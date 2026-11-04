<?php
/**
 * Job step: send a backup to remote storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads the backup part by part (at least one part per slice) to the destination named by the
 * job option "send_to"; does nothing when it is empty (an export that stays on the server).
 *
 * Used as "export.send" after an export, and as "send.upload" for a stored backup (job option
 * "file"). Progress is saved after every part, so an interrupted slice repeats at most one part.
 * If the job fails or is cancelled, the unfinished upload is aborted on the storage; the local
 * backup is never touched unless the upload completed and "delete_after_send" is set.
 */
final class Send_Step implements Step {

	const STATE = 'send';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the backup is missing or changes.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$destination = (string) $job->option( 'send_to', '' );
		if ( '' === $destination ) {
			return true;
		}
		$file   = self::source( $job );
		$name   = basename( $file );
		$driver = Destinations::driver( $destination );
		$state  = $job->state( self::STATE );
		$size   = is_file( $file ) ? (int) filesize( $file ) : -1;

		if ( array() === $state ) {
			if ( $size <= 0 ) {
				throw new Job_Exception( 'The backup to send is missing or empty.', Job_Exception::STEP );
			}
			$state = array(
				'size'     => $size,
				'offset'   => 0,
				'index'    => 0,
				'upload'   => $driver->begin( $name, $size ),
				'finished' => false,
			);
			$job->set_state( self::STATE, $state );
			$job->save();
			$job->log( 'info', sprintf( 'Sending %s (%s) to %s in parts of %s.', $name, size_format( $size, 1 ), $driver->describe(), size_format( $state['upload']['part_size'] ) ) );
		}
		if ( $size !== $state['size'] ) {
			throw new Job_Exception( 'The backup changed or disappeared while it was being sent.', Job_Exception::STEP );
		}

		$part   = (int) $state['upload']['part_size'];
		$handle = Io::open_or_fail( $file, 'rb' );
		try {
			while ( $state['offset'] < $state['size'] ) {
				$bytes = self::read( $handle, $state['offset'], (int) min( $part, $state['size'] - $state['offset'] ) );

				$state['upload'] = $driver->put_part( $state['upload'], $state['index'], $bytes );
				$state['offset'] += strlen( $bytes );
				++$state['index'];
				$job->set_state( self::STATE, $state );
				$job->set_progress( sprintf( 'Sending to %s: %s of %s', Destinations::short_label( $destination ), size_format( $state['offset'], 1 ), size_format( $state['size'], 1 ) ), $state['offset'], $state['size'] );
				$job->save();

				if ( $state['offset'] < $state['size'] && $budget->exhausted() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$driver->finish( $state['upload'] );
		$state['finished'] = true;
		$job->set_state( self::STATE, $state );

		$result         = $job->result();
		$result['sent'] = array(
			'destination' => $destination,
			'name'        => $name,
			'size'        => $state['size'],
			'where'       => $driver->describe(),
		);
		$job->log( 'info', sprintf( 'Sent %s to %s.', $name, $driver->describe() ) );

		if ( $job->option( 'delete_after_send', false ) ) {
			$deleted = Io::quiet(
				static function () use ( $file ) {
					return unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
			$result['deleted_local'] = (bool) $deleted;
			if ( $deleted ) {
				$job->log( 'info', 'Deleted the copy on this server, as asked.' );
			} else {
				$job->warn( 'The copy on this server could not be deleted.' );
			}
		}
		$job->set_result( $result );
		$job->set_progress( sprintf( 'Sent to %s', $driver->describe() ), 1, 1 );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$state = $job->state( self::STATE );
		if ( ! isset( $state['upload'] ) || ! empty( $state['finished'] ) ) {
			return;
		}
		Destinations::driver( (string) $job->option( 'send_to' ) )->abort( $state['upload'] );
		$job->log( 'info', 'Removed the unfinished upload from the storage.' );
	}

	/**
	 * The backup file this job sends.
	 *
	 * @param Job $job The job.
	 */
	private static function source( Job $job ): string {
		if ( 'export' === $job->type() ) {
			// The finished backup, which is a .wpress file when the export made one.
			$result = $job->result();
			return ! empty( $result['file'] ) ? (string) $result['file'] : $job->option( 'output_dir' ) . '/' . $job->option( 'archive' );
		}
		return (string) $job->option( 'file' );
	}

	/**
	 * Reads exactly $length bytes at $offset.
	 *
	 * @param resource $handle File handle.
	 * @param int      $offset Position.
	 * @param int      $length Bytes.
	 * @throws Job_Exception If the file ends early.
	 */
	private static function read( $handle, int $offset, int $length ): string {
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new Job_Exception( 'Cannot read the backup.', Job_Exception::STEP );
		}
		$bytes = '';
		while ( strlen( $bytes ) < $length ) {
			$chunk = fread( $handle, $length - strlen( $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				throw new Job_Exception( 'The backup ended early while it was being sent.', Job_Exception::STEP );
			}
			$bytes .= $chunk;
		}
		return $bytes;
	}
}
