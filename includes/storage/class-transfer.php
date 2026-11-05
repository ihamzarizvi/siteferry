<?php
/**
 * Starting transfers between this site and remote storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Backups;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates "send" jobs (a stored backup to a destination) and "fetch" jobs (a backup from a
 * destination into the backups folder).
 */
final class Transfer {

	const SEND_STEPS  = array( 'send.upload' );
	const FETCH_STEPS = array( 'fetch.download' );

	/** Free space kept on top of a download. */
	const SPARE_BYTES = 67108864;

	/**
	 * Starts sending a backup.
	 *
	 * @param string $destination Destination id.
	 * @param string $file        Backup file.
	 * @return array{0:Job,1:string} The job and its token.
	 * @throws Job_Exception If the destination is not set up.
	 */
	public static function send( string $destination, string $file ): array {
		Destinations::require_ready( $destination );
		return Job::create(
			Storage::jobs_dir(),
			'send',
			self::SEND_STEPS,
			array(
				'send_to' => $destination,
				'file'    => $file,
			)
		);
	}

	/**
	 * Starts downloading a backup.
	 *
	 * @param string $destination Destination id.
	 * @param string $name        Backup file name on the storage.
	 * @return array{0:Job,1:string} The job and its token.
	 * @throws Job_Exception If the backup is not there or there is not enough disk space.
	 */
	public static function fetch( string $destination, string $name ): array {
		if ( ! preg_match( Backups::SAFE_NAME, $name ) ) {
			throw new Job_Exception( 'Unknown backup.', Job_Exception::NOT_FOUND );
		}
		Destinations::require_ready( $destination );
		$found = Parts::find( Destinations::driver( $destination ), $name ); // Whole, or all its parts.
		if ( null === $found ) {
			throw new Job_Exception( 'That backup is not on the storage (any more).', Job_Exception::NOT_FOUND );
		}
		if ( ! $found['complete'] ) {
			throw new Job_Exception( sprintf( 'Parts of that backup are missing on the storage (%d of them are there).', $found['parts'] ), Job_Exception::STEP );
		}

		$dir  = Storage::backups_dir();
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < $found['size'] + self::SPARE_BYTES ) {
			throw new Job_Exception( sprintf( 'Not enough disk space: the backup needs %s, %s is free.', size_format( $found['size'], 1 ), size_format( $free, 1 ) ), Job_Exception::STEP );
		}
		return Job::create(
			Storage::jobs_dir(),
			'fetch',
			self::FETCH_STEPS,
			array(
				'source'     => $destination,
				'name'       => $name,
				'size'       => $found['size'],
				'pieces'     => $found['pieces'],
				'output_dir' => $dir,
			)
		);
	}
}
