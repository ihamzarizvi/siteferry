<?php
/**
 * Several storages per schedule (0.38.0): where it goes as a list, old settings migrated, the
 * places a run is in, export options, and the 3-2-1 meter.
 *
 * Run: php tests/test-copies.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Schedule\Copies;
use SiteFerry\Schedule\Schedule;

echo "Several storages and 3-2-1 tests\n";

Sf_Test::run(
	'where it goes: a list becomes send_to + send_also; old single settings keep working; checked and de-duplicated',
	static function () {
		$ready = array( 'dropbox', 'ftp', 's3' );
		$s     = Schedule::normalize( array( 'send_to' => array( 'ftp', 'dropbox', 'ftp', '' ) ), $ready );
		Sf_Test::same( array( 'ftp', array( 'dropbox' ) ), array( $s['send_to'], $s['send_also'] ), 'list:' );
		Sf_Test::same( false, $s['keep_local'], 'not forced on with storages:' );

		// Settings saved before 0.38.0: one storage, no send_also.
		$old = Schedule::normalize(
			array(
				'send_to'    => 'dropbox',
				'keep_local' => true,
			),
			$ready
		);
		Sf_Test::same( array( 'dropbox', array() ), array( $old['send_to'], $old['send_also'] ), 'old settings:' );
		Sf_Test::same( array( 'dropbox' ), Schedule::targets_of( $old ) );

		// send_to + send_also given separately (saved settings), and a comma list.
		Sf_Test::same( array( 's3', 'ftp' ), Schedule::targets_of( array( 'send_to' => 's3', 'send_also' => array( 'ftp', 's3' ) ) ) );
		Sf_Test::same( array( 'ftp', 'dropbox' ), Schedule::targets_of( array( 'send_to' => 'ftp,dropbox' ) ) );

		// Nothing chosen: this server, always.
		$none = Schedule::normalize( array( 'send_to' => array() ), $ready );
		Sf_Test::same( array( '', array(), true ), array( $none['send_to'], $none['send_also'], $none['keep_local'] ), 'none:' );

		foreach ( array( array( 'send_to' => array( 'dropbox', 'gdrive' ) ), array( 'send_to' => array( '../x' ) ), array( 'send_also' => array( 'BAD!' ) ) ) as $input ) {
			try {
				Schedule::normalize( $input, $ready );
			} catch ( Job_Exception $e ) {
				continue;
			}
			throw new RuntimeException( 'accepted: ' . json_encode( $input ) );
		}
	}
);

Sf_Test::run(
	'export options: send_also passed on, the copy here deleted only when asked; places of a recorded run',
	static function () {
		$s       = Schedule::normalize( array( 'send_to' => array( 'ftp', 'dropbox' ) ), array( 'ftp', 'dropbox' ) );
		$options = Schedule::export_options( $s );
		Sf_Test::same( array( 'ftp', array( 'dropbox' ), true ), array( $options['send_to'], $options['send_also'], $options['delete_after_send'] ) );
		$normalized = \SiteFerry\Export\Export::normalize( $options );
		Sf_Test::same( array( 'dropbox' ), $normalized['send_also'], 'valid export options:' );
		Sf_Test::same( array(), \SiteFerry\Export\Export::normalize( array( 'send_also' => array( 'ftp' ) ) )['send_also'], 'no send_also without send_to:' );
		Sf_Test::same( array( 'dropbox' ), \SiteFerry\Export\Export::normalize( array( 'send_to' => 'ftp', 'send_also' => array( 'ftp', 'dropbox', 'dropbox' ) ) )['send_also'], 'the first one and duplicates dropped:' );

		Sf_Test::same( array( 'dropbox' ), Schedule::places_of( array( 'destination' => 'dropbox' ) ), 'a run recorded before 0.38.0:' );
		Sf_Test::same(
			array( 'ftp', 'dropbox' ),
			Schedule::places_of(
				array(
					'destination'  => 'ftp',
					'destinations' => array( 'ftp', 'dropbox' ),
				)
			)
		);
		Sf_Test::same( array(), Schedule::places_of( array( 'destination' => '' ) ) );
	}
);

Sf_Test::run(
	'3-2-1: copies, kinds of storage, away from this server, and what is missing',
	static function () {
		$m = Copies::meter( array( 'local' ) );
		Sf_Test::same( array( 1, 1, 0, false ), array( $m['copies'], $m['kinds'], $m['offsite'], $m['ok'] ) );
		Sf_Test::same( array( 'offsite', 'kind', 'copies' ), $m['missing'] );

		$m = Copies::meter( array( 'local', 'gdrive' ) );
		Sf_Test::same( array( true, true, false ), array( $m['one'], $m['two'], $m['three'] ) );
		Sf_Test::same( array( 'copies' ), $m['missing'] );

		$m = Copies::meter( array( 'local', 'gdrive', 'ftp' ) );
		Sf_Test::same( true, $m['ok'], 'server + cloud + FTP:' );

		$m = Copies::meter( array( 'gdrive', 'dropbox', 's3' ) );
		Sf_Test::same( array( 3, 1, false, array( 'kind' ) ), array( $m['copies'], $m['kinds'], $m['ok'], $m['missing'] ), 'three clouds are one kind:' );

		$m = Copies::meter( array( 'local', 'local', 'gdrive', '' ) );
		Sf_Test::same( 2, $m['copies'], 'duplicates and empty ignored:' );

		Sf_Test::same( 'ftp', Copies::kind( 'ftp' ) );
		Sf_Test::same( 'cloud', Copies::kind( 'dropbox' ) );
		Sf_Test::same( 'server', Copies::kind( 'local' ) );
	}
);

Sf_Test::run(
	'3-2-1 places: planned from the settings, and of the newest recorded run',
	static function () {
		Sf_Test::same( array( 'local' ), Copies::planned( array( 'send_to' => '' ) ) );
		Sf_Test::same(
			array( 'local', 'ftp', 'gdrive' ),
			Copies::planned(
				array(
					'send_to'    => 'ftp',
					'send_also'  => array( 'gdrive' ),
					'keep_local' => true,
				)
			)
		);
		Sf_Test::same(
			array( 'ftp' ),
			Copies::planned(
				array(
					'send_to'    => 'ftp',
					'keep_local' => false,
				)
			)
		);
		Sf_Test::same( null, Copies::newest( array() ) );
		$runs = array(
			array(
				'name'        => 'a.tar',
				'time'        => 100,
				'local'       => true,
				'destination' => 'dropbox',
			),
			array(
				'name'         => 'b.tar',
				'time'         => 200,
				'local'        => false,
				'destination'  => 'ftp',
				'destinations' => array( 'ftp', 'gdrive' ),
			),
		);
		Sf_Test::same( array( 'ftp', 'gdrive' ), Copies::newest( $runs ), 'the newest run:' );
	}
);

exit( Sf_Test::finish( 'test-copies' ) );
