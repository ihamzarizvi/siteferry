<?php
/**
 * The protected folder that holds backups and job files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Security;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Locates and protects SiteFerry's storage folder: siteferry-<random>/ in the media (uploads) folder.
 *
 * The random folder name and random backup names are the protection on servers that ignore
 * .htaccess and web.config (nginx). Downloads always go through an authenticated PHP request.
 *
 * Versions before 0.47.0 kept the folder in wp-content. Such a folder is moved into the media
 * folder when no job is running (move_legacy()); until then, it is used where it is.
 */
final class Storage {

	/** Option holding the folder name. */
	const OPTION = 'siteferry_storage';

	/** Folder names SiteFerry generates; used to exclude every SiteFerry folder from backups. */
	const NAME_PATTERN = '/^siteferry-[a-f0-9]{12}$/';

	/**
	 * Absolute path of the storage folder, created and protected on first use.
	 *
	 * @throws Archive_Exception If the folder cannot be created.
	 */
	public static function root(): string {
		return self::ensure( self::place( self::name() ) );
	}

	/**
	 * Folder name (creates the option on first use).
	 */
	public static function name(): string {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			$name = 'siteferry-' . bin2hex( random_bytes( 6 ) );
			update_option( self::OPTION, $name, false );
		}
		return $name;
	}

	/**
	 * Where a storage folder of this name is: in the media folder, or in wp-content while a folder
	 * made by an older version is still there.
	 *
	 * @param string $name Folder name.
	 */
	private static function place( string $name ): string {
		$dir = \SiteFerry\Paths::uploads() . '/' . $name;
		if ( ! is_dir( $dir ) ) {
			$legacy = self::legacy( $name );
			if ( null !== $legacy ) {
				return $legacy;
			}
		}
		return $dir;
	}

	/**
	 * The folder an older version made in wp-content, if it is there.
	 *
	 * @param string $name Folder name.
	 */
	private static function legacy( string $name ): ?string {
		$dir = \SiteFerry\Paths::content() . '/' . $name;
		return is_dir( $dir ) && ! Io::is_link_or_junction( $dir ) ? $dir : null;
	}

	/**
	 * Moves a storage folder an older version made in wp-content into the media folder, unless a
	 * job is running. Returns false while a job runs (to try again later). When the folder cannot
	 * be moved (for example, the media folder is on another disk), it stays where it is and is used
	 * there.
	 */
	public static function move_legacy(): bool {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			return true;
		}
		$legacy = self::legacy( $name );
		if ( null === $legacy ) {
			return true;
		}
		if ( null !== \SiteFerry\Jobs\Job::active( $legacy . '/jobs' ) ) {
			return false;
		}
		$uploads = \SiteFerry\Paths::uploads();
		$dir     = $uploads . '/' . $name;
		if ( file_exists( $dir ) || ( ! is_dir( $uploads ) && ! wp_mkdir_p( $uploads ) ) ) {
			return true;
		}
		Io::quiet(
			static function () use ( $legacy, $dir ) {
				return rename( $legacy, $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- SiteFerry's own folder; runs in cron and WP-CLI, where WP_Filesystem may need credentials.
			}
		);
		return true;
	}

	/**
	 * Folder for finished backups.
	 */
	public static function backups_dir(): string {
		return self::ensure( self::root() . '/backups' );
	}

	/**
	 * Backups folder if SiteFerry has created one, without creating anything.
	 */
	public static function existing_backups_dir(): ?string {
		return self::existing( 'backups' );
	}

	/**
	 * Jobs folder if SiteFerry has created one, without creating anything.
	 */
	public static function existing_jobs_dir(): ?string {
		return self::existing( 'jobs' );
	}

	/**
	 * A folder in the storage folder if SiteFerry has created it, without creating anything.
	 *
	 * @param string $sub Folder name.
	 */
	private static function existing( string $sub ): ?string {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			return null;
		}
		$dir = self::place( $name ) . '/' . $sub;
		return is_dir( $dir ) ? $dir : null;
	}

	/**
	 * Folder for job state and temporary files.
	 */
	public static function jobs_dir(): string {
		return self::ensure( self::root() . '/jobs' );
	}

	/**
	 * Creates a folder if needed and adds the deny files.
	 *
	 * @param string $dir Folder.
	 * @return string The folder.
	 * @throws Archive_Exception If it cannot be created.
	 */
	public static function ensure( string $dir ): string {
		if ( ! is_dir( $dir ) ) {
			$made = Io::quiet(
				static function () use ( $dir ) {
					return mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- SiteFerry's own files; runs in cron and WP-CLI, where WP_Filesystem may need credentials.
				}
			);
			if ( ! $made && ! is_dir( $dir ) ) {
				throw new Archive_Exception( sprintf( /* translators: %s: dir */ __( 'Cannot create the folder "%s". Check that the uploads folder is writable.', 'siteferry' ), $dir ), Archive_Exception::IO );
			}
		}
		self::protect( $dir );
		return $dir;
	}

	/**
	 * Writes index.php, .htaccess and web.config so web servers that honour them refuse direct access.
	 *
	 * @param string $dir Existing folder.
	 */
	public static function protect( string $dir ): void {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Written by SiteFerry: nothing in this folder may be served directly.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<!-- Written by SiteFerry: nothing in this folder may be served directly. -->\n"
				. "<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n"
				. "\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n"
				. "\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n"
				. "\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;
			if ( ! file_exists( $path ) ) {
				Io::quiet(
					static function () use ( $path, $content ) {
						return file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					}
				);
			}
		}
	}

	/**
	 * A random, unguessable token of $bytes bytes, as lowercase hex.
	 *
	 * @param int $bytes Entropy in bytes.
	 */
	public static function random_hex( int $bytes ): string {
		return bin2hex( random_bytes( $bytes ) );
	}
}
