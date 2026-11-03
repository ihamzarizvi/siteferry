<?php
/**
 * gzip archive input that can resume at gzip member boundaries.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Decompresses a .tar.gz made of one or more gzip members.
 *
 * It records where each member starts. A saved state points at the start of the member that holds
 * the current position, so resuming only re-inflates part of one member (about 8 MB for SiteFerry
 * archives). A .tar.gz made by another tool is usually a single member; it still works, but each
 * resume re-inflates from the start of the file.
 */
final class Gzip_Input implements Input_Stream {

	/**
	 * Compressed bytes fed to zlib per call. Small, because deflate can expand about 1000:1 and
	 * inflate_add() returns everything at once.
	 */
	const FEED_SIZE = 32768;

	/** Unread output kept before compacting the buffer. */
	const COMPACT_AT = 1048576;

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Decompressor for the current member, or null between members.
	 *
	 * @var resource|\InflateContext|null
	 */
	private $inflate = null;

	/**
	 * Decompressed bytes, read from $buffer_offset on.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Read offset into $buffer.
	 *
	 * @var int
	 */
	private $buffer_offset = 0;

	/**
	 * Uncompressed position of the next byte returned.
	 *
	 * @var int
	 */
	private $position;

	/**
	 * Uncompressed bytes produced so far (position plus unread buffer).
	 *
	 * @var int
	 */
	private $produced;

	/**
	 * Compressed bytes read from the file but not yet given to zlib.
	 *
	 * @var string
	 */
	private $pending = '';

	/**
	 * Compressed offset of the first byte of $pending (or of the file pointer when it is empty).
	 *
	 * @var int
	 */
	private $compressed_offset;

	/**
	 * Compressed bytes given to the current member's decompressor.
	 *
	 * @var int
	 */
	private $fed = 0;

	/**
	 * Member starts as [compressed offset, uncompressed offset], oldest first.
	 *
	 * @var array<int,array{0:int,1:int}>
	 */
	private $boundaries;

	/**
	 * Members started so far in this request.
	 *
	 * @var int
	 */
	private $members = 0;

	/**
	 * True once the compressed data is exhausted.
	 *
	 * @var bool
	 */
	private $eof = false;

	/**
	 * Opens a file at the start or at a saved state.
	 *
	 * @param string     $path  Archive path.
	 * @param array|null $state Data from state(), or null to start at the beginning.
	 * @throws Archive_Exception If zlib is missing, the file cannot be opened or the state is invalid.
	 */
	public function __construct( string $path, ?array $state = null ) {
		if ( ! function_exists( 'inflate_init' ) || ! function_exists( 'inflate_get_read_len' ) ) {
			throw new Archive_Exception( 'Reading .tar.gz files needs the PHP zlib extension.', Archive_Exception::IO );
		}

		$member_offset   = null === $state ? 0 : (int) $state['member_offset'];
		$member_position = null === $state ? 0 : (int) $state['member_position'];
		$position        = null === $state ? 0 : (int) $state['position'];

		$this->handle = Io::open_or_fail( $path, 'rb' );
		if ( $member_offset > Io::size( $this->handle ) || 0 !== fseek( $this->handle, $member_offset ) || $position < $member_position ) {
			$this->close();
			throw new Archive_Exception( 'Saved read position is not valid for this archive.', Archive_Exception::BAD_STATE );
		}

		$this->compressed_offset = $member_offset;
		$this->position          = $member_position;
		$this->produced          = $member_position;
		$this->boundaries        = array( array( $member_offset, $member_position ) );

		$discard = $position - $member_position;
		if ( $discard > 0 && $this->skip( $discard ) !== $discard ) {
			$this->close();
			throw new Archive_Exception( 'Saved read position is past the end of the archive.', Archive_Exception::BAD_STATE );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $length Bytes wanted.
	 */
	public function read( int $length ): string {
		if ( $length <= 0 ) {
			return '';
		}
		$this->fill( $length );

		$take = min( $length, strlen( $this->buffer ) - $this->buffer_offset );
		$data = (string) substr( $this->buffer, $this->buffer_offset, $take );

		$this->buffer_offset += $take;
		$this->position      += $take;
		if ( $this->buffer_offset >= strlen( $this->buffer ) ) {
			$this->buffer        = '';
			$this->buffer_offset = 0;
		} elseif ( $this->buffer_offset >= self::COMPACT_AT ) {
			$this->buffer        = (string) substr( $this->buffer, $this->buffer_offset );
			$this->buffer_offset = 0;
		}
		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $length Bytes to skip.
	 */
	public function skip( int $length ): int {
		$skipped = 0;
		while ( $skipped < $length ) {
			$chunk = strlen( $this->read( min( $length - $skipped, self::COMPACT_AT ) ) );
			if ( 0 === $chunk ) {
				break;
			}
			$skipped += $chunk;
		}
		return $skipped;
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * {@inheritDoc}
	 */
	public function state(): array {
		$start = $this->boundaries[0];
		$keep  = 0;
		foreach ( $this->boundaries as $index => $boundary ) {
			if ( $boundary[1] > $this->position ) {
				break;
			}
			$start = $boundary;
			$keep  = $index;
		}
		$this->boundaries = array_slice( $this->boundaries, $keep );

		return array(
			'member_offset'   => $start[0],
			'member_position' => $start[1],
			'position'        => $this->position,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle  = null;
			$this->inflate = null;
		}
	}

	/**
	 * Decompresses until $need unread bytes are buffered or the data ends.
	 *
	 * @param int $need Bytes wanted.
	 * @throws Archive_Exception On damaged or truncated gzip data.
	 */
	private function fill( int $need ): void {
		while ( strlen( $this->buffer ) - $this->buffer_offset < $need && ! $this->eof ) {
			if ( null === $this->inflate ) {
				if ( ! $this->start_member() ) {
					$this->eof = true;
					break;
				}
			}

			if ( '' === $this->pending ) {
				$this->pending = $this->read_compressed();
				if ( '' === $this->pending ) {
					throw new Archive_Exception( 'The compressed archive ends in the middle of a gzip member; the file is incomplete.', Archive_Exception::TRUNCATED );
				}
			}

			$input         = $this->pending;
			$this->pending = '';
			$output        = $this->inflate_chunk( $input );

			$this->fed               += strlen( $input );
			$this->compressed_offset += strlen( $input );

			if ( ZLIB_STREAM_END === inflate_get_status( $this->inflate ) ) {
				// zlib stops at the member trailer; the rest of $input belongs to the next member.
				$leftover = $this->fed - inflate_get_read_len( $this->inflate );
				if ( $leftover > 0 ) {
					$this->pending            = (string) substr( $input, -$leftover );
					$this->compressed_offset -= $leftover;
				}
				$this->inflate = null;
			}

			$this->buffer   .= $output;
			$this->produced += strlen( $output );
		}
	}

	/**
	 * Starts decompressing the next member.
	 *
	 * @return bool False at the end of the data (or at trailing non-gzip bytes after the first member).
	 * @throws Archive_Exception If the file does not start with a gzip member.
	 */
	private function start_member(): bool {
		while ( strlen( $this->pending ) < 2 ) {
			$more = $this->read_compressed();
			if ( '' === $more ) {
				break;
			}
			$this->pending .= $more;
		}

		if ( "\x1f\x8b" !== substr( $this->pending, 0, 2 ) ) {
			if ( 0 === $this->members && 0 === $this->compressed_offset ) {
				throw new Archive_Exception( 'This is not a gzip file.', Archive_Exception::CORRUPT );
			}
			// End of data, or padding some tools add after the last member.
			return false;
		}

		$this->inflate = inflate_init( ZLIB_ENCODING_GZIP );
		if ( false === $this->inflate ) {
			throw new Archive_Exception( 'Cannot start gzip decompression.', Archive_Exception::IO );
		}
		$this->fed = 0;
		++$this->members;
		$this->boundaries[] = array( $this->compressed_offset, $this->produced );
		return true;
	}

	/**
	 * Reads the next compressed chunk from the file ('' at end of file).
	 */
	private function read_compressed(): string {
		$chunk = fread( $this->handle, self::FEED_SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		return false === $chunk ? '' : $chunk;
	}

	/**
	 * Inflates one chunk, turning zlib warnings into an exception.
	 *
	 * @param string $input Compressed bytes.
	 * @throws Archive_Exception On damaged data.
	 */
	private function inflate_chunk( string $input ): string {
		$inflate = $this->inflate;
		$output  = Io::quiet(
			static function () use ( $inflate, $input ) {
				return inflate_add( $inflate, $input, ZLIB_SYNC_FLUSH );
			}
		);
		if ( false === $output ) {
			throw new Archive_Exception( 'The compressed archive is damaged (gzip data error).', Archive_Exception::CORRUPT );
		}
		return $output;
	}
}
