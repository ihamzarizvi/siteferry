<?php
/**
 * The staging copy's wp-config.php, made from the live one.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the text of wp-config.php with PHP's own tokenizer; the file is never run.
 *
 * Only these changes are made: the $table_prefix string becomes the staging prefix; definitions of
 * WP_HOME, WP_SITEURL, WP_ENVIRONMENT_TYPE, WP_CACHE and SITEFERRY_STAGING are removed and set
 * again, with the staging values, right after the opening tag. Everything else (database login,
 * keys, debug settings) stays as it is.
 *
 * A configuration that cannot be copied safely is refused with the reason: a table prefix that is
 * not a plain string, ABSPATH not set from the file's own folder, folders of wp-content or plugins
 * moved elsewhere, a network, or other files included by a path relative to the configuration
 * (the staging copy lives in another folder, so they would not be found).
 */
final class Config {

	/** Set again for the staging copy. */
	const REPLACED = array( 'WP_HOME', 'WP_SITEURL', 'WP_ENVIRONMENT_TYPE', 'WP_CACHE', 'SITEFERRY_STAGING' );

	/** A copy in a subfolder cannot keep these: they point at the live site's folders. */
	const REFUSED = array( 'WP_CONTENT_DIR', 'WP_CONTENT_URL', 'WP_PLUGIN_DIR', 'WP_PLUGIN_URL', 'PLUGINDIR', 'WPMU_PLUGIN_DIR', 'WPMU_PLUGIN_URL', 'UPLOADS', 'MULTISITE', 'WP_ALLOW_MULTISITE', 'SUBDOMAIN_INSTALL' );

	/**
	 * The staging wp-config.php.
	 *
	 * @param string $source  The live wp-config.php.
	 * @param string $prefix  Staging table prefix.
	 * @param string $url     Staging address (home and site URL).
	 * @param string $live    The live site's address (for the comment).
	 * @return string
	 * @throws Job_Exception If the configuration cannot be copied safely.
	 */
	public static function rewrite( string $source, string $prefix, string $url, string $live ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,20}$/', $prefix ) ) {
			throw new Job_Exception( __( 'The staging table prefix is not valid.', 'siteferry' ), Job_Exception::STEP );
		}
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$open   = null;
		$prefix_at = null;
		$remove = array(); // [first token, last token] of statements to drop.
		$abspath_ok = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( null === $open && is_array( $token ) && T_OPEN_TAG === $token[0] ) {
				$open = $i;
				continue;
			}
			if ( ! is_array( $token ) ) {
				continue;
			}
			// $table_prefix = '...';
			if ( T_VARIABLE === $token[0] && '$table_prefix' === $token[1] ) {
				$j = self::next( $tokens, $i );
				$k = null === $j ? null : self::next( $tokens, $j );
				$l = null === $k ? null : self::next( $tokens, $k );
				if ( null === $l || '=' !== $tokens[ $j ] || ! is_array( $tokens[ $k ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $k ][0] || ';' !== $tokens[ $l ] ) {
					throw new Job_Exception( __( 'wp-config.php sets the table prefix in a way SiteFerry cannot change safely (it must be a plain text value, such as $table_prefix = \'wp_\';).', 'siteferry' ), Job_Exception::STEP );
				}
				if ( null !== $prefix_at ) {
					throw new Job_Exception( __( 'wp-config.php sets the table prefix more than once; SiteFerry cannot tell which one counts.', 'siteferry' ), Job_Exception::STEP );
				}
				$prefix_at = $k;
				continue;
			}
			// define( 'NAME', ... );
			if ( T_STRING === $token[0] && 'define' === strtolower( $token[1] ) ) {
				$paren = self::next( $tokens, $i );
				$name  = null === $paren ? null : self::next( $tokens, $paren );
				if ( null === $name || '(' !== $tokens[ $paren ] || ! is_array( $tokens[ $name ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $name ][0] ) {
					continue;
				}
				$constant = strtoupper( (string) substr( $tokens[ $name ][1], 1, -1 ) );
				$end      = self::statement_end( $tokens, $paren );
				if ( null === $end ) {
					throw new Job_Exception( __( 'wp-config.php could not be read: a definition is not closed.', 'siteferry' ), Job_Exception::STEP );
				}
				if ( in_array( $constant, self::REFUSED, true ) ) {
					throw new Job_Exception( sprintf( /* translators: %s: a constant name, such as WP_CONTENT_DIR */ __( 'wp-config.php sets %s. A staging copy in a subfolder cannot keep that setting, so SiteFerry cannot make one for this site yet.', 'siteferry' ), $constant ), Job_Exception::STEP );
				}
				if ( 'ABSPATH' === $constant ) {
					$abspath_ok = self::uses_own_folder( $tokens, $name, $end );
				}
				if ( in_array( $constant, self::REPLACED, true ) ) {
					$remove[] = array( $i, $end );
				}
				$i = $end;
				continue;
			}
			// Other files included by a path relative to this one would not be found from the copy.
			if ( in_array( $token[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
				$end  = self::statement_end( $tokens, $i );
				$text = '';
				for ( $m = $i + 1; null !== $end && $m < $end; $m++ ) {
					$text .= is_array( $tokens[ $m ] ) ? $tokens[ $m ][1] : $tokens[ $m ];
				}
				$compact = (string) preg_replace( '/\s+/', '', $text );
				$compact = (string) preg_replace( '/^\((.*)\)$/', '$1', $compact );
				if ( "ABSPATH.'wp-settings.php'" !== $compact && 'ABSPATH."wp-settings.php"' !== $compact && ! preg_match( '#^\(?[\'"](/|[A-Za-z]:[\\\\/])#', $compact ) ) {
					throw new Job_Exception( __( 'wp-config.php loads another file by a path relative to its own folder. The staging copy lives in another folder, so SiteFerry cannot make one for this site yet.', 'siteferry' ), Job_Exception::STEP );
				}
			}
		}
		if ( null === $open ) {
			throw new Job_Exception( __( 'wp-config.php could not be read: it has no opening PHP tag.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( null === $prefix_at ) {
			throw new Job_Exception( __( 'wp-config.php does not set $table_prefix as a plain text value; SiteFerry cannot change it safely.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! $abspath_ok ) {
			throw new Job_Exception( __( 'wp-config.php does not set ABSPATH from its own folder (dirname( __FILE__ ) or __DIR__), so a copy in a subfolder would load the live site\'s files.', 'siteferry' ), Job_Exception::STEP );
		}

		$block = "\n// Added by SiteFerry: this is a staging copy of " . self::comment( $live ) . ".\n"
			. 'define( \'WP_HOME\', ' . var_export( $url, true ) . " );\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
			. 'define( \'WP_SITEURL\', ' . var_export( $url, true ) . " );\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
			. "define( 'WP_ENVIRONMENT_TYPE', 'staging' );\n"
			. "define( 'WP_CACHE', false );\n"
			. "define( 'SITEFERRY_STAGING', true );\n";

		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			foreach ( $remove as $span ) {
				if ( $i === $span[0] ) {
					$out .= '/* SiteFerry staging: definition replaced above. */';
					$i    = $span[1];
					continue 2;
				}
			}
			if ( $i === $prefix_at ) {
				$out .= var_export( $prefix, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
				continue;
			}
			$out .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			if ( $i === $open ) {
				$out .= ( "\n" === substr( $tokens[ $i ][1], -1 ) ? '' : "\n" ) . ltrim( $block, "\n" );
			}
		}
		return $out;
	}

	/**
	 * The table prefix set in a wp-config.php, or null when it is not a plain string.
	 *
	 * @param string $source wp-config.php.
	 */
	public static function prefix( string $source ): ?string {
		$tokens = token_get_all( $source );
		foreach ( $tokens as $i => $token ) {
			if ( is_array( $token ) && T_VARIABLE === $token[0] && '$table_prefix' === $token[1] ) {
				$j = self::next( $tokens, $i );
				$k = null === $j ? null : self::next( $tokens, $j );
				if ( null !== $k && '=' === $tokens[ $j ] && is_array( $tokens[ $k ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0] ) {
					return stripcslashes( substr( $tokens[ $k ][1], 1, -1 ) );
				}
			}
		}
		return null;
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      From.
	 */
	private static function next( array $tokens, int $i ): ?int {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $j;
		}
		return null;
	}

	/**
	 * Index of the ";" that ends the statement started before $from (outside brackets).
	 *
	 * @param array $tokens Tokens.
	 * @param int   $from   Start.
	 */
	private static function statement_end( array $tokens, int $from ): ?int {
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $from; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t || '[' === $t ) {
				++$depth;
			} elseif ( ')' === $t || ']' === $t ) {
				--$depth;
			} elseif ( ';' === $t && $depth <= 0 ) {
				return $j;
			} elseif ( is_array( $t ) && T_CLOSE_TAG === $t[0] && $depth <= 0 ) {
				return $j;
			}
		}
		return null;
	}

	/**
	 * Whether ABSPATH's value is the configuration's own folder (__DIR__ or dirname( __FILE__ )).
	 *
	 * @param array $tokens Tokens.
	 * @param int   $from   The name token.
	 * @param int   $end    End of the definition.
	 */
	private static function uses_own_folder( array $tokens, int $from, int $end ): bool {
		$text = '';
		for ( $m = $from + 1; $m < $end; $m++ ) {
			$text .= is_array( $tokens[ $m ] ) ? $tokens[ $m ][1] : $tokens[ $m ];
		}
		$compact = strtolower( (string) preg_replace( '/\s+/', '', $text ) );
		return (bool) preg_match( '/^,(__dir__|dirname\(__file__\))\.[\'"]\/[\'"]\)$/', $compact );
	}

	/**
	 * A value safe inside a one-line comment.
	 *
	 * @param string $text Text.
	 */
	private static function comment( string $text ): string {
		return (string) preg_replace( '/[^A-Za-z0-9.:\/_-]+/', '', $text );
	}
}
