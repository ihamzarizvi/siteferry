<?php
/**
 * The checklist's search and replace in the live site's tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

use SiteFerry\Database\Replacer;
use SiteFerry\Database\Table_Replacer;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the old site's addresses (or http links to this site) in this site's own tables, one
 * batch at a time, with the same serialized-safe replacer an import uses. Each batch commits on
 * its own, so a stopped job leaves some rows changed and can simply be run again: rows already
 * changed no longer match.
 */
final class Checklist_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		$state = $job->state( 'checklist' );
		if ( array() === $state ) {
			$state = array(
				'index' => 0,
				'plan'  => null,
				'rows'  => 0,
				'stats' => array(
					'serialized' => 0,
					'broken'     => 0,
					'opaque'     => 0,
				),
			);
		}
		$tables   = (array) $job->option( 'tables', array() );
		$skip     = (array) $job->option( 'skip', array() );
		$replacer = new Replacer( (array) $job->option( 'pairs', array() ) );
		$runner   = new Table_Replacer( $wpdb, $replacer );

		while ( $state['index'] < count( $tables ) ) {
			$table = (string) $tables[ $state['index'] ];
			if ( null === $state['plan'] ) {
				$state['plan'] = $runner->plan( $table, isset( $skip[ $table ] ) ? (array) $skip[ $table ] : array() );
				if ( null === $state['plan'] ) {
					++$state['index'];
					continue;
				}
			}
			if ( $runner->run( $state['plan'] ) ) {
				$state['rows'] += (int) $state['plan']['rows'];
				$state['plan']  = null;
				++$state['index'];
			}
			if ( $budget->exhausted() ) {
				break;
			}
		}
		foreach ( $replacer->stats() as $key => $count ) {
			$state['stats'][ $key ] += $count;
		}

		$done = $state['index'] >= count( $tables );
		$job->set_state( 'checklist', $state );
		$job->set_progress(
			/* translators: 1: a number, 2: a number */
			sprintf( __( 'Updating addresses: table %1$d of %2$d', 'siteferry' ), min( $state['index'] + 1, max( 1, count( $tables ) ) ), count( $tables ) ),
			$state['index'],
			count( $tables )
		);
		if ( $done ) {
			wp_cache_flush();
			if ( $state['stats']['opaque'] > 0 ) {
				/* translators: %d: a number */
				$job->warn( sprintf( __( '%d serialized values use a custom format SiteFerry cannot rewrite; they keep the old addresses.', 'siteferry' ), $state['stats']['opaque'] ) );
			}
			$job->set_result(
				array(
					'checklist' => array(
						'item' => (string) $job->option( 'item' ),
						'rows' => $state['rows'],
					),
				)
			);
			$job->log( 'info', sprintf( 'Checklist %s: %d rows changed.', (string) $job->option( 'item' ), $state['rows'] ) );
		}
		return $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}
}
