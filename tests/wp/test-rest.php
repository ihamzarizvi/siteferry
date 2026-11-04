<?php
/**
 * REST API checks inside WordPress (no import is run). On a test site where SiteFerry is active:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-src eval-file tests/wp/test-rest.php
 *
 * Checks that every route refuses anonymous requests, that job routes accept the job token and
 * nothing else, runs an export through the API with the token only (as the browser does), uploads
 * that backup again in chunks (with a wrong offset and a damaged chunk on the way), and removes the
 * backups it made.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Rest\Rest' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/fixtures.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

echo "REST tests\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
);
$admin  = (int) $admins[0];

/**
 * Dispatches a REST request.
 *
 * @param string      $method  HTTP method.
 * @param string      $route   Route under /siteferry/v1.
 * @param int         $user    User id (0 = anonymous).
 * @param array       $headers Headers.
 * @param array|null  $json    JSON body.
 * @param string|null $raw     Raw body.
 * @return array{0:int,1:mixed} Status and data.
 */
function sf_rest( string $method, string $route, int $user, array $headers = array(), ?array $json = null, ?string $raw = null ): array {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, '/siteferry/v1' . $route );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	if ( null !== $json ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $json ) );
	}
	if ( null !== $raw ) {
		$request->set_header( 'Content-Type', 'application/octet-stream' );
		$request->set_body( $raw );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

$created = array();

Sf_Test::run(
	'anonymous requests are refused on every route',
	static function () {
		$routes = array(
			array( 'GET', '/status' ),
			array( 'GET', '/tables' ),
			array( 'GET', '/backups' ),
			array( 'DELETE', '/backups/x-2026-01-01-0123456789ab.tar' ),
			array( 'GET', '/backups/x-2026-01-01-0123456789ab.tar/download' ),
			array( 'POST', '/exports' ),
			array( 'POST', '/imports' ),
			array( 'GET', '/jobs' ),
			array( 'GET', '/jobs/0123456789abcdef' ),
			array( 'POST', '/jobs/0123456789abcdef/run' ),
			array( 'POST', '/jobs/0123456789abcdef/confirm' ),
			array( 'POST', '/jobs/0123456789abcdef/cancel' ),
			array( 'GET', '/jobs/0123456789abcdef/log' ),
			array( 'GET', '/uploads' ),
			array( 'POST', '/uploads/new' ),
			array( 'GET', '/uploads/0123456789abcdef01234567' ),
			array( 'POST', '/uploads/0123456789abcdef01234567/chunk' ),
			array( 'POST', '/uploads/0123456789abcdef01234567/finish' ),
			array( 'POST', '/uploads/0123456789abcdef01234567/cancel' ),
		);
		foreach ( $routes as list( $method, $route ) ) {
			list( $status ) = sf_rest( $method, $route, 0 );
			Sf_Test::ok( in_array( $status, array( 401, 403 ), true ), "$method $route answered $status" );
		}
		$routes = rest_get_server()->get_routes( 'siteferry/v1' );
		unset( $routes['/siteferry/v1'] ); // WordPress's own namespace index (a list of routes).
		foreach ( $routes as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				Sf_Test::ok( isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ), "no permission callback on $route" );
			}
		}
	}
);

Sf_Test::run(
	'export through the API, driven with the job token only; tokens are checked',
	static function () use ( $admin, &$created ) {
		list( $status, $data ) = sf_rest( 'POST', '/exports', $admin, array(), array( 'exclude_media' => true ) );
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $data ) );
		$id    = $data['job']['id'];
		$token = $data['token'];
		Sf_Test::ok( (bool) preg_match( '/^[a-f0-9]{64}$/', $token ), 'token format' );

		list( $status ) = sf_rest( 'POST', '/exports', $admin, array(), array() );
		Sf_Test::same( 409, $status, 'second job while one is active:' );

		list( $status ) = sf_rest( 'GET', "/jobs/$id", 0, array( 'X-SiteFerry-Job' => str_repeat( 'a', 64 ) ) );
		Sf_Test::same( 401, $status, 'wrong token:' );
		list( $status ) = sf_rest( 'POST', "/jobs/$id/confirm", 0, array( 'X-SiteFerry-Job' => $token ) );
		Sf_Test::same( 401, $status, 'confirm needs the admin as well as the token:' );

		// A zero-second slice still does one unit of work, so a short "seconds" never stalls a job.
		list( $status, $job ) = sf_rest( 'POST', "/jobs/$id/run", 0, array( 'X-SiteFerry-Job' => $token ), array( 'seconds' => 0 ) );
		Sf_Test::same( 200, $status, 'zero-second slice: ' . wp_json_encode( $job ) );
		Sf_Test::ok( 'running' === $job['status'] && ( $job['progress'] > 0 || $job['step']['index'] > 0 ), 'zero-second slice made progress: ' . wp_json_encode( $job ) );

		$slices = 0;
		do {
			list( $status, $job ) = sf_rest( 'POST', "/jobs/$id/run", 0, array( 'X-SiteFerry-Job' => $token ) );
			Sf_Test::same( 200, $status, 'run: ' . wp_json_encode( $job ) );
			++$slices;
		} while ( ! in_array( $job['status'], array( 'done', 'failed' ), true ) && $slices < 100 );
		Sf_Test::same( 'done', $job['status'], 'export result: ' . (string) $job['error'] );
		Sf_Test::ok( ! isset( $job['token_hash'] ), 'the token hash is never sent' );
		$created[] = $job['result']['name'];

		list( $status, $backups ) = sf_rest( 'GET', '/backups', $admin );
		Sf_Test::ok( in_array( $job['result']['name'], array_column( $backups, 'name' ), true ), 'new backup is listed' );
		list( $status, $log ) = sf_rest( 'GET', "/jobs/$id/log", $admin );
		Sf_Test::ok( false !== strpos( $log['log'], 'Backup complete' ), 'log readable' );
	}
);

Sf_Test::run(
	'chunked upload through the API: wrong offset 409, damaged chunk 400, then the backup is listed',
	static function () use ( $admin, &$created ) {
		$source = SiteFerry\Backups::path( SiteFerry\Security\Storage::backups_dir(), $created[0] );
		$data   = (string) file_get_contents( $source );
		$chunk  = SiteFerry\Rest\Rest::chunk_size();

		list( $status, $upload ) = sf_rest( 'POST', '/uploads/new', $admin, array(), array( 'name' => $created[0], 'size' => strlen( $data ) ) );
		Sf_Test::same( 200, $status, 'start upload' );
		$id = $upload['id'];

		$first = substr( $data, 0, $chunk );
		list( $status ) = sf_rest( 'POST', "/uploads/$id/chunk", $admin, array( 'X-SiteFerry-Offset' => '5', 'X-SiteFerry-Sha256' => hash( 'sha256', $first ) ), null, $first );
		Sf_Test::same( 409, $status, 'wrong offset:' );
		list( $status ) = sf_rest( 'POST', "/uploads/$id/chunk", $admin, array( 'X-SiteFerry-Offset' => '0', 'X-SiteFerry-Sha256' => hash( 'sha256', 'other' ) ), null, $first );
		Sf_Test::same( 400, $status, 'damaged chunk:' );

		$offset = 0;
		while ( $offset < strlen( $data ) ) {
			$part                    = substr( $data, $offset, $chunk );
			list( $status, $upload ) = sf_rest( 'POST', "/uploads/$id/chunk", $admin, array( 'X-SiteFerry-Offset' => (string) $offset, 'X-SiteFerry-Sha256' => hash( 'sha256', $part ) ), null, $part );
			Sf_Test::same( 200, $status, "chunk at $offset" );
			$offset = $upload['offset'];
		}
		list( $status, $done ) = sf_rest( 'POST', "/uploads/$id/finish", $admin );
		Sf_Test::same( 200, $status, 'finish: ' . wp_json_encode( $done ) );
		Sf_Test::ok( $done['backup'] !== $created[0], 'a copy with an existing name gets a new name' );
		$created[] = $done['backup'];
		Sf_Test::same( hash( 'sha256', $data ), hash_file( 'sha256', SiteFerry\Backups::path( SiteFerry\Security\Storage::backups_dir(), $done['backup'] ) ) );
	}
);

Sf_Test::run(
	'backups: unsafe names are refused, deleting works',
	static function () use ( $admin, &$created ) {
		foreach ( array( '..%2F..%2Fwp-config.php', 'x.php' ) as $name ) {
			list( $status ) = sf_rest( 'DELETE', '/backups/' . $name, $admin );
			Sf_Test::ok( in_array( $status, array( 404, 400 ), true ), "delete $name answered $status" );
		}
		foreach ( $created as $name ) {
			list( $status ) = sf_rest( 'DELETE', '/backups/' . $name, $admin );
			Sf_Test::same( 200, $status, "delete $name:" );
		}
		$created = array();
	}
);

exit( Sf_Test::finish( 'wp/test-rest' ) );
