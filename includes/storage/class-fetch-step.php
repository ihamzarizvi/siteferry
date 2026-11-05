<?php
/**
 * Job step: download a backup from remote storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Backups;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads a stored backup in ranges into the job folder (download.part), then checks that it is
 * a SiteFerry backup and moves it into the backups folder. The recorded offset is the truth:
 * anything a dead request wrote past it is cut off before the next range is appended.
 */
final class Fetch_Step implements Step {

	const STATE = 'fetch';

	/** Bytes per range request. */
	const CHUNK = 8388608;

	const PART = 'download.part';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the download is incomplete or not a backup.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$source = (string) $job->option( 'source' );
		$name   = (string) $job->option( 'name' );
		$size   = (int) $job->option( 'size' );
		$driver = Destinations::driver( $source );
		$state  = $job->state( self::STATE ) + array( 'offset' => 0 );
		$part   = $job->path( self::PART );

		if ( 0 === $state['offset'] ) {
			$job->log( 'info', sprintf( 'Downloading %s (%s) from %s.', $name, size_format( $size, 1 ), $driver->describe() ) );
		}

		$handle = Io::open_or_fail( $part, 'c+b' );
		try {
			if ( ! ftruncate( $handle, $state['offset'] ) || 0 !== fseek( $handle, $state['offset'] ) ) {
				throw new Archive_Exception( __( 'Cannot write the download.', 'siteferry' ), Archive_Exception::IO );
			}
			// A backup stored in parts is joined back into one file, part after part.
			$pieces = (array) $job->option( 'pieces', array( array( $name, $size ) ) );
			while ( $state['offset'] < $size ) {
				$start = 0;
				foreach ( $pieces as $piece ) {
					if ( $state['offset'] < $start + (int) $piece[1] ) {
						break;
					}
					$start += (int) $piece[1];
				}
				$want  = (int) min( self::CHUNK, $size - $state['offset'], $start + (int) $piece[1] - $state['offset'] );
				$bytes = $driver->download_range( (string) $piece[0], $state['offset'] - $start, $want );
				if ( strlen( $bytes ) !== $want ) {
					throw new Job_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'The storage sent %1$d bytes instead of %2$d; the file may have changed there.', 'siteferry' ), strlen( $bytes ), $want ), Job_Exception::STEP );
				}
				Io::write_all( $handle, $bytes );
				fflush( $handle );
				$state['offset'] += $want;
				$job->set_state( self::STATE, $state );
				$job->set_progress( sprintf( /* translators: 1: where it is stored, such as Dropbox, 2: a size, such as 12 MB, 3: a size, such as 12 MB */ __( 'Downloading from %1$s: %2$s of %3$s', 'siteferry' ), Destinations::short_label( $source ), size_format( $state['offset'], 1 ), size_format( $size, 1 ) ), $state['offset'], $size );
				$job->save();

				if ( $state['offset'] < $size && $budget->exhausted() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		if ( false === $job->option( 'adopt', true ) ) {
			// A check reads the download where it is and deletes it afterwards.
			$job->log( 'info', sprintf( 'Downloaded %s from %s for the check.', $name, $driver->describe() ) );
			return true;
		}
		try {
			$final = Backups::adopt( $part, $name, (string) $job->option( 'output_dir' ) );
		} catch ( Archive_Exception $e ) {
			throw new Job_Exception( sprintf( /* translators: %s: the reason */ __( 'The downloaded file is not a backup SiteFerry can import: %s', 'siteferry' ), $e->getMessage() ), Job_Exception::STEP );
		}
		$job->set_result(
			array(
				'fetched' => array(
					'name' => $final,
					'size' => $size,
					'from' => $driver->describe(),
				),
			)
		);
		$job->set_progress( sprintf( /* translators: %s: final */ __( 'Downloaded %s', 'siteferry' ), $final ), 1, 1 );
		$job->log( 'info', sprintf( 'Downloaded %s from %s; stored as %s.', $name, $driver->describe(), $final ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$job->delete_file( self::PART );
	}
}
