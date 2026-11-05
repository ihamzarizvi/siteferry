<?php
/**
 * Streaming, resumable TAR reader.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Reads POSIX ustar/pax archives (plain or gzip), also accepting GNU long names and old-style headers.
 *
 * Entry names are returned exactly as stored. Callers that write to disk must validate them,
 * which Tar_Extractor does with Path_Guard.
 */
final class Tar_Reader implements Entry_Reader {

	/** Bytes copied at a time. */
	const CHUNK = 1048576;

	/** Largest pax or GNU long-name data accepted, to bound memory use. */
	const MAX_META_SIZE = 1048576;

	/**
	 * Input stream.
	 *
	 * @var Input_Stream
	 */
	private $in;

	/**
	 * Whether the file is gzip-compressed.
	 *
	 * @var bool
	 */
	private $compressed;

	/**
	 * Current entry, or null before the first and after the last.
	 *
	 * @var Tar_Entry|null
	 */
	private $entry = null;

	/**
	 * Data bytes of the current entry not yet read.
	 *
	 * @var int
	 */
	private $remaining = 0;

	/**
	 * Padding bytes after the current entry's data.
	 *
	 * @var int
	 */
	private $padding = 0;

	/**
	 * True after the end-of-archive marker.
	 *
	 * @var bool
	 */
	private $ended = false;

	/**
	 * Constructor.
	 *
	 * @param Input_Stream $in         Input.
	 * @param bool         $compressed Whether it is gzip.
	 */
	private function __construct( Input_Stream $in, bool $compressed ) {
		$this->in         = $in;
		$this->compressed = $compressed;
	}

	/**
	 * Opens an archive at the start or at a saved state. Compression is detected from the content.
	 *
	 * @param string     $path  Archive path.
	 * @param array|null $state Data from state().
	 * @throws Archive_Exception If the file cannot be opened or the state is invalid.
	 */
	public static function open( string $path, ?array $state = null ): self {
		if ( null === $state ) {
			$compressed = self::is_gzip( $path );
			$input      = $compressed ? new Gzip_Input( $path ) : new File_Input( $path );
			return new self( $input, $compressed );
		}

		if ( ! isset( $state['version'], $state['input'] ) || 1 !== $state['version'] ) {
			throw new Archive_Exception( __( 'Invalid saved reader state.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		$compressed        = ! empty( $state['compressed'] );
		$input             = $compressed ? new Gzip_Input( $path, (array) $state['input'] ) : new File_Input( $path, (array) $state['input'] );
		$reader            = new self( $input, $compressed );
		$reader->entry     = empty( $state['entry'] ) ? null : Tar_Entry::from_array( (array) $state['entry'] );
		$reader->remaining = (int) $state['remaining'];
		$reader->padding   = (int) $state['padding'];
		$reader->ended     = ! empty( $state['ended'] );
		return $reader;
	}

	/**
	 * Whether a file starts with the gzip magic bytes.
	 *
	 * @param string $path File path.
	 * @throws Archive_Exception If the file cannot be opened.
	 */
	public static function is_gzip( string $path ): bool {
		$handle = Io::open_or_fail( $path, 'rb' );
		$magic  = fread( $handle, 2 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return "\x1f\x8b" === $magic;
	}

	/**
	 * Moves to the next entry, skipping any unread data of the current one.
	 *
	 * @return Tar_Entry|null Null at the end of the archive.
	 * @throws Archive_Exception On damaged or truncated archives.
	 */
	public function next(): ?Tar_Entry {
		if ( $this->ended ) {
			return null;
		}

		$skip = $this->remaining + $this->padding;
		if ( $skip > 0 && $this->in->skip( $skip ) !== $skip ) {
			throw $this->truncated();
		}
		$this->entry     = null;
		$this->remaining = 0;
		$this->padding   = 0;

		$pax       = array();
		$long_name = null;
		$long_link = null;

		while ( true ) {
			$block = $this->in->read( Tar_Header::BLOCK );
			if ( '' === $block ) {
				throw new Archive_Exception( __( 'The archive has no end marker; the file is incomplete.', 'siteferry' ), Archive_Exception::TRUNCATED );
			}
			if ( strlen( $block ) < Tar_Header::BLOCK ) {
				throw $this->truncated();
			}

			if ( Tar_Header::is_zero_block( $block ) ) {
				// The end marker is two zero blocks; accept a missing or cut-off second one.
				$second = $this->in->read( Tar_Header::BLOCK );
				if ( '' === trim( $second, "\0" ) ) {
					$this->ended = true;
					return null;
				}
				throw new Archive_Exception( __( 'Unexpected data after an empty TAR block.', 'siteferry' ), Archive_Exception::CORRUPT );
			}

			$header = Tar_Header::parse( $block );
			$type   = $header['type'];

			if ( 'x' === $type ) {
				$pax = array_merge( $pax, Tar_Header::parse_pax( $this->read_meta( $header['size'] ) ) );
				continue;
			}
			if ( 'g' === $type ) {
				// Global pax headers carry archive-wide defaults that SiteFerry does not need.
				$this->skip_meta( $header['size'] );
				continue;
			}
			if ( 'L' === $type ) {
				$long_name = self::cstring( $this->read_meta( $header['size'] ) );
				continue;
			}
			if ( 'K' === $type ) {
				$long_link = self::cstring( $this->read_meta( $header['size'] ) );
				continue;
			}

			return $this->make_entry( $header, $pax, $long_name, $long_link );
		}
	}

	/**
	 * Current entry.
	 */
	public function entry(): ?Tar_Entry {
		return $this->entry;
	}

	/**
	 * Data bytes of the current entry not yet read.
	 */
	public function remaining(): int {
		return $this->remaining;
	}

	/**
	 * Reads up to $length bytes of the current entry's data ('' when it is all read).
	 *
	 * @param int $length Maximum bytes.
	 * @throws Archive_Exception If the archive ends early.
	 */
	public function read( int $length ): string {
		$want = min( $length, $this->remaining );
		if ( $want <= 0 ) {
			return '';
		}
		$data = $this->in->read( $want );
		if ( strlen( $data ) !== $want ) {
			throw $this->truncated();
		}
		$this->remaining -= $want;
		return $data;
	}

	/**
	 * Copies the rest of the current entry's data to an open file.
	 *
	 * @param resource            $handle Destination.
	 * @param Slice_Budget|null   $budget Work limit.
	 * @param Resumable_Hash|null $hash   Hash to update with the copied bytes.
	 * @return bool True when the entry is fully copied, false if the budget ran out first.
	 */
	public function copy_to( $handle, ?Slice_Budget $budget = null, ?Resumable_Hash $hash = null ): bool {
		while ( $this->remaining > 0 ) {
			$data = $this->read( self::CHUNK );
			Io::write_all( $handle, $data );
			if ( null !== $hash ) {
				$hash->update( $data );
			}
			if ( null !== $budget ) {
				$budget->consume( strlen( $data ) );
				if ( $this->remaining > 0 && $budget->exhausted() ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether the archive is gzip-compressed.
	 */
	public function is_compressed(): bool {
		return $this->compressed;
	}

	/**
	 * Uncompressed bytes consumed so far.
	 */
	public function position(): int {
		return $this->in->position();
	}

	/**
	 * State to save; open() with it continues exactly here.
	 *
	 * @return array JSON-safe state.
	 */
	public function state(): array {
		return array(
			'version'    => 1,
			'compressed' => $this->compressed,
			'input'      => $this->in->state(),
			'entry'      => null === $this->entry ? null : $this->entry->to_array(),
			'remaining'  => $this->remaining,
			'padding'    => $this->padding,
			'ended'      => $this->ended,
		);
	}

	/**
	 * Closes the file.
	 */
	public function close(): void {
		$this->in->close();
	}

	/**
	 * Builds the entry from its header and any extended headers before it.
	 *
	 * @param array       $header    Parsed ustar header.
	 * @param array       $pax       pax records for this entry.
	 * @param string|null $long_name GNU long name.
	 * @param string|null $long_link GNU long link name.
	 * @throws Archive_Exception On malformed pax values.
	 */
	private function make_entry( array $header, array $pax, ?string $long_name, ?string $long_link ): Tar_Entry {
		$entry           = new Tar_Entry();
		$entry->name     = $header['name'];
		$entry->type     = $header['type'];
		$entry->size     = $header['size'];
		$entry->mtime    = $header['mtime'];
		$entry->mode     = $header['mode'];
		$entry->linkname = $header['linkname'];

		if ( null !== $long_name ) {
			$entry->name = $long_name;
		}
		if ( null !== $long_link ) {
			$entry->linkname = $long_link;
		}
		if ( isset( $pax['path'] ) && '' !== $pax['path'] ) {
			$entry->name = $pax['path'];
		}
		if ( isset( $pax['linkpath'] ) && '' !== $pax['linkpath'] ) {
			$entry->linkname = $pax['linkpath'];
		}
		if ( isset( $pax['size'] ) ) {
			if ( ! preg_match( '/^[0-9]{1,18}$/', $pax['size'] ) ) {
				throw new Archive_Exception( __( 'Malformed size in pax header.', 'siteferry' ), Archive_Exception::CORRUPT );
			}
			$entry->size = (int) $pax['size'];
		}
		if ( isset( $pax['mtime'] ) && preg_match( '/^(-?[0-9]{1,18})(\.[0-9]*)?$/', $pax['mtime'], $match ) ) {
			$entry->mtime = (int) $match[1];
		}
		foreach ( array_keys( $pax ) as $key ) {
			if ( 0 === strpos( $key, 'GNU.sparse.' ) ) {
				$entry->sparse = true;
			}
		}
		if ( 'S' === $entry->type ) {
			$entry->sparse = true;
		}

		// Data follows every type except links, devices, directories and FIFOs
		// (a pax hard link may carry data; then its size is non-zero).
		$no_data         = in_array( $entry->type, array( Tar_Entry::TYPE_SYMLINK, Tar_Entry::TYPE_CHAR, Tar_Entry::TYPE_BLOCK, Tar_Entry::TYPE_DIR, Tar_Entry::TYPE_FIFO ), true );
		$this->remaining = $no_data ? 0 : $entry->size;
		$this->padding   = ( Tar_Header::BLOCK - $this->remaining % Tar_Header::BLOCK ) % Tar_Header::BLOCK;

		// Old archives mark directories with a trailing slash on a regular-file entry.
		if ( Tar_Entry::TYPE_FILE === $entry->type && '/' === substr( $entry->name, -1 ) && 0 === $entry->size ) {
			$entry->type = Tar_Entry::TYPE_DIR;
		}

		$this->entry = $entry;
		return $entry;
	}

	/**
	 * Reads the data of a pax or GNU long-name entry, including its padding.
	 *
	 * @param int $size Data size.
	 * @throws Archive_Exception If it is too large or truncated.
	 */
	private function read_meta( int $size ): string {
		if ( $size > self::MAX_META_SIZE ) {
			throw new Archive_Exception( __( 'Extended TAR header is too large.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		$data = $size > 0 ? $this->in->read( $size ) : '';
		if ( strlen( $data ) !== $size ) {
			throw $this->truncated();
		}
		$this->skip_exact( ( Tar_Header::BLOCK - $size % Tar_Header::BLOCK ) % Tar_Header::BLOCK );
		return $data;
	}

	/**
	 * Skips the data of an ignored metadata entry.
	 *
	 * @param int $size Data size.
	 */
	private function skip_meta( int $size ): void {
		$this->skip_exact( $size + ( Tar_Header::BLOCK - $size % Tar_Header::BLOCK ) % Tar_Header::BLOCK );
	}

	/**
	 * Skips exactly $length bytes.
	 *
	 * @param int $length Bytes.
	 * @throws Archive_Exception If the archive ends first.
	 */
	private function skip_exact( int $length ): void {
		if ( $length > 0 && $this->in->skip( $length ) !== $length ) {
			throw $this->truncated();
		}
	}

	/**
	 * Exception for an archive that ends early.
	 */
	private function truncated(): Archive_Exception {
		$where = null === $this->entry ? '' : sprintf( ' inside "%s"', Path_Guard::printable( $this->entry->name ) );
		return new Archive_Exception( sprintf( /* translators: %s: empty, or where it ends, such as " after wp-content/uploads/a.jpg" */ __( 'The archive ends unexpectedly%s; the file is incomplete.', 'siteferry' ), $where ), Archive_Exception::TRUNCATED );
	}

	/**
	 * Text up to the first NUL.
	 *
	 * @param string $data Raw data.
	 */
	private static function cstring( string $data ): string {
		$nul = strpos( $data, "\0" );
		return false === $nul ? $data : substr( $data, 0, $nul );
	}
}
