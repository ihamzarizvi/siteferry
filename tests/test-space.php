<?php
/**
 * Space report, the part that needs no WordPress: adding up a file list into parts of
 * wp-content, media by year, plugins and themes (active or not), cache, top-level folders and
 * the largest files.
 *
 * Run: php tests/test-space.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Space;

echo "Space report tests\n";

/**
 * A scanner line for a file.
 *
 * @param string $path Path.
 * @param int    $size Size.
 */
function sf_line( string $path, int $size ): string {
	return "f\t$size\t1700000000\t$path\n";
}

$layout = array(
	'uploads'        => 'uploads',
	'plugins'        => 'plugins',
	'themes'         => 'themes',
	'cache'          => array( 'cache', 'litespeed' ),
	'active_plugins' => array( 'akismet', 'hello.php' ),
	'active_themes'  => array( 'child', 'parent' ),
);

Sf_Test::run(
	'parts, media by year, plugins and themes with what is inactive, cache, the rest',
	static function () use ( $layout ) {
		$lines = array(
			"d\t1700000000\t\n",
			"d\t1700000000\tuploads\n",
			sf_line( 'uploads/2024/01/a.jpg', 300 ),
			sf_line( 'uploads/2024/02/b.jpg', 200 ),
			sf_line( 'uploads/2025/c.jpg', 1000 ),
			sf_line( 'uploads/elementor/css.css', 7 ),
			sf_line( 'plugins/akismet/akismet.php', 50 ),
			sf_line( 'plugins/akismet/readme.txt', 10 ),
			sf_line( 'plugins/big/big.php', 900 ),
			sf_line( 'plugins/hello.php', 5 ),
			sf_line( 'plugins/index.php', 1 ),
			sf_line( 'themes/child/style.css', 20 ),
			sf_line( 'themes/parent/style.css', 30 ),
			sf_line( 'themes/old/style.css', 400 ),
			sf_line( 'cache/page.html', 70 ),
			sf_line( 'languages/de.mo', 40 ),
			"garbage line\n",
		);
		$report = Space::summarize( $lines, $layout );
		Sf_Test::same( 14, $report['files'] );
		Sf_Test::same( 3033, $report['bytes'] );
		Sf_Test::same( array( 'bytes' => 1507, 'files' => 4 ), $report['parts']['uploads'] );
		Sf_Test::same( array( 'bytes' => 966, 'files' => 5, 'inactive' => 900 ), $report['parts']['plugins'] );
		Sf_Test::same( array( 'bytes' => 450, 'files' => 3, 'inactive' => 400 ), $report['parts']['themes'] );
		Sf_Test::same( array( 'bytes' => 70, 'files' => 1 ), $report['parts']['cache'] );
		Sf_Test::same( array( 'bytes' => 40, 'files' => 1 ), $report['parts']['other'] );
		Sf_Test::same(
			array(
				array( 'name' => '2025', 'bytes' => 1000 ),
				array( 'name' => '2024', 'bytes' => 500 ),
			),
			$report['years'],
			'media years, largest first (not "elementor"):'
		);
		Sf_Test::same(
			array(
				array( 'name' => 'big', 'bytes' => 900, 'active' => false ),
				array( 'name' => 'akismet', 'bytes' => 60, 'active' => true ),
				array( 'name' => 'hello.php', 'bytes' => 5, 'active' => true ),
			),
			$report['plugins'],
			'plugins (index.php is no plugin):'
		);
		Sf_Test::same( array( 'old', 'parent', 'child' ), array_column( $report['themes'], 'name' ) );
		Sf_Test::same( array( 'uploads', 'plugins', 'themes', 'cache', 'languages' ), array_column( $report['items'], 'name' ) );
		Sf_Test::same( array( 'uploads/2025/c.jpg', 'plugins/big/big.php', 'themes/old/style.css' ), array_slice( array_column( $report['largest'], 'path' ), 0, 3 ) );
	}
);

Sf_Test::run(
	'the largest files stay right over many files; folders outside wp-content are only "other"',
	static function () use ( $layout ) {
		$lines = array();
		for ( $i = 1; $i <= 500; $i++ ) {
			$lines[] = sf_line( 'misc/f' . $i . '.bin', ( $i * 7919 ) % 1000 + 1 );
		}
		$report  = Space::summarize( $lines, $layout );
		$sizes   = array_map(
			static function ( int $i ): int {
				return ( $i * 7919 ) % 1000 + 1;
			},
			range( 1, 500 )
		);
		rsort( $sizes );
		Sf_Test::same( array_slice( $sizes, 0, Space::TOP ), array_column( $report['largest'], 'bytes' ) );

		$none   = array( 'uploads' => null, 'plugins' => null, 'themes' => null ) + $layout;
		$report = Space::summarize( array( sf_line( 'plugins/a/a.php', 5 ) ), $none );
		Sf_Test::same( 5, $report['parts']['other']['bytes'], 'plugins kept outside wp-content:' );
		Sf_Test::same( array(), $report['plugins'] );
	}
);

exit( Sf_Test::finish( 'test-space' ) );
