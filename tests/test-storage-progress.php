<?php
/**
 * The live picture of a storage's connection test: every check that starts, ends or fails reaches
 * the listener as the whole list so far, and a failure names the check.
 *
 * Run: php tests/test-storage-progress.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Storage\Storage_Exception;
use SiteFerry\Storage\Test_Progress;

echo "Storage test progress\n";

Sf_Test::run(
	'checks are reported as they start and end; the total feeds the bar',
	static function () {
		$seen = array();
		Test_Progress::listen(
			static function ( array $progress ) use ( &$seen ) {
				$seen[] = $progress;
			}
		);
		Test_Progress::total( 3 );
		$value = Test_Progress::check(
			'Connecting',
			static function () {
				return 42;
			}
		);
		Sf_Test::same( 42, $value, 'the check\'s own result:' );
		Test_Progress::listen( null );
		Sf_Test::same( array( 3, 3, 3 ), array_column( $seen, 'total' ), 'totals:' );
		Sf_Test::same(
			array(
				array(),
				array(
					array(
						'label' => 'Connecting',
						'state' => 'running',
					),
				),
				array(
					array(
						'label' => 'Connecting',
						'state' => 'done',
					),
				),
			),
			array_column( $seen, 'steps' )
		);
	}
);

Sf_Test::run(
	'a failing check is marked failed, and its name leads the error',
	static function () {
		$last = null;
		Test_Progress::listen(
			static function ( array $progress ) use ( &$last ) {
				$last = $progress;
			}
		);
		try {
			Test_Progress::check(
				'Writing a test file',
				static function () {
					throw new Storage_Exception( 'Disk full.', Storage_Exception::REMOTE );
				}
			);
			Sf_Test::ok( false, 'no exception' );
		} catch ( Storage_Exception $e ) {
			Sf_Test::same( 'Writing a test file failed. Disk full.', $e->getMessage() );
			Sf_Test::same( Storage_Exception::REMOTE, $e->getCode() );
		}
		Test_Progress::listen( null );
		Sf_Test::same( 'failed', $last['steps'][0]['state'] );
		Sf_Test::same( 1, $last['total'], 'without a total, the checks so far:' );
	}
);

Sf_Test::run(
	'without a listener nothing is kept or sent',
	static function () {
		Test_Progress::listen( null );
		Sf_Test::same(
			'ok',
			Test_Progress::check(
				'Listing',
				static function () {
					return 'ok';
				}
			)
		);
	}
);

exit( Sf_Test::finish( 'test-storage-progress' ) );
