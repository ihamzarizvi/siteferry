<?php
/**
 * Stream wrapper that reads and writes the plain contents of an encrypted backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * "siteferry-enc://<path>" behaves like the plain backup: the archive code opens, reads, seeks,
 * truncates and appends as with any file, and this wrapper encrypts and decrypts chunks (see
 * Crypto). Keys are registered per path for the current request only, never written anywhere.
 *
 * Reading: any position; each chunk is checked when its data is read. A file without its final
 * chunk is refused when opened. Writing: "x" creates a file (with the registered header); "r+"
 * continues one after ftruncate() to a chunk boundary, as the resumable writers do. Written data
 * is sealed in chunks of Crypto::CHUNK bytes, and at every fflush() and fclose(); the final chunk
 * is added by Crypto::seal() when the archive is complete.
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- the stream wrapper API's names.
 */
final class Crypto_Stream {

	const SCHEME = 'siteferry-enc';

	/**
	 * Registered keys (and headers for new files) by path.
	 *
	 * @var array<string,array{key:string,header:?string}>
	 */
	private static $keys = array();

	/**
	 * Set by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The encrypted file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Key.
	 *
	 * @var string
	 */
	private $key = '';

	/**
	 * File header.
	 *
	 * @var string
	 */
	private $header = '';

	/**
	 * Chunks as [file offset, plaintext offset, length, final].
	 *
	 * @var array<int,array>
	 */
	private $chunks = array();

	/**
	 * Plaintext size of the sealed chunks.
	 *
	 * @var int
	 */
	private $size = 0;

	/**
	 * Where the next chunk goes in the file.
	 *
	 * @var int
	 */
	private $end = 0;

	/**
	 * Current plaintext position.
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * Whether the stream was opened for writing.
	 *
	 * @var bool
	 */
	private $writing = false;

	/**
	 * Written plaintext not sealed yet.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * The chunk read last: [index, plaintext].
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * The path through this wrapper, with the key registered.
	 *
	 * @param string      $path   Encrypted file.
	 * @param string      $key    Key.
	 * @param string|null $header Header for a file that is about to be created.
	 */
	public static function url(
		string $path,
		#[\SensitiveParameter]
		string $key,
		?string $header = null
	): string {
		if ( ! in_array( self::SCHEME, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::SCHEME, self::class );
		}
		$path                = str_replace( '\\', '/', $path );
		self::$keys[ $path ] = array(
			'key'    => $key,
			'header' => $header,
		);
		return self::SCHEME . '://' . $path;
	}

	/**
	 * Forgets every registered key (tests, and the end of a job's slice).
	 */
	public static function forget_keys(): void {
		self::$keys = array();
	}

	/**
	 * Opens the stream.
	 *
	 * @param string      $url         URL.
	 * @param string      $mode        Mode: rb, xb or r+b.
	 * @param int         $options     Flags.
	 * @param string|null $opened_path Unused.
	 * @throws Archive_Exception If the file is not a complete encrypted backup.
	 */
	public function stream_open( string $url, string $mode, int $options, ?string &$opened_path ): bool {
		unset( $options, $opened_path );
		$path = (string) substr( $url, strlen( self::SCHEME ) + 3 );
		if ( ! isset( self::$keys[ $path ] ) ) {
			return false;
		}
		$this->key = self::$keys[ $path ]['key'];
		$mode      = str_replace( array( 'b', 't' ), '', $mode );

		if ( 'x' === $mode ) {
			$header = (string) self::$keys[ $path ]['header'];
			if ( Crypto::HEADER_BYTES !== strlen( $header ) ) {
				return false;
			}
			$handle = Io::open( $path, 'xb' );
			if ( false === $handle ) {
				return false;
			}
			$this->handle  = $handle;
			$this->header  = $header;
			$this->writing = true;
			Io::write_all( $handle, $header );
			$this->end = Crypto::HEADER_BYTES;
			return true;
		}
		if ( 'r' !== $mode && 'r+' !== $mode ) {
			return false;
		}

		$handle = Io::open( $path, 'r' === $mode ? 'rb' : 'r+b' );
		if ( false === $handle ) {
			return false;
		}
		$this->handle  = $handle;
		$this->writing = 'r+' === $mode;
		$this->header  = (string) fread( $handle, Crypto::HEADER_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( Crypto::HEADER_BYTES !== strlen( $this->header ) || 0 !== strpos( $this->header, Crypto::MAGIC ) ) {
			$this->stream_close();
			throw new Archive_Exception( __( 'This is not an encrypted SiteFerry backup.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		$scan         = Crypto::scan( $handle );
		$this->chunks = $scan['chunks'];
		$this->size   = $scan['size'];
		$this->end    = $scan['end'];
		if ( ! $this->writing && ( ! $scan['sealed'] || $scan['torn'] ) ) {
			$this->stream_close();
			throw new Archive_Exception( __( 'The encrypted backup is incomplete (it was cut off).', 'siteferry' ), Archive_Exception::TRUNCATED );
		}
		if ( $this->writing && $scan['sealed'] ) {
			$this->stream_close();
			throw new Archive_Exception( __( 'This encrypted backup is already complete.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		return true;
	}

	/**
	 * Reads plaintext.
	 *
	 * @param int $count Bytes wanted.
	 * @throws Archive_Exception If a chunk fails its check.
	 */
	public function stream_read( int $count ): string {
		if ( $this->writing || $this->position >= $this->size || $count <= 0 ) {
			return '';
		}
		$index = $this->find( $this->position );
		if ( null === $this->cache || $this->cache[0] !== $index ) {
			list( $offset, , $length ) = $this->chunks[ $index ];
			fseek( $this->handle, $offset );
			$head = (string) fread( $this->handle, Crypto::CHUNK_HEAD ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$body = '';
			while ( strlen( $body ) < $length + Crypto::TAG ) {
				$part = fread( $this->handle, $length + Crypto::TAG - strlen( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $part || '' === $part ) {
					break;
				}
				$body .= $part;
			}
			$this->cache = array( $index, Crypto::open_chunk( $this->key, $this->header, $index, $head, $body ) );
		}
		$data            = (string) substr( $this->cache[1], $this->position - $this->chunks[ $index ][1], $count );
		$this->position += strlen( $data );
		return $data;
	}

	/**
	 * Writes plaintext.
	 *
	 * @param string $data Bytes.
	 */
	public function stream_write( string $data ): int {
		if ( ! $this->writing ) {
			return 0;
		}
		$this->buffer   .= $data;
		$this->position += strlen( $data );
		while ( strlen( $this->buffer ) >= Crypto::CHUNK ) {
			$this->emit( (string) substr( $this->buffer, 0, Crypto::CHUNK ) );
			$this->buffer = (string) substr( $this->buffer, Crypto::CHUNK );
		}
		return strlen( $data );
	}

	/**
	 * Seals what was written so far.
	 */
	public function stream_flush(): bool {
		if ( $this->writing && '' !== $this->buffer ) {
			$this->emit( $this->buffer );
			$this->buffer = '';
		}
		return null === $this->handle || fflush( $this->handle );
	}

	/**
	 * Cuts the plaintext back to a chunk boundary (a resumed writer drops what a dead request
	 * wrote after its last checkpoint).
	 *
	 * @param int $size New plaintext size.
	 */
	public function stream_truncate( int $size ): bool {
		if ( ! $this->writing || '' !== $this->buffer ) {
			return false;
		}
		$keep = count( $this->chunks );
		if ( $size < $this->size ) {
			$keep = null;
			foreach ( $this->chunks as $index => $chunk ) {
				if ( $chunk[1] === $size ) {
					$keep = $index;
					break;
				}
			}
			if ( null === $keep ) {
				return false;
			}
		} elseif ( $size > $this->size ) {
			return false;
		}
		$this->end    = $keep < count( $this->chunks ) ? $this->chunks[ $keep ][0] : $this->end;
		$this->chunks = array_slice( $this->chunks, 0, $keep );
		$this->size   = $size;
		$this->cache  = null;
		return ftruncate( $this->handle, $this->end );
	}

	/**
	 * Moves the position (writing: only to the end).
	 *
	 * @param int $offset Offset.
	 * @param int $whence SEEK_SET, SEEK_CUR or SEEK_END.
	 */
	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		$total = $this->size + strlen( $this->buffer );
		$to    = SEEK_END === $whence ? $total + $offset : ( SEEK_CUR === $whence ? $this->position + $offset : $offset );
		if ( $to < 0 || $to > $total || ( $this->writing && $to !== $total ) ) {
			return false;
		}
		$this->position = $to;
		return true;
	}

	/**
	 * Current position.
	 */
	public function stream_tell(): int {
		return $this->position;
	}

	/**
	 * Whether the end was reached.
	 */
	public function stream_eof(): bool {
		return $this->position >= $this->size + strlen( $this->buffer );
	}

	/**
	 * Size of the plain contents.
	 */
	public function stream_stat(): array {
		return array(
			'size' => $this->size + strlen( $this->buffer ),
			'mode' => 0100644,
		);
	}

	/**
	 * Stream options (none).
	 *
	 * @param int $option Option.
	 * @param int $arg1   Argument.
	 * @param int $arg2   Argument.
	 */
	public function stream_set_option( int $option, int $arg1, int $arg2 ): bool {
		unset( $option, $arg1, $arg2 );
		return false;
	}

	/**
	 * Seals what is left and closes the file.
	 */
	public function stream_close(): void {
		if ( null === $this->handle ) {
			return;
		}
		if ( $this->writing && '' !== $this->buffer ) {
			$this->emit( $this->buffer );
			$this->buffer = '';
		}
		fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->handle = null;
		$this->cache  = null;
	}

	/**
	 * Writes one (not final) chunk.
	 *
	 * @param string $plain Plaintext.
	 */
	private function emit( string $plain ): void {
		$index = count( $this->chunks );
		$chunk = Crypto::seal_chunk( $this->key, $this->header, $index, $plain, false );
		fseek( $this->handle, $this->end );
		Io::write_all( $this->handle, $chunk );
		$this->chunks[] = array( $this->end, $this->size, strlen( $plain ), false );
		$this->end     += strlen( $chunk );
		$this->size    += strlen( $plain );
	}

	/**
	 * The chunk that holds a plaintext position.
	 *
	 * @param int $position Position (below the size).
	 */
	private function find( int $position ): int {
		if ( null !== $this->cache ) {
			$cached = $this->chunks[ $this->cache[0] ];
			if ( $position >= $cached[1] && $position < $cached[1] + $cached[2] ) {
				return $this->cache[0];
			}
		}
		$low  = 0;
		$high = count( $this->chunks ) - 1;
		while ( $low < $high ) {
			$middle = intdiv( $low + $high + 1, 2 );
			if ( $this->chunks[ $middle ][1] <= $position ) {
				$low = $middle;
			} else {
				$high = $middle - 1;
			}
		}
		// Empty chunks (a checkpoint with nothing new) are passed over.
		while ( $low < count( $this->chunks ) - 1 && $this->chunks[ $low ][1] + $this->chunks[ $low ][2] <= $position ) {
			++$low;
		}
		return $low;
	}
}
