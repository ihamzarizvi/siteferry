<?php
/**
 * Import step: load the dump into temporary tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Sql;
use SiteFerry\Database\Statement_Reader;
use SiteFerry\Database\Statement_Rewriter;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the dump statement by statement into tables named <temp prefix><name>, while the live
 * site keeps using its own tables. Afterwards the row count must match the manifest exactly.
 */
final class Database_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception On database errors or unexpected statements.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		$inspect = $job->state( 'inspect' );
		$state   = array_merge(
			array(
				'offset'     => 0,
				'statements' => 0,
				'tables'     => array(),
				'mapped'     => array(),
				'done'       => false,
			),
			$job->state( 'database' )
		);

		// A partial restore loads only the chosen tables (or none).
		$only = Import::table_suffixes( $job );
		if ( array() === $only ) {
			$state['done'] = true;
			$job->set_state( 'database', $state );
			$job->log( 'info', 'The database is not restored, as chosen.' );
			return true;
		}
		$temp = (string) $job->option( 'temp_prefix' );

		$rewriter = new Statement_Rewriter(
			$inspect['prefix'],
			$job->option( 'temp_prefix' ),
			Raw_Query::column( $wpdb, 'SHOW COLLATION' ),
			Raw_Query::column( $wpdb, 'SHOW CHARACTER SET' )
		);
		$zone     = Raw_Query::column( $wpdb, 'SELECT @@session.time_zone' );
		$reader   = new Statement_Reader( Extract_Step::staging( $job ) . '/' . Manifest::DATABASE, $state['offset'] );

		try {
			$this->prepare_session( $wpdb, $inspect['charset'] );
			while ( null !== ( $statement = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$rewritten = $rewriter->rewrite( $statement );
				if ( null !== $only && null !== $rewritten['table'] && ! in_array( substr( (string) $rewritten['table'], strlen( $temp ) ), $only, true ) ) {
					$rewritten['type'] = 'skip'; // A table that is not restored.
				}
				if ( 'skip' !== $rewritten['type'] ) {
					Raw_Query::run( $wpdb, $rewritten['sql'] );
				}
				if ( 'create' === $rewritten['type'] && ! in_array( $rewritten['table'], $state['tables'], true ) ) {
					$state['tables'][] = $rewritten['table'];
				}
				++$state['statements'];
				$state['offset'] = $reader->offset();
				$budget->consume( strlen( $statement ) );
				if ( $budget->exhausted() ) {
					break;
				}
			}
			$state['done'] = null === $statement;
		} finally {
			$reader->close();
			$this->restore_session( $wpdb, isset( $zone[0] ) ? (string) $zone[0] : 'SYSTEM' );
		}

		foreach ( $rewriter->mapped() as $from => $to ) {
			if ( ! isset( $state['mapped'][ $from ] ) ) {
				$state['mapped'][ $from ] = $to;
				$job->warn( sprintf( /* translators: 1: a collation or character set, 2: the one used instead */ __( 'This database server does not support %1$s; %2$s is used instead.', 'siteferry' ), $from, $to ) );
			}
		}

		if ( $state['done'] ) {
			$this->check_counts( $wpdb, $job, $state['tables'] );
		}
		$job->set_state( 'database', $state );

		$size = (int) $inspect['db_size'];
		$job->set_progress( sprintf( /* translators: %d: a number */ __( 'Loading the database: %d tables', 'siteferry' ), count( $state['tables'] ) ), min( $state['offset'], $size ), $size );
		if ( $state['done'] ) {
			$job->log( 'info', sprintf( 'Database loaded into %d temporary tables (%d statements).', count( $state['tables'] ), $state['statements'] ) );
		}
		return $state['done'];
	}

	/**
	 * Drops the temporary tables (only ever names with this job's random prefix).
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		global $wpdb;
		self::drop_prefixed( $wpdb, (string) $job->option( 'temp_prefix' ) );
	}

	/**
	 * Drops every table whose name starts with an import prefix ("sft"/"sfo" + 6 hex + "_").
	 *
	 * @param \wpdb  $db     Connection.
	 * @param string $prefix Prefix.
	 * @throws Job_Exception For anything that is not an import prefix.
	 */
	public static function drop_prefixed( $db, string $prefix ): void {
		if ( ! preg_match( '/^sf[to][a-f0-9]{6}_$/', $prefix ) ) {
			throw new Job_Exception( __( 'Refusing to drop tables with an unexpected prefix.', 'siteferry' ), Job_Exception::STEP );
		}
		$tables = Raw_Query::column( $db, 'SHOW TABLES LIKE ' . Raw_Query::quote( $db, addcslashes( $prefix, '_%\\' ) . '%' ) );
		if ( array() !== $tables ) {
			Raw_Query::run( $db, 'SET FOREIGN_KEY_CHECKS = 0' );
			Raw_Query::run( $db, 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( array( Sql::class, 'identifier' ), $tables ) ) );
			Raw_Query::run( $db, 'SET FOREIGN_KEY_CHECKS = 1' );
		}
	}

	/**
	 * Session settings for loading: no strict mode, no key checks, UTC, the dump's character set.
	 *
	 * @param \wpdb  $db      Connection.
	 * @param string $charset Character set of the dump.
	 */
	private function prepare_session( $db, string $charset ): void {
		$charset = preg_match( '/^[A-Za-z0-9_]+$/', $charset ) ? $charset : 'utf8mb4';
		$known   = array_map( 'strtolower', Raw_Query::column( $db, 'SHOW CHARACTER SET' ) );
		if ( ! in_array( strtolower( $charset ), $known, true ) ) {
			$charset = in_array( 'utf8mb4', $known, true ) ? 'utf8mb4' : 'utf8';
		}
		Raw_Query::run( $db, "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		Raw_Query::run( $db, 'SET FOREIGN_KEY_CHECKS = 0' );
		Raw_Query::run( $db, 'SET UNIQUE_CHECKS = 0' );
		Raw_Query::run( $db, "SET time_zone = '+00:00'" );
		Raw_Query::run( $db, 'SET NAMES ' . $charset );
	}

	/**
	 * Puts WordPress's session settings back.
	 *
	 * @param \wpdb  $db   Connection.
	 * @param string $zone Previous time zone.
	 */
	private function restore_session( $db, string $zone ): void {
		Raw_Query::run( $db, 'SET FOREIGN_KEY_CHECKS = 1' );
		Raw_Query::run( $db, 'SET UNIQUE_CHECKS = 1' );
		Raw_Query::run( $db, 'SET time_zone = ' . Raw_Query::quote( $db, $zone ) );
		$db->set_charset( $db->dbh );
		$db->set_sql_mode();
	}

	/**
	 * Compares the loaded rows and tables with the manifest.
	 *
	 * @param \wpdb    $db     Connection.
	 * @param Job      $job    The job.
	 * @param string[] $tables Temporary tables.
	 * @throws Job_Exception On a mismatch.
	 */
	private function check_counts( $db, Job $job, array $tables ): void {
		$inspect  = $job->state( 'inspect' );
		$expected = count( $inspect['tables'] );
		$want     = (int) $inspect['rows'];
		$chosen   = (array) $job->option( 'restore_tables', array() );
		if ( array() !== $chosen ) {
			// Only the chosen tables were loaded: compare with their counts in the manifest.
			$expected = count( $chosen );
			$want     = 0;
			foreach ( $chosen as $table ) {
				$want += isset( $inspect['table_rows'][ $table ] ) ? (int) $inspect['table_rows'][ $table ] : 0;
			}
		}
		if ( count( $tables ) !== $expected ) {
			throw new Job_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'The backup lists %1$d tables to load, but the dump created %2$d.', 'siteferry' ), $expected, count( $tables ) ), Job_Exception::STEP );
		}
		if ( array() !== $chosen && empty( $inspect['rows_exact'] ) ) {
			// Backups from SiteFerry before 0.12 list estimated rows per table; the dump itself was
			// verified by its checksum.
			$job->log( 'info', 'This backup lists only estimated row counts per table, so the rows of the chosen tables are not counted.' );
			return;
		}
		$rows = 0;
		foreach ( $tables as $table ) {
			$count = Raw_Query::column( $db, 'SELECT COUNT(*) FROM ' . Sql::identifier( $table ) );
			$rows += (int) $count[0];
		}
		if ( $rows !== $want ) {
			throw new Job_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'The backup has %1$d rows, but %2$d were loaded.', 'siteferry' ), $want, $rows ), Job_Exception::STEP );
		}
	}
}
