<?php
/**
 * Scheduled backups inside WordPress, with real (small) backups: settings through the REST API,
 * the cron start, "Run it now" carried by the site's own background requests, retention, email,
 * waiting for another job, the watchdog, the schedule surviving a lost cron table, and WP-CLI.
 * On a test site where SiteFerry is active and no schedule is set up:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-schedule.php
 *
 * The backups leave out media, plugins and themes to stay small, and are deleted at the end with
 * the schedule's settings. Emails are caught (pre_wp_mail) in this process; the ones sent by the
 * site's own background requests go wherever the site's mail goes (LocalWP keeps them).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Backups;
use SiteFerry\Export\Export;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Schedule\Schedule' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/fixtures.php';
require dirname( __DIR__ ) . '/lib/wp-rest.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

if ( false !== get_option( Schedule::OPTION ) || false !== get_option( Schedule::STATE ) ) {
	echo "A backup schedule is already set up on this site; this test would replace it. Remove it first.\n";
	exit( 1 );
}
if ( null !== Job::active( Storage::jobs_dir() ) ) {
	echo "Another SiteFerry job is active on this site; finish or cancel it first.\n";
	exit( 1 );
}

echo 'Schedule tests on ' . home_url() . "\n";

$sf_admin = (int) get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
)[0];
$sf_mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) use ( &$sf_mails ) {
		$sf_mails[] = $atts;
		return true;
	},
	10,
	2
);
$sf_made  = array();
$sf_small = array(
	'exclude_media'   => true,
	'exclude_plugins' => true,
	'exclude_themes'  => true,
);

/**
 * Forgets cached options, so values written by the site's own requests are read fresh.
 */
function sf_fresh(): void {
	foreach ( array( Schedule::OPTION, Schedule::STATE, \SiteFerry\Labels::OPTION, 'cron' ) as $option ) {
		wp_cache_delete( $option, 'options' );
	}
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
}

/**
 * Runs a job to its end in this process, as WP-CLI does, then lets the schedule see the result.
 *
 * @param Job $job The job.
 */
function sf_drive_here( Job $job ): Job {
	do {
		Runner::run_slice( $job, Slice_Budget::unlimited() );
	} while ( ! $job->finished() && Job::STATUS_WAITING !== $job->status() );
	Schedule::after_slice( $job );
	return $job;
}

/**
 * Waits for a job that other requests run.
 *
 * @param string $id      Job id.
 * @param int    $seconds Longest wait.
 */
function sf_wait( string $id, int $seconds ): Job {
	$until = time() + $seconds;
	do {
		$job = Job::load( Storage::jobs_dir(), $id );
		if ( $job->finished() ) {
			return $job;
		}
		sleep( 1 );
	} while ( time() < $until );
	return $job;
}

/**
 * Sets fields of the schedule's state (as a test fixture).
 *
 * @param array $fields Fields.
 */
function sf_set_state( array $fields ): void {
	sf_fresh();
	update_option( Schedule::STATE, $fields + Schedule::state(), false );
}

/**
 * Cron events of a hook: [time, args].
 *
 * @param string $hook Hook.
 */
function sf_events( string $hook ): array {
	sf_fresh();
	$found = array();
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		foreach ( isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : array() as $event ) {
			$found[] = array( (int) $time, $event['args'] );
		}
	}
	return $found;
}

Sf_Test::run(
	'settings through the REST API: administrators only, checked, saved without autoload, next run planned in the site\'s time zone',
	static function () use ( $sf_admin, $sf_small ) {
		list( $status ) = sf_rest( 'GET', '/schedule', 0 );
		Sf_Test::ok( in_array( $status, array( 401, 403 ), true ), 'anonymous read refused: ' . $status );
		list( $status ) = sf_rest( 'POST', '/schedule', 0, array(), array( 'enabled' => true ) );
		Sf_Test::ok( in_array( $status, array( 401, 403 ), true ), 'anonymous save refused: ' . $status );
		list( $status ) = sf_rest( 'POST', '/schedule/run', 0 );
		Sf_Test::ok( in_array( $status, array( 401, 403 ), true ), 'anonymous run refused: ' . $status );
		Sf_Test::same( false, get_option( Schedule::OPTION ), 'nothing saved:' );

		list( $status, $data ) = sf_rest( 'POST', '/schedule', $sf_admin, array(), array( 'enabled' => true, 'frequency' => 'weekly', 'weekday' => 3, 'time' => '04:30', 'retain' => 2, 'notify' => 'always', 'not_a_setting' => 'x' ) + $sf_small );
		Sf_Test::same( 200, $status, 'save: ' . wp_json_encode( $data ) );
		Sf_Test::same( true, $data['settings']['enabled'] );
		Sf_Test::ok( ! isset( $data['settings']['not_a_setting'] ), 'unknown fields dropped' );
		$next = wp_next_scheduled( Schedule::HOOK_START );
		Sf_Test::same( $next, $data['next'], 'the planned run is the cron event:' );
		$local = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
		Sf_Test::same( '3 04:30', $local->format( 'w H:i' ), 'Wednesday 04:30 site time (' . wp_timezone_string() . '):' );
		Sf_Test::ok( $next > time() && $next <= time() + 7 * 86400, 'within the next week' );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Schedule::OPTION ) );
		Sf_Test::ok( in_array( $autoload, array( 'no', 'off' ), true ), 'not autoloaded: ' . $autoload );

		foreach ( array( array( 'time' => '25:00' ), array( 'send_to' => 'dropbox' ), array( 'email' => 'nobody' ), array( 'frequency' => 'hourly' ) ) as $bad ) {
			list( $status, $error ) = sf_rest( 'POST', '/schedule', $sf_admin, array(), $bad );
			Sf_Test::same( 400, $status, wp_json_encode( $bad ) . ' ' . wp_json_encode( $error ) );
		}
		sf_fresh();
		Sf_Test::same( '04:30', Schedule::settings()['time'], 'unchanged after invalid input:' );

		list( , $data ) = sf_rest( 'POST', '/schedule', $sf_admin, array(), array( 'enabled' => false ) );
		Sf_Test::same( null, $data['next'], 'turned off: nothing planned' );
		Sf_Test::same( array(), sf_events( Schedule::HOOK_START ) );
		Sf_Test::same( 'weekly', $data['settings']['frequency'], 'the other settings stay:' );

		list( , $data ) = sf_rest( 'POST', '/schedule', $sf_admin, array(), array( 'enabled' => true, 'frequency' => 'daily', 'time' => '03:00', 'notify' => 'failure' ) );
		Sf_Test::same( 1, count( sf_events( Schedule::HOOK_START ) ), 'exactly one start planned:' );
	}
);

Sf_Test::run(
	'cron: a due start makes a scheduled backup, plans the next one, and records the result',
	static function () use ( &$sf_made ) {
		sf_set_state( array( 'last_start' => 0 ) );
		do_action( Schedule::HOOK_START ); // As WP-Cron calls it.
		sf_fresh();
		$state = Schedule::state();
		Sf_Test::ok( is_array( $state['current'] ) || is_array( $state['last'] ), 'a backup was started' );
		$id  = is_array( $state['current'] ) ? $state['current']['job'] : $state['last']['job'];
		$job = sf_wait( $id, 300 );
		Sf_Test::same( 'done', $job->status(), 'scheduled backup: ' . (string) $job->error() );
		Sf_Test::same( 'schedule', $job->summary()['origin'] );
		Sf_Test::same( 'schedule.finish', end( $job->summary()['step']['names'] ), 'its last step:' );
		$name      = $job->result()['name'];
		$sf_made[] = $name;
		Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $name ), 'stored on this server' );
		sf_fresh();
		Sf_Test::same( array( 'scheduled' ), \SiteFerry\Labels::get( $name )['tags'], 'tagged "scheduled":' );
		$state = Schedule::state();
		Sf_Test::same( 'done', $state['last']['status'] );
		Sf_Test::same( $name, $state['last']['name'] );
		Sf_Test::same( array( $name ), array_column( $state['runs'], 'name' ) );
		$starts = sf_events( Schedule::HOOK_START );
		Sf_Test::same( 1, count( $starts ), 'the next run is planned (and only that):' );
		Sf_Test::ok( $starts[0][0] > time(), 'in the future' );

		do_action( Schedule::HOOK_START ); // A copy of the event right after a start does nothing.
		sf_fresh();
		Sf_Test::same( $id, Schedule::state()['last']['job'], 'no second backup:' );
	}
);

Sf_Test::run(
	'"Run it now" hands the backup to the site\'s own background requests, which finish it alone',
	static function () use ( $sf_admin, &$sf_made ) {
		list( $status, $data ) = sf_rest( 'POST', '/schedule/run', $sf_admin );
		Sf_Test::same( 200, $status, 'run now: ' . wp_json_encode( $data ) );
		$id = $data['job']['id'];
		Sf_Test::same( 'pending', $data['job']['status'], 'this request ran nothing itself:' );
		list( $status ) = sf_rest( 'POST', '/schedule/run', $sf_admin );
		Sf_Test::same( 409, $status, 'a second start while it runs:' );

		$job = sf_wait( $id, 300 );
		Sf_Test::same( 'done', $job->status(), 'finished by the background requests: ' . (string) $job->error() . ' / ' . $job->progress()['message'] );
		$sf_made[] = $job->result()['name'];
		$log       = (string) file_get_contents( $job->path( 'job.log' ) );
		Sf_Test::ok( false !== strpos( $log, 'Started by the backup schedule.' ), 'logged as scheduled' );
		sf_fresh();
		Sf_Test::same( $job->result()['name'], Schedule::state()['last']['name'] );
		Sf_Test::same( null, Schedule::state()['current'], 'no longer running:' );
	}
);

Sf_Test::run(
	'retention keeps the newest scheduled backups everywhere; backups made by hand are never deleted',
	static function () use ( &$sf_made, $sf_small ) {
		list( $manual ) = Export::start( $sf_small );
		sf_drive_here( $manual );
		Sf_Test::same( 'done', $manual->status() );
		$sf_made[] = $manual->result()['name'];

		$names = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$job       = sf_drive_here( Schedule::start_job() );
			$names[]   = $job->result()['name'];
			$sf_made[] = $job->result()['name'];
			Sf_Test::same( 'done', $job->status() );
		}
		sf_fresh();
		$state = Schedule::state();
		Sf_Test::same( $names, array_column( $state['runs'], 'name' ), 'the newest two remembered:' );
		foreach ( array_slice( $sf_made, 0, 2 ) as $old ) {
			Sf_Test::ok( ! is_file( Storage::backups_dir() . '/' . $old ), 'older scheduled backup deleted: ' . $old );
			Sf_Test::same( null, \SiteFerry\Labels::get( $old ), 'and its label' );
		}
		foreach ( $names as $kept ) {
			Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $kept ), 'kept: ' . $kept );
		}
		Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $manual->result()['name'] ), 'the backup made by hand stays' );
		Sf_Test::same( 1, $state['last']['deleted'], 'the last run deleted one:' );
	}
);

Sf_Test::run(
	'email: after every backup when asked, on failure by default, never for a person\'s cancel',
	static function () use ( &$sf_mails, &$sf_made ) {
		Schedule::save( array( 'notify' => 'always', 'email' => 'backups@example.test' ) );
		$sf_mails = array();
		$job      = sf_drive_here( Schedule::start_job() );
		$sf_made[] = $job->result()['name'];
		Sf_Test::same( 1, count( $sf_mails ), 'one email:' );
		Sf_Test::same( 'backups@example.test', $sf_mails[0]['to'] );
		Sf_Test::ok( false !== strpos( $sf_mails[0]['subject'], 'Scheduled backup done' ), $sf_mails[0]['subject'] );
		Sf_Test::ok( false !== strpos( $sf_mails[0]['message'], $job->result()['name'] ), 'names the backup' );
		Sf_Test::ok( false !== strpos( $sf_mails[0]['message'], 'Older scheduled backups deleted:' ), 'says what retention removed' );

		Schedule::save( array( 'notify' => 'failure', 'email' => '' ) );
		$sf_mails  = array();
		$job       = sf_drive_here( Schedule::start_job() );
		$sf_made[] = $job->result()['name'];
		Sf_Test::same( 0, count( $sf_mails ), 'no email for a success by default:' );

		$failing = Schedule::start_job();
		Runner::cancel( $failing, 'Simulated failure for the test.' );
		Schedule::after_slice( $failing );
		Sf_Test::same( 1, count( $sf_mails ), 'an email for the failure:' );
		Sf_Test::same( get_option( 'admin_email' ), $sf_mails[0]['to'], 'to the admin email by default:' );
		Sf_Test::ok( false !== strpos( $sf_mails[0]['subject'], 'FAILED' ) && false !== strpos( $sf_mails[0]['message'], 'Simulated failure for the test.' ), $sf_mails[0]['message'] );
		sf_fresh();
		Sf_Test::same( 'failed', Schedule::state()['last']['status'] );

		$stopped = Schedule::start_job();
		Runner::cancel( $stopped );
		Schedule::after_slice( $stopped );
		Sf_Test::same( 1, count( $sf_mails ), 'no email when a person stops it:' );
		sf_fresh();
		Sf_Test::same( 'Cancelled.', Schedule::state()['last']['message'] );
		Sf_Test::ok( ! is_file( Storage::backups_dir() . '/' . $stopped->option( 'archive' ) ), 'nothing left of the stopped backup' );
	}
);

Sf_Test::run(
	'busy: a due backup waits for another job, retrying every 15 minutes, and is skipped (with an email) after two hours',
	static function () use ( &$sf_mails, $sf_small ) {
		list( $other ) = Export::start( $sf_small );
		sf_set_state( array( 'last_start' => 0 ) );
		$sf_mails = array();
		do_action( Schedule::HOOK_START );
		sf_fresh();
		Sf_Test::same( null, Schedule::state()['current'], 'nothing started:' );
		$retries = array_values(
			array_filter(
				sf_events( Schedule::HOOK_START ),
				static function ( array $event ): bool {
					return array() !== $event[1];
				}
			)
		);
		Sf_Test::same( 1, count( $retries ), 'one retry planned:' );
		Sf_Test::same( array( 1 ), $retries[0][1] );
		Sf_Test::ok( abs( $retries[0][0] - ( time() + Schedule::RETRY_DELAY ) ) < 60, 'in 15 minutes' );
		Sf_Test::same( $retries[0][0], Schedule::overview()['retry'], 'shown on the admin screen:' );

		do_action( Schedule::HOOK_START, Schedule::MAX_RETRIES );
		sf_fresh();
		Sf_Test::same( 'skipped', Schedule::state()['last']['status'] );
		Sf_Test::same( 1, count( $sf_mails ), 'an email about the skipped backup:' );

		Runner::cancel( $other );
		wp_unschedule_hook( Schedule::HOOK_START );
		Schedule::ensure_scheduled();
	}
);

Sf_Test::run(
	'watchdog: carries on a backup whose own requests stopped, and stops one without progress for an hour',
	static function () use ( &$sf_mails, &$sf_made ) {
		$job  = Schedule::start_job(); // Started, but nobody runs it.
		$file = $job->path( 'job.json' );
		$data = json_decode( (string) file_get_contents( $file ), true );
		$data['updated'] = time() - 600;
		file_put_contents( $file, wp_json_encode( $data ) );
		Sf_Test::ok( false !== wp_next_scheduled( Schedule::HOOK_WATCH ), 'the watchdog is planned while it runs' );

		do_action( Schedule::HOOK_WATCH );
		$job = sf_wait( $job->id(), 300 );
		Sf_Test::same( 'done', $job->status(), 'the watchdog ran it (and its requests finished it): ' . (string) $job->error() );
		$sf_made[] = $job->result()['name'];
		sf_fresh();
		Sf_Test::same( null, Schedule::state()['current'] );
		Sf_Test::same( false, wp_next_scheduled( Schedule::HOOK_WATCH ), 'the watchdog stops with the backup:' );

		$stuck = Schedule::start_job();
		$mark  = $stuck->step_index() . ':' . (int) $stuck->progress()['done'] . ':' . (string) $stuck->progress()['message'];
		sf_fresh();
		$state                    = Schedule::state();
		$state['current']['seen'] = array(
			'mark' => $mark,
			'at'   => time() - Schedule::STALL_LIMIT - 60,
		);
		update_option( Schedule::STATE, $state, false );
		$sf_mails = array();
		do_action( Schedule::HOOK_WATCH );
		$stuck = Job::load( Storage::jobs_dir(), $stuck->id() );
		Sf_Test::same( 'failed', $stuck->status() );
		Sf_Test::ok( false !== strpos( (string) $stuck->error(), 'no progress for an hour' ), (string) $stuck->error() );
		Sf_Test::same( 1, count( $sf_mails ), 'an email about it:' );

		do_action( Schedule::HOOK_WATCH ); // Nothing running: it removes itself.
		Sf_Test::same( false, wp_next_scheduled( Schedule::HOOK_WATCH ) );
	}
);

Sf_Test::run(
	'the next run comes back after an import replaced the cron table, and after reactivation',
	static function () {
		wp_unschedule_hook( Schedule::HOOK_START ); // As an import of another site's database would.
		Schedule::ensure_scheduled();
		Sf_Test::same( Schedule::next_run( Schedule::settings(), time(), wp_timezone() ), wp_next_scheduled( Schedule::HOOK_START ), 'planned again:' );
		\SiteFerry\Plugin::deactivate();
		Sf_Test::same( false, wp_next_scheduled( Schedule::HOOK_START ), 'deactivation removes it:' );
		Schedule::ensure_scheduled();
		Sf_Test::ok( false !== wp_next_scheduled( Schedule::HOOK_START ), 'back' );
	}
);

Sf_Test::run(
	'WP-CLI: schedule show and run',
	static function () use ( &$sf_made ) {
		$cli  = static function ( string $command ) {
			return WP_CLI::runcommand(
				$command,
				array(
					'launch'     => false,
					'exit_error' => false,
					'return'     => 'all',
				)
			);
		};
		$show = $cli( 'siteferry schedule show' );
		Sf_Test::same( 0, $show->return_code, $show->stderr );
		Sf_Test::ok( false !== strpos( $show->stdout, 'Schedule: every day at 03:00' ) && false !== strpos( $show->stdout, 'Next run:' ), $show->stdout );
		$run = $cli( 'siteferry schedule run --quiet' );
		Sf_Test::same( 0, $run->return_code, 'schedule run: ' . $run->stderr );
		Sf_Test::ok( (bool) preg_match( '/Scheduled backup done: (\S+)\./', $run->stdout, $match ), $run->stdout );
		$sf_made[] = $match[1];
	}
);

// Clean up: every backup made here, the schedule and its events.
foreach ( array_unique( $sf_made ) as $sf_name ) {
	$sf_path = Storage::backups_dir() . '/' . $sf_name;
	if ( is_file( $sf_path ) ) {
		unlink( $sf_path );
	}
	\SiteFerry\Labels::set( $sf_name, '', '', array() );
}
delete_option( Schedule::OPTION );
delete_option( Schedule::STATE );
Schedule::unschedule_all();

exit( Sf_Test::finish( 'wp/test-schedule' ) );
