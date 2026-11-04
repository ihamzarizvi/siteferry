<?php
/**
 * The search/replace pairs for moving a site to a new address and folder.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Builds search => replace pairs from the old and new site facts.
 *
 * URLs are covered as http://, https:// and protocol-relative //, with and without "www."
 * (for real domain names), with JSON-escaped slashes and URL-encoded. Every scheme variant of the
 * old address becomes the new address with the new site's own scheme. Filesystem paths are covered
 * with forward slashes, backslashes (Windows) and JSON escaping. Trailing slashes are ignored, so
 * "http://old/" and "http://old" both map cleanly.
 */
final class Replacements {

	/** URL facts, most specific last does not matter: strtr() prefers longer matches. */
	const URL_KEYS = array( 'home', 'siteurl', 'wp_content_url', 'uploads_url' );

	/** Path facts. */
	const PATH_KEYS = array( 'abspath', 'wp_content_dir', 'uploads_dir' );

	/**
	 * Builds the pairs.
	 *
	 * @param array                $from  Old site facts (manifest site/paths/urls merged).
	 * @param array                $to    New site facts, same keys.
	 * @param array<int,string[]>  $extra Additional [search, replace] pairs from the user.
	 * @return array<string,string>
	 */
	public static function build( array $from, array $to, array $extra = array() ): array {
		$pairs = array();
		foreach ( self::URL_KEYS as $key ) {
			if ( ! empty( $from[ $key ] ) && ! empty( $to[ $key ] ) ) {
				self::add_url( $pairs, (string) $from[ $key ], (string) $to[ $key ] );
			}
		}
		foreach ( self::PATH_KEYS as $key ) {
			if ( ! empty( $from[ $key ] ) && ! empty( $to[ $key ] ) ) {
				self::add_path( $pairs, (string) $from[ $key ], (string) $to[ $key ] );
			}
		}
		foreach ( $extra as $pair ) {
			if ( isset( $pair[0], $pair[1] ) && '' !== (string) $pair[0] ) {
				$pairs[ (string) $pair[0] ] = (string) $pair[1];
				if ( false !== strpos( (string) $pair[0], '/' ) ) {
					$pairs[ self::json_slashes( (string) $pair[0] ) ] = self::json_slashes( (string) $pair[1] );
				}
			}
		}

		return array_filter(
			$pairs,
			static function ( $replace, $search ) {
				return (string) $search !== $replace;
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Adds every form of one old URL.
	 *
	 * @param array<string,string> $pairs Pairs being built.
	 * @param string               $old   Old URL.
	 * @param string               $new   New URL.
	 */
	private static function add_url( array &$pairs, string $old, string $new ): void {
		$old   = rtrim( $old, '/' );
		$new   = rtrim( $new, '/' );
		$parts = parse_url( $old ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( $old === $new || ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return;
		}

		$new_relative = (string) preg_replace( '#^[a-z][a-z0-9+.-]*:#i', '', $new );
		$tail         = ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . ( isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '' );

		foreach ( self::host_variants( $parts['host'] ) as $host ) {
			$authority = $host . $tail;
			$forms     = array(
				'http://' . $authority  => $new,
				'https://' . $authority => $new,
				'//' . $authority       => $new_relative,
			);
			foreach ( $forms as $search => $replace ) {
				$pairs[ $search ]                       = $replace;
				$pairs[ self::json_slashes( $search ) ] = self::json_slashes( $replace );
				if ( 0 !== strpos( $search, '//' ) ) {
					$pairs[ rawurlencode( $search ) ] = rawurlencode( $replace );
				}
			}
		}
	}

	/**
	 * Adds every form of one old filesystem path.
	 *
	 * @param array<string,string> $pairs Pairs being built.
	 * @param string               $old   Old path.
	 * @param string               $new   New path.
	 */
	private static function add_path( array &$pairs, string $old, string $new ): void {
		$old = rtrim( str_replace( '\\', '/', $old ), '/' );
		$new = rtrim( str_replace( '\\', '/', $new ), '/' );
		if ( '' === $old || $old === $new ) {
			return;
		}

		$pairs[ $old ]                       = $new;
		$pairs[ self::json_slashes( $old ) ] = self::json_slashes( $new );

		// Windows paths are also stored with backslashes, raw and JSON-escaped.
		if ( preg_match( '#^[A-Za-z]:/#', $old ) ) {
			$old_back = str_replace( '/', '\\', $old );
			$new_back = str_replace( '/', '\\', $new );
			$pairs[ $old_back ]                         = $new_back;
			$pairs[ str_replace( '\\', '\\\\', $old_back ) ] = str_replace( '\\', '\\\\', $new_back );
		}
	}

	/**
	 * The host with and without "www." (only for domain names, not localhost or IP addresses).
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	private static function host_variants( string $host ): array {
		$host = strtolower( $host );
		if ( false === strpos( $host, '.' ) || filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		return 0 === strpos( $host, 'www.' ) ? array( $host, substr( $host, 4 ) ) : array( $host, 'www.' . $host );
	}

	/**
	 * Slashes escaped as JSON writes them by default.
	 *
	 * @param string $text Text.
	 */
	private static function json_slashes( string $text ): string {
		return str_replace( '/', '\\/', $text );
	}
}
