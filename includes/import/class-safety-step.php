<?php
/**
 * Import step: a safety backup of the site before anything changes.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Export\Export;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;
use SiteFerry\Labels;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * After the restore is confirmed and before anything on the site changes, makes a whole-site
 * backup of the site as it is (an export job of its own, run slice by slice inside this step), so
 * the restore can be undone later by restoring that backup.
 *
 * It is tagged "before-restore" and named after the backup being restored. If the restore then
 * fails and the site is left as it was (or put back completely), the safety backup is not needed
 * and is removed; if putting the site back did not fully succeed, it is kept.
 */
final class Safety_Step implements Step {

	const STEP  = 'import.safety';
	const STATE = 'safety';
	const TAG   = 'before-restore';

	/**
	 * Whether this import makes a safety backup: the choice made when confirming, else the option.
	 *
	 * @param Job $job Import job.
	 */
	public static function wanted( Job $job ): bool {
		$confirm = $job->state( 'confirm' );
		return isset( $confirm['safety'] ) ? (bool) $confirm['safety'] : (bool) $job->option( 'safety', true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    Import job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the safety backup fails (nothing on the site has changed then).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		if ( ! self::wanted( $job ) ) {
			$job->log( 'info', 'No safety backup, as asked.' );
			return true;
		}
		$state = $job->state( self::STATE );
		if ( empty( $state['export'] ) ) {
			$archive = basename( (string) $job->option( 'archive' ) );
			$label   = Labels::get( $archive );
			$what    = null !== $label && '' !== $label['title'] ? $label['title'] : $archive;
			list( $export ) = Export::start(
				array(
					'title' => 'Before restoring ' . $what,
					'tags'  => array( self::TAG ),
				),
				null,
				'safety',
				$job->id()
			);
			$state = array( 'export' => $export->id() );
			$job->set_state( self::STATE, $state );
			$job->save();
			$job->log( 'info', sprintf( 'Making a safety backup of this site before anything changes (job %s).', $export->id() ) );
		}

		$export = Job::load( Storage::jobs_dir(), (string) $state['export'] );
		Runner::run_slice( $export, $budget );
		$progress = $export->progress();
		$job->set_progress( 'Safety backup: ' . $progress['message'], (int) $progress['done'], (int) $progress['total'] );
		if ( Job::STATUS_FAILED === $export->status() ) {
			throw new Job_Exception( 'The safety backup could not be made: ' . $export->error() . ' Nothing on this site has changed. To restore without one, untick "Make a safety backup" and try again.', Job_Exception::STEP );
		}
		if ( ! $export->finished() ) {
			return false;
		}

		$made             = $export->result();
		$result           = $job->result();
		$result['safety'] = array(
			'name' => (string) $made['name'],
			'size' => (int) $made['size'],
		);
		$job->set_result( $result );
		$job->log( 'info', sprintf( 'Safety backup made: %s (%s).', $made['name'], size_format( (int) $made['size'], 1 ) ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The restore stopped. An unfinished safety backup is stopped too (its partial file goes); a
	 * finished one is removed when the site is as it was, and kept when putting it back did not
	 * fully succeed. (Cleanups run last step first, so the swap's rollback has already run.)
	 *
	 * @param Job $job Import job.
	 */
	public function cleanup( Job $job ): void {
		$state = $job->state( self::STATE );
		if ( empty( $state['export'] ) ) {
			return;
		}
		try {
			$export = Job::load( Storage::jobs_dir(), (string) $state['export'] );
		} catch ( Job_Exception $e ) {
			return;
		}
		if ( ! $export->finished() ) {
			Runner::cancel( $export, 'Stopped: the restore it was made for stopped.' );
			return;
		}
		if ( Job::STATUS_DONE !== $export->status() ) {
			return;
		}
		$name   = (string) $export->result()['name'];
		$result = $job->result();
		if ( ! empty( $result['rollback_errors'] ) ) {
			$job->log( 'warning', sprintf( 'Keeping the safety backup %s: putting the previous site back did not fully succeed.', $name ) );
			return;
		}
		$file = Storage::backups_dir() . '/' . $name;
		if ( is_file( $file ) && ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return;
		}
		\SiteFerry\Contents::forget( $name );
		\SiteFerry\Check\Check::forget( $name );
		try {
			Labels::set( $name, '', '', array() );
		} catch ( Job_Exception $e ) {
			unset( $e );
		}
		unset( $result['safety'] );
		$job->set_result( $result );
		$job->log( 'info', sprintf( 'Removed the safety backup %s: the site was left as it was.', $name ) );
	}
}
