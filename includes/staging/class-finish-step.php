<?php
/**
 * Staging copy, step 4: its configuration, and done.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the copy's wp-config.php (Config::rewrite()), its must-use plugin (Mu_Plugin) and, on
 * Apache and LiteSpeed, its .htaccess; then records the copy as ready.
 */
final class Finish_Step implements Step {

	const STEP = 'staging.finish';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused).
	 * @throws Job_Exception If a file cannot be written.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;
		$record = (array) $job->option( 'staging' );
		$dest   = (string) $record['path'];
		$config = Staging::live_config();
		if ( null === $config ) {
			throw new Job_Exception( __( 'wp-config.php could not be found or read.', 'siteferry' ), Job_Exception::STEP );
		}
		$text = Config::rewrite( (string) file_get_contents( $config ), (string) $record['prefix'], (string) $record['url'], (string) $record['live'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		self::write( $dest . '/wp-config.php', $text );

		$mu = $dest . '/wp-content/mu-plugins';
		if ( ! is_dir( $mu ) && ! mkdir( $mu, 0755, true ) && ! is_dir( $mu ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- runs in cron and WP-CLI, where WP_Filesystem may need credentials.
			throw new Job_Exception( sprintf( /* translators: %s: a folder path */ __( 'Cannot create the folder "%s".', 'siteferry' ), $mu ), Job_Exception::STEP );
		}
		self::write( $mu . '/' . Mu_Plugin::FILE, Mu_Plugin::source( (string) $record['live'] ) );
		if ( empty( $record['media'] ) && ! is_dir( $dest . '/wp-content/uploads' ) ) {
			mkdir( $dest . '/wp-content/uploads', 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- the copy's empty media folder.
		}
		if ( in_array( (string) $record['server'], array( 'apache', 'litespeed' ), true ) ) {
			self::write( $dest . '/.htaccess', Staging::htaccess( (string) $record['url'] ) );
		}

		$db = 0;
		foreach ( Staging::live_tables( $wpdb, (string) $job->option( 'live_prefix' ) ) as $table ) {
			$db += $table[1];
		}
		$files            = $job->state( 'staging_files' );
		$record['bytes']  = (int) ( isset( $files['bytes'] ) ? $files['bytes'] : 0 ) + $db;
		$record['status'] = 'ready';
		$record['ready']  = time();
		$current          = Staging::record();
		if ( null !== $current && $current['id'] === $record['id'] ) {
			update_option( Staging::OPTION, $record, false );
		}
		$job->set_result(
			array(
				'staging' => $record,
				'nginx'   => 'nginx' === $record['server'] ? Staging::nginx_rule( (string) $record['url'] ) : '',
			)
		);
		$job->set_progress( __( 'Staging copy ready', 'siteferry' ) );
		$job->log( 'info', 'Staging copy ready at ' . $record['url'] . '.' );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		// A copy that did not finish stays recorded as failed, so it can be deleted from the page.
		$record  = (array) $job->option( 'staging' );
		$current = Staging::record();
		if ( null !== $current && isset( $record['id'] ) && $current['id'] === $record['id'] && 'ready' !== $current['status'] ) {
			$current['status'] = 'failed';
			update_option( Staging::OPTION, $current, false );
		}
	}

	/**
	 * Writes a file of the copy.
	 *
	 * @param string $path Path.
	 * @param string $data Contents.
	 * @throws Job_Exception If it cannot be written.
	 */
	private static function write( string $path, string $data ): void {
		if ( false === file_put_contents( $path, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- runs in cron and WP-CLI, where WP_Filesystem may need credentials.
			throw new Job_Exception( sprintf( /* translators: %s: a file path */ __( 'Cannot write "%s".', 'siteferry' ), $path ), Job_Exception::STEP );
		}
	}
}
