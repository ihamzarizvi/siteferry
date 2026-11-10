<?php
/**
 * Staging copy, step 2: the database tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Sql;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Copies every table with the site's prefix to the staging prefix inside the database server:
 * CREATE TABLE ... LIKE, then INSERT ... SELECT. Tables with a one-column whole-number primary key
 * go in batches above the highest key already copied, so an interrupted slice simply continues;
 * other tables are copied in one statement (emptied first, so a repeat starts clean). Generated
 * columns are left to the server.
 */
final class Tables_Step implements Step {

	const STEP = 'staging.tables';

	/** Rows per batch. */
	const BATCH = 5000;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception On database errors.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;
		$record = (array) $job->option( 'staging' );
		$live   = (string) $job->option( 'live_prefix' );
		$prefix = (string) $record['prefix'];
		$state  = $job->state( 'staging_tables' );
		if ( ! isset( $state['tables'] ) ) {
			$state = array(
				'tables' => array_column( Staging::live_tables( $wpdb, $live ), 0 ),
				'index'  => 0,
				'rows'   => 0,
				'made'   => array(),
			);
		}
		$count = count( $state['tables'] );
		while ( $state['index'] < $count && ! $budget->exhausted() ) {
			$from = (string) $state['tables'][ $state['index'] ];
			$to   = $prefix . substr( $from, strlen( $live ) );
			if ( 0 !== strpos( $to, $prefix ) || $to === $from ) {
				throw new Job_Exception( __( 'A staging table name would match a live table; stopped.', 'siteferry' ), Job_Exception::STEP );
			}
			Raw_Query::run( $wpdb, 'CREATE TABLE IF NOT EXISTS ' . Sql::identifier( $to ) . ' LIKE ' . Sql::identifier( $from ) );
			$state['made'][ $to ] = true;
			if ( $this->copy( $wpdb, $from, $to, $budget, $state ) ) {
				++$state['index'];
			}
			$job->set_state( 'staging_tables', $state );
			$job->set_progress( sprintf( /* translators: 1: a number, 2: a number */ __( 'Copying tables: %1$d of %2$d', 'siteferry' ), min( $state['index'] + 1, $count ), $count ), $state['index'], $count );
		}
		$job->set_state( 'staging_tables', $state );
		if ( $state['index'] >= $count ) {
			$job->log( 'info', sprintf( 'Staging: %d tables, %d rows copied.', $count, (int) $state['rows'] ) );
			return true;
		}
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}

	/**
	 * Copies rows of one table; true when the table is complete.
	 *
	 * @param \wpdb        $db     Connection.
	 * @param string       $from   Live table.
	 * @param string       $to     Staging table.
	 * @param Slice_Budget $budget Work limit.
	 * @param array        $state  Progress.
	 */
	private function copy( $db, string $from, string $to, Slice_Budget $budget, array &$state ): bool {
		// SHOW COLUMNS: 0 Field, 1 Type, 5 Extra.
		$columns = array();
		$types   = array();
		foreach ( Raw_Query::rows( $db, 'SHOW COLUMNS FROM ' . Sql::identifier( $from ) ) as $column ) {
			$types[ (string) $column[0] ] = strtolower( (string) $column[1] );
			if ( false === stripos( (string) ( isset( $column[5] ) ? $column[5] : '' ), 'GENERATED' ) ) {
				$columns[] = Sql::identifier( (string) $column[0] );
			}
		}
		$list = implode( ', ', $columns );
		// SHOW KEYS: 2 Key_name, 4 Column_name.
		$primary = array();
		foreach ( Raw_Query::rows( $db, 'SHOW KEYS FROM ' . Sql::identifier( $from ) ) as $key ) {
			if ( 'PRIMARY' === (string) $key[2] ) {
				$primary[] = (string) $key[4];
			}
		}
		$sliced = 1 === count( $primary ) && isset( $types[ $primary[0] ] ) && preg_match( '/^(tiny|small|medium|big)?int\b/', $types[ $primary[0] ] );
		if ( ! $sliced ) {
			Raw_Query::run( $db, 'TRUNCATE TABLE ' . Sql::identifier( $to ) );
			$state['rows'] += Raw_Query::run( $db, 'INSERT INTO ' . Sql::identifier( $to ) . " ($list) SELECT $list FROM " . Sql::identifier( $from ) );
			return true;
		}
		$key = Sql::identifier( $primary[0] );
		while ( true ) {
			$last  = Raw_Query::column( $db, "SELECT MAX($key) FROM " . Sql::identifier( $to ) );
			$after = isset( $last[0] ) && null !== $last[0] ? ' WHERE ' . $key . ' > ' . (int) $last[0] : '';
			$rows  = Raw_Query::run( $db, 'INSERT INTO ' . Sql::identifier( $to ) . " ($list) SELECT $list FROM " . Sql::identifier( $from ) . $after . " ORDER BY $key LIMIT " . self::BATCH );
			$state['rows'] += $rows;
			if ( $rows < self::BATCH ) {
				return true;
			}
			if ( $budget->exhausted() ) {
				return false;
			}
		}
	}
}
