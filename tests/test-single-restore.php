<?php
/**
 * Restoring one post: reading literal values out of a dump strictly, and finding one post's rows
 * (and its custom fields and term links) in a dump. The writing side needs WordPress and is checked
 * on a test site.
 *
 * Run: php tests/test-single-restore.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Database\Dump_Rows;
use SiteFerry\Database\Sql;

echo "Single restore tests\n";

Sf_Test::run(
	'literals: NULL, numbers, hex, strings with every escape SiteFerry writes, and doubled quotes',
	static function () {
		Sf_Test::same( null, Dump_Rows::literal( 'NULL' ) );
		Sf_Test::same( '42', Dump_Rows::literal( '42' ) );
		Sf_Test::same( '-1.5e3', Dump_Rows::literal( '-1.5e3' ) );
		Sf_Test::same( "\x00\xff", Dump_Rows::literal( '0x00FF' ) );
		Sf_Test::same( '', Dump_Rows::literal( "''" ) );
		Sf_Test::same( "it's", Dump_Rows::literal( "'it''s'" ), 'doubled quote:' );
		Sf_Test::same( 'say "hi"', Dump_Rows::literal( '"say ""hi"""' ), 'double-quoted:' );
		Sf_Test::same( "a\tb\x08\\%", Dump_Rows::literal( "'a\\tb\\b\\%'" ), 'tab, backspace, \% keeps its backslash:' );
		// Whatever the dumper writes comes back unchanged.
		$values = array( "line\nbreak\r\n", "quote ' and \" and \\", "nul\0byte", "ctrl-z\x1a", 'a:1:{s:3:"key";s:5:"value";}', 'üñíçødé ✓' );
		foreach ( $values as $value ) {
			Sf_Test::same( $value, Dump_Rows::literal( Sql::literal( $value, 'string' ) ), 'round trip:' );
		}
		Sf_Test::same( "\x01\x02", Dump_Rows::literal( Sql::literal( "\x01\x02", 'binary' ) ), 'binary round trip:' );
	}
);

Sf_Test::run(
	'anything else is refused: expressions, open strings, stray quotes, odd hex',
	static function () {
		foreach ( array( "'open", "'a'b'", 'NOW()', "CONCAT('a')", '0x123', "'x' OR 1", '1; DROP TABLE x', '', "'a\\'" ) as $bad ) {
			try {
				Dump_Rows::literal( $bad );
			} catch ( Archive_Exception $e ) {
				Sf_Test::same( Archive_Exception::CORRUPT, $e->getCode(), 'refused ' . $bad . ':' );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . $bad );
		}
	}
);

Sf_Test::run(
	'scan: one post, its custom fields and term links among other tables and statements',
	static function () {
		$dir  = Sf_Test::temp_dir( 'single' );
		$dump = $dir . '/database.sql';
		$post = static function ( int $id, string $title ): string {
			return '(' . $id . ',' . Sql::literal( $title, 'string' ) . ",'post','publish')";
		};
		file_put_contents(
			$dump,
			"-- SiteFerry database dump\nSET NAMES utf8mb4;\n"
			. "CREATE TABLE `wp_posts` (`ID` bigint, `post_title` text, `post_type` varchar(20), `post_status` varchar(20));\n"
			. 'INSERT INTO `wp_posts` (`ID`, `post_title`, `post_type`, `post_status`) VALUES ' . $post( 1, 'Hello' ) . ',' . $post( 7, "Seven's (day), with; semicolons" ) . ";\n"
			. 'INSERT INTO `wp_postmeta` (`meta_id`, `post_id`, `meta_key`, `meta_value`) VALUES (1,7,' . Sql::literal( '_thumb', 'string' ) . ",'12'),(2,1,'x','y'),(3,7,'data'," . Sql::literal( 'a:1:{i:0;s:3:"a;b";}', 'string' ) . ");\n"
			. "INSERT INTO `wp_postsx` (`ID`) VALUES (7);\n"
			. "INSERT INTO `wp_term_relationships` (`object_id`, `term_taxonomy_id`, `term_order`) VALUES (7,3,0),(1,3,0);\n"
			. "-- SiteFerry dump end\n"
		);
		$found = array();
		Dump_Rows::scan(
			$dump,
			array( 'wp_posts', 'wp_postmeta', 'wp_term_relationships' ),
			static function ( string $table, array $columns, array $fields ) use ( &$found ): bool {
				$owner = array(
					'wp_posts'              => 'ID',
					'wp_postmeta'           => 'post_id',
					'wp_term_relationships' => 'object_id',
				)[ $table ];
				if ( '7' === Dump_Rows::literal( $fields[ array_search( $owner, $columns, true ) ] ) ) {
					$found[ $table ][] = Dump_Rows::row( $columns, $fields );
				}
				return true;
			}
		);
		Sf_Test::same( "Seven's (day), with; semicolons", $found['wp_posts'][0]['post_title'] );
		Sf_Test::same( 1, count( $found['wp_posts'] ), 'wp_postsx is another table:' );
		Sf_Test::same( array( '_thumb', 'data' ), array_column( $found['wp_postmeta'], 'meta_key' ) );
		Sf_Test::same( 'a:1:{i:0;s:3:"a;b";}', $found['wp_postmeta'][1]['meta_value'] );
		Sf_Test::same( array( '3' ), array_column( $found['wp_term_relationships'], 'term_taxonomy_id' ) );

		// Stopping early.
		$seen = 0;
		Dump_Rows::scan(
			$dump,
			array( 'wp_postmeta' ),
			static function () use ( &$seen ): bool {
				++$seen;
				return false;
			}
		);
		Sf_Test::same( 1, $seen, 'stops when told:' );

		// A row with a value that is not a literal is refused when it is decoded.
		file_put_contents( $dump, "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES (9,CONCAT('a','b'));\n" );
		try {
			Dump_Rows::scan(
				$dump,
				array( 'wp_posts' ),
				static function ( string $table, array $columns, array $fields ): bool {
					Dump_Rows::row( $columns, $fields );
					return true;
				}
			);
			throw new RuntimeException( 'an expression was accepted' );
		} catch ( Archive_Exception $e ) {
			Sf_Test::same( Archive_Exception::CORRUPT, $e->getCode() );
		}
	}
);

exit( Sf_Test::finish( 'test-single-restore' ) );
