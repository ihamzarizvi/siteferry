<?php
/**
 * Export step: list the files to back up.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Writes files.list in the job folder.
 */
final class Scan_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$site    = $job->option( 'site' );
		$state   = $job->state( 'scan' );
		$scanner = new Scanner( $site['wp_content_dir'], Exclusions::from_array( $job->option( 'exclusions' ) ) );
		$done    = $scanner->run( $state, $job->path( 'files.list' ), $budget, array( $job, 'warn' ) );
		$job->set_state( 'scan', $state );

		$summary = sprintf( /* translators: 1: a number, 2: a number, 3: a size, such as 12 MB */ __( '%1$s files in %2$s folders, %3$s', 'siteferry' ), number_format( $state['files'] ), number_format( $state['dirs'] ), size_format( $state['bytes'], 1 ) );
		$job->set_progress( sprintf( /* translators: %s: progress, such as "1,200 files, 45 MB" */ __( 'Scanning files: %s', 'siteferry' ), $summary ));
		if ( $done ) {
			$job->log( 'info', 'Scan complete: ' . $summary . '.' );
		}
		return $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$job->delete_file( 'files.list' );
	}
}
