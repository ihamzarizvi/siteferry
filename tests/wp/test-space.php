<?php
/**
 * Space report inside WordPress (nothing is changed but the kept report). On a test site:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes eval-file tests/wp/test-space.php
 *
 * Measures through the REST API (administrators only; in steps when the time is short), and
 * compares the media and plugin sizes with a walk of those folders and the database size with
 * SHOW TABLE STATUS; a kept report is used again unless "fresh" is asked for.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Space;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Space' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/wp-rest.php';

echo "Space report tests\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
);
$admin  = (int) $admins[0];

/**
 * Bytes of the files below a folder (folder links not followed).
 *
 * @param string $dir Folder.
 */
function sf_walk( string $dir ): int {
	$bytes = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$bytes += $file->isFile() ? (int) $file->getSize() : 0;
	}
	return $bytes;
}

Sf_Test::run(
	'administrators only',
	static function () {
		list( $status ) = sf_rest( 'GET', '/space', 0 );
		Sf_Test::same( 401, $status );
	}
);

Sf_Test::run(
	'measured in steps; the sizes match the folders and the database',
	static function () {
		Space::forget();
		$steps = 0;
		do {
			$result = Space::report( 0.0 ); // One folder per step.
			++$steps;
		} while ( ! $result['complete'] && $steps < 100000 );
		Sf_Test::ok( $steps > 5, 'in ' . $steps . ' steps' );
		$report = $result['report'];
		$uploads = wp_upload_dir()['basedir'];
		Sf_Test::same( sf_walk( $uploads ), $report['files']['parts']['uploads']['bytes'], 'media:' );
		$plugins = 0;
		foreach ( array_diff( (array) scandir( WP_PLUGIN_DIR ), array( '.', '..', \SiteFerry\Plugin::folder() ) ) as $name ) {
			$path     = WP_PLUGIN_DIR . '/' . $name;
			$plugins += is_dir( $path ) ? sf_walk( $path ) : (int) filesize( $path );
		}
		Sf_Test::same( $plugins, $report['files']['parts']['plugins']['bytes'], 'plugins (SiteFerry itself is never in a backup):' );
		global $wpdb;
		$data = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_A ) as $row ) {
			$data += (int) $row['Data_length'];
		}
		Sf_Test::same( $data, $report['database']['bytes'], 'database:' );
		Sf_Test::ok( null === $report['free'] || $report['free'] > 0, 'free space' );
		Sf_Test::same( false, is_file( \SiteFerry\Security\Storage::root() . '/space/files.list' ), 'the file list is removed once added up' );
	}
);

Sf_Test::run(
	'the kept report is used again; "fresh" measures again',
	static function () use ( $admin ) {
		list( $status, $first ) = sf_rest( 'GET', '/space', $admin );
		Sf_Test::same( 200, $status );
		Sf_Test::same( true, $first['complete'] );
		list( , $again ) = sf_rest( 'GET', '/space', $admin );
		Sf_Test::same( $first['report']['time'], $again['report']['time'], 'kept:' );
		sleep( 1 );
		$request = new WP_REST_Request( 'GET', '/siteferry/v1/space' );
		$request->set_param( 'fresh', 1 );
		wp_set_current_user( $admin );
		$data  = rest_do_request( $request )->get_data();
		$tries = 0;
		while ( ! $data['complete'] && $tries++ < 100 ) {
			list( , $data ) = sf_rest( 'GET', '/space', $admin );
		}
		Sf_Test::ok( $data['report']['time'] > $first['report']['time'], 'measured again' );
	}
);

exit( Sf_Test::finish( 'wp/test-space' ) );
