<?php
/**
 * One step of a job.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A resumable unit of work (scan files, dump the database, pack, ...).
 *
 * run() is called once per slice until it returns true. It keeps its progress in
 * $job->state( name ) / $job->set_state( name, ... ); the runner saves the job after every call.
 */
interface Step {

	/**
	 * Does work until finished or the budget is used up.
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit for this slice.
	 * @return bool True when the step is complete.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool;

	/**
	 * Removes the step's temporary output after the job failed.
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void;
}
