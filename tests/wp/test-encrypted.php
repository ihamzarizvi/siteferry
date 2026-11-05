<?php
/**
 * Password-protected backups inside WordPress (no import is run). On the test site siteferry-dst:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes eval-file tests/wp/test-encrypted.php
 *
 * Makes a small protected backup (the database only) and checks that nothing of it is readable
 * without the password; that the list, a check, "Look inside" and a restore check ask for the
 * password, refuse a wrong one and work with the right one (the restore check is cancelled before
 * anything changes); that jobs keep no key once they end; and that the schedule keeps its
 * password encrypted and never sends it back. Puts the schedule settings back and deletes the
 * backup at the end.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Archive\Crypto;
use SiteFerry\Export\Export;
use SiteFerry\Jobs\Job;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Archive_Keys;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Archive\Crypto' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

echo "Encrypted backup tests\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
);
$admin  = (int) $admins[0];

require dirname( __DIR__ ) . '/lib/wp-rest.php';

$password = 'sf-test-' . bin2hex( random_bytes( 6 ) );
$backup   = '';

/**
 * Options that leave out every file: the database only.
 */
function sf_db_only(): array {
	$exclude = array();
	foreach ( array_diff( (array) scandir( WP_CONTENT_DIR ), array( '.', '..', 'plugins', 'themes', 'uploads' ) ) as $name ) {
		if ( 0 !== strpos( $name, 'siteferry-' ) ) {
			$exclude[] = $name;
		}
	}
	return array(
		'title'           => 'SiteFerry test: encrypted',
		'exclude_media'   => true,
		'exclude_plugins' => true,
		'exclude_themes'  => true,
		'exclude_paths'   => $exclude,
	);
}

Sf_Test::run(
	'a protected backup: named .enc, nothing readable in it, its key wiped from the job afterwards',
	static function () use ( $password, &$backup ) {
		list( $job, $token ) = Export::start( sf_db_only() + array( 'password' => $password ) );
		Sf_Test::same( false, strpos( (string) file_get_contents( $job->path( 'job.json' ) ), $password ), 'the password is not in the job:' );
		Sf_Test::ok( null !== Archive_Keys::key( $job ), 'the job holds the key while it runs' );
		$summary = sf_rest_drive( $job->id(), $token );
		Sf_Test::same( 'done', $summary['status'], 'export: ' . wp_json_encode( $summary ) );
		$backup = (string) $summary['result']['name'];
		Sf_Test::ok( (bool) preg_match( '/\.tar\.enc$/', $backup ), 'name: ' . $backup );
		$file = Storage::backups_dir() . '/' . $backup;
		Sf_Test::same( true, Crypto::is_encrypted( $file ) );
		$raw = (string) file_get_contents( $file );
		foreach ( array( 'CREATE TABLE', 'siteferry-backup', home_url(), 'ustar' ) as $plain ) {
			Sf_Test::same( false, strpos( $raw, $plain ), '"' . $plain . '" in the file:' );
		}
		Sf_Test::same( array(), Job::load( Storage::jobs_dir(), $job->id() )->state( Archive_Keys::STATE ), 'key wiped after the job:' );
	}
);

if ( '' === $backup ) {
	exit( Sf_Test::finish( 'wp/test-encrypted' ) );
}

Sf_Test::run(
	'what cannot be made: a .wpress with a password, a short password',
	static function () {
		foreach ( array(
			array( array( 'format' => 'wpress', 'password' => 'long enough pw' ), 'cannot be protected with a password' ),
			array( array( 'password' => 'short' ), 'at least 8 characters' ),
		) as list( $options, $says ) ) {
			try {
				Export::start( sf_db_only() + $options );
				Sf_Test::ok( false, 'refused: ' . $says );
			} catch ( \SiteFerry\Jobs\Job_Exception $e ) {
				Sf_Test::ok( false !== strpos( $e->getMessage(), $says ), $e->getMessage() );
			}
		}
	}
);

Sf_Test::run(
	'the list shows it as protected, not as broken',
	static function () use ( $backup, $admin ) {
		list( $status, $list ) = sf_rest( 'GET', '/backups', $admin );
		Sf_Test::same( 200, $status );
		$entry = array_values( array_filter( $list, static function ( $b ) use ( $backup ) {
			return $b['name'] === $backup;
		} ) )[0];
		Sf_Test::same( true, $entry['encrypted'] );
		Sf_Test::same( null, $entry['error'] );
		Sf_Test::same( 'SiteFerry test: encrypted', $entry['title'] );
	}
);

Sf_Test::run(
	'checking: no password, a wrong one, then the right one',
	static function () use ( $backup, $admin, $password ) {
		list( $status, $data ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $backup ) );
		Sf_Test::same( array( 400, 'siteferry_locked' ), array( $status, $data['code'] ), 'no password:' );
		list( $status, $data ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $backup, 'password' => $password . 'x' ) );
		Sf_Test::same( array( 400, 'siteferry_locked', 'The password is wrong.' ), array( $status, $data['code'], $data['message'] ), 'wrong password:' );
		list( $status, $data ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $backup, 'password' => $password ) );
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $data ) );
		$job = sf_rest_drive( $data['job']['id'], $data['token'], 0 );
		Sf_Test::same( 'done', $job['status'], 'check: ' . (string) $job['error'] );
		Sf_Test::ok( 0 === strpos( $job['result']['checked']['summary'], 'All ' ), $job['result']['checked']['summary'] );
		Sf_Test::same( array(), Job::load( Storage::jobs_dir(), $data['job']['id'] )->state( Archive_Keys::STATE ), 'key wiped after the check:' );
	}
);

Sf_Test::run(
	'look inside: unlocked for this backup only, with a token instead of the password',
	static function () use ( $backup, $admin, $password ) {
		list( $status, $data ) = sf_rest( 'GET', '/backups/' . $backup . '/contents', $admin );
		Sf_Test::same( array( 400, 'siteferry_locked' ), array( $status, $data['code'] ), 'without unlocking:' );
		list( $status, $data ) = sf_rest( 'POST', '/backups/' . $backup . '/unlock', $admin, array(), array( 'password' => 'wrong password' ) );
		Sf_Test::same( array( 400, 'siteferry_locked' ), array( $status, $data['code'] ), 'wrong password:' );
		list( $status, $data ) = sf_rest( 'POST', '/backups/' . $backup . '/unlock', $admin, array(), array( 'password' => $password ) );
		Sf_Test::same( 200, $status, 'unlock: ' . wp_json_encode( $data ) );
		$token = (string) $data['unlock'];
		Sf_Test::ok( (bool) preg_match( '/^[a-f0-9]{32}$/', $token ), 'token' );
		Sf_Test::same( false, strpos( (string) get_transient( 'siteferry_unlock_' . $token ), base64_encode( '' ) . $password ), 'the password is not kept' );

		$calls = 0;
		do {
			$request = new WP_REST_Request( 'GET', '/siteferry/v1/backups/' . $backup . '/contents' );
			$request->set_param( 'unlock', $token );
			wp_set_current_user( $admin );
			$response = rest_do_request( $request );
			$data     = $response->get_data();
			++$calls;
		} while ( 200 === $response->get_status() && ! $data['complete'] && $calls < 50 );
		Sf_Test::same( 200, $response->get_status(), 'contents: ' . wp_json_encode( $data ) );
		Sf_Test::same( array( 'database.sql', 'siteferry.json', 'siteferry.sha256' ), array_values( array_intersect( array( 'database.sql', 'siteferry.json', 'siteferry.sha256' ), array_column( $data['entries'], 'path' ) ) ) );
		Sf_Test::ok( is_array( $data['tables'] ) && count( $data['tables'] ) > 5, 'tables listed' );

		$index = (array) glob( Storage::root() . '/index/idx-' . substr( sha1( $backup ), 0, 16 ) . '-*.json' );
		Sf_Test::same( 1, count( $index ) );
		Sf_Test::same( false, strpos( (string) file_get_contents( (string) $index[0] ), 'database.sql' ), 'the kept file list is encrypted too:' );

		$other = new WP_REST_Request( 'GET', '/siteferry/v1/backups/x-2026-01-01-0123456789ab.tar.enc/contents' );
		$other->set_param( 'unlock', $token );
		Sf_Test::ok( 200 !== rest_do_request( $other )->get_status(), 'the token is for this backup only' );
	}
);

Sf_Test::run(
	'restoring: the password is asked first; the right one passes the check (cancelled before anything changes)',
	static function () use ( $backup, $admin, $password ) {
		list( $status, $data ) = sf_rest( 'POST', '/imports', $admin, array(), array( 'backup' => $backup ) );
		Sf_Test::same( array( 400, 'siteferry_locked' ), array( $status, $data['code'] ), 'no password:' );
		Sf_Test::same( null, Job::active( Storage::jobs_dir() ), 'no job was started' );
		list( $status, $data ) = sf_rest( 'POST', '/imports', $admin, array(), array( 'backup' => $backup, 'password' => 'not it at all' ) );
		Sf_Test::same( array( 400, 'The password is wrong.' ), array( $status, $data['message'] ), 'wrong password:' );

		list( $status, $data ) = sf_rest( 'POST', '/imports', $admin, array(), array( 'backup' => $backup, 'password' => $password ) );
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $data ) );
		$job = sf_rest_drive( $data['job']['id'], $data['token'] );
		Sf_Test::same( 'waiting', $job['status'], 'checked, waiting for confirmation: ' . (string) $job['error'] );
		Sf_Test::same( home_url(), $job['result']['inspection']['from'], 'the manifest was read:' );
		list( $status ) = sf_rest( 'POST', '/jobs/' . $data['job']['id'] . '/cancel', 0, array( 'X-SiteFerry-Job' => $data['token'] ) );
		Sf_Test::same( 200, $status, 'cancelled:' );
		Sf_Test::same( array(), Job::load( Storage::jobs_dir(), $data['job']['id'] )->state( Archive_Keys::STATE ), 'key wiped after cancelling:' );
	}
);

Sf_Test::run(
	'the schedule keeps its password encrypted and never sends it back',
	static function () use ( $admin ) {
		$settings_before = get_option( Schedule::OPTION, null );
		$password_before = get_option( Schedule::PASSWORD, null );
		try {
			delete_option( Schedule::PASSWORD );
			list( $status, $data ) = sf_rest( 'POST', '/schedule', $admin, array(), array( 'encrypt' => true ) );
			Sf_Test::same( 400, $status, 'encrypting without a password: ' . wp_json_encode( $data ) );
			list( $status, $data ) = sf_rest( 'POST', '/schedule', $admin, array(), array( 'encrypt' => true, 'password' => 'short' ) );
			Sf_Test::same( 400, $status, 'a short password:' );
			list( $status, $data ) = sf_rest( 'POST', '/schedule', $admin, array(), array( 'encrypt' => true, 'password' => 'schedule password 1' ) );
			Sf_Test::same( 200, $status, 'saved: ' . wp_json_encode( $data ) );
			Sf_Test::same( array( true, true ), array( $data['settings']['encrypt'], $data['has_password'] ) );
			Sf_Test::same( false, strpos( (string) wp_json_encode( $data ), 'schedule password 1' ), 'not sent back:' );
			Sf_Test::same( false, strpos( (string) get_option( Schedule::PASSWORD ), 'schedule password 1' ), 'stored encrypted:' );
			Sf_Test::same( 'schedule password 1', Schedule::password() );
			Sf_Test::same( 'schedule password 1', Schedule::with_password( array() )['password'], 'automatic backups use it:' );
			Sf_Test::same( false, isset( Schedule::with_password( array( 'format' => 'wpress' ) )['password'] ), 'not for .wpress:' );
			list( $status, $data ) = sf_rest( 'POST', '/schedule', $admin, array(), array( 'encrypt' => true ) );
			Sf_Test::same( 200, $status, 'saved again without the password: it stays' );
			Sf_Test::same( 'schedule password 1', Schedule::password() );
		} finally {
			null === $settings_before ? delete_option( Schedule::OPTION ) : update_option( Schedule::OPTION, $settings_before, false );
			null === $password_before ? delete_option( Schedule::PASSWORD ) : update_option( Schedule::PASSWORD, $password_before, false );
			Schedule::reschedule( Schedule::settings() );
		}
	}
);

Sf_Test::run(
	'deleting the backup',
	static function () use ( $backup, $admin ) {
		list( $status ) = sf_rest( 'DELETE', '/backups/' . $backup, $admin );
		Sf_Test::same( 200, $status );
		sf_rest( 'POST', '/labels/' . $backup, $admin, array(), array() );
		Sf_Test::same( array(), (array) glob( Storage::root() . '/index/idx-' . substr( sha1( $backup ), 0, 16 ) . '-*.json' ), 'its kept file list removed:' );
	}
);

exit( Sf_Test::finish( 'wp/test-encrypted' ) );
