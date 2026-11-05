<?php
/**
 * Undoing an interrupted swap.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;
use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Sql;
use SiteFerry\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the site back the way it was before Swap_Step started.
 *
 * File moves are reversed newest first from the journal. Tables are swapped back with one
 * RENAME TABLE: the imported tables return to the temporary prefix (Database_Step's cleanup then
 * drops them) and the rollback copies get their names back. RENAME TABLE is atomic, so if any
 * temporary table still exists the forward swap never happened and the tables are left alone.
 * If something cannot be restored, the rollback folder is kept and the job log says so.
 */
final class Rollback {

	/**
	 * Runs the rollback if the swap started but did not finish.
	 *
	 * @param Job $job The job.
	 */
	public static function run( Job $job ): void {
		global $wpdb;

		$state = $job->state( 'swap' );
		if ( empty( $state['started'] ) || ! empty( $state['done'] ) ) {
			return;
		}
		$job->log( 'warning', 'Rolling back: restoring the previous files and tables.' );
		$errors = array();

		try {
			Maintenance::enable();
		} catch ( \Throwable $e ) {
			$errors[] = $e->getMessage();
		}

		foreach ( array_reverse( Journal::read( $job->path( Swap_Step::JOURNAL ) ) ) as $move ) {
			list( $type, $from, $to ) = $move;
			try {
				if ( 'install' === $type && ( file_exists( $to ) || is_link( $to ) ) ) {
					Swap_Step::rename( $to, $from );
				} elseif ( 'backup' === $type && ( file_exists( $to ) || is_link( $to ) ) && ! file_exists( $from ) && ! is_link( $from ) ) {
					Swap_Step::rename( $to, $from );
				}
			} catch ( \Throwable $e ) {
				$errors[] = $e->getMessage();
			}
		}

		try {
			self::restore_tables( $wpdb, $job, $state );
		} catch ( \Throwable $e ) {
			$errors[] = $e->getMessage();
		}

		wp_cache_flush();
		Maintenance::disable();

		if ( array() === $errors ) {
			Io::remove_tree( $job->path( 'rollback' ) );
			$job->log( 'info', 'Rollback complete: the site is as it was before the import.' );
			return;
		}
		$job->log( 'error', 'Rollback incomplete. Replaced items are kept in ' . $job->path( 'rollback' ) . '. Problems: ' . implode( ' | ', $errors ) );
		$job->set_result( array_merge( $job->result(), array( 'rollback_errors' => $errors ) ) );
	}

	/**
	 * Swaps the tables back.
	 *
	 * @param \wpdb $db    Connection.
	 * @param Job   $job   The job.
	 * @param array $state Swap state: tables = suffix => whether a live table was moved aside.
	 */
	private static function restore_tables( $db, Job $job, array $state ): void {
		if ( empty( $state['tables'] ) && empty( $state['aside'] ) ) {
			return;
		}
		$temp = (string) $job->option( 'temp_prefix' );
		$old  = (string) $job->option( 'old_prefix' );
		$live = (string) $job->option( 'site' )['prefix'];

		$remaining = Raw_Query::column( $db, 'SHOW TABLES LIKE ' . Raw_Query::quote( $db, addcslashes( $temp, '_%\\' ) . '%' ) );
		if ( array() !== $remaining ) {
			return; // The forward RENAME never ran.
		}
		$renames = array();
		foreach ( $state['tables'] as $suffix => $had_live ) {
			$renames[] = Sql::identifier( $live . $suffix ) . ' TO ' . Sql::identifier( $temp . $suffix );
			if ( $had_live ) {
				$renames[] = Sql::identifier( $old . $suffix ) . ' TO ' . Sql::identifier( $live . $suffix );
			}
		}
		foreach ( isset( $state['aside'] ) ? (array) $state['aside'] : array() as $suffix ) {
			$renames[] = Sql::identifier( $old . $suffix ) . ' TO ' . Sql::identifier( $live . $suffix );
		}
		Raw_Query::run( $db, 'RENAME TABLE ' . implode( ', ', $renames ) );
		$job->log( 'info', sprintf( 'Tables restored (%d).', count( $state['tables'] ) ) );
	}
}
