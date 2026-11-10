<?php
/**
 * WP-CLI commands: wp siteferry backups ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Backups;
use SiteFerry\Check\Check;
use SiteFerry\Jobs\Job;
use SiteFerry\Security\Storage;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manages SiteFerry backups.
 */
final class Backups_Command {

	/**
	 * Lists backups, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<folder>]
	 * : List this folder instead of SiteFerry's storage folder.
	 *
	 * [--format=<format>]
	 * : Output format. In csv, json and yaml, size is in bytes (bytes is the same number, kept for
	 * older scripts); kind is full or changes-only, base the full backup a changes-only one needs.
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
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups list
	 *     wp siteferry backups list --format=json
	 *     wp siteferry backups list --format=count
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$format = Output::list_format( $assoc_args );
		$dir    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', null );
		if ( null === $dir ) {
			$dir = Storage::existing_backups_dir();
		}

		$rows = array();
		foreach ( null === $dir ? array() : Backups::in( (string) $dir ) as $backup ) {
			$manifest = $backup['manifest'];
			$base     = \SiteFerry\Incremental::base_of( $manifest );
			$rows[]   = array(
				'name'      => $backup['name'],
				'size'      => Output::size( (int) $backup['size'], $format ),
				'bytes'     => (int) $backup['size'],
				'created'   => null !== $manifest ? $manifest['created'] : gmdate( 'Y-m-d\TH:i:s\Z', $backup['modified'] ),
				'source'    => null !== $manifest ? $manifest['site']['home'] : '',
				'wordpress' => null !== $manifest ? $manifest['versions']['wordpress'] : '',
				'kind'      => null === $base ? 'full' : 'changes-only',
				'base'      => null === $base ? '' : $base['name'],
				'encrypted' => Backups::encrypted_name( (string) $backup['name'] ),
				'place'     => Check::LOCAL,
				'status'    => null !== $backup['error'] ? $backup['error'] : 'ok',
				'path'      => $backup['file'],
			);
		}

		$fields = 'table' === $format
			? array( 'name', 'size', 'created', 'kind', 'source', 'wordpress', 'status' )
			: array( 'name', 'size', 'bytes', 'created', 'kind', 'base', 'encrypted', 'source', 'wordpress', 'place', 'status', 'path' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Takes one site out of a whole-network backup on this server, as a new single-site backup
	 * that imports into any single site. The network backup and the live network stay as they are.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The network backup's file name (see "wp siteferry backups list").
	 *
	 * --site=<id>
	 * : The site's id in that network.
	 *
	 * [--porcelain]
	 * : Print only the new backup's path.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, path, size, site, url, tables, users, files,
	 * warnings). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups extract example-com-2026-11-09-1a2b3c4d5e6f.tar --site=2
	 *
	 * @param string[] $args       Positional arguments: the network backup.
	 * @param array    $assoc_args Options.
	 */
	public function extract( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		try {
			$made = \SiteFerry\Network\Extract::run( Backups::path( Storage::backups_dir(), (string) $args[0] ), (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'site', 0 ) );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Output::done(
			$mode,
			$made,
			(string) $made['path'],
			sprintf( 'Site %d (%s) taken out as %s (%s; %d tables, %s files, %d users).', $made['site'], $made['url'], $made['name'], size_format( $made['size'], 1 ), $made['tables'], number_format( $made['files'] ), $made['users'] )
		);
	}

	/**
	 * Deletes backups on this server. A full backup that changes-only backups are built on is
	 * kept until they are deleted (delete them first, or in the same command after it is listed
	 * last).
	 *
	 * ## OPTIONS
	 *
	 * <name>...
	 * : One or more backup file names (see "wp siteferry backups list").
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--porcelain]
	 * : Print only the names deleted, one per line.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, deleted, warnings). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups delete mysite-2026-11-01-1a2b3c4d5e6f.tar --yes
	 *
	 * @param string[] $args       Positional arguments: backup names.
	 * @param array    $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		if ( Output::HUMAN !== $mode && empty( $assoc_args['yes'] ) ) {
			Output::fail( $mode, '--json and --porcelain need --yes (they cannot ask for confirmation).' );
		}
		$dir   = Storage::existing_backups_dir();
		$paths = array();
		foreach ( array_values( array_unique( array_map( 'strval', $args ) ) ) as $name ) {
			try {
				if ( null === $dir ) {
					throw new \RuntimeException( 'There are no backups on this server.' );
				}
				$path = Backups::path( $dir, $name );
			} catch ( \Throwable $e ) {
				Output::fail( $mode, sprintf( 'No backup named "%s" on this server.', $name ) );
				return;
			}
			if ( ! is_file( $path ) ) {
				Output::fail( $mode, sprintf( 'No backup named "%s" on this server.', $name ) );
			}
			$paths[ $name ] = $path;
		}
		WP_CLI::confirm( sprintf( 'Delete %s from this server? This cannot be undone.', implode( ', ', array_keys( $paths ) ) ), $assoc_args );
		$deleted = array();
		foreach ( $paths as $name => $path ) {
			try {
				\SiteFerry\Incremental::guard_delete( $name ); // Not while changes-only backups need it.
				if ( ! \SiteFerry\Archive\Io::quiet(
					static function () use ( $path ) {
						return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				) ) {
					throw new \RuntimeException( sprintf( 'The backup %s could not be deleted.', $name ) );
				}
			} catch ( \Throwable $e ) {
				Output::fail( $mode, $e->getMessage() . ( array() === $deleted ? '' : ' Deleted before this: ' . implode( ', ', $deleted ) . '.' ) );
				return;
			}
			\SiteFerry\Contents::forget( $name );
			Check::forget( $name, Check::LOCAL );
			\SiteFerry\Move\Receiver::forget_backup( $name );
			$deleted[] = $name;
		}
		Output::done( $mode, array( 'deleted' => $deleted ), implode( "\n", $deleted ), sprintf( 'Deleted %s.', implode( ', ', $deleted ) ) );
	}

	/**
	 * Checks a copy of a backup: reads it from start to end and compares every file with the
	 * backup's own checksums, without unpacking anything. A copy on a storage is downloaded into
	 * a temporary folder first and deleted afterwards.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Backup file name.
	 *
	 * [--storage=<id>]
	 * : Check the copy on this storage (s3, ftp, gdrive, dropbox) instead of the one on this server.
	 *
	 * [--password[=<password>]]
	 * : Password of a protected backup (.enc). Without a value it is taken from the
	 * SITEFERRY_PASSWORD environment variable, or asked for.
	 *
	 * [--porcelain]
	 * : Print only "ok" when the copy is intact (a damaged copy is an error, exit code 1).
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, place, where, status, summary, warnings). Same as
	 * --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups check mysite-2026-11-05-1a2b3c4d5e6f.tar
	 *     wp siteferry backups check mysite-2026-11-05-1a2b3c4d5e6f.tar --storage=dropbox
	 *     wp siteferry backups check mysite-2026-11-05-1a2b3c4d5e6f.tar --json
	 *
	 * @param string[] $args       Positional arguments: the backup name.
	 * @param array    $assoc_args Options.
	 */
	public function check( $args, $assoc_args ): void {
		$mode  = Output::mode( $assoc_args );
		$place = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'storage', Check::LOCAL );
		try {
			list( $job ) = Check::start( (string) $args[0], $place, Command::password( $assoc_args ) );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Command::drive( $job, Output::HUMAN !== $mode );
		if ( Job::STATUS_FAILED === $job->status() ) {
			Output::fail( $mode, sprintf( '%s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		$checked = $job->result()['checked'];
		Output::done(
			$mode,
			array(
				'name'    => (string) $checked['name'],
				'place'   => (string) $checked['place'],
				'where'   => (string) $checked['where'],
				'status'  => 'ok',
				'summary' => (string) $checked['summary'],
			),
			'ok',
			sprintf( 'The copy of %s on %s is intact. %s', $checked['name'], $checked['where'], $checked['summary'] ),
			$job->warnings()
		);
	}

	/**
	 * Shows what differs between two backups on this server, from the older to the newer: versions,
	 * site address, plugins, themes, content counts, database tables and files. Only the backups'
	 * manifests are read.
	 *
	 * ## OPTIONS
	 *
	 * <a>
	 * : One backup's file name.
	 *
	 * <b>
	 * : The other backup's file name.
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * [--password=<password>]
	 * : Password of password-protected backups (both must use it); better: the SITEFERRY_PASSWORD
	 * environment variable, or asked for.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups compare mysite-2026-11-01-1a2b3c4d5e6f.tar mysite-2026-11-08-0f9e8d7c6b5a.tar
	 *
	 * @param string[] $args       Positional arguments: the two backup names.
	 * @param array    $assoc_args Options.
	 */
	public function compare( $args, $assoc_args ): void {
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$dir    = \SiteFerry\Security\Storage::backups_dir();
		$keys   = array();
		$asked  = null;
		try {
			foreach ( array( (string) $args[0], (string) $args[1] ) as $name ) {
				$path = \SiteFerry\Backups::path( $dir, $name );
				if ( \SiteFerry\Archive\Crypto::is_encrypted( $path ) ) {
					$asked  = null === $asked ? Command::password( $assoc_args ) : $asked;
					$keys[] = \SiteFerry\Archive\Crypto::unlock( $path, (string) $asked );
				} else {
					$keys[] = null;
				}
			}
			$diff = \SiteFerry\Backup_Compare::files( (string) $args[0], (string) $args[1], $keys[0], $keys[1] );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( (string) wp_json_encode( $diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		$rows = array();
		$add  = static function ( string $area, string $item, $older, $newer, string $change = '' ) use ( &$rows ) {
			$rows[] = array(
				'area'   => $area,
				'item'   => $item,
				'older'  => null === $older ? '-' : (string) $older,
				'newer'  => null === $newer ? '-' : (string) $newer,
				'change' => $change,
			);
		};
		$add( 'backup', 'name', $diff['older']['name'], $diff['newer']['name'] );
		$add( 'backup', 'made', $diff['older']['created'], $diff['newer']['created'] );
		$add( 'backup', 'kind', $diff['older']['kind'], $diff['newer']['kind'] );
		foreach ( $diff['versions'] as $version ) {
			$add( 'version', $version['what'], $version['older'], $version['newer'] );
		}
		if ( $diff['address'] ) {
			$add( 'site', 'address', $diff['address']['older'], $diff['address']['newer'] );
		}
		foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $list => $area ) {
			foreach ( $diff[ $list ] as $item ) {
				$add( $area, $item['name'], $item['older'], $item['newer'], trim( $item['change'] . ' ' . $item['activity'] ) );
			}
		}
		if ( $diff['theme'] ) {
			$add( 'theme', 'in use', $diff['theme']['older'], $diff['theme']['newer'] );
		}
		foreach ( (array) $diff['content'] as $what => $counts ) {
			if ( $counts['older'] !== $counts['newer'] ) {
				$add( 'content', $what, $counts['older'], $counts['newer'] );
			}
		}
		foreach ( $diff['tables']['added'] as $table ) {
			$add( 'table', $table, null, 'yes', 'added' );
		}
		foreach ( $diff['tables']['removed'] as $table ) {
			$add( 'table', $table, 'yes', null, 'removed' );
		}
		foreach ( $diff['tables']['changed'] as $table ) {
			$add( 'table', $table['name'] . ' rows', $table['older'], $table['newer'] );
		}
		$add( 'database', 'size', size_format( $diff['tables']['size']['older'], 1 ), size_format( $diff['tables']['size']['newer'], 1 ) );
		$add( 'files', 'count', $diff['files']['older']['files'], $diff['files']['newer']['files'] );
		$add( 'files', 'size', size_format( $diff['files']['older']['bytes'], 1 ), size_format( $diff['files']['newer']['bytes'], 1 ) );
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'area', 'item', 'older', 'newer', 'change' ) );
		WP_CLI::log(
			sprintf(
				'Unchanged: %s, %s, %s with the same row count.',
				1 === (int) $diff['plugins_same'] ? '1 plugin' : (int) $diff['plugins_same'] . ' plugins',
				1 === (int) $diff['themes_same'] ? '1 theme' : (int) $diff['themes_same'] . ' themes',
				1 === (int) $diff['tables']['same'] ? '1 table' : (int) $diff['tables']['same'] . ' tables'
			)
		);
	}

	/**
	 * Runs the weekly check of stored copies now, in this process: up to two copies from each
	 * storage that is set up (the ones not checked yet first), downloaded and checked, and the
	 * scheduled backups missing on a storage. Damaged or missing copies are emailed as the
	 * schedule's email setting says.
	 *
	 * [--porcelain]
	 * : Print only the number of damaged or missing copies.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, checked, intact, bad, errors, skipped, missing). Same as
	 * --json. Damaged copies do not make the command fail; "bad" lists them.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups recheck
	 *     wp siteferry backups recheck --json
	 *
	 * @param array $args       Positional arguments (none).
	 * @param array $assoc_args Options.
	 */
	public function recheck( $args, $assoc_args ): void {
		unset( $args );
		$mode = Output::mode( $assoc_args );
		if ( array() === \SiteFerry\Check\Recheck::storages() ) {
			Output::fail( $mode, 'No storage is set up, so there is nothing to check.' );
		}
		$state = \SiteFerry\Check\Recheck::state();
		if ( array() !== $state['queue'] || ! empty( $state['current'] ) ) {
			Output::fail( $mode, 'A check of stored copies is already running in the background.' );
		}
		$round = \SiteFerry\Check\Recheck::start_round();
		Output::note( $mode, sprintf( 'Checking %d stored copies; %d skipped for their size; %d missing.', count( $round['queue'] ), count( $round['skipped'] ), count( $round['missing'] ) ) );
		do {
			\SiteFerry\Check\Recheck::on_slice();
			$state = \SiteFerry\Check\Recheck::state();
		} while ( array() !== $state['queue'] || ! empty( $state['current'] ) );
		wp_clear_scheduled_hook( \SiteFerry\Check\Recheck::HOOK_SLICE );
		$last = $state['last'];
		$bad  = array_values( array_map( 'strval', (array) $last['bad'] ) );
		if ( Output::HUMAN !== $mode ) {
			Output::done(
				$mode,
				array(
					'checked' => (int) $last['checked'],
					'intact'  => (int) $last['ok'],
					'bad'     => $bad,
					'errors'  => array_values( array_map( 'strval', (array) $last['errors'] ) ),
					'skipped' => array_values( (array) $round['skipped'] ),
					'missing' => array_values( (array) $round['missing'] ),
				),
				(string) count( $bad ),
				''
			);
			return;
		}
		WP_CLI::log( sprintf( '%d checked, %d intact.', $last['checked'], $last['ok'] ) );
		foreach ( array_merge( $bad, (array) $last['errors'] ) as $line ) {
			WP_CLI::warning( $line );
		}
		if ( array() === $bad ) {
			WP_CLI::success( 'No damaged or missing copies found.' );
		}
	}
}