<?php
/**
 * Loads the plugin. Included by siteferry.php (and, in development, by tests/tools/cli-load.php).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SITEFERRY_VERSION' ) ) {
	return; // Already loaded (an installed copy plus tests/tools/cli-load.php).
}

define( 'SITEFERRY_VERSION', '0.47.0' );
define( 'SITEFERRY_DIR', dirname( __DIR__ ) );
define( 'SITEFERRY_FILE', SITEFERRY_DIR . '/siteferry.php' );

require_once SITEFERRY_DIR . '/includes/class-autoloader.php';

\SiteFerry\Autoloader::register();

register_activation_hook( SITEFERRY_FILE, array( '\SiteFerry\Plugin', 'activate' ) );
register_deactivation_hook( SITEFERRY_FILE, array( '\SiteFerry\Plugin', 'deactivate' ) );

if ( did_action( 'plugins_loaded' ) ) {
	\SiteFerry\Plugin::boot();
} else {
	add_action( 'plugins_loaded', array( '\SiteFerry\Plugin', 'boot' ) );
}
