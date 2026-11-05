<?php
/**
 * Checking a copy of a backup: starting the check and keeping its outcome.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Check;

use SiteFerry\Backups;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;
use SiteFerry\Storage\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * A check reads a copy of a backup from start to end, without unpacking anything: every file's
 * SHA-256 is compared with the backup's own checksum list, the manifest and the database dump are
 * looked at, and anything an import would refuse is reported. A copy on a storage is downloaded
 * into the job's folder first and deleted again afterwards; the backups list does not change.
 *
 * The outcome is kept per backup and place ("local" or a storage id) in a small option.
 */
final class Check {

	const LOCAL = 'local';

	const LOCAL_STEPS  = array( 'check.verify' );
	const REMOTE_STEPS = array( 'check.download', 'check.verify' );

	/** Outcomes of checks: name => place => { time, ok, size, summary }. Not autoloaded. */
	const OPTION = 'siteferry_checks';

	/** Outcomes kept (the oldest go first). */
	const KEEP = 300;

	/**
	 * Starts checking one copy of a backup.
	 *
	 * @param string $name     Backup file name.
	 * @param string $place    "local" for the copy on this server, or a storage id.
	 * @param string $password Password of an encrypted backup.
	 * @return array{0:Job,1:string} The job and its token.
	 * @throws Job_Exception If there is no such copy, or no room to download it.
	 * @throws \SiteFerry\Archive\Archive_Exception If the backup is encrypted and the password is missing or wrong.
	 */
	public static function start(
		string $name,
		string $place,
		#[\SensitiveParameter]
		string $password = ''
	): array {
		$encrypted = Backups::encrypted_name( $name );
		if ( $encrypted && '' === $password ) {
			throw new \SiteFerry\Archive\Archive_Exception( 'This backup is protected with a password; enter it to check the backup.', \SiteFerry\Archive\Archive_Exception::LOCKED );
		}
		if ( ! preg_match( Backups::SAFE_NAME, $name ) ) {
			throw new Job_Exception( 'Unknown backup.', Job_Exception::NOT_FOUND );
		}
		if ( self::LOCAL === $place || '' === $place ) {
			$file    = Backups::path( Storage::backups_dir(), $name );
			$key     = $encrypted ? \SiteFerry\Archive\Crypto::unlock( $file, $password ) : null;
			$created = Job::create(
				Storage::jobs_dir(),
				'check',
				self::LOCAL_STEPS,
				array(
					'name'  => $name,
					'place' => self::LOCAL,
					'file'  => $file,
					'size'  => (int) filesize( $file ),
				)
			);
			if ( null !== $key ) {
				\SiteFerry\Security\Archive_Keys::store( $created[0], $key );
				$created[0]->save();
			}
			return $created;
		}

		Destinations::require_ready( $place );
		$found = null;
		foreach ( Destinations::driver( $place )->list() as $item ) {
			if ( $item['name'] === $name ) {
				$found = $item;
				break;
			}
		}
		if ( null === $found ) {
			throw new Job_Exception( 'That backup is not on the storage (any more).', Job_Exception::NOT_FOUND );
		}
		$dir  = Storage::jobs_dir();
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < $found['size'] + Transfer::SPARE_BYTES ) {
			throw new Job_Exception( sprintf( 'Not enough disk space to download the copy for the check: it needs %s, %s is free.', size_format( $found['size'], 1 ), size_format( $free, 1 ) ), Job_Exception::STEP );
		}
		$created = Job::create(
			$dir,
			'check',
			self::REMOTE_STEPS,
			array(
				'name'   => $name,
				'place'  => $place,
				'source' => $place,
				'size'   => (int) $found['size'],
				'adopt'  => false,
			)
		);
		if ( $encrypted ) {
			// The key needs the copy's header: the password is kept (encrypted) until it is downloaded.
			\SiteFerry\Security\Archive_Keys::store_password( $created[0], $password );
			$created[0]->save();
		}
		return $created;
	}

	/**
	 * Where a place is, in words ("this server", "Dropbox").
	 *
	 * @param string $place Place.
	 */
	public static function where( string $place ): string {
		return self::LOCAL === $place ? 'this server' : Destinations::short_label( $place );
	}

	/**
	 * Every recorded outcome.
	 *
	 * @return array<string,array<string,array{time:int,ok:bool,size:int,summary:string}>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Records the outcome of a check.
	 *
	 * @param string $name    Backup name.
	 * @param string $place   Place.
	 * @param bool   $ok      Whether the copy is intact.
	 * @param int    $size    Size of the copy that was checked.
	 * @param string $summary What was found, in words.
	 */
	public static function record( string $name, string $place, bool $ok, int $size, string $summary ): void {
		$all    = self::all();
		$places = isset( $all[ $name ] ) && is_array( $all[ $name ] ) ? $all[ $name ] : array();
		unset( $all[ $name ] );
		$places[ $place ] = array(
			'time'    => time(),
			'ok'      => $ok,
			'size'    => $size,
			'summary' => substr( $summary, 0, 500 ),
		);
		// The backup checked last goes first; the oldest fall off the end.
		$all = array( $name => $places ) + $all;
		update_option( self::OPTION, array_slice( $all, 0, self::KEEP, true ), false );
	}

	/**
	 * Forgets the outcome for one copy, or for every copy of a backup.
	 *
	 * @param string      $name  Backup name.
	 * @param string|null $place Place, or null for all of them.
	 */
	public static function forget( string $name, ?string $place = null ): void {
		$all = self::all();
		if ( ! isset( $all[ $name ] ) ) {
			return;
		}
		if ( null === $place ) {
			unset( $all[ $name ] );
		} else {
			unset( $all[ $name ][ $place ] );
			if ( array() === $all[ $name ] ) {
				unset( $all[ $name ] );
			}
		}
		update_option( self::OPTION, $all, false );
	}
}
