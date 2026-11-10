<?php
/**
 * Changes-only backups, the parts that need no WordPress: comparing the files with the full
 * backup (new, changed, unchanged, gone), reading the checksum list and its fingerprint from a
 * backup, naming the full backup in a manifest, and retention that keeps a full backup as long as
 * kept changes-only backups are built on it.
 *
 * Run: php tests/test-incremental.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Incremental;

echo "Changes-only backup tests\n";

Sf_Test::run(
	'comparing with the full backup: unchanged by size and time (with a checksum), new, changed, gone',
	static function () {
		$base = array(
			'wp-content/a.txt'           => array( 10, 1000 ),
			'wp-content/b.txt'           => array( 20, 1000 ),
			'wp-content/c.txt'           => array( 30, 1000 ),
			'wp-content/gone.txt'        => array( 5, 1000 ),
			'wp-content/no-checksum.txt' => array( 7, 1000 ),
		);
		$sums = array(
			'wp-content/a.txt'    => str_repeat( 'a', 64 ),
			'wp-content/b.txt'    => str_repeat( 'b', 64 ),
			'wp-content/c.txt'    => str_repeat( 'c', 64 ),
			'wp-content/gone.txt' => str_repeat( 'd', 64 ),
		);
		$now  = array(
			'wp-content/a.txt'           => array( 10, 1000 ), // Unchanged.
			'wp-content/b.txt'           => array( 21, 1000 ), // Size changed.
			'wp-content/c.txt'           => array( 30, 2000 ), // Time changed.
			'wp-content/new.txt'         => array( 40, 3000 ), // New.
			'wp-content/no-checksum.txt' => array( 7, 1000 ),  // Same, but the full backup has no checksum for it.
		);
		$r = Incremental::compare( $base, $sums, $now );
		Sf_Test::same( array( 'wp-content/a.txt' => str_repeat( 'a', 64 ) ), $r['unchanged'], 'unchanged, with its checksum:' );
		Sf_Test::same( array( 'wp-content/gone.txt' ), $r['deleted'], 'gone:' );
		Sf_Test::same( 4, $r['changed'], 'stored again: changed size, changed time, new, no checksum:' );
		Sf_Test::same( 21 + 30 + 40 + 7, $r['changed_bytes'] );

		// Nothing in common: everything is stored, everything of the full backup is gone.
		$r = Incremental::compare( $base, $sums, array() );
		Sf_Test::same( array(), $r['unchanged'] );
		Sf_Test::same( 5, count( $r['deleted'] ) );
	}
);

Sf_Test::run(
	'the full backup: its files from the index, its checksum list and fingerprint read from the archive',
	static function () {
		$dir  = Sf_Test::temp_dir( 'incr' );
		$file = $dir . '/full.tar';
		$list = str_repeat( '1', 64 ) . "  wp-content/a.txt\n" . str_repeat( '2', 64 ) . "  database.sql\nnot a line\n";
		$w    = Tar_Writer::create( $file );
		$w->add_string( 'siteferry.json', "{}\n", 1700000000 );
		$w->add_string( 'wp-content/a.txt', 'hello', 1700000001 );
		$w->add_string( 'siteferry.sha256', $list, 1700000002 );
		$w->finish();
		$w->close();

		Sf_Test::same( $list, Incremental::read_entry( $file, 'siteferry.sha256' ), 'read back whole:' );
		Sf_Test::same( null, Incremental::read_entry( $file, 'nothing.here' ), 'a missing entry:' );
		list( $sums, $fingerprint ) = Incremental::checksums( $file );
		Sf_Test::same(
			array(
				'wp-content/a.txt' => str_repeat( '1', 64 ),
				'database.sql'     => str_repeat( '2', 64 ),
			),
			$sums,
			'only valid lines:'
		);
		Sf_Test::same( hash( 'sha256', $list ), $fingerprint, 'the fingerprint is the list\'s SHA-256:' );

		$files = Incremental::files_of(
			array(
				array( 'siteferry.json', 'f', 3, 1 ),
				array( 'wp-content', 'd', 0, 1 ),
				array( 'wp-content/a.txt', 'f', 5, 1700000001 ),
			)
		);
		Sf_Test::same( array( 'wp-content/a.txt' => array( 5, 1700000001 ) ), $files, 'wp-content files only:' );
	}
);

Sf_Test::run(
	'the full backup named in a manifest: read strictly; a full backup has none',
	static function () {
		Sf_Test::same( null, Incremental::base_of( array() ) );
		Sf_Test::same( null, Incremental::base_of( null ) );
		Sf_Test::same( null, Incremental::base_of( array( 'base' => array( 'name' => '../../wp-config.php' ) ) ), 'an unsafe name is no base:' );
		$base = Incremental::base_of(
			array(
				'base' => array(
					'name'        => 'site-2026-11-09-abcdef.tar',
					'created'     => '2026-11-09T10:00:00Z',
					'fingerprint' => str_repeat( 'f', 64 ),
					'files'       => '12',
				),
			)
		);
		Sf_Test::same( 'site-2026-11-09-abcdef.tar', $base['name'] );
		Sf_Test::same( 12, $base['files'] );
	}
);

Sf_Test::run(
	'retention: a full backup stays while kept changes-only backups are built on it',
	static function () {
		$runs = array(
			array( 'name' => 'full1', 'time' => 1, 'base' => '' ),
			array( 'name' => 'inc1a', 'time' => 2, 'base' => 'full1' ),
			array( 'name' => 'inc1b', 'time' => 3, 'base' => 'full1' ),
			array( 'name' => 'full2', 'time' => 4, 'base' => '' ),
			array( 'name' => 'inc2a', 'time' => 5, 'base' => 'full2' ),
		);
		list( $expired, $held ) = Incremental::expired( $runs, 2 );
		Sf_Test::same( array( 'full1', 'inc1a', 'inc1b' ), array_column( $expired, 'name' ), 'keeping 2 (inc2a, full2): the first set goes, oldest first:' );
		Sf_Test::same( array(), $held );

		list( $expired, $held ) = Incremental::expired( $runs, 1 );
		Sf_Test::same( array( 'full1', 'inc1a', 'inc1b' ), array_column( $expired, 'name' ), 'keeping 1 (inc2a): its full backup full2 is held:' );
		Sf_Test::same( array( 'full2' ), $held );

		list( $expired, $held ) = Incremental::expired( $runs, 3 );
		Sf_Test::same( array( 'inc1a' ), array_column( $expired, 'name' ), 'keeping 3 (inc2a, full2, inc1b): full1 is held for inc1b:' );
		Sf_Test::same( array( 'full1' ), $held );

		Sf_Test::same( array( array(), array() ), Incremental::expired( $runs, 0 ), '0 keeps them all:' );
	}
);

Sf_Test::run(
	'schedule: a full backup every N runs, and what it cannot be combined with',
	static function () {
		$ok = \SiteFerry\Schedule\Schedule::normalize( array( 'full_every' => 7, 'retain' => 14 ), array() );
		Sf_Test::same( 7, $ok['full_every'] );
		Sf_Test::same( 1, \SiteFerry\Schedule\Schedule::normalize( array(), array() )['full_every'], 'default: always full:' );
		$refused = array(
			'0 runs'           => array( 'full_every' => 0 ),
			'31 runs'          => array( 'full_every' => 31 ),
			'.wpress'          => array( 'full_every' => 3, 'format' => 'wpress' ),
			'password'         => array( 'full_every' => 3, 'encrypt' => true ),
			'no copy here'     => array( 'full_every' => 3, 'send_to' => 's3', 'keep_local' => false ),
			'keeps too few'    => array( 'full_every' => 5, 'retain' => 3 ),
		);
		foreach ( $refused as $what => $input ) {
			try {
				\SiteFerry\Schedule\Schedule::normalize( $input, array( 's3' ) );
				throw new RuntimeException( 'accepted: ' . $what );
			} catch ( \SiteFerry\Jobs\Job_Exception $e ) {
				Sf_Test::ok( '' !== $e->getMessage(), $what . ' refused' );
			}
		}
		Sf_Test::same( 5, \SiteFerry\Schedule\Schedule::normalize( array( 'full_every' => 5, 'retain' => 0 ), array() )['full_every'], 'keeping all is fine:' );
	}
);

exit( Sf_Test::finish( 'test-incremental' ) );
