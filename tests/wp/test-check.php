<?php
/**
 * Checking a copy of a backup inside WordPress (no import is run). On the test site siteferry-dst:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes eval-file tests/wp/test-check.php
 *
 * Makes a small backup (the database only), checks it through the REST API (administrators only,
 * one job at a time), checks a copy of it with one byte changed, reads the outcomes, and deletes
 * both backups again (which forgets their outcomes). Copies on a storage are checked by
 * tests\wp-storage-check.ps1.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Check\Check;
use SiteFerry\Export\Export;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Check\Check' ) ) {
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

echo "Check tests\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
);
$admin  = (int) $admins[0];

require dirname( __DIR__ ) . '/lib/wp-rest.php';

$backup  = '';
$damaged = '';

Sf_Test::run(
	'a small backup to check (the database only)',
	static function () use ( &$backup ) {
		$exclude = array();
		foreach ( array_diff( (array) scandir( WP_CONTENT_DIR ), array( '.', '..', 'plugins', 'themes', 'uploads' ) ) as $name ) {
			if ( 0 !== strpos( $name, 'siteferry-' ) ) {
				$exclude[] = $name;
			}
		}
		list( $job, $token ) = Export::start(
			array(
				'title'           => 'SiteFerry test: check',
				'exclude_media'   => true,
				'exclude_plugins' => true,
				'exclude_themes'  => true,
				'exclude_paths'   => $exclude,
			)
		);
		$summary = sf_rest_drive( $job->id(), $token );
		Sf_Test::same( 'done', $summary['status'], 'export: ' . wp_json_encode( $summary ) );
		$backup = (string) $summary['result']['name'];
	}
);

if ( '' === $backup ) {
	exit( Sf_Test::finish( 'wp/test-check' ) );
}

Sf_Test::run(
	'checks are for administrators only',
	static function () use ( $backup ) {
		list( $status ) = sf_rest( 'GET', '/checks', 0 );
		Sf_Test::same( 401, $status, 'outcomes, anonymous:' );
		list( $status ) = sf_rest( 'POST', '/checks', 0, array(), array( 'name' => $backup ) );
		Sf_Test::same( 401, $status, 'start, anonymous:' );
		$others = get_users(
			array(
				'role__not_in' => array( 'administrator' ),
				'fields'       => 'ID',
				'number'       => 1,
			)
		);
		if ( $others ) {
			list( $status ) = sf_rest( 'POST', '/checks', (int) $others[0], array(), array( 'name' => $backup ) );
			Sf_Test::same( 403, $status, 'start, not an administrator:' );
		}
	}
);

Sf_Test::run(
	'the copy on this server is intact; one job at a time; the outcome is listed',
	static function () use ( $backup, $admin ) {
		list( $status, $data ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $backup, 'place' => 'local' ) );
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $data ) );
		list( $busy ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $backup ) );
		Sf_Test::same( 409, $busy, 'a second job while one runs:' );
		$job = sf_rest_drive( $data['job']['id'], $data['token'], 0 );
		Sf_Test::same( 'done', $job['status'], 'check: ' . (string) $job['error'] );
		Sf_Test::same( 'check', $job['type'] );
		Sf_Test::ok( 0 === strpos( $job['result']['checked']['summary'], 'All ' ), 'summary: ' . $job['result']['checked']['summary'] );

		list( $status, $all ) = sf_rest( 'GET', '/checks', $admin );
		Sf_Test::same( 200, $status );
		Sf_Test::same( true, $all[ $backup ]['local']['ok'] );
		Sf_Test::same( filesize( Storage::backups_dir() . '/' . $backup ), $all[ $backup ]['local']['size'] );
	}
);

Sf_Test::run(
	'a copy with one changed byte in its dump is damaged, and says where',
	static function () use ( $backup, $admin, &$damaged ) {
		$damaged = preg_replace( '/-[a-f0-9]{12}\.tar$/', '-' . bin2hex( random_bytes( 6 ) ) . '.tar', $backup );
		$data    = (string) file_get_contents( Storage::backups_dir() . '/' . $backup );
		$at      = strpos( $data, 'INSERT INTO' );
		$data[ $at + 1 ] = 'X';
		file_put_contents( Storage::backups_dir() . '/' . $damaged, $data );

		list( $status, $data ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $damaged ) );
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $data ) );
		$job = sf_rest_drive( $data['job']['id'], $data['token'] );
		Sf_Test::same( 'failed', $job['status'] );
		Sf_Test::same( 'The copy on this server is damaged: 1 files do not match their checksums: database.sql.', $job['error'] );
		Sf_Test::same( false, $job['result']['checked']['ok'] );
		Sf_Test::same( false, Check::all()[ $damaged ]['local']['ok'], 'recorded:' );

		list( $status ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => 'none-2026-01-01-000000000000.tar' ) );
		Sf_Test::same( 404, $status, 'a backup that is not there:' );
		list( $status ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => '../wp-config.php' ) );
		Sf_Test::same( 404, $status, 'a path as the name:' );
		list( $status ) = sf_rest( 'POST', '/checks', $admin, array(), array( 'name' => $backup, 'place' => 'nowhere' ) );
		Sf_Test::ok( $status >= 400 && $status < 500, 'an unknown storage (' . $status . ')' );
	}
);

Sf_Test::run(
	'deleting a backup forgets the outcome of its check',
	static function () use ( $backup, $admin, &$damaged ) {
		foreach ( array( $backup, $damaged ) as $name ) {
			if ( '' === $name ) {
				continue;
			}
			list( $status ) = sf_rest( 'DELETE', '/backups/' . $name, $admin );
			Sf_Test::same( 200, $status, 'delete ' . $name . ':' );
			Sf_Test::ok( ! isset( Check::all()[ $name ] ), 'outcome of ' . $name . ' forgotten' );
		}
		sf_rest( 'POST', '/labels/' . $backup, $admin, array(), array() );
	}
);

exit( Sf_Test::finish( 'wp/test-check' ) );
