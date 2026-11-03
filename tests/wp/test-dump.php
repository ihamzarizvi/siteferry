<?php
/**
 * Database dump round trip inside WordPress. Run on a test site only:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-src eval-file tests/wp/test-dump.php
 *
 * Creates {prefix}sftest_* tables with awkward data (NULLs, every byte value, emoji, escapes,
 * a 3 MB value, generated columns, composite, binary and missing primary keys), dumps them in
 * one pass and in one-batch slices with junk appended between slices, checks both dumps are
 * identical, loads the dump into {prefix}sfcopy_* tables and compares every row and the table
 * definitions. All sftest_ and sfcopy_ tables are dropped at the end.
 *
 * The real importer (milestone 3) gets its own round-trip test; this one checks the dump.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Database\Dumper' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active (or loaded with --require).\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require dirname( __DIR__ ) . '/lib/fixtures.php';

use SiteFerry\Database\Dumper;
use SiteFerry\Jobs\Slice_Budget;

// Notices from SiteFerry or test code fail the test; WordPress core's own are left alone.
set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

echo 'Database dump tests (WordPress ', get_bloginfo( 'version' ), ', ', $GLOBALS['wpdb']->db_server_info(), ")\n";

global $wpdb;
$source = $wpdb->prefix . 'sftest_';
$copy   = $wpdb->prefix . 'sfcopy_';
$work   = Sf_Test::temp_dir( 'dump' );

/**
 * Runs SQL on the raw connection (bypassing wpdb's query filters) and fails on errors.
 *
 * @param string $sql Statement.
 */
function sf_sql( string $sql ): void {
	global $wpdb;
	if ( ! mysqli_query( $wpdb->dbh, $sql ) ) {
		throw new RuntimeException( 'SQL error: ' . mysqli_error( $wpdb->dbh ) . ' in ' . substr( $sql, 0, 200 ) );
	}
}

/**
 * Drops every table whose name starts with one of the test prefixes.
 *
 * @param string[] $prefixes Test prefixes.
 */
function sf_drop_test_tables( array $prefixes ): void {
	global $wpdb;
	foreach ( $prefixes as $prefix ) {
		if ( ! preg_match( '/_(sftest|sfcopy)_$/', $prefix ) ) {
			throw new RuntimeException( 'Refusing to drop tables with prefix ' . $prefix );
		}
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $table ) {
			sf_sql( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		}
	}
}

/**
 * Loads a SiteFerry dump, renaming tables from one prefix to another.
 *
 * Relies on the dump format: one statement per line except CREATE TABLE, every statement ending
 * in ";\n", values never containing raw newlines.
 *
 * @param string $file Dump file.
 * @param string $from Prefix in the dump.
 * @param string $to   Prefix to create.
 * @return int Statements run.
 */
function sf_load_dump( string $file, string $from, string $to ): int {
	$handle    = fopen( $file, 'rb' );
	$statement = '';
	$count     = 0;
	while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		if ( '' === $statement && ( 0 === strpos( $line, '--' ) || '' === trim( $line ) ) ) {
			continue;
		}
		$statement .= $line;
		if ( ";\n" === substr( $statement, -2 ) ) {
			sf_sql( str_replace( '`' . $from, '`' . $to, substr( $statement, 0, -2 ) ) );
			$statement = '';
			++$count;
		}
	}
	fclose( $handle );
	if ( '' !== trim( $statement ) ) {
		throw new RuntimeException( 'Unterminated statement at the end of the dump.' );
	}
	return $count;
}

/**
 * Short description of a value for mismatch messages: length and bytes around the first difference.
 *
 * @param string|null $value Value.
 * @param string|null $other Value it is compared with.
 */
function sf_describe( ?string $value, ?string $other ): string {
	if ( null === $value ) {
		return 'NULL';
	}
	$at = 0;
	if ( null !== $other ) {
		$limit = min( strlen( $value ), strlen( $other ) );
		while ( $at < $limit && $value[ $at ] === $other[ $at ] ) {
			++$at;
		}
	}
	return sprintf( '%d bytes, first difference at byte %d: hex %s', strlen( $value ), $at, bin2hex( substr( $value, max( 0, $at - 4 ), 12 ) ) );
}

/**
 * Dumps the test tables; returns [state, calls].
 *
 * @param string $prefix Table prefix to dump.
 * @param string $file   Dump file.
 * @param bool   $sliced One batch per call, with a JSON round trip and junk between calls.
 */
function sf_dump( string $prefix, string $file, bool $sliced ): array {
	global $wpdb;
	$dumper = new Dumper( $wpdb );
	$state  = Dumper::initial_state( $dumper->tables( $prefix ), array(), 97 );
	$calls  = 0;
	$warn   = static function ( string $message ) {
		throw new RuntimeException( 'Unexpected warning: ' . $message );
	};
	do {
		++$calls;
		$done = $dumper->run( $state, $file, $sliced ? new Slice_Budget( null, 1 ) : Slice_Budget::unlimited(), $warn );
		if ( $sliced && ! $done ) {
			// Plain json_encode, as Job::save() uses: it fails on bytes that are not UTF-8.
			$json = json_encode( $state ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			if ( false === $json ) {
				throw new RuntimeException( 'Dump state is not JSON-safe: ' . json_last_error_msg() );
			}
			$state = json_decode( $json, true );
			file_put_contents( $file, "INSERT INTO junk VALUES ('from a dead request');\n", FILE_APPEND );
		}
	} while ( ! $done );
	return array( $state, $calls );
}

$tables = array(
	'types'     => array( 'ORDER BY `id`' ),
	'composite' => array( 'ORDER BY `a`, `b`' ),
	'nopk'      => array( 'ORDER BY `x`, `y`' ),
	'binpk'     => array( 'ORDER BY `k`' ),
);

try {
	sf_drop_test_tables( array( $source, $copy ) );

	sf_sql(
		"CREATE TABLE `{$source}types` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`txt` LONGTEXT NULL,
			`vc` VARCHAR(191) NULL,
			`bin` VARBINARY(300) NULL,
			`blb` LONGBLOB NULL,
			`bt` BIT(8) NULL,
			`num` DECIMAL(30,10) NULL,
			`flt` DOUBLE NULL,
			`dt` DATETIME NULL,
			`ts` TIMESTAMP NULL DEFAULT NULL,
			`js` JSON NULL,
			`en` ENUM('a','b','c') NULL,
			`gen` VARCHAR(220) GENERATED ALWAYS AS (CONCAT('g:', COALESCE(`vc`, ''))) VIRTUAL,
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
	);
	sf_sql( "CREATE TABLE `{$source}composite` (`a` INT NOT NULL, `b` VARCHAR(50) NOT NULL, `c` TEXT NULL, PRIMARY KEY (`a`, `b`)) DEFAULT CHARSET=utf8mb4" );
	sf_sql( "CREATE TABLE `{$source}nopk` (`x` INT NULL, `y` TEXT NULL) DEFAULT CHARSET=utf8mb4" );
	sf_sql( "CREATE TABLE `{$source}binpk` (`k` VARBINARY(16) NOT NULL, `v` TEXT NULL, PRIMARY KEY (`k`)) DEFAULT CHARSET=utf8mb4" );

	$all_bytes = implode( '', array_map( 'chr', range( 0, 255 ) ) );
	$tricky    = "back\\slash 'single' \"double\" \0nul \r\ncrlf \x1a ctrl-z %s %d 100% _under ;\nsemicolon-newline\n-- not a comment;\n/* nor this */";
	$quote     = static function ( string $value ) use ( $wpdb ): string {
		return "'" . mysqli_real_escape_string( $wpdb->dbh, $value ) . "'";
	};

	sf_sql( "SET time_zone = '+05:00'" );
	sf_sql( "INSERT INTO `{$source}types` (`id`) VALUES (1)" );
	sf_sql( "INSERT INTO `{$source}types` (`txt`, `vc`, `js`, `en`) VALUES (" . $quote( "😀🎉 👩‍👩‍👧 日本語 עברית Ünïcödé" ) . ', ' . $quote( 'emoji 😀' ) . ", '{\"a\": \"😀\", \"b\": [1, 2]}', 'b')" );
	sf_sql( "INSERT INTO `{$source}types` (`txt`, `vc`) VALUES (" . $quote( $tricky ) . ', ' . $quote( "quote ' and \\" ) . ')' );
	sf_sql( "INSERT INTO `{$source}types` (`bin`, `blb`, `bt`) VALUES (0x" . bin2hex( $all_bytes ) . ', 0x' . bin2hex( random_bytes( 100000 ) ) . ", b'10101010')" );
	sf_sql( "INSERT INTO `{$source}types` (`txt`, `vc`, `bin`, `blb`) VALUES ('', '', '', '')" );
	sf_sql( "INSERT INTO `{$source}types` (`txt`) VALUES (" . $quote( str_repeat( "Large value with emoji 😀 and quotes ' \" \\ ;\n", 70000 ) ) . ')' );
	sf_sql( "INSERT INTO `{$source}types` (`num`, `flt`, `dt`, `ts`, `en`) VALUES ('-12345678901234567890.0123456789', 1.5e-10, '2024-02-29 23:59:59', '2024-06-01 12:00:00', 'c')" );
	sf_sql( "INSERT INTO `{$source}types` (`dt`, `txt`) VALUES ('0000-00-00 00:00:00', 'zero date')" );
	$values = array();
	for ( $i = 0; $i < 1500; $i++ ) {
		$values[] = sprintf( "('row %d', 'v%d')", $i, $i );
	}
	sf_sql( "INSERT INTO `{$source}types` (`txt`, `vc`) VALUES " . implode( ',', $values ) );
	sf_sql( "INSERT INTO `{$source}types` (`id`, `txt`) VALUES (18446744073709551000, 'near the top of BIGINT UNSIGNED')" );

	$values = array();
	for ( $i = 0; $i < 1300; $i++ ) {
		$values[] = sprintf( "(%d, 'k%d', %s)", $i % 7, $i, 0 === $i % 50 ? 'NULL' : "'c$i'" );
	}
	sf_sql( "INSERT INTO `{$source}composite` (`a`, `b`, `c`) VALUES " . implode( ',', $values ) );

	$values = array();
	for ( $i = 0; $i < 1200; $i++ ) {
		$values[] = 0 === $i % 100 ? '(NULL, NULL)' : sprintf( "(%d, 'dup %d')", $i % 300, $i % 300 );
	}
	sf_sql( "INSERT INTO `{$source}nopk` (`x`, `y`) VALUES " . implode( ',', $values ) );

	$values = array( "(0x00, 'zero')", "(0xff, 'ff')", "(0x000001, 'zero zero one')", "(0x7f80, 'boundary')" );
	for ( $i = 0; $i < 500; $i++ ) {
		$values[] = '(0x' . bin2hex( random_bytes( 16 ) ) . ", 'random $i')";
	}
	sf_sql( "INSERT IGNORE INTO `{$source}binpk` (`k`, `v`) VALUES " . implode( ',', $values ) );
	sf_sql( "SET time_zone = '+00:00'" );

	$single = $work . '/single.sql';
	$sliced = $work . '/sliced.sql';

	Sf_Test::run(
		'dump in one pass: only prefixed base tables, complete, with end marker',
		static function () use ( $source, $single ) {
			list( $state ) = sf_dump( $source, $single, false );
			Sf_Test::same( array( $source . 'binpk', $source . 'composite', $source . 'nopk', $source . 'types' ), array_column( $state['tables'], 'name' ) );
			Sf_Test::same( 1509 + 1300 + 1200 + 504, $state['rows_done'] );
			$text = (string) file_get_contents( $single );
			Sf_Test::same( Dumper::END_MARKER . "\n", substr( $text, -strlen( Dumper::END_MARKER ) - 1 ) );
			Sf_Test::ok( false === strpos( $text, '`gen`) VALUES' ) && false === strpos( $text, ', `gen`' ), 'generated column must not be inserted' );
		}
	);

	Sf_Test::run(
		'dump in one-batch slices with junk between slices is byte-identical',
		static function () use ( $source, $single, $sliced ) {
			list( , $calls ) = sf_dump( $source, $sliced, true );
			Sf_Test::ok( $calls > 40, "expected many slices, got $calls" );
			Sf_Test::same( hash_file( 'sha256', $single ), hash_file( 'sha256', $sliced ) );
		}
	);

	Sf_Test::run(
		'loading the dump reproduces every row and table definition exactly',
		static function () use ( $source, $copy, $single, $tables ) {
			global $wpdb;
			$count = sf_load_dump( $single, $source, $copy );
			Sf_Test::ok( $count > 10, 'statements run: ' . $count );
			$wpdb->set_sql_mode();
			sf_sql( "SET time_zone = '+00:00'" );

			// MySQL 8 prints "CHARACTER SET x COLLATE y" for columns of a re-created table where the
			// original shows "COLLATE y" only; both mean the same, so that wording is normalized away.
			$normalize = static function ( string $sql ): string {
				return (string) preg_replace( '/ CHARACTER SET \w+(?= COLLATE )/', '', $sql );
			};
			foreach ( $tables as $name => $order ) {
				$original = $wpdb->get_row( "SHOW CREATE TABLE `{$source}{$name}`", ARRAY_N );
				$loaded   = $wpdb->get_row( "SHOW CREATE TABLE `{$copy}{$name}`", ARRAY_N );
				Sf_Test::same( $normalize( str_replace( $source, $copy, $original[1] ) ), $normalize( $loaded[1] ), "$name definition:" );

				$a = $wpdb->get_results( "SELECT * FROM `{$source}{$name}` {$order[0]}", ARRAY_N );
				$b = $wpdb->get_results( "SELECT * FROM `{$copy}{$name}` {$order[0]}", ARRAY_N );
				Sf_Test::same( count( $a ), count( $b ), "$name row count:" );
				foreach ( $a as $index => $row ) {
					foreach ( $row as $column => $value ) {
						if ( $value !== $b[ $index ][ $column ] ) {
							throw new RuntimeException( sprintf( '%s row %d column %d differs: original %s, loaded %s', $name, $index, $column, sf_describe( $value, $b[ $index ][ $column ] ), sf_describe( $b[ $index ][ $column ], $value ) ) );
						}
					}
				}
			}
		}
	);

	Sf_Test::run(
		'timestamps survive a different session time zone',
		static function () use ( $copy ) {
			global $wpdb;
			sf_sql( "SET time_zone = '+05:00'" );
			Sf_Test::same( '2024-06-01 12:00:00', $wpdb->get_var( "SELECT `ts` FROM `{$copy}types` WHERE `ts` IS NOT NULL" ) );
			sf_sql( "SET time_zone = '+00:00'" );
		}
	);
} catch ( Throwable $e ) {
	echo 'Setup failed: ', $e->getMessage(), "\n";
	Sf_Test::run(
		'setup',
		static function () use ( $e ) {
			throw $e;
		}
	);
} finally {
	sf_drop_test_tables( array( $source, $copy ) );
	$wpdb->set_sql_mode();
}

exit( Sf_Test::finish( 'wp/test-dump' ) );
