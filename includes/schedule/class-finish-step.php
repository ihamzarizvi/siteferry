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
		// Several storages (0.38.0): each one that got it, and what went wrong with the others.
		$sent     = isset( $result['sent_all'] ) ? (array) $result['sent_all'] : ( isset( $result['sent'] ) ? array( $result['sent'] + array( 'ok' => true ) ) : array() );
		$places   = array();
		$failures = array();
		foreach ( $sent as $one ) {
			if ( ! empty( $one['ok'] ) ) {
				$where[]  = (string) $one['where'];
				$places[] = (string) $one['destination'];
			} else {
				/* translators: 1: a storage, such as Dropbox, 2: what went wrong */
				$failures[] = sprintf( __( 'Not sent to %1$s: %2$s', 'siteferry' ), (string) $one['where'], (string) $one['error'] );
			}
		}
		$backup = array(
			'name'         => (string) $result['name'],
			'size'         => isset( $result['size'] ) ? (int) $result['size'] : 0,
			'local'        => $local,
			'destination'  => isset( $places[0] ) ? $places[0] : ( array() === $sent ? $destination : '' ),
			'destinations' => $places,
			'where'        => implode( ', ', $where ),
			'base'         => isset( $result['base'] ) ? (string) $result['base'] : '',
		);

		$kept             = Schedule::record_success( $job, $backup );
		$kept['problems'] = array_merge( $failures, $kept['problems'] );
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
