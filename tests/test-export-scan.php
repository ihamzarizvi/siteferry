<?php
/**
 * Export exclusions and the resumable wp-content scanner.
 *
 * Run: php tests/test-export-scan.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Export\Exclusions;
use SiteFerry\Export\Scanner;
use SiteFerry\Jobs\Slice_Budget;

echo "Export scan tests\n";

/**
 * Default exclusion rules, as an export with cache excluded would use.
 */
function sf_default_exclusions( array $extra = array() ): Exclusions {
	return new Exclusions( array_merge( Exclusions::ALWAYS, Exclusions::BACKUP_PLUGIN_FOLDERS, Exclusions::CACHE_FOLDERS, $extra ) );
}

/**
 * Scans a folder; returns [list lines, state, warnings, calls].
 *
 * @param string     $root       Folder.
 * @param string     $list       List file.
 * @param Exclusions $exclusions Rules.
 * @param bool       $sliced     One folder per call, with a JSON round trip and junk appended between calls.
 */
function sf_scan( string $root, string $list, Exclusions $exclusions, bool $sliced = false ): array {
	$state    = array();
	$warnings = array();
	$calls    = 0;
	$scanner  = new Scanner( $root, $exclusions );
	do {
		++$calls;
		$done = $scanner->run(
			$state,
			$list,
			$sliced ? new Slice_Budget( null, 0 ) : Slice_Budget::unlimited(),
			static function ( string $message ) use ( &$warnings ) {
				$warnings[] = $message;
			}
		);
		if ( $sliced ) {
			$state = json_decode( (string) json_encode( $state ), true );
			file_put_contents( $list, "f\t1\t1\tjunk-from-a-dead-request\n", FILE_APPEND );
		}
	} while ( ! $done );

	if ( $sliced ) {
		// The last call's junk is beyond the saved size; a real resume would cut it off.
		$handle = fopen( $list, 'r+b' );
		ftruncate( $handle, $state['list_size'] );
		fclose( $handle );
	}
	return array( file( $list, FILE_IGNORE_NEW_LINES ), $state, $warnings, $calls );
}

Sf_Test::run(
	'exclusion rules: paths, names at any depth and patterns',
	static function () {
		$rules = sf_default_exclusions( array( 'uploads/2019', 'plugins/akismet' ) );
		$yes   = array( 'cache', 'upgrade', 'upgrade-temp-backup', 'ai1wm-backups', 'updraft', 'siteferry-0123456789ab', 'backupwordpress-1a2b3c-backups', 'uploads/backwpup-1a2b3c-backups', 'uploads/wp-staging/backups', 'uploads/2019', 'plugins/akismet', 'themes/x/.git', 'plugins/y/node_modules', 'et-cache' );
		$no    = array( 'cache-not', 'uploads', 'uploads/2020', 'uploads/2019x', 'plugins/akismet-extra', 'themes/x/.github', 'uploads/cache', 'plugins/y/node_modules_old', 'uploads/wp-staging', 'mysiteferry-0123456789ab' );
		foreach ( $yes as $path ) {
			Sf_Test::ok( $rules->excludes( $path ), "should exclude $path" );
		}
		foreach ( $no as $path ) {
			Sf_Test::ok( ! $rules->excludes( $path ), "should keep $path" );
		}
		$copy = Exclusions::from_array( json_decode( (string) json_encode( $rules->to_array() ), true ) );
		foreach ( array_merge( $yes, $no ) as $path ) {
			Sf_Test::same( $rules->excludes( $path ), $copy->excludes( $path ), "round trip $path:" );
		}
	}
);

$root = Sf_Test::temp_dir( 'wp-content' );
$tree = array(
	'index.php'                                 => '<?php',
	'debug.log'                                 => 'log',
	'cache/page.html'                           => 'cached',
	'upgrade/x.zip'                             => 'x',
	'ai1wm-backups/site.wpress'                 => 'other plugin',
	'siteferry-0123456789ab/backups/old.tar'    => 'own backup',
	'plugins/akismet/akismet.php'               => '<?php',
	'plugins/builder/node_modules/lib/index.js' => 'js',
	'plugins/builder/builder.php'               => '<?php',
	'plugins/hello.php'                         => '<?php',
	'themes/theme-a/.git/HEAD'                  => 'ref',
	'themes/theme-a/style.css'                  => 'css',
	'themes/theme-b/style.css'                  => 'css',
	'uploads/2024/01/photo.jpg'                 => str_repeat( 'j', 5000 ),
	'uploads/2024/01/café ☕.jpg'               => 'unicode',
	'uploads/backwpup-1a2b3c-backups/b.zip'     => 'zip',
	'mu-plugins/loader.php'                     => '<?php',
);
foreach ( $tree as $path => $content ) {
	if ( ! is_dir( dirname( $root . '/' . $path ) ) ) {
		mkdir( dirname( $root . '/' . $path ), 0755, true );
	}
	file_put_contents( $root . '/' . $path, $content );
}
mkdir( $root . '/uploads/2024/02', 0755, true );

$expected = array(
	'd' => array( '', 'mu-plugins', 'plugins', 'plugins/akismet', 'plugins/builder', 'themes', 'themes/theme-a', 'themes/theme-b', 'uploads', 'uploads/2024', 'uploads/2024/01', 'uploads/2024/02' ),
	'f' => array( 'debug.log', 'index.php', 'mu-plugins/loader.php', 'plugins/akismet/akismet.php', 'plugins/builder/builder.php', 'plugins/hello.php', 'themes/theme-a/style.css', 'themes/theme-b/style.css', 'uploads/2024/01/café ☕.jpg', 'uploads/2024/01/photo.jpg' ),
);

/**
 * Splits list lines into folder and file paths (sorted) and checks the line order.
 *
 * @param string[] $lines List lines.
 */
function sf_parse_list( array $lines ): array {
	$out  = array(
		'd' => array(),
		'f' => array(),
	);
	$seen = array( '' => true );
	foreach ( $lines as $index => $line ) {
		$item = Scanner::parse_line( $line );
		Sf_Test::ok( null !== $item, 'malformed line ' . $line );
		$parent = false === strrpos( $item['path'], '/' ) ? '' : substr( $item['path'], 0, strrpos( $item['path'], '/' ) );
		Sf_Test::ok( 0 === $index || isset( $seen[ $parent ] ), 'parent listed after child: ' . $item['path'] );
		if ( 'd' === $item['type'] ) {
			$seen[ $item['path'] ] = true;
		}
		$out[ $item['type'] ][] = $item['path'];
	}
	sort( $out['d'], SORT_STRING );
	sort( $out['f'], SORT_STRING );
	return $out;
}

Sf_Test::run(
	'scan lists folders before their contents and leaves out the default exclusions',
	static function () use ( $root, $expected ) {
		list( $lines, $state, $warnings ) = sf_scan( $root, $root . '-full.list', sf_default_exclusions() );
		Sf_Test::same( $expected, sf_parse_list( $lines ) );
		Sf_Test::same( 'd', $lines[0][0], 'root first:' );
		Sf_Test::same( count( $expected['f'] ), $state['files'] );
		Sf_Test::same( count( $expected['d'] ), $state['dirs'] );
		$bytes = 0;
		foreach ( $expected['f'] as $path ) {
			$bytes += filesize( $root . '/' . $path );
		}
		Sf_Test::same( $bytes, $state['bytes'], 'bytes:' );
		Sf_Test::same( array(), $warnings );
	}
);

Sf_Test::run(
	'scan in one-folder slices with junk from dead requests gives the same list',
	static function () use ( $root ) {
		list( $single )                       = sf_scan( $root, $root . '-single.list', sf_default_exclusions() );
		list( $sliced, $state, $none, $calls ) = sf_scan( $root, $root . '-sliced.list', sf_default_exclusions(), true );
		Sf_Test::same( $single, $sliced );
		Sf_Test::same( 12, $calls, 'one call per folder:' );
	}
);

Sf_Test::run(
	'option-based exclusions: media, plugins, a single plugin file',
	static function () use ( $root ) {
		list( $lines ) = sf_scan( $root, $root . '-opts.list', sf_default_exclusions( array( 'uploads', 'plugins/akismet', 'plugins/hello.php' ) ) );
		$parsed        = sf_parse_list( $lines );
		Sf_Test::ok( ! in_array( 'uploads', $parsed['d'], true ), 'uploads left out' );
		Sf_Test::ok( ! in_array( 'plugins/akismet', $parsed['d'], true ), 'akismet left out' );
		Sf_Test::ok( ! in_array( 'plugins/hello.php', $parsed['f'], true ), 'hello.php left out' );
		Sf_Test::ok( in_array( 'plugins/builder/builder.php', $parsed['f'], true ), 'builder kept' );
	}
);

Sf_Test::run(
	'linked folders (Windows junctions) are not followed',
	static function () {
		$case = (string) getenv( 'SITEFERRY_TEST_JUNCTION_CASE' );
		if ( '' === $case || ! is_dir( $case . '/staging/wp-content' ) ) {
			Sf_Test::skip( 'no junction prepared (run through tests/run-tests.ps1 on Windows)' );
		}
		file_put_contents( $case . '/outside/secret.txt', 'outside the tree' );
		list( $lines, $state, $warnings ) = sf_scan( $case . '/staging', $case . '/scan.list', sf_default_exclusions() );
		unlink( $case . '/outside/secret.txt' );
		unlink( $case . '/scan.list' );
		Sf_Test::same( array( "d\t" ), array_map( 'sf_without_mtime', $lines ) );
		Sf_Test::same( 1, $state['skipped'] );
		Sf_Test::ok( false !== strpos( $warnings[0], 'is a link to another location' ), 'warning text' );
	}
);

/**
 * A list line without its mtime field, for comparisons.
 *
 * @param string $line List line.
 */
function sf_without_mtime( string $line ): string {
	$item = Scanner::parse_line( $line );
	return $item['type'] . "\t" . $item['path'];
}

exit( Sf_Test::finish( 'test-export-scan' ) );
