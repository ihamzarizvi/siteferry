<?php
/**
 * Loads SiteFerry from this checkout for one WP-CLI command, on a site where it is not installed:
 *
 *   wp --require=/path/to/siteferry/tests/tools/cli-load.php siteferry export --dir=...
 *
 * WP-CLI reads --require files before WordPress loads, so the plugin is loaded once it is up.
 * Development only: the tests folder is not part of the plugin package.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( 'WP_CLI' ) ) {
	exit;
}

WP_CLI::add_hook(
	'after_wp_load',
	static function () {
		require_once dirname( __DIR__, 2 ) . '/includes/bootstrap.php';
	}
);
