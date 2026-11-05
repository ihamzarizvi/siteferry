<?php
/**
 * Direct queries on WordPress's database connection.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Runs queries on $wpdb's mysqli connection without wpdb's query filters.
 *
 * wpdb::query() may strip or reject text it considers invalid for a column's character set and
 * logs every query when SAVEQUERIES is on; an import must store values byte for byte and handles
 * large statements, so it talks to the connection directly. Values are always escaped with the
 * connection's own escaping, and identifiers with Sql::identifier().
 */
final class Raw_Query {

	/**
	 * Runs a statement that returns no rows.
	 *
	 * @param \wpdb  $db  Connection.
	 * @param string $sql Statement.
	 * @return int Affected rows.
	 * @throws Job_Exception On database errors.
	 */
	public static function run( $db, string $sql ): int {
		$link = self::link( $db );
		if ( null === $link ) {
			$db->query( $sql );
			if ( '' !== $db->last_error ) {
				throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), $db->last_error ), Job_Exception::STEP );
			}
			return (int) $db->rows_affected;
		}
		try {
			$ok = mysqli_query( $link, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), $e->getMessage() ), Job_Exception::STEP );
		}
		if ( false === $ok ) {
			throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), mysqli_error( $link ) ), Job_Exception::STEP );
		}
		if ( $ok instanceof \mysqli_result ) {
			mysqli_free_result( $ok );
		}
		return (int) mysqli_affected_rows( $link );
	}

	/**
	 * Runs a query and returns all rows as numeric arrays (strings and nulls).
	 *
	 * @param \wpdb  $db  Connection.
	 * @param string $sql Query.
	 * @return array<int,array<int,string|null>>
	 * @throws Job_Exception On database errors.
	 */
	public static function rows( $db, string $sql ): array {
		$link = self::link( $db );
		if ( null === $link ) {
			$rows = $db->get_results( $sql, ARRAY_N );
			if ( '' !== $db->last_error ) {
				throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), $db->last_error ), Job_Exception::STEP );
			}
			return is_array( $rows ) ? $rows : array();
		}
		try {
			$result = mysqli_query( $link, $sql );
		} catch ( \mysqli_sql_exception $e ) {
			throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), $e->getMessage() ), Job_Exception::STEP );
		}
		if ( ! $result instanceof \mysqli_result ) {
			throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), mysqli_error( $link ) ), Job_Exception::STEP );
		}
		$rows = array();
		while ( null !== ( $row = mysqli_fetch_row( $result ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$rows[] = $row;
		}
		mysqli_free_result( $result );
		return $rows;
	}

	/**
	 * First column of every row.
	 *
	 * @param \wpdb  $db  Connection.
	 * @param string $sql Query.
	 * @return array<int,string|null>
	 */
	public static function column( $db, string $sql ): array {
		return array_map(
			static function ( array $row ) {
				return $row[0];
			},
			self::rows( $db, $sql )
		);
	}

	/**
	 * Single-quoted, escaped string literal for this connection.
	 *
	 * @param \wpdb  $db    Connection.
	 * @param string $value Value.
	 */
	public static function quote( $db, string $value ): string {
		$link = self::link( $db );
		return null === $link
			? "'" . esc_sql( $value ) . "'"
			: "'" . mysqli_real_escape_string( $link, $value ) . "'";
	}

	/**
	 * The mysqli connection, or null if wpdb uses something else (a drop-in).
	 *
	 * @param \wpdb $db Connection.
	 * @return \mysqli|null
	 */
	private static function link( $db ) {
		return isset( $db->dbh ) && $db->dbh instanceof \mysqli ? $db->dbh : null;
	}
}
