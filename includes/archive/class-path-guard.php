<?php
/**
 * Validation of paths stored in archives.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * Accepts only relative paths that stay inside the extraction folder.
 */
final class Path_Guard {

	/**
	 * Validates an archive path and returns it normalized ("a/b/c": no "." segments, no repeated or trailing slashes).
	 *
	 * Rejected everywhere: empty paths, NUL and control characters, invalid UTF-8, backslashes,
	 * absolute paths, drive letters and ".." segments. Also rejected when $windows is true: colons
	 * (alternate data streams), characters Windows cannot store, trailing dots or spaces (Windows
	 * silently strips them) and reserved device names such as CON or NUL.
	 *
	 * @param string    $path    Path from the archive.
	 * @param bool|null $windows Apply Windows rules; null means "if this server runs Windows".
	 * @return string Normalized path.
	 * @throws Archive_Exception With code UNSAFE_PATH.
	 */
	public static function check( string $path, ?bool $windows = null ): string {
		if ( null === $windows ) {
			$windows = '\\' === DIRECTORY_SEPARATOR;
		}

		if ( '' === $path ) {
			self::reject( $path, 'empty name' );
		}
		if ( 1 !== preg_match( '//u', $path ) ) {
			self::reject( $path, 'not valid UTF-8' );
		}
		if ( preg_match( '/[\x00-\x1f\x7f]/', $path ) ) {
			self::reject( $path, 'control character' );
		}
		if ( false !== strpos( $path, '\\' ) ) {
			self::reject( $path, 'backslash' );
		}
		if ( '/' === $path[0] ) {
			self::reject( $path, 'absolute path' );
		}
		if ( preg_match( '/^[A-Za-z]:/', $path ) ) {
			self::reject( $path, 'drive letter' );
		}

		$clean = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				self::reject( $path, 'parent folder reference' );
			}
			if ( $windows ) {
				self::check_windows_segment( $path, $segment );
			}
			$clean[] = $segment;
		}

		if ( array() === $clean ) {
			self::reject( $path, 'empty name' );
		}
		return implode( '/', $clean );
	}

	/**
	 * Safe, single-line, shortened form of a path for messages (still needs HTML escaping).
	 *
	 * @param string $path Any path.
	 */
	public static function printable( string $path ): string {
		$path = (string) preg_replace( '/[\x00-\x1f\x7f]/', '?', $path );
		if ( 1 !== preg_match( '//u', $path ) ) {
			$path = (string) preg_replace( '/[\x80-\xff]/', '?', $path );
		}
		return strlen( $path ) > 300 ? substr( $path, 0, 300 ) . '...' : $path;
	}

	/**
	 * Applies Windows file name rules to one segment.
	 *
	 * @param string $path    Whole path, for the message.
	 * @param string $segment One path segment.
	 */
	private static function check_windows_segment( string $path, string $segment ): void {
		if ( false !== strpos( $segment, ':' ) ) {
			self::reject( $path, 'colon in name' );
		}
		if ( preg_match( '/[<>"|?*]/', $segment ) ) {
			self::reject( $path, 'character not allowed on Windows' );
		}
		if ( preg_match( '/[. ]$/', $segment ) ) {
			self::reject( $path, 'name ends with a dot or space' );
		}
		if ( preg_match( '/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(\..*)?$/i', $segment ) ) {
			self::reject( $path, 'reserved Windows device name' );
		}
	}

	/**
	 * Throws the rejection.
	 *
	 * @param string $path   Offending path.
	 * @param string $reason Why it was rejected.
	 * @throws Archive_Exception Always.
	 */
	private static function reject( string $path, string $reason ): void {
		throw new Archive_Exception(
			sprintf( /* translators: 1: the reason, 2: a file or backup name */ __( 'Unsafe path in archive (%1$s): "%2$s"', 'siteferry' ), $reason, self::printable( $path ) ),
			Archive_Exception::UNSAFE_PATH
		);
	}
}
