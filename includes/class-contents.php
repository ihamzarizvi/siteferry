<?php
/**
 * What is inside a backup: an index of its files, folder listings and single-file downloads.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Tar_Entry;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a backup's headers once and keeps the list of its entries (path, type, size, time) in
 * SiteFerry's storage folder, named after the backup and its size and time, so a changed file
 * gets a new index. Reading a .tar only reads headers (the data is skipped); a .tar.gz has to be
 * decompressed, so building its index is resumable: each call works for a limited time and the
 * next one continues.
 *
 * Paths are as an import sees them: siteferry.json, database.sql, siteferry.sha256 and
 * wp-content/... For a .wpress backup, its package.json and database.sql stay at the top and
 * everything else is under wp-content/.
 */
final class Contents {

	/** Top-level files of a .wpress backup (everything else lives in wp-content). */
	const WPRESS_TOP = array( 'package.json', 'database.sql', 'multisite.json', 'blogs.json' );

	/** Seconds one call may spend building an index. */
	const SECONDS = 20.0;

	/**
	 * The index of a backup, built (or continued) for at most $seconds.
	 *
	 * @param string      $file    Backup file.
	 * @param float       $seconds Time to spend now.
	 * @param string|null $key     Key of an encrypted backup.
	 * @return array{complete:bool,entries:array,read:int,size:int} Entries: [path, type (d, f or l), size, mtime].
	 * @throws Archive_Exception If the backup cannot be read, or it is encrypted and no key is given.
	 */
	public static function index(
		string $file,
		float $seconds = self::SECONDS,
		#[\SensitiveParameter]
		?string $key = null
	): array {
		$source = self::source( $file, $key );
		$cache  = self::cache_file( $file );
		$saved  = self::load( $cache, $source !== $file );
		$size  = (int) filesize( $file );
		if ( is_array( $saved ) && ! empty( $saved['complete'] ) ) {
			return array(
				'complete' => true,
				'entries'  => (array) $saved['entries'],
				'read'     => $size,
				'size'     => $size,
			);
		}
		$entries = is_array( $saved ) && isset( $saved['entries'] ) ? (array) $saved['entries'] : array();
		$wpress  = $source === $file && ( Wpress\Wpress::is_name( $file ) || Wpress\Reader::is_wpress( $file ) );
		$reader  = self::open( $source, $wpress, is_array( $saved ) && isset( $saved['state'] ) ? (array) $saved['state'] : null );
		$until   = microtime( true ) + $seconds;
		$done    = false;
		try {
			for ( ;; ) {
				$entry = $reader->next();
				if ( null === $entry ) {
					$done = true;
					break;
				}
				$entries[] = array( self::name( $entry, $wpress ), $entry->is_dir() ? 'd' : ( $entry->is_file() ? 'f' : 'l' ), (int) $entry->size, (int) $entry->mtime );
				if ( microtime( true ) >= $until ) {
					break;
				}
			}
			$state = $done ? null : $reader->state();
			$read  = $done ? $size : min( $size, self::offset( $reader, $state ) );
		} finally {
			$reader->close();
		}
		self::save( $cache, $file, $done, $entries, $state, $source !== $file );
		return array(
			'complete' => $done,
			'entries'  => $entries,
			'read'     => $read,
			'size'     => $size,
		);
	}

	/**
	 * What is directly inside a folder of the backup, from its index: folders first (with their
	 * number of files and size), then files.
	 *
	 * @param array  $entries Index entries.
	 * @param string $dir     Folder ('' for the top).
	 * @return array<int,array{name:string,path:string,type:string,size:int,files:int,mtime:int}>
	 */
	public static function listing( array $entries, string $dir ): array {
		$dir    = trim( $dir, '/' );
		$prefix = '' === $dir ? '' : $dir . '/';
		$items  = array();
		foreach ( $entries as $entry ) {
			$path = rtrim( (string) $entry[0], '/' );
			if ( '' === $path || ( '' !== $prefix && 0 !== strpos( $path, $prefix ) ) ) {
				continue;
			}
			$rest  = substr( $path, strlen( $prefix ) );
			$slash = strpos( $rest, '/' );
			$name  = false === $slash ? $rest : substr( $rest, 0, $slash );
			if ( '' === $name ) {
				continue;
			}
			if ( ! isset( $items[ $name ] ) ) {
				$items[ $name ] = array(
					'name'  => $name,
					'path'  => $prefix . $name,
					'type'  => false === $slash && 'd' !== $entry[1] ? ( 'l' === $entry[1] ? 'link' : 'file' ) : 'dir',
					'size'  => 0,
					'files' => 0,
					'mtime' => 0,
				);
			}
			if ( false === $slash && 'd' !== $entry[1] ) {
				$items[ $name ]['size']  = (int) $entry[2];
				$items[ $name ]['mtime'] = (int) $entry[3];
			} elseif ( 'f' === $entry[1] ) {
				$items[ $name ]['type']   = 'dir';
				$items[ $name ]['size']  += (int) $entry[2];
				$items[ $name ]['files'] += 1;
			}
		}
		$items = array_values( $items );
		usort(
			$items,
			static function ( array $a, array $b ): int {
				if ( ( 'dir' === $a['type'] ) !== ( 'dir' === $b['type'] ) ) {
					return 'dir' === $a['type'] ? -1 : 1;
				}
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);
		return $items;
	}

	/**
	 * Sends one file from inside a backup to the browser and ends the request.
	 *
	 * @param string $file  Backup file.
	 * @param string $inner Path inside the backup, as in the index.
	 * @throws Job_Exception If there is no such file, or the backup is password-protected.
	 */
	public static function stream(
		string $file,
		string $inner,
		#[\SensitiveParameter]
		?string $key = null
	): void {
		$source = self::source( $file, $key );
		$wpress = $source === $file && ( Wpress\Wpress::is_name( $file ) || Wpress\Reader::is_wpress( $file ) );
		if ( $wpress && ! empty( Wpress\Wpress::describe( $file )['encrypted'] ) ) {
			throw new Job_Exception( 'This .wpress backup is protected with a password; its files cannot be read.', Job_Exception::STEP );
		}
		$reader = self::open( $source, $wpress, null );
		try {
			while ( null !== ( $entry = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( self::name( $entry, $wpress ) !== $inner ) {
					continue;
				}
				if ( ! $entry->is_file() ) {
					break;
				}
				while ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				Io::quiet(
					static function () {
						return set_time_limit( 0 );
					}
				);
				$name = (string) preg_replace( '/[^A-Za-z0-9._-]+/', '_', basename( $inner ) );
				status_header( 200 );
				header( 'Content-Type: application/octet-stream' );
				header( 'Content-Disposition: attachment; filename="' . ( '' === $name ? 'file' : $name ) . '"' );
				header( 'Content-Length: ' . (int) $entry->size );
				header( 'Cache-Control: no-store' );
				header( 'X-Content-Type-Options: nosniff' );
				$left = (int) $entry->size;
				while ( $left > 0 && ! connection_aborted() ) {
					$chunk = $reader->read( (int) min( 1048576, $left ) );
					if ( '' === $chunk ) {
						break;
					}
					echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file download.
					flush();
					$left -= strlen( $chunk );
				}
				$reader->close();
				exit;
			}
		} finally {
			$reader->close();
		}
		throw new Job_Exception( 'That file is not in the backup.', Job_Exception::NOT_FOUND );
	}

	/**
	 * Removes the index of a backup (when the backup is deleted).
	 *
	 * @param string $name Backup file name.
	 */
	public static function forget( string $name ): void {
		if ( null === Storage::existing_backups_dir() ) {
			return;
		}
		foreach ( (array) glob( Storage::root() . '/index/idx-' . substr( sha1( basename( $name ) ), 0, 16 ) . '-*.json' ) as $old ) {
			Io::quiet(
				static function () use ( $old ) {
					return unlink( (string) $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
		}
	}

	/**
	 * A reader for a backup.
	 *
	 * @param string     $file   Backup file.
	 * @param bool       $wpress Whether it is a .wpress backup.
	 * @param array|null $state  Saved reader state, to continue.
	 * @return Tar_Reader|Wpress\Reader
	 */
	private static function open( string $file, bool $wpress, ?array $state ) {
		return $wpress ? Wpress\Reader::open( $file, $state ) : Tar_Reader::open( $file, $state );
	}

	/**
	 * How far into the backup file the reader is. A .tar.gz reader counts uncompressed bytes, so
	 * its offset is the start of the gzip member it is in.
	 *
	 * @param Tar_Reader|Wpress\Reader $reader Reader.
	 * @param array                    $state  Its state.
	 */
	private static function offset( $reader, array $state ): int {
		if ( ! empty( $state['compressed'] ) && isset( $state['input']['member_offset'] ) ) {
			return (int) $state['input']['member_offset'];
		}
		return (int) $reader->position();
	}

	/**
	 * An entry's path as an import sees it.
	 *
	 * @param Tar_Entry $entry  Entry.
	 * @param bool      $wpress Whether it comes from a .wpress backup.
	 */
	private static function name( Tar_Entry $entry, bool $wpress ): string {
		$name = rtrim( str_replace( '\\', '/', (string) $entry->name ), '/' );
		if ( $wpress && ! in_array( $name, self::WPRESS_TOP, true ) ) {
			return 'wp-content/' . ltrim( $name, '/' );
		}
		return ltrim( $name, '/' );
	}

	/**
	 * The path to read a backup through: its decrypting stream when it is encrypted.
	 *
	 * @param string      $file Backup file.
	 * @param string|null $key  Key of an encrypted backup.
	 * @throws Archive_Exception If it is encrypted and no key is given.
	 */
	private static function source(
		string $file,
		#[\SensitiveParameter]
		?string $key
	): string {
		if ( ! Archive\Crypto::is_encrypted( $file ) ) {
			return $file;
		}
		if ( null === $key ) {
			throw new Archive_Exception( 'This backup is protected with a password.', Archive_Exception::LOCKED );
		}
		return Archive\Crypto::url( $file, $key );
	}

	/**
	 * A saved index, or null.
	 *
	 * @param string $cache  Index file.
	 * @param bool   $sealed Whether it is kept encrypted.
	 */
	private static function load( string $cache, bool $sealed ): ?array {
		if ( ! is_file( $cache ) ) {
			return null;
		}
		$raw = (string) file_get_contents( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $sealed ) {
			try {
				$raw = Security\Secrets::site()->decrypt( $raw );
			} catch ( \Throwable $e ) {
				return null; // Made with other salts: read the backup again.
			}
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Index file of a backup: named after its name, size and time.
	 *
	 * @param string $file Backup file.
	 */
	private static function cache_file( string $file ): string {
		$dir = Storage::ensure( Storage::root() . '/index' );
		return $dir . '/idx-' . substr( sha1( basename( $file ) ), 0, 16 ) . '-' . substr( sha1( filesize( $file ) . '|' . filemtime( $file ) ), 0, 8 ) . '.json';
	}

	/**
	 * Writes the index (and removes older ones of the same backup).
	 *
	 * @param string     $cache    Index file.
	 * @param string     $file     Backup file.
	 * @param bool       $complete Whether the whole backup was read.
	 * @param array      $entries  Entries so far.
	 * @param array|null $state    Reader state, to continue.
	 */
	private static function save( string $cache, string $file, bool $complete, array $entries, ?array $state, bool $sealed = false ): void {
		foreach ( (array) glob( dirname( $cache ) . '/idx-' . substr( sha1( basename( $file ) ), 0, 16 ) . '-*.json' ) as $old ) {
			if ( $old !== $cache ) {
				Io::quiet(
					static function () use ( $old ) {
						return unlink( (string) $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				);
			}
		}
		$data = array(
			'complete' => $complete,
			'entries'  => $entries,
		);
		if ( ! $complete ) {
			$data['state'] = $state;
		}
		$json = (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		// The file list of an encrypted backup is kept encrypted with the site's key.
		file_put_contents( $cache, $sealed ? Security\Secrets::site()->encrypt( $json ) : $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
