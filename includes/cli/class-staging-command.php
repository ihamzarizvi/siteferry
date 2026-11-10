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
	 * ## EXAMPLES
	 *
	 *     wp siteferry staging create --folder=test
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function create( $args, $assoc_args ): void {
		unset( $args );
		$this->idle();
		try {
			list( $job ) = Staging::start( isset( $assoc_args['folder'] ) ? (string) $assoc_args['folder'] : 'staging', isset( $assoc_args['media'] ) && ! $assoc_args['media'] );
		} catch ( Job_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		Command::drive( $job, false );
		if ( Job::STATUS_DONE !== $job->status() ) {
			WP_CLI::error( 'The staging copy was not finished: ' . (string) $job->error() );
		}
		$result = $job->result();
		WP_CLI::success( 'Staging copy ready: ' . $result['staging']['url'] );
		if ( '' !== (string) $result['nginx'] ) {
			WP_CLI::log( "This server runs nginx. For pretty permalinks in the copy, add to the site's nginx configuration:\n" . $result['nginx'] );
		}
	}

	/**
	 * Shows the staging copy, or why one cannot be made.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry staging status
	 *
	 * @param string[] $args Positional arguments (none).
	 */
	public function status( $args ): void {
		unset( $args );
		$overview = Staging::overview();
		$record   = $overview['record'];
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
	 * ## EXAMPLES
	 *
	 *     wp siteferry staging delete --yes
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ): void {
		unset( $args );
		$record = Staging::record();
		if ( null === $record ) {
			WP_CLI::error( 'There is no staging copy.' );
			return;
		}
		WP_CLI::confirm( sprintf( 'Delete the staging copy at %s (folder %s, tables %s*)?', $record['url'], $record['path'], $record['prefix'] ), $assoc_args );
		$this->idle();
		try {
			list( $job ) = Staging::start_delete();
		} catch ( Job_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		Command::drive( $job, false );
		if ( Job::STATUS_DONE !== $job->status() ) {
			WP_CLI::error( 'The staging copy was not fully deleted: ' . (string) $job->error() . ' Run the command again to continue.' );
		}
		WP_CLI::success( 'Staging copy deleted.' );
	}

	/**
	 * Stops when another job runs.
	 */
	private function idle(): void {
		$active = Job::active( Storage::jobs_dir() );
		if ( null !== $active ) {
			WP_CLI::error( sprintf( 'Another %s is still in progress (job %s). Finish or cancel it first.', $active->type(), $active->id() ) );
		}
	}
}
