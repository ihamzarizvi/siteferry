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
	 * : Storage id (s3, ftp, gdrive, dropbox).
	 *
	 * [--porcelain]
	 * : Print only "ok" when the storage works.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, place, status, lines). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry storage test s3
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function test( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		try {
			$lines = Destinations::driver( (string) $args[0] )->test();
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		foreach ( $lines as $line ) {
			Output::note( $mode, (string) $line );
		}
		Output::done(
			$mode,
			array(
				'place'  => (string) $args[0],
				'status' => 'ok',
				'lines'  => array_values( array_map( 'strval', $lines ) ),
			),
			'ok',
			'The storage works.'
		);
	}

	/**
	 * Lists the backups stored there.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3, ftp, gdrive, dropbox).
	 *
	 * [--format=<format>]
	 * : Output format. In csv, json and yaml, size is in bytes (bytes is the same number).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$format = Output::list_format( $assoc_args );
		$place  = (string) $args[0];
		try {
			$items = \SiteFerry\Storage\Parts::group( Destinations::driver( $place )->list() );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		$rows = array_map(
			static function ( array $item ) use ( $format, $place ): array {
				return array(
					'name'     => $item['name'],
					'size'     => Output::size( (int) $item['size'], $format ),
					'bytes'    => (int) $item['size'],
					'modified' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $item['modified'] ),
					'parts'    => 'table' === $format ? $item['parts'] . ( $item['complete'] ? '' : ' (incomplete)' ) : (int) $item['parts'],
					'complete' => (bool) $item['complete'],
					'place'    => $place,
				);
			},
			$items
		);
		$fields = 'table' === $format ? array( 'name', 'size', 'modified', 'parts' ) : array( 'name', 'size', 'bytes', 'modified', 'parts', 'complete', 'place' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Sends a stored backup there.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3, ftp, gdrive, dropbox).
	 *
	 * <backup>
	 * : Backup file name in SiteFerry's backups folder (see "wp siteferry backups list"), or a path.
	 *
	 * [--porcelain]
	 * : Print only the name it has on the storage.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, size, place, where, warnings). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry storage send s3 mysite-2026-11-04-0123456789ab.tar
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function send( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		$file = (string) $args[1];
		if ( ! is_file( $file ) ) {
			try {
				$file = Backups::path( Storage::backups_dir(), $file );
			} catch ( \Throwable $e ) {
				Output::fail( $mode, sprintf( 'No backup named "%s".', $args[1] ) );
			}
		}
		$this->run(
			$mode,
			static function () use ( $args, $file ) {
				return Transfer::send( (string) $args[0], (string) realpath( $file ) );
			},
			static function ( Job $job ) use ( $args ): array {
				$sent = $job->result()['sent'];
				return array(
					array(
						'name'  => (string) $sent['name'],
						'size'  => (int) $sent['size'],
						'place' => (string) $args[0],
						'where' => (string) $sent['where'],
					),
					(string) $sent['name'],
					sprintf( 'Sent %s (%s) to %s.', $sent['name'], size_format( $sent['size'], 1 ), $sent['where'] ),
				);
			}
		);
	}

	/**
	 * Downloads a backup from there into SiteFerry's backups folder.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3, ftp, gdrive, dropbox).
	 *
	 * <name>
	 * : Backup file name on the storage (see "wp siteferry storage list").
	 *
	 * [--porcelain]
	 * : Print only the path of the downloaded backup.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, path, size, place, warnings). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function fetch( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		$this->run(
			$mode,
			static function () use ( $args ) {
				return Transfer::fetch( (string) $args[0], (string) $args[1] );
			},
			static function ( Job $job ) use ( $args ): array {
				$fetched = $job->result()['fetched'];
				$path    = Storage::backups_dir() . '/' . $fetched['name'];
				return array(
					array(
						'name'  => (string) $fetched['name'],
						'path'  => $path,
						'size'  => (int) $fetched['size'],
						'place' => (string) $args[0],
					),
					$path,
					sprintf( 'Downloaded %s (%s) to %s.', $fetched['name'], size_format( $fetched['size'], 1 ), Storage::backups_dir() ),
				);
			}
		);
	}

	/**
	 * Deletes a backup from there.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Storage id (s3, ftp, gdrive, dropbox).
	 *
	 * <name>
	 * : Backup file name on the storage.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--porcelain]
	 * : Print only the name deleted. Needs --yes.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, place). Needs --yes. Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		if ( Output::HUMAN !== $mode && empty( $assoc_args['yes'] ) ) {
			Output::fail( $mode, '--json and --porcelain need --yes (they cannot ask for confirmation).' );
		}
		$name = (string) $args[1];
		if ( ! preg_match( Backups::SAFE_NAME, $name ) ) {
			Output::fail( $mode, 'That is not a backup file name.' );
		}
		WP_CLI::confirm( sprintf( 'Delete %s from the storage? This cannot be undone.', $name ), $assoc_args );
		try {
			$driver = Destinations::driver( (string) $args[0] );
			\SiteFerry\Incremental::guard_remote_delete( $driver, $name );
			\SiteFerry\Storage\Parts::delete( $driver, $name );
			\SiteFerry\Check\Check::forget( $name, (string) $args[0] );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
		}
		Output::done(
			$mode,
			array(
				'name'  => $name,
				'place' => (string) $args[0],
			),
			$name,
			sprintf( 'Deleted %s.', $name )
		);
	}

	/**
	 * Starts a transfer job, runs it and reports.
	 *
	 * @param string   $mode    Output mode.
	 * @param callable $start   Returns [job, token].
	 * @param callable $outcome For the finished job: [fields, porcelain value, message].
	 */
	private function run( string $mode, callable $start, callable $outcome ): void {
		try {
			list( $job ) = $start();
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Command::drive( $job, Output::HUMAN !== $mode );
		if ( Job::STATUS_FAILED === $job->status() ) {
			Output::fail( $mode, sprintf( '%s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		list( $fields, $porcelain, $message ) = $outcome( $job );
		Output::done( $mode, $fields, $porcelain, $message, $job->warnings() );
	}
}
