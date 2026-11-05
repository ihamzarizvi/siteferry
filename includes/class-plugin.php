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
		Rest\Rest::register();
		Schedule\Schedule::register();
		Schedule\Before_Updates::register();
		if ( is_admin() ) {
			Admin\Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Command::register();
		}
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
	 * Runs on deactivation: no scheduled backup starts while SiteFerry is off. Settings and backups
	 * are kept until uninstall; the schedule comes back when SiteFerry is activated and an
	 * administrator opens the dashboard.
	 */
	public static function deactivate(): void {
		Schedule\Schedule::unschedule_all();
	}

	/**
	 * This plugin's basename as WordPress knows it ("siteferry/siteferry.php").
	 */
	public static function basename(): string {
		return function_exists( 'plugin_basename' ) ? plugin_basename( SITEFERRY_FILE ) : basename( SITEFERRY_DIR ) . '/siteferry.php';
	}

	/**
	 * Name of this plugin's folder inside the plugins folder.
	 */
	public static function folder(): string {
		$basename = self::basename();
		$folder   = false !== strpos( $basename, '/' ) ? dirname( $basename ) : '';
		return '' !== $folder && '.' !== $folder ? $folder : basename( SITEFERRY_DIR );
	}
}
