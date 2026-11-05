<?php
/**
 * Splitting INSERT value lists.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Splits "(..),(..)" into rows and a row into its literal values, respecting quoted strings with
 * backslash and doubled-quote escapes. Values are returned as written (still quoted).
 */
final class Sql_Values {

	/**
	 * The rows of a VALUES list, each without its parentheses.
	 *
	 * @param string $values Text after "VALUES".
	 * @return string[]
	 * @throws Archive_Exception If the list is malformed.
	 */
	public static function rows( string $values ): array {
		$rows   = array();
		$length = strlen( $values );
		$i      = 0;
		while ( true ) {
			$i += strspn( $values, " \t\r\n,", $i );
			if ( $i >= $length ) {
				return $rows;
			}
			if ( '(' !== $values[ $i ] ) {
				throw self::malformed();
			}
			$start = $i + 1;
			$depth = 1;
			$j     = $start;
			while ( $depth > 0 ) {
				$j += strcspn( $values, "()'\"", $j );
				if ( $j >= $length ) {
					throw self::malformed();
				}
				$char = $values[ $j ];
				if ( '(' === $char ) {
					++$depth;
					++$j;
				} elseif ( ')' === $char ) {
					--$depth;
					++$j;
				} else {
					$j = self::after_string( $values, $j );
				}
			}
			$rows[] = substr( $values, $start, $j - 1 - $start );
			$i      = $j;
		}
	}

	/**
	 * The values of one row, trimmed and still quoted.
	 *
	 * @param string $row Row text without parentheses.
	 * @return string[]
	 * @throws Archive_Exception If a string is not closed.
	 */
	public static function fields( string $row ): array {
		$fields = array();
		$length = strlen( $row );
		$start  = 0;
		$depth  = 0;
		$i      = 0;
		while ( $i < $length ) {
			$i += strcspn( $row, ",()'\"", $i );
			if ( $i >= $length ) {
				break;
			}
			$char = $row[ $i ];
			if ( "'" === $char || '"' === $char ) {
				$i = self::after_string( $row, $i );
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( 0 === $depth ) {
				$fields[] = trim( substr( $row, $start, $i - $start ) );
				$start    = $i + 1;
			}
			++$i;
		}
		$fields[] = trim( substr( $row, $start ) );
		return $fields;
	}

	/**
	 * Column names of a CREATE TABLE statement, in order.
	 *
	 * @param string $create Statement.
	 * @return string[]
	 */
	public static function columns( string $create ): array {
		$open = strpos( $create, '(' );
		if ( false === $open ) {
			return array();
		}
		preg_match_all( '/^\s*`((?:[^`]|``)+)`\s/m', substr( $create, $open + 1 ), $match );
		return array_map(
			static function ( string $name ): string {
				return str_replace( '``', '`', $name );
			},
			$match[1]
		);
	}

	/**
	 * Index just after the quoted string starting at $at.
	 *
	 * @param string $text Text.
	 * @param int    $at   Position of the opening quote.
	 * @throws Archive_Exception If the string is not closed.
	 */
	private static function after_string( string $text, int $at ): int {
		$quote  = $text[ $at ];
		$length = strlen( $text );
		$j      = $at + 1;
		while ( true ) {
			$j += strcspn( $text, $quote . '\\', $j );
			if ( $j >= $length ) {
				throw self::malformed();
			}
			if ( '\\' === $text[ $j ] ) {
				$j += 2;
				continue;
			}
			if ( $j + 1 < $length && $quote === $text[ $j + 1 ] ) {
				$j += 2;
				continue;
			}
			return $j + 1;
		}
	}

	/**
	 * Exception for a broken value list.
	 */
	private static function malformed(): Archive_Exception {
		return new Archive_Exception( __( 'An INSERT statement in the database dump is malformed.', 'siteferry' ), Archive_Exception::CORRUPT );
	}
}
