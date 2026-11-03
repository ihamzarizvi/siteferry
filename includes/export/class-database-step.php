<?php
/**
 * Export step: dump the database.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

use SiteFerry\Database\Dumper;
use SiteFerry\Database\Sql;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Writes database.sql in the job folder.
 *
 * SiteFerry's own options (settings and encrypted storage credentials) are never exported.
 */
final class Database_Step implements Step {

	/**
	 * Whether this process holds an open consistent-snapshot transaction (WP-CLI only).
	 *
	 * @var bool
	 */
	private static $snapshot = false;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		$dumper = new Dumper( $wpdb );
		$state  = $job->state( 'database' );
		if ( array() === $state ) {
			$prefix = $job->option( 'site' )['prefix'];
			$tables = $dumper->tables( $prefix, $job->option( 'exclude_tables', array() ) );
			$state  = Dumper::initial_state( $tables, self::filters( $job, $prefix ) );
			$job->log( 'info', sprintf( 'Dumping %d tables with the prefix %s.', count( $tables ), $prefix ) );
		}

		// In one long WP-CLI process, read every table from the same InnoDB snapshot.
		if ( $job->option( 'snapshot' ) && ! self::$snapshot ) {
			$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
			$wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
			self::$snapshot = true;
		}

		try {
			$done = $dumper->run( $state, $job->path( 'database.sql' ), $budget, array( $job, 'warn' ) );
		} catch ( \Throwable $e ) {
			$this->end_snapshot();
			throw $e;
		}
		if ( $done ) {
			$this->end_snapshot();
		}
		$job->set_state( 'database', $state );

		$count = count( $state['tables'] );
		$index = min( $state['index'], max( 0, $count - 1 ) );
		$job->set_progress(
			$done
				? sprintf( 'Database exported: %d tables, %s rows', $count, number_format( $state['rows_done'] ) )
				: sprintf( 'Exporting database: table %d of %d (%s)', $index + 1, $count, $count > 0 ? $state['tables'][ $index ]['name'] : '' ),
			$state['rows_done'],
			max( $state['rows_total'], $state['rows_done'] )
		);
		if ( $done ) {
			$job->log( 'info', sprintf( 'Database dump complete: %d tables, %d rows, %s.', $count, $state['rows_done'], size_format( $state['size'], 1 ) ) );
		}
		return $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$this->end_snapshot();
		$job->delete_file( 'database.sql' );
	}

	/**
	 * Row filters: SiteFerry's options always; spam comments and revisions on request.
	 *
	 * @param Job    $job    The job.
	 * @param string $prefix Table prefix.
	 * @return array<string,string> Table name => SQL condition.
	 */
	private static function filters( Job $job, string $prefix ): array {
		global $wpdb;

		$comments = Sql::identifier( $prefix . 'comments' );
		$posts    = Sql::identifier( $prefix . 'posts' );

		$filters = array(
			$prefix . 'options' => $wpdb->prepare( '`option_name` NOT LIKE %s', $wpdb->esc_like( 'siteferry_' ) . '%' ),
		);
		if ( $job->option( 'exclude_spam_comments' ) ) {
			$filters[ $prefix . 'comments' ]    = "`comment_approved` <> 'spam'";
			$filters[ $prefix . 'commentmeta' ] = "`comment_id` NOT IN (SELECT `comment_ID` FROM $comments WHERE `comment_approved` = 'spam')";
		}
		if ( $job->option( 'exclude_revisions' ) ) {
			$filters[ $prefix . 'posts' ]    = "`post_type` <> 'revision'";
			$filters[ $prefix . 'postmeta' ] = "`post_id` NOT IN (SELECT `ID` FROM $posts WHERE `post_type` = 'revision')";
		}
		return $filters;
	}

	/**
	 * Commits the read-only snapshot transaction if one is open.
	 */
	private function end_snapshot(): void {
		global $wpdb;
		if ( self::$snapshot ) {
			$wpdb->query( 'COMMIT' );
			self::$snapshot = false;
		}
	}
}
