<?php
/**
 * Resumable search and replace over database tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a Replacer to the text columns of a table, one batch of rows at a time.
 *
 * Only rows that may contain a search string are fetched: the WHERE clause uses LIKE with a
 * reduced set of needles (a search string that contains another is covered by the shorter one),
 * so no candidate is missed. Rows are paged by primary key and updated by primary key. Tables
 * without a primary key fall back to SQL REPLACE() on values that do not look serialized.
 */
final class Table_Replacer {

	/** Candidate rows fetched per batch. */
	const BATCH_ROWS = 200;

	/** Column types that hold text. */
	const TEXT_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json' );

	/**
	 * Connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Replacer.
	 *
	 * @var Replacer
	 */
	private $replacer;

	/**
	 * Constructor.
	 *
	 * @param \wpdb    $db       Connection.
	 * @param Replacer $replacer Replacements to apply.
	 */
	public function __construct( $db, Replacer $replacer ) {
		$this->db       = $db;
		$this->replacer = $replacer;
	}

	/**
	 * Plans one table, or returns null if it has nothing to search.
	 *
	 * @param string   $table Table name.
	 * @param string[] $skip  Column names to leave alone.
	 * @return array|null JSON-safe plan for run().
	 */
	public function plan( string $table, array $skip = array() ): ?array {
		if ( array() === $this->replacer->searches() ) {
			return null;
		}

		$columns = array();
		$kinds   = array();
		foreach ( Raw_Query::rows( $this->db, 'SHOW FULL COLUMNS FROM ' . Sql::identifier( $table ) ) as $column ) {
			list( $name, $type ) = array( (string) $column[0], strtolower( (string) $column[1] ) );
			$kinds[ $name ]      = Sql::kind( $type );
			$base                = (string) preg_replace( '/[\s(].*$/s', '', $type );
			if ( in_array( $base, self::TEXT_TYPES, true ) && ! preg_match( '/\bGENERATED\b/i', (string) $column[6] ) && ! in_array( $name, $skip, true ) ) {
				$columns[] = $name;
			}
		}
		if ( array() === $columns ) {
			return null;
		}

		$primary = array();
		foreach ( Raw_Query::rows( $this->db, 'SHOW KEYS FROM ' . Sql::identifier( $table ) . " WHERE Key_name = 'PRIMARY'" ) as $key ) {
			$primary[ (int) $key[3] ] = (string) $key[4];
		}
		ksort( $primary );
		$primary = array_values( $primary );

		$key_kinds = array();
		foreach ( $primary as $column ) {
			$key_kinds[] = $kinds[ $column ];
		}

		return array(
			'table'   => $table,
			'columns' => $columns,
			'primary' => $primary,
			'kinds'   => $key_kinds,
			'last'    => null,
			'rows'    => 0,
		);
	}

	/**
	 * Processes one batch.
	 *
	 * @param array $plan In/out: plan from plan().
	 * @return bool True when the table is finished.
	 */
	public function run( array &$plan ): bool {
		if ( array() === $plan['primary'] ) {
			$plan['rows'] += $this->replace_without_key( $plan );
			return true;
		}

		$select = array_merge( $plan['primary'], $plan['columns'] );
		$where  = array( $this->match_condition( $plan['columns'] ) );
		if ( null !== $plan['last'] ) {
			$where[] = $this->after_key( $plan );
		}
		$rows = Raw_Query::rows(
			$this->db,
			'SELECT ' . implode( ', ', array_map( array( Sql::class, 'identifier' ), $select ) )
			. ' FROM ' . Sql::identifier( $plan['table'] )
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY ' . implode( ', ', array_map( array( Sql::class, 'identifier' ), $plan['primary'] ) )
			. ' LIMIT ' . self::BATCH_ROWS
		);

		$key_count = count( $plan['primary'] );
		foreach ( $rows as $row ) {
			$sets = array();
			foreach ( $plan['columns'] as $index => $column ) {
				$value = $row[ $key_count + $index ];
				if ( null === $value ) {
					continue;
				}
				$new = $this->replacer->replace( $value );
				if ( $new !== $value ) {
					$sets[] = Sql::identifier( $column ) . ' = ' . Raw_Query::quote( $this->db, $new );
				}
			}
			if ( array() !== $sets ) {
				Raw_Query::run(
					$this->db,
					'UPDATE ' . Sql::identifier( $plan['table'] ) . ' SET ' . implode( ', ', $sets ) . ' WHERE ' . $this->key_equals( $plan, $row )
				);
				++$plan['rows'];
			}
		}

		if ( array() !== $rows ) {
			$last         = end( $rows );
			$plan['last'] = array();
			for ( $i = 0; $i < $key_count; $i++ ) {
				$plan['last'][] = bin2hex( (string) $last[ $i ] );
			}
		}
		return count( $rows ) < self::BATCH_ROWS;
	}

	/**
	 * Search strings reduced to the shortest ones that still catch every match.
	 *
	 * @param string[] $searches Search strings.
	 * @return string[]
	 */
	public static function needles( array $searches ): array {
		usort(
			$searches,
			static function ( string $a, string $b ): int {
				return strlen( $a ) - strlen( $b );
			}
		);
		$needles = array();
		foreach ( $searches as $search ) {
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $search, $needle ) ) {
					continue 2;
				}
			}
			$needles[] = $search;
		}
		return $needles;
	}

	/**
	 * (col LIKE '%n1%' OR col LIKE '%n2%' OR ...) over the text columns.
	 *
	 * @param string[] $columns Columns.
	 */
	private function match_condition( array $columns ): string {
		$likes = array();
		foreach ( self::needles( $this->replacer->searches() ) as $needle ) {
			$pattern = Raw_Query::quote( $this->db, '%' . addcslashes( $needle, '_%\\' ) . '%' );
			foreach ( $columns as $column ) {
				$likes[] = Sql::identifier( $column ) . ' LIKE ' . $pattern;
			}
		}
		return '(' . implode( ' OR ', $likes ) . ')';
	}

	/**
	 * Rows after the last key of the previous batch.
	 *
	 * @param array $plan Plan.
	 */
	private function after_key( array $plan ): string {
		$columns = array();
		$values  = array();
		foreach ( $plan['primary'] as $index => $column ) {
			$columns[] = Sql::identifier( $column );
			$values[]  = $this->key_literal( (string) hex2bin( $plan['last'][ $index ] ), $plan['kinds'][ $index ] );
		}
		return 1 === count( $columns )
			? $columns[0] . ' > ' . $values[0]
			: '(' . implode( ', ', $columns ) . ') > (' . implode( ', ', $values ) . ')';
	}

	/**
	 * WHERE condition matching one row by its primary key.
	 *
	 * @param array $plan Plan.
	 * @param array $row  Row (primary key columns first).
	 */
	private function key_equals( array $plan, array $row ): string {
		$parts = array();
		foreach ( $plan['primary'] as $index => $column ) {
			$parts[] = Sql::identifier( $column ) . ' = ' . $this->key_literal( (string) $row[ $index ], $plan['kinds'][ $index ] );
		}
		return implode( ' AND ', $parts );
	}

	/**
	 * Literal for a key value.
	 *
	 * @param string $value Value.
	 * @param string $kind  Sql::kind().
	 */
	private function key_literal( string $value, string $kind ): string {
		if ( 'binary' === $kind ) {
			return Sql::hex_literal( $value );
		}
		if ( ( 'number' === $kind || 'bit' === $kind ) && preg_match( '/^-?[0-9]+(\.[0-9]+)?$/', $value ) ) {
			return $value;
		}
		return Raw_Query::quote( $this->db, $value );
	}

	/**
	 * Fallback for tables without a primary key: REPLACE() in SQL on values that do not look
	 * serialized (serialized ones cannot be fixed safely without a key and are left alone).
	 *
	 * @param array $plan Plan.
	 * @return int Rows changed.
	 */
	private function replace_without_key( array $plan ): int {
		$pairs = $this->replacer->pairs();
		uksort(
			$pairs,
			static function ( $a, $b ): int {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);

		$changed = 0;
		$table   = Sql::identifier( $plan['table'] );
		foreach ( $plan['columns'] as $column ) {
			$field = Sql::identifier( $column );
			foreach ( $pairs as $search => $replace ) {
				$search = (string) $search;
				$changed += Raw_Query::run(
					$this->db,
					"UPDATE $table SET $field = REPLACE($field, " . Raw_Query::quote( $this->db, $search ) . ', ' . Raw_Query::quote( $this->db, $replace ) . ')'
					. " WHERE $field LIKE " . Raw_Query::quote( $this->db, '%' . addcslashes( $search, '_%\\' ) . '%' )
					. " AND $field NOT REGEXP '^[aOs]:[0-9]+:'"
				);
			}
		}
		return $changed;
	}
}
