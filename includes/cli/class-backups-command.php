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
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups list
	 *     wp siteferry backups list --format=json
	 *
	 * @subcommand list
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$dir = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', null );
		if ( null === $dir ) {
			$dir = Storage::existing_backups_dir();
		}

		$rows = array();
		foreach ( null === $dir ? array() : Backups::in( (string) $dir ) as $backup ) {
			$manifest = $backup['manifest'];
			$rows[]   = array(
				'name'      => $backup['name'],
				'size'      => size_format( $backup['size'], 1 ),
				'bytes'     => $backup['size'],
				'created'   => null !== $manifest ? $manifest['created'] : gmdate( 'Y-m-d\TH:i:s\Z', $backup['modified'] ),
				'source'    => null !== $manifest ? $manifest['site']['home'] : '',
				'wordpress' => null !== $manifest ? $manifest['versions']['wordpress'] : '',
				'status'    => null !== $backup['error'] ? $backup['error'] : 'ok',
				'path'      => $backup['file'],
			);
		}

		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$fields = 'table' === $format
			? array( 'name', 'size', 'created', 'source', 'wordpress', 'status' )
			: array( 'name', 'bytes', 'created', 'source', 'wordpress', 'status', 'path' );
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
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
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry backups check mysite-2026-11-05-1a2b3c4d5e6f.tar
	 *     wp siteferry backups check mysite-2026-11-05-1a2b3c4d5e6f.tar --storage=dropbox
	 *
	 * @param string[] $args       Positional arguments: the backup name.
	 * @param array    $assoc_args Options.
	 */
	public function check( $args, $assoc_args ): void {
		$place = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'storage', Check::LOCAL );
		try {
			list( $job ) = Check::start( (string) $args[0], $place, Command::password( $assoc_args ) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		Command::drive( $job, false );
		if ( Job::STATUS_FAILED === $job->status() ) {
			WP_CLI::error( sprintf( '%s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		$checked = $job->result()['checked'];
		WP_CLI::success( sprintf( 'The copy of %s on %s is intact. %s', $checked['name'], $checked['where'], $checked['summary'] ) );
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
		WP_CLI::log( sprintf( '%d plugins and %d themes the same; %d tables with the same row count.', $diff['plugins_same'], $diff['themes_same'], $diff['tables']['same'] ) );
	}
}
