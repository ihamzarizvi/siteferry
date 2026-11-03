<?php
/**
 * WP-CLI commands: wp siteferry backups ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Backups;
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
}
