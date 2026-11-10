<?php
/**
 * A staging copy of this site: a subfolder and its own tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Database\Raw_Query;
use SiteFerry\Export\Exclusions;
use SiteFerry\Export\Site_Info;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * One staging copy at a time, recorded in an option:
 *
 * - its files: a subfolder of WordPress's folder (wp-admin, wp-includes, wp-content and the rest,
 *   with the same things left out as a backup leaves out), marked with a ".siteferry-staging" file
 *   holding its id;
 * - its database: a copy of every table with this site's prefix, under a new prefix "stg" + 4
 *   random hex digits + "_", in the same database;
 * - its address: this site's address followed by the folder name.
 *
 * Making it and deleting it are jobs (resumable, in slices, like a backup). Deleting checks the
 * record again on every slice and only ever drops tables with the recorded staging prefix and
 * removes the recorded folder, and only when that folder still carries the marker with the id.
 */
final class Staging {

	/** The record of the staging copy. */
	const OPTION = 'siteferry_staging';

	/** In the staging copy: which site it copies, and when. */
	const COPY_OPTION = 'siteferry_staging_copy';

	/** Marker file in the staging folder. */
	const MARKER = '.siteferry-staging';

	/** Steps that make a copy. */
	const STEPS = array( 'staging.files', 'staging.tables', 'staging.fix', 'staging.finish' );

	/** The step that deletes it. */
	const DELETE_STEP = 'staging.delete';

	/** Folder names that WordPress itself uses. */
	const RESERVED = array( 'wp-admin', 'wp-content', 'wp-includes' );

	/**
	 * The recorded staging copy, or null.
	 *
	 * @return array|null
	 */
	public static function record(): ?array {
		$record = get_option( self::OPTION );
		return is_array( $record ) && isset( $record['id'], $record['folder'], $record['prefix'] ) ? $record : null;
	}

	/**
	 * What the admin page shows: the copy (or null), why one cannot be made here, sizes and hints.
	 *
	 * @return array
	 */
	public static function overview(): array {
		global $wpdb;
		$record = self::record();
		$db     = 0;
		foreach ( self::live_tables( $wpdb, (string) $wpdb->prefix ) as $table ) {
			$db += $table[1];
		}
		$server = self::server();
		$out    = array(
			'record'   => $record,
			'blockers' => null === $record ? self::blockers() : array(),
			'db_bytes' => $db,
			'server'   => $server,
			'home'     => untrailingslashit( (string) get_option( 'home' ) ),
			'copy_of'  => get_option( self::COPY_OPTION ) ?: null,
		);
		if ( null !== $record ) {
			$out['record']['nginx'] = 'nginx' === (string) ( isset( $record['server'] ) ? $record['server'] : '' ) ? self::nginx_rule( (string) $record['url'] ) : '';
		}
		return $out;
	}

	/**
	 * Why a staging copy cannot be made on this site now (empty when it can).
	 *
	 * @return string[]
	 */
	public static function blockers(): array {
		$out = array();
		if ( defined( 'SITEFERRY_STAGING' ) && SITEFERRY_STAGING ) {
			return array( __( 'This site is itself a staging copy. Make staging copies on the live site.', 'siteferry' ) );
		}
		if ( is_multisite() ) {
			return array( __( 'This site is a multisite network. Staging copies of networks are not supported yet.', 'siteferry' ) );
		}
		$home    = untrailingslashit( (string) get_option( 'home' ) );
		$siteurl = untrailingslashit( (string) get_option( 'siteurl' ) );
		if ( 0 !== strcasecmp( $home, $siteurl ) ) {
			$out[] = __( 'WordPress\'s files are in a different folder from the site\'s address (WordPress Address and Site Address differ). Staging copies of such sites are not supported yet.', 'siteferry' );
		}
		$root = Site_Info::path( ABSPATH );
		if ( 0 !== strcasecmp( Site_Info::path( WP_CONTENT_DIR ), $root . '/wp-content' ) ) {
			$out[] = __( 'wp-content is not in WordPress\'s folder. Staging copies of such sites are not supported yet.', 'siteferry' );
		}
		$uploads = wp_upload_dir( null, false );
		if ( 0 !== strcasecmp( Site_Info::path( (string) $uploads['basedir'] ), $root . '/wp-content/uploads' ) ) {
			$out[] = __( 'The media folder is not wp-content/uploads. Staging copies of such sites are not supported yet.', 'siteferry' );
		}
		$config = self::live_config();
		if ( null === $config ) {
			$out[] = __( 'wp-config.php could not be found or read.', 'siteferry' );
		} else {
			try {
				Config::rewrite( (string) file_get_contents( $config ), 'stg0000_', $home . '/staging', $home ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
			} catch ( Job_Exception $e ) {
				$out[] = $e->getMessage();
			}
		}
		if ( ! wp_is_writable( ABSPATH ) ) {
			$out[] = __( 'WordPress\'s folder is not writable by the web server, so the staging folder cannot be made.', 'siteferry' );
		}
		return $out;
	}

	/**
	 * Starts making a staging copy.
	 *
	 * @param string $folder   Folder name.
	 * @param bool   $no_media Leave the media library out.
	 * @return array{0:Job,1:string}
	 * @throws Job_Exception If it cannot be made.
	 */
	public static function start( string $folder, bool $no_media ): array {
		global $wpdb;
		$folder = strtolower( trim( $folder ) );
		self::check_folder_name( $folder );
		if ( null !== self::record() ) {
			throw new Job_Exception( __( 'There is already a staging copy. Delete it first; one copy at a time is supported.', 'siteferry' ), Job_Exception::STEP );
		}
		$blockers = self::blockers();
		if ( array() !== $blockers ) {
			throw new Job_Exception( $blockers[0], Job_Exception::STEP );
		}
		$root = Site_Info::path( ABSPATH );
		$dest = $root . '/' . $folder;
		if ( file_exists( $dest ) || is_link( $dest ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a folder name */ __( 'A file or folder named "%s" already exists in WordPress\'s folder. Choose another name.', 'siteferry' ), $folder ), Job_Exception::STEP );
		}
		$live   = (string) $wpdb->prefix;
		$prefix = self::new_prefix( $wpdb, $live );
		foreach ( self::live_tables( $wpdb, $live ) as $table ) {
			if ( strlen( $prefix . substr( $table[0], strlen( $live ) ) ) > 64 ) {
				throw new Job_Exception( sprintf( /* translators: %s: a table name */ __( 'The table %s would get too long a name in the copy.', 'siteferry' ), $table[0] ), Job_Exception::STEP );
			}
		}
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$id   = Storage::random_hex( 8 );
		$url  = $home . '/' . $folder;

		$record = array(
			'id'       => $id,
			'folder'   => $folder,
			'path'     => $dest,
			'prefix'   => $prefix,
			'url'      => $url,
			'live'     => $home,
			'media'    => ! $no_media,
			'server'   => self::server(),
			'created'  => time(),
			'status'   => 'building',
			'bytes'    => 0,
			'job'      => '',
		);
		$options = array(
			'staging'    => $record,
			'live_prefix' => $live,
			'site'       => Site_Info::collect(),
			'exclusions' => self::exclusions( $folder, $no_media, $root )->to_array(),
		);
		$created = Job::create( Storage::jobs_dir(), 'staging', self::STEPS, $options );
		$record['job'] = $created[0]->id();
		update_option( self::OPTION, $record, false );
		return $created;
	}

	/**
	 * Starts deleting the staging copy.
	 *
	 * @return array{0:Job,1:string}
	 * @throws Job_Exception If there is none, or its record does not look safe to act on.
	 */
	public static function start_delete(): array {
		$record = self::record();
		if ( null === $record ) {
			throw new Job_Exception( __( 'There is no staging copy.', 'siteferry' ), Job_Exception::NOT_FOUND );
		}
		self::check_target( $record );
		$record['status'] = 'deleting';
		update_option( self::OPTION, $record, false );
		return Job::create( Storage::jobs_dir(), 'staging-delete', array( self::DELETE_STEP ), array( 'staging' => $record ) );
	}

	/**
	 * Checks a folder name: lower-case letters, digits and hyphens, 1 to 30, not one of WordPress's.
	 *
	 * @param string $folder Name.
	 * @throws Job_Exception If it is not allowed.
	 */
	public static function check_folder_name( string $folder ): void {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,29}$/', $folder ) || in_array( $folder, self::RESERVED, true ) ) {
			throw new Job_Exception( __( 'Use 1 to 30 lower-case letters, digits or hyphens for the folder name (not wp-admin, wp-content or wp-includes).', 'siteferry' ), Job_Exception::STEP );
		}
	}

	/**
	 * Checks that a record only points at a staging folder inside WordPress's folder and at staging
	 * tables, never at the live site.
	 *
	 * @param array       $record  The record.
	 * @param string|null $root    WordPress's folder (default ABSPATH).
	 * @param string|null $live    The live table prefix (default $wpdb->prefix).
	 * @throws Job_Exception If anything does not match.
	 */
	public static function check_target( array $record, ?string $root = null, ?string $live = null ): void {
		global $wpdb;
		$root   = Site_Info::path( null === $root ? ABSPATH : $root );
		$live   = null === $live ? (string) $wpdb->prefix : $live;
		$folder = isset( $record['folder'] ) ? (string) $record['folder'] : '';
		$prefix = isset( $record['prefix'] ) ? (string) $record['prefix'] : '';
		$path   = isset( $record['path'] ) ? Site_Info::path( (string) $record['path'] ) : '';
		$id     = isset( $record['id'] ) ? (string) $record['id'] : '';
		$bad    = new Job_Exception( __( 'The staging record does not match a staging copy SiteFerry made; nothing was deleted.', 'siteferry' ), Job_Exception::STEP );

		if ( ! preg_match( '/^[a-f0-9]{16}$/', $id ) || ! preg_match( '/^stg[a-f0-9]{4}_$/', $prefix ) || $prefix === $live || 0 === strpos( $live, $prefix ) ) {
			throw $bad;
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,29}$/', $folder ) || in_array( $folder, self::RESERVED, true ) || 0 !== strcasecmp( $path, $root . '/' . $folder ) ) {
			throw $bad;
		}
		if ( file_exists( $path ) ) {
			$real      = realpath( $path );
			$real_root = realpath( $root );
			if ( false === $real || false === $real_root || 0 !== strcasecmp( Site_Info::path( $real ), Site_Info::path( $real_root ) . '/' . $folder ) ) {
				throw $bad;
			}
			$marker = $path . '/' . self::MARKER;
			if ( ! is_file( $marker ) || trim( (string) file_get_contents( $marker ) ) !== $id ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
				throw $bad;
			}
		}
	}

	/**
	 * What the copy leaves out, relative to WordPress's folder: the staging folder, wp-config.php
	 * and .htaccess (written for the copy), other WordPress sites or staging copies in subfolders,
	 * and in wp-content what a backup leaves out (SiteFerry's and other backup plugins' storage,
	 * caches, update folders, cache drop-ins) plus the media library when asked.
	 *
	 * @param string $folder   Staging folder name.
	 * @param bool   $no_media Leave media out.
	 * @param string $root     WordPress's folder.
	 */
	public static function exclusions( string $folder, bool $no_media, string $root ): Exclusions {
		$paths = array( $folder, 'wp-config.php', '.htaccess', 'web.config', 'wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/debug.log' );
		foreach ( array_merge( Exclusions::ALWAYS, Exclusions::BACKUP_PLUGIN_FOLDERS, Exclusions::CACHE_FOLDERS ) as $path ) {
			$paths[] = 'wp-content/' . $path;
		}
		if ( $no_media ) {
			$paths[] = 'wp-content/uploads';
		}
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$name = basename( (string) $dir );
			if ( in_array( $name, self::RESERVED, true ) ) {
				continue;
			}
			if ( file_exists( $dir . '/' . self::MARKER ) || file_exists( $dir . '/wp-config.php' ) || file_exists( $dir . '/wp-settings.php' ) ) {
				$paths[] = $name; // Another WordPress site, or another staging copy.
			}
		}
		return new Exclusions( $paths );
	}

	/**
	 * The live wp-config.php: in WordPress's folder, or the folder above when that one is not
	 * another WordPress's (as WordPress itself looks).
	 */
	public static function live_config(): ?string {
		$here  = ABSPATH . 'wp-config.php';
		$above = dirname( ABSPATH ) . '/wp-config.php';
		if ( is_readable( $here ) ) {
			return $here;
		}
		if ( is_readable( $above ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $above;
		}
		return null;
	}

	/**
	 * The web server, from what PHP reports: nginx, apache, litespeed or other.
	 */
	public static function server(): string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		foreach ( array( 'nginx', 'litespeed', 'apache' ) as $name ) {
			if ( false !== strpos( $software, $name ) ) {
				return $name;
			}
		}
		return 'other';
	}

	/**
	 * The nginx rule that pretty permalinks in the staging folder need.
	 *
	 * @param string $url Staging address.
	 */
	public static function nginx_rule( string $url ): string {
		$path = '/' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) . '/';
		return "location {$path} {\n\ttry_files \$uri \$uri/ {$path}index.php?\$args;\n}";
	}

	/**
	 * The .htaccess for the staging folder on Apache and LiteSpeed.
	 *
	 * @param string $url Staging address.
	 */
	public static function htaccess( string $url ): string {
		$path = '/' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) . '/';
		return "# BEGIN WordPress (SiteFerry staging copy)\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase {$path}\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . {$path}index.php [L]\n</IfModule>\n# END WordPress\n";
	}

	/**
	 * This site's tables: [name, bytes] for every table with its prefix, except SiteFerry's own
	 * temporary tables and other staging copies.
	 *
	 * @param \wpdb  $db   Connection.
	 * @param string $live Prefix.
	 * @return array<int,array{0:string,1:int}>
	 */
	public static function live_tables( $db, string $live ): array {
		$rows = Raw_Query::rows( $db, 'SHOW TABLE STATUS LIKE ' . Raw_Query::quote( $db, addcslashes( $live, '_%\\' ) . '%' ) );
		$out  = array();
		foreach ( $rows as $row ) {
			// SHOW TABLE STATUS: 0 Name, 6 Data_length, 8 Index_length (views have no sizes).
			$name = (string) ( isset( $row[0] ) ? $row[0] : '' );
			if ( '' === $name || 0 !== strpos( $name, $live ) || preg_match( '/^(sft|sfo)[a-f0-9]{6}_|^stg[a-f0-9]{4}_/', $name ) || ! isset( $row[1] ) ) {
				continue;
			}
			$out[] = array( $name, (int) ( isset( $row[6] ) ? $row[6] : 0 ) + (int) ( isset( $row[8] ) ? $row[8] : 0 ) );
		}
		return $out;
	}

	/**
	 * A free staging prefix: "stg" + 4 hex digits + "_", with no table using it.
	 *
	 * @param \wpdb  $db   Connection.
	 * @param string $live Live prefix.
	 * @throws Job_Exception If none is found.
	 */
	private static function new_prefix( $db, string $live ): string {
		for ( $try = 0; $try < 20; $try++ ) {
			$prefix = 'stg' . Storage::random_hex( 2 ) . '_';
			if ( 0 === strpos( $live, $prefix ) ) {
				continue;
			}
			if ( array() === Raw_Query::column( $db, 'SHOW TABLES LIKE ' . Raw_Query::quote( $db, addcslashes( $prefix, '_%\\' ) . '%' ) ) ) {
				return $prefix;
			}
		}
		throw new Job_Exception( __( 'No free table prefix was found for the staging copy.', 'siteferry' ), Job_Exception::STEP );
	}
}
