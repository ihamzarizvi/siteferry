<?php
/**
 * Site-to-site move inside WordPress (no import is run). On the test site siteferry-dst, which
 * sends a small backup (the database only) to itself over HTTP:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes eval-file tests/wp/test-move.php
 *
 * Checks the key (administrators make it; the move routes answer only to it; wrong keys are counted
 * and drop it; it works once), the move itself (an encrypted backup sent in chunks, the local copy
 * removed, the backup in the list with the "moved" tag), a chunk at the wrong offset, and that the
 * backup that arrived opens without a password for a check and a restore check (cancelled before
 * anything changes). Deletes the backup at the end.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Move\Receiver;
use SiteFerry\Move\Sender;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Move\Receiver' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/wp-rest.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

echo "Move tests\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
);
$admin  = (int) $admins[0];

// This site moves to itself, with a small backup.
add_filter( 'siteferry_move_to_self', '__return_true' );
add_filter(
	'siteferry_move_export_options',
	static function ( array $options ): array {
		$exclude = array();
		foreach ( array_diff( (array) scandir( WP_CONTENT_DIR ), array( '.', '..', 'plugins', 'themes', 'uploads' ) ) as $name ) {
			if ( 0 !== strpos( $name, 'siteferry-' ) ) {
				$exclude[] = $name;
			}
		}
		return $options + array(
			'exclude_media'   => true,
			'exclude_plugins' => true,
			'exclude_themes'  => true,
			'exclude_paths'   => $exclude,
		);
	}
);

/**
 * A request to a move route over HTTP, as the sending site makes it.
 *
 * @param string $action Route.
 * @param string $header X-SiteFerry-Move value.
 * @param array  $more   Extra request arguments.
 * @return array{0:int,1:mixed}
 */
function sf_move_http( string $action, string $header, array $more = array() ): array {
	$response = wp_remote_request(
		home_url( '/?rest_route=/siteferry/v1/move/' . $action ),
		$more + array(
			'method'  => 'GET',
			'timeout' => 60,
			'headers' => array( 'X-SiteFerry-Move' => $header ),
		)
	);
	return array( (int) wp_remote_retrieve_response_code( $response ), json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
}

$key      = '';
$received = '';

Sf_Test::run(
	'the key: made by administrators; the move routes answer to it alone; wrong keys drop it',
	static function () use ( $admin, &$key ) {
		list( $status ) = sf_rest( 'POST', '/receive', 0 );
		Sf_Test::same( 401, $status, 'making a key, anonymous:' );
		list( $status, $data ) = sf_rest( 'POST', '/receive', $admin );
		Sf_Test::same( 200, $status );
		Sf_Test::same( 'waiting', $data['state'] );
		$key    = (string) $data['key'];
		$parsed = Sender::parse( $key );
		Sf_Test::same( home_url(), $parsed['url'] );

		list( $status ) = sf_move_http( 'hello', '' );
		Sf_Test::same( 401, $status, 'no key:' );
		list( $status ) = sf_move_http( 'hello', $parsed['id'] . '.' . str_repeat( 'A', 43 ) );
		Sf_Test::same( 401, $status, 'a wrong secret:' );
		list( $status, $hello ) = sf_move_http( 'hello', $parsed['id'] . '.' . $parsed['secret'] );
		Sf_Test::same( 200, $status, 'the key: ' . wp_json_encode( $hello ) );
		Sf_Test::same( home_url(), $hello['site'] );
		Sf_Test::same( 'waiting', Receiver::status()['state'], 'one wrong secret does not drop it:' );

		// A key that someone tries to guess is dropped.
		Receiver::create();
		$other = Sender::parse( Receiver::create()['key'] );
		for ( $i = 0; $i < Receiver::MAX_FAILURES; $i++ ) {
			Receiver::accepts( $other['id'] . '.' . str_repeat( 'B', 43 ) );
		}
		Sf_Test::same( 'none', Receiver::status()['state'], 'dropped after ' . Receiver::MAX_FAILURES . ' wrong secrets:' );
		Sf_Test::same( false, Receiver::accepts( $other['id'] . '.' . $other['secret'] ), 'even the right secret no longer works' );

		foreach ( array( '', 'SFMOVE1.garbage', 'nonsense' ) as $bad ) {
			try {
				Sender::parse( $bad );
				Sf_Test::ok( false, 'refused: "' . $bad . '"' );
			} catch ( \SiteFerry\Jobs\Job_Exception $e ) {
				Sf_Test::ok( true, 'refused' );
			}
		}
		$key = (string) Receiver::create()['key'];
	}
);

Sf_Test::run(
	'the move: an encrypted backup sent in chunks, the local copy removed, the backup in the list',
	static function () use ( $admin, $key, &$received ) {
		list( $status, $data ) = sf_rest( 'POST', '/move/check', $admin, array(), array( 'key' => $key ) );
		Sf_Test::same( 200, $status, 'check: ' . wp_json_encode( $data ) );

		list( $job ) = Sender::start( $key );
		$slices      = 0;
		while ( ! $job->finished() && $slices < 500 ) {
			Runner::run_slice( $job, new Slice_Budget( 5.0 ) );
			$job = Job::load( Storage::jobs_dir(), $job->id() );
			++$slices;
		}
		Sf_Test::same( Job::STATUS_DONE, $job->status(), 'move: ' . (string) $job->error() );
		$moved = $job->result()['moved'];
		Sf_Test::same( home_url(), $moved['to'] );
		Sf_Test::same( true, $job->result()['deleted_local'], 'the copy made for the move is removed:' );
		Sf_Test::same( false, is_file( (string) $job->result()['file'] ) );
		Sf_Test::same( array( 'to' => home_url() ), $job->state( 'move' ), 'the key is not kept in the job:' );

		wp_cache_flush(); // The receiving side ran in the site's own requests, not in this process.
		$received = (string) $moved['name'];
		Sf_Test::ok( (bool) preg_match( '/\.tar(\.gz)?\.enc$/', $received ), 'encrypted: ' . $received );
		Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $received ), 'in the backups folder' );
		$status = Receiver::status();
		Sf_Test::same( array( 'received', $received ), array( $status['state'], $status['received']['name'] ) );
		$label = \SiteFerry\Labels::get( $received );
		Sf_Test::same( array( 'moved' ), $label['tags'] );

		list( , $list ) = sf_rest( 'GET', '/backups', $admin );
		$entry          = array_values( array_filter( $list, static function ( array $b ) use ( $received ): bool {
			return $b['name'] === $received;
		} ) );
		Sf_Test::same( array( true, true ), array( $entry[0]['encrypted'], $entry[0]['unlocked'] ), 'listed as protected, opened by the kept key:' );

		$parsed         = Sender::parse( $key );
		list( $status ) = sf_move_http( 'hello', $parsed['id'] . '.' . $parsed['secret'] );
		Sf_Test::same( 401, $status, 'the key works once:' );
	}
);

Sf_Test::run(
	'a chunk at the wrong offset is answered with 409, so the sender asks where to go on',
	static function () {
		$parsed = Sender::parse( Receiver::create()['key'] );
		$header = $parsed['id'] . '.' . $parsed['secret'];
		list( $status, $start ) = sf_move_http(
			'start',
			$header,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array( 'X-SiteFerry-Move' => $header, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'name' => 'localhost-2026-11-05-0123456789ab.tar.enc', 'size' => 100, 'from' => 'http://example.test' ) ),
			)
		);
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $start ) );
		list( $status ) = sf_move_http(
			'chunk',
			$header,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array( 'X-SiteFerry-Move' => $header, 'X-SiteFerry-Offset' => '50', 'X-SiteFerry-Sha256' => hash( 'sha256', 'abc' ) ),
				'body'    => 'abc',
			)
		);
		Sf_Test::same( 409, $status, 'wrong offset:' );
		list( $status, $data ) = sf_move_http(
			'start',
			$header,
			array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array( 'X-SiteFerry-Move' => $header, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'name' => 'x.php', 'size' => 100, 'from' => '' ) ),
			)
		);
		Sf_Test::same( 400, $status, 'only encrypted SiteFerry backups: ' . wp_json_encode( $data ) );
		wp_cache_flush(); // The transfer was started in the site's own requests.
		Receiver::cancel();
		Sf_Test::same( 'none', Receiver::status()['state'] );
	}
);

Sf_Test::run(
	'what arrived opens without a password: a check, and a restore check (cancelled)',
	static function () use ( $admin, &$received ) {
		Sf_Test::ok( null !== Receiver::password_for( $received ), 'its password is kept, also after a new key was made' );
		list( $status, $data ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $received ) );
		Sf_Test::same( 200, $status, 'a check without a password: ' . wp_json_encode( $data ) );
		$job = sf_rest_drive( $data['job']['id'], $data['token'] );
		Sf_Test::same( 'done', $job['status'], 'check: ' . (string) $job['error'] );

		list( $status, $data ) = sf_rest( 'POST', '/imports', $admin, array(), array( 'backup' => $received ) );
		Sf_Test::same( 200, $status, 'a restore check without a password: ' . wp_json_encode( $data ) );
		$job = sf_rest_drive( $data['job']['id'], $data['token'] );
		Sf_Test::same( 'waiting', $job['status'], 'checked, waiting for confirmation: ' . (string) $job['error'] );
		list( $status ) = sf_rest( 'POST', '/jobs/' . $data['job']['id'] . '/cancel', 0, array( 'X-SiteFerry-Job' => $data['token'] ) );
		Sf_Test::same( 200, $status, 'cancelled before anything changed:' );
	}
);

if ( '' !== $received ) {
	list( $status ) = sf_rest( 'DELETE', '/backups/' . $received, $admin );
	sf_rest( 'POST', '/labels/' . $received, $admin, array(), array() );
	Sf_Test::run(
		'deleting the backup forgets its password',
		static function () use ( $received ) {
			Sf_Test::same( null, Receiver::password_for( $received ) );
		}
	);
}

exit( Sf_Test::finish( 'wp/test-move' ) );
