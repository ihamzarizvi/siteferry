<?php
/**
 * WP-CLI commands: wp siteferry storage ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Backups;
use SiteFerry\Jobs\Job;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;
use SiteFerry\Storage\Transfer;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Tests remote storage and sends, lists, fetches and deletes backups there.
 *
 * Storage settings and keys are entered on the Storage tab of the SiteFerry admin page; these
 * commands use the saved settings. Storage id: s3.
 */
final class Storage_Command {

	/**
	 * Checks the saved settings with a small test file (write, read, list, delete).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3).
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry storage test s3
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function test( $args ): void {
		try {
			foreach ( Destinations::driver( (string) $args[0] )->test() as $line ) {
				WP_CLI::log( $line );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( 'The storage works.' );
	}

	/**
	 * Lists the backups stored there.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		try {
			$items = Destinations::driver( (string) $args[0] )->list();
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$rows = array_map(
			static function ( array $item ): array {
				return array(
					'name'     => $item['name'],
					'size'     => size_format( $item['size'], 1 ),
					'bytes'    => $item['size'],
					'modified' => gmdate( 'Y-m-d H:i:s', $item['modified'] ),
				);
			},
			$items
		);
		\WP_CLI\Utils\format_items( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'name', 'size', 'bytes', 'modified' ) );
	}

	/**
	 * Sends a stored backup there.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3).
	 *
	 * <backup>
	 * : Backup file name in SiteFerry's backups folder (see "wp siteferry backups list"), or a path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry storage send s3 mysite-2026-11-04-0123456789ab.tar
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function send( $args ): void {
		$file = (string) $args[1];
		if ( ! is_file( $file ) ) {
			try {
				$file = Backups::path( Storage::backups_dir(), $file );
			} catch ( \Throwable $e ) {
				WP_CLI::error( sprintf( 'No backup named "%s".', $args[1] ) );
			}
		}
		$this->run(
			static function () use ( $args, $file ) {
				return Transfer::send( (string) $args[0], (string) realpath( $file ) );
			},
			static function ( Job $job ) {
				$sent = $job->result()['sent'];
				return sprintf( 'Sent %s (%s) to %s.', $sent['name'], size_format( $sent['size'], 1 ), $sent['where'] );
			}
		);
	}

	/**
	 * Downloads a backup from there into SiteFerry's backups folder.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3).
	 *
	 * <name>
	 * : Backup file name on the storage (see "wp siteferry storage list").
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function fetch( $args ): void {
		$this->run(
			static function () use ( $args ) {
				return Transfer::fetch( (string) $args[0], (string) $args[1] );
			},
			static function ( Job $job ) {
				$fetched = $job->result()['fetched'];
				return sprintf( 'Downloaded %s (%s) to %s.', $fetched['name'], size_format( $fetched['size'], 1 ), Storage::backups_dir() );
			}
		);
	}

	/**
	 * Deletes a backup from there.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3).
	 *
	 * <name>
	 * : Backup file name on the storage.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ): void {
		$name = (string) $args[1];
		if ( ! preg_match( Backups::SAFE_NAME, $name ) ) {
			WP_CLI::error( 'That is not a backup file name.' );
		}
		WP_CLI::confirm( sprintf( 'Delete %s from the storage? This cannot be undone.', $name ), $assoc_args );
		try {
			Destinations::driver( (string) $args[0] )->delete( $name );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( 'Deleted %s.', $name ) );
	}

	/**
	 * Starts a transfer job, runs it and reports.
	 *
	 * @param callable $start   Returns [job, token].
	 * @param callable $success Message for the finished job.
	 */
	private function run( callable $start, callable $success ): void {
		try {
			list( $job ) = $start();
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		Command::drive( $job, false );
		if ( Job::STATUS_FAILED === $job->status() ) {
			WP_CLI::error( sprintf( '%s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		WP_CLI::success( $success( $job ) );
	}
}
