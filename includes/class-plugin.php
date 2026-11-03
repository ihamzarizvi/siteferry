<?php
/**
 * Plugin bootstrap: activation checks and hook wiring.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points called from siteferry.php.
 */
final class Plugin {

	/**
	 * Wires up the plugin on every request.
	 *
	 * Admin screens, REST routes and WP-CLI commands are registered here as
	 * each area is added.
	 */
	public static function boot(): void {
	}

	/**
	 * Refuses activation on servers that cannot run the plugin safely.
	 */
	public static function activate(): void {
		$problems = Requirements::problems();
		if ( array() === $problems ) {
			return;
		}

		deactivate_plugins( plugin_basename( SITEFERRY_FILE ) );
		wp_die(
			'<p>' . esc_html__( 'SiteFerry cannot run on this server:', 'siteferry' ) . '</p><ul><li>'
			. implode( '</li><li>', array_map( 'esc_html', $problems ) )
			. '</li></ul>',
			esc_html__( 'Plugin activation failed', 'siteferry' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Runs on deactivation. Settings and backups are kept until uninstall.
	 */
	public static function deactivate(): void {
	}
}
