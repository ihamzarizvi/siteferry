<?php
/**
 * Runs jobs one slice at a time.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Executes the steps of a job within a slice budget.
 *
 * The browser calls "run next slice" repeatedly; WP-CLI calls run_slice() in a loop in one
 * process. Both use the same steps, so they behave the same.
 */
final class Runner {

	/**
	 * Step name => class.
	 *
	 * @var array<string,string>
	 */
	const STEPS = array(
		'export.scan'     => \SiteFerry\Export\Scan_Step::class,
		'export.database' => \SiteFerry\Export\Database_Step::class,
		'export.pack'     => \SiteFerry\Export\Pack_Step::class,
		'export.finish'   => \SiteFerry\Export\Finish_Step::class,
		'export.wpress'   => \SiteFerry\Wpress\Export_Step::class,
		'export.send'     => \SiteFerry\Storage\Send_Step::class,
		'send.upload'     => \SiteFerry\Storage\Send_Step::class,
		'fetch.download'  => \SiteFerry\Storage\Fetch_Step::class,
		'import.wpress'   => \SiteFerry\Wpress\Import_Step::class,
		'import.inspect'  => \SiteFerry\Import\Inspect_Step::class,
		'import.confirm'  => \SiteFerry\Import\Confirm_Step::class,
		'import.extract'  => \SiteFerry\Import\Extract_Step::class,
		'import.verify'   => \SiteFerry\Import\Verify_Step::class,
		'import.database' => \SiteFerry\Import\Database_Step::class,
		'import.replace'  => \SiteFerry\Import\Replace_Step::class,
		'import.swap'     => \SiteFerry\Import\Swap_Step::class,
		'import.finish'   => \SiteFerry\Import\Finish_Step::class,
	);

	/**
	 * Steps registered at run time (tests).
	 *
	 * @var array<string,string>
	 */
	private static $extra = array();

	/**
	 * Registers an additional step class.
	 *
	 * @param string $name  Step name.
	 * @param string $class Class implementing Step.
	 */
	public static function register( string $name, string $class ): void {
		self::$extra[ $name ] = $class;
	}

	/**
	 * Runs steps until the job ends or the budget is used up, then saves the job.
	 *
	 * Errors end the job: it is marked failed and every step undoes its work, last step first
	 * (an import puts the old tables and files back before the temporary ones are removed).
	 *
	 * A step may set the job to STATUS_WAITING (an import waiting for confirmation); the slice then
	 * ends and later slices do nothing until the job is set back to pending.
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If another request is running a slice of the same job.
	 */
	public static function run_slice( Job $job, Slice_Budget $budget ): void {
		if ( $job->finished() || Job::STATUS_WAITING === $job->status() ) {
			return;
		}
		if ( ! $job->lock() ) {
			throw new Job_Exception( 'This job is already running in another request.', Job_Exception::BUSY );
		}

		try {
			$job->set_status( Job::STATUS_RUNNING );
			while ( null !== ( $name = $job->current_step() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$done = self::make( $name )->run( $job, $budget );
				if ( $done ) {
					$job->advance();
				}
				$job->save();
				if ( ! $done || $budget->exhausted() || Job::STATUS_WAITING === $job->status() ) {
					break;
				}
			}
			if ( null === $job->current_step() ) {
				$job->set_status( Job::STATUS_DONE );
				$job->log( 'info', 'Job finished.' );
			}
		} catch ( \Throwable $e ) {
			$job->fail( $e->getMessage() );
			self::cleanup( $job );
		} finally {
			$job->save();
			$job->unlock();
		}
	}

	/**
	 * Stops an unfinished job and undoes its work, as a failure would.
	 *
	 * @param Job $job The job.
	 * @throws Job_Exception If a slice of the job is running right now.
	 */
	public static function cancel( Job $job ): void {
		if ( $job->finished() ) {
			return;
		}
		if ( ! $job->lock() ) {
			throw new Job_Exception( 'This job is running right now; try again in a moment.', Job_Exception::BUSY );
		}
		try {
			$job->fail( 'Cancelled.' );
			self::cleanup( $job );
		} finally {
			$job->save();
			$job->unlock();
		}
	}

	/**
	 * Runs every step's cleanup, last step first.
	 *
	 * @param Job $job The job.
	 */
	private static function cleanup( Job $job ): void {
		foreach ( array_reverse( $job->steps() ) as $name ) {
			try {
				self::make( $name )->cleanup( $job );
			} catch ( \Throwable $e ) {
				$job->log( 'warning', 'Cleanup of ' . $name . ' failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Instantiates a step by name.
	 *
	 * @param string $name Step name.
	 * @throws Job_Exception For unknown names.
	 */
	private static function make( string $name ): Step {
		$steps = self::STEPS + self::$extra;
		if ( ! isset( $steps[ $name ] ) ) {
			throw new Job_Exception( 'Unknown job step: ' . $name, Job_Exception::INVALID );
		}
		$class = $steps[ $name ];
		return new $class();
	}
}
