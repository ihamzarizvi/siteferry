<?php
/**
 * Restore preview: what a restore would change (plugins and themes, which are turned on, the theme
 * in use, content counts) for whole and partial restores, and for backups that do not record
 * versions.
 *
 * Run: php tests/test-preview.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Import\Preview;

echo "Restore preview tests\n";

/** A backup's manifest: WooCommerce 8.7 and Akismet 5.3 and Hello Dolly, Astra in use. */
function sf_pv_manifest(): array {
	return array(
		'database' => array( 'prefix' => 'wp_' ),
		'site'     => array( 'network' => null ),
		'plugins'  => array(
			'active'    => array( 'woocommerce/woocommerce.php', 'akismet/akismet.php', 'hello.php', 'siteferry/siteferry.php' ),
			'installed' => array(
				'woocommerce/woocommerce.php'       => array( 'WooCommerce', '8.7.0' ),
				'akismet/akismet.php'               => array( 'Akismet Anti-spam', '5.3' ),
				'hello.php'                         => array( 'Hello Dolly', '1.7.2' ),
				'classic-editor/classic-editor.php' => array( 'Classic Editor', '1.6.3' ),
				'siteferry/siteferry.php'           => array( 'SiteFerry', '0.20.0' ),
			),
		),
		'themes'   => array(
			'installed' => array(
				'astra'            => array( 'Astra', '4.6.0' ),
				'twentytwentyfour' => array( 'Twenty Twenty-Four', '1.2' ),
			),
		),
		'theme'    => array(
			'stylesheet' => 'astra',
			'template'   => 'astra',
		),
		'content'  => array(
			'posts'    => 98,
			'pages'    => 12,
			'media'    => 400,
			'users'    => 3,
			'comments' => 50,
		),
	);
}

/** This site now: WooCommerce 9.2.1, Akismet 5.2, no Hello Dolly, Jetpack added, Twenty Twenty-Four in use. */
function sf_pv_site(): array {
	return array(
		'active_plugins'    => array( 'woocommerce/woocommerce.php', 'akismet/akismet.php', 'jetpack/jetpack.php', 'siteferry/siteferry.php' ),
		'plugins_installed' => array(
			'woocommerce/woocommerce.php'       => array( 'WooCommerce', '9.2.1' ),
			'akismet/akismet.php'               => array( 'Akismet Anti-spam', '5.2' ),
			'jetpack/jetpack.php'               => array( 'Jetpack', '13.0' ),
			'classic-editor/classic-editor.php' => array( 'Classic Editor', '1.6.3' ),
			'siteferry/siteferry.php'           => array( 'SiteFerry', '0.29.0' ),
		),
		'themes_installed'  => array(
			'astra'            => array( 'Astra', '4.6.0' ),
			'twentytwentyfour' => array( 'Twenty Twenty-Four', '1.3' ),
		),
		'stylesheet'        => 'twentytwentyfour',
		'content'           => array(
			'posts'    => 120,
			'pages'    => 12,
			'media'    => 410,
			'users'    => 3,
			'comments' => 61,
		),
	);
}

const SF_PV_OWN = array(
	'own_plugin' => 'siteferry/siteferry.php',
	'own_folder' => 'siteferry',
);

/**
 * The listed plugins or themes as "name:change:activity".
 *
 * @param array $items Items.
 */
function sf_pv_lines( array $items ): array {
	return array_map(
		static function ( array $item ): string {
			return $item['name'] . ':' . $item['change'] . ':' . $item['activity'];
		},
		$items
	);
}

Sf_Test::run(
	'a whole restore: versions back, a plugin comes back, one added since is turned off, the theme and content',
	static function () {
		$p = Preview::compare( sf_pv_manifest(), sf_pv_site(), SF_PV_OWN );
		Sf_Test::same( true, $p['known'] );
		Sf_Test::same(
			array( 'Akismet Anti-spam:newer:', 'Hello Dolly:back:on', 'Jetpack:stays:off', 'WooCommerce:older:' ),
			sf_pv_lines( $p['plugins'] ),
			'plugins:'
		);
		Sf_Test::same( 1, $p['plugins_same'], 'Classic Editor stays; SiteFerry is not counted:' );
		$woo = $p['plugins'][3];
		Sf_Test::same( array( '9.2.1', '8.7.0' ), array( $woo['now'], $woo['then'] ), 'WooCommerce versions:' );
		Sf_Test::same( array( 'Twenty Twenty-Four:older:' ), sf_pv_lines( $p['themes'] ), 'themes:' );
		Sf_Test::same( 1, $p['themes_same'] );
		Sf_Test::same(
			array(
				'now'  => 'Twenty Twenty-Four',
				'then' => 'Astra',
			),
			$p['theme'],
			'the theme in use:'
		);
		Sf_Test::same(
			array(
				'now'  => 120,
				'then' => 98,
			),
			$p['content']['posts'],
			'posts:'
		);
		Sf_Test::same( array( 'posts', 'pages', 'media', 'users', 'comments' ), array_keys( $p['content'] ) );
	}
);

Sf_Test::run(
	'files only: versions change, nothing is turned on or off, theme and content stay',
	static function () {
		$p = Preview::compare( sf_pv_manifest(), sf_pv_site(), array( 'database' => false ) + SF_PV_OWN );
		Sf_Test::same( array( 'Akismet Anti-spam:newer:', 'Hello Dolly:back:', 'WooCommerce:older:' ), sf_pv_lines( $p['plugins'] ) );
		Sf_Test::same( null, $p['theme'] );
		Sf_Test::same( null, $p['content'] );
	}
);

Sf_Test::run(
	'database only: no version changes; what is turned on, the theme and content do change',
	static function () {
		$p = Preview::compare( sf_pv_manifest(), sf_pv_site(), array( 'files' => false ) + SF_PV_OWN );
		// Hello Dolly is not installed here and its files are not restored: it cannot be turned on.
		Sf_Test::same( array( 'Jetpack:stays:off' ), sf_pv_lines( $p['plugins'] ) );
		Sf_Test::same( array(), $p['themes'] );
		Sf_Test::same( 'Astra', $p['theme']['then'] );
		Sf_Test::ok( null !== $p['content'], 'content is compared' );
	}
);

Sf_Test::run(
	'chosen parts: one plugin, or the posts table without the options',
	static function () {
		$p = Preview::compare(
			sf_pv_manifest(),
			sf_pv_site(),
			array(
				'database' => false,
				'paths'    => array( 'plugins/akismet' ),
			) + SF_PV_OWN
		);
		Sf_Test::same( array( 'Akismet Anti-spam:newer:' ), sf_pv_lines( $p['plugins'] ), 'only Akismet:' );
		Sf_Test::same( array(), $p['themes'] );

		$p = Preview::compare(
			sf_pv_manifest(),
			sf_pv_site(),
			array(
				'files'  => false,
				'tables' => array( 'wp_posts' ),
			) + SF_PV_OWN
		);
		Sf_Test::same( array(), $p['plugins'], 'no options table: nothing turned on or off:' );
		Sf_Test::same( null, $p['theme'] );
		Sf_Test::same( array( 'posts', 'pages', 'media' ), array_keys( $p['content'] ), 'only what lives in the posts table:' );

		$p = Preview::compare( sf_pv_manifest(), sf_pv_site(), array( 'paths' => array( 'themes' ) ) + SF_PV_OWN );
		Sf_Test::same( array( 'Jetpack:stays:off' ), sf_pv_lines( $p['plugins'] ), 'themes only: plugins keep their versions:' );
		Sf_Test::same( array( 'Twenty Twenty-Four:older:' ), sf_pv_lines( $p['themes'] ) );
	}
);

Sf_Test::run(
	'an older backup or a .wpress file without versions: only what is turned on is compared',
	static function () {
		$m = sf_pv_manifest();
		unset( $m['plugins']['installed'], $m['themes'], $m['content'] );
		$p = Preview::compare( $m, sf_pv_site(), SF_PV_OWN );
		Sf_Test::same( false, $p['known'] );
		Sf_Test::same( array( 'hello.php::on', 'Jetpack::off' ), sf_pv_lines( $p['plugins'] ), 'turned on or off, by file name when not installed here:' );
		Sf_Test::same( null, $p['content'] );
		Sf_Test::same( 'Astra', $p['theme']['then'], 'the name from this site when the backup has none:' );

		// A manifest with nothing about plugins at all.
		$p = Preview::compare( array( 'database' => array( 'prefix' => 'wp_' ) ), sf_pv_site(), SF_PV_OWN );
		Sf_Test::same( array(), $p['plugins'] );
		Sf_Test::same( null, $p['theme'] );
	}
);

exit( Sf_Test::finish( 'test-preview' ) );
