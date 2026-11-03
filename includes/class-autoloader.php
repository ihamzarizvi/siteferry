<?php
/**
 * Class autoloader for the SiteFerry namespace.
 *
 * Maps SiteFerry\Some_Area\Some_Class to includes/some-area/class-some-class.php
 * (or interface-some-class.php for interfaces).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin classes on demand.
 */
final class Autoloader {

	const PREFIX = 'SiteFerry\\';

	/**
	 * Registers the autoloader once.
	 */
	public static function register(): void {
		static $registered = false;
		if ( ! $registered ) {
			spl_autoload_register( array( __CLASS__, 'load' ) );
			$registered = true;
		}
	}

	/**
	 * Loads one class if it belongs to the plugin.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class, strlen( self::PREFIX ) ) );
		$name  = self::slug( array_pop( $parts ) );
		$dir   = __DIR__;
		foreach ( $parts as $part ) {
			$dir .= '/' . self::slug( $part );
		}

		foreach ( array( 'class', 'interface', 'trait' ) as $kind ) {
			$file = $dir . '/' . $kind . '-' . $name . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
				return;
			}
		}
	}

	/**
	 * Converts a class or namespace segment to a file name slug.
	 *
	 * @param string $segment For example "Tar_Writer".
	 * @return string For example "tar-writer".
	 */
	private static function slug( string $segment ): string {
		return strtolower( str_replace( '_', '-', $segment ) );
	}
}
