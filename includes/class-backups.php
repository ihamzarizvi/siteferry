<?php
/**
 * Finished backups on disk.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Export\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, reads, streams and deletes backups.
 */
final class Backups {

	/** Largest manifest accepted. */
	const MAX_MANIFEST_BYTES = 4194304;

	/** Names SiteFerry gives backups: <site>-<YYYY-MM-DD>-<12 hex>.tar[.gz] or .wpress. */
	const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,80}-\d{4}-\d{2}-\d{2}-[a-f0-9]{12}\.(tar(\.gz)?(\.enc)?|wpress)$/';

	/** Any backup file name the admin screen may refer to (no paths, no "..", .tar, .tar.gz or .wpress). */
	const SAFE_NAME = '/^[A-Za-z0-9][A-Za-z0-9_-]*(\.[A-Za-z0-9_-]+)*\.(tar(\.gz)?(\.enc)?|wpress)$/';

	/** Backup files in a folder: SiteFerry backups and .wpress backups. */
	const FILE_PATTERN = '/\.(tar(\.gz)?(\.enc)?|wpress)$/';

	/**
	 * Full path of a backup in a folder, by file name.
	 *
	 * @param string $dir  Backups folder.
	 * @param string $name File name.
	 * @throws \SiteFerry\Jobs\Job_Exception If the name is not a plain backup name or the file is missing.
	 */
	public static function path( string $dir, string $name ): string {
		if ( strlen( $name ) > 200 || ! preg_match( self::SAFE_NAME, $name ) || ! is_file( $dir . '/' . $name ) ) {
			throw new \SiteFerry\Jobs\Job_Exception( 'Unknown backup.', \SiteFerry\Jobs\Job_Exception::NOT_FOUND );
		}
		return $dir . '/' . $name;
	}

	/**
	 * Moves a received file (an upload or a download) into a backups folder after checking that it
	 * is a SiteFerry backup. A SiteFerry-style name is kept unless it is taken; any other name is
	 * replaced by an unguessable one.
	 *
	 * @param string $file File to move.
	 * @param string $name Name it arrived with.
	 * @param string $dir  Backups folder.
	 * @return string The backup's file name.
	 * @throws Archive_Exception If the file is not a SiteFerry backup.
	 * @throws \SiteFerry\Jobs\Job_Exception If it cannot be moved.
	 */
	public static function adopt( string $file, string $name, string $dir ): string {
		$wpress    = Wpress\Wpress::is_name( $name );
		$encrypted = ! $wpress && Archive\Crypto::is_encrypted( $file );
		if ( $wpress ) {
			Wpress\Wpress::describe( $file );
		} elseif ( $encrypted ) {
			Archive\Crypto::header( $file ); // Its contents are checked when it is restored, with the password.
		} else {
			self::manifest( $file );
		}
		if ( preg_match( self::NAME_PATTERN, $name ) && $encrypted === self::encrypted_name( $name ) ) {
			// A second copy of a SiteFerry backup keeps its site and date; only the random part changes.
			while ( file_exists( $dir . '/' . $name ) ) {
				$name = (string) preg_replace( '/-[a-f0-9]{12}(\.(tar(\.gz)?(\.enc)?|wpress))$/', '-' . Security\Storage::random_hex( 6 ) . '$1', $name );
			}
		} else {
			$compressed = (bool) preg_match( '/\.(tar\.gz|tgz)(\.enc)?$/i', $name );
			$base       = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) preg_replace( '/\.(tar\.gz|tgz|tar|wpress)(\.enc)?$/i', '', $name ) ) ), '-' );
			$extension  = $wpress ? 'wpress' : ( $compressed ? 'tar.gz' : 'tar' ) . ( $encrypted ? Archive\Crypto::SUFFIX : '' );
			$name       = sprintf( '%s-%s-%s.%s', '' === $base ? 'upload' : substr( $base, 0, 40 ), gmdate( 'Y-m-d' ), Security\Storage::random_hex( 6 ), $extension );
		}
		$target = $dir . '/' . $name;
		$moved  = Archive\Io::quiet(
			static function () use ( $file, $target ) {
				return rename( $file, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		);
		if ( ! $moved ) {
			throw new \SiteFerry\Jobs\Job_Exception( 'Cannot move the backup into place.', \SiteFerry\Jobs\Job_Exception::STEP );
		}
		return $name;
	}

	/**
	 * Sends a backup to the browser and ends the request.
	 *
	 * Supports a single "Range: bytes=a-b" so interrupted downloads can resume.
	 *
	 * @param string      $file  Backup file.
	 * @param string|null $range Range header, if any.
	 */
	public static function stream( string $file, ?string $range ): void {
		$size  = (int) filesize( $file );
		$start = 0;
		$end   = $size - 1;
		$code  = 200;

		if ( null !== $range && '' !== $range ) {
			if ( ! preg_match( '/^bytes=(\d*)-(\d*)$/', trim( $range ), $match ) || ( '' === $match[1] && '' === $match[2] ) ) {
				self::refuse_range( $size );
			}
			if ( '' === $match[1] ) {
				$start = max( 0, $size - (int) $match[2] );
			} else {
				$start = (int) $match[1];
				$end   = '' === $match[2] ? $size - 1 : min( (int) $match[2], $size - 1 );
			}
			if ( $start > $end || $start >= $size ) {
				self::refuse_range( $size );
			}
			$code = 206;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		Archive\Io::quiet(
			static function () {
				return set_time_limit( 0 );
			}
		);

		status_header( $code );
		$type = Wpress\Wpress::is_name( $file ) ? 'application/octet-stream' : ( '.gz' === substr( $file, -3 ) ? 'application/gzip' : 'application/x-tar' );
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		if ( 206 === $code ) {
			header( sprintf( 'Content-Range: bytes %d-%d/%d', $start, $end, $size ) );
		}

		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $handle, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$chunk = (string) fread( $handle, (int) min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file download.
			flush();
			$left -= strlen( $chunk );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Answers an unsatisfiable range and ends the request.
	 *
	 * @param int $size File size.
	 */
	private static function refuse_range( int $size ): void {
		status_header( 416 );
		header( 'Content-Range: bytes */' . $size );
		exit;
	}

	/**
	 * Backups in a folder, newest first.
	 *
	 * @param string $dir Folder.
	 * @return array<int,array{file:string,name:string,size:int,modified:int,manifest:array|null,error:string|null}>
	 */
	public static function in( string $dir ): array {
		$names = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( false === $names ) {
			return array();
		}

		$backups = array();
		foreach ( $names as $name ) {
			if ( ! preg_match( self::FILE_PATTERN, $name ) || ! is_file( $dir . '/' . $name ) ) {
				continue;
			}
			$path      = $dir . '/' . $name;
			$manifest  = null;
			$error     = null;
			$encrypted = self::encrypted_name( $name );
			try {
				if ( $encrypted ) {
					Archive\Crypto::header( $path ); // What is inside shows once the password is given.
				} else {
					$manifest = Wpress\Wpress::is_name( $name ) ? Wpress\Wpress::describe( $path ) : self::manifest( $path );
				}
				if ( ! empty( $manifest['encrypted'] ) ) {
					$error = 'Protected with a password; SiteFerry cannot open password-protected .wpress backups yet.';
				}
			} catch ( Archive_Exception $e ) {
				$error = $e->getMessage();
			}
			$backups[] = array(
				'file'     => $path,
				'name'     => $name,
				'size'     => (int) filesize( $path ),
				'modified' => (int) filemtime( $path ),
				'manifest'  => $manifest,
				'error'     => $error,
				'encrypted' => $encrypted,
			);
		}

		usort(
			$backups,
			static function ( array $a, array $b ): int {
				return $b['modified'] - $a['modified'];
			}
		);
		return $backups;
	}

	/**
	 * Whether a backup's name says it is encrypted.
	 *
	 * @param string $name File name.
	 */
	public static function encrypted_name( string $name ): bool {
		return Archive\Crypto::SUFFIX === substr( $name, -strlen( Archive\Crypto::SUFFIX ) );
	}

	/**
	 * Reads the manifest, which is the first entry of a SiteFerry backup.
	 *
	 * @param string $path Backup file.
	 * @return array
	 * @throws Archive_Exception If the file is not a SiteFerry backup.
	 */
	public static function manifest(
		string $path,
		#[\SensitiveParameter]
		?string $key = null
	): array {
		if ( Archive\Crypto::is_encrypted( $path ) ) {
			if ( null === $key ) {
				throw new Archive_Exception( 'This backup is protected with a password.', Archive_Exception::LOCKED );
			}
			$path = Archive\Crypto::url( $path, $key );
		}
		$reader = Tar_Reader::open( $path );
		try {
			$entry = $reader->next();
			if ( null === $entry || Manifest::ENTRY !== $entry->name || $entry->size > self::MAX_MANIFEST_BYTES ) {
				throw new Archive_Exception( 'This file is not a SiteFerry backup (no manifest).', Archive_Exception::CORRUPT );
			}
			$data = json_decode( $reader->read( $entry->size ), true );
		} finally {
			$reader->close();
		}
		if ( ! is_array( $data ) || Manifest::FORMAT !== ( isset( $data['format'] ) ? $data['format'] : null ) ) {
			throw new Archive_Exception( 'The backup manifest is damaged.', Archive_Exception::CORRUPT );
		}
		return $data;
	}
}
