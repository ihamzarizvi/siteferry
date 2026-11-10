<?php
/**
 * Weekly re-check of stored copies: which copies are checked (never checked first, newest
 * first, then checked longest ago), the size cap, incomplete and password-protected copies, and
 * scheduled backups missing on a storage. Also how an outcome is recorded.
 *
 * Run: php tests/test-recheck.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Check\Recheck;

echo "Re-check of stored copies tests\n";

/**
 * A listed copy.
 *
 * @param string $name     Name.
 * @param int    $size     Size.
 * @param int    $modified Time.
 * @param bool   $complete All parts there.
 */
function sf_copy( string $name, int $size, int $modified, bool $complete = true ): array {
	return array(
		'name'     => $name,
		'size'     => $size,
		'modified' => $modified,
		'complete' => $complete,
	);
}

/**
 * "name@place" for each planned item.
 *
 * @param array $items Items.
 */
function sf_at_places( array $items ): array {
	return array_map(
		static function ( array $item ): string {
			return $item['name'] . '@' . $item['place'];
		},
		$items
	);
}

Sf_Test::run(
	'never checked first, newest first; two per storage; each storage on its own',
	static function () {
		$listings = array(
			'dropbox' => array( sf_copy( 'a.tar', 10, 100 ), sf_copy( 'b.tar', 10, 200 ), sf_copy( 'c.tar', 10, 300 ) ),
			'ftp'     => array( sf_copy( 'a.tar', 10, 100 ) ),
		);
		$plan     = Recheck::plan( $listings, array(), array(), 0 );
		Sf_Test::same( array( 'c.tar@dropbox', 'b.tar@dropbox', 'a.tar@ftp' ), sf_at_places( $plan['queue'] ) );
		Sf_Test::same( array(), $plan['skipped'] );
	}
);

Sf_Test::run(
	'rotation: once the newest are checked, the ones checked longest ago come next; a changed size counts as never checked',
	static function () {
		$listings = array( 'dropbox' => array( sf_copy( 'a.tar', 10, 100 ), sf_copy( 'b.tar', 10, 200 ), sf_copy( 'c.tar', 10, 300 ), sf_copy( 'd.tar', 10, 400 ) ) );
		$outcomes = array(
			'd.tar' => array( 'dropbox' => array( 'time' => 5000, 'size' => 10, 'ok' => true ) ),
			'c.tar' => array( 'dropbox' => array( 'time' => 5000, 'size' => 10, 'ok' => true ) ),
			'b.tar' => array( 'dropbox' => array( 'time' => 1000, 'size' => 10, 'ok' => true ) ),
			'a.tar' => array( 'dropbox' => array( 'time' => 2000, 'size' => 10, 'ok' => true ) ),
		);
		Sf_Test::same( array( 'b.tar@dropbox', 'a.tar@dropbox' ), sf_at_places( Recheck::plan( $listings, $outcomes, array(), 0 )['queue'] ), 'oldest checks first:' );

		$outcomes['c.tar']['dropbox']['size'] = 9; // That check was of another copy.
		Sf_Test::same( 'c.tar@dropbox', sf_at_places( Recheck::plan( $listings, $outcomes, array(), 0 )['queue'] )[0], 'changed copy first:' );

		// Week by week every copy gets its turn.
		$seen = array();
		$time = 10000;
		for ( $week = 0; $week < 2; $week++ ) {
			foreach ( Recheck::plan( $listings, $outcomes, array(), 0 )['queue'] as $item ) {
				$seen[ $item['name'] ]               = true;
				$outcomes[ $item['name'] ]['dropbox'] = array(
					'time' => ++$time,
					'size' => 10,
					'ok'   => true,
				);
			}
		}
		ksort( $seen );
		Sf_Test::same( array( 'a.tar', 'b.tar', 'c.tar', 'd.tar' ), array_keys( $seen ), 'all four in two weeks:' );
	}
);

Sf_Test::run(
	'the size cap: copies that do not fit are skipped and named; smaller ones still go; 0 means no limit',
	static function () {
		$listings = array( 'dropbox' => array( sf_copy( 'big.tar', 900, 300 ), sf_copy( 'small.tar', 100, 200 ), sf_copy( 'old.tar', 100, 100 ) ) );
		$plan     = Recheck::plan( $listings, array(), array(), 500 );
		Sf_Test::same( array( 'small.tar@dropbox', 'old.tar@dropbox' ), sf_at_places( $plan['queue'] ) );
		Sf_Test::same( array( 'big.tar@dropbox' ), sf_at_places( $plan['skipped'] ) );
		Sf_Test::same( array( 'big.tar@dropbox', 'small.tar@dropbox' ), sf_at_places( Recheck::plan( $listings, array(), array(), 0 )['queue'] ) );
		// The cap counts across storages.
		$two = Recheck::plan(
			array(
				'dropbox' => array( sf_copy( 'x.tar', 300, 1 ) ),
				'ftp'     => array( sf_copy( 'y.tar', 300, 1 ) ),
			),
			array(),
			array(),
			500
		);
		Sf_Test::same( array( array( 'x.tar@dropbox' ), array( 'y.tar@ftp' ) ), array( sf_at_places( $two['queue'] ), sf_at_places( $two['skipped'] ) ) );
	}
);

Sf_Test::run(
	'incomplete sets and password-protected copies without a stored password are not checked',
	static function () {
		$listings = array( 'dropbox' => array( sf_copy( 'parts.tar', 10, 300, false ), sf_copy( 'locked.tar.enc', 10, 200 ), sf_copy( 'plain.tar', 10, 100 ) ) );
		Sf_Test::same( array( 'plain.tar@dropbox' ), sf_at_places( Recheck::plan( $listings, array(), array(), 0 )['queue'] ) );
		Sf_Test::same( array( 'locked.tar.enc@dropbox', 'plain.tar@dropbox' ), sf_at_places( Recheck::plan( $listings, array(), array(), 0, 2, true )['queue'] ), 'with the schedule\'s password:' );
	}
);

Sf_Test::run(
	'scheduled backups missing on a storage that was listed are reported; unlisted storages are not judged',
	static function () {
		$listings = array( 'dropbox' => array( sf_copy( 'here.tar', 10, 100 ) ) );
		$runs     = array(
			array(
				'name'         => 'here.tar',
				'destination'  => 'dropbox',
				'destinations' => array( 'dropbox', 'ftp' ),
			),
			array(
				'name'        => 'gone.tar',
				'destination' => 'dropbox',
			),
			array(
				'name'        => 'local-only.tar',
				'destination' => '',
			),
		);
		$plan     = Recheck::plan( $listings, array(), $runs, 0 );
		Sf_Test::same( array( 'gone.tar@dropbox' ), sf_at_places( $plan['missing'] ), 'FTP was not listed, so here.tar is not judged there:' );
	}
);

exit( Sf_Test::finish( 'test-recheck' ) );
