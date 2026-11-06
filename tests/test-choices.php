<?php
/**
 * The remembered choices of "Back up now", the part that needs no WordPress: only known keys and
 * valid values are kept, combinations an export would refuse are set right, and a password or a
 * name never is.
 *
 * Run: php tests/test-choices.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Admin\Choices;
use SiteFerry\Backups;
use SiteFerry\Export\Export;

echo "Choices tests\n";

Sf_Test::run(
	'the defaults are an export\'s defaults: the whole site, a .tar file, on this server',
	static function () {
		$defaults = Choices::defaults();
		foreach ( Choices::FLAGS as $key => $value ) {
			if ( array_key_exists( $key, Export::DEFAULTS ) ) {
				Sf_Test::same( Export::DEFAULTS[ $key ], $value, "default of $key:" );
			}
		}
		Sf_Test::same( 'tar', $defaults['format'] );
		Sf_Test::same( '', $defaults['send_to'] );
		Sf_Test::same( array(), $defaults['exclude_tables'] );
		Sf_Test::same( $defaults, Choices::clean( array() ), 'nothing given:' );
	}
);

Sf_Test::run(
	'only known choices are kept, never a password, a name, a note or tags',
	static function () {
		$clean = Choices::clean(
			array(
				'exclude_media' => true,
				'password'      => 'secret-secret',
				'title'         => 'Name',
				'note'          => 'Note',
				'tags'          => array( 'a' ),
				'exclude_paths' => array( 'uploads' ),
				'level'         => 9,
			)
		);
		Sf_Test::same( array_keys( Choices::defaults() ), array_keys( $clean ), 'keys:' );
		Sf_Test::ok( true === $clean['exclude_media'], 'media left out' );
	}
);

Sf_Test::run(
	'yes means JSON true or 1; anything else is no',
	static function () {
		foreach ( array( true, 1, '1', 'true' ) as $yes ) {
			Sf_Test::ok( true === Choices::clean( array( 'exclude_plugins' => $yes ) )['exclude_plugins'], 'yes: ' . var_export( $yes, true ) );
		}
		foreach ( array( false, 0, '0', 'false', 'yes', array(), null ) as $no ) {
			Sf_Test::ok( false === Choices::clean( array( 'exclude_cache' => $no ) )['exclude_cache'], 'no: ' . var_export( $no, true ) );
		}
	}
);

Sf_Test::run(
	'format and storage: known values only; .wpress is neither compressed nor protected; deleting here needs a storage',
	static function () {
		Sf_Test::same( 'tar', Choices::clean( array( 'format' => 'zip' ) )['format'] );
		Sf_Test::same( 'tar', Choices::clean( array( 'format' => array( 'wpress' ) ) )['format'] );
		$wpress = Choices::clean(
			array(
				'format'   => 'wpress',
				'compress' => true,
				'encrypt'  => true,
			)
		);
		Sf_Test::same( array( 'wpress', false, false ), array( $wpress['format'], $wpress['compress'], $wpress['encrypt'] ) );
		$tar = Choices::clean(
			array(
				'compress' => true,
				'encrypt'  => true,
			)
		);
		Sf_Test::same( array( true, true ), array( $tar['compress'], $tar['encrypt'] ) );
		Sf_Test::same( 'dropbox', Choices::clean( array( 'send_to' => 'dropbox' ) )['send_to'] );
		foreach ( array( 'Dropbox', '../s3', 's3 ', str_repeat( 'a', 21 ), 5, array( 's3' ) ) as $bad ) {
			Sf_Test::same( '', Choices::clean( array( 'send_to' => $bad ) )['send_to'], 'storage ' . var_export( $bad, true ) . ':' );
		}
		Sf_Test::ok( false === Choices::clean( array( 'delete_after_send' => true ) )['delete_after_send'], 'not deleted without a storage' );
		Sf_Test::ok(
			true === Choices::clean(
				array(
					'send_to'           => 's3',
					'delete_after_send' => true,
				)
			)['delete_after_send'],
			'deleted after sending to a storage'
		);
	}
);

Sf_Test::run(
	'left-out tables: names only, once each, without control characters, at most 1000',
	static function () {
		$tables = Choices::clean( array( 'exclude_tables' => array( 'wp_posts', 'wp_posts', '123', 7, '', "bad\nname", str_repeat( 'x', 65 ), 'wp_ünï', array( 'x' ) ) ) )['exclude_tables'];
		Sf_Test::same( array( 'wp_posts', '123', 'wp_ünï' ), $tables );
		Sf_Test::ok( is_string( $tables[1] ), 'a table named with digits stays a string' );
		$many = array();
		for ( $i = 0; $i < 1200; $i++ ) {
			$many[] = 't' . $i;
		}
		Sf_Test::same( Choices::MAX_TABLES, count( Choices::clean( array( 'exclude_tables' => $many ) )['exclude_tables'] ) );
		Sf_Test::same( array(), Choices::clean( array( 'exclude_tables' => 'wp_posts' ) )['exclude_tables'], 'not a list:' );
	}
);

Sf_Test::run(
	'what a backup holds: tables, files, the parts left out, a network; unknown without a full manifest',
	static function () {
		$manifest = array(
			'database' => array( 'tables' => array( array( 'name' => 'wp_posts' ), array( 'name' => 'wp_options' ) ) ),
			'files'    => array(
				'files' => 12,
				'bytes' => 3400,
			),
			'site'     => array( 'home' => 'https://example.com' ),
			'options'  => array(
				'exclude_media'  => true,
				'exclude_themes' => true,
				'exclude_tables' => array( 'wp_big' ),
			),
		);
		Sf_Test::same(
			array(
				'tables'   => 2,
				'files'    => 12,
				'left_out' => array( 'media', 'themes', 'tables' ),
				'network'  => false,
			),
			Backups::holds( $manifest )
		);
		$manifest['options']         = array( 'exclude_cache' => true );
		$manifest['site']['network'] = array( 'domain' => 'example.com' );
		$holds                       = Backups::holds( $manifest );
		Sf_Test::same( array( array(), true ), array( $holds['left_out'], $holds['network'] ) );
		// Without recorded choices (a .wpress backup, an older SiteFerry) nothing is known about what was left out.
		unset( $manifest['options'] );
		Sf_Test::same( null, Backups::holds( $manifest )['left_out'] );
		// A .wpress backup before it is checked says only where it came from.
		Sf_Test::same( null, Backups::holds( array( 'site' => array( 'home' => 'https://example.com' ) ) ) );
	}
);

exit( Sf_Test::finish( 'test-choices' ) );
