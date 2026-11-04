<?php
/**
 * Chunked, resumable upload of a backup file.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Backups;
use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * An upload session: <uploads folder>/<id>/upload.json and data.part.
 *
 * The browser sends the file in chunks, each with its offset and SHA-256. A chunk is accepted
 * only at the current end of the file and only if its hash matches, so a retried or duplicated
 * chunk can never corrupt the file. Bytes a dead request wrote after the last recorded offset are
 * cut off before the next chunk. Only post_max_size limits the chunk size; upload_max_filesize
 * does not apply because chunks are sent as the raw request body.
 */
final class Upload {

	/** Largest chunk accepted. */
	const MAX_CHUNK = 16777216;

	/** Unfinished uploads older than this are removed when a new one starts. */
	const MAX_AGE = 604800;

	/**
	 * Session folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Session data: id, name, size, offset, created, updated.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string $dir  Session folder.
	 * @param array  $data Session data.
	 */
	private function __construct( string $dir, array $data ) {
		$this->dir  = $dir;
		$this->data = $data;
	}

	/**
	 * Starts an upload.
	 *
	 * @param string $base Uploads folder.
	 * @param string $name Original file name (.tar, .tar.gz or .tgz).
	 * @param int    $size Size in bytes.
	 * @throws Job_Exception On a bad name or size, or too little disk space.
	 */
	public static function create( string $base, string $name, int $size ): self {
		$name = self::clean_name( $name );
		if ( ! preg_match( '/\.(tar|tar\.gz|tgz|wpress)$/i', $name ) ) {
			throw new Job_Exception( 'Only SiteFerry backups (.tar or .tar.gz) and All-in-One WP Migration backups (.wpress) can be uploaded.', Job_Exception::STEP );
		}
		if ( $size <= 0 ) {
			throw new Job_Exception( 'The file is empty.', Job_Exception::STEP );
		}
		$free = function_exists( 'disk_free_space' ) ? Io::quiet(
			static function () use ( $base ) {
				return disk_free_space( $base );
			}
		) : false;
		if ( false !== $free && $free < $size + 52428800 ) {
			throw new Job_Exception( 'Not enough disk space for this upload.', Job_Exception::STEP );
		}

		self::remove_stale( $base );
		$id  = bin2hex( random_bytes( 12 ) );
		$dir = rtrim( $base, '/\\' ) . '/' . $id;
		if ( ! Io::quiet(
			static function () use ( $dir ) {
				return mkdir( $dir, 0755, true );
			}
		) ) {
			throw new Job_Exception( 'Cannot create the upload folder.', Job_Exception::STEP );
		}
		touch( $dir . '/data.part' );

		$upload = new self(
			$dir,
			array(
				'id'      => $id,
				'name'    => $name,
				'size'    => $size,
				'offset'  => 0,
				'created' => time(),
				'updated' => time(),
			)
		);
		$upload->save();
		return $upload;
	}

	/**
	 * Loads an upload session.
	 *
	 * @param string $base Uploads folder.
	 * @param string $id   Session id.
	 * @throws Job_Exception If it does not exist.
	 */
	public static function load( string $base, string $id ): self {
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $id ) ) {
			throw new Job_Exception( 'Unknown upload.', Job_Exception::NOT_FOUND );
		}
		$dir  = rtrim( $base, '/\\' ) . '/' . $id;
		$json = is_file( $dir . '/upload.json' ) ? file_get_contents( $dir . '/upload.json' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['id'] ) || $id !== $data['id'] ) {
			throw new Job_Exception( 'Unknown upload.', Job_Exception::NOT_FOUND );
		}
		return new self( $dir, $data );
	}

	/**
	 * Unfinished uploads, newest first.
	 *
	 * @param string $base Uploads folder.
	 * @return Upload[]
	 */
	public static function all( string $base ): array {
		$names   = is_dir( $base ) ? scandir( $base ) : false;
		$uploads = array();
		foreach ( false === $names ? array() : $names as $name ) {
			if ( preg_match( '/^[a-f0-9]{24}$/', $name ) ) {
				try {
					$uploads[] = self::load( $base, $name );
				} catch ( Job_Exception $e ) {
					continue;
				}
			}
		}
		usort(
			$uploads,
			static function ( Upload $a, Upload $b ): int {
				return $b->data['updated'] - $a->data['updated'];
			}
		);
		return $uploads;
	}

	/**
	 * Appends one chunk.
	 *
	 * @param int    $offset Where the chunk starts (must be the current end).
	 * @param string $bytes  Chunk data.
	 * @param string $sha256 Lowercase hex SHA-256 of the chunk, as computed by the browser.
	 * @return int The new offset.
	 * @throws Job_Exception On a wrong offset (code BUSY, so the client resynchronizes), a hash
	 *                       mismatch, an oversized chunk or a write error.
	 */
	public function append( int $offset, string $bytes, string $sha256 ): int {
		$length = strlen( $bytes );
		if ( 0 === $length || $length > self::MAX_CHUNK ) {
			throw new Job_Exception( 'The chunk is empty or too large.', Job_Exception::STEP );
		}
		if ( ! hash_equals( strtolower( $sha256 ), hash( 'sha256', $bytes ) ) ) {
			throw new Job_Exception( 'The chunk was damaged in transit (checksum mismatch); it will be sent again.', Job_Exception::STEP );
		}

		$handle = Io::open_or_fail( $this->dir . '/data.part', 'c+b' );
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new Job_Exception( 'The upload is busy.', Job_Exception::BUSY );
			}
			$this->reload();
			if ( $offset !== $this->data['offset'] ) {
				throw new Job_Exception( sprintf( 'Expected the chunk at byte %d, not %d.', $this->data['offset'], $offset ), Job_Exception::BUSY );
			}
			if ( $offset + $length > $this->data['size'] ) {
				throw new Job_Exception( 'The chunk goes past the end of the file.', Job_Exception::STEP );
			}
			// Cut off anything a request that died after writing (but before saving) left behind.
			if ( ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) ) {
				throw new Archive_Exception( 'Cannot write the upload.', Archive_Exception::IO );
			}
			Io::write_all( $handle, $bytes );
			fflush( $handle );
			$this->data['offset']  = $offset + $length;
			$this->data['updated'] = time();
			$this->save();
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $this->data['offset'];
	}

	/**
	 * Checks the finished file and moves it into the backups folder.
	 *
	 * @param string $backups Backups folder.
	 * @return string Backup file name.
	 * @throws Job_Exception If the upload is incomplete or not a SiteFerry backup.
	 */
	public function finish( string $backups ): string {
		if ( $this->data['offset'] !== $this->data['size'] ) {
			throw new Job_Exception( 'The upload is not complete yet.', Job_Exception::STEP );
		}
		try {
			$name = Backups::adopt( $this->dir . '/data.part', $this->data['name'], $backups );
		} catch ( Archive_Exception $e ) {
			$this->delete();
			throw new Job_Exception( 'The uploaded file is not a backup SiteFerry can import: ' . $e->getMessage(), Job_Exception::STEP );
		}
		$this->delete();
		return $name;
	}

	/**
	 * Removes the session and its data.
	 */
	public function delete(): void {
		Io::remove_tree( $this->dir );
	}

	/**
	 * Session data for the browser.
	 *
	 * @return array{id:string,name:string,size:int,offset:int}
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->data['id'],
			'name'   => $this->data['name'],
			'size'   => (int) $this->data['size'],
			'offset' => (int) $this->data['offset'],
		);
	}

	/**
	 * Re-reads upload.json (another request may have appended).
	 */
	private function reload(): void {
		$json = file_get_contents( $this->dir . '/upload.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false === $json ? null : json_decode( $json, true );
		if ( is_array( $data ) ) {
			$this->data = $data;
		}
	}

	/**
	 * Writes upload.json atomically.
	 *
	 * @throws Job_Exception If it cannot be written.
	 */
	private function save(): void {
		$temp  = $this->dir . '/upload.json.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		$final = $this->dir . '/upload.json';
		$json  = (string) json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$ok    = false !== Io::quiet(
			static function () use ( $temp, $json ) {
				return file_put_contents( $temp, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		) && Io::quiet(
			static function () use ( $temp, $final ) {
				return rename( $temp, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		);
		if ( ! $ok ) {
			throw new Job_Exception( 'Cannot save the upload state.', Job_Exception::STEP );
		}
	}

	/**
	 * A file name reduced to safe characters (display and extension checks only).
	 *
	 * @param string $name Name from the browser.
	 */
	private static function clean_name( string $name ): string {
		$name = basename( str_replace( '\\', '/', $name ) );
		$name = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name );
		return substr( trim( $name, '.-' ), 0, 120 );
	}

	/**
	 * Removes unfinished uploads older than MAX_AGE.
	 *
	 * @param string $base Uploads folder.
	 */
	private static function remove_stale( string $base ): void {
		foreach ( self::all( $base ) as $upload ) {
			if ( time() - (int) $upload->data['updated'] > self::MAX_AGE ) {
				$upload->delete();
			}
		}
	}
}
