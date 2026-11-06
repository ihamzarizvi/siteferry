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

require dirname( __DIR__ ) . '/lib/wp-rest.php';

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
			array( 'GET', '/backups/x-2026-01-01-0123456789ab.tar/contents' ),
			array( 'GET', '/backups/x-2026-01-01-0123456789ab.tar/file' ),
			array( 'GET', '/receive' ),
			array( 'POST', '/receive' ),
			array( 'POST', '/move/check' ),
			array( 'POST', '/move/send' ),
			array( 'GET', '/move/hello' ),
			array( 'POST', '/move/start' ),
			array( 'POST', '/move/chunk' ),
			array( 'POST', '/move/finish' ),
			array( 'GET', '/space' ),
			array( 'GET', '/checks' ),
			array( 'GET', '/labels' ),
			array( 'POST', '/labels/x-2026-01-01-0123456789ab.tar' ),
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
			array( 'GET', '/storage' ),
			array( 'POST', '/storage/s3' ),
			array( 'DELETE', '/storage/s3' ),
			array( 'POST', '/storage/s3/test' ),
			array( 'GET', '/storage/s3/backups' ),
			array( 'DELETE', '/storage/s3/backups/x-2026-01-01-0123456789ab.tar' ),
			array( 'POST', '/storage/s3/send' ),
			array( 'POST', '/storage/s3/fetch' ),
			array( 'POST', '/storage/gdrive/authorize' ),
			array( 'POST', '/storage/gdrive/connect' ),
			array( 'POST', '/storage/gdrive/disconnect' ),
			array( 'POST', '/storage/dropbox/authorize' ),
			array( 'POST', '/storage/dropbox/connect' ),
			array( 'GET', '/schedule' ),
			array( 'POST', '/schedule' ),
			array( 'POST', '/schedule/run' ),
			array( 'POST', '/undo/dismiss' ),
			array( 'POST', '/choices' ),
			array( 'DELETE', '/choices' ),
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
		list( $status, $data ) = sf_rest(
			'POST',
			'/exports',
			$admin,
			array(),
			array(
				'exclude_media' => true,
				'title'         => '  Before   the update ',
				'note'          => "Line one\nline two",
				'tags'          => 'test, Before-update, test',
			)
		);
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
		$listed = array_values( array_filter( $backups, static function ( $b ) use ( $job ) { return $b['name'] === $job['result']['name']; } ) )[0];
		Sf_Test::same( array( 'Before the update', "Line one\nline two", array( 'test', 'Before-update' ), 'Without media' ), array( $listed['title'], $listed['note'], $listed['tags'], $listed['contents'] ), 'name, note, tags and contents' );
		$manifest = SiteFerry\Backups::manifest( SiteFerry\Backups::path( SiteFerry\Security\Storage::backups_dir(), $job['result']['name'] ) );
		Sf_Test::same( 'Before the update', $manifest['options']['title'], 'the name travels in the manifest' );
		list( $status, $log ) = sf_rest( 'GET', "/jobs/$id/log", $admin );
		Sf_Test::ok( false !== strpos( $log['log'], 'Backup complete' ), 'log readable' );
	}
);

Sf_Test::run(
	'names, notes and tags: renamed, cleaned up, refused for non-backup names, removed when emptied',
	static function () use ( $admin, &$created ) {
		$name = $created[0];
		list( $status, $saved ) = sf_rest(
			'POST',
			'/labels/' . $name,
			$admin,
			array(),
			array(
				'title' => "Renamed\tbackup <b>bold</b>" . str_repeat( 'x', 200 ),
				'note'  => "Note\x07 with a bell",
				'tags'  => array( 'one', 'ONE', 'two<script>', '', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i' ),
			)
		);
		Sf_Test::same( 200, $status, 'save: ' . wp_json_encode( $saved ) );
		Sf_Test::same( 100, mb_strlen( $saved['label']['title'] ), 'name cut to 100 characters' );
		Sf_Test::ok( 0 === strpos( $saved['label']['title'], 'Renamed backup <b>bold</b>' ), 'one line, stored as text (escaped when shown): ' . $saved['label']['title'] );
		Sf_Test::same( 'Note with a bell', $saved['label']['note'], 'control characters dropped' );
		Sf_Test::same( array( 'one', 'twoscript', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h' ), $saved['label']['tags'], 'tags: duplicates and symbols dropped, at most 10' );

		list( $status, $labels ) = sf_rest( 'GET', '/labels', $admin );
		Sf_Test::same( $saved['label'], $labels[ $name ], 'listed by file name' );
		list( $status, $backups ) = sf_rest( 'GET', '/backups', $admin );
		$listed = array_values( array_filter( $backups, static function ( $b ) use ( $name ) { return $b['name'] === $name; } ) )[0];
		Sf_Test::same( $saved['label']['title'], $listed['title'], 'the new name replaces the one in the manifest' );

		foreach ( array( 'x.php', 'x-2026-01-01-0123456789ab.zip' ) as $bad ) {
			list( $status ) = sf_rest( 'POST', '/labels/' . $bad, $admin, array(), array( 'title' => 'x' ) );
			Sf_Test::ok( in_array( $status, array( 400, 404 ), true ), "label for $bad answered $status" );
		}
		list( $status, $cleared ) = sf_rest( 'POST', '/labels/' . $name, $admin, array(), array( 'title' => ' ', 'note' => '', 'tags' => array() ) );
		Sf_Test::same( null, $cleared['label'], 'emptied: removed' );
		Sf_Test::ok( ! isset( SiteFerry\Labels::all()[ $name ] ), 'gone from the option' );
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
	'the choices of "Back up now" are remembered for each user, cleaned, and the defaults are not stored',
	static function () use ( $admin ) {
		$meta  = \SiteFerry\Admin\Choices::META;
		$saved = get_user_meta( $admin, $meta, true ); // This admin's own choices, put back at the end.
		try {
			list( $status, $data ) = sf_rest(
				'POST',
				'/choices',
				$admin,
				array(),
				array(
					'exclude_media'  => true,
					'format'         => 'wpress',
					'compress'       => true,
					'send_to'        => '../s3',
					'exclude_tables' => array( 'wp_big', 'wp_big' ),
					'password'       => 'never-kept-123',
					'title'          => 'Not kept either',
				)
			);
			Sf_Test::same( 200, $status, 'save: ' . wp_json_encode( $data ) );
			Sf_Test::same( array( true, 'wpress', false, '', array( 'wp_big' ) ), array( $data['exclude_media'], $data['format'], $data['compress'], $data['send_to'], $data['exclude_tables'] ) );
			$stored = get_user_meta( $admin, $meta, true );
			Sf_Test::same( $data, $stored, 'stored as answered:' );
			Sf_Test::ok( false === strpos( (string) wp_json_encode( $stored ), 'never-kept' ) && ! isset( $stored['title'] ), 'no password or name is stored' );
			Sf_Test::same( $data, \SiteFerry\Admin\Choices::get( $admin ), 'read back:' );
			Sf_Test::same( \SiteFerry\Admin\Choices::defaults(), \SiteFerry\Admin\Choices::get( 0 ), 'nobody logged in gets the defaults:' );

			list( $status, $data ) = sf_rest( 'DELETE', '/choices', $admin );
			Sf_Test::same( 200, $status );
			Sf_Test::same( \SiteFerry\Admin\Choices::defaults(), $data, 'back to the defaults:' );
			Sf_Test::same( '', get_user_meta( $admin, $meta, true ), 'the defaults are not stored:' );
		} finally {
			if ( is_array( $saved ) ) {
				update_user_meta( $admin, $meta, $saved );
			} else {
				delete_user_meta( $admin, $meta );
			}
		}

		// The list says what each backup holds, for the restore panel.
		list( $status, $list ) = sf_rest( 'GET', '/backups', $admin );
		Sf_Test::same( 200, $status );
		foreach ( $list as $backup ) {
			Sf_Test::ok( array_key_exists( 'holds', $backup ), 'holds listed for ' . $backup['name'] );
			if ( is_array( $backup['holds'] ) ) {
				Sf_Test::same( array( 'tables', 'files', 'left_out', 'network' ), array_keys( $backup['holds'] ), $backup['name'] . ':' );
			}
		}
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
			SiteFerry\Labels::set( $name, '', '', array() );
		}
		$created = array();
	}
);

exit( Sf_Test::finish( 'wp/test-rest' ) );
