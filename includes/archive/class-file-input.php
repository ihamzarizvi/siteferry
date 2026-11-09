<?php
/**
 * Uncompressed archive input.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * Reads a plain TAR file.
 */
final class File_Input implements Input_Stream {

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Current offset.
	 *
	 * @var int
	 */
	private $position;

	/**
	 * File size when opened.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Opens a file at the start or at a saved position.
	 *
	 * @param string     $path  Archive path.
	 * @param array|null $state Data from state(), or null to start at the beginning.
	 * @throws Archive_Exception If the file cannot be opened or the position is invalid.
	 */
	public function __construct( string $path, ?array $state = null ) {
		$this->handle   = Io::open_or_fail( $path, 'rb' );
		$this->size     = Io::size( $this->handle );
		$this->position = null === $state ? 0 : (int) $state['position'];
		if ( $this->position > $this->size || 0 !== fseek( $this->handle, $this->position ) ) {
			$this->close();
			throw new Archive_Exception( __( 'Saved read position is past the end of the archive.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $length Bytes wanted.
	 */
	public function read( int $length ): string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$chunk = fread( $this->handle, $length - strlen( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		$this->position += strlen( $data );
		return $data;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $length Bytes to skip.
	 */
	public function skip( int $length ): int {
		$length = max( 0, min( $length, $this->size - $this->position ) );
		if ( $length > 0 ) {
			fseek( $this->handle, $this->position + $length );
			$this->position += $length;
		}
		return $length;
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
		return array( 'position' => $this->position );
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
