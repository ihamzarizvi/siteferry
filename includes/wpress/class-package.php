<?php
/**
 * package.json of .wpress backups, and SiteFerry manifests.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Export\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Maps between the package.json at the top of a .wpress backup (site addresses, paths, versions,
 * table prefix, active plugins and theme) and SiteFerry's siteferry.json manifest.
 */
final class Package {

	/** Largest package.json accepted. */
	const MAX_BYTES = 4194304;

	/**
	 * Parses package.json.
	 *
	 * @param string $json Contents.
	 * @return array
	 * @throws Archive_Exception If it is not a usable package.json.
	 */
	public static function parse( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['SiteURL'], $data['HomeURL'] ) || ! is_string( $data['SiteURL'] ) || ! is_string( $data['HomeURL'] ) ) {
			throw new Archive_Exception( 'The package.json of this .wpress backup is damaged or incomplete.', Archive_Exception::CORRUPT );
		}
		return $data;
	}

	/**
	 * Whether the backup is protected with a password.
	 *
	 * @param array $package Parsed package.json.
	 */
	public static function encrypted( array $package ): bool {
		return ! empty( $package['Encrypted'] );
	}

	/**
	 * Options the dump of a .wpress backup leaves empty and package.json holds: the theme and the
	 * active plugins.
	 *
	 * @param array $package Parsed package.json.
	 * @return array<string,string> Option name => value (active_plugins serialized).
	 */
	public static function options( array $package ): array {
		$options = array();
		foreach ( array( 'template' => 'Template', 'stylesheet' => 'Stylesheet' ) as $option => $key ) {
			if ( isset( $package[ $key ] ) && is_string( $package[ $key ] ) && '' !== $package[ $key ] ) {
				$options[ $option ] = $package[ $key ];
			}
		}
		if ( isset( $package['Plugins'] ) && is_array( $package['Plugins'] ) ) {
			$plugins = array_values( array_filter( $package['Plugins'], 'is_string' ) );
			if ( array() !== $plugins ) {
				$options['active_plugins'] = serialize( $plugins ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the option's own storage format.
			}
		}
		return $options;
	}

	/**
	 * A SiteFerry manifest describing a .wpress backup.
	 *
	 * @param array $package Parsed package.json.
	 * @param array $facts   created (Unix time), files, bytes, and from the converted dump: size,
	 *                       tables (name => rows), db_version, charset, collate.
	 * @return array
	 */
	public static function to_manifest( array $package, array $facts ): array {
		$wordpress = self::section( $package, 'WordPress' );
		$database  = self::section( $package, 'Database' );
		$php       = self::section( $package, 'PHP' );

		$abspath = self::path( self::text( $wordpress, 'Absolute' ) );
		$content = self::path( self::text( $wordpress, 'Content' ) );
		if ( '' === $content && '' !== $abspath ) {
			$content = $abspath . '/wp-content';
		}
		$themes = isset( $wordpress['Themes'] ) ? $wordpress['Themes'] : '';
		$themes = self::path( is_array( $themes ) ? (string) reset( $themes ) : (string) $themes );
		$plugins = self::path( self::text( $wordpress, 'Plugins' ) );
		$uploads = self::path( self::text( $wordpress, 'Uploads' ) );

		$home    = untrailingslashit( (string) $package['HomeURL'] );
		$siteurl = untrailingslashit( (string) $package['SiteURL'] );
		// The content folder's address: the site address plus its place under the WordPress folder.
		$relative    = '' !== $abspath && 0 === strpos( $content . '/', $abspath . '/' ) ? substr( $content, strlen( $abspath ) + 1 ) : 'wp-content';
		$content_url = $siteurl . '/' . ( '' === $relative ? 'wp-content' : $relative );
		$uploads_url = untrailingslashit( self::text( $wordpress, 'UploadsURL' ) );

		$tables = array();
		$rows   = 0;
		foreach ( $facts['tables'] as $name => $count ) {
			$tables[] = array(
				'name' => $name,
				'rows' => (int) $count,
			);
			$rows    += (int) $count;
		}
		$plugin = self::section( $package, 'Plugin' );

		return array(
			'format'         => Manifest::FORMAT,
			'format_version' => Manifest::FORMAT_VERSION,
			'generator'      => array(
				'name'    => 'SiteFerry',
				'version' => SITEFERRY_VERSION,
				'from'    => trim( 'All-in-One WP Migration ' . self::text( $plugin, 'Version' ) ),
			),
			'created'        => gmdate( 'Y-m-d\TH:i:s\Z', (int) $facts['created'] ),
			'site'           => array(
				'home'      => $home,
				'siteurl'   => $siteurl,
				'name'      => '',
				'multisite' => false,
			),
			'paths'          => array(
				'abspath'        => $abspath,
				'wp_content_dir' => $content,
				'plugins_dir'    => '' !== $plugins ? $plugins : $content . '/plugins',
				'themes_dir'     => '' !== $themes ? $themes : $content . '/themes',
				'uploads_dir'    => '' !== $uploads ? $uploads : $content . '/uploads',
				'os'             => self::text( $php, 'System' ),
			),
			'urls'           => array(
				'wp_content_url' => $content_url,
				'uploads_url'    => '' !== $uploads_url ? $uploads_url : $content_url . '/uploads',
			),
			'versions'       => array(
				'wordpress'  => self::text( $wordpress, 'Version' ),
				'db_version' => (string) $facts['db_version'],
				'php'        => self::text( $php, 'Version' ),
				'mysql'      => self::text( $database, 'Version' ),
			),
			'database'       => array(
				'file'    => Manifest::DATABASE,
				'prefix'  => self::text( $database, 'Prefix' ),
				'charset' => '' !== $facts['charset'] ? $facts['charset'] : self::text( $database, 'Charset' ),
				'collate' => '' !== $facts['collate'] ? $facts['collate'] : self::text( $database, 'Collate' ),
				'size'    => (int) $facts['size'],
				'rows'    => $rows,
				'tables'  => $tables,
			),
			'files'          => array(
				'root'       => 'wp-content',
				'files'      => (int) $facts['files'],
				'folders'    => 0,
				'bytes'      => (int) $facts['bytes'],
				'exclusions' => array(),
			),
			'plugins'        => array( 'active' => isset( $package['Plugins'] ) && is_array( $package['Plugins'] ) ? array_values( array_filter( $package['Plugins'], 'is_string' ) ) : array() ),
			'theme'          => array(
				'stylesheet' => isset( $package['Stylesheet'] ) ? (string) $package['Stylesheet'] : '',
				'template'   => isset( $package['Template'] ) ? (string) $package['Template'] : '',
			),
			'options'        => array(),
			'checksums'      => array(
				'file'      => Manifest::CHECKSUMS,
				'algorithm' => 'sha256',
				'format'    => 'sha256sum',
			),
		);
	}

	/**
	 * A package.json for a .wpress backup made from a SiteFerry manifest.
	 *
	 * @param array $manifest SiteFerry manifest.
	 * @return array
	 */
	public static function from_manifest( array $manifest ): array {
		$site     = $manifest['site'];
		$paths    = $manifest['paths'];
		$urls     = $manifest['urls'];
		$versions = $manifest['versions'];
		$database = $manifest['database'];

		return array(
			'SiteURL'         => $site['siteurl'],
			'HomeURL'         => $site['home'],
			'InternalSiteURL' => $site['siteurl'],
			'InternalHomeURL' => $site['home'],
			'WordPress'       => array(
				'Version'    => $versions['wordpress'],
				'Absolute'   => $paths['abspath'] . '/',
				'Content'    => $paths['wp_content_dir'],
				'Plugins'    => $paths['plugins_dir'],
				'Themes'     => array( $paths['themes_dir'] ),
				'Uploads'    => $paths['uploads_dir'],
				'UploadsURL' => $urls['uploads_url'] . '/',
			),
			'Database'        => array(
				'Version' => $versions['mysql'],
				'Charset' => $database['charset'],
				'Collate' => $database['collate'],
				'Prefix'  => $database['prefix'],
			),
			'PHP'             => array(
				'Version' => $versions['php'],
				'System'  => $paths['os'],
				'Integer' => PHP_INT_SIZE,
			),
			'Plugins'         => isset( $manifest['plugins']['active'] ) ? array_values( $manifest['plugins']['active'] ) : array(),
			'Template'        => isset( $manifest['theme']['template'] ) ? $manifest['theme']['template'] : '',
			'Stylesheet'      => isset( $manifest['theme']['stylesheet'] ) ? $manifest['theme']['stylesheet'] : '',
			'Uploads'         => '',
			'UploadsURL'      => '',
			'Server'          => array(
				'.htaccess'  => '',
				'web.config' => '',
			),
			'SiteFerry'       => array(
				'Version' => SITEFERRY_VERSION,
				'Created' => $manifest['created'],
			),
		);
	}

	/**
	 * A sub-array of the package, or an empty one.
	 *
	 * @param array  $package Package.
	 * @param string $key     Key.
	 */
	private static function section( array $package, string $key ): array {
		return isset( $package[ $key ] ) && is_array( $package[ $key ] ) ? $package[ $key ] : array();
	}

	/**
	 * A string value, or ''.
	 *
	 * @param array  $data Data.
	 * @param string $key  Key.
	 */
	private static function text( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * A path with forward slashes and no trailing slash.
	 *
	 * @param string $path Path.
	 */
	private static function path( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}
}
