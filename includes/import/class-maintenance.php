<?php
/**
 * WordPress maintenance mode during the swap.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and removes ABSPATH/.maintenance.
 *
 * Visitors see WordPress's maintenance message while tables and files are swapped. Requests that
 * carry the X-SiteFerry-Job header (the import page's own requests) are let through, so the job
 * can continue or roll back. WordPress itself ignores the file after ten minutes, so a crashed
 * request cannot keep the site offline.
 */
final class Maintenance {

	/** Marker that identifies the file as ours. */
	const MARKER = 'Written by SiteFerry';

	/**
	 * Path of the maintenance file.
	 */
	public static function file(): string {
		return ABSPATH . '.maintenance';
	}

	/**
	 * Turns maintenance mode on.
	 *
	 * @throws Job_Exception If another process put WordPress in maintenance mode.
	 */
	public static function enable(): void {
		$file = self::file();
		if ( is_file( $file ) && ! self::is_ours() ) {
			throw new Job_Exception( __( 'WordPress is already in maintenance mode (an update may be running). Try the import again later.', 'siteferry' ), Job_Exception::STEP );
		}
		$content = "<?php\n// " . self::MARKER . " while an import swaps the database and files; it is removed automatically.\n"
			. "// WordPress also ignores this file ten minutes after it was written.\n"
			. '$upgrading = isset( $_SERVER[\'HTTP_X_SITEFERRY_JOB\'] ) ? 0 : ' . time() . ";\n";
		$written = Io::quiet(
			static function () use ( $file, $content ) {
				return file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		);
		if ( false === $written ) {
			throw new Job_Exception( __( 'Cannot write the maintenance file in the WordPress folder.', 'siteferry' ), Job_Exception::STEP );
		}
	}

	/**
	 * Turns maintenance mode off, if SiteFerry turned it on.
	 */
	public static function disable(): void {
		$file = self::file();
		if ( is_file( $file ) && self::is_ours() ) {
			Io::quiet(
				static function () use ( $file ) {
					return unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
		}
	}

	/**
	 * Whether the current maintenance file was written by SiteFerry.
	 */
	private static function is_ours(): bool {
		$content = Io::quiet(
			static function () {
				return file_get_contents( self::file() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		);
		return is_string( $content ) && false !== strpos( $content, self::MARKER );
	}
}
