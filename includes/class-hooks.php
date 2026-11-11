<?php
/**
 * SiteFerry's own actions and filters, in one place.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Fires SiteFerry's hooks. Jobs also run where WordPress's plugin API is not loaded (the
 * standalone tests, "wp siteferry export --dir" on a site without SiteFerry); there the hooks are
 * simply skipped. A callback that throws or fails never breaks a backup or restore: actions are
 * run inside try/catch, and a filter's result is checked by the caller.
 *
 * Every hook is listed in HOOKS with its arguments (docs/hooks.md describes them in full).
 */
final class Hooks {

	/** Hook name => what it passes, for the documentation and its test. */
	const HOOKS = array(
		// Actions.
		'siteferry_export_started'   => 'action( Job $job ): a backup job was created (Back up now, WP-CLI, a schedule, a quick backup before an update, a safety backup).',
		'siteferry_export_done'      => 'action( Job $job, array $result ): the backup file is complete (result: file, name, size, files, bytes, base).',
		'siteferry_import_started'   => 'action( Job $job ): a restore or move-here job was created (it checks the backup and waits for confirmation first).',
		'siteferry_import_done'      => 'action( Job $job, array $imported ): a restore finished (imported: from, to, tables, files, partial, rows_changed).',
		'siteferry_backup_sent'      => 'action( Job $job, array $sent ): a backup reached one storage (sent: name, where, destination when known).',
		'siteferry_job_failed'       => 'action( Job $job, string $error ): any SiteFerry job failed or was cancelled, after its cleanup.',
		// Filters.
		'siteferry_export_exclusions' => 'filter( string[] $paths, array $options ): paths inside wp-content a backup leaves out ("*" matches within one folder name).',
		'siteferry_manifest_extra'    => 'filter( array $extra, Job $job ): extra data stored in the backup\'s manifest under "extra", one entry per plugin key.',
		'siteferry_backup_name_label' => 'filter( string $label, string $home ): the site part of a backup file name (letters, digits, hyphens; at most 40).',
		'siteferry_checklist_items'   => 'filter( array $items, ?array $move ): extra after-move checklist items (id, title, state ok|info|warn|fail, detail); shown only, never fixed by SiteFerry.',
		'siteferry_move_to_self'      => 'filter( bool $allow ): allow moving a site to itself (tests).',
		'siteferry_move_export_options' => 'filter( array $options ): the export options of a site-to-site move.',
		'siteferry_update_backup_seconds' => 'filter( float $seconds, array $item ): time limit of the quick backup before an update or risky change.',
		'siteferry_relay_url'         => 'filter( string $url ): the one-click sign-in service (default https://auth.xsofty.com/siteferry).',
		'siteferry_gdrive_endpoints'  => 'filter( array $endpoints ): Google endpoints (tests).',
		'siteferry_dropbox_endpoints' => 'filter( array $endpoints ): Dropbox endpoints (tests).',
	);

	/**
	 * Runs an action; a callback's failure is logged to the job, never thrown.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments; the first is the job when there is one.
	 */
	public static function action( string $hook, ...$args ): void {
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}
		try {
			do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- every name passed here starts with siteferry_ (see HOOKS).
		} catch ( \Throwable $e ) {
			$job = isset( $args[0] ) && $args[0] instanceof Jobs\Job ? $args[0] : null;
			if ( null !== $job ) {
				$job->log( 'warning', sprintf( 'A callback on %s failed: %s', $hook, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Applies a filter, or returns the value unchanged without WordPress.
	 *
	 * @param string $hook  Hook name.
	 * @param mixed  $value Value.
	 * @param mixed  ...$args More arguments.
	 * @return mixed
	 */
	public static function filter( string $hook, $value, ...$args ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $value;
		}
		return apply_filters( $hook, $value, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- every name passed here starts with siteferry_ (see HOOKS).
	}

	/**
	 * The extra manifest data a filter returned, kept only when it is safe to store: keys of
	 * letters, digits, "_" and "-" (a plugin's own key), JSON-encodable values, at most 64 KB.
	 *
	 * @param mixed $extra Filter result.
	 * @return array<string,mixed>
	 */
	public static function clean_extra( $extra ): array {
		if ( ! is_array( $extra ) ) {
			return array();
		}
		$clean = array();
		foreach ( $extra as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/i', $key ) ) {
				continue;
			}
			$json = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- also runs without WordPress.
			if ( false === $json || strlen( $json ) > 65536 ) {
				continue;
			}
			$clean[ $key ] = json_decode( $json, true );
		}
		return $clean;
	}

	/**
	 * The label part of a backup name after the filter: lower-case letters, digits and hyphens,
	 * at most 40, never empty (else the unfiltered label).
	 *
	 * @param mixed  $label    Filter result.
	 * @param string $fallback The label before the filter.
	 */
	public static function clean_label( $label, string $fallback ): string {
		$label = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( is_scalar( $label ) ? (string) $label : '' ) ), '-' );
		$label = rtrim( substr( $label, 0, 40 ), '-' );
		return '' === $label ? $fallback : $label;
	}

	/**
	 * Exclusion paths after the filter: strings only, trimmed, no "..", no absolute paths.
	 *
	 * @param mixed    $paths    Filter result.
	 * @param string[] $fallback The paths before the filter.
	 * @return string[]
	 */
	public static function clean_paths( $paths, array $fallback ): array {
		if ( ! is_array( $paths ) ) {
			return $fallback;
		}
		$clean = array();
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) ) {
				continue;
			}
			$path = trim( str_replace( '\\', '/', $path ), '/ ' );
			if ( '' === $path || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '/^[a-z]:/i', $path ) ) {
				continue;
			}
			$clean[] = $path;
		}
		return array_values( array_unique( $clean ) );
	}
}
