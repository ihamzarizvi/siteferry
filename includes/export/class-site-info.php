<?php
/**
 * Facts about the site being exported.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Collects addresses, paths and versions for the manifest. Reads only; writes nothing.
 */
final class Site_Info {

	/**
	 * Site facts as JSON-safe data.
	 *
	 * @return array
	 */
	public static function collect(): array {
		global $wpdb, $wp_version;

		// The stored option values, not filtered URLs: these are what search-replace must find.
		$uploads = wp_upload_dir( null, false );

		return array(
			'home'           => untrailingslashit( (string) get_option( 'home' ) ),
			'siteurl'        => untrailingslashit( (string) get_option( 'siteurl' ) ),
			'name'           => (string) get_option( 'blogname' ),
			'multisite'      => is_multisite(),
			'network'        => self::network(),
			'abspath'        => self::path( ABSPATH ),
			'wp_content_dir' => self::path( WP_CONTENT_DIR ),
			'wp_content_url' => untrailingslashit( content_url() ),
			'plugins_dir'    => self::path( WP_PLUGIN_DIR ),
			'themes_dir'     => self::path( get_theme_root() ),
			'uploads_dir'    => self::path( (string) $uploads['basedir'] ),
			'uploads_url'    => untrailingslashit( (string) $uploads['baseurl'] ),
			'wordpress'      => (string) $wp_version,
			'db_version'     => (string) get_option( 'db_version' ),
			'php'            => PHP_VERSION,
			'mysql'          => (string) $wpdb->db_server_info(),
			'charset'        => (string) $wpdb->charset,
			'collate'        => (string) $wpdb->collate,
			'prefix'         => (string) $wpdb->base_prefix, // A network: every site's tables.
			'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'stylesheet'     => (string) get_stylesheet(),
			'template'       => (string) get_template(),
			'os'             => PHP_OS,
			// For the restore preview (since 0.29.0): what is installed, in which version, and how much content.
			'plugins_installed' => self::plugins(),
			'themes_installed'  => self::themes(),
			'content'           => self::content(),
		);
	}

	/**
	 * Installed plugins: plugin file => [name, version].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[ (string) $file ] = array( self::text( $data['Name'] ), self::text( $data['Version'] ) );
		}
		return $out;
	}

	/**
	 * Installed themes: folder => [name, version].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function themes(): array {
		$out = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[ (string) $slug ] = array( self::text( $theme->get( 'Name' ) ), self::text( $theme->get( 'Version' ) ) );
		}
		return $out;
	}

	/**
	 * How much content the site has: published posts and pages, media items, users, approved
	 * comments (on a network: the main site's, and all users).
	 *
	 * @return array<string,int>
	 */
	public static function content(): array {
		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );
		$media = wp_count_posts( 'attachment' );
		$users = function_exists( 'get_user_count' ) ? (int) get_user_count() : (int) count_users()['total_users'];
		return array(
			'posts'    => (int) ( isset( $posts->publish ) ? $posts->publish : 0 ),
			'pages'    => (int) ( isset( $pages->publish ) ? $pages->publish : 0 ),
			'media'    => (int) ( isset( $media->inherit ) ? $media->inherit : 0 ),
			'users'    => $users,
			'comments' => (int) wp_count_comments()->approved,
		);
	}

	/**
	 * A header value as short plain text.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		return substr( trim( wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' ) ), 0, 200 );
	}

	/**
	 * The network: its address, whether subsites are subdomains, and every subsite (id, domain,
	 * path). Null on a single site.
	 */
	public static function network(): ?array {
		if ( ! is_multisite() ) {
			return null;
		}
		$network = get_network();
		$blogs   = array();
		foreach ( get_sites(
			array(
				'number'     => 0,
				'network_id' => (int) $network->id,
				'orderby'    => 'id',
			)
		) as $blog ) {
			$blogs[] = array(
				'id'     => (int) $blog->blog_id,
				'domain' => (string) $blog->domain,
				'path'   => (string) $blog->path,
			);
		}
		return array(
			'id'                => (int) $network->id,
			'domain'            => (string) $network->domain,
			'path'              => (string) $network->path,
			'subdomain_install' => is_subdomain_install(),
			'main_site'         => (int) get_main_site_id( (int) $network->id ),
			'blogs'             => $blogs,
		);
	}
	/**
	 * A filesystem path with forward slashes and no trailing slash.
	 *
	 * @param string $path Path.
	 */
	public static function path( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
