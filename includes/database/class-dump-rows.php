<?php
/**
 * Reading single rows out of a SiteFerry database dump.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Wpress\Sql_Values;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the INSERT statements of some tables in a dump and turns their values into PHP values.
 *
 * Values are decoded strictly, in the forms SiteFerry's dumper writes (Sql::literal): NULL,
 * numbers, quoted strings with MySQL's backslash escapes or doubled quotes, and 0x hex. Anything
 * else is refused. The decoded values are written to the live database through $wpdb with
 * placeholders, never as SQL text.
 */
final class Dump_Rows {

	/** Backslash escapes MySQL understands in string literals. */
	const ESCAPES = array(
		'0'  => "\0",
		"'"  => "'",
		'"'  => '"',
		'b'  => "\x08",
		'n'  => "\n",
		'r'  => "\r",
		't'  => "\t",
		'Z'  => "\x1a",
		'\\' => '\\',
		'%'  => '\\%',
		'_'  => '\\_',
	);

	/**
	 * Goes through a dump and calls $visit for every row of the wanted tables.
	 *
	 * @param string   $dump   Dump file.
	 * @param string[] $tables Full table names.
	 * @param callable $visit  function ( string $table, array $columns, array $fields ): bool — the
	 *                         row's raw (still quoted) values in column order; return false to stop.
	 * @throws Archive_Exception If the dump cannot be read or a statement is malformed.
	 */
	public static function scan( string $dump, array $tables, callable $visit ): void {
		$heads = array();
		foreach ( $tables as $table ) {
			$heads[ 'INSERT INTO ' . Sql::identifier( $table ) . ' (' ] = $table;
		}
		$reader = new Statement_Reader( $dump );
		try {
			while ( null !== ( $statement = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( 0 !== strncmp( $statement, 'INSERT INTO ', 12 ) ) {
					continue;
				}
				foreach ( $heads as $head => $table ) {
					if ( 0 !== strncmp( $statement, $head, strlen( $head ) ) ) {
						continue;
					}
					list( $columns, $values ) = self::split( $statement, strlen( $head ) );
					foreach ( Sql_Values::rows( $values ) as $row ) {
						$fields = Sql_Values::fields( $row );
						if ( count( $fields ) !== count( $columns ) ) {
							throw self::malformed();
						}
						if ( false === $visit( $table, $columns, $fields ) ) {
							return;
						}
					}
					break;
				}
			}
		} finally {
			$reader->close();
		}
	}

	/**
	 * A whole row as column => value.
	 *
	 * @param array $columns Column names.
	 * @param array $fields  Raw values.
	 * @throws Archive_Exception On a value that is not a plain literal.
	 */
	public static function row( array $columns, array $fields ): array {
		$out = array();
		foreach ( $columns as $i => $column ) {
			$out[ $column ] = self::literal( (string) $fields[ $i ] );
		}
		return $out;
	}

	/**
	 * The PHP value of one literal: null, or a string (numbers stay as their text).
	 *
	 * @param string $raw Literal as written in the dump.
	 * @throws Archive_Exception On anything that is not NULL, a number, a quoted string or 0x hex.
	 */
	public static function literal( string $raw ): ?string {
		$raw = trim( $raw );
		if ( 'NULL' === strtoupper( $raw ) ) {
			return null;
		}
		if ( preg_match( '/^-?[0-9]+(\.[0-9]+)?([eE][-+]?[0-9]+)?$/', $raw ) ) {
			return $raw;
		}
		if ( preg_match( '/^0x([0-9A-Fa-f]*)$/', $raw, $match ) ) {
			if ( 0 !== strlen( $match[1] ) % 2 ) {
				throw self::malformed();
			}
			return (string) hex2bin( $match[1] );
		}
		$length = strlen( $raw );
		if ( $length < 2 || ( "'" !== $raw[0] && '"' !== $raw[0] ) || $raw[ $length - 1 ] !== $raw[0] ) {
			throw self::malformed();
		}
		$quote = $raw[0];
		$out   = '';
		$i     = 1;
		$end   = $length - 1;
		while ( $i < $end ) {
			$run  = strcspn( $raw, '\\' . $quote, $i, $end - $i );
			$out .= substr( $raw, $i, $run );
			$i   += $run;
			if ( $i >= $end ) {
				break;
			}
			$char = $raw[ $i ];
			if ( '\\' === $char ) {
				if ( $i + 1 >= $end ) {
					throw self::malformed();
				}
				$next = $raw[ $i + 1 ];
				// An unknown escape stands for the character itself, as in MySQL.
				$out .= isset( self::ESCAPES[ $next ] ) ? self::ESCAPES[ $next ] : $next;
				$i   += 2;
				continue;
			}
			// A quote inside the string must be doubled.
			if ( $i + 1 < $end && $quote === $raw[ $i + 1 ] ) {
				$out .= $quote;
				$i   += 2;
				continue;
			}
			throw self::malformed();
		}
		return $out;
	}

	/**
	 * The column names and the VALUES text of an INSERT, from just after "INSERT INTO `t` (".
	 *
	 * @param string $statement Statement.
	 * @param int    $at        Offset after the opening parenthesis of the column list.
	 * @return array{0:string[],1:string}
	 * @throws Archive_Exception If the statement is malformed.
	 */
	private static function split( string $statement, int $at ): array {
		$columns = array();
		$length  = strlen( $statement );
		$i       = $at;
		while ( true ) {
			$i += strspn( $statement, " \t\r\n,", $i );
			if ( $i >= $length ) {
				throw self::malformed();
			}
			if ( ')' === $statement[ $i ] ) {
				++$i;
				break;
			}
			if ( '`' !== $statement[ $i ] ) {
				throw self::malformed();
			}
			$name = '';
			++$i;
			while ( true ) {
				$close = strpos( $statement, '`', $i );
				if ( false === $close ) {
					throw self::malformed();
				}
				$name .= substr( $statement, $i, $close - $i );
				if ( $close + 1 < $length && '`' === $statement[ $close + 1 ] ) {
					$name .= '`';
					$i     = $close + 2;
					continue;
				}
				$i = $close + 1;
				break;
			}
			$columns[] = $name;
		}
		$i += strspn( $statement, " \t\r\n", $i );
		if ( 0 !== strncasecmp( substr( $statement, $i, 6 ), 'VALUES', 6 ) || array() === $columns ) {
			throw self::malformed();
		}
		return array( $columns, (string) substr( $statement, $i + 6 ) );
	}

	/**
	 * Exception for a statement or value that cannot be read.
	 */
	private static function malformed(): Archive_Exception {
		return new Archive_Exception( __( 'A row in the backup\'s database dump cannot be read.', 'siteferry' ), Archive_Exception::CORRUPT );
	}
}
