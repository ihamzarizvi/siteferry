<?php
/**
 * Import step: remove the rollback copies after a successful import.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Drops the old tables, deletes the replaced files and the staging folder, and records the result.
 */
final class Finish_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		Database_Step::drop_prefixed( $wpdb, (string) $job->option( 'old_prefix' ) );
		Io::remove_tree( $job->path( 'rollback' ) );
		Io::remove_tree( Extract_Step::staging( $job ) );
		$job->delete_file( 'extracted.sha256' );
		$job->delete_file( Swap_Step::JOURNAL );

		$inspect = $job->state( 'inspect' );
		$replace = $job->state( 'replace' );
		$loaded  = (array) $job->state( 'database' )['tables'];
		$partial = Import::partial( $job );
		$job->set_result(
			array_merge(
				$job->result(),
				array(
					'imported' => array(
						'from'         => $inspect['source']['home'],
						'to'           => $inspect['target']['home'],
						'tables'       => count( $loaded ),
						'rows'         => $inspect['rows'],
						'files'        => $inspect['files'],
						'partial'      => $partial ? (string) $job->result()['inspection']['restores'] : '',
						'rows_changed' => isset( $replace['rows'] ) ? $replace['rows'] : 0,
						'warnings'     => $job->warning_count(),
						'seconds'      => time() - $job->created(),
					),
				)
			)
		);
		// "Undo this restore" restores the safety backup made before it; a restore without one has
		// nothing to undo with (an earlier safety backup stays an ordinary backup).
		$result = $job->result();
		$safety = isset( $result['safety'] ) ? $result['safety'] : null;
		if ( '' !== (string) $job->option( 'new_site', '' ) ) {
			// A new site of the network replaced nothing: an earlier restore's undo point stays.
			$safety = false;
		} elseif ( is_array( $safety ) ) {
			update_option(
				Import::UNDO_OPTION,
				array(
					'safety' => (string) $safety['name'],
					'backup' => basename( (string) $job->option( 'archive' ) ),
					'time'   => time(),
					'from'   => $inspect['source']['home'],
					'to'     => $inspect['target']['home'],
				),
				false
			);
		} else {
			delete_option( Import::UNDO_OPTION );
		}

		$job->set_progress( __( 'Import complete', 'siteferry' ), 1, 1 );
		$job->log( 'info', 'Import complete; rollback copies removed.' );
		\SiteFerry\Hooks::action( 'siteferry_import_done', $job, $job->result()['imported'] );
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
