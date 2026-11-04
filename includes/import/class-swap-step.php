<?php
/**
 * Import step: switch the site over to the imported tables and files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;
use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Sql;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Under maintenance mode, in one request:
 *
 * 1. copies SiteFerry's own settings into the imported options and keeps SiteFerry active;
 * 2. swaps the tables with one RENAME TABLE statement (atomic): each live table that the backup
 *    replaces becomes <old prefix><name>, each temporary table takes its place. Live tables the
 *    backup does not contain are left alone;
 * 3. moves the staged files into place, moving replaced items aside first (journalled);
 * 4. checks the result, clears caches and rewrite rules, and leaves maintenance mode.
 *
 * Any failure triggers Rollback, which reverses the moves and the renames.
 */
final class Swap_Step implements Step {

	const JOURNAL = 'swap.journal';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused: the swap is done in one go).
	 * @throws Job_Exception On any failure (the runner then rolls back).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		$state = $job->state( 'swap' );
		if ( ! empty( $state['done'] ) ) {
			return true;
		}

		$site    = $job->option( 'site' );
		$inspect = $job->state( 'inspect' );
		$temp    = (string) $job->option( 'temp_prefix' );
		$old     = (string) $job->option( 'old_prefix' );
		$live    = (string) $site['prefix'];

		Maintenance::enable();
		$state = array(
			'started' => true,
			'done'    => false,
			'tables'  => array(),
		);
		$job->set_state( 'swap', $state );
		$job->save();
		$job->log( 'info', 'Maintenance mode on; swapping tables and files.' );

		$this->keep_own_settings( $wpdb, $temp, $live, (string) $job->option( 'own_plugin' ) );

		// 2. Tables.
		$existing = array_flip( Raw_Query::column( $wpdb, 'SHOW TABLES LIKE ' . Raw_Query::quote( $wpdb, addcslashes( $live, '_%\\' ) . '%' ) ) );
		$renames  = array();
		foreach ( $job->state( 'database' )['tables'] as $table ) {
			$suffix                     = substr( $table, strlen( $temp ) );
			$state['tables'][ $suffix ] = isset( $existing[ $live . $suffix ] );
			if ( $state['tables'][ $suffix ] ) {
				$renames[] = Sql::identifier( $live . $suffix ) . ' TO ' . Sql::identifier( $old . $suffix );
			}
			$renames[] = Sql::identifier( $table ) . ' TO ' . Sql::identifier( $live . $suffix );
		}
		$job->set_state( 'swap', $state );
		$job->save();
		Raw_Query::run( $wpdb, 'RENAME TABLE ' . implode( ', ', $renames ) );
		wp_cache_flush();
		$job->log( 'info', sprintf( 'Tables swapped (%d).', count( $state['tables'] ) ) );

		// 3. Files.
		$moved = $this->move_files( $job, $site, $inspect );
		$job->log( 'info', sprintf( 'Files moved into place (%d items).', $moved ) );

		if ( defined( 'SITEFERRY_TEST_FAIL_AFTER_SWAP' ) && SITEFERRY_TEST_FAIL_AFTER_SWAP ) {
			throw new Job_Exception( 'Simulated failure after the swap (test).', Job_Exception::STEP );
		}

		// 4. Check, clean caches, reopen the site.
		$home = Raw_Query::column( $wpdb, 'SELECT option_value FROM ' . Sql::identifier( $live . 'options' ) . " WHERE option_name = 'home'" );
		if ( ! isset( $home[0] ) || untrailingslashit( (string) $home[0] ) !== untrailingslashit( (string) $inspect['target']['home'] ) ) {
			throw new Job_Exception( 'After the swap the site address is not the expected one; rolling back.', Job_Exception::STEP );
		}
		Raw_Query::run( $wpdb, 'DELETE FROM ' . Sql::identifier( $live . 'options' ) . " WHERE option_name = 'rewrite_rules'" );
		wp_cache_flush();
		if ( function_exists( 'opcache_reset' ) ) {
			Io::quiet( 'opcache_reset' );
		}
		Maintenance::disable();

		$state['done'] = true;
		$job->set_state( 'swap', $state );
		$job->set_progress( 'Site switched over' );
		$job->log( 'info', 'Swap complete; maintenance mode off.' );
		return true;
	}

	/**
	 * Undoes a swap that did not complete.
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		Rollback::run( $job );
	}

	/**
	 * Copies siteferry_* options from the live site into the imported options (settings and
	 * storage credentials stay with this site) and keeps SiteFerry active if it is now.
	 *
	 * @param \wpdb  $db     Connection.
	 * @param string $temp   Temporary prefix.
	 * @param string $live   Live prefix.
	 * @param string $plugin SiteFerry's basename if it is active, else ''.
	 */
	private function keep_own_settings( $db, string $temp, string $live, string $plugin ): void {
		$imported = Sql::identifier( $temp . 'options' );
		$current  = Sql::identifier( $live . 'options' );
		Raw_Query::run(
			$db,
			"INSERT INTO $imported (option_name, option_value, autoload) SELECT option_name, option_value, autoload FROM $current"
			. ' WHERE option_name LIKE ' . Raw_Query::quote( $db, 'siteferry\\_%' )
			. ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)'
		);

		if ( '' === $plugin ) {
			return;
		}
		$value  = Raw_Query::column( $db, "SELECT option_value FROM $imported WHERE option_name = 'active_plugins'" );
		$active = isset( $value[0] ) ? Io::quiet(
			static function () use ( $value ) {
				return unserialize( (string) $value[0], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		) : array();
		$active = is_array( $active ) ? array_values( $active ) : array();
		if ( ! in_array( $plugin, $active, true ) ) {
			$active[] = $plugin;
			$data     = serialize( $active ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( isset( $value[0] ) ) {
				Raw_Query::run( $db, "UPDATE $imported SET option_value = " . Raw_Query::quote( $db, $data ) . " WHERE option_name = 'active_plugins'" );
			} else {
				Raw_Query::run( $db, "INSERT INTO $imported (option_name, option_value, autoload) VALUES ('active_plugins', " . Raw_Query::quote( $db, $data ) . ", 'yes')" );
			}
		}
	}

	/**
	 * Moves the staged wp-content items into place.
	 *
	 * Plugins, themes and (if chosen) must-use plugins are moved one by one, so SiteFerry itself
	 * and items the backup does not contain stay; uploads and other top-level items are replaced
	 * whole. Server-specific drop-ins are only copied when asked for.
	 *
	 * @param Job   $job     The job.
	 * @param array $site    This site's facts.
	 * @param array $inspect Inspection state.
	 * @return int Items moved into place.
	 */
	private function move_files( Job $job, array $site, array $inspect ): int {
		$staged = Extract_Step::staging( $job ) . '/wp-content';
		if ( ! is_dir( $staged ) ) {
			return 0;
		}
		$rollback = $job->path( 'rollback' );
		if ( ! is_dir( $rollback ) && ! mkdir( $rollback, 0755, true ) && ! is_dir( $rollback ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			throw new Job_Exception( 'Cannot create the rollback folder.', Job_Exception::STEP );
		}
		$journal = new Journal( $job->path( self::JOURNAL ) );

		$source_content = (string) $inspect['source']['wp_content_dir'];
		$groups         = array(
			self::relative( (string) $inspect['plugins'], $source_content, 'plugins' ) => array( $site['plugins_dir'], true ),
			self::relative( (string) $inspect['themes'], $source_content, 'themes' )   => array( $site['themes_dir'], true ),
			self::relative( (string) $inspect['source']['uploads_dir'], $source_content, 'uploads' ) => array( $site['uploads_dir'], false ),
		);
		if ( $job->option( 'include_mu_plugins' ) ) {
			$groups['mu-plugins'] = array( str_replace( '\\', '/', WPMU_PLUGIN_DIR ), true );
		}
		$skip_plugins = array( (string) $job->option( 'own_folder' ), 'siteferry' );
		$plugins_name = self::relative( (string) $inspect['plugins'], $source_content, 'plugins' );
		$moved        = 0;

		foreach ( self::entries( $staged ) as $name ) {
			$from = $staged . '/' . $name;
			if ( 0 === strpos( $name, 'siteferry-' ) ) {
				continue;
			}
			if ( 'mu-plugins' === $name && ! $job->option( 'include_mu_plugins' ) ) {
				$job->log( 'info', 'Must-use plugins in the backup were not copied (not chosen).' );
				continue;
			}
			if ( in_array( $name, Import::DROP_INS, true ) && ! $job->option( 'include_drop_ins' ) ) {
				$job->warn( sprintf( 'The drop-in wp-content/%s was not copied; it is tied to the old server. Copy it yourself if this server needs it.', $name ) );
				continue;
			}
			if ( isset( $groups[ $name ] ) ) {
				list( $into, $each ) = $groups[ $name ];
				if ( ! $each ) {
					$this->move( $from, $into, $journal, $rollback );
					++$moved;
					continue;
				}
				foreach ( self::entries( $from ) as $entry ) {
					if ( $name === $plugins_name && in_array( $entry, $skip_plugins, true ) ) {
						continue;
					}
					$this->move( $from . '/' . $entry, $into . '/' . $entry, $journal, $rollback );
					++$moved;
				}
				continue;
			}
			$this->move( $from, $site['wp_content_dir'] . '/' . $name, $journal, $rollback );
			++$moved;
		}
		return $moved;
	}

	/**
	 * Moves one item into place, moving any existing item aside first.
	 *
	 * @param string  $from     Staged path.
	 * @param string  $to       Live path.
	 * @param Journal $journal  Journal.
	 * @param string  $rollback Rollback folder.
	 * @throws Job_Exception If a rename fails.
	 */
	private function move( string $from, string $to, Journal $journal, string $rollback ): void {
		if ( file_exists( $to ) || is_link( $to ) ) {
			$aside = $rollback . '/' . sprintf( '%05d', $journal->count() ) . '-' . basename( $to );
			self::rename( $to, $aside );
			$journal->add( 'backup', $to, $aside );
		}
		$parent = dirname( $to );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0755, true ) && ! is_dir( $parent ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			throw new Job_Exception( sprintf( 'Cannot create the folder %s.', $parent ), Job_Exception::STEP );
		}
		self::rename( $from, $to );
		$journal->add( 'install', $from, $to );
	}

	/**
	 * Renames or throws.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @throws Job_Exception On failure.
	 */
	public static function rename( string $from, string $to ): void {
		$done = Io::quiet(
			static function () use ( $from, $to ) {
				return rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		);
		if ( ! $done ) {
			throw new Job_Exception( sprintf( 'Cannot move %s to %s (a file inside may be in use).', $from, $to ), Job_Exception::STEP );
		}
	}

	/**
	 * Names in a folder without "." and "..".
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	private static function entries( string $dir ): array {
		$names = is_dir( $dir ) ? scandir( $dir ) : false;
		return false === $names ? array() : array_values( array_diff( $names, array( '.', '..' ) ) );
	}

	/**
	 * Path of a source folder relative to the source wp-content, or a default.
	 *
	 * @param string $path     Absolute source path.
	 * @param string $content  Absolute source wp-content path.
	 * @param string $fallback Default name.
	 */
	private static function relative( string $path, string $content, string $fallback ): string {
		$prefix = rtrim( $content, '/' ) . '/';
		return '' !== $path && 0 === stripos( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : $fallback;
	}
}
