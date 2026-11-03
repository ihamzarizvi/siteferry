<?php
/**
 * Server requirement checks.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Lists what the server is missing, if anything.
 */
final class Requirements {

	const MIN_PHP = '7.4.0';

	/**
	 * Returns human-readable problems; an empty array means the server is fine.
	 *
	 * Messages are plain text; callers escape them for output.
	 *
	 * @return string[]
	 */
	public static function problems(): array {
		$problems = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			$problems[] = sprintf( 'PHP %s or newer is required (this server runs %s).', self::MIN_PHP, PHP_VERSION );
		}
		if ( PHP_INT_SIZE < 8 ) {
			$problems[] = 'A 64-bit build of PHP is required to handle files larger than 2 GB.';
		}
		if ( ! function_exists( 'hash_init' ) || ! in_array( 'sha256', hash_algos(), true ) ) {
			$problems[] = 'The PHP hash extension with SHA-256 is required.';
		}
		if ( ! function_exists( 'json_encode' ) ) {
			$problems[] = 'The PHP JSON extension is required.';
		}

		return $problems;
	}

	/**
	 * Whether compressed (.tar.gz) archives can be written and read.
	 */
	public static function can_compress(): bool {
		return function_exists( 'deflate_init' ) && function_exists( 'inflate_get_read_len' );
	}
}
