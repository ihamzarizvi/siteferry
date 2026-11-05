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
			$problems[] = sprintf( /* translators: 1: a PHP version, such as 7.4, 2: the PHP version of this server */ __( 'PHP %1$s or newer is required (this server runs %2$s).', 'siteferry' ), self::MIN_PHP, PHP_VERSION );
		}
		if ( PHP_INT_SIZE < 8 ) {
			$problems[] = __( 'A 64-bit build of PHP is required to handle files larger than 2 GB.', 'siteferry' );
		}
		if ( ! function_exists( 'hash_init' ) || ! in_array( 'sha256', hash_algos(), true ) ) {
			$problems[] = __( 'The PHP hash extension with SHA-256 is required.', 'siteferry' );
		}
		if ( ! function_exists( 'json_encode' ) ) {
			$problems[] = __( 'The PHP JSON extension is required.', 'siteferry' );
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
