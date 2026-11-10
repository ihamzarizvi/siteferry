<?php
/**
 * Scheduled backups protected with a password, sent to a fake S3: the copy on the storage and
 * the one kept here are encrypted (SFENC); the advice to protect copies shows only without a
 * password; "Test my password" says yes or no and stops after five wrong tries.
 * Started by tests\wp-copies-check.ps1 -Test test-encrypted-schedule.php, which runs the fake servers.
 *
 * Needs a site with no schedule and no S3 storage set up; everything it makes is removed at the end.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Archive\Crypto;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Schedule\Password_Check;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Schedule\Password_Check' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/class-fake-s3.php';

$sf_s3_dir = (string) getenv( 'SITEFERRY_FAKE_S3_DIR' );
if ( ! is_file( $sf_s3_dir . '/config.json' ) ) {
	echo "Start the fake S3 server first (tests\\wp-copies-check.ps1 does that).\n";
	exit( 1 );
}
if ( false !== get_option( Schedule::OPTION ) || false !== get_option( Schedule::STATE ) || false !== get_option( Schedule::PASSWORD ) ) {
	echo "A backup schedule is already on this site; this test would replace it. Remove it first.\n";
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

echo 'Encrypted scheduled backups on ' . home_url() . "\n";

$sf_config   = json_decode( (string) file_get_contents( $sf_s3_dir . '/config.json' ), true );
$sf_s3       = new Fake_S3( $sf_s3_dir . '/data', $sf_config );
$sf_password = 'Test ' . wp_generate_password( 20, false, false );
$sf_user     = 1;
$sf_meta     = array(
	Password_Check::CHECKED_META   => get_user_meta( $sf_user, Password_Check::CHECKED_META, true ),
	Password_Check::DISMISSED_META => get_user_meta( $sf_user, Password_Check::DISMISSED_META, true ),
);
wp_set_current_user( $sf_user );
add_filter( 'pre_wp_mail', '__return_true' );

try {
	Destinations::save(
		's3',
		array(
			'preset'     => 'custom',
			'endpoint'   => (string) getenv( 'SITEFERRY_FAKE_S3_URL' ),
			'region'     => $sf_config['region'],
			'bucket'     => 'sftest',
			'folder'     => 'wp-encrypted',
			'access_key' => $sf_config['access_key'],
			'secret_key' => $sf_config['secret_key'],
			'path_style' => true,
		)
	);
	$sf_base = array(
		'enabled'         => true,
		'exclude_media'   => true,
		'exclude_plugins' => true,
		'exclude_themes'  => true,
		'keep_local'      => true,
		'notify'          => 'never',
		'send_to'         => array( 's3' ),
	);

	Sf_Test::run(
		'without a password, scheduled copies sent to a storage get the advice; it can be dismissed',
		static function () use ( $sf_base, $sf_user ) {
			Schedule::save( $sf_base );
			Sf_Test::same( true, Schedule::overview()['password']['advise'] );
			Password_Check::dismiss_advice( $sf_user );
			Sf_Test::same( false, Schedule::overview()['password']['advise'], 'dismissed:' );
			delete_user_meta( $sf_user, Password_Check::DISMISSED_META );
		}
	);

	Sf_Test::run(
		'changes-only backups cannot have a password yet: a clear refusal',
		static function () use ( $sf_base, $sf_password ) {
			try {
				Schedule::save( array( 'encrypt' => true, 'full_every' => 3 ) + $sf_base, $sf_password );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( false !== strpos( $e->getMessage(), 'Changes-only backups cannot be protected' ), $e->getMessage() );
				return;
			}
			throw new RuntimeException( 'accepted changes-only with a password' );
		}
	);

	Sf_Test::run(
		'with a password: the copy here and the one on the storage are encrypted (SFENC); no advice',
		static function () use ( $sf_base, $sf_password, $sf_s3 ) {
			Schedule::save( array( 'encrypt' => true ) + $sf_base, $sf_password );
			Sf_Test::same( false, Schedule::overview()['password']['advise'] );
			$job = Schedule::start_job();
			do {
				Runner::run_slice( $job, Slice_Budget::unlimited() );
			} while ( ! $job->finished() && Job::STATUS_WAITING !== $job->status() );
			Schedule::after_slice( $job );
			Sf_Test::same( Job::STATUS_DONE, $job->status(), 'scheduled backup: ' . (string) $job->error() );
			$name  = (string) $job->result()['name'];
			$local = Storage::backups_dir() . '/' . $name;
			Sf_Test::ok( is_file( $local ) && Crypto::is_encrypted( $local ), 'the copy here is encrypted: ' . $name );
			$blob = $sf_s3->blob( 'wp-encrypted/' . $name );
			Sf_Test::ok( is_string( $blob ) && is_file( $blob ), 'the copy is on the storage' );
			Sf_Test::same( Crypto::MAGIC, (string) file_get_contents( $blob, false, null, 0, strlen( Crypto::MAGIC ) ), 'the stored copy starts with SFENC:' );
		}
	);

	Sf_Test::run(
		'test my password: yes for the right one, no for a wrong one, and a pause after five wrong tries',
		static function () use ( $sf_password, $sf_user ) {
			delete_transient( 'siteferry_pwtest_' . $sf_user );
			Sf_Test::same( true, Password_Check::test( $sf_user, $sf_password ) );
			Sf_Test::ok( time() - (int) get_user_meta( $sf_user, Password_Check::CHECKED_META, true ) < 60, 'a right answer resets the monthly reminder' );
			for ( $i = 0; $i < Password_Check::TRIES; $i++ ) {
				Sf_Test::same( false, Password_Check::test( $sf_user, $sf_password . 'x' ), 'wrong try ' . ( $i + 1 ) . ':' );
			}
			try {
				Password_Check::test( $sf_user, $sf_password );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( false !== strpos( $e->getMessage(), 'Too many tries' ), $e->getMessage() );
				delete_transient( 'siteferry_pwtest_' . $sf_user );
				return;
			}
			throw new RuntimeException( 'a sixth try was allowed' );
		}
	);

	Sf_Test::run(
		'the REST route answers the same, and is for settings only',
		static function () use ( $sf_password, $sf_user ) {
			$ask = static function ( string $password ): array {
				$request = new WP_REST_Request( 'POST', '/siteferry/v1/schedule/password-test' );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( wp_json_encode( array( 'password' => $password ) ) );
				$response = rest_do_request( $request );
				return array( $response->get_status(), $response->get_data() );
			};
			Sf_Test::same( array( 200, array( 'ok' => true ) ), $ask( $sf_password ) );
			Sf_Test::same( array( 200, array( 'ok' => false ) ), $ask( 'nope' ) );
			Sf_Test::ok( false === strpos( wp_json_encode( Schedule::overview() ), $sf_password ), 'the overview never holds the password' );
			wp_set_current_user( 0 );
			Sf_Test::same( 401, $ask( $sf_password )[0], 'signed out:' );
			wp_set_current_user( $sf_user );
			delete_transient( 'siteferry_pwtest_' . $sf_user );
		}
	);
} finally {
	foreach ( Schedule::state()['runs'] as $run ) {
		$problem = Schedule::delete_run( $run );
		if ( null !== $problem ) {
			echo 'Clean-up: ' . $problem . "\n";
		}
	}
	Sf_Test::run(
		'clean-up: nothing left on the fake S3',
		static function () use ( $sf_s3 ) {
			$left = array_filter(
				$sf_s3->keys(),
				static function ( string $key ): bool {
					return 0 === strpos( $key, 'wp-encrypted/' );
				}
			);
			Sf_Test::same( array(), array_values( $left ) );
		}
	);
	Destinations::remove( 's3' );
	Schedule::unschedule_all();
	delete_option( Schedule::OPTION );
	delete_option( Schedule::STATE );
	delete_option( Schedule::PASSWORD );
	foreach ( $sf_meta as $key => $value ) {
		if ( '' === $value ) {
			delete_user_meta( $sf_user, $key );
		} else {
			update_user_meta( $sf_user, $key, $value );
		}
	}
}

exit( Sf_Test::finish( 'test-encrypted-schedule' ) );
