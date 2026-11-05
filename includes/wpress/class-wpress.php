<?php
/**
 * .wpress backups in the backups folder.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes .wpress backups (the format of the All-in-One WP Migration plugin) and describes
 * them for the backups list, and decides which of their entries an import leaves out.
 */
final class Wpress {

	const EXTENSION = '.wpress';

	/** Shown for files made by other tools. */
	const LABEL = 'All-in-One WP Migration';

	/**
	 * Whether a file name has the .wpress extension.
	 *
	 * @param string $name File name.
	 */
	public static function is_name( string $name ): bool {
		return self::EXTENSION === strtolower( substr( $name, -strlen( self::EXTENSION ) ) );
	}

	/**
	 * What the backups list shows for a .wpress file, read from its package.json: created,
	 * site.home, versions.wordpress, plus format "wpress" and whether it is encrypted.
	 *
	 * @param string $path File.
	 * @return array
	 * @throws Archive_Exception If it is not a .wpress backup.
	 */
	public static function describe( string $path ): array {
		$reader = Reader::open( $path );
		try {
			$entry = $reader->next();
			$raw   = $reader->raw();
			if ( null === $entry || 'package.json' !== $entry->name || '.' !== $raw['path'] || $entry->size > Package::MAX_BYTES ) {
				throw new Archive_Exception( __( 'This file is not a .wpress backup (it has no package.json).', 'siteferry' ), Archive_Exception::CORRUPT );
			}
			$package = Package::parse( $reader->read( $entry->size ) );
		} finally {
			$reader->close();
		}
		$wordpress = isset( $package['WordPress'] ) && is_array( $package['WordPress'] ) ? $package['WordPress'] : array();
		return array(
			'format'    => 'wpress',
			'created'   => gmdate( 'Y-m-d\TH:i:s\Z', $entry->mtime > 0 ? $entry->mtime : (int) filemtime( $path ) ),
			'site'      => array( 'home' => untrailingslashit( (string) $package['HomeURL'] ) ),
			'versions'  => array( 'wordpress' => isset( $wordpress['Version'] ) ? (string) $wordpress['Version'] : '' ),
			'encrypted' => Package::encrypted( $package ),
		);
	}

	/**
	 * Entries an import does not unpack (relative to wp-content): the package and the dump, which
	 * are read separately; other SiteFerry storage folders (backups and job files of the site the
	 * backup came from); All-in-One WP Migration's own backups folder; and SiteFerry's plugin folder,
	 * which stays as it is on this site.
	 *
	 * @param string $relative "<folder>/<name>", or "<name>" at the top.
	 */
	public static function skipped( string $relative ): bool {
		if ( in_array( $relative, array( 'package.json', 'database.sql', 'multisite.json', 'blogs.json' ), true ) ) {
			return true;
		}
		if ( preg_match( '#^(siteferry-[a-f0-9]{12}|ai1wm-backups)(/|$)#', $relative ) ) {
			return true;
		}
		$own = 'plugins/' . \SiteFerry\Plugin::folder() . '/';
		return 0 === strpos( $relative . '/', $own );
	}

	/**
	 * Copies a byte range of a file into another, checking its CRC-32, resumably.
	 *
	 * @param string                       $from   Source file.
	 * @param int                          $offset Start of the range.
	 * @param int                          $length Length of the range.
	 * @param string                       $to     Target file.
	 * @param array                        $state  done (bytes copied), crc (of them).
	 * @param \SiteFerry\Jobs\Slice_Budget $budget Work limit.
	 * @return array The new state; done === $length when finished.
	 * @throws Archive_Exception On read or write errors.
	 */
	public static function copy_range( string $from, int $offset, int $length, string $to, array $state, \SiteFerry\Jobs\Slice_Budget $budget ): array {
		$done = (int) $state['done'];
		$crc  = (int) $state['crc'];
		$in   = Io::open_or_fail( $from, 'rb' );
		$out  = Io::open_or_fail( $to, 'c+b' );
		try {
			if ( 0 !== fseek( $in, $offset + $done ) || ! ftruncate( $out, $done ) || 0 !== fseek( $out, $done ) ) {
				throw new Archive_Exception( __( 'Cannot continue copying from the .wpress backup.', 'siteferry' ), Archive_Exception::IO );
			}
			while ( $done < $length ) {
				$want = (int) min( Reader::CHUNK, $length - $done );
				$data = (string) fread( $in, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( strlen( $data ) !== $want ) {
					throw new Archive_Exception( __( 'The .wpress archive is incomplete (it ends too early).', 'siteferry' ), Archive_Exception::TRUNCATED );
				}
				Io::write_all( $out, $data );
				$crc   = Crc32::update( $crc, $data );
				$done += $want;
				$budget->consume( $want );
				if ( $done < $length && $budget->exhausted() ) {
					break;
				}
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return array(
			'done' => $done,
			'crc'  => $crc,
		);
	}
}
