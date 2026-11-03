<?php
/**
 * Flags calls to functions that do not exist in PHP 7.4.
 *
 * Syntax is checked separately by running "php -l" with PHP 7.4 itself; this catches what the
 * linter cannot: newer built-in functions that would only fail when the line runs.
 *
 * Run: php tests/compat-check.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// Built-in functions added after PHP 7.4, by version.
$newer = array(
	'8.0' => array( 'str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'get_resource_id', 'fdiv', 'preg_last_error_msg' ),
	'8.1' => array( 'array_is_list', 'fsync', 'fdatasync', 'enum_exists' ),
	'8.2' => array( 'ini_parse_quantity', 'memory_reset_peak_usage', 'mysqli_execute_query', 'openssl_cipher_key_length', 'sodium_crypto_stream_xchacha20_xor_ic' ),
	'8.3' => array( 'json_validate', 'mb_str_pad', 'str_increment', 'str_decrement', 'stream_context_set_options' ),
	'8.4' => array( 'array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst', 'bcdivmod', 'fpow', 'request_parse_body', 'http_get_last_response_headers', 'http_clear_last_response_headers' ),
	'8.5' => array( 'array_first', 'array_last', 'get_error_handler', 'get_exception_handler' ),
);
$lookup = array();
foreach ( $newer as $version => $functions ) {
	foreach ( $functions as $function ) {
		$lookup[ $function ] = $version;
	}
}

$root     = dirname( __DIR__ );
$problems = 0;
$files    = 0;
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $iterator as $info ) {
	$path = $info->getPathname();
	if ( 'php' !== $info->getExtension() || preg_match( '#[\\\\/](\.git|tmp|vendor|node_modules)[\\\\/]#', $path ) ) {
		continue;
	}
	++$files;
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] ) {
			continue;
		}
		$name = strtolower( ltrim( $tokens[ $i ][1], '\\' ) );
		if ( ! isset( $lookup[ $name ] ) ) {
			continue;
		}

		// Next significant token must be "(" (a call) and the previous one must not make it a method,
		// a declaration or a namespaced name.
		$next = $i + 1;
		while ( $next < $count && is_array( $tokens[ $next ] ) && T_WHITESPACE === $tokens[ $next ][0] ) {
			++$next;
		}
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			--$prev;
		}
		$is_call   = $next < $count && '(' === $tokens[ $next ];
		$prev_kind = $prev >= 0 && is_array( $tokens[ $prev ] ) ? $tokens[ $prev ][0] : null;
		if ( ! $is_call || in_array( $prev_kind, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
			continue;
		}
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $prev_kind ) {
			continue;
		}

		// A function_exists() guard on the same line is treated as intentional.
		$line_text = explode( "\n", (string) file_get_contents( $path ) )[ $tokens[ $i ][2] - 1 ];
		if ( false !== strpos( $line_text, 'function_exists' ) ) {
			continue;
		}

		printf( "%s:%d: %s() needs PHP %s\n", substr( $path, strlen( $root ) + 1 ), $tokens[ $i ][2], $name, $lookup[ $name ] );
		++$problems;
	}
}

printf( "compat-check: %d files scanned, %d problem(s)\n", $files, $problems );
exit( $problems > 0 ? 1 : 0 );
