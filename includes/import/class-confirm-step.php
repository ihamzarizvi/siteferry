<?php
/**
 * Import step: wait until the user confirms.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Parks the job in STATUS_WAITING until Import::confirm() is called (or the job was started
 * already confirmed). Nothing on the site has changed at this point.
 */
final class Confirm_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		if ( $job->option( 'confirmed' ) || ! empty( $job->state( 'confirm' )['confirmed'] ) ) {
			return true;
		}
		$job->set_status( Job::STATUS_WAITING );
		$job->set_progress( __( 'Waiting for confirmation', 'siteferry' ) );
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}
}
