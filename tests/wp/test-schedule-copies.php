<?php
/**
 * Several storages per schedule, in WordPress: one scheduled run sends a small real backup to a
 * fake S3 and a fake FTPS server; a storage that fails does not stop the other; the run record,
 * retention and the 3-2-1 meter know every place. Started by tests\wp-copies-check.ps1, which runs
 * both fake servers:
 *
 *     powershell -File tests\wp-copies-check.ps1 -Site siteferry-dst
 *
 * Needs a site with no schedule and no S3 or FTP storage set up; everything it makes (backups,
 * the two storages, the schedule settings) is removed at the end.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Schedule\Copies' ) ) {
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

$sf_s3_dir  = (string) getenv( 'SITEFERRY_FAKE_S3_DIR' );
$sf_ftp_dir = (string) getenv( 'SITEFERRY_FAKE_FTP_DIR' );
if ( ! is_file( $sf_s3_dir . '/config.json' ) || ! is_file( $sf_ftp_dir . '/config.json' ) ) {
	echo "Start the fake S3 and FTP servers first (tests\\wp-copies-check.ps1 does that).\n";
	exit( 1 );
}
if ( false !== get_option( Schedule::OPTION ) || false !== get_option( Schedule::STATE ) ) {
	echo "A backup schedule is already set up on this site; this test would replace it. Remove it first.\n";
	exit( 1 );
}
if ( Destinations::configured( 's3' ) || Destinations::configured( 'ftp' ) ) {
	echo "S3 or FTP storage is already set up on this site; this test would replace it. Remove it first.\n";
	exit( 1 );
}
if ( null !== Job::active( Storage::jobs_dir() ) ) {
	echo "Another SiteFerry job is active on this site; finish or cancel it first.\n";
	exit( 1 );
}

echo 'Several storages per schedule on ' . home_url() . "\n";

$sf_s3_config  = json_decode( (string) file_get_contents( $sf_s3_dir . '/config.json' ), true );
$sf_ftp_config = json_decode( (string) file_get_contents( $sf_ftp_dir . '/config.json' ), true );
$sf_s3         = new Fake_S3( $sf_s3_dir . '/data', $sf_s3_config );
$sf_ftp_folder = $sf_ftp_dir . '/root/wp-copies';
$sf_s3_input   = array(
	'preset'     => 'custom',
	'endpoint'   => (string) getenv( 'SITEFERRY_FAKE_S3_URL' ),
	'region'     => $sf_s3_config['region'],
	'bucket'     => 'sftest',
	'folder'     => 'wp-copies',
	'access_key' => $sf_s3_config['access_key'],
	'secret_key' => $sf_s3_config['secret_key'],
	'path_style' => true,
);
$sf_ftp_input  = array(
	'host'       => '127.0.0.1',
	'port'       => (string) $sf_ftp_config['port'],
	'encryption' => 'ftps',
	'username'   => $sf_ftp_config['user'],
	'password'   => $sf_ftp_config['pass'],
	'folder'     => 'wp-copies',
	'passive'    => true,
);
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
$sf_settings = array(
	'enabled'         => false,
	'exclude_media'   => true,
	'exclude_plugins' => true,
	'exclude_themes'  => true,
	'keep_local'      => true,
	'notify'          => 'always',
	'email'           => 'copies-test@example.com',
	'send_to'         => array( 's3', 'ftp' ),
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

/**
 * Names in the fake S3 folder.
 *
 * @param Fake_S3 $s3 Fake.
 */
function sf_s3_names( Fake_S3 $s3 ): array {
	$names = array();
	foreach ( $s3->keys() as $key ) {
		if ( 0 === strpos( $key, 'wp-copies/' ) ) {
			$names[] = substr( $key, strlen( 'wp-copies/' ) );
		}
	}
	return $names;
}

/**
 * Names in the fake FTP folder.
 *
 * @param string $folder Folder.
 */
function sf_ftp_names( string $folder ): array {
	return is_dir( $folder ) ? array_values( array_diff( scandir( $folder ), array( '.', '..' ) ) ) : array();
}

try {
	Destinations::save( 's3', $sf_s3_input );
	Destinations::save( 'ftp', $sf_ftp_input );
	Schedule::save( $sf_settings );

	Sf_Test::run(
		'one run sends the backup to both storages and keeps a copy here; the run knows every place; 3-2-1 is met',
		static function () use ( $sf_s3, $sf_ftp_folder, &$sf_mails ) {
			$settings = Schedule::settings();
			Sf_Test::same( array( 's3', array( 'ftp' ) ), array( $settings['send_to'], $settings['send_also'] ), 'saved:' );
			$job = sf_drive_here( Schedule::start_job() );
			Sf_Test::same( Job::STATUS_DONE, $job->status(), 'done: ' . (string) $job->error() );
			$result = $job->result();
			$name   = (string) $result['name'];
			Sf_Test::same( array( true, true ), array_column( (array) $result['sent_all'], 'ok' ), 'both sent:' );
			Sf_Test::ok( in_array( $name, sf_s3_names( $sf_s3 ), true ), 'on the fake S3' );
			Sf_Test::ok( in_array( $name, sf_ftp_names( $sf_ftp_folder ), true ), 'on the fake FTP server' );
			Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $name ), 'kept on this server' );
			$runs = Schedule::state()['runs'];
			$last = end( $runs );
			Sf_Test::same( array( 's3', 'ftp' ), Schedule::places_of( $last ), 'the run record:' );
			$copies = Schedule::overview()['copies'];
			Sf_Test::same( array( 3, 3, 2, true ), array( $copies['newest']['copies'], $copies['newest']['kinds'], $copies['newest']['offsite'], $copies['newest']['ok'] ), '3-2-1:' );
			$mail = end( $sf_mails );
			Sf_Test::ok( false !== strpos( (string) $mail['message'], 'Stored: this server, ' ), 'the email lists the places: ' . (string) $mail['message'] );
		}
	);

	Sf_Test::run(
		'a storage that fails does not stop the other: the run is done, the email names the failure, only the good place is recorded',
		static function () use ( $sf_s3, $sf_ftp_folder, $sf_ftp_input, &$sf_mails ) {
			Destinations::save( 'ftp', array( 'password' => 'wrong-password-for-the-test' ) + $sf_ftp_input );
			$job = sf_drive_here( Schedule::start_job() );
			$GLOBALS['sf_failed_names'][] = isset( $job->result()['name'] ) ? (string) $job->result()['name'] : ''; // Gone with its run, unless it failed.
			Sf_Test::same( Job::STATUS_DONE, $job->status(), 'done: ' . (string) $job->error() );
			$result = $job->result();
			$name   = (string) $result['name'];
			Sf_Test::same( array( true, false ), array_column( (array) $result['sent_all'], 'ok' ), 'S3 yes, FTP no:' );
			Sf_Test::ok( in_array( $name, sf_s3_names( $sf_s3 ), true ), 'on the fake S3' );
			Sf_Test::ok( ! in_array( $name, sf_ftp_names( $sf_ftp_folder ), true ), 'not on the FTP server' );
			$runs = Schedule::state()['runs'];
			$last = end( $runs );
			Sf_Test::same( array( 's3' ), Schedule::places_of( $last ), 'only S3 recorded:' );
			$mail = end( $sf_mails );
			Sf_Test::ok( false !== strpos( (string) $mail['message'], 'Not sent to' ), 'the email says what failed: ' . (string) $mail['message'] );
			Sf_Test::ok( $job->warning_count() > 0, 'a warning in the job log' );
		}
	);

	Sf_Test::run(
		'when no storage gets it, the run fails with each reason',
		static function () use ( $sf_s3_input ) {
			Destinations::save( 's3', array( 'secret_key' => str_repeat( 'x', 40 ) ) + $sf_s3_input );
			$job = sf_drive_here( Schedule::start_job() );
			Sf_Test::same( Job::STATUS_FAILED, $job->status() );
			$GLOBALS['sf_failed_names'][] = isset( $job->result()['name'] ) ? (string) $job->result()['name'] : '';
			Sf_Test::ok( false !== strpos( (string) $job->error(), 'could not be sent to any storage' ), (string) $job->error() );
		}
	);
} finally {
	// Everything this test made goes: the scheduled backups (here and on both fakes), the storages, the schedule.
	try {
		Destinations::save( 's3', $sf_s3_input );
		Destinations::save( 'ftp', $sf_ftp_input );
		foreach ( Schedule::state()['runs'] as $run ) {
			$problem = Schedule::delete_run( $run );
			if ( null !== $problem ) {
				echo 'Clean-up: ' . $problem . "\n";
			}
		}
		Sf_Test::run(
			'clean-up: the scheduled backups are gone from both storages',
			static function () use ( $sf_s3, $sf_ftp_folder ) {
				Sf_Test::same( array(), sf_s3_names( $sf_s3 ), 'S3:' );
				Sf_Test::same( array(), sf_ftp_names( $sf_ftp_folder ), 'FTP:' );
			}
		);
	} finally {
		Destinations::remove( 's3' );
		Destinations::remove( 'ftp' );
		Schedule::unschedule_all();
		// Backups of runs that reached no storage, if their failed jobs left them here.
		foreach ( isset( $GLOBALS['sf_failed_names'] ) ? array_filter( (array) $GLOBALS['sf_failed_names'] ) : array() as $left ) {
			if ( is_file( Storage::backups_dir() . '/' . $left ) ) {
				unlink( Storage::backups_dir() . '/' . $left ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				echo 'Clean-up: removed ' . $left . "\n";
			}
		}
		delete_option( Schedule::OPTION );
		delete_option( Schedule::STATE );
	}
}

exit( Sf_Test::finish( 'test-schedule-copies' ) );
