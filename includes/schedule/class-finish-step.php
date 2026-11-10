<?php
/**
 * Last step of a scheduled backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Schedule;

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * After the backup is made (and sent): records it, deletes the scheduled backups beyond the number
 * to keep, and sends the email. When the job fails or is stopped, cleanup() records and mails the
 * failure instead (cleanup runs for every step of a failed job, this one first).
 */
final class Finish_Step implements Step {

	const STEP = 'schedule.finish';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$result      = $job->result();
		$destination = (string) $job->option( 'send_to', '' );
		$local       = ! empty( $result['file'] ) && is_file( (string) $result['file'] );
		$where       = array();
		if ( $local ) {
			$where[] = 'this server';
		}
		if ( isset( $result['sent']['where'] ) ) {
			$where[] = (string) $result['sent']['where'];
		}
		$backup = array(
			'name'        => (string) $result['name'],
			'size'        => isset( $result['size'] ) ? (int) $result['size'] : 0,
			'local'       => $local,
			'destination' => $destination,
			'where'       => implode( ' and ', $where ),
			'base'        => isset( $result['base'] ) ? (string) $result['base'] : '',
		);

		$kept = Schedule::record_success( $job, $backup );
		foreach ( $kept['held'] as $name ) {
			$job->log( 'info', sprintf( 'Kept the older full backup %s beyond the number to keep: newer changes-only backups are built on it.', $name ) );
		}
		foreach ( $kept['deleted'] as $name ) {
			$job->log( 'info', sprintf( 'Deleted the older scheduled backup %s (keeping the newest %d).', $name, (int) Schedule::settings()['retain'] ) );
		}
		foreach ( $kept['problems'] as $problem ) {
			$job->warn( $problem );
		}
		$result['schedule'] = array( 'deleted' => $kept['deleted'] );
		$job->set_result( $result );

		$mailed = Schedule::notify(
			'done',
			$backup + array(
				'deleted'  => $kept['deleted'],
				'problems' => $kept['problems'],
				'warnings' => $job->warning_count(),
				'seconds'  => time() - $job->created(),
			)
		);
		if ( $mailed ) {
			$job->log( 'info', 'Sent the email about this backup.' );
		}
		$job->set_progress( __( 'Scheduled backup done', 'siteferry' ), 1, 1 );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The scheduled backup failed or was stopped: record it, and email unless a person cancelled it.
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$error = (string) $job->error();
		Schedule::record_outcome( $job->id(), 'failed', $error, array() );
		if ( 'Cancelled.' !== $error && Schedule::notify( 'failed', array( 'error' => $error ) ) ) {
			$job->log( 'info', 'Sent the email about the failure.' );
		}
	}
}
