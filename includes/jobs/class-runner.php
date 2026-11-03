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
	 * Errors end the job: it is marked failed and every step removes its temporary output.
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If another request is running a slice of the same job.
	 */
	public static function run_slice( Job $job, Slice_Budget $budget ): void {
		if ( $job->finished() ) {
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
				if ( ! $done || $budget->exhausted() ) {
					break;
				}
			}
			if ( null === $job->current_step() ) {
				$job->set_status( Job::STATUS_DONE );
				$job->log( 'info', 'Job finished.' );
			}
		} catch ( \Throwable $e ) {
			$job->fail( $e->getMessage() );
			foreach ( $job->steps() as $name ) {
				try {
					self::make( $name )->cleanup( $job );
				} catch ( \Throwable $ignored ) {
					$job->log( 'warning', 'Cleanup of ' . $name . ' failed: ' . $ignored->getMessage() );
				}
			}
		} finally {
			$job->save();
			$job->unlock();
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
