<?php
/**
 * Streaming, resumable writer of .wpress archives.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Writes entries one after another. A file's size is given up front; its CRC-32 is computed while
 * the data is written and then filled into its header. seal() computes the CRC-32 of the whole
 * archive (reading it back, resumably) and writes the closing header.
 *
 * checkpoint() returns a state from which resume() continues after the last flushed byte; anything
 * written after it (by a request that died) is cut off.
 */
final class Writer {

	/** Bytes read at a time while sealing. */
	const CHUNK = 8388608;

	/**
	 * Archive file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Bytes written.
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * Entry being written: header_at, name, size, written, crc.
	 *
	 * @var array|null
	 */
	private $current = null;

	/**
	 * Sealing progress: offset, crc; null before seal() starts.
	 *
	 * @var array|null
	 */
	private $seal = null;

	/**
	 * True after the closing header.
	 *
	 * @var bool
	 */
	private $sealed = false;

	/**
	 * Constructor.
	 *
	 * @param resource $handle Open file.
	 */
	private function __construct( $handle ) {
		$this->handle = $handle;
	}

	/**
	 * Starts a new archive (an existing file is replaced).
	 *
	 * @param string $path Archive path.
	 * @throws Archive_Exception If the file cannot be created.
	 */
	public static function create( string $path ): self {
		return new self( Io::open_or_fail( $path, 'w+b' ) );
	}

	/**
	 * Continues an archive from a checkpoint.
	 *
	 * @param string $path  Archive path.
	 * @param array  $state Data from checkpoint().
	 * @throws Archive_Exception If the file is shorter than the checkpoint.
	 */
	public static function resume( string $path, array $state ): self {
		if ( ! isset( $state['version'] ) || 1 !== $state['version'] ) {
			throw new Archive_Exception( 'Invalid saved writer state.', Archive_Exception::BAD_STATE );
		}
		$handle   = Io::open_or_fail( $path, 'c+b' );
		$position = (int) $state['position'];
		if ( Io::size( $handle ) < $position || ! ftruncate( $handle, $position ) || 0 !== fseek( $handle, $position ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Archive_Exception( 'The .wpress archive being written is shorter than expected.', Archive_Exception::BAD_STATE );
		}
		$writer           = new self( $handle );
		$writer->position = $position;
		$writer->current  = empty( $state['current'] ) ? null : (array) $state['current'];
		$writer->seal     = empty( $state['seal'] ) ? null : (array) $state['seal'];
		$writer->sealed   = ! empty( $state['sealed'] );
		return $writer;
	}

	/**
	 * Adds a file from a string.
	 *
	 * @param string $name  File name.
	 * @param string $path  Folder relative to wp-content ("." for the top).
	 * @param string $data  Contents.
	 * @param int    $mtime Modification time.
	 * @throws Archive_Exception If an entry is still open or a field does not fit.
	 */
	public function add_string( string $name, string $path, string $data, int $mtime ): void {
		$this->assert_open( false );
		$this->write_raw( Header::build( $name, strlen( $data ), $mtime, $path, Crc32::of( $data ) ) . $data );
	}

	/**
	 * Starts a file whose data follows through write().
	 *
	 * @param string $name  File name.
	 * @param string $path  Folder relative to wp-content ("." for the top).
	 * @param int    $size  Exact data size.
	 * @param int    $mtime Modification time.
	 * @throws Archive_Exception If an entry is still open or a field does not fit.
	 */
	public function begin( string $name, string $path, int $size, int $mtime ): void {
		$this->assert_open( false );
		$header        = Header::build( $name, $size, $mtime, $path, null );
		$this->current = array(
			'header_at' => $this->position,
			'name'      => $name,
			'size'      => $size,
			'written'   => 0,
			'crc'       => 0,
		);
		$this->write_raw( $header );
		if ( 0 === $size ) {
			$this->end();
		}
	}

	/**
	 * Appends data to the open file.
	 *
	 * @param string $data Data.
	 * @throws Archive_Exception If there is more data than announced.
	 */
	public function write( string $data ): void {
		$this->assert_open( true );
		if ( $this->current['written'] + strlen( $data ) > $this->current['size'] ) {
			throw new Archive_Exception( sprintf( 'The file "%s" grew while it was being archived.', $this->current['name'] ), Archive_Exception::IO );
		}
		$this->write_raw( $data );
		$this->current['written'] += strlen( $data );
		$this->current['crc']      = Crc32::update( (int) $this->current['crc'], $data );
		if ( $this->current['written'] === $this->current['size'] ) {
			$this->end();
		}
	}

	/**
	 * Whether a file is open (started, not all of its data written).
	 */
	public function has_open_entry(): bool {
		return null !== $this->current;
	}

	/**
	 * Bytes still expected for the open file.
	 */
	public function remaining(): int {
		return null === $this->current ? 0 : $this->current['size'] - $this->current['written'];
	}

	/**
	 * Computes the archive's CRC-32 and writes the closing header.
	 *
	 * @param Slice_Budget|null $budget Work limit.
	 * @return bool True when the archive is complete.
	 * @throws Archive_Exception If a file is still open or the archive cannot be read back.
	 */
	public function seal( ?Slice_Budget $budget = null ): bool {
		if ( $this->sealed ) {
			return true;
		}
		$this->assert_open( false );
		if ( null === $this->seal ) {
			$this->seal = array(
				'offset' => 0,
				'crc'    => 0,
			);
		}
		fflush( $this->handle );
		while ( $this->seal['offset'] < $this->position ) {
			if ( 0 !== fseek( $this->handle, $this->seal['offset'] ) ) {
				throw new Archive_Exception( 'Cannot read back the .wpress archive.', Archive_Exception::IO );
			}
			$want = (int) min( self::CHUNK, $this->position - $this->seal['offset'] );
			$data = (string) fread( $this->handle, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( strlen( $data ) !== $want ) {
				throw new Archive_Exception( 'Cannot read back the .wpress archive.', Archive_Exception::IO );
			}
			$this->seal['crc']     = Crc32::update( (int) $this->seal['crc'], $data );
			$this->seal['offset'] += $want;
			if ( null !== $budget ) {
				$budget->consume( $want );
				if ( $this->seal['offset'] < $this->position && $budget->exhausted() ) {
					fseek( $this->handle, $this->position );
					return false;
				}
			}
		}
		fseek( $this->handle, $this->position );
		$this->write_raw( Header::end( $this->position, (int) $this->seal['crc'] ) );
		$this->sealed = true;
		return true;
	}

	/**
	 * Bytes written so far.
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Flushes and returns a state for resume().
	 *
	 * @return array JSON-safe state.
	 */
	public function checkpoint(): array {
		fflush( $this->handle );
		return array(
			'version'  => 1,
			'position' => $this->position,
			'current'  => $this->current,
			'seal'     => $this->seal,
			'sealed'   => $this->sealed,
		);
	}

	/**
	 * Closes the file.
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle = null;
		}
	}

	/**
	 * Fills the finished file's CRC into its header.
	 */
	private function end(): void {
		$at = (int) $this->current['header_at'] + Header::CRC_AT;
		if ( 0 !== fseek( $this->handle, $at ) ) {
			throw new Archive_Exception( 'Cannot finish an entry of the .wpress archive.', Archive_Exception::IO );
		}
		Io::write_all( $this->handle, Crc32::hex( (int) $this->current['crc'] ) );
		fseek( $this->handle, $this->position );
		$this->current = null;
	}

	/**
	 * Writes at the end of the archive.
	 *
	 * @param string $data Bytes.
	 */
	private function write_raw( string $data ): void {
		Io::write_all( $this->handle, $data );
		$this->position += strlen( $data );
	}

	/**
	 * Checks whether an entry is (or is not) open.
	 *
	 * @param bool $open Expected.
	 * @throws Archive_Exception Otherwise.
	 */
	private function assert_open( bool $open ): void {
		if ( $this->sealed ) {
			throw new Archive_Exception( 'The .wpress archive is already complete.', Archive_Exception::BAD_STATE );
		}
		if ( $open !== ( null !== $this->current ) ) {
			throw new Archive_Exception( $open ? 'No file is open in the .wpress archive.' : 'A file in the .wpress archive is not finished.', Archive_Exception::BAD_STATE );
		}
	}
}
