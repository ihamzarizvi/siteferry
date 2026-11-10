<?php
/**
 * Machine-readable output for the WP-CLI commands.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * How a command reports, the same way everywhere:
 *
 * - Commands that list or report something take --format=table|csv|json|yaml|count (where a list
 *   makes sense); table is the default, the others print nothing else.
 * - Commands that do something take --porcelain (print only the key result, such as the backup's
 *   path) and --json (one JSON object: "ok", the result fields, "warnings"). Where --format is not
 *   already an option of the command, --format=json means the same as --json. ("wp siteferry
 *   export" keeps --format for tar or wpress.)
 * - In --json and --porcelain modes no progress or other lines are printed; a failure in --json
 *   mode prints {"ok":false,"error":"..."} on STDOUT. The exit code is 0 on success and 1 on any
 *   error, in every mode.
 *
 * Field names are shared: name (backup file name), path (absolute path), size (bytes), created
 * (UTC, ISO 8601), place ("local" or a storage id), status, where (a storage's label).
 */
final class Output {

	const HUMAN     = 'human';
	const JSON      = 'json';
	const PORCELAIN = 'porcelain';

	/** List formats WP-CLI's format_items handles. */
	const LIST_FORMATS = array( 'table', 'csv', 'json', 'yaml', 'count' );

	/**
	 * The mode of a command that does something.
	 *
	 * @param array $assoc_args Options.
	 * @param bool  $format_free Whether --format is free to mean json (not the command's own option).
	 */
	public static function mode( array $assoc_args, bool $format_free = true ): string {
		if ( ! empty( $assoc_args['json'] ) || ( $format_free && isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) ) {
			return self::JSON;
		}
		return ! empty( $assoc_args['porcelain'] ) ? self::PORCELAIN : self::HUMAN;
	}

	/**
	 * The JSON object of a result: "ok" first, then the fields, then "warnings".
	 *
	 * @param array    $fields   Result fields.
	 * @param string[] $warnings Warnings.
	 */
	public static function payload( array $fields, array $warnings = array() ): array {
		return array_merge( array( 'ok' => true ), $fields, array( 'warnings' => array_values( array_map( 'strval', $warnings ) ) ) );
	}

	/**
	 * JSON text, pretty and with plain slashes and Unicode.
	 *
	 * @param mixed $data Data.
	 */
	public static function encode( $data ): string {
		return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- also used without WordPress (tests).
	}

	/**
	 * A list row's size for the chosen format: readable in a table, bytes elsewhere.
	 *
	 * @param int    $bytes  Size.
	 * @param string $format Format.
	 * @return int|string
	 */
	public static function size( int $bytes, string $format ) {
		return 'table' === $format && function_exists( 'size_format' ) ? (string) size_format( $bytes, 1 ) : $bytes;
	}

	/**
	 * The format of a list command, checked.
	 *
	 * @param array    $assoc_args Options.
	 * @param string[] $allowed    Formats this command offers.
	 */
	public static function list_format( array $assoc_args, array $allowed = self::LIST_FORMATS ): string {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( ! in_array( $format, $allowed, true ) ) {
			WP_CLI::error( sprintf( 'Unknown --format "%s". Use: %s.', $format, implode( ', ', $allowed ) ) );
		}
		return $format;
	}

	/**
	 * Prints the outcome of a command that did something.
	 *
	 * @param string   $mode      Mode.
	 * @param array    $fields    Result fields (for --json).
	 * @param string   $porcelain The key result (for --porcelain).
	 * @param string   $message   Success message (human mode).
	 * @param string[] $warnings  Warnings.
	 */
	public static function done( string $mode, array $fields, string $porcelain, string $message, array $warnings = array() ): void {
		if ( self::JSON === $mode ) {
			WP_CLI::line( self::encode( self::payload( $fields, $warnings ) ) );
			return;
		}
		if ( self::PORCELAIN === $mode ) {
			if ( '' !== $porcelain ) {
				WP_CLI::line( $porcelain );
			}
			return;
		}
		WP_CLI::success( $message );
	}

	/**
	 * Stops with an error, as JSON on STDOUT in --json mode; exit code 1 either way.
	 *
	 * @param string $mode    Mode.
	 * @param string $message Error.
	 */
	public static function fail( string $mode, string $message ): void {
		if ( self::JSON === $mode ) {
			WP_CLI::line(
				self::encode(
					array(
						'ok'    => false,
						'error' => $message,
					)
				)
			);
			WP_CLI::halt( 1 );
		}
		WP_CLI::error( $message );
	}

	/**
	 * Prints a line only in human mode (progress and notes stay out of machine output).
	 *
	 * @param string $mode Mode.
	 * @param string $line Text.
	 */
	public static function note( string $mode, string $line ): void {
		if ( self::HUMAN === $mode ) {
			WP_CLI::log( $line );
		}
	}
}
