<?php
/**
 * Network rules: which tables, user meta keys and files belong to one site of a network, the
 * address of a new site, and how a backup's users join a network.
 *
 * Run: php tests/test-network-rules.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Network\Extract;
use SiteFerry\Network\Rules;

echo "Network rules tests\n";

const SF_NET_TABLES = array( 'wp_blogs', 'wp_blogmeta', 'wp_site', 'wp_sitemeta', 'wp_signups', 'wp_registration_log', 'wp_users', 'wp_usermeta', 'wp_options', 'wp_posts', 'wp_2_options', 'wp_2_posts', 'wp_2_wc_orders', 'wp_20_posts', 'wp_3_options', 'wp_custom_log', 'other_table' );

Sf_Test::run(
	'tables: a subsite gets its own (renamed) and the users tables; the main site keeps the base tables',
	static function () {
		Sf_Test::same(
			array(
				'wp_users'       => 'wp_users',
				'wp_usermeta'    => 'wp_usermeta',
				'wp_2_options'   => 'wp_options',
				'wp_2_posts'     => 'wp_posts',
				'wp_2_wc_orders' => 'wp_wc_orders',
			),
			Rules::table_map( SF_NET_TABLES, 'wp_', 2 ),
			'site 2 (not wp_20_*):'
		);
		Sf_Test::same(
			array( 'wp_users', 'wp_usermeta', 'wp_options', 'wp_posts', 'wp_custom_log' ),
			array_keys( Rules::table_map( SF_NET_TABLES, 'wp_', 1 ) ),
			'site 1 (no network tables, no numbered ones, no other prefix):'
		);
		Sf_Test::same( array( 'wp_users', 'wp_usermeta' ), array_keys( Rules::table_map( SF_NET_TABLES, 'wp_', 9 ) ), 'a site without tables:' );
	}
);

Sf_Test::run(
	'user meta keys: the site\'s keys lose its number, other sites\' keys are left out',
	static function () {
		Sf_Test::same( 'wp_capabilities', Rules::meta_key( 'wp_2_capabilities', 'wp_', 2 ) );
		Sf_Test::same( null, Rules::meta_key( 'wp_3_capabilities', 'wp_', 2 ), 'another site:' );
		Sf_Test::same( null, Rules::meta_key( 'wp_capabilities', 'wp_', 2 ), 'the main site\'s, for site 2:' );
		Sf_Test::same( 'wp_capabilities', Rules::meta_key( 'wp_capabilities', 'wp_', 1 ), 'site 1 keeps its own:' );
		Sf_Test::same( null, Rules::meta_key( 'wp_2_capabilities', 'wp_', 1 ), 'site 1 drops numbered ones:' );
		Sf_Test::same( 'first_name', Rules::meta_key( 'first_name', 'wp_', 2 ), 'unprefixed keys stay:' );
	}
);

Sf_Test::run(
	'files: the site\'s media become uploads/, other sites\' media are left out, the rest stays',
	static function () {
		Sf_Test::same( 'wp-content/uploads/2026/01/a.jpg', Rules::file_path( 'wp-content/uploads/sites/2/2026/01/a.jpg', 2 ) );
		Sf_Test::same( 'wp-content/uploads', Rules::file_path( 'wp-content/uploads/sites/2', 2 ) );
		Sf_Test::same( null, Rules::file_path( 'wp-content/uploads/sites/20/x.jpg', 2 ), 'site 20, not 2:' );
		Sf_Test::same( null, Rules::file_path( 'wp-content/uploads/2026/01/main.jpg', 2 ), 'the main site\'s media:' );
		Sf_Test::same( 'wp-content/uploads/2026/01/main.jpg', Rules::file_path( 'wp-content/uploads/2026/01/main.jpg', 1 ) );
		Sf_Test::same( null, Rules::file_path( 'wp-content/uploads/sites/3/x.jpg', 1 ), 'site 1 without the subsites\' media:' );
		Sf_Test::same( 'wp-content/plugins/akismet/akismet.php', Rules::file_path( 'wp-content/plugins/akismet/akismet.php', 2 ) );
		Sf_Test::same( null, Rules::file_path( 'wp-content/blogs.dir/2/files/x.jpg', 2 ), 'old blogs.dir:' );
		Sf_Test::same( 'database.sql', Rules::file_path( 'database.sql', 2 ), 'not wp-content: unchanged (the caller decides):' );
	}
);

Sf_Test::run(
	'new site address: subfolder names, subdomains, reserved and malformed names refused',
	static function () {
		$folders = array(
			'domain'            => 'localhost:10016',
			'path'              => '/',
			'subdomain_install' => false,
		);
		Sf_Test::same(
			array(
				'domain' => 'localhost:10016',
				'path'   => '/alpha-copy/',
				'url'    => 'http://localhost:10016/alpha-copy',
			),
			Rules::new_site( ' /Alpha-Copy/ ', $folders, 'http' )
		);
		$sub = array(
			'domain'            => 'example.com',
			'path'              => '/',
			'subdomain_install' => true,
		);
		Sf_Test::same( 'https://shop.example.com', Rules::new_site( 'shop', $sub, 'https' )['url'] );
		Sf_Test::same( 'shop.example.com', Rules::new_site( 'shop.example.com', $sub, 'https' )['domain'] );
		foreach ( array( 'wp-admin', 'files', 'a b', '-x', '', 'x/y', 'é' ) as $bad ) {
			try {
				Rules::new_site( $bad, $folders, 'http' );
				throw new RuntimeException( 'accepted: ' . $bad );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( '' !== $e->getMessage(), 'refused "' . $bad . '"' );
			}
		}
	}
);

Sf_Test::run(
	'users: the same e-mail is the same person; new users keep their login unless taken (then -2, -3)',
	static function () {
		$map = Rules::map_users(
			array(
				1 => array(
					'login' => 'admin',
					'email' => 'Owner@Example.com',
				),
				2 => array(
					'login' => 'admin',
					'email' => 'other@example.com',
				),
				3 => array(
					'login' => 'editor',
					'email' => 'ed@example.com',
				),
				4 => array(
					'login' => 'admin-2',
					'email' => 'third@example.com',
				),
			),
			array( 'owner@example.com' => 7 ),
			array(
				'admin'   => true,
				'admin-2' => true,
			)
		);
		Sf_Test::same( array( 'match', 7 ), array( $map[1]['action'], $map[1]['id'] ), 'matched by e-mail, case-insensitive:' );
		Sf_Test::same( array( 'create', 'admin-3', true ), array( $map[2]['action'], $map[2]['login'], $map[2]['renamed'] ), 'login taken twice:' );
		Sf_Test::same( array( 'create', 'editor', false ), array( $map[3]['action'], $map[3]['login'], $map[3]['renamed'] ) );
		Sf_Test::same( 'admin-2-2', $map[4]['login'], 'a renamed login is not reused:' );
		Sf_Test::same( 'wp_wc_orders', Rules::too_long( array( 'wp_wc_orders' ), 'wp_', 'wp_2_' . str_repeat( 'x', 51 ) ), 'one character too long:' );
		Sf_Test::same( null, Rules::too_long( array( 'wp_wc_orders' ), 'wp_', 'wp_2_' . str_repeat( 'x', 50 ) ), 'exactly 64 fits:' );
		Sf_Test::same( null, Rules::too_long( array( 'wp_posts' ), 'wp_', 'wp_12_' ) );
	}
);

Sf_Test::run(
	'dump statements: table of a statement, renaming its head only, splitting an INSERT',
	static function () {
		Sf_Test::same( 'wp_2_posts', Extract::table_of( 'INSERT INTO `wp_2_posts` (`ID`) VALUES (1)' ) );
		Sf_Test::same( 'wp_2_posts', Extract::table_of( 'DROP TABLE IF EXISTS `wp_2_posts`' ) );
		Sf_Test::same( null, Extract::table_of( 'SET NAMES utf8mb4' ) );
		Sf_Test::same(
			"CREATE TABLE `wp_posts` (\n  `note` text DEFAULT 'wp_2_posts'\n)",
			Extract::rename_head( "CREATE TABLE `wp_2_posts` (\n  `note` text DEFAULT 'wp_2_posts'\n)", 'wp_2_posts', 'wp_posts' ),
			'only the table name:'
		);
		list( $head, $columns, $values ) = Extract::split_insert( "INSERT INTO `wp_usermeta` (`umeta_id`, `user_id`, `meta_key`) VALUES (1,2,'a'),\n(2,3,'b')" );
		Sf_Test::same( 'INSERT INTO `wp_usermeta` (`umeta_id`, `user_id`, `meta_key`) VALUES ', $head );
		Sf_Test::same( array( 'umeta_id', 'user_id', 'meta_key' ), $columns );
		Sf_Test::same( "(1,2,'a'),\n(2,3,'b')", $values );
	}
);

exit( Sf_Test::finish( 'test-network-rules' ) );
