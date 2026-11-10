<?php
/**
 * Job step: download a backup from remote storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

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

	/** The full backup of a changes-only backup, while it downloads. */
	const BASE_PART = 'base-download.part';

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

		if ( empty( $state['adopted'] ) ) {
			if ( 0 === $state['offset'] ) {
				$job->log( 'info', sprintf( 'Downloading %s (%s) from %s.', $name, size_format( $size, 1 ), $driver->describe() ) );
			}
			if ( ! isset( $state['started_at'] ) ) {
				$state['started_at'] = microtime( true ); // For the forecast's download speed.
				$job->set_state( self::STATE, $state );
			}
			$pieces = (array) $job->option( 'pieces', array( array( $name, $size ) ) );
			$offset = (int) $state['offset'];
			$done   = $this->download(
				$job,
				$driver,
				$source,
				$pieces,
				$size,
				$job->path( self::PART ),
				$offset,
				$budget,
				static function ( int $offset ) use ( $job, &$state ) {
					$state['offset'] = $offset;
					$job->set_state( self::STATE, $state );
				}
			);
			if ( ! $done ) {
				return false;
			}
			if ( isset( $state['started_at'] ) ) {
				\SiteFerry\Forecast::record_transfer( $source, 'down', $size, microtime( true ) - (float) $state['started_at'] );
			}

			if ( false === $job->option( 'adopt', true ) ) {
				// A check reads the download where it is and deletes it afterwards.
				$job->log( 'info', sprintf( 'Downloaded %s from %s for the check.', $name, $driver->describe() ) );
				return true;
			}
			try {
				$final = Backups::adopt( $job->path( self::PART ), $name, (string) $job->option( 'output_dir' ) );
			} catch ( Archive_Exception $e ) {
				throw new Job_Exception( sprintf( /* translators: %s: the reason */ __( 'The downloaded file is not a backup SiteFerry can import: %s', 'siteferry' ), $e->getMessage() ), Job_Exception::STEP );
			}
			$state['adopted'] = $final;
			$state['base']    = self::base_to_fetch( (string) $job->option( 'output_dir' ) . '/' . $final, $driver );
			$job->set_state( self::STATE, $state );
			$job->save();
			$job->log( 'info', sprintf( 'Downloaded %s from %s; stored as %s.', $name, $driver->describe(), $final ) );
			if ( null !== $state['base'] && $budget->exhausted() ) {
				return false;
			}
		}
		$final = (string) $state['adopted'];

		// A changes-only backup needs its full backup on this server too.
		if ( null !== $state['base'] && empty( $state['base']['done'] ) ) {
			$base   = $state['base'];
			$offset = (int) $base['offset'];
			if ( 0 === $offset ) {
				$job->log( 'info', sprintf( 'Downloading the full backup %s (%s) it is built on.', $base['name'], size_format( (int) $base['size'], 1 ) ) );
			}
			$done = $this->download(
				$job,
				$driver,
				$source,
				(array) $base['pieces'],
				(int) $base['size'],
				$job->path( self::BASE_PART ),
				$offset,
				$budget,
				static function ( int $offset ) use ( $job, &$state ) {
					$state['base']['offset'] = $offset;
					$job->set_state( self::STATE, $state );
				}
			);
			if ( ! $done ) {
				return false;
			}
			try {
				$stored = Backups::adopt( $job->path( self::BASE_PART ), (string) $base['name'], (string) $job->option( 'output_dir' ) );
			} catch ( Archive_Exception $e ) {
				throw new Job_Exception( sprintf( /* translators: %s: the reason */ __( 'The downloaded file is not a backup SiteFerry can import: %s', 'siteferry' ), $e->getMessage() ), Job_Exception::STEP );
			}
			if ( $stored !== $base['name'] ) {
				throw new Job_Exception( sprintf( /* translators: %s: a backup name */ __( 'The full backup %s could not be stored under its own name; a file of that name is in the way.', 'siteferry' ), (string) $base['name'] ), Job_Exception::STEP );
			}
			$state['base']['done'] = true;
			$job->set_state( self::STATE, $state );
			$job->log( 'info', sprintf( 'Downloaded the full backup %s.', $stored ) );
		}

		$job->set_result(
			array(
				'fetched' => array(
					'name' => $final,
					'size' => $size,
					'from' => $driver->describe(),
					'base' => null === $state['base'] ? '' : (string) $state['base']['name'],
				),
			)
		);
		$job->set_progress( sprintf( /* translators: %s: final */ __( 'Downloaded %s', 'siteferry' ), $final ), 1, 1 );
		return true;
	}

	/**
	 * Downloads a stored backup (whole, or its parts joined) into a file, range by range.
	 *
	 * @param Job          $job    The job.
	 * @param Driver       $driver Storage.
	 * @param string       $source Destination id.
	 * @param array        $pieces Parts: [name, size].
	 * @param int          $size   Whole size.
	 * @param string       $part   File to write.
	 * @param int          $offset Bytes already written (the recorded offset is the truth).
	 * @param Slice_Budget $budget Work limit.
	 * @param callable     $save   function( int $offset ): void, after every range.
	 * @return bool True when the whole file is there.
	 * @throws Job_Exception If the storage sends less than asked.
	 * @throws Archive_Exception If the file cannot be written.
	 */
	private function download( Job $job, Driver $driver, string $source, array $pieces, int $size, string $part, int $offset, Slice_Budget $budget, callable $save ): bool {
		$handle = Io::open_or_fail( $part, 'c+b' );
		try {
			if ( ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) ) {
				throw new Archive_Exception( __( 'Cannot write the download.', 'siteferry' ), Archive_Exception::IO );
			}
			// A backup stored in parts is joined back into one file, part after part.
			while ( $offset < $size ) {
				$start = 0;
				foreach ( $pieces as $piece ) {
					if ( $offset < $start + (int) $piece[1] ) {
						break;
					}
					$start += (int) $piece[1];
				}
				$want  = (int) min( self::CHUNK, $size - $offset, $start + (int) $piece[1] - $offset );
				$bytes = $driver->download_range( (string) $piece[0], $offset - $start, $want );
				if ( strlen( $bytes ) !== $want ) {
					throw new Job_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'The storage sent %1$d bytes instead of %2$d; the file may have changed there.', 'siteferry' ), strlen( $bytes ), $want ), Job_Exception::STEP );
				}
				Io::write_all( $handle, $bytes );
				fflush( $handle );
				$offset += $want;
				$save( $offset );
				$job->set_progress( sprintf( /* translators: 1: where it is stored, such as Dropbox, 2: a size, such as 12 MB, 3: a size, such as 12 MB */ __( 'Downloading from %1$s: %2$s of %3$s', 'siteferry' ), Destinations::short_label( $source ), size_format( $offset, 1 ), size_format( $size, 1 ) ), $offset, $size );
				$job->save();

				if ( $offset < $size && $budget->exhausted() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return true;
	}

	/**
	 * The full backup to download after a changes-only backup: name, size and parts on the storage,
	 * or null when it is a full backup or its full backup is already on this server.
	 *
	 * @param string $file   The downloaded backup.
	 * @param Driver $driver Storage.
	 * @throws Job_Exception If the full backup is neither here nor (complete) on the storage.
	 */
	private static function base_to_fetch( string $file, Driver $driver ): ?array {
		$name = basename( $file );
		if ( Backups::encrypted_name( $name ) || \SiteFerry\Wpress\Wpress::is_name( $name ) ) {
			return null;
		}
		try {
			$base = \SiteFerry\Incremental::base_of( Backups::manifest( $file ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( null === $base || is_file( dirname( $file ) . '/' . $base['name'] ) ) {
			return null;
		}
		$found = Parts::find( $driver, $base['name'] );
		if ( null === $found || ! $found['complete'] ) {
			throw new Job_Exception( sprintf( /* translators: %s: a backup name */ __( 'This changes-only backup is built on the full backup %s, which is neither on this server nor (complete) on the storage. It cannot be restored without it.', 'siteferry' ), $base['name'] ), Job_Exception::STEP );
		}
		return array(
			'name'   => $base['name'],
			'size'   => (int) $found['size'],
			'pieces' => $found['pieces'],
			'offset' => 0,
			'done'   => false,
		);
	}
	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$job->delete_file( self::PART );
		$job->delete_file( self::BASE_PART );
	}
}
