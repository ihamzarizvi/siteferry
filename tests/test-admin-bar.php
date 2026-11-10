<?php
/**
 * Admin bar job item: what it shows for a running, waiting, finished or failed job, and the
 * percent from the steps and the current step's progress.
 *
 * Run: php tests/test-admin-bar.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Admin\Admin_Bar;

echo "Admin bar tests\n";

/**
 * A job summary as Job::summary() gives it.
 *
 * @param string $status  Status.
 * @param int    $index   Current step.
 * @param int    $count   Steps.
 * @param int    $done    Units done in the step.
 * @param int    $total   Units in the step.
 * @param int    $updated Last saved.
 * @param string $type    Job type.
 */
function sf_ab_job( string $status, int $index = 0, int $count = 4, int $done = 0, int $total = 0, int $updated = 1000, string $type = 'export' ): array {
	return array(
		'id'       => 'abcdef0123456789',
		'type'     => $type,
		'status'   => $status,
		'step'     => array(
			'index' => $index,
			'count' => $count,
		),
		'progress' => array(
			'message' => 'Working',
			'done'    => $done,
			'total'   => $total,
		),
		'updated'  => $updated,
	);
}

Sf_Test::run(
	'percent: finished steps plus the part of the current one, never 100 while running',
	static function () {
		Sf_Test::same( 0, Admin_Bar::percent( sf_ab_job( 'running' ) ) );
		Sf_Test::same( 25, Admin_Bar::percent( sf_ab_job( 'running', 1 ) ), 'one of four steps done:' );
		Sf_Test::same( 37, Admin_Bar::percent( sf_ab_job( 'running', 1, 4, 50, 100 ) ), 'and half of the second:' );
		Sf_Test::same( 99, Admin_Bar::percent( sf_ab_job( 'running', 4, 4 ) ), 'all steps done but not ended:' );
		Sf_Test::same( 0, Admin_Bar::percent( sf_ab_job( 'running', 0, 0 ) ), 'no steps: no division by zero:' );
		Sf_Test::same( 50, Admin_Bar::percent( sf_ab_job( 'running', 1, 4, 500, 100 ) ), 'more done than total is capped at the step:' );
	}
);

Sf_Test::run(
	'what the bar shows: running, waiting, done for 30 s, failed for 10 min, then nothing',
	static function () {
		$running = Admin_Bar::summarize( sf_ab_job( 'running', 2, 4 ), 1000 );
		Sf_Test::same( array( 'running', 50, 'SiteFerry 50%' ), array( $running['state'], $running['percent'], $running['label'] ) );
		Sf_Test::same( 'pending', sf_ab_job( 'pending' )['status'] );
		Sf_Test::same( 'running', Admin_Bar::summarize( sf_ab_job( 'pending' ), 1000 )['state'], 'pending counts as running:' );
		Sf_Test::same( 'waiting', Admin_Bar::summarize( sf_ab_job( 'waiting', 1, 9, 0, 0, 1000, 'import' ), 1000 )['state'] );

		$done = Admin_Bar::summarize( sf_ab_job( 'done', 4, 4, 0, 0, 1000 ), 1020 );
		Sf_Test::same( array( 'done', 100, 'Backup done ✓' ), array( $done['state'], $done['percent'], $done['label'] ) );
		Sf_Test::same( null, Admin_Bar::summarize( sf_ab_job( 'done', 4, 4, 0, 0, 1000 ), 1031 ), 'gone after 30 seconds:' );

		$failed = Admin_Bar::summarize( sf_ab_job( 'failed', 1, 4, 0, 0, 1000, 'import' ), 1500 );
		Sf_Test::same( array( 'failed', 'Restore failed' ), array( $failed['state'], $failed['label'] ) );
		Sf_Test::same( null, Admin_Bar::summarize( sf_ab_job( 'failed', 1, 4, 0, 0, 1000 ), 1601 ), 'gone after 10 minutes:' );
		Sf_Test::same( null, Admin_Bar::summarize( null, 1000 ) );
		Sf_Test::same( null, Admin_Bar::summarize( array( 'status' => 'running' ), 1000 ), 'no type: nothing:' );
	}
);

Sf_Test::run(
	'job types in words',
	static function () {
		$words = array();
		foreach ( array( 'export', 'import', 'send', 'fetch', 'check', 'staging', 'staging-delete', 'other' ) as $type ) {
			$words[] = Admin_Bar::what( $type );
		}
		Sf_Test::same( array( 'Backup', 'Restore', 'Upload', 'Download', 'Check', 'Staging copy', 'Deleting the staging copy', 'SiteFerry job' ), $words );
	}
);

exit( Sf_Test::finish( 'test-admin-bar' ) );
