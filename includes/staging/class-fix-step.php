<?php
/**
 * Staging copy, step 3: make the copied tables a separate site.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Replacer;
use SiteFerry\Database\Sql;
use SiteFerry\Database\Table_Replacer;
use SiteFerry\Import\Replace_Step;
use SiteFerry\Import\Replacements;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * On the staging tables only:
 *
 * 1. the prefixed option and user meta keys follow the new prefix (as when a backup is restored
 *    under another prefix);
 * 2. the site's addresses and folders are replaced with the staging ones, serialized values
 *    included (posts' GUIDs are left alone, as WordPress wants);
 * 3. home and siteurl are set to the staging address; search engines are asked to stay away
 *    (blog_public 0);
 * 4. SiteFerry's own settings are removed from the copy (its storage logins, schedule and the
 *    backup before updates) and SiteFerry's scheduled events taken out of the copy's cron, so the
 *    copy never sends backups or uses the live site's cloud access; the copy remembers which site
 *    it copies.
 */
final class Fix_Step implements Step {

	const STEP = 'staging.fix';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;
		$record = (array) $job->option( 'staging' );
		$live   = (string) $job->option( 'live_prefix' );
		$prefix = (string) $record['prefix'];
		$state  = $job->state( 'staging_fix' );
		if ( ! isset( $state['tables'] ) ) {
			$state = array(
				'tables'   => array_keys( (array) $job->state( 'staging_tables' )['made'] ),
				'index'    => 0,
				'plan'     => null,
				'rows'     => 0,
				'prefixed' => false,
			);
			sort( $state['tables'] );
		}
		if ( ! $state['prefixed'] ) {
			Replace_Step::rename_prefixed_keys( $wpdb, $prefix, $live, $prefix );
			$state['prefixed'] = true;
			$job->set_state( 'staging_fix', $state );
		}

		$replacer = new Replacer( self::pairs( (array) $job->option( 'site' ), $record ) );
		$tables   = new Table_Replacer( $wpdb, $replacer );
		$count    = count( $state['tables'] );
		while ( $state['index'] < $count ) {
			$table = (string) $state['tables'][ $state['index'] ];
			if ( null === $state['plan'] ) {
				$state['plan'] = $tables->plan( $table, $prefix . 'posts' === $table ? array( 'guid' ) : array() );
				if ( null === $state['plan'] ) {
					++$state['index'];
					continue;
				}
			}
			if ( $tables->run( $state['plan'] ) ) {
				$state['rows'] += (int) $state['plan']['rows'];
				$state['plan']  = null;
				++$state['index'];
			}
			if ( $budget->exhausted() ) {
				break;
			}
		}
		$job->set_progress( sprintf( /* translators: 1: a number, 2: a number */ __( 'Updating addresses in the copy: table %1$d of %2$d', 'siteferry' ), min( $state['index'] + 1, max( 1, $count ) ), max( 1, $count ) ), $state['index'], max( 1, $count ) );
		$job->set_state( 'staging_fix', $state );
		if ( $state['index'] < $count ) {
			return false;
		}
		self::settings( $wpdb, $prefix . 'options', $record );
		$job->log( 'info', sprintf( 'Staging: addresses updated in %d rows; settings of the copy set.', (int) $state['rows'] ) );
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
	 * Search and replace pairs from the live site to the staging copy.
	 *
	 * @param array $site   The live site's facts (Site_Info).
	 * @param array $record The staging record.
	 * @return array<string,string>
	 */
	public static function pairs( array $site, array $record ): array {
		$url  = (string) $record['url'];
		$path = (string) $record['path'];
		return Replacements::build(
			array(
				'home'           => $site['home'],
				'siteurl'        => $site['siteurl'],
				'wp_content_url' => $site['wp_content_url'],
				'uploads_url'    => $site['uploads_url'],
				'abspath'        => $site['abspath'],
				'wp_content_dir' => $site['wp_content_dir'],
				'uploads_dir'    => $site['uploads_dir'],
			),
			array(
				'home'           => $url,
				'siteurl'        => $url,
				'wp_content_url' => $url . '/wp-content',
				'uploads_url'    => $url . '/wp-content/uploads',
				'abspath'        => $path,
				'wp_content_dir' => $path . '/wp-content',
				'uploads_dir'    => $path . '/wp-content/uploads',
			)
		);
	}

	/**
	 * The copy's own settings.
	 *
	 * @param \wpdb  $db      Connection.
	 * @param string $options The staging options table.
	 * @param array  $record  The staging record.
	 */
	private static function settings( $db, string $options, array $record ): void {
		$table = Sql::identifier( $options );
		foreach ( array(
			'home'        => (string) $record['url'],
			'siteurl'     => (string) $record['url'],
			'blog_public' => '0',
		) as $name => $value ) {
			Raw_Query::run( $db, "UPDATE $table SET option_value = " . Raw_Query::quote( $db, $value ) . ' WHERE option_name = ' . Raw_Query::quote( $db, $name ) );
		}
		// SiteFerry's settings stay with the live site (storage logins, schedule, records).
		Raw_Query::run( $db, "DELETE FROM $table WHERE option_name LIKE " . Raw_Query::quote( $db, 'siteferry\\_%' ) . ' OR option_name LIKE ' . Raw_Query::quote( $db, '\\_transient\\_%siteferry%' ) . ' OR option_name LIKE ' . Raw_Query::quote( $db, '\\_site\\_transient\\_%siteferry%' ) );
		$copy = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stored the way WordPress stores array options.
			array(
				'of'      => (string) $record['live'],
				'made'    => time(),
				'id'      => (string) $record['id'],
				'folder'  => (string) $record['folder'],
			)
		);
		Raw_Query::run( $db, "INSERT INTO $table (option_name, option_value, autoload) VALUES (" . Raw_Query::quote( $db, Staging::COPY_OPTION ) . ', ' . Raw_Query::quote( $db, $copy ) . ", 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );

		// SiteFerry's scheduled events out of the copy's cron.
		$cron = Raw_Query::column( $db, "SELECT option_value FROM $table WHERE option_name = 'cron'" );
		if ( isset( $cron[0] ) ) {
			$events = @unserialize( (string) $cron[0], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress's own cron array; a damaged value is left alone.
			if ( is_array( $events ) ) {
				foreach ( $events as $time => $hooks ) {
					if ( ! is_array( $hooks ) ) {
						continue;
					}
					foreach ( array_keys( $hooks ) as $hook ) {
						if ( 0 === strpos( (string) $hook, 'siteferry' ) ) {
							unset( $events[ $time ][ $hook ] );
						}
					}
					if ( array() === $events[ $time ] ) {
						unset( $events[ $time ] );
					}
				}
				Raw_Query::run( $db, "UPDATE $table SET option_value = " . Raw_Query::quote( $db, serialize( $events ) ) . " WHERE option_name = 'cron'" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- stored the way WordPress stores it.
			}
		}
	}
}
