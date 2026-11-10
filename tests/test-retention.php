<?php
/**
 * Which scheduled backups are kept: the newest N, or the newest of each of the last days, weeks
 * and months in the site's time zone (ISO weeks, calendar months), with changes-only bases kept.
 *
 * Run: php tests/test-retention.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Schedule\Retention;

echo "Retention tests\n";

/**
 * A run at a local time in a zone.
 *
 * @param string $name  Name.
 * @param string $local Local time, "Y-m-d H:i".
 * @param string $zone  Time zone.
 * @param string $base  Full backup it is built on.
 */
function sf_run( string $name, string $local, string $zone = 'UTC', string $base = '' ): array {
	return array(
		'name' => $name,
		'time' => ( new DateTimeImmutable( $local, new DateTimeZone( $zone ) ) )->getTimestamp(),
		'base' => $base,
	);
}

/**
 * Settings for "periods".
 *
 * @param int $d Days.
 * @param int $w Weeks.
 * @param int $m Months.
 */
function sf_periods( int $d, int $w, int $m ): array {
	return array(
		'retain_mode'  => 'periods',
		'retain'       => 7,
		'keep_daily'   => $d,
		'keep_weekly'  => $w,
		'keep_monthly' => $m,
	);
}

/**
 * Names kept, sorted.
 *
 * @param array $result select() result.
 */
function sf_kept( array $result ): array {
	$names = array_keys( $result['keep'] );
	sort( $names );
	return $names;
}

Sf_Test::run(
	'daily: the newest of each of the last D days; an earlier backup the same day goes',
	static function () {
		$zone = 'Asia/Karachi';
		$runs = array();
		for ( $day = 1; $day <= 20; $day++ ) {
			$runs[] = sf_run( sprintf( 'd%02d', $day ), sprintf( '2026-11-%02d 03:00', $day ), $zone );
		}
		$runs[] = sf_run( 'd20-late', '2026-11-20 15:00', $zone ); // A second one on the 20th, later.
		$now    = ( new DateTimeImmutable( '2026-11-20 18:00', new DateTimeZone( $zone ) ) )->getTimestamp();
		$result = Retention::select( $runs, sf_periods( 7, 0, 0 ), $now, new DateTimeZone( $zone ) );
		Sf_Test::same( array( 'd14', 'd15', 'd16', 'd17', 'd18', 'd19', 'd20-late' ), sf_kept( $result ) );
		Sf_Test::same( array( 'day' ), $result['keep']['d14'] );
		Sf_Test::same( 'd01', $result['expired'][0]['name'], 'expired oldest first:' );
		Sf_Test::ok( in_array( 'd20', array_column( $result['expired'], 'name' ), true ), 'the earlier backup of the 20th goes' );
	}
);

Sf_Test::run(
	'the site\'s time zone decides the day: 01:00 in Karachi is still the day before in UTC',
	static function () {
		$runs = array(
			sf_run( 'evening', '2026-11-09 18:00', 'UTC' ), // 23:00 on the 9th in Karachi.
			sf_run( 'late', '2026-11-09 20:00', 'UTC' ),    // 01:00 on the 10th in Karachi.
		);
		$now  = ( new DateTimeImmutable( '2026-11-09 21:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		Sf_Test::same( array( 'late' ), sf_kept( Retention::select( $runs, sf_periods( 1, 0, 0 ), $now, new DateTimeZone( 'UTC' ) ) ), 'UTC: one day, the newer one:' );
		Sf_Test::same( array( 'evening', 'late' ), sf_kept( Retention::select( $runs, sf_periods( 2, 0, 0 ), $now, new DateTimeZone( 'Asia/Karachi' ) ) ), 'Karachi: two different days:' );
	}
);

Sf_Test::run(
	'weekly: ISO weeks (Monday to Sunday), across the year end',
	static function () {
		$zone = new DateTimeZone( 'Europe/Berlin' );
		$runs = array(
			sf_run( 'mon-dec-21', '2026-12-21 03:00', 'Europe/Berlin' ),
			sf_run( 'sun-dec-27', '2026-12-27 03:00', 'Europe/Berlin' ), // Same ISO week as Dec 21.
			sf_run( 'thu-dec-31', '2026-12-31 03:00', 'Europe/Berlin' ), // 2026-W53.
			sf_run( 'fri-jan-01', '2027-01-01 03:00', 'Europe/Berlin' ), // Also 2026-W53.
			sf_run( 'mon-jan-04', '2027-01-04 03:00', 'Europe/Berlin' ), // 2027-W01.
		);
		$now    = ( new DateTimeImmutable( '2027-01-04 12:00', $zone ) )->getTimestamp();
		$result = Retention::select( $runs, sf_periods( 0, 3, 0 ), $now, $zone );
		Sf_Test::same( array( 'fri-jan-01', 'mon-jan-04', 'sun-dec-27' ), sf_kept( $result ), 'one per week, the newest of each:' );
		Sf_Test::same( 'Sunday', ( new DateTimeImmutable( '2026-12-27 03:00', $zone ) )->format( 'l' ) );
	}
);

Sf_Test::run(
	'monthly: calendar months, counted from the 1st so a short month is not skipped',
	static function () {
		$zone = new DateTimeZone( 'UTC' );
		$runs = array(
			sf_run( 'jan-31', '2026-01-31 03:00' ),
			sf_run( 'feb-10', '2026-02-10 03:00' ),
			sf_run( 'feb-28', '2026-02-28 03:00' ),
			sf_run( 'mar-30', '2026-03-30 03:00' ),
			sf_run( 'mar-31', '2026-03-31 03:00' ),
		);
		$now    = ( new DateTimeImmutable( '2026-03-31 12:00', $zone ) )->getTimestamp();
		$result = Retention::select( $runs, sf_periods( 0, 0, 2 ), $now, $zone );
		Sf_Test::same( array( 'feb-28', 'mar-31' ), sf_kept( $result ), 'March and February (from the 31st, "1 month back" would land in March again):' );
		Sf_Test::same( array( 'jan-31', 'feb-10', 'mar-30' ), array_column( $result['expired'], 'name' ) );
	}
);

Sf_Test::run(
	'together: a backup can be the newest of its day, week and month at once; the newest always stays',
	static function () {
		$zone = new DateTimeZone( 'UTC' );
		$runs = array(
			sf_run( 'old', '2026-05-01 03:00' ),
			sf_run( 'today', '2026-11-10 03:00' ),
		);
		$now    = ( new DateTimeImmutable( '2026-11-10 12:00', $zone ) )->getTimestamp();
		$result = Retention::select( $runs, sf_periods( 7, 4, 6 ), $now, $zone );
		Sf_Test::same( array( 'day', 'week', 'month' ), $result['keep']['today'] );
		Sf_Test::same( array( 'old' ), array_column( $result['expired'], 'name' ), 'May is more than 6 months back:' );

		// All backups older than every period: the newest still stays.
		$stale = Retention::select( array( sf_run( 'a', '2026-01-01 03:00' ), sf_run( 'b', '2026-01-02 03:00' ) ), sf_periods( 1, 0, 0 ), $now, $zone );
		Sf_Test::same( array( 'b' ), sf_kept( $stale ) );
		Sf_Test::same( array( 'newest' ), $stale['keep']['b'] );
	}
);

Sf_Test::run(
	'changes-only: the full backup a kept changes-only backup needs stays, even outside the periods',
	static function () {
		$zone = new DateTimeZone( 'UTC' );
		$runs = array(
			sf_run( 'full-1', '2026-11-01 03:00' ),
			sf_run( 'inc-1', '2026-11-09 03:00', 'UTC', 'full-1' ),
			sf_run( 'inc-2', '2026-11-10 03:00', 'UTC', 'full-1' ),
		);
		$now    = ( new DateTimeImmutable( '2026-11-10 12:00', $zone ) )->getTimestamp();
		$result = Retention::select( $runs, sf_periods( 2, 0, 0 ), $now, $zone );
		Sf_Test::same( array( 'full-1', 'inc-1', 'inc-2' ), sf_kept( $result ) );
		Sf_Test::same( array( 'base' ), $result['keep']['full-1'] );
		Sf_Test::same( array( 'full-1' ), $result['held'] );
		Sf_Test::same( array(), $result['expired'] );
	}
);

Sf_Test::run(
	'newest N: the same choice as before (and 0 keeps all); ties broken by recording order',
	static function () {
		$zone = new DateTimeZone( 'UTC' );
		$runs = array(
			sf_run( 'a', '2026-11-01 03:00' ),
			sf_run( 'b', '2026-11-02 03:00' ),
			sf_run( 'c', '2026-11-02 03:00' ), // Same second as b, recorded later.
		);
		$now    = ( new DateTimeImmutable( '2026-11-10 12:00', $zone ) )->getTimestamp();
		$newest = array(
			'retain_mode' => 'newest',
			'retain'      => 1,
		);
		Sf_Test::same( array( 'c' ), sf_kept( Retention::select( $runs, $newest, $now, $zone ) ) );
		Sf_Test::same( array( 'a', 'b', 'c' ), sf_kept( Retention::select( $runs, array( 'retain' => 0 ) + $newest, $now, $zone ) ), '0 keeps all:' );
		list( $old ) = \SiteFerry\Incremental::expired( $runs, 1 );
		Sf_Test::same( array_column( $old, 'name' ), array_column( Retention::select( $runs, $newest, $now, $zone )['expired'], 'name' ), 'same as the existing rule:' );
	}
);

Sf_Test::run(
	'settings: the new choices are checked',
	static function () {
		$ok = \SiteFerry\Schedule\Schedule::normalize( sf_periods( 7, 4, 6 ), array() );
		Sf_Test::same( 'periods', $ok['retain_mode'] );
		foreach ( array( array( 'retain_mode' => 'forever' ), sf_periods( 0, 0, 0 ), sf_periods( 61, 0, 0 ), sf_periods( 1, 53, 0 ), sf_periods( 1, 0, 121 ) ) as $bad ) {
			try {
				\SiteFerry\Schedule\Schedule::normalize( $bad, array() );
			} catch ( \SiteFerry\Jobs\Job_Exception $e ) {
				continue;
			}
			throw new RuntimeException( 'accepted: ' . json_encode( $bad ) );
		}
		Sf_Test::ok( true, 'refused out of range' );
	}
);

exit( Sf_Test::finish( 'test-retention' ) );
