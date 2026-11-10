<?php
/**
 * "Changes only" backups: a backup that holds the files changed since one full backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * A changes-only backup is always based on ONE full SiteFerry backup on this server (never on
 * another changes-only backup, so no chains). It holds the whole database, every folder entry,
 * the files that are new or whose size or modification time differ from the full backup's, and
 * a complete checksum list for the whole tree (the unchanged files' checksums are carried over
 * from the full backup). Its manifest names the full backup ("base": name, created and a
 * fingerprint, the SHA-256 of the full backup's checksum list) and lists the files of the full
 * backup that are gone ("deleted"); the entry siteferry.base lists the files a restore takes from
 * the full backup.
 *
 * Files are compared by size and modification time only: a file changed without changing either
 * would be missed. In practice that does not happen (editors, updates and uploads all change the
 * time), and it keeps a changes-only backup as quick to make as a scan.
 *
 * Not for password-protected backups or .wpress files (as the full backup or as the changes).
 */
final class Incremental {

	/** Manifest format version of a changes-only backup (older SiteFerry versions refuse it). */
	const FORMAT_VERSION = 2;

	/** Tag of backups made before an update: they are partial, so they are never a full backup to build on. */
	const UPDATE_TAG = 'before-update';

	/**
	 * Why a backup on this server cannot be the full backup of a changes-only backup; null if it can.
	 *
	 * @param string     $path     Backup file.
	 * @param array|null $manifest Its manifest when already read.
	 */
	public static function unsuitable_base( string $path, ?array $manifest = null ): ?string {
		$name = basename( $path );
		if ( Backups::encrypted_name( $name ) ) {
			return __( 'Password-protected backups cannot be the base of a changes-only backup.', 'siteferry' );
		}
		if ( Wpress\Wpress::is_name( $name ) ) {
			return __( 'A .wpress file cannot be the base of a changes-only backup.', 'siteferry' );
		}
		if ( null === $manifest ) {
			try {
				$manifest = Backups::manifest( $path );
			} catch ( \Throwable $e ) {
				return $e->getMessage();
			}
		}
		if ( ! empty( $manifest['base'] ) ) {
			return __( 'That backup holds only changes itself; a changes-only backup needs a full backup to build on.', 'siteferry' );
		}
		$tags = isset( $manifest['options']['tags'] ) ? (array) $manifest['options']['tags'] : array();
		if ( in_array( self::UPDATE_TAG, $tags, true ) ) {
			return __( 'A backup made before an update holds only part of the site; it cannot be the base of a changes-only backup.', 'siteferry' );
		}
		$home = isset( $manifest['site']['home'] ) ? untrailingslashit( strtolower( (string) $manifest['site']['home'] ) ) : '';
		if ( untrailingslashit( strtolower( (string) get_option( 'home' ) ) ) !== $home ) {
			return __( 'That backup is of another site.', 'siteferry' );
		}
		return null;
	}

	/**
	 * The newest backup on this server that can be the base of a changes-only backup, or null.
	 *
	 * @param array|null $backups Backups::in() output, when already read.
	 */
	public static function latest_base( ?array $backups = null ): ?string {
		$dir     = Security\Storage::existing_backups_dir();
		$backups = null === $backups ? ( null === $dir ? array() : Backups::in( $dir ) ) : $backups;
		$best    = null;
		$time    = '';
		foreach ( $backups as $backup ) {
			if ( null !== $backup['error'] || ! is_array( $backup['manifest'] ) || null !== self::unsuitable_base( $backup['file'], $backup['manifest'] ) ) {
				continue;
			}
			$created = (string) $backup['manifest']['created'];
			if ( null === $best || strcmp( $created, $time ) > 0 ) {
				$best = $backup['name'];
				$time = $created;
			}
		}
		return $best;
	}

	/**
	 * The base a backup is built on: name, created, fingerprint; null for a full backup.
	 *
	 * @param array|null $manifest Manifest.
	 * @return array{name:string,created:string,fingerprint:string,files:int}|null
	 */
	public static function base_of( ?array $manifest ): ?array {
		if ( ! is_array( $manifest ) || empty( $manifest['base'] ) || ! is_array( $manifest['base'] ) ) {
			return null;
		}
		$base = $manifest['base'];
		$name = isset( $base['name'] ) ? (string) $base['name'] : '';
		if ( ! preg_match( Backups::SAFE_NAME, $name ) ) {
			return null;
		}
		return array(
			'name'        => $name,
			'created'     => isset( $base['created'] ) ? (string) $base['created'] : '',
			'fingerprint' => isset( $base['fingerprint'] ) ? (string) $base['fingerprint'] : '',
			'files'       => isset( $base['files'] ) ? (int) $base['files'] : 0,
		);
	}

	/**
	 * The changes-only backups on this server built on a backup, newest first.
	 *
	 * @param string     $name    Name of the full backup.
	 * @param array|null $backups Backups::in() output, when already read.
	 * @return string[]
	 */
	public static function dependents( string $name, ?array $backups = null ): array {
		if ( null === $backups ) {
			$dir     = Security\Storage::existing_backups_dir();
			$backups = null === $dir ? array() : Backups::in( $dir );
		}
		$out = array();
		foreach ( $backups as $backup ) {
			$base = self::base_of( is_array( $backup['manifest'] ) ? $backup['manifest'] : null );
			if ( null !== $base && $base['name'] === $name ) {
				$out[] = (string) $backup['name'];
			}
		}
		return $out;
	}

	/**
	 * Refuses to delete a full backup that changes-only backups on this server are built on.
	 *
	 * @param string $name Backup name.
	 * @throws Job_Exception If changes-only backups depend on it.
	 */
	public static function guard_delete( string $name ): void {
		$dependents = self::dependents( $name );
		if ( array() !== $dependents ) {
			throw new Job_Exception(
				sprintf(
					/* translators: %s: names of backups, separated by commas */
					__( 'This full backup cannot be deleted: these changes-only backups are built on it and could not be restored without it: %s. Delete them first.', 'siteferry' ),
					implode( ', ', $dependents )
				),
				Job_Exception::STEP
			);
		}
	}

	/**
	 * Refuses to delete a full backup on a storage while changes-only backups built on it are stored
	 * there too (known from the changes-only backups on this server, which name their full backup).
	 *
	 * @param Storage\Driver $driver Storage.
	 * @param string         $name   Backup name.
	 * @throws Job_Exception If changes-only backups on that storage depend on it.
	 */
	public static function guard_remote_delete( Storage\Driver $driver, string $name ): void {
		$stored = array();
		foreach ( self::dependents( $name ) as $dependent ) {
			if ( null !== Storage\Parts::find( $driver, $dependent ) ) {
				$stored[] = $dependent;
			}
		}
		if ( array() !== $stored ) {
			throw new Job_Exception(
				sprintf(
					/* translators: %s: names of backups, separated by commas */
					__( 'This full backup cannot be deleted from the storage: these changes-only backups there are built on it and could not be restored without it: %s. Delete them first.', 'siteferry' ),
					implode( ', ', $stored )
				),
				Job_Exception::STEP
			);
		}
	}

	/**
	 * Reads one small entry (the checksum list, the base list) from a backup.
	 *
	 * @param string $file Backup file (not encrypted).
	 * @param string $name Entry name.
	 * @return string|null Its content, or null when the backup has no such entry.
	 * @throws Archive_Exception If the backup cannot be read.
	 */
	public static function read_entry( string $file, string $name ): ?string {
		$reader = Tar_Reader::open( $file );
		try {
			while ( null !== ( $entry = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( ltrim( str_replace( '\\', '/', (string) $entry->name ), '/' ) !== $name ) {
					continue;
				}
				if ( ! $entry->is_file() || $entry->size > 268435456 ) {
					return null;
				}
				$data = '';
				$left = (int) $entry->size;
				while ( $left > 0 ) {
					$chunk = $reader->read( (int) min( 1048576, $left ) );
					if ( '' === $chunk ) {
						break;
					}
					$data .= $chunk;
					$left -= strlen( $chunk );
				}
				return $data;
			}
		} finally {
			$reader->close();
		}
		return null;
	}

	/**
	 * The checksum list of a full backup as path => sha256, and its fingerprint.
	 *
	 * @param string $file Backup file.
	 * @return array{0:array<string,string>,1:string}
	 * @throws Job_Exception If the backup has no checksum list.
	 */
	public static function checksums( string $file ): array {
		$list = self::read_entry( $file, Manifest::CHECKSUMS );
		if ( null === $list ) {
			throw new Job_Exception( sprintf( /* translators: %s: a backup name */ __( 'The backup %s has no checksum list.', 'siteferry' ), basename( $file ) ), Job_Exception::STEP );
		}
		return array( self::parse_sums( $list ), hash( 'sha256', $list ) );
	}

	/**
	 * Parses "hash  path" lines into path => hash (lines without a hash are left out).
	 *
	 * @param string $list Checksum list.
	 * @return array<string,string>
	 */
	public static function parse_sums( string $list ): array {
		$out = array();
		foreach ( explode( "\n", $list ) as $line ) {
			if ( preg_match( '/^([0-9a-f]{64}) [ *](.+)$/', rtrim( $line, "\r" ), $match ) ) {
				$out[ $match[2] ] = $match[1];
			}
		}
		return $out;
	}

	/**
	 * Compares the files now with the full backup's.
	 *
	 * A file is unchanged when the full backup has it with the same size and modification time
	 * and a checksum; everything else is stored again. Files of the full backup that are not here
	 * now are "deleted".
	 *
	 * @param array<string,array{0:int,1:int}> $base Full backup: path => [size, mtime].
	 * @param array<string,string>             $sums Full backup's checksums: path => sha256.
	 * @param array<string,array{0:int,1:int}> $now  Files now: path => [size, mtime].
	 * @return array{unchanged:array<string,string>,deleted:string[],changed:int,changed_bytes:int}
	 */
	public static function compare( array $base, array $sums, array $now ): array {
		$unchanged = array();
		$changed   = 0;
		$bytes     = 0;
		foreach ( $now as $path => $item ) {
			if ( isset( $base[ $path ], $sums[ $path ] ) && (int) $base[ $path ][0] === (int) $item[0] && (int) $base[ $path ][1] === (int) $item[1] ) {
				$unchanged[ $path ] = $sums[ $path ];
				continue;
			}
			++$changed;
			$bytes += (int) $item[0];
		}
		$deleted = array();
		foreach ( array_keys( $base ) as $path ) {
			if ( ! isset( $now[ $path ] ) ) {
				$deleted[] = (string) $path;
			}
		}
		sort( $deleted );
		return array(
			'unchanged'     => $unchanged,
			'deleted'       => $deleted,
			'changed'       => $changed,
			'changed_bytes' => $bytes,
		);
	}

	/**
	 * The files of a full backup from its index: path => [size, mtime] (wp-content files only).
	 *
	 * @param array $entries Contents::index() entries: [path, type, size, mtime].
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function files_of( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			if ( 'f' === $entry[1] && 0 === strpos( (string) $entry[0], 'wp-content/' ) ) {
				$out[ (string) $entry[0] ] = array( (int) $entry[2], (int) $entry[3] );
			}
		}
		return $out;
	}

	/**
	 * The scheduled runs beyond the newest $keep, oldest first, never including a full backup that
	 * a kept changes-only run is built on (it is kept beyond the number instead).
	 *
	 * @param array $runs Runs: name, time, base ('' for a full backup).
	 * @param int   $keep How many to keep (0: all).
	 * @return array{0:array,1:string[]} Expired runs, and the full backups kept for their changes.
	 */
	public static function expired( array $runs, int $keep ): array {
		if ( $keep <= 0 ) {
			return array( array(), array() );
		}
		usort(
			$runs,
			static function ( array $a, array $b ): int {
				return (int) $b['time'] - (int) $a['time'];
			}
		);
		$kept   = array_slice( $runs, 0, $keep );
		$needed = array();
		foreach ( $kept as $run ) {
			if ( ! empty( $run['base'] ) ) {
				$needed[ (string) $run['base'] ] = true;
			}
		}
		$expired = array();
		$held    = array();
		foreach ( array_slice( $runs, $keep ) as $run ) {
			if ( isset( $needed[ (string) $run['name'] ] ) ) {
				$held[] = (string) $run['name'];
				continue;
			}
			$expired[] = $run;
		}
		return array( array_reverse( $expired ), $held );
	}
}
