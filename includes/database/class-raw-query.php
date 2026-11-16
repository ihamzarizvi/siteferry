<?php
/**
 * Direct queries on WordPress's database connection.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Callers build statements from identifiers quoted with Sql::identifier() and values escaped with quote() (esc_sql()); restores and copies work on tables of any name, which prepare() cannot express. Nothing is cached: these read and change whole tables during an import.

use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the statements of an import, a restore or a staging copy through $wpdb.
 *
 * Every value in them is escaped with esc_sql() (quote() below) and every table or column name
 * with Sql::identifier(); binary values travel as hex literals. Errors become exceptions, so a
 * failed statement stops the job (and its rollback runs) instead of being printed.
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
		$quiet = $db->suppress_errors( true );
		$db->query( $sql );
		$db->suppress_errors( $quiet );
		self::check( $db );
		return (int) $db->rows_affected;
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
		$quiet = $db->suppress_errors( true );
		$rows  = $db->get_results( $sql, ARRAY_N );
		$db->suppress_errors( $quiet );
		self::check( $db );
		return is_array( $rows ) ? $rows : array();
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
	 * Single-quoted, escaped string literal (esc_sql()).
	 *
	 * @param \wpdb  $db    Connection.
	 * @param string $value Value.
	 */
	public static function quote( $db, string $value ): string {
		return "'" . esc_sql( $value ) . "'";
	}

	/**
	 * Throws the last database error, if there was one. With SAVEQUERIES on, the log of queries is
	 * emptied: an import sends many large statements, which would otherwise fill the memory.
	 *
	 * @param \wpdb $db Connection.
	 * @throws Job_Exception On a database error.
	 */
	private static function check( $db ): void {
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$db->queries = array();
		}
		if ( '' !== (string) $db->last_error ) {
			throw new Job_Exception( sprintf( /* translators: %s: the error message of the database server */ __( 'Database error: %s', 'siteferry' ), $db->last_error ), Job_Exception::STEP );
		}
	}
}
