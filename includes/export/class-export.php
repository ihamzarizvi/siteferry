<?php
/**
 * Starting an export.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Path_Guard;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Validates export options and creates the export job.
 *
 * Steps: scan wp-content, dump the database, pack the archive, finish (rename and clean up).
 */
final class Export {

	const STEPS = array( 'export.scan', 'export.database', 'export.pack', 'export.finish' );

	/** Option names a caller may set, with defaults. */
	const DEFAULTS = array(
		'compress'                 => false,
		'level'                    => 6,
		'exclude_media'            => false,
		'exclude_plugins'          => false,
		'exclude_themes'           => false,
		'exclude_inactive_plugins' => false,
		'exclude_inactive_themes'  => false,
		'exclude_cache'            => true,
		'exclude_spam_comments'    => false,
		'exclude_revisions'        => false,
		'exclude_tables'           => array(),
		'exclude_paths'            => array(),
		'snapshot'                 => false,
	);

	/**
	 * Creates an export job.
	 *
	 * @param array       $options  Export options (see DEFAULTS).
	 * @param string|null $work_dir Folder for the backup and job files instead of SiteFerry's
	 *                              storage folder. When given, nothing is written to the site.
	 * @return array{0:Job,1:string} The job and its token.
	 * @throws Job_Exception If the site cannot be exported.
	 */
	public static function start( array $options, ?string $work_dir = null ): array {
		if ( is_multisite() ) {
			throw new Job_Exception( 'Multisite networks are not supported by this version of SiteFerry.', Job_Exception::STEP );
		}

		$options = self::normalize( $options );
		$site    = Site_Info::collect();

		if ( null === $work_dir ) {
			$jobs   = Storage::jobs_dir();
			$output = Storage::backups_dir();
		} else {
			$output = rtrim( str_replace( '\\', '/', $work_dir ), '/' );
			$jobs   = $output . '/jobs';
			if ( ! is_dir( $jobs ) && ! mkdir( $jobs, 0755, true ) && ! is_dir( $jobs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				throw new Job_Exception( sprintf( 'Cannot create the folder "%s".', $jobs ), Job_Exception::STEP );
			}
		}

		$options['site']       = $site;
		$options['exclusions'] = self::exclusions( $options, $site )->to_array();
		$options['output_dir'] = $output;
		$options['archive']    = self::archive_name( $site['home'], $options['compress'] );

		return Job::create( $jobs, 'export', self::STEPS, $options );
	}

	/**
	 * Fills in defaults and validates types.
	 *
	 * @param array $options Raw options.
	 * @throws Job_Exception On invalid values.
	 */
	public static function normalize( array $options ): array {
		$unknown = array_diff( array_keys( $options ), array_keys( self::DEFAULTS ) );
		if ( array() !== $unknown ) {
			throw new Job_Exception( 'Unknown export option: ' . implode( ', ', $unknown ), Job_Exception::STEP );
		}

		$normalized = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = array_key_exists( $key, $options ) ? $options[ $key ] : $default;
			if ( is_bool( $default ) ) {
				$value = (bool) $value;
			} elseif ( is_int( $default ) ) {
				$value = (int) $value;
			} else {
				$value = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $value ) ), 'strlen' ) );
			}
			$normalized[ $key ] = $value;
		}

		$normalized['level'] = max( 1, min( 9, $normalized['level'] ) );
		if ( $normalized['compress'] && ! function_exists( 'deflate_init' ) ) {
			throw new Job_Exception( 'Compression needs the PHP zlib extension.', Job_Exception::STEP );
		}

		$paths = array();
		foreach ( $normalized['exclude_paths'] as $path ) {
			$path = trim( str_replace( '\\', '/', $path ), '/' );
			if ( 0 === strpos( $path, 'wp-content/' ) ) {
				$path = substr( $path, strlen( 'wp-content/' ) );
			}
			try {
				// "*" is allowed as a wildcard; everything else must be a safe relative path.
				Path_Guard::check( str_replace( '*', 'x', $path ), false );
			} catch ( Archive_Exception $e ) {
				throw new Job_Exception( sprintf( 'Invalid path to leave out: "%s". Use paths inside wp-content, such as uploads/2019.', Path_Guard::printable( $path ) ), Job_Exception::STEP );
			}
			$paths[] = $path;
		}
		$normalized['exclude_paths'] = $paths;

		return $normalized;
	}

	/**
	 * The exclusion rules for these options.
	 *
	 * @param array $options Normalized options.
	 * @param array $site    Site_Info::collect() output.
	 */
	public static function exclusions( array $options, array $site ): Exclusions {
		$content = $site['wp_content_dir'];
		$paths   = array_merge( Exclusions::ALWAYS, Exclusions::BACKUP_PLUGIN_FOLDERS, $options['exclude_paths'] );

		if ( $options['exclude_cache'] ) {
			$paths = array_merge( $paths, Exclusions::CACHE_FOLDERS );
		}

		$plugins = self::relative( $site['plugins_dir'], $content );
		$themes  = self::relative( $site['themes_dir'], $content );
		$uploads = self::relative( $site['uploads_dir'], $content );

		if ( null !== $plugins ) {
			// SiteFerry itself never travels with a backup; the destination keeps its own copy.
			$paths[] = $plugins . '/' . self::own_folder();
			if ( $options['exclude_plugins'] ) {
				$paths[] = $plugins;
			} elseif ( $options['exclude_inactive_plugins'] ) {
				$paths = array_merge( $paths, self::inactive_plugins( $site['plugins_dir'], $plugins, $site['active_plugins'] ) );
			}
		}
		if ( null !== $themes ) {
			if ( $options['exclude_themes'] ) {
				$paths[] = $themes;
			} elseif ( $options['exclude_inactive_themes'] ) {
				$paths = array_merge( $paths, self::inactive_themes( $site['themes_dir'], $themes, array( $site['stylesheet'], $site['template'] ) ) );
			}
		}
		if ( null !== $uploads && $options['exclude_media'] ) {
			$paths[] = $uploads;
		}

		return new Exclusions( $paths );
	}

	/**
	 * Backup file name: <site>-<YYYY-MM-DD>-<random>.tar[.gz].
	 *
	 * @param string $home     Home URL.
	 * @param bool   $compress gzip.
	 */
	public static function archive_name( string $home, bool $compress ): string {
		$parts = wp_parse_url( $home );
		$label = ( isset( $parts['host'] ) ? $parts['host'] : 'site' )
			. ( isset( $parts['port'] ) ? '-' . $parts['port'] : '' )
			. ( isset( $parts['path'] ) ? '-' . $parts['path'] : '' );
		$label = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $label ) ), '-' );
		$label = '' === $label ? 'site' : substr( $label, 0, 40 );

		return sprintf( '%s-%s-%s.%s', rtrim( $label, '-' ), wp_date( 'Y-m-d' ), Storage::random_hex( 6 ), $compress ? 'tar.gz' : 'tar' );
	}

	/**
	 * Path of $path relative to $base, or null if it is not inside it.
	 *
	 * @param string $path Absolute path (forward slashes).
	 * @param string $base Absolute folder (forward slashes, no trailing slash).
	 */
	private static function relative( string $path, string $base ): ?string {
		$windows = '\\' === DIRECTORY_SEPARATOR;
		$prefix  = $base . '/';
		$match   = $windows ? 0 === stripos( $path, $prefix ) : 0 === strpos( $path, $prefix );
		return $match ? substr( $path, strlen( $prefix ) ) : null;
	}

	/**
	 * Folder name of this plugin inside the plugins folder.
	 */
	private static function own_folder(): string {
		$basename = function_exists( 'plugin_basename' ) ? plugin_basename( SITEFERRY_FILE ) : '';
		$folder   = false !== strpos( $basename, '/' ) ? dirname( $basename ) : '';
		return '' !== $folder && '.' !== $folder ? $folder : basename( SITEFERRY_DIR );
	}

	/**
	 * Paths of plugin folders and single-file plugins that are not active.
	 *
	 * @param string   $dir      Plugins folder.
	 * @param string   $relative Plugins folder relative to wp-content.
	 * @param string[] $active   Active plugin basenames ("folder/file.php" or "file.php").
	 * @return string[]
	 */
	private static function inactive_plugins( string $dir, string $relative, array $active ): array {
		$active_folders = array();
		foreach ( $active as $basename ) {
			$slash = strpos( $basename, '/' );
			$active_folders[ false === $slash ? $basename : substr( $basename, 0, $slash ) ] = true;
		}

		$inactive = array();
		foreach ( self::folder_entries( $dir ) as $name ) {
			if ( isset( $active_folders[ $name ] ) ) {
				continue;
			}
			// Folders are plugins; loose files only when they are PHP (index.php is a guard file, kept).
			if ( is_dir( $dir . '/' . $name ) || ( '.php' === substr( $name, -4 ) && 'index.php' !== $name ) ) {
				$inactive[] = $relative . '/' . $name;
			}
		}
		return $inactive;
	}

	/**
	 * Paths of theme folders other than the active theme and its parent.
	 *
	 * @param string   $dir      Themes folder.
	 * @param string   $relative Themes folder relative to wp-content.
	 * @param string[] $active   Active stylesheet and template.
	 * @return string[]
	 */
	private static function inactive_themes( string $dir, string $relative, array $active ): array {
		$inactive = array();
		foreach ( self::folder_entries( $dir ) as $name ) {
			if ( ! in_array( $name, $active, true ) && is_dir( $dir . '/' . $name ) ) {
				$inactive[] = $relative . '/' . $name;
			}
		}
		return $inactive;
	}

	/**
	 * Names in a folder, without hidden entries ("." and ".." included).
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	private static function folder_entries( string $dir ): array {
		$names = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( false === $names ) {
			return array();
		}
		return array_values(
			array_filter(
				$names,
				static function ( string $name ): bool {
					return '' !== $name && '.' !== $name[0];
				}
			)
		);
	}
}
