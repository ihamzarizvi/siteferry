<?php
/**
 * Backups in parts, the parts that need no WordPress: how a backup is cut, which stored names
 * count, and how a storage listing shows sets of parts (also incomplete ones) as one backup.
 *
 * Run: php tests/test-parts.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Storage\Parts;
use SiteFerry\Storage\Storage_Exception;

echo "Parts tests\n";

/**
 * Stand-in for size_format().
 *
 * @param int $bytes Bytes.
 */
function size_format( $bytes ) {
	return $bytes . ' B';
}

/**
 * Asserts that a call is refused as a configuration error.
 *
 * @param callable $call    Call.
 * @param string   $message Label.
 */
function sf_refused( callable $call, string $message ): void {
	try {
		$call();
	} catch ( Storage_Exception $e ) {
		Sf_Test::same( Storage_Exception::CONFIG, $e->getCode(), $message . ':' );
		return;
	}
	Sf_Test::ok( false, $message . ' was not refused' );
}

Sf_Test::run(
	'cutting: whole when it fits, numbered parts that add up exactly, and a limit on their number',
	static function () {
		Sf_Test::same( array( array( 'a.tar', 0, 100 ) ), Parts::pieces( 'a.tar', 100, 0 ) );
		Sf_Test::same( array( array( 'a.tar', 0, 100 ) ), Parts::pieces( 'a.tar', 100, 100 ), 'exactly the limit:' );
		Sf_Test::same(
			array( array( 'a.tar.001', 0, 40 ), array( 'a.tar.002', 40, 40 ), array( 'a.tar.003', 80, 21 ) ),
			Parts::pieces( 'a.tar', 101, 40 )
		);
		Sf_Test::same( 999, count( Parts::pieces( 'a.tar', 999 * 10, 10 ) ) );
		sf_refused(
			static function () {
				Parts::pieces( 'a.tar', 1000 * 10, 10 );
			},
			'more than 999 parts'
		);
	}
);

Sf_Test::run(
	'which stored names count',
	static function () {
		foreach ( array( 'site-2026-11-05-0123456789ab.tar', 'x.tar.gz.enc', 'x.tar.001', 'x.tar.gz.enc.012', 'x.wpress.999' ) as $good ) {
			Sf_Test::same( true, Parts::stored_name( $good ), $good . ':' );
		}
		foreach ( array( 'x.tar.000', 'x.tar.1', 'x.tar.0001', 'x.zip.001', '../x.tar.001', 'x.php', str_repeat( 'a', 200 ) . '.tar' ) as $bad ) {
			Sf_Test::same( false, Parts::stored_name( $bad ), $bad . ':' );
		}
	}
);

Sf_Test::run(
	'a listing shows each set of parts as one backup; a missing part is noticed; a whole file wins',
	static function () {
		$list   = array(
			array( 'name' => 'b.tar.002', 'size' => 5, 'modified' => 20 ),
			array( 'name' => 'b.tar.001', 'size' => 10, 'modified' => 19 ),
			array( 'name' => 'a.tar', 'size' => 7, 'modified' => 5 ),
			array( 'name' => 'c.tar.001', 'size' => 10, 'modified' => 30 ),
			array( 'name' => 'c.tar.003', 'size' => 4, 'modified' => 31 ),
			array( 'name' => 'a.tar.001', 'size' => 3, 'modified' => 50 ),
		);
		$groups = Parts::group( $list );
		Sf_Test::same( array( 'c.tar', 'b.tar', 'a.tar' ), array_column( $groups, 'name' ), 'newest first:' );
		$b = $groups[1];
		Sf_Test::same( array( 15, 20, 2, true ), array( $b['size'], $b['modified'], $b['parts'], $b['complete'] ) );
		Sf_Test::same( array( array( 'b.tar.001', 10 ), array( 'b.tar.002', 5 ) ), $b['pieces'], 'in order:' );
		Sf_Test::same( false, $groups[0]['complete'], 'part 2 of c is missing:' );
		Sf_Test::same( array( 1, array( array( 'a.tar', 7 ) ) ), array( $groups[2]['parts'], $groups[2]['pieces'] ), 'the whole a.tar, not its stray part:' );
	}
);

Sf_Test::run(
	'part sizes from the settings form',
	static function () {
		Sf_Test::same( 0, Parts::normalize_mb( '' ) );
		Sf_Test::same( 2048, Parts::normalize_mb( '2048' ) );
		foreach ( array( 5, -1, Parts::MAX_MB + 1 ) as $bad ) {
			sf_refused(
				static function () use ( $bad ) {
					Parts::normalize_mb( $bad );
				},
				(string) $bad
			);
		}
	}
);

exit( Sf_Test::finish( 'test-parts' ) );
