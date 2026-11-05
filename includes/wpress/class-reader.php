<?php
/**
 * Streaming, resumable reader of .wpress archives.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Entry_Reader;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Resumable_Hash;
use SiteFerry\Archive\Tar_Entry;
use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the entries of a .wpress archive as Tar_Entry objects, so Tar_Extractor can unpack them
 * with all of its checks. Entry names are "<prefix><folder>/<name>" (the folder left out at the
 * top); they are returned as stored, and Tar_Extractor validates them with Path_Guard.
 *
 * Each file's CRC-32 is checked as its data is read; a mismatch stops with an exception.
 */
final class Reader implements Entry_Reader {

	/** Bytes read at a time. */
	const CHUNK = 1048576;

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Archive size.
	 *
	 * @var int
	 */
	private $length;

	/**
	 * Position of the next unread byte.
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * Current entry.
	 *
	 * @var Tar_Entry|null
	 */
	private $entry = null;

	/**
	 * Header fields of the current entry (name, path, crc).
	 *
	 * @var array|null
	 */
	private $raw = null;

	/**
	 * Data bytes of the current entry not yet read.
	 *
	 * @var int
	 */
	private $remaining = 0;

	/**
	 * CRC-32 of the current entry's data read so far.
	 *
	 * @var int
	 */
	private $crc = 0;

	/**
	 * True after the closing header.
	 *
	 * @var bool
	 */
	private $ended = false;

	/**
	 * Prefix for entry names.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Optional filter: function( string $relative ): bool, true skips the entry.
	 *
	 * @var callable|null
	 */
	private $skip;

	/**
	 * Constructor.
	 *
	 * @param resource $handle  Open file.
	 * @param int      $length  File size.
	 * @param array    $options See open().
	 */
	private function __construct( $handle, int $length, array $options ) {
		$this->handle = $handle;
		$this->length = $length;
		$this->prefix = isset( $options['prefix'] ) ? (string) $options['prefix'] : '';
		$this->skip   = isset( $options['skip'] ) ? $options['skip'] : null;
	}

	/**
	 * Opens an archive at the start or at a saved state.
	 *
	 * @param string     $path    Archive.
	 * @param array|null $state   Data from state().
	 * @param array      $options {
	 *     Optional settings.
	 *
	 *     @type string   $prefix Put before every entry name, such as "wp-content/".
	 *     @type callable $skip   function( string $relative ): bool; true skips the entry (the
	 *                            relative name is "<folder>/<name>", or "<name>" at the top).
	 * }
	 * @throws Archive_Exception If the file cannot be opened or is not a .wpress archive.
	 */
	public static function open( string $path, ?array $state = null, array $options = array() ): self {
		$handle = Io::open_or_fail( $path, 'rb' );
		$reader = new self( $handle, Io::size( $handle ), $options );
		if ( null !== $state ) {
			if ( ! isset( $state['version'] ) || 1 !== $state['version'] ) {
				$reader->close();
				throw new Archive_Exception( __( 'Invalid saved reader state.', 'siteferry' ), Archive_Exception::BAD_STATE );
			}
			$reader->offset    = (int) $state['offset'];
			$reader->entry     = empty( $state['entry'] ) ? null : Tar_Entry::from_array( (array) $state['entry'] );
			$reader->raw       = empty( $state['raw'] ) ? null : (array) $state['raw'];
			$reader->remaining = (int) $state['remaining'];
			$reader->crc       = (int) $state['crc'];
			$reader->ended     = ! empty( $state['ended'] );
		}
		if ( 0 !== fseek( $handle, $reader->offset ) ) {
			$reader->close();
			throw new Archive_Exception( __( 'Cannot resume reading the .wpress archive.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		return $reader;
	}

	/**
	 * Whether a file looks like a .wpress archive: its first header parses.
	 *
	 * @param string $path File.
	 */
	public static function is_wpress( string $path ): bool {
		$handle = Io::open( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$block = (string) fread( $handle, Header::SIZE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		try {
			$header = Header::parse( $block );
		} catch ( Archive_Exception $e ) {
			return false;
		}
		return ! $header['end'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function next(): ?Tar_Entry {
		if ( $this->ended ) {
			return null;
		}
		$this->offset   += $this->remaining;
		$this->entry     = null;
		$this->raw       = null;
		$this->remaining = 0;

		while ( true ) {
			if ( 0 !== fseek( $this->handle, $this->offset ) ) {
				throw $this->truncated();
			}
			$header        = Header::parse( (string) fread( $this->handle, Header::SIZE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$this->offset += Header::SIZE;
			if ( $header['end'] ) {
				$this->ended = true;
				return null;
			}
			if ( $this->offset + $header['size'] > $this->length ) {
				throw $this->truncated();
			}

			$relative = '.' === $header['path'] ? $header['name'] : rtrim( str_replace( '\\', '/', $header['path'] ), '/' ) . '/' . $header['name'];
			if ( null !== $this->skip && true === call_user_func( $this->skip, $relative ) ) {
				$this->offset += $header['size'];
				continue;
			}

			$entry        = new Tar_Entry();
			$entry->name  = $this->prefix . $relative;
			$entry->type  = Tar_Entry::TYPE_FILE;
			$entry->size  = $header['size'];
			$entry->mtime = $header['mtime'];
			$entry->mode  = 0644;

			$this->entry     = $entry;
			$this->raw       = array(
				'name' => $header['name'],
				'path' => $header['path'],
				'crc'  => $header['crc'],
			);
			$this->remaining = $header['size'];
			$this->crc       = 0;
			if ( 0 === $header['size'] ) {
				$this->check_crc();
			}
			return $entry;
		}
	}

	/**
	 * The current entry.
	 */
	public function entry(): ?Tar_Entry {
		return $this->entry;
	}

	/**
	 * Header fields of the current entry: name, path (folder) and crc (or null).
	 */
	public function raw(): ?array {
		return $this->raw;
	}

	/**
	 * {@inheritDoc}
	 */
	public function remaining(): int {
		return $this->remaining;
	}

	/**
	 * Reads up to $length bytes of the current entry ('' when it is all read).
	 *
	 * @param int $length Maximum bytes.
	 * @throws Archive_Exception If the archive ends early or the data does not match its CRC.
	 */
	public function read( int $length ): string {
		$want = min( $length, $this->remaining );
		if ( $want <= 0 ) {
			return '';
		}
		$data = (string) fread( $this->handle, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $data ) !== $want ) {
			throw $this->truncated();
		}
		$this->offset    += $want;
		$this->remaining -= $want;
		$this->crc        = Crc32::update( $this->crc, $data );
		if ( 0 === $this->remaining ) {
			$this->check_crc();
		}
		return $data;
	}

	/**
	 * {@inheritDoc}
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
	 * Position of the next unread byte.
	 */
	public function position(): int {
		return $this->offset;
	}

	/**
	 * Archive size.
	 */
	public function length(): int {
		return $this->length;
	}

	/**
	 * {@inheritDoc}
	 */
	public function state(): array {
		return array(
			'version'   => 1,
			'offset'    => $this->offset,
			'entry'     => null === $this->entry ? null : $this->entry->to_array(),
			'raw'       => $this->raw,
			'remaining' => $this->remaining,
			'crc'       => $this->crc,
			'ended'     => $this->ended,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle = null;
		}
	}

	/**
	 * Compares the finished entry's CRC with its header.
	 *
	 * @throws Archive_Exception On a mismatch.
	 */
	private function check_crc(): void {
		if ( null !== $this->raw && null !== $this->raw['crc'] && $this->raw['crc'] !== $this->crc ) {
			throw new Archive_Exception(
				sprintf( /* translators: %s: a file name */ __( 'The file "%s" in the .wpress backup is damaged (its CRC-32 does not match).', 'siteferry' ), $this->entry->name ),
				Archive_Exception::CORRUPT
			);
		}
	}

	/**
	 * Exception for an archive that ends too early.
	 */
	private function truncated(): Archive_Exception {
		return new Archive_Exception( __( 'The .wpress archive is incomplete (it ends too early).', 'siteferry' ), Archive_Exception::TRUNCATED );
	}
}
