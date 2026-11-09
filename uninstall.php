<?php
/**
 * Removes SiteFerry settings and stored storage credentials when the plugin is deleted.
 *
 * Backup files are left in place; deleting them is a separate, explicit choice.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Every option the plugin owns starts with "siteferry_", including encrypted storage credentials.
$siteferry_options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finds the plugin's own options to delete them on uninstall.
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'siteferry_' ) . '%'
	)
);
foreach ( $siteferry_options as $siteferry_option ) {
	delete_option( $siteferry_option );
}

// Each administrator's remembered choices for "Back up now".
delete_metadata( 'user', 0, 'siteferry_backup_choices', '', true );

// Scheduled backups (deactivation removes these too).
wp_unschedule_hook( 'siteferry_scheduled_backup' );
wp_unschedule_hook( 'siteferry_schedule_watchdog' );
