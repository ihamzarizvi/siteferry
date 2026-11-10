<?php
/**
 * The weekly re-check of stored copies, in WordPress: three small scheduled backups on a fake S3;
 * one is damaged on purpose and one deleted there; a round finds the damaged one, the missing
 * one and the intact one, emails the problems, and its check jobs never count as the active job.
 * Started by tests\wp-copies-check.ps1 -Test test-recheck.php, which runs the fake servers.
 *
 * Needs a site with no schedule and no S3 storage set up; everything it makes is removed at the
 * end (the check jobs stay on the Activity tab, as checks do).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Check\Check;
use SiteFerry\Check\Recheck;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Check\Recheck' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/class-fake-s3.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

$sf_s3_dir = (string) getenv( 'SITEFERRY_FAKE_S3_DIR' );
if ( ! is_file( $sf_s3_dir . '/config.json' ) ) {
	echo "Start the fake S3 server first (tests\\wp-copies-check.ps1 does that).\n";
	exit( 1 );
}
if ( false !== get_option( Schedule::OPTION ) || false !== get_option( Schedule::STATE ) || false !== get_option( Recheck::STATE ) ) {
	echo "A backup schedule or re-check state is already on this site; this test would replace it. Remove it first.\n";
	exit( 1 );
}
if ( Destinations::configured( 's3' ) ) {
	echo "S3 storage is already set up on this site; this test would replace it. Remove it first.\n";
	exit( 1 );
}
if ( null !== Job::active( Storage::jobs_dir() ) ) {
	echo "Another SiteFerry job is active on this site; finish or cancel it first.\n";
	exit( 1 );
}

echo 'Weekly re-check on ' . home_url() . "\n";

$sf_config = json_decode( (string) file_get_contents( $sf_s3_dir . '/config.json' ), true );
$sf_s3     = new Fake_S3( $sf_s3_dir . '/data', $sf_config );
$sf_mails  = array();
add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) use ( &$sf_mails ) {
		$sf_mails[] = $atts;
		return true;
	},
	10,
	2
);

/**
 * Runs a job to its end in this process, then lets the schedule see the result.
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

$sf_names = array();
try {
	Destinations::save(
		's3',
		array(
			'preset'     => 'custom',
			'endpoint'   => (string) getenv( 'SITEFERRY_FAKE_S3_URL' ),
			'region'     => $sf_config['region'],
			'bucket'     => 'sftest',
			'folder'     => 'wp-recheck',
			'access_key' => $sf_config['access_key'],
			'secret_key' => $sf_config['secret_key'],
			'path_style' => true,
		)
	);
	Schedule::save(
		array(
			'enabled'         => false,
			'exclude_media'   => true,
			'exclude_plugins' => true,
			'exclude_themes'  => true,
			'keep_local'      => true,
			'notify'          => 'failure',
			'email'           => 'recheck-test@example.com',
			'send_to'         => array( 's3' ),
			'recheck'         => true,
			'recheck_cap_mb'  => 2048,
		)
	);
	for ( $i = 0; $i < 3; $i++ ) {
		$job = sf_drive_here( Schedule::start_job() );
		if ( Job::STATUS_DONE !== $job->status() ) {
			throw new RuntimeException( 'The scheduled backup failed: ' . (string) $job->error() );
		}
		$sf_names[] = (string) $job->result()['name'];
		sleep( 1 ); // Distinct times, so "newest first" is clear.
	}
	list( $sf_damaged, $sf_good, $sf_gone ) = $sf_names;

	Sf_Test::run(
		'the re-check is on with a storage, and planned weekly',
		static function () {
			Sf_Test::same( true, Recheck::enabled() );
			Sf_Test::ok( false !== wp_next_scheduled( Recheck::HOOK_WEEKLY ), 'the weekly event' );
		}
	);

	Sf_Test::run(
		'a round: the damaged copy is found, the missing one reported, the intact one ok; the problems are emailed; the checks never block other jobs',
		static function () use ( $sf_s3, $sf_damaged, $sf_good, $sf_gone, &$sf_mails ) {
			// Damage one copy in the middle (its file data), and delete another from the storage.
			$blob = $sf_s3->blob( 'wp-recheck/' . $sf_damaged );
			Sf_Test::ok( is_string( $blob ) && is_file( $blob ), 'the copy is in the fake store' );
			$bytes = (string) file_get_contents( $blob );
			$at    = (int) ( strlen( $bytes ) / 2 );
			file_put_contents( $blob, substr( $bytes, 0, $at ) . strrev( substr( $bytes, $at, 4096 ) ) . substr( $bytes, $at + 4096 ) );
			Destinations::driver( 's3' )->delete( $sf_gone );

			$round = Recheck::start_round();
			Sf_Test::same( array( $sf_good, $sf_damaged ), array_column( $round['queue'], 'name' ), 'newest first, two per storage:' );
			Sf_Test::same( array( $sf_gone ), array_column( $round['missing'], 'name' ), 'missing:' );

			$blocked = false;
			for ( $guard = 0; $guard < 400; $guard++ ) {
				Recheck::on_slice();
				$state = Recheck::state();
				if ( ! empty( $state['current'] ) && null !== Job::active( Storage::jobs_dir() ) ) {
					$blocked = true;
				}
				if ( array() === $state['queue'] && empty( $state['current'] ) ) {
					break;
				}
			}
			Sf_Test::same( false, $blocked, 'a background check never counted as the active job:' );
			$last = Recheck::state()['last'];
			Sf_Test::same( array( 2, 1 ), array( $last['checked'], $last['ok'] ), 'checked and intact:' );
			Sf_Test::same( 2, count( $last['bad'] ), 'damaged + missing: ' . implode( ' | ', $last['bad'] ) );
			$all = Check::all();
			Sf_Test::same(
				array( Check::OK, Check::DAMAGED, Check::MISSING ),
				array( $all[ $sf_good ]['s3']['state'], $all[ $sf_damaged ]['s3']['state'], $all[ $sf_gone ]['s3']['state'] ),
				'recorded:'
			);
			$mail = end( $sf_mails );
			Sf_Test::ok( false !== strpos( (string) $mail['subject'], 'damaged or missing' ), 'emailed: ' . (string) $mail['subject'] );
			Sf_Test::ok( false !== strpos( (string) $mail['message'], $sf_damaged ) && false !== strpos( (string) $mail['message'], $sf_gone ), 'the email names both' );
		}
	);

	Sf_Test::run(
		'turned off: the weekly event is removed',
		static function () {
			Schedule::save( array( 'recheck' => false ) );
			Sf_Test::same( false, wp_next_scheduled( Recheck::HOOK_WEEKLY ) );
		}
	);
} finally {
	foreach ( Schedule::state()['runs'] as $run ) {
		$problem = Schedule::delete_run( $run );
		if ( null !== $problem ) {
			echo 'Clean-up: ' . $problem . "\n";
		}
		Check::forget( (string) $run['name'] );
	}
	Sf_Test::run(
		'clean-up: nothing left on the fake S3',
		static function () use ( $sf_s3 ) {
			$left = array_filter(
				$sf_s3->keys(),
				static function ( string $key ): bool {
					return 0 === strpos( $key, 'wp-recheck/' );
				}
			);
			Sf_Test::same( array(), array_values( $left ) );
		}
	);
	Recheck::stop();
	Destinations::remove( 's3' );
	Schedule::unschedule_all();
	delete_option( Schedule::OPTION );
	delete_option( Schedule::STATE );
	delete_option( Recheck::STATE );
}

exit( Sf_Test::finish( 'test-recheck' ) );
