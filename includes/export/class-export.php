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
 * Steps: scan wp-content, dump the database, pack the archive, finish (rename and clean up), and
 * send to remote storage when "send_to" names a destination.
 */
final class Export {

	const STEPS = array( 'export.scan', 'export.database', 'export.pack', 'export.finish' );

	const SEND_STEP = 'export.send';

	/** Option names a caller may set, with defaults. */
	const DEFAULTS = array(
		'title'                    => '',
		'note'                     => '',
		'tags'                     => array(),
		'format'                   => 'tar',
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
		'send_to'                  => '',
		'delete_after_send'        => false,
	);

	/**
	 * Creates an export job.
	 *
	 * @param array       $options  Export options (see DEFAULTS).
	 * @param string|null $work_dir Folder for the backup and job files instead of SiteFerry's
	 *                              storage folder. When given, nothing is written to the site.
	 * @param string      $origin   Who started it: "admin" (the admin screen or WP-CLI),
	 *                              "schedule" (which adds the schedule's last step) or "safety" (the
	 *                              safety backup an import makes first, inside its own job) or
	 *                              "update" (the quick backup before an update).
	 * @param string      $parent   For "safety": the import job it runs in.
	 * @return array{0:Job,1:string} The job and its token.
	 * @throws Job_Exception If the site cannot be exported.
	 */
	public static function start( array $options, ?string $work_dir = null, string $origin = 'admin', string $parent = '', array $then = array() ): array {
		if ( is_multisite() ) {
			throw new Job_Exception( 'Multisite networks are not supported by this version of SiteFerry.', Job_Exception::STEP );
		}

		// The password only gives the key; it is never saved.
		$password = isset( $options['password'] ) && is_string( $options['password'] ) ? $options['password'] : '';
		unset( $options['password'] );
		$options = self::normalize( $options );
		$site    = Site_Info::collect();
		$sealed  = null;
		if ( '' !== $password ) {
			$sealed = self::encryption( $password, $options );
		}

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

		$steps = self::STEPS;
		if ( 'wpress' === $options['format'] ) {
			$steps[] = \SiteFerry\Wpress\Export_Step::STEP;
		}
		if ( '' !== $options['send_to'] ) {
			// Fails now, not after the export, if the destination is not set up (or not connected).
			\SiteFerry\Storage\Destinations::require_ready( $options['send_to'] );
			$steps[] = self::SEND_STEP;
		}
		if ( 'schedule' === $origin ) {
			$steps[] = \SiteFerry\Schedule\Finish_Step::STEP;
		}
		$steps = array_merge( $steps, $then ); // Steps that use the finished backup (sending it to another site).

		$options['origin']      = in_array( $origin, array( 'schedule', 'safety', 'update' ), true ) ? $origin : 'admin';
		$options['parent']      = $parent; // A job that runs inside another one never counts as the active job.
		$options['site']        = $site;
		$options['exclusions']  = self::exclusions( $options, $site )->to_array();
		$options['output_dir']  = $output;
		$options['save_labels'] = null === $work_dir; // Exports to another folder write nothing to the site.
		$options['archive']    = self::archive_name( $site['home'], $options['compress'] ) . ( null === $sealed ? '' : \SiteFerry\Archive\Crypto::SUFFIX );
		$options['encrypted']  = null !== $sealed;

		$created = Job::create( $jobs, 'export', $steps, $options );
		if ( null !== $sealed ) {
			\SiteFerry\Security\Archive_Keys::store( $created[0], $sealed[1], $sealed[0] );
			$created[0]->save();
		}
		return $created;
	}

	/**
	 * Header and key for a backup protected with a password.
	 *
	 * @param string $password Password.
	 * @param array  $options  Normalized options.
	 * @return array{0:string,1:string}
	 * @throws Job_Exception If the password is too short, the format cannot be encrypted, or this
	 *                       server cannot encrypt.
	 */
	private static function encryption(
		#[\SensitiveParameter]
		string $password,
		array $options
	): array {
		if ( 'tar' !== $options['format'] ) {
			throw new Job_Exception( 'A .wpress file cannot be protected with a password by SiteFerry; choose the SiteFerry format.', Job_Exception::STEP );
		}
		if ( strlen( $password ) < \SiteFerry\Archive\Crypto::MIN_PASSWORD ) {
			throw new Job_Exception( sprintf( 'The password needs at least %d characters.', \SiteFerry\Archive\Crypto::MIN_PASSWORD ), Job_Exception::STEP );
		}
		if ( ! \SiteFerry\Archive\Crypto::available() ) {
			throw new Job_Exception( 'Encrypted backups need the PHP OpenSSL extension with AES-256-GCM, which this server does not have.', Job_Exception::STEP );
		}
		return \SiteFerry\Archive\Crypto::create( $password );
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
			} elseif ( is_string( $default ) ) {
				$value = trim( is_scalar( $value ) ? (string) $value : '' );
			} else {
				$value = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $value ) ), 'strlen' ) );
			}
			$normalized[ $key ] = $value;
		}

		$normalized['level'] = max( 1, min( 9, $normalized['level'] ) );
		$label               = \SiteFerry\Labels::normalize( isset( $options['title'] ) ? $options['title'] : '', isset( $options['note'] ) ? $options['note'] : '', isset( $options['tags'] ) ? $options['tags'] : array() );
		$normalized['title'] = $label['title'];
		$normalized['note']  = $label['note'];
		$normalized['tags']  = $label['tags'];
		$normalized['format'] = strtolower( $normalized['format'] );
		if ( ! in_array( $normalized['format'], array( 'tar', 'wpress' ), true ) ) {
			throw new Job_Exception( 'Unknown backup format; use "tar" or "wpress".', Job_Exception::STEP );
		}
		if ( 'wpress' === $normalized['format'] ) {
			$normalized['compress'] = false; // A .wpress file is made from a plain TAR.
		}
		if ( '' !== $normalized['send_to'] && ! preg_match( '/^[a-z0-9]{1,20}$/', $normalized['send_to'] ) ) {
			throw new Job_Exception( 'Unknown storage to send the backup to.', Job_Exception::STEP );
		}
		if ( '' === $normalized['send_to'] ) {
			$normalized['delete_after_send'] = false;
		}
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
			$paths[] = $plugins . '/' . \SiteFerry\Plugin::folder();
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
