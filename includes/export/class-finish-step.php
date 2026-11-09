<?php
/**
 * Export step: publish the backup and remove temporary files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Renames <backup>.part to its final name, records the result and deletes the job's temporary
 * files (the file list, the database dump and the checksum list). The job log is kept.
 */
final class Finish_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused; this step is quick).
	 * @throws Job_Exception If the archive cannot be renamed.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$part  = Pack_Step::part_path( $job );
		$final = $job->option( 'output_dir' ) . '/' . $job->option( 'archive' );

		if ( ! is_file( $final ) ) {
			$renamed = Io::quiet(
				static function () use ( $part, $final ) {
					return rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
			);
			if ( ! $renamed ) {
				throw new Job_Exception( __( 'Cannot rename the finished backup.', 'siteferry' ), Job_Exception::STEP );
			}
		}

		foreach ( array( 'files.list', 'database.sql', 'checksums.tmp' ) as $temporary ) {
			$job->delete_file( $temporary );
		}
		if ( $job->option( 'save_labels', false ) && ( '' !== (string) $job->option( 'title', '' ) || '' !== (string) $job->option( 'note', '' ) || array() !== (array) $job->option( 'tags', array() ) ) ) {
			\SiteFerry\Labels::set( (string) $job->option( 'archive' ), $job->option( 'title', '' ), $job->option( 'note', '' ), $job->option( 'tags', array() ) );
		}

		$pack = $job->state( 'pack' );
		$job->set_result(
			array(
				'file'     => $final,
				'name'     => $job->option( 'archive' ),
				'size'     => (int) filesize( $final ),
				'files'    => $pack['files'],
				'bytes'    => $pack['bytes'],
				'changed'  => $pack['changed'],
				'skipped'  => $pack['skipped'] + (int) $job->state( 'scan' )['skipped'],
				'warnings' => $job->warning_count(),
				'seconds'  => time() - $job->created(),
			)
		);
		$job->set_progress( __( 'Backup complete', 'siteferry' ), 1, 1 );
		$job->log( 'info', sprintf( 'Backup complete: %s (%s).', $job->option( 'archive' ), size_format( (int) filesize( $final ), 1 ) ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}
}
