<?php
/**
 * WP-CLI commands: wp siteferry schedule ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Schedule\Schedule;
use SiteFerry\Storage\Destinations;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the backup schedule and runs it now. The schedule itself is set on the Backups tab
 * ("Schedule"). WP-Cron starts scheduled backups; on sites with DISABLE_WP_CRON, a server cron job
 * must run wp-cron.php (or `wp cron event run --due-now`).
 */
final class Schedule_Command {

	/**
	 * Shows the schedule, the next run and the last one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry schedule show
	 *
	 * @param string[] $args Positional arguments (none).
	 */
	public function show( $args ): void {
		unset( $args );
		$overview = Schedule::overview();
		$settings = $overview['settings'];
		if ( ! $settings['enabled'] ) {
			WP_CLI::log( 'No schedule: backups are made only when you start them.' );
		} else {
			$days  = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
			$when  = 'daily' === $settings['frequency'] ? 'every day' : ( 'weekly' === $settings['frequency'] ? 'every ' . $days[ $settings['weekday'] ] : 'on day ' . $settings['monthday'] . ' of every month' );
			$targets = Schedule::targets_of( $settings );
			$where   = array() === $targets ? 'this server' : implode( ', ', array_map( array( Destinations::class, 'short_label' ), $targets ) ) . ( $settings['keep_local'] ? ' (and this server)' : '' );
			$keep = 0 === $settings['retain'] ? 'every scheduled backup' : 'the newest ' . $settings['retain'];
			if ( \SiteFerry\Schedule\Retention::PERIODS === $settings['retain_mode'] ) {
				$keep = sprintf( 'one a day for %d days, one a week for %d weeks and one a month for %d months', $settings['keep_daily'], $settings['keep_weekly'], $settings['keep_monthly'] );
			}
			WP_CLI::log( sprintf( 'Schedule: %s at %s (%s), to %s, keeping %s.', $when, $settings['time'], $overview['timezone'], $where, $keep ) );
			WP_CLI::log( 'Next run: ' . ( null === $overview['next'] ? 'not planned' : wp_date( 'Y-m-d H:i T', $overview['next'] ) ) . ( null === $overview['retry'] ? '' : ' (retry after another job: ' . wp_date( 'Y-m-d H:i T', $overview['retry'] ) . ')' ) );
			if ( $overview['cron_disabled'] ) {
				WP_CLI::warning( 'DISABLE_WP_CRON is set: a server cron job must run wp-cron.php, or scheduled backups never start.' );
			}
		}
		$last = $overview['last'];
		if ( is_array( $last ) ) {
			WP_CLI::log( sprintf( 'Last run: %s, %s%s.', wp_date( 'Y-m-d H:i T', (int) $last['time'] ), $last['status'], '' === $last['message'] ? ( '' === $last['name'] ? '' : ': ' . $last['name'] ) : ': ' . $last['message'] ) );
		}
		if ( null !== $overview['running'] ) {
			WP_CLI::log( 'Running now: ' . $overview['running']['progress']['message'] );
		}
	}

	/**
	 * Makes the scheduled backup now, in this process, with the schedule's settings (and its
	 * retention and email). Works whether or not the schedule is turned on.
	 *
	 * ## OPTIONS
	 *
	 * [--quiet]
	 * : No progress lines.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry schedule run
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function run( $args, $assoc_args ): void {
		unset( $args );
		try {
			$job = Schedule::start_job();
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		Command::drive( $job, (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'quiet', false ) );
		Schedule::after_slice( $job ); // Clears it from the schedule's state.
		if ( 'done' !== $job->status() ) {
			WP_CLI::error( 'The scheduled backup failed: ' . (string) $job->error() );
		}
		$result = $job->result();
		WP_CLI::success( sprintf( 'Scheduled backup done: %s.', $result['name'] ) );
	}
}
