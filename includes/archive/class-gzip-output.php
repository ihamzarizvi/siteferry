<?php
/**
 * Block-gzip archive output.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the TAR stream as a series of independent gzip members.
 *
 * A new member starts after about block_size uncompressed bytes and at every checkpoint, so a
 * resumed job never needs the compressor state of an earlier request. Concatenated gzip members
 * are still one valid .gz file (RFC 1952, section 2.2).
 */
final class Gzip_Output implements Output_Stream {

	const DEFAULT_BLOCK_SIZE = 8388608;
	const DEFAULT_LEVEL      = 6;

	/**
	 * Open file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Compressor for the current member, or null between members.
	 *
	 * @var resource|\DeflateContext|null
	 */
	private $deflate = null;

	/**
	 * Compressed bytes in the file.
	 *
	 * @var int
	 */
	private $size;

	/**
	 * Uncompressed bytes written.
	 *
	 * @var int
	 */
	private $position;

	/**
	 * Uncompressed bytes in the current member.
	 *
	 * @var int
	 */
	private $member_bytes = 0;

	/**
	 * Compression level 1-9.
	 *
	 * @var int
	 */
	private $level;

	/**
	 * Uncompressed bytes per member.
	 *
	 * @var int
	 */
	private $block_size;

	/**
	 * Constructor.
	 *
	 * @param resource $handle     Open file at the end of valid data.
	 * @param int      $size       Compressed bytes already in the file.
	 * @param int      $position   Uncompressed bytes already written.
	 * @param int      $level      Compression level.
	 * @param int      $block_size Member size.
	 */
	private function __construct( $handle, int $size, int $position, int $level, int $block_size ) {
		$this->handle     = $handle;
		$this->size       = $size;
		$this->position   = $position;
		$this->level      = max( 1, min( 9, $level ) );
		$this->block_size = max( 65536, $block_size );
	}

	/**
	 * Creates a new file (it must not exist) or reopens one at a checkpoint.
	 *
	 * @param string     $path       Archive path.
	 * @param array|null $state      Data from checkpoint(), or null for a new file.
	 * @param int        $level      Compression level 1-9.
	 * @param int        $block_size Uncompressed bytes per gzip member.
	 * @throws Archive_Exception If zlib is missing or the file cannot be opened.
	 */
	public static function open( string $path, ?array $state = null, int $level = self::DEFAULT_LEVEL, int $block_size = self::DEFAULT_BLOCK_SIZE ): self {
		if ( ! function_exists( 'deflate_init' ) ) {
			throw new Archive_Exception( 'Compression needs the PHP zlib extension.', Archive_Exception::IO );
		}
		if ( null === $state ) {
			return new self( Io::open_or_fail( $path, 'xb' ), 0, 0, $level, $block_size );
		}

		$size   = (int) $state['size'];
		$handle = Io::open_or_fail( $path, 'r+b' );
		if ( Io::size( $handle ) < $size ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Archive_Exception( 'The archive is shorter than its saved progress; it was changed by something else.', Archive_Exception::BAD_STATE );
		}
		if ( ! ftruncate( $handle, $size ) || 0 !== fseek( $handle, $size ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Archive_Exception( 'Cannot rewind the archive to its saved progress.', Archive_Exception::IO );
		}
		return new self( $handle, $size, (int) $state['position'], $level, $block_size );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $data Bytes.
	 * @throws Archive_Exception If the compressor fails.
	 */
	public function write( string $data ): void {
		if ( '' === $data ) {
			return;
		}
		if ( null === $this->deflate ) {
			$this->deflate = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $this->level ) );
			if ( false === $this->deflate ) {
				throw new Archive_Exception( 'Cannot start gzip compression.', Archive_Exception::IO );
			}
		}

		$this->emit( deflate_add( $this->deflate, $data, ZLIB_NO_FLUSH ) );
		$this->position     += strlen( $data );
		$this->member_bytes += strlen( $data );

		if ( $this->member_bytes >= $this->block_size ) {
			$this->end_member();
		}
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
		$this->end_member();
		fflush( $this->handle );
		return array(
			'size'     => $this->size,
			'position' => $this->position,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle  = null;
			$this->deflate = null;
		}
	}

	/**
	 * Finishes the current gzip member, if one is open.
	 */
	private function end_member(): void {
		if ( null !== $this->deflate ) {
			$this->emit( deflate_add( $this->deflate, '', ZLIB_FINISH ) );
			$this->deflate      = null;
			$this->member_bytes = 0;
		}
	}

	/**
	 * Writes compressed bytes to the file.
	 *
	 * @param string|false $compressed Output of deflate_add().
	 * @throws Archive_Exception If compression failed.
	 */
	private function emit( $compressed ): void {
		if ( false === $compressed ) {
			throw new Archive_Exception( 'gzip compression failed.', Archive_Exception::IO );
		}
		if ( '' !== $compressed ) {
			Io::write_all( $this->handle, $compressed );
			$this->size += strlen( $compressed );
		}
	}
}
