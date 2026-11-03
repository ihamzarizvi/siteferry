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
 * Lists backups and reads their manifests.
 */
final class Backups {

	/** Largest manifest accepted. */
	const MAX_MANIFEST_BYTES = 4194304;

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
			if ( ! preg_match( '/\.tar(\.gz)?$/', $name ) || ! is_file( $dir . '/' . $name ) ) {
				continue;
			}
			$path     = $dir . '/' . $name;
			$manifest = null;
			$error    = null;
			try {
				$manifest = self::manifest( $path );
			} catch ( Archive_Exception $e ) {
				$error = $e->getMessage();
			}
			$backups[] = array(
				'file'     => $path,
				'name'     => $name,
				'size'     => (int) filesize( $path ),
				'modified' => (int) filemtime( $path ),
				'manifest' => $manifest,
				'error'    => $error,
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
	 * Reads the manifest, which is the first entry of a SiteFerry backup.
	 *
	 * @param string $path Backup file.
	 * @return array
	 * @throws Archive_Exception If the file is not a SiteFerry backup.
	 */
	public static function manifest( string $path ): array {
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
