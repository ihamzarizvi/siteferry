<?php
/**
 * Uncompressed archive output.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the TAR stream to a plain file.
 */
final class File_Output implements Output_Stream {

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Bytes written.
	 *
	 * @var int
	 */
	private $position;

	/**
	 * Constructor.
	 *
	 * @param resource $handle   Open file positioned at the end of valid data.
	 * @param int      $position Bytes already in the file.
	 */
	private function __construct( $handle, int $position ) {
		$this->handle   = $handle;
		$this->position = $position;
	}

	/**
	 * Creates a new file (it must not exist) or reopens one at a checkpoint.
	 *
	 * On resume, anything written after the checkpoint (by a request that died) is cut off.
	 *
	 * @param string     $path  Archive path.
	 * @param array|null $state Data from checkpoint(), or null for a new file.
	 * @throws Archive_Exception If the file cannot be opened or is shorter than the checkpoint.
	 */
	public static function open( string $path, ?array $state = null ): self {
		if ( null === $state ) {
			return new self( Io::open_or_fail( $path, 'xb' ), 0 );
		}

		$size   = (int) $state['size'];
		$handle = Io::open_or_fail( $path, 'r+b' );
		if ( Io::size( $handle ) < $size ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Archive_Exception( __( 'The archive is shorter than its saved progress; it was changed by something else.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		if ( ! ftruncate( $handle, $size ) || 0 !== fseek( $handle, $size ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Archive_Exception( __( 'Cannot rewind the archive to its saved progress.', 'siteferry' ), Archive_Exception::IO );
		}
		return new self( $handle, $size );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $data Bytes.
	 */
	public function write( string $data ): void {
		Io::write_all( $this->handle, $data );
		$this->position += strlen( $data );
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
	public function checkpoint(): array {
		fflush( $this->handle );
		return array(
			'size'     => $this->position,
			'position' => $this->position,
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
}
