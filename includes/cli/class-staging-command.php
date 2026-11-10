<?php
/**
 * WP-CLI commands: wp siteferry staging ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;
use SiteFerry\Staging\Staging;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Makes, shows and deletes the staging copy: this site in a subfolder, with its own tables, kept
 * out of search engines and holding back email. One copy at a time; single sites only.
 */
final class Staging_Command {

	/**
	 * Makes a staging copy.
	 *
	 * ## OPTIONS
	 *
	 * [--folder=<name>]
	 * : Subfolder for the copy (lower-case letters, digits, hyphens). Default: staging.
	 *
	 * [--[no-]media]
	 * : Copy the media library (default). --no-media leaves it out; the copy's images will be missing.
	 *
	 * [--porcelain]
	 * : Print only the copy's address.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, url, path, prefix, nginx, warnings). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry staging create --folder=test
	 *     wp siteferry staging create --no-media --json
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function create( $args, $assoc_args ): void {
		unset( $args );
		$mode = Output::mode( $assoc_args );
		$this->idle( $mode );
		try {
			list( $job ) = Staging::start( isset( $assoc_args['folder'] ) ? (string) $assoc_args['folder'] : 'staging', isset( $assoc_args['media'] ) && ! $assoc_args['media'] );
		} catch ( Job_Exception $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Command::drive( $job, Output::HUMAN !== $mode );
		if ( Job::STATUS_DONE !== $job->status() ) {
			Output::fail( $mode, 'The staging copy was not finished: ' . (string) $job->error() );
		}
		$result  = $job->result();
		$staging = (array) $result['staging'];
		Output::done(
			$mode,
			array(
				'url'    => (string) $staging['url'],
				'path'   => isset( $staging['path'] ) ? (string) $staging['path'] : '',
				'prefix' => isset( $staging['prefix'] ) ? (string) $staging['prefix'] : '',
				'nginx'  => (string) $result['nginx'],
			),
			(string) $staging['url'],
			'Staging copy ready: ' . $staging['url'],
			$job->warnings()
		);
		if ( Output::HUMAN === $mode && '' !== (string) $result['nginx'] ) {
			WP_CLI::log( "This server runs nginx. For pretty permalinks in the copy, add to the site's nginx configuration:\n" . $result['nginx'] );
		}
	}

	/**
	 * Shows the staging copy, or why one cannot be made.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table: in words (default). json or yaml: record (url, path, prefix, status, created, size; null
	 * when there is none) and blockers.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry staging status
	 *     wp siteferry staging status --format=json
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function status( $args, $assoc_args = array() ): void {
		unset( $args );
		$format   = Output::list_format( (array) $assoc_args, array( 'table', 'json', 'yaml' ) );
		$overview = Staging::overview();
		$record   = $overview['record'];
		if ( 'table' !== $format ) {
			$data = array(
				'record'   => null === $record ? null : array(
					'url'     => (string) $record['url'],
					'path'    => (string) $record['path'],
					'prefix'  => (string) $record['prefix'],
					'status'  => (string) $record['status'],
					'created' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $record['created'] ),
					'size'    => (int) $record['bytes'],
				),
				'blockers' => array_values( (array) $overview['blockers'] ),
			);
			if ( 'json' === $format ) {
				WP_CLI::line( Output::encode( $data ) );
			} else {
				\WP_CLI\Utils\format_items( 'yaml', array( $data ), array_keys( $data ) );
			}
			return;
		}
		if ( null === $record ) {
			WP_CLI::log( 'No staging copy.' );
			foreach ( $overview['blockers'] as $reason ) {
				WP_CLI::log( 'Cannot make one here: ' . $reason );
			}
			return;
		}
		WP_CLI::log( sprintf( 'Staging copy (%s): %s', $record['status'], $record['url'] ) );
		WP_CLI::log( sprintf( 'Folder: %s; tables: %s*; made %s; %s.', $record['path'], $record['prefix'], wp_date( 'Y-m-d H:i T', (int) $record['created'] ), size_format( (int) $record['bytes'], 1 ) ?: 'size not known yet' ) );
	}

	/**
	 * Deletes the staging copy: its tables and its folder.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--porcelain]
	 * : Print only "deleted". Needs --yes.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, url, path, prefix). Needs --yes. Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry staging delete --yes
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ): void {
		unset( $args );
		$mode = Output::mode( $assoc_args );
		if ( Output::HUMAN !== $mode && empty( $assoc_args['yes'] ) ) {
			Output::fail( $mode, '--json and --porcelain need --yes (they cannot ask for confirmation).' );
		}
		$record = Staging::record();
		if ( null === $record ) {
			Output::fail( $mode, 'There is no staging copy.' );
			return;
		}
		WP_CLI::confirm( sprintf( 'Delete the staging copy at %s (folder %s, tables %s*)?', $record['url'], $record['path'], $record['prefix'] ), $assoc_args );
		$this->idle( $mode );
		try {
			list( $job ) = Staging::start_delete();
		} catch ( Job_Exception $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Command::drive( $job, Output::HUMAN !== $mode );
		if ( Job::STATUS_DONE !== $job->status() ) {
			Output::fail( $mode, 'The staging copy was not fully deleted: ' . (string) $job->error() . ' Run the command again to continue.' );
		}
		Output::done(
			$mode,
			array(
				'url'    => (string) $record['url'],
				'path'   => (string) $record['path'],
				'prefix' => (string) $record['prefix'],
			),
			'deleted',
			'Staging copy deleted.'
		);
	}

	/**
	 * Stops when another job runs.
	 *
	 * @param string $mode Output mode.
	 */
	private function idle( string $mode = Output::HUMAN ): void {
		$active = Job::active( Storage::jobs_dir() );
		if ( null !== $active ) {
			Output::fail( $mode, sprintf( 'Another %s is still in progress (job %s). Finish or cancel it first.', $active->type(), $active->id() ) );
		}
	}
}
