<?php
/**
 * SQL literal encoding used in dump files.
 *
 * Run: php tests/test-sql.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Database\Sql;

echo "SQL encoding tests\n";

Sf_Test::run(
	'column types map to the right kind',
	static function () {
		$cases = array(
			'bigint(20) unsigned'       => 'number',
			'int'                       => 'number',
			'decimal(30,10)'            => 'number',
			'double'                    => 'number',
			'year'                      => 'number',
			'bit(1)'                    => 'bit',
			'BIT(8)'                    => 'bit',
			'varbinary(255)'            => 'binary',
			'longblob'                  => 'binary',
			'point'                     => 'binary',
			'varchar(191)'              => 'string',
			'longtext'                  => 'string',
			"enum('a','b')"             => 'string',
			'json'                      => 'string',
			'datetime'                  => 'string',
			'timestamp'                 => 'string',
		);
		foreach ( $cases as $type => $kind ) {
			Sf_Test::same( $kind, Sql::kind( $type ), $type );
		}
	}
);

Sf_Test::run(
	'literals: NULL, numbers, BIT as integers, binary as hex, strings escaped',
	static function () {
		Sf_Test::same( 'NULL', Sql::literal( null, 'string' ) );
		Sf_Test::same( '-12.5', Sql::literal( '-12.5', 'number' ) );
		Sf_Test::same( '1.5e-10', Sql::literal( '1.5e-10', 'number' ) );
		Sf_Test::same( "'12abc'", Sql::literal( '12abc', 'number' ), 'non-numeric text in a number column stays quoted:' );
		Sf_Test::same( '170', Sql::literal( '170', 'bit' ), 'BIT values are written as integers:' );
		Sf_Test::same( '0', Sql::literal( '0', 'bit' ) );
		Sf_Test::same( '0x00ff41', Sql::literal( "\x00\xffA", 'binary' ) );
		Sf_Test::same( "''", Sql::literal( '', 'binary' ), 'empty binary:' );
		Sf_Test::same( "'a\\\\b\\'c\\\"d\\0e\\nf\\rg\\Zh % _'", Sql::literal( "a\\b'c\"d\0e\nf\rg\x1ah % _", 'string' ) );
		Sf_Test::same( "'😀 日本語'", Sql::literal( '😀 日本語', 'string' ), 'UTF-8 passes through:' );
	}
);

Sf_Test::run(
	'identifiers are backquoted; BIT columns are read as unsigned integers',
	static function () {
		Sf_Test::same( '`wp_posts`', Sql::identifier( 'wp_posts' ) );
		Sf_Test::same( '`a``b`', Sql::identifier( 'a`b' ) );
		Sf_Test::same( '`flag`', Sql::select_expression( 'flag', 'number' ) );
		Sf_Test::same( 'CAST(`flag` AS UNSIGNED) AS `flag`', Sql::select_expression( 'flag', 'bit' ) );
	}
);

exit( Sf_Test::finish( 'test-sql' ) );
