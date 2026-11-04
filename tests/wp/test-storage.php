<?php
/**
 * Remote storage inside WordPress, against a fake server: S3 (tests/tools/fake-s3-server.php,
 * through the WordPress HTTP API) or FTPS (tests/tools/fake-ftp-server.php, through PHP's ftp
 * extension). Run it with the wrapper, which starts and stops the servers:
 *
 *     powershell -File tests\wp-storage-check.ps1 -Site siteferry-src [-Driver s3|ftp|all]
 *
 * The environment variable SITEFERRY_TEST_STORAGE says which one (s3 or ftp).
 *
 * Covers: settings saved encrypted and never sent back, the connection test, an export sent to
 * storage, fetching it back, sending in slices, cancelling (the unfinished upload is removed),
 * retries of temporary failures, the WP-CLI commands, remote delete and removing the settings.
 * Refuses to run if the site already has settings for that storage (they would be replaced).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Backups;
use SiteFerry\Export\Manifest;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Storage\Destinations' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/fixtures.php';
require dirname( __DIR__ ) . '/lib/wp-rest.php';
require dirname( __DIR__ ) . '/lib/class-fake-s3.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

$sf_kind = (string) getenv( 'SITEFERRY_TEST_STORAGE' );

// What differs between the storages: settings, messages, and how to look at the fake's files.
if ( 's3' === $sf_kind ) {
	$sf_dir = (string) getenv( 'SITEFERRY_FAKE_S3_DIR' );
	if ( ! is_file( $sf_dir . '/config.json' ) ) {
		echo "Start the fake S3 server first (tests\\wp-storage-check.ps1 does that).\n";
		exit( 1 );
	}
	$sf_config = json_decode( (string) file_get_contents( $sf_dir . '/config.json' ), true );
	$sf_fake   = new Fake_S3( $sf_dir . '/data', $sf_config );
	$sf_b      = array(
		'id'          => 's3',
		'where'       => (string) getenv( 'SITEFERRY_FAKE_S3_URL' ),
		'input'       => array(
			'preset'     => 'custom',
			'endpoint'   => (string) getenv( 'SITEFERRY_FAKE_S3_URL' ),
			'region'     => $sf_config['region'],
			'bucket'     => 'sftest',
			'folder'     => 'wp-test',
			'access_key' => $sf_config['access_key'],
			'secret_key' => $sf_config['secret_key'],
			'path_style' => true,
		),
		'secret'      => 'secret_key',
		'invalid'     => array( 'bucket' => 'a' ),
		'kept'        => array( 'bucket', 'sftest' ),
		'test_lines'  => 4,
		'wrong_says'  => array( 'SignatureDoesNotMatch', 'Check the secret access key' ),
		'hash'        => static function ( string $name ) use ( $sf_fake ) {
			return $sf_fake->hash( 'wp-test/' . $name );
		},
		'names'       => static function () use ( $sf_fake ): array {
			return $sf_fake->keys();
		},
		'pending'     => static function () use ( $sf_fake ): int {
			return $sf_fake->pending_uploads();
		},
		'fault'       => static function () use ( $sf_dir ) {
			file_put_contents( $sf_dir . '/faults.json', '[503,503]' );
		},
		'faults_used' => static function () use ( $sf_dir ): bool {
			return '[]' === (string) file_get_contents( $sf_dir . '/faults.json' );
		},
	);
} elseif ( 'ftp' === $sf_kind ) {
	$sf_dir = (string) getenv( 'SITEFERRY_FAKE_FTP_DIR' );
	if ( ! is_file( $sf_dir . '/config.json' ) ) {
		echo "Start the fake FTP server first (tests\\wp-storage-check.ps1 does that).\n";
		exit( 1 );
	}
	$sf_config = json_decode( (string) file_get_contents( $sf_dir . '/config.json' ), true );
	$sf_folder = $sf_dir . '/root/wp-test';
	$sf_b      = array(
		'id'          => 'ftp',
		'where'       => 'ftp://127.0.0.1:' . $sf_config['port'],
		'input'       => array(
			'host'       => '127.0.0.1',
			'port'       => (string) $sf_config['port'],
			'encryption' => 'ftps',
			'username'   => $sf_config['user'],
			'password'   => $sf_config['pass'],
			'folder'     => 'wp-test',
			'passive'    => true,
		),
		'secret'      => 'password',
		'invalid'     => array( 'port' => '70000' ),
		'kept'        => array( 'port', (int) $sf_config['port'] ),
		'test_lines'  => 6,
		'wrong_says'  => array( 'refused the login', 'Check the user name and password' ),
		'hash'        => static function ( string $name ) use ( $sf_folder ) {
			return is_file( $sf_folder . '/' . $name ) ? hash_file( 'sha256', $sf_folder . '/' . $name ) : null;
		},
		'names'       => static function () use ( $sf_folder ): array {
			return is_dir( $sf_folder ) ? array_values( array_diff( scandir( $sf_folder ), array( '.', '..' ) ) ) : array();
		},
		'pending'     => static function () use ( $sf_folder ): int {
			return count( (array) glob( $sf_folder . '/*.part' ) );
		},
		'fault'       => static function () use ( $sf_dir ) {
			file_put_contents( $sf_dir . '/faults.json', '[{"cmd":"MLSD","action":"drop"},{"cmd":"MLSD","action":"drop"}]' );
		},
		'faults_used' => static function () use ( $sf_dir ): bool {
			return array() === json_decode( (string) file_get_contents( $sf_dir . '/faults.json' ), true );
		},
	);
} elseif ( 'gdrive' === $sf_kind ) {
	require dirname( __DIR__ ) . '/lib/class-fake-google.php';
	$sf_dir = (string) getenv( 'SITEFERRY_FAKE_GOOGLE_DIR' );
	if ( ! is_file( $sf_dir . '/config.json' ) ) {
		echo "Start the fake Google server first (tests\\wp-storage-check.ps1 does that).\n";
		exit( 1 );
	}
	$sf_config = json_decode( (string) file_get_contents( $sf_dir . '/config.json' ), true );
	$sf_fake   = new Fake_Google( $sf_dir . '/data', $sf_config );
	$sf_base   = $sf_config['base'];
	add_filter(
		'siteferry_gdrive_endpoints',
		static function () use ( $sf_base ): array {
			return array(
				'auth'   => $sf_base . '/o/oauth2/v2/auth',
				'token'  => $sf_base . '/token',
				'revoke' => $sf_base . '/revoke',
				'api'    => $sf_base . '/drive/v3',
				'upload' => $sf_base . '/upload/drive/v3',
			);
		}
	);
	$sf_ginput = array(
		'client_id'     => $sf_config['client_id'],
		'client_secret' => $sf_config['client_secret'],
		'folder'        => 'wp-test',
	);
	$sf_names  = static function () use ( $sf_fake ): array {
		return array_values( array_column( $sf_fake->files(), 'name' ) );
	};
	$sf_b     = array(
		'id'          => 'gdrive',
		'where'       => $sf_base,
		'input'       => $sf_ginput,
		'secret'      => 'client_secret',
		'invalid'     => array( 'client_id' => 'not-a-client-id' ),
		'kept'        => array( 'folder', 'wp-test' ),
		'test_lines'  => 6,
		'wrong_says'  => array( 'invalid_client', 'Check them on the Storage tab' ),
		'hash'        => static function ( string $name ) use ( $sf_fake ) {
			return $sf_fake->hash( $name );
		},
		'names'       => $sf_names,
		'pending'     => static function () use ( $sf_fake ): int {
			return $sf_fake->open_sessions();
		},
		'fault'       => static function () use ( $sf_dir ) {
			file_put_contents( $sf_dir . '/data/faults.json', '[503,503]' );
		},
		'faults_used' => static function () use ( $sf_dir ): bool {
			return '[]' === (string) file_get_contents( $sf_dir . '/data/faults.json' );
		},
		// The sign-in: authorize, the consent page's "Allow", then connect with the code and state.
		'after_save'  => static function ( int $admin ) use ( $sf_fake, $sf_ginput ) {
			list( $status, $start ) = sf_rest( 'POST', '/storage/gdrive/authorize', $admin );
			Sf_Test::same( 200, $status, 'authorize: ' . wp_json_encode( $start ) );
			Sf_Test::ok( false !== strpos( $start['redirect_uri'], 'admin.php?page=siteferry&oauth=gdrive' ), $start['redirect_uri'] );
			list( $status ) = sf_rest( 'GET', '/storage', $admin );
			Sf_Test::ok( ! Destinations::ready( 'gdrive' ), 'not ready before connecting' );

			$consent = wp_remote_get( $start['url'] . '&decision=allow', array( 'redirection' => 0 ) );
			$back    = array();
			parse_str( (string) wp_parse_url( (string) wp_remote_retrieve_header( $consent, 'location' ), PHP_URL_QUERY ), $back );
			Sf_Test::ok( isset( $back['code'], $back['state'] ), 'the consent page sent a code and the state back' );

			list( $status, $wrong ) = sf_rest( 'POST', '/storage/gdrive/connect', $admin, array(), array( 'code' => $back['code'], 'state' => str_repeat( 'a', 48 ) ) );
			Sf_Test::same( 400, $status, 'a wrong state is refused: ' . wp_json_encode( $wrong ) );
			list( $status ) = sf_rest( 'POST', '/storage/gdrive/connect', $admin, array(), array( 'code' => $back['code'], 'state' => $back['state'] ) );
			Sf_Test::same( 400, $status, 'the state is used up by any answer, even a wrong one:' );

			// Start again; this time the answer is right.
			list( , $start ) = sf_rest( 'POST', '/storage/gdrive/authorize', $admin );
			$consent         = wp_remote_get( $start['url'] . '&decision=allow', array( 'redirection' => 0 ) );
			parse_str( (string) wp_parse_url( (string) wp_remote_retrieve_header( $consent, 'location' ), PHP_URL_QUERY ), $back );
			list( $status, $entry ) = sf_rest( 'POST', '/storage/gdrive/connect', 0, array(), array( 'code' => $back['code'], 'state' => $back['state'] ) );
			Sf_Test::ok( in_array( $status, array( 401, 403 ), true ), 'connect needs the administrator: ' . $status );
			list( , $start ) = sf_rest( 'POST', '/storage/gdrive/authorize', $admin );
			$consent         = wp_remote_get( $start['url'] . '&decision=allow', array( 'redirection' => 0 ) );
			parse_str( (string) wp_parse_url( (string) wp_remote_retrieve_header( $consent, 'location' ), PHP_URL_QUERY ), $back );
			list( $status, $entry ) = sf_rest( 'POST', '/storage/gdrive/connect', $admin, array(), array( 'code' => $back['code'], 'state' => $back['state'] ) );
			Sf_Test::same( 200, $status, 'connect: ' . wp_json_encode( $entry ) );
			Sf_Test::same( true, $entry['oauth']['connected'] );
			Sf_Test::same( 'tester@example.com', $entry['oauth']['account'] );
			Sf_Test::ok( Destinations::ready( 'gdrive' ), 'ready after connecting' );

			global $wpdb;
			$raw     = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Destinations::OPTION ) );
			list( , $overview ) = sf_rest( 'GET', '/storage', $admin );
			foreach ( $sf_fake->refresh_tokens() as $token ) {
				Sf_Test::ok( false === strpos( $raw, $token ), 'the refresh token is stored encrypted' );
				Sf_Test::ok( false === strpos( wp_json_encode( $overview ), $token ), 'the refresh token is not sent to the browser' );
			}

			// Saving the settings again (same client ID) keeps the connection.
			list( $status, $entry ) = sf_rest( 'POST', '/storage/gdrive', $admin, array(), array( 'client_secret' => '' ) + $sf_ginput );
			Sf_Test::same( true, $entry['oauth']['connected'], 'still connected after saving again:' );
		},
	);
} else {
	echo "Set SITEFERRY_TEST_STORAGE to s3, ftp or gdrive (tests\\wp-storage-check.ps1 does that).\n";
	exit( 1 );
}

$sf_id = $sf_b['id'];
if ( Destinations::configured( $sf_id ) ) {
	echo "Storage \"$sf_id\" is already set up on this site; this test would replace those settings. Remove them first.\n";
	exit( 1 );
}

echo "Storage tests: $sf_id (fake server at {$sf_b['where']})\n";

$sf_admin = (int) get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
)[0];
$sf_local = array();

/**
 * Starts a job through the REST API and returns [id, token].
 *
 * @param int    $admin Administrator id.
 * @param string $route Route.
 * @param array  $body  JSON body.
 */
function sf_start( int $admin, string $route, array $body ): array {
	list( $status, $data ) = sf_rest( 'POST', $route, $admin, array(), $body );
	Sf_Test::same( 200, $status, "start $route: " . wp_json_encode( $data ) );
	return array( $data['job']['id'], $data['token'] );
}

/**
 * SHA-256 of a local backup.
 *
 * @param string $name Backup name.
 */
function sf_local_hash( string $name ): string {
	return hash_file( 'sha256', Backups::path( Storage::backups_dir(), $name ) );
}

/**
 * The overview entry of a destination.
 *
 * @param string $id Destination id.
 */
function sf_overview( string $id ): array {
	foreach ( Destinations::overview() as $entry ) {
		if ( $entry['id'] === $id ) {
			return $entry;
		}
	}
	return array();
}

Sf_Test::run(
	'settings are saved encrypted, not autoloaded, and never sent back',
	static function () use ( $sf_b, $sf_id, $sf_admin ) {
		$secret = $sf_b['input'][ $sf_b['secret'] ];
		list( $status ) = sf_rest( 'POST', "/storage/$sf_id", 0, array(), $sf_b['input'] );
		Sf_Test::ok( in_array( $status, array( 401, 403 ), true ), 'anonymous save refused: ' . $status );
		Sf_Test::ok( ! Destinations::configured( $sf_id ), 'nothing saved by the anonymous request' );

		list( $status, $data ) = sf_rest( 'POST', "/storage/$sf_id", $sf_admin, array(), $sf_b['input'] );
		Sf_Test::same( 200, $status, 'save: ' . wp_json_encode( $data ) );
		Sf_Test::same( true, $data['configured'] );
		Sf_Test::same( true, $data['secrets_set'][ $sf_b['secret'] ] );

		global $wpdb;
		$raw = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", Destinations::OPTION ), ARRAY_A );
		Sf_Test::ok( false === strpos( $raw['option_value'], $secret ), 'the secret is not in the database in plain text' );
		Sf_Test::ok( false !== strpos( $raw['option_value'], 'sf1s:' ) || false !== strpos( $raw['option_value'], 'sf1o:' ), 'the secret is stored encrypted' );
		Sf_Test::ok( in_array( $raw['autoload'], array( 'no', 'off' ), true ), 'not autoloaded: ' . $raw['autoload'] );

		list( $status, $list ) = sf_rest( 'GET', '/storage', $sf_admin );
		Sf_Test::same( 200, $status );
		Sf_Test::ok( false === strpos( wp_json_encode( $list ), $secret ), 'GET /storage does not contain the secret' );
		Sf_Test::ok( ! isset( sf_overview( $sf_id )['settings'][ $sf_b['secret'] ] ), 'no secret in the settings sent to the browser' );

		// Saving again without the secret keeps it; invalid input changes nothing.
		list( $status ) = sf_rest( 'POST', "/storage/$sf_id", $sf_admin, array(), array( $sf_b['secret'] => '' ) + $sf_b['input'] );
		Sf_Test::same( 200, $status, 'save without the secret:' );
		list( $status, $error ) = sf_rest( 'POST', "/storage/$sf_id", $sf_admin, array(), $sf_b['invalid'] + $sf_b['input'] );
		Sf_Test::same( 400, $status, 'invalid input: ' . wp_json_encode( $error ) );
		Sf_Test::same( $sf_b['kept'][1], sf_overview( $sf_id )['settings'][ $sf_b['kept'][0] ], 'settings unchanged after invalid input:' );

		if ( isset( $sf_b['after_save'] ) ) {
			$sf_b['after_save']( $sf_admin );
		}
	}
);

Sf_Test::run(
	'connection test; a wrong secret is reported without revealing it',
	static function () use ( $sf_b, $sf_id, $sf_admin ) {
		list( $status, $data ) = sf_rest( 'POST', "/storage/$sf_id/test", $sf_admin );
		Sf_Test::same( 200, $status, 'test: ' . wp_json_encode( $data ) );
		Sf_Test::same( $sf_b['test_lines'], count( $data['lines'] ), implode( ' | ', $data['lines'] ) );
		Sf_Test::same( array(), $sf_b['names'](), 'test file removed:' );

		list( $status ) = sf_rest( 'POST', "/storage/$sf_id", $sf_admin, array(), array( $sf_b['secret'] => 'Wrong-Secret-123' ) + $sf_b['input'] );
		Sf_Test::same( 200, $status );
		list( $status, $error ) = sf_rest( 'POST', "/storage/$sf_id/test", $sf_admin );
		Sf_Test::same( 400, $status, 'wrong secret: ' . wp_json_encode( $error ) );
		foreach ( $sf_b['wrong_says'] as $text ) {
			Sf_Test::ok( false !== strpos( $error['message'], $text ), $error['message'] );
		}
		Sf_Test::ok( false === strpos( $error['message'], 'Wrong-Secret-123' ), 'the secret is not in the message' );

		list( $status ) = sf_rest( 'POST', "/storage/$sf_id", $sf_admin, array(), $sf_b['input'] );
		Sf_Test::same( 200, $status, 'correct secret saved again:' );
	}
);

Sf_Test::run(
	'export with "send to": the stored file is byte-identical; fetching it back works',
	static function () use ( $sf_b, $sf_id, $sf_admin, &$sf_local ) {
		list( $id, $token ) = sf_start(
			$sf_admin,
			'/exports',
			array(
				'exclude_media' => true,
				'send_to'       => $sf_id,
			)
		);
		$job = sf_rest_drive( $id, $token );
		if ( isset( $job['result']['name'] ) ) {
			$sf_local[] = $job['result']['name']; // Removed at the end even if a check below fails.
		}
		Sf_Test::same( 'done', $job['status'], 'export: ' . (string) $job['error'] );
		$name = $job['result']['name'];
		Sf_Test::same( $name, $job['result']['sent']['name'] );
		Sf_Test::same( 5, $job['step']['count'], 'the export has a fifth step (send):' );
		Sf_Test::same( sf_local_hash( $name ), $sf_b['hash']( $name ), 'stored bytes:' );

		list( $status, $remote ) = sf_rest( 'GET', "/storage/$sf_id/backups", $sf_admin );
		Sf_Test::same( 200, $status );
		Sf_Test::same( array( $name ), array_column( $remote['backups'], 'name' ) );

		list( $id, $token ) = sf_start( $sf_admin, "/storage/$sf_id/fetch", array( 'name' => $name ) );
		$job                = sf_rest_drive( $id, $token );
		Sf_Test::same( 'done', $job['status'], 'fetch: ' . (string) $job['error'] );
		$fetched    = $job['result']['fetched']['name'];
		$sf_local[] = $fetched;
		Sf_Test::ok( $fetched !== $name && preg_match( Backups::NAME_PATTERN, $fetched ), 'the copy gets its own name: ' . $fetched );
		Sf_Test::same( sf_local_hash( $name ), sf_local_hash( $fetched ), 'fetched bytes:' );

		list( $status, $missing ) = sf_rest( 'POST', "/storage/$sf_id/fetch", $sf_admin, array(), array( 'name' => 'none-2026-01-01-000000000000.tar' ) );
		Sf_Test::same( 404, $status, 'fetching a missing backup: ' . wp_json_encode( $missing ) );
	}
);

Sf_Test::run(
	'sending in slices; cancelling removes the unfinished upload',
	static function () use ( $sf_b, $sf_id, $sf_admin, &$sf_local ) {
		$name   = 'sftest-' . gmdate( 'Y-m-d' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.tar';
		$file   = Storage::backups_dir() . '/' . $name;
		$writer = Tar_Writer::create( $file );
		$writer->add_string(
			Manifest::ENTRY,
			(string) wp_json_encode(
				array(
					'format'   => Manifest::FORMAT,
					'created'  => gmdate( 'c' ),
					'site'     => array( 'home' => home_url() ),
					'versions' => array( 'wordpress' => get_bloginfo( 'version' ) ),
				)
			)
		);
		$writer->add_string( 'padding.bin', random_bytes( 12 * 1048576 ) );
		$writer->finish();
		$sf_local[] = $name;

		list( $id, $token ) = sf_start( $sf_admin, "/storage/$sf_id/send", array( 'backup' => $name ) );
		list( $status, $job ) = sf_rest( 'POST', "/jobs/$id/run", 0, array( 'X-SiteFerry-Job' => $token ), array( 'seconds' => 0 ) );
		Sf_Test::same( 200, $status );
		Sf_Test::same( 'running', $job['status'], 'one slice sends one part: ' . wp_json_encode( $job['progress'] ) );
		Sf_Test::same( 8388608, $job['progress']['done'], 'bytes sent after one slice:' );
		Sf_Test::same( 1, $sf_b['pending'](), 'unfinished upload on the storage:' );

		list( $status, $job ) = sf_rest( 'POST', "/jobs/$id/cancel", 0, array( 'X-SiteFerry-Job' => $token ) );
		Sf_Test::same( 200, $status );
		Sf_Test::same( 'failed', $job['status'] );
		Sf_Test::same( 0, $sf_b['pending'](), 'unfinished upload removed:' );
		Sf_Test::ok( null === $sf_b['hash']( $name ), 'nothing stored' );
		Sf_Test::ok( is_file( $file ), 'the local backup is kept' );

		list( $id, $token ) = sf_start( $sf_admin, "/storage/$sf_id/send", array( 'backup' => $name ) );
		$job                = sf_rest_drive( $id, $token, 0 );
		Sf_Test::same( 'done', $job['status'], 'send: ' . (string) $job['error'] );
		Sf_Test::same( hash_file( 'sha256', $file ), $sf_b['hash']( $name ), 'stored bytes:' );
	}
);

Sf_Test::run(
	'temporary failures (two in a row) are retried',
	static function () use ( $sf_b, $sf_id, $sf_admin ) {
		$sf_b['fault']();
		$started               = microtime( true );
		list( $status, $data ) = sf_rest( 'GET', "/storage/$sf_id/backups", $sf_admin );
		Sf_Test::same( 200, $status, 'listing after retries: ' . wp_json_encode( $data ) );
		Sf_Test::ok( $sf_b['faults_used'](), 'both faults used' );
		Sf_Test::ok( microtime( true ) - $started >= 4, 'waited between attempts' );
	}
);

Sf_Test::run(
	'WP-CLI: storage test, list, send, fetch and delete',
	static function () use ( $sf_b, $sf_id, &$sf_local ) {
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
		$test = $cli( "siteferry storage test $sf_id" );
		Sf_Test::same( 0, $test->return_code, 'storage test: ' . $test->stderr );
		Sf_Test::ok( false !== strpos( $test->stdout, 'Deleted the test file.' ), $test->stdout );

		$name = end( $sf_local );
		$sent = $cli( "siteferry storage send $sf_id " . $name );
		Sf_Test::same( 0, $sent->return_code, 'storage send: ' . $sent->stderr );

		$list  = $cli( "siteferry storage list $sf_id --format=json" );
		$items = json_decode( $list->stdout, true );
		Sf_Test::ok( is_array( $items ) && in_array( $name, array_column( $items, 'name' ), true ), 'listed: ' . $list->stdout );

		$fetch = $cli( "siteferry storage fetch $sf_id " . $name );
		Sf_Test::same( 0, $fetch->return_code, 'storage fetch: ' . $fetch->stderr );
		Sf_Test::ok( (bool) preg_match( '/Downloaded (\S+\.tar)/', $fetch->stdout, $match ), $fetch->stdout );
		$sf_local[] = $match[1];

		$delete = $cli( "siteferry storage delete $sf_id " . $name . ' --yes' );
		Sf_Test::same( 0, $delete->return_code, 'storage delete: ' . $delete->stderr );
		Sf_Test::ok( null === $sf_b['hash']( $name ), 'deleted on the storage' );

		$bad = $cli( 'siteferry storage test nowhere' );
		Sf_Test::ok( 0 !== $bad->return_code && false !== strpos( $bad->stderr, 'Unknown storage' ), 'unknown id: ' . $bad->stderr );
	}
);

Sf_Test::run(
	'remote delete, then removing the settings',
	static function () use ( $sf_b, $sf_id, $sf_admin ) {
		foreach ( array( '..%2Fx.tar', 'x.php' ) as $bad ) {
			list( $status ) = sf_rest( 'DELETE', "/storage/$sf_id/backups/" . $bad, $sf_admin );
			Sf_Test::ok( in_array( $status, array( 400, 404 ), true ), "delete $bad answered $status" );
		}
		list( $status, $remote ) = sf_rest( 'GET', "/storage/$sf_id/backups", $sf_admin );
		foreach ( array_column( $remote['backups'], 'name' ) as $name ) {
			list( $status ) = sf_rest( 'DELETE', "/storage/$sf_id/backups/" . $name, $sf_admin );
			Sf_Test::same( 200, $status, "delete $name:" );
		}
		Sf_Test::same( array(), $sf_b['names'](), 'storage empty:' );

		list( $status, $data ) = sf_rest( 'DELETE', "/storage/$sf_id", $sf_admin );
		Sf_Test::same( 200, $status );
		Sf_Test::same( false, $data['configured'] );
		Sf_Test::ok( ! Destinations::configured( $sf_id ), 'settings removed' );
	}
);

foreach ( $sf_local as $sf_name ) {
	$sf_path = Storage::backups_dir() . '/' . $sf_name;
	if ( is_file( $sf_path ) ) {
		unlink( $sf_path );
	}
}
if ( Destinations::configured( $sf_id ) ) {
	Destinations::remove( $sf_id );
}

exit( Sf_Test::finish( 'wp/test-storage (' . $sf_id . ')' ) );
