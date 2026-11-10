<?php
/**
 * WP-CLI output and hooks, the parts that need no WordPress: output modes, the JSON payload,
 * sizes per format, and the checks applied to what hook callbacks return.
 *
 * Run: php tests/test-cli-output.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Cli\Output;
use SiteFerry\Hooks;

echo "WP-CLI output and hooks tests\n";

Sf_Test::run(
	'output modes: --json, --format=json where it is free, --porcelain, else words',
	static function () {
		Sf_Test::same( Output::HUMAN, Output::mode( array() ) );
		Sf_Test::same( Output::JSON, Output::mode( array( 'json' => true ) ) );
		Sf_Test::same( Output::JSON, Output::mode( array( 'format' => 'json' ) ), '--format=json where --format is free:' );
		Sf_Test::same( Output::HUMAN, Output::mode( array( 'format' => 'json' ), false ), 'export: --format means tar or wpress there:' );
		Sf_Test::same( Output::JSON, Output::mode( array( 'format' => 'wpress', 'json' => true ), false ), 'export --json:' );
		Sf_Test::same( Output::PORCELAIN, Output::mode( array( 'porcelain' => true ) ) );
		Sf_Test::same( Output::JSON, Output::mode( array( 'porcelain' => true, 'json' => true ) ), '--json wins over --porcelain:' );
		Sf_Test::same( Output::HUMAN, Output::mode( array( 'format' => 'table' ) ) );
	}
);

Sf_Test::run(
	'the JSON payload: "ok" first, the fields, then warnings as strings; valid JSON with plain slashes',
	static function () {
		$payload = Output::payload( array( 'name' => 'a.tar', 'path' => 'C:/x/a.tar', 'size' => 12 ), array( 'one', 2 ) );
		Sf_Test::same( array( 'ok', 'name', 'path', 'size', 'warnings' ), array_keys( $payload ) );
		Sf_Test::same( true, $payload['ok'] );
		Sf_Test::same( array( 'one', '2' ), $payload['warnings'] );
		$json = Output::encode( $payload );
		Sf_Test::ok( false !== strpos( $json, '"C:/x/a.tar"' ), 'slashes are not escaped' );
		Sf_Test::same( $payload, json_decode( $json, true ), 'round trip:' );
		Sf_Test::same( array( 'ok' => true, 'warnings' => array() ), Output::payload( array() ) );
		Sf_Test::ok( false !== strpos( Output::encode( array( 'name' => "caf\xc3\xa9" ) ), "caf\xc3\xa9" ), 'Unicode stays readable' );
	}
);

Sf_Test::run(
	'sizes: bytes outside a table',
	static function () {
		foreach ( array( 'json', 'csv', 'yaml', 'count' ) as $format ) {
			Sf_Test::same( 2048, Output::size( 2048, $format ), $format . ':' );
		}
		Sf_Test::ok( in_array( 'count', Output::LIST_FORMATS, true ) && in_array( 'yaml', Output::LIST_FORMATS, true ), 'list formats include yaml and count' );
	}
);

Sf_Test::run(
	'hooks: every name starts with siteferry_; without WordPress actions are skipped and filters pass the value through',
	static function () {
		foreach ( array_keys( Hooks::HOOKS ) as $name ) {
			Sf_Test::ok( 0 === strpos( $name, 'siteferry_' ), $name );
		}
		foreach ( array( 'siteferry_export_started', 'siteferry_export_done', 'siteferry_import_started', 'siteferry_import_done', 'siteferry_backup_sent', 'siteferry_job_failed', 'siteferry_export_exclusions', 'siteferry_manifest_extra', 'siteferry_backup_name_label' ) as $name ) {
			Sf_Test::ok( isset( Hooks::HOOKS[ $name ] ), 'documented: ' . $name );
		}
		if ( ! function_exists( 'apply_filters' ) ) {
			Sf_Test::same( array( 'a' ), Hooks::filter( 'siteferry_export_exclusions', array( 'a' ), array() ) );
			Hooks::action( 'siteferry_job_failed', null, 'x' ); // Nothing happens, nothing is thrown.
		}
	}
);

Sf_Test::run(
	'what callbacks return is checked: manifest extra, name label, exclusion paths',
	static function () {
		$big   = str_repeat( 'x', 70000 );
		$extra = Hooks::clean_extra(
			array(
				'my-shop'      => array( 'orders' => 3 ),
				'Bad Key!'     => 1,
				7              => 'numeric key',
				'too-big'      => $big,
				'_starts-bad'  => 1,
				'fine_2'       => 'text',
			)
		);
		Sf_Test::same( array( 'my-shop' => array( 'orders' => 3 ), 'fine_2' => 'text' ), $extra );
		Sf_Test::same( array(), Hooks::clean_extra( 'not an array' ) );

		Sf_Test::same( 'my-shop', Hooks::clean_label( 'My Shop!!', 'fallback' ) );
		Sf_Test::same( 'fallback', Hooks::clean_label( '***', 'fallback' ), 'nothing usable left:' );
		Sf_Test::same( 'fallback', Hooks::clean_label( array( 'x' ), 'fallback' ) );
		Sf_Test::same( 40, strlen( Hooks::clean_label( str_repeat( 'ab', 40 ), 'f' ) ), 'at most 40:' );
		Sf_Test::same( 'a-b', Hooks::clean_label( 'a' . str_repeat( '-', 3 ) . 'b', 'f' ) );

		Sf_Test::same(
			array( 'uploads/videos', 'cache', 'uploads/*-old' ),
			Hooks::clean_paths( array( ' /uploads/videos/ ', 'cache', '../wp-config.php', 'uploads/../../x', 'C:/Windows', 5, '', 'uploads/*-old', 'cache' ), array( 'kept' ) )
		);
		Sf_Test::same( array( 'kept' ), Hooks::clean_paths( 'not an array', array( 'kept' ) ), 'a broken filter keeps the original list:' );
	}
);

exit( Sf_Test::finish( 'test-cli-output' ) );
