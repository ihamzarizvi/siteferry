<?php
/**
 * Activity export: rows from job summaries, the CSV text (byte order mark, quoting, formulas
 * defused), durations and the --since date.
 *
 * Run: php tests/test-activity.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Activity;

echo "Activity export tests\n";

/**
 * A job summary.
 *
 * @param array $over Overrides.
 */
function sf_act_job( array $over = array() ): array {
	return $over + array(
		'type'     => 'export',
		'status'   => 'done',
		'origin'   => 'admin',
		'backup'   => 'example-2026-11-10-abcdef012345.tar',
		'by'       => 'sfadmin',
		'progress' => array( 'message' => 'Backup complete' ),
		'warnings' => array( 'count' => 0 ),
		'error'    => null,
		'result'   => array( 'size' => 123456 ),
		'created'  => 1794300000,
		'updated'  => 1794300125,
	);
}

Sf_Test::run(
	'a row: times in the site zone and UTC, duration, size, places, who, message',
	static function () {
		$tz  = new DateTimeZone( 'Asia/Karachi' );
		$row = Activity::row( sf_act_job(), array( 'local', 's3' ), $tz );
		Sf_Test::same( Activity::COLUMNS, array_keys( $row ), 'every column, in order:' );
		Sf_Test::same( '2026-11-10T08:40:00Z', $row['time_utc'] );
		Sf_Test::same( '2026-11-10 13:40:00 PKT', $row['time'], 'site time zone:' );
		Sf_Test::same( '2:05', $row['duration'] );
		Sf_Test::same( '123456', $row['size'] );
		Sf_Test::same( 'this server, s3', $row['where'] );
		Sf_Test::same( 'sfadmin', $row['started_by'] );
		Sf_Test::same( 'Backup complete', $row['message'] );

		$failed = Activity::row( sf_act_job( array( 'status' => 'failed', 'error' => "Disk full\nwhile packing", 'warnings' => array( 'count' => 2 ) ) ), array(), $tz );
		Sf_Test::same( 'Disk full while packing (2 warnings)', $failed['message'], 'error on one line, warnings counted:' );
		$running = Activity::row( sf_act_job( array( 'status' => 'running', 'by' => '', 'origin' => 'schedule', 'result' => array() ) ), array(), $tz );
		Sf_Test::same( array( '', '', 'schedule' ), array( $running['duration'], $running['size'], $running['started_by'] ), 'running: no duration yet; older jobs fall back to their origin:' );
		$check = Activity::row( sf_act_job( array( 'type' => 'check', 'result' => array( 'checked' => array( 'size' => 99 ) ) ) ), array( 'gdrive' ), $tz );
		Sf_Test::same( '99', $check['size'], 'a check: the size of the checked copy:' );
	}
);

Sf_Test::run(
	'CSV: byte order mark, titles, every cell quoted, quotes doubled, formulas defused',
	static function () {
		$row = Activity::row( sf_act_job( array( 'backup' => '=HYPERLINK("http://evil")', 'by' => '+cmd', 'error' => '@SUM(1)' , 'status' => 'failed' ) ), array(), new DateTimeZone( 'UTC' ) );
		$csv = Activity::csv( array( $row ) );
		Sf_Test::same( "\xEF\xBB\xBF", substr( $csv, 0, 3 ), 'BOM first:' );
		$lines = explode( "\r\n", rtrim( substr( $csv, 3 ), "\r\n" ) );
		Sf_Test::same( 2, count( $lines ), 'titles and one row, CRLF:' );
		Sf_Test::same( '"Time","Time (UTC)","Type","Backup","Result","Duration","Size","Where","Started by","Message"', $lines[0] );
		Sf_Test::ok( false !== strpos( $lines[1], '"\'=HYPERLINK(""http://evil"")"' ), 'formula prefixed and quotes doubled: ' . $lines[1] );
		Sf_Test::ok( false !== strpos( $lines[1], '"\'+cmd"' ), 'plus prefixed' );
		Sf_Test::ok( false !== strpos( $lines[1], '"\'@SUM(1)"' ), 'at prefixed' );
		Sf_Test::same( '"\'-1","a,b","",' . "\"'\tx\"", Activity::csv_line( array( '-1', 'a,b', '', "\tx" ) ), 'minus and tab defused, commas kept inside quotes:' );
		Sf_Test::same( "\xEF\xBB\xBF" . $lines[0] . "\r\n", Activity::csv( array() ), 'no rows: just the titles:' );
	}
);

Sf_Test::run(
	'durations and the since date',
	static function () {
		Sf_Test::same( array( '0:00', '0:59', '1:00', '59:59', '1:00:00', '25:01:01' ), array_map( array( Activity::class, 'duration' ), array( 0, 59, 60, 3599, 3600, 90061 ) ) );
		$tz = new DateTimeZone( 'Asia/Karachi' );
		Sf_Test::same( 1793991600, Activity::since( '2026-11-07', $tz ), 'midnight in the site zone:' );
		Sf_Test::same( null, Activity::since( '', $tz ) );
		foreach ( array( '2026-13-01', '07-11-2026', '2026-11-7', 'yesterday' ) as $bad ) {
			try {
				Activity::since( $bad, $tz );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
			throw new RuntimeException( 'accepted: ' . $bad );
		}
	}
);

exit( Sf_Test::finish( 'test-activity' ) );
