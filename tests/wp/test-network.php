<?php
/**
 * Multisite networks inside WordPress (no import is run). On a subfolder network (siteferry-dst
 * converted, with the subsites /alpha/ and /beta/):
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes eval-file tests/wp/test-network.php
 *
 * Checks who may use SiteFerry (super admins, on the main site), that a backup holds the whole
 * network (every site's tables, the network tables, subsite media, the network in its manifest),
 * that a restore check maps every subsite (cancelled before anything changes), and that a backup
 * of a single site is refused. Removes its backup.
 *
 * Optional argument: the path of a single-site SiteFerry backup, for the refusal check.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Backups;
use SiteFerry\Export\Export;
use SiteFerry\Import\Import;
use SiteFerry\Jobs\Job;
use SiteFerry\Rest\Rest;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! is_multisite() ) {
	echo "Run with wp eval-file on a multisite network where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/wp-rest.php';

echo "Network tests\n";

$admin  = (int) get_super_admins() ? (int) get_user_by( 'login', get_super_admins()[0] )->ID : 1;
$single = isset( $args[0] ) ? (string) $args[0] : '';
$backup = '';

Sf_Test::run(
	'super admins on the main site only',
	static function () use ( $admin ) {
		wp_set_current_user( $admin );
		Sf_Test::same( true, Rest::can_manage(), 'super admin, main site:' );
		switch_to_blog( (int) get_blog_id_from_url( get_network()->domain, get_network()->path . 'alpha/' ) );
		Sf_Test::same( false, Rest::can_manage(), 'super admin, a subsite:' );
		restore_current_blog();
		$others = get_users( array( 'blog_id' => get_main_site_id(), 'fields' => 'ID', 'exclude' => array( $admin ), 'number' => 50 ) );
		foreach ( $others as $id ) {
			if ( ! is_super_admin( (int) $id ) ) {
				wp_set_current_user( (int) $id );
				Sf_Test::same( false, Rest::can_manage(), 'not a super admin:' );
				break;
			}
		}
		wp_set_current_user( $admin );
	}
);

Sf_Test::run(
	'a backup of the whole network: every site\'s tables, the network tables, subsite media, the network in the manifest',
	static function () use ( &$backup ) {
		$exclude = array();
		foreach ( array_diff( (array) scandir( WP_CONTENT_DIR ), array( '.', '..', 'plugins', 'themes', 'uploads' ) ) as $name ) {
			if ( 0 !== strpos( $name, 'siteferry-' ) ) {
				$exclude[] = $name;
			}
		}
		list( $job, $token ) = Export::start(
			array(
				'exclude_plugins' => true,
				'exclude_themes'  => true,
				'exclude_paths'   => $exclude,
			)
		);
		$summary = sf_rest_drive( $job->id(), $token );
		Sf_Test::same( 'done', $summary['status'], 'export: ' . (string) $summary['error'] );
		$backup   = (string) $summary['result']['name'];
		$manifest = Backups::manifest( Storage::backups_dir() . '/' . $backup );
		$network  = $manifest['site']['network'];
		Sf_Test::same( true, $manifest['site']['multisite'] );
		Sf_Test::same( false, $network['subdomain_install'] );
		Sf_Test::same( array( '/', '/alpha/', '/beta/' ), array_column( $network['blogs'], 'path' ) );
		$tables = array_column( $manifest['database']['tables'], 'name' );
		global $wpdb;
		foreach ( array( 'blogs', 'site', 'sitemeta', 'users', '2_posts', '3_options', 'options' ) as $suffix ) {
			Sf_Test::ok( in_array( $wpdb->base_prefix . $suffix, $tables, true ), 'table ' . $wpdb->base_prefix . $suffix );
		}
		$index = \SiteFerry\Contents::index( Storage::backups_dir() . '/' . $backup, 60.0 );
		$paths = array_column( $index['entries'], 0 );
		Sf_Test::ok( (bool) preg_grep( '#^wp-content/uploads/sites/2/.+/sftest-alpha\.txt$#', $paths ), 'the media of /alpha/' );
	}
);

Sf_Test::run(
	'a restore check maps every subsite to this network (cancelled before anything changes)',
	static function () use ( $backup, $admin ) {
		list( $status, $data ) = sf_rest( 'POST', '/imports', $admin, array(), array( 'backup' => $backup ) );
		Sf_Test::same( 200, $status, 'start: ' . wp_json_encode( $data ) );
		$job = sf_rest_drive( $data['job']['id'], $data['token'] );
		Sf_Test::same( 'waiting', $job['status'], 'checked: ' . (string) $job['error'] );
		$map = Job::load( Storage::jobs_dir(), $data['job']['id'] )->state( 'inspect' )['network'];
		Sf_Test::same( get_network()->domain, $map['domain'] );
		Sf_Test::same(
			array( home_url(), home_url( '/alpha' ), home_url( '/beta' ) ),
			array_column( $map['blogs'], 'url' )
		);
		list( $status ) = sf_rest( 'POST', '/jobs/' . $data['job']['id'] . '/cancel', 0, array( 'X-SiteFerry-Job' => $data['token'] ) );
		Sf_Test::same( 200, $status, 'cancelled:' );

		list( $status, $data ) = sf_rest( 'POST', '/imports', $admin, array(), array( 'backup' => $backup, 'replace_url' => 'http://elsewhere.test' ) );
		if ( 200 === $status ) {
			$job = sf_rest_drive( $data['job']['id'], $data['token'] );
			Sf_Test::same( 'failed', $job['status'] );
			Sf_Test::ok( false !== strpos( (string) $job['error'], 'wp-config.php' ), 'no new address for a network: ' . $job['error'] );
		}
	}
);

Sf_Test::run(
	'a backup of a single site is refused here',
	static function () use ( $single ) {
		if ( '' === $single || ! is_file( $single ) ) {
			Sf_Test::skip( 'no single-site backup given' );
		}
		list( $job, $token ) = Import::start( $single );
		$summary = sf_rest_drive( $job->id(), $token );
		Sf_Test::same( 'failed', $summary['status'] );
		Sf_Test::ok( false !== strpos( (string) $summary['error'], 'multisite network' ), $summary['error'] );
	}
);

if ( '' !== $backup ) {
	sf_rest( 'DELETE', '/backups/' . $backup, $admin );
}

exit( Sf_Test::finish( 'wp/test-network' ) );
