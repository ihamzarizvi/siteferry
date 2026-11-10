<?php
/**
 * Deleting the staging copy.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

use SiteFerry\Archive\Io;
use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Sql;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Drops the staging tables and removes the staging folder, a little per slice. Every slice checks
 * the record again (Staging::check_target()): only tables starting with the recorded "stg…_"
 * prefix are dropped, and only the recorded folder in WordPress's folder, carrying the marker with
 * the record's id, is removed. Links inside it are removed without following them. When both are
 * gone the record is removed.
 */
final class Delete_Step implements Step {

	const STEP = 'staging.delete';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;
		$record = (array) $job->option( 'staging' );
		$state  = $job->state( 'staging_delete' ) + array(
			'tables'  => 0,
			'files'   => 0,
			'tables_done' => false,
		);
		$path = (string) $record['path'];
		// The marker is removed last, so the check keeps working until the folder is gone.
		Staging::check_target( $record );

		if ( ! $state['tables_done'] ) {
			$prefix = (string) $record['prefix'];
			foreach ( Raw_Query::column( $wpdb, 'SHOW TABLES LIKE ' . Raw_Query::quote( $wpdb, addcslashes( $prefix, '_%\\' ) . '%' ) ) as $table ) {
				if ( 0 !== strpos( (string) $table, $prefix ) ) {
					continue;
				}
				Raw_Query::run( $wpdb, 'DROP TABLE IF EXISTS ' . Sql::identifier( (string) $table ) );
				++$state['tables'];
				if ( $budget->exhausted() ) {
					$job->set_state( 'staging_delete', $state );
					return false;
				}
			}
			$state['tables_done'] = true;
		}

		if ( file_exists( $path ) ) {
			$gone = self::remove( $path, $budget, $state, $path . '/' . Staging::MARKER );
			if ( $gone ) {
				// Everything else is gone: now the marker, then the folder.
				Io::quiet(
					static function () use ( $path ) {
						unlink( $path . '/' . Staging::MARKER ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removes the staging copy SiteFerry made.
						return rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the staging copy SiteFerry made.
					}
				);
			}
		}
		$job->set_state( 'staging_delete', $state );
		$job->set_progress( sprintf( /* translators: 1: a number of tables, 2: a number of files */ __( 'Deleting the staging copy: %1$d tables, %2$s files removed', 'siteferry' ), (int) $state['tables'], number_format( (int) $state['files'] ) ) );
		if ( file_exists( $path ) ) {
			return false;
		}
		$current = Staging::record();
		if ( null !== $current && $current['id'] === $record['id'] ) {
			delete_option( Staging::OPTION );
		}
		$job->log( 'info', sprintf( 'Staging copy deleted: %d tables, %d files.', (int) $state['tables'], (int) $state['files'] ) );
		$job->set_result( array( 'deleted' => $record['url'] ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}

	/**
	 * Removes what is inside a folder, children first, until the budget runs out; true when only
	 * $keep is left (or the folder is empty).
	 *
	 * @param string       $dir    Folder.
	 * @param Slice_Budget $budget Work limit.
	 * @param array        $state  Counts.
	 * @param string       $keep   A file to leave for last.
	 */
	private static function remove( string $dir, Slice_Budget $budget, array &$state, string $keep = '' ): bool {
		$names = Io::quiet(
			static function () use ( $dir ) {
				return scandir( $dir );
			}
		);
		foreach ( false === $names ? array() : $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( $path === $keep ) {
				continue;
			}
			if ( Io::is_link_or_junction( $path ) || ! is_dir( $path ) ) {
				Io::remove_tree( $path ); // A file, or a link removed without following it.
				++$state['files'];
			} else {
				if ( ! self::remove( $path, $budget, $state ) ) {
					return false;
				}
				Io::quiet(
					static function () use ( $path ) {
						return rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removes the staging copy SiteFerry made.
					}
				);
			}
			if ( $budget->exhausted() ) {
				return false;
			}
		}
		return true;
	}
}
