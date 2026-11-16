<?php
/**
 * The site's folders, in one place.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Where WordPress keeps its files, with forward slashes and no trailing slash.
 *
 * A full-site backup copies wp-content and its plugins, themes and media folders, and a restore
 * puts them back, so SiteFerry needs the real folders. WordPress has functions for the media and
 * themes folders (wp_upload_dir(), get_theme_root()), used everywhere. For wp-content, the plugins
 * and must-use plugins folders and WordPress's own folder there are none: WordPress itself only
 * keeps them in WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR and ABSPATH. They are read here
 * and nowhere else, so a site that moves wp-content or the plugins folder elsewhere (by setting
 * those constants in wp-config.php) is backed up and restored from where it really is.
 */
final class Paths {

	/**
	 * The wp-content folder.
	 */
	public static function content(): string {
		return self::clean( WP_CONTENT_DIR );
	}

	/**
	 * The plugins folder.
	 */
	public static function plugins(): string {
		return self::clean( WP_PLUGIN_DIR );
	}

	/**
	 * The must-use plugins folder.
	 */
	public static function mu_plugins(): string {
		return self::clean( WPMU_PLUGIN_DIR );
	}

	/**
	 * WordPress's own folder (the one with wp-settings.php), for the staging copy and maintenance
	 * mode, which WordPress reads from there.
	 */
	public static function wordpress(): string {
		return self::clean( ABSPATH );
	}

	/**
	 * The media folder of the main site (on a network, every subsite's media is below it).
	 */
	public static function uploads(): string {
		$switch = is_multisite() && ! is_main_site();
		if ( $switch ) {
			switch_to_blog( get_main_site_id() );
		}
		$uploads = wp_upload_dir( null, false );
		if ( $switch ) {
			restore_current_blog();
		}
		return self::clean( (string) $uploads['basedir'] );
	}

	/**
	 * A path with forward slashes and no trailing slash.
	 *
	 * @param string $path Path.
	 */
	public static function clean( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
