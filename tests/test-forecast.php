<?php
/**
 * Forecast: size and time ranges from measurements on this site; unknown until measured.
 *
 * Run: php tests/test-forecast.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Forecast;

echo "Forecast tests\n";

const SF_MB = 1048576;

Sf_Test::run(
	'nothing measured yet: the size is known, the times are not',
	static function () {
		$f = Forecast::estimate( 400 * SF_MB, array(), array() );
		Sf_Test::same( array( 400 * SF_MB, 400 * SF_MB ), $f['bytes'] );
		Sf_Test::same( null, $f['make'] );
		Sf_Test::same( null, $f['send'] );
		Sf_Test::same( array(), $f['based_on'] );
		$gz = Forecast::estimate( 100 * SF_MB, array( 'compress' => true ), array() );
		Sf_Test::same( array( 40 * SF_MB, 90 * SF_MB ), $gz['bytes'], 'compressed, before any compressed backup: 40 to 90 %:' );
	}
);

Sf_Test::run(
	'from the last backup of the same kind: a range around its speed',
	static function () {
		$speeds = array(
			'backup'    => array(
				'bytes'   => 425 * SF_MB,
				'seconds' => 997.0,
			),
			'backup_gz' => array(
				'bytes'   => 100 * SF_MB,
				'seconds' => 50.0,
			),
			'ratio'     => 0.5,
		);
		$f      = Forecast::estimate( 425 * SF_MB, array(), $speeds );
		Sf_Test::same( array( (int) round( 997 * 0.75 ), (int) round( 997 * 1.4 ) ), $f['make'], 'the same size takes about as long:' );
		Sf_Test::same( array( 'backup' ), $f['based_on'] );
		$gz = Forecast::estimate( 200 * SF_MB, array( 'compress' => true ), $speeds );
		Sf_Test::same( array( 75, 140 ), $gz['make'], 'compressed: its own speed (2 MB/s), on the uncompressed size:' );
		Sf_Test::same( array( 80 * SF_MB, 120 * SF_MB ), $gz['bytes'], 'compressed size around the last ratio (0.5 ± 20 %):' );
	}
);

Sf_Test::run(
	'sending: the upload speed last measured to that storage; other storages unknown',
	static function () {
		$speeds = array(
			'up' => array(
				'gdrive' => array(
					'bytes'   => 50 * SF_MB,
					'seconds' => 25.0,
				),
			),
		);
		$f = Forecast::estimate( 100 * SF_MB, array( 'send_to' => 'gdrive' ), $speeds );
		Sf_Test::same( array( 38, 70 ), $f['send'], '100 MB at 2 MB/s, as a range:' );
		Sf_Test::same( null, Forecast::estimate( 100 * SF_MB, array( 'send_to' => 'ftp' ), $speeds )['send'] );
		Sf_Test::same( null, Forecast::estimate( 100 * SF_MB, array(), $speeds )['send'], 'kept here: nothing to send:' );
	}
);

Sf_Test::run(
	'measurements too short or broken are not used',
	static function () {
		Sf_Test::same( null, Forecast::rate( array( 'bytes' => 1000, 'seconds' => 1.0 ) ), 'under 3 seconds:' );
		Sf_Test::same( null, Forecast::rate( array( 'bytes' => 0, 'seconds' => 10.0 ) ) );
		Sf_Test::same( null, Forecast::rate( 'x' ) );
		Sf_Test::same( 100.0, Forecast::rate( array( 'bytes' => 1000, 'seconds' => 10 ) ) );
		$f = Forecast::estimate( -5, array( 'compress' => true ), array( 'ratio' => 7 ) );
		Sf_Test::same( array( 0, 0 ), $f['bytes'], 'a negative size and an impossible ratio:' );
	}
);

exit( Sf_Test::finish( 'test-forecast' ) );
