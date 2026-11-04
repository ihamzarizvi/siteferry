<?php
/**
 * Import step: remove the rollback copies after a successful import.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Drops the old tables, deletes the replaced files and the staging folder, and records the result.
 */
final class Finish_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		Database_Step::drop_prefixed( $wpdb, (string) $job->option( 'old_prefix' ) );
		Io::remove_tree( $job->path( 'rollback' ) );
		Io::remove_tree( Extract_Step::staging( $job ) );
		$job->delete_file( 'extracted.sha256' );
		$job->delete_file( Swap_Step::JOURNAL );

		$inspect = $job->state( 'inspect' );
		$replace = $job->state( 'replace' );
		$job->set_result(
			array_merge(
				$job->result(),
				array(
					'imported' => array(
						'from'         => $inspect['source']['home'],
						'to'           => $inspect['target']['home'],
						'tables'       => count( $inspect['tables'] ),
						'rows'         => $inspect['rows'],
						'files'        => $inspect['files'],
						'rows_changed' => $replace['rows'],
						'warnings'     => $job->warning_count(),
						'seconds'      => time() - $job->created(),
					),
				)
			)
		);
		$job->set_progress( 'Import complete', 1, 1 );
		$job->log( 'info', 'Import complete; rollback copies removed.' );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}
}
