<?php
/**
 * Backup schedule, the parts that need no WordPress: settings, when the next run is due (time
 * zones, daylight saving time, month ends), which scheduled backups retention deletes, and the
 * export options a schedule makes.
 *
 * Run: php tests/test-schedule.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Schedule\Schedule;

echo "Schedule tests\n";

/**
 * Normalized settings with overrides.
 *
 * @param array $input Overrides.
 * @param array $ready Ready storages.
 */
function sf_sched( array $input = array(), array $ready = array( 'dropbox' ) ): array {
	return Schedule::normalize( $input, $ready );
}

/**
 * A Unix time from a local date and time in a zone.
 *
 * @param string $local "Y-m-d H:i".
 * @param string $zone  Time zone.
 */
function sf_at( string $local, string $zone ): int {
	return ( new DateTimeImmutable( $local, new DateTimeZone( $zone ) ) )->getTimestamp();
}

/**
 * A Unix time shown in a zone.
 *
 * @param int    $time Unix time.
 * @param string $zone Time zone.
 */
function sf_show( int $time, string $zone ): string {
	return ( new DateTimeImmutable( '@' . $time ) )->setTimezone( new DateTimeZone( $zone ) )->format( 'Y-m-d H:i D' );
}

Sf_Test::run(
	'settings: defaults, types, and every invalid value refused',
	static function () {
		$defaults = sf_sched();
		Sf_Test::same( false, $defaults['enabled'], 'off until turned on:' );
		Sf_Test::same( 'daily', $defaults['frequency'] );
		Sf_Test::same( '03:00', $defaults['time'] );
		Sf_Test::same( 7, $defaults['retain'] );
		Sf_Test::same( 'failure', $defaults['notify'] );
		Sf_Test::same( true, $defaults['exclude_cache'] );
		Sf_Test::same( true, $defaults['keep_local'], 'backups kept on this server stay there:' );

		$set = sf_sched( array( 'enabled' => '1', 'frequency' => 'weekly', 'weekday' => '0', 'time' => '23:59', 'retain' => '0', 'send_to' => 'dropbox', 'keep_local' => 'false', 'format' => 'wpress', 'compress' => true, 'email' => 'ops@example.com', 'notify' => 'always' ) );
		Sf_Test::same( true, $set['enabled'] );
		Sf_Test::same( 0, $set['weekday'], 'Sunday:' );
		Sf_Test::same( 0, $set['retain'], 'keep all:' );
		Sf_Test::same( false, $set['keep_local'] );
		Sf_Test::same( false, $set['compress'], 'a .wpress file is never compressed:' );
		Sf_Test::same( 'ops@example.com', $set['email'] );

		$bad = array(
			array( 'frequency' => 'hourly' ),
			array( 'time' => '24:00' ),
			array( 'time' => '3:00' ),
			array( 'time' => '03:00:00' ),
			array( 'weekday' => 7 ),
			array( 'weekday' => 'monday' ),
			array( 'monthday' => 0 ),
			array( 'monthday' => 29 ),
			array( 'retain' => -1 ),
			array( 'retain' => 101 ),
			array( 'format' => 'zip' ),
			array( 'send_to' => 's3' ),
			array( 'notify' => 'sometimes' ),
			array( 'email' => 'not an address' ),
			array( 'email' => "a@example.com\nBcc: b@example.com" ),
		);
		foreach ( $bad as $input ) {
			try {
				sf_sched( $input );
			} catch ( Job_Exception $e ) {
				Sf_Test::same( Job_Exception::STEP, $e->getCode() );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . json_encode( $input ) );
		}
	}
);

Sf_Test::run(
	'next run, daily: later today, or tomorrow once the time has passed (in the site\'s zone)',
	static function () {
		$daily = sf_sched( array( 'time' => '03:00' ) );
		$zone  = 'Europe/Berlin';
		Sf_Test::same( '2026-11-05 03:00 Thu', sf_show( Schedule::next_run( $daily, sf_at( '2026-11-05 01:00', $zone ), new DateTimeZone( $zone ) ), $zone ) );
		Sf_Test::same( '2026-11-06 03:00 Fri', sf_show( Schedule::next_run( $daily, sf_at( '2026-11-05 03:00', $zone ), new DateTimeZone( $zone ) ), $zone ), 'exactly at the time: the next day' );
		Sf_Test::same( '2026-11-06 03:00 Fri', sf_show( Schedule::next_run( $daily, sf_at( '2026-11-05 15:00', $zone ), new DateTimeZone( $zone ) ), $zone ) );
		$pk = 'Asia/Karachi';
		Sf_Test::same( '2026-11-06 03:00 Fri', sf_show( Schedule::next_run( $daily, sf_at( '2026-11-05 22:00', $pk ), new DateTimeZone( $pk ) ), $pk ), 'another zone:' );
		Sf_Test::same( sf_at( '2026-11-06 03:00', 'UTC' ), Schedule::next_run( $daily, sf_at( '2026-11-05 22:00', 'UTC' ), new DateTimeZone( 'UTC' ) ), 'UTC:' );
	}
);

Sf_Test::run(
	'next run across daylight saving time keeps the local time',
	static function () {
		$zone  = 'Europe/Berlin';
		$daily = sf_sched( array( 'time' => '03:00' ) );
		// Clocks go back on 25 October 2026 and forward on 29 March 2026.
		Sf_Test::same( '2026-10-25 03:00 Sun', sf_show( Schedule::next_run( $daily, sf_at( '2026-10-24 12:00', $zone ), new DateTimeZone( $zone ) ), $zone ) );
		Sf_Test::same( '2026-03-29 03:00 Sun', sf_show( Schedule::next_run( $daily, sf_at( '2026-03-28 12:00', $zone ), new DateTimeZone( $zone ) ), $zone ) );
		$gap = sf_sched( array( 'time' => '02:30' ) );
		$run = Schedule::next_run( $gap, sf_at( '2026-03-28 12:00', $zone ), new DateTimeZone( $zone ) );
		Sf_Test::ok( $run > sf_at( '2026-03-28 12:00', $zone ) && $run < sf_at( '2026-03-29 04:00', $zone ), 'a time the clocks skip still runs that night: ' . sf_show( $run, $zone ) );
	}
);

Sf_Test::run(
	'next run, weekly: the chosen weekday, this week or next',
	static function () {
		$zone   = 'America/New_York';
		$monday = sf_sched( array( 'frequency' => 'weekly', 'weekday' => 1, 'time' => '04:15' ) );
		Sf_Test::same( '2026-11-09 04:15 Mon', sf_show( Schedule::next_run( $monday, sf_at( '2026-11-05 10:00', $zone ), new DateTimeZone( $zone ) ), $zone ), 'Thursday: next Monday' );
		Sf_Test::same( '2026-11-09 04:15 Mon', sf_show( Schedule::next_run( $monday, sf_at( '2026-11-09 04:00', $zone ), new DateTimeZone( $zone ) ), $zone ), 'Monday before the time: today' );
		Sf_Test::same( '2026-11-16 04:15 Mon', sf_show( Schedule::next_run( $monday, sf_at( '2026-11-09 05:00', $zone ), new DateTimeZone( $zone ) ), $zone ), 'Monday after the time: a week later' );
		$sunday = sf_sched( array( 'frequency' => 'weekly', 'weekday' => 0, 'time' => '00:00' ) );
		Sf_Test::same( '2026-11-08 00:00 Sun', sf_show( Schedule::next_run( $sunday, sf_at( '2026-11-07 23:59', $zone ), new DateTimeZone( $zone ) ), $zone ) );
	}
);

Sf_Test::run(
	'next run, monthly: the chosen day, this month or next, across the year end',
	static function () {
		$zone    = 'UTC';
		$monthly = sf_sched( array( 'frequency' => 'monthly', 'monthday' => 28, 'time' => '01:00' ) );
		Sf_Test::same( '2026-11-28 01:00 Sat', sf_show( Schedule::next_run( $monthly, sf_at( '2026-11-05 10:00', $zone ), new DateTimeZone( $zone ) ), $zone ) );
		Sf_Test::same( '2027-02-28 01:00 Sun', sf_show( Schedule::next_run( $monthly, sf_at( '2027-01-30 10:00', $zone ), new DateTimeZone( $zone ) ), $zone ), 'from the end of January: February 28' );
		$first = sf_sched( array( 'frequency' => 'monthly', 'monthday' => 1, 'time' => '03:00' ) );
		Sf_Test::same( '2027-01-01 03:00 Fri', sf_show( Schedule::next_run( $first, sf_at( '2026-12-01 04:00', $zone ), new DateTimeZone( $zone ) ), $zone ), 'December after the time: January' );
		Sf_Test::same( '2026-12-01 03:00 Tue', sf_show( Schedule::next_run( $first, sf_at( '2026-11-30 23:00', $zone ), new DateTimeZone( $zone ) ), $zone ) );
	}
);

Sf_Test::run(
	'retention: only the scheduled backups beyond the newest N, oldest first; 0 keeps all',
	static function () {
		$runs = array();
		foreach ( array( 30, 10, 50, 20, 40 ) as $time ) {
			$runs[] = array(
				'name'        => 'b-' . $time . '.tar',
				'time'        => $time,
				'local'       => true,
				'destination' => '',
			);
		}
		Sf_Test::same( array( 'b-10.tar', 'b-20.tar' ), array_column( Schedule::expired( $runs, 3 ), 'name' ) );
		Sf_Test::same( array(), Schedule::expired( $runs, 5 ) );
		Sf_Test::same( array(), Schedule::expired( $runs, 9 ) );
		Sf_Test::same( array(), Schedule::expired( $runs, 0 ), 'keep all:' );
		Sf_Test::same( array( 'b-10.tar', 'b-20.tar', 'b-30.tar', 'b-40.tar' ), array_column( Schedule::expired( $runs, 1 ), 'name' ) );
	}
);

Sf_Test::run(
	'export options: what to leave out, the file, where, and the copy on this server',
	static function () {
		$options = Schedule::export_options( sf_sched( array( 'exclude_media' => true, 'format' => 'tar', 'compress' => true, 'send_to' => 'dropbox', 'keep_local' => false ) ) );
		Sf_Test::same( true, $options['exclude_media'] );
		Sf_Test::same( true, $options['compress'] );
		Sf_Test::same( 'dropbox', $options['send_to'] );
		Sf_Test::same( true, $options['delete_after_send'], 'not kept on this server:' );
		Sf_Test::same( array( 'scheduled' ), $options['tags'] );
		Sf_Test::ok( ! isset( $options['retain'], $options['email'], $options['enabled'] ), 'only export options' );
		$kept = Schedule::export_options( sf_sched( array( 'send_to' => 'dropbox', 'keep_local' => true ) ) );
		Sf_Test::same( false, $kept['delete_after_send'] );
		$here = Schedule::export_options( sf_sched( array( 'send_to' => '', 'keep_local' => false ) ) );
		Sf_Test::same( false, $here['delete_after_send'], 'backups on this server only are never deleted after a send:' );
	}
);

exit( Sf_Test::finish( 'test-schedule' ) );
