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
			list( $job ) = Check::start( (string) $args[0], $place );
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
}
