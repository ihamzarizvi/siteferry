<?php
/**
 * Loaded with "wp --require=..." by tests\wp-cli-json-check.ps1: uses SiteFerry's hooks during an
 * export and writes what it saw to SITEFERRY_HOOKS_PROBE (a file path).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$sf_probe_file = (string) getenv( 'SITEFERRY_HOOKS_PROBE' );
if ( '' === $sf_probe_file ) {
	return;
}

$sf_probe = static function ( string $line ) use ( $sf_probe_file ) {
	file_put_contents( $sf_probe_file, $line . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
};

WP_CLI::add_hook(
	'after_wp_load',
	static function () use ( $sf_probe ) {
		add_action(
			'siteferry_export_started',
			static function ( $job ) use ( $sf_probe ) {
				$sf_probe( 'started ' . $job->type() );
			}
		);
		add_action(
			'siteferry_export_done',
			static function ( $job, $result ) use ( $sf_probe ) {
				$sf_probe( 'done ' . $result['name'] );
			},
			10,
			2
		);
		add_filter(
			'siteferry_manifest_extra',
			static function ( $extra ) {
				$extra['probe-test'] = array( 'answer' => 42 );
				$extra['Bad Key']    = 'dropped';
				return $extra;
			}
		);
		add_filter(
			'siteferry_backup_name_label',
			static function () {
				return 'Probe Label';
			}
		);
		add_filter(
			'siteferry_export_exclusions',
			static function ( $paths ) {
				$paths[] = 'languages';
				return $paths;
			}
		);
		// A callback that throws must not break the backup.
		add_action(
			'siteferry_export_started',
			static function () {
				throw new RuntimeException( 'probe callback failure' );
			},
			20
		);
	}
);
