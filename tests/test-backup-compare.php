<?php
/**
 * Comparing two backups from their manifests: plugins, themes, content, tables, files, versions,
 * changes-only backups, older manifests, identical backups and the order of the two.
 *
 * Run: php tests/test-backup-compare.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Backup_Compare;

echo "Backup compare tests\n";

/**
 * A manifest.
 *
 * @param array $over Values to change.
 */
function sf_bc_manifest( array $over = array() ): array {
	$m = array(
		'format'         => 'siteferry',
		'format_version' => 1,
		'generator'      => array(
			'name'    => 'SiteFerry',
			'version' => '0.29.0',
		),
		'created'        => '2026-11-01T10:00:00Z',
		'site'           => array(
			'home'    => 'https://example.com',
			'network' => null,
		),
		'versions'       => array(
			'wordpress'  => '6.8',
			'db_version' => '58975',
			'php'        => '8.2.10',
			'mysql'      => '8.0.36',
		),
		'database'       => array(
			'prefix' => 'wp_',
			'size'   => 1000000,
			'rows'   => 130,
			'tables' => array(
				array(
					'name' => 'wp_posts',
					'rows' => 100,
				),
				array(
					'name' => 'wp_options',
					'rows' => 30,
				),
			),
		),
		'files'          => array(
			'files' => 500,
			'bytes' => 5000000,
		),
		'options'        => array(
			'exclude_media' => false,
			'tags'          => array(),
		),
		'plugins'        => array(
			'active'    => array( 'woocommerce/woocommerce.php', 'akismet/akismet.php' ),
			'installed' => array(
				'woocommerce/woocommerce.php' => array( 'WooCommerce', '8.7.0' ),
				'akismet/akismet.php'         => array( 'Akismet Anti-spam', '5.3' ),
				'hello.php'                   => array( 'Hello Dolly', '1.7.2' ),
			),
		),
		'themes'         => array(
			'installed' => array(
				'astra'            => array( 'Astra', '4.6.0' ),
				'twentytwentyfour' => array( 'Twenty Twenty-Four', '1.2' ),
			),
		),
		'theme'          => array( 'stylesheet' => 'astra' ),
		'content'        => array(
			'posts'    => 98,
			'pages'    => 12,
			'media'    => 400,
			'users'    => 3,
			'comments' => 50,
		),
	);
	return array_replace_recursive( $m, $over );
}

/**
 * Items as "name:change:activity".
 *
 * @param array $items Items.
 */
function sf_bc_lines( array $items ): array {
	return array_map(
		static function ( array $item ): string {
			return $item['name'] . ':' . $item['change'] . ':' . $item['activity'];
		},
		$items
	);
}

/** The newer manifest of the main test: updates, a new plugin, a theme switch, more content. */
function sf_bc_newer(): array {
	$m                                      = sf_bc_manifest(
		array(
			'created'   => '2026-11-08T10:00:00Z',
			'generator' => array( 'version' => '0.32.0' ),
			'versions'  => array( 'wordpress' => '6.9' ),
			'database'  => array( 'size' => 1300000 ),
			'files'     => array(
				'files' => 520,
				'bytes' => 5400000,
			),
			'theme'     => array( 'stylesheet' => 'twentytwentyfour' ),
			'content'   => array(
				'posts'    => 120,
				'comments' => 61,
			),
		)
	);
	$m['plugins']['installed']              = array(
		'woocommerce/woocommerce.php' => array( 'WooCommerce', '9.2.1' ),
		'akismet/akismet.php'         => array( 'Akismet Anti-spam', '5.2' ),
		'jetpack/jetpack.php'         => array( 'Jetpack', '13.0' ),
	);
	$m['plugins']['active']                 = array( 'woocommerce/woocommerce.php', 'jetpack/jetpack.php' );
	$m['themes']['installed']['twentytwentyfour'] = array( 'Twenty Twenty-Four', '1.3' );
	$m['database']['tables']                = array(
		array(
			'name' => 'wp_posts',
			'rows' => 125,
		),
		array(
			'name' => 'wp_options',
			'rows' => 30,
		),
		array(
			'name' => 'wp_wc_orders',
			'rows' => 7,
		),
	);
	return $m;
}

Sf_Test::run(
	'full vs full: versions, plugins, themes, the theme in use, content, tables and files',
	static function () {
		$c = Backup_Compare::compare(
			array(
				'name'     => 'old.tar',
				'size'     => 10,
				'manifest' => sf_bc_manifest(),
			),
			array(
				'name'     => 'new.tar',
				'size'     => 12,
				'manifest' => sf_bc_newer(),
			)
		);
		Sf_Test::same( array( 'old.tar', 'new.tar' ), array( $c['older']['name'], $c['newer']['name'] ) );
		Sf_Test::same( array( 'siteferry', 'wordpress' ), array_column( $c['versions'], 'what' ), 'versions that differ:' );
		Sf_Test::same( null, $c['address'] );
		Sf_Test::same( true, $c['plugins_known'] );
		Sf_Test::same(
			array( 'Akismet Anti-spam:down:off', 'Hello Dolly:removed:', 'Jetpack:added:on', 'WooCommerce:up:' ),
			sf_bc_lines( $c['plugins'] ),
			'plugins:'
		);
		Sf_Test::same( 0, $c['plugins_same'] );
		Sf_Test::same( array( 'Twenty Twenty-Four:up:' ), sf_bc_lines( $c['themes'] ), 'themes:' );
		Sf_Test::same( 1, $c['themes_same'] );
		Sf_Test::same(
			array(
				'older' => 'Astra',
				'newer' => 'Twenty Twenty-Four',
			),
			$c['theme']
		);
		Sf_Test::same(
			array(
				'older' => 98,
				'newer' => 120,
			),
			$c['content']['posts']
		);
		Sf_Test::same( array( 'wc_orders' ), $c['tables']['added'], 'tables added (without prefix):' );
		Sf_Test::same( array(), $c['tables']['removed'] );
		Sf_Test::same( array( 'posts' ), array_column( $c['tables']['changed'], 'name' ) );
		Sf_Test::same( 1, $c['tables']['same'] );
		Sf_Test::same( 520, $c['files']['newer']['files'] );
		Sf_Test::same( 'full', $c['older']['kind'] );
	}
);

Sf_Test::run(
	'the order given does not matter: always older → newer',
	static function () {
		$c = Backup_Compare::compare( array( 'manifest' => sf_bc_newer() ), array( 'manifest' => sf_bc_manifest() ) );
		Sf_Test::same( '2026-11-01T10:00:00Z', $c['older']['created'] );
		Sf_Test::same( 'Jetpack:added:on', sf_bc_lines( $c['plugins'] )[2] );
	}
);

Sf_Test::run(
	'identical backups: nothing differs',
	static function () {
		$c = Backup_Compare::compare( array( 'manifest' => sf_bc_manifest() ), array( 'manifest' => sf_bc_manifest() ) );
		Sf_Test::same( array(), $c['versions'] );
		Sf_Test::same( array(), $c['plugins'] );
		Sf_Test::same( 3, $c['plugins_same'] );
		Sf_Test::same( array(), $c['themes'] );
		Sf_Test::same( null, $c['theme'] );
		Sf_Test::same( array(), array_merge( $c['tables']['added'], $c['tables']['removed'], $c['tables']['changed'] ) );
		Sf_Test::same( 2, $c['tables']['same'] );
	}
);

Sf_Test::run(
	'older manifests without installed lists or content: only which plugins are on is compared',
	static function () {
		$old = sf_bc_manifest();
		unset( $old['plugins']['installed'], $old['themes'], $old['content'] );
		$c = Backup_Compare::compare( array( 'manifest' => $old ), array( 'manifest' => sf_bc_newer() ) );
		Sf_Test::same( false, $c['plugins_known'] );
		Sf_Test::same( array( 'Akismet Anti-spam::off', 'Jetpack::on' ), sf_bc_lines( $c['plugins'] ), 'names from the backup that has them:' );
		Sf_Test::same( array(), $c['themes'] );
		Sf_Test::same( null, $c['content'] );
		Sf_Test::same( 'Twenty Twenty-Four', $c['theme']['newer'], 'the theme in use still compared:' );
		Sf_Test::same( 'Astra', $c['theme']['older'], 'named from the newer backup:' );
	}
);

Sf_Test::run(
	'a changes-only backup against its full backup',
	static function () {
		$base    = sf_bc_manifest();
		$changes = sf_bc_manifest(
			array(
				'created' => '2026-11-02T10:00:00Z',
				'files'   => array(
					'files' => 2,
					'bytes' => 3000,
				),
			)
		);
		$changes['base']    = array(
			'name'        => 'site-2026-11-01-0a1b2c3d4e5f.tar',
			'created'     => '2026-11-01T10:00:00Z',
			'fingerprint' => str_repeat( 'a', 64 ),
		);
		$changes['changes'] = array( 'files' => 2 );
		$c                  = Backup_Compare::compare(
			array(
				'name'     => 'changes.tar',
				'manifest' => $changes,
			),
			array(
				'name'     => 'site-2026-11-01-0a1b2c3d4e5f.tar',
				'manifest' => $base,
			)
		);
		Sf_Test::same( array( 'full', 'changes' ), array( $c['older']['kind'], $c['newer']['kind'] ) );
		Sf_Test::same( 'site-2026-11-01-0a1b2c3d4e5f.tar', $c['newer']['base'] );
		Sf_Test::same( 2, $c['files']['newer']['changed'] );
		Sf_Test::same( array(), $c['plugins'], 'the site itself did not change:' );
	}
);

exit( Sf_Test::finish( 'test-backup-compare' ) );
