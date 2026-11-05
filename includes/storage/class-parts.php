<?php
/**
 * Backups stored in parts.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * A storage with a size limit gets a backup as numbered parts: "<name>.001", "<name>.002", ...,
 * each at most the limit set for it; joined in order they are the backup, byte for byte. The
 * backup on this server stays one file. Listings show a set of parts as one backup.
 */
final class Parts {

	/** "<backup name>.<3 digits>". */
	const PATTERN = '/^(.+)\.(\d{3})$/';

	/** Most parts a backup is cut into. */
	const MAX = 999;

	/** Smallest and largest part size that can be set, in MB. */
	const MIN_MB = 10;
	const MAX_MB = 1048576;

	/**
	 * The name of part $number (from 1).
	 *
	 * @param string $name   Backup name.
	 * @param int    $number Part number.
	 */
	public static function name( string $name, int $number ): string {
		return sprintf( '%s.%03d', $name, $number );
	}

	/**
	 * Whether a file on a storage is a backup or a part of one.
	 *
	 * @param string $name File name.
	 */
	public static function stored_name( string $name ): bool {
		if ( strlen( $name ) > 200 ) {
			return false;
		}
		if ( preg_match( Backups::SAFE_NAME, $name ) ) {
			return true;
		}
		return (bool) preg_match( self::PATTERN, $name, $match ) && preg_match( Backups::SAFE_NAME, $match[1] ) && (int) $match[2] >= 1;
	}

	/**
	 * How a backup is cut for a storage: [name, offset, length] per piece (one piece, the backup
	 * itself, when it fits).
	 *
	 * @param string $name  Backup name.
	 * @param int    $size  Its size.
	 * @param int    $limit Largest piece in bytes, 0 for no limit.
	 * @return array<int,array{0:string,1:int,2:int}>
	 * @throws Storage_Exception If it would take too many parts.
	 */
	public static function pieces( string $name, int $size, int $limit ): array {
		if ( $limit <= 0 || $size <= $limit ) {
			return array( array( $name, 0, $size ) );
		}
		$count = (int) ceil( $size / $limit );
		if ( $count > self::MAX ) {
			throw new Storage_Exception( sprintf( /* translators: 1: a number, 2: a size, such as 12 MB, 3: a number */ __( 'The backup would take %1$d parts of %2$s; choose larger parts for this storage (at most %3$d parts).', 'siteferry' ), $count, size_format( $limit ), self::MAX ), Storage_Exception::CONFIG );
		}
		$pieces = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$pieces[] = array( self::name( $name, $i + 1 ), $i * $limit, (int) min( $limit, $size - $i * $limit ) );
		}
		return $pieces;
	}

	/**
	 * A storage listing with every set of parts shown as one backup: its name, the size of all
	 * parts, the newest time, "parts" (1 for a whole file), "pieces" ([name, size] in order) and
	 * "complete" (false when a part is missing).
	 *
	 * @param array $list Items with name, size and modified.
	 */
	public static function group( array $list ): array {
		$whole = array();
		$sets  = array();
		foreach ( $list as $item ) {
			$name = (string) $item['name'];
			if ( preg_match( self::PATTERN, $name, $match ) && preg_match( Backups::SAFE_NAME, $match[1] ) ) {
				$sets[ $match[1] ][ (int) $match[2] ] = $item;
				continue;
			}
			$item['parts']    = 1;
			$item['pieces']   = array( array( $name, (int) $item['size'] ) );
			$item['complete'] = true;
			$whole[ $name ]   = $item;
		}
		foreach ( $sets as $name => $parts ) {
			if ( isset( $whole[ $name ] ) ) {
				continue; // The whole file is there too: that one counts.
			}
			ksort( $parts );
			$pieces = array();
			$size   = 0;
			$time   = 0;
			foreach ( $parts as $part ) {
				$pieces[] = array( (string) $part['name'], (int) $part['size'] );
				$size    += (int) $part['size'];
				$time     = max( $time, (int) $part['modified'] );
			}
			$whole[ $name ] = array(
				'name'     => $name,
				'size'     => $size,
				'modified' => $time,
				'parts'    => count( $parts ),
				'pieces'   => $pieces,
				'complete' => array_keys( $parts ) === range( 1, count( $parts ) ),
			);
		}
		$out = array_values( $whole );
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return (int) $b['modified'] - (int) $a['modified'];
			}
		);
		return $out;
	}

	/**
	 * One backup on a storage (whole, or all its parts), or null.
	 *
	 * @param Driver $driver Storage.
	 * @param string $name   Backup name.
	 */
	public static function find( Driver $driver, string $name ): ?array {
		foreach ( self::group( $driver->list() ) as $item ) {
			if ( $item['name'] === $name ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Deletes a backup on a storage, all of its parts.
	 *
	 * @param Driver $driver Storage.
	 * @param string $name   Backup name.
	 */
	public static function delete( Driver $driver, string $name ): void {
		$item = self::find( $driver, $name );
		if ( null === $item ) {
			$driver->delete( $name ); // Lets the storage say that it is not there.
			return;
		}
		foreach ( $item['pieces'] as $piece ) {
			$driver->delete( (string) $piece[0] );
		}
	}

	/**
	 * Part size for a value from the settings form, in MB (0: whole files).
	 *
	 * @param mixed $value Input.
	 * @throws Storage_Exception If it is out of range.
	 */
	public static function normalize_mb( $value ): int {
		$mb = (int) $value;
		if ( 0 !== $mb && ( $mb < self::MIN_MB || $mb > self::MAX_MB ) ) {
			throw new Storage_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'Parts must be between %1$d MB and %2$d MB (or 0 for whole files).', 'siteferry' ), self::MIN_MB, self::MAX_MB ), Storage_Exception::CONFIG );
		}
		return $mb;
	}
}
