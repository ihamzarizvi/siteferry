<?php
/**
 * SQL text helpers for dump files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Quoting for identifiers and literals written to dump files.
 *
 * Dump files are read back by SiteFerry's importer with a session sql_mode it controls (no
 * NO_BACKSLASH_ESCAPES), so string literals use backslash escapes like mysqldump. Queries sent to
 * the live database use $wpdb->prepare() instead.
 */
final class Sql {

	/**
	 * Backquoted identifier.
	 *
	 * @param string $name Table or column name.
	 */
	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Single-quoted string literal; binary-safe for UTF-8 and single-byte character sets.
	 *
	 * @param string $value Value.
	 */
	public static function string_literal( string $value ): string {
		return "'" . strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/**
	 * Hexadecimal literal for binary data ('' for empty values, which 0x cannot express).
	 *
	 * @param string $value Bytes.
	 */
	public static function hex_literal( string $value ): string {
		return '' === $value ? "''" : '0x' . bin2hex( $value );
	}

	/**
	 * How a column's values are read and written: 'number', 'bit', 'binary' or 'string'.
	 *
	 * @param string $type Column type from SHOW COLUMNS, e.g. "bigint(20) unsigned".
	 */
	public static function kind( string $type ): string {
		$base = strtolower( (string) preg_replace( '/[\s(].*$/s', '', trim( $type ) ) );
		if ( in_array( $base, array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year' ), true ) ) {
			return 'number';
		}
		if ( 'bit' === $base ) {
			return 'bit';
		}
		if ( in_array( $base, array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection', 'geomcollection' ), true ) ) {
			return 'binary';
		}
		return 'string';
	}

	/**
	 * SELECT expression for a column.
	 *
	 * BIT values come back from mysqlnd as decimal text ("170") rather than raw bytes, depending on
	 * the driver; reading them as unsigned integers gives the same answer everywhere.
	 *
	 * @param string $column Column name.
	 * @param string $kind   Result of kind().
	 */
	public static function select_expression( string $column, string $kind ): string {
		return 'bit' === $kind
			? 'CAST(' . self::identifier( $column ) . ' AS UNSIGNED) AS ' . self::identifier( $column )
			: self::identifier( $column );
	}

	/**
	 * Literal for one value of a column kind.
	 *
	 * @param string|null $value Value as returned by mysqli (null for SQL NULL).
	 * @param string      $kind  Result of kind().
	 */
	public static function literal( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'binary' === $kind ) {
			return self::hex_literal( $value );
		}
		if ( ( 'number' === $kind || 'bit' === $kind ) && preg_match( '/^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?$/', $value ) ) {
			return $value;
		}
		return self::string_literal( $value );
	}
}
