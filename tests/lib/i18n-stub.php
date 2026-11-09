<?php
/**
 * Stand-ins for WordPress's translation functions where WordPress is not loaded (the CLI tests and
 * tools): messages stay in English.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! function_exists( '__' ) ) {
	/**
	 * Returns the text as it is.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Stand-in for wp_parse_url(): PHP's parse_url() (WordPress adds handling for
	 * scheme-relative URLs, which SiteFerry does not pass).
	 *
	 * @param string $url       URL.
	 * @param int    $component A PHP_URL_* constant, or -1 for all parts.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * The singular or the plural, as English needs.
	 *
	 * @param string $single Singular.
	 * @param string $plural Plural.
	 * @param int    $number Number.
	 * @param string $domain Text domain.
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}
