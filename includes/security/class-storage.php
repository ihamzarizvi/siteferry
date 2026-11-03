<?php
/**
 * The protected folder that holds backups and job files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Security;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Locates and protects SiteFerry's storage folder: wp-content/siteferry-<random>/.
 *
 * The random folder name and random backup names are the protection on servers that ignore
 * .htaccess and web.config (nginx). Downloads always go through an authenticated PHP request.
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
		return self::ensure( WP_CONTENT_DIR . '/' . self::name() );
	}

	/**
	 * Folder name inside wp-content (creates the option on first use).
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
	 * Folder for finished backups.
	 */
	public static function backups_dir(): string {
		return self::ensure( self::root() . '/backups' );
	}

	/**
	 * Backups folder if SiteFerry has created one, without creating anything.
	 */
	public static function existing_backups_dir(): ?string {
		$name = get_option( self::OPTION );
		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			return null;
		}
		$dir = WP_CONTENT_DIR . '/' . $name . '/backups';
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
					return mkdir( $dir, 0755, true );
				}
			);
			if ( ! $made && ! is_dir( $dir ) ) {
				throw new Archive_Exception( sprintf( 'Cannot create the folder "%s". Check that wp-content is writable.', $dir ), Archive_Exception::IO );
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
