<?php
/**
 * Checking a copy of a backup, the parts that need no WordPress: an intact .tar, .tar.gz and
 * .wpress (also read in one-chunk slices), and every kind of damage named: a changed byte, a cut
 * file, a file missing from the archive, an incomplete dump, a dump of the wrong size, a missing
 * manifest or checksum list, and entries an import refuses. The outcome is recorded per place.
 *
 * Run: php tests/test-check.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Check\Check;
use SiteFerry\Database\Dumper;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Wpress\Writer;

echo "Check tests\n";

$dir = Sf_Test::temp_dir( 'check' );

// The few WordPress pieces the code under test touches.
$GLOBALS['sf_options'] = array();

/**
 * Stand-in for get_option().
 *
 * @param string $name    Option.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['sf_options'] ) ? $GLOBALS['sf_options'][ $name ] : $default;
}

/**
 * Stand-in for update_option().
 *
 * @param string $name  Option.
 * @param mixed  $value Value.
 */
function update_option( $name, $value ) {
	$GLOBALS['sf_options'][ $name ] = $value;
	return true;
}

/**
 * Stand-in for size_format().
 *
 * @param int $bytes Bytes.
 */
function size_format( $bytes ) {
	return $bytes . ' B';
}

/**
 * Writes a backup laid out like a SiteFerry one.
 *
 * @param string $file    Target.
 * @param array  $options Changes: compress, no_marker, db_size (in the manifest), no_manifest,
 *                        no_sums, listed (extra checksum lines: path => hash), extra (entries
 *                        an import refuses: name => data).
 */
function sf_make( string $file, array $options = array() ): void {
	$files = array(
		'plugins/a/a.php'          => str_repeat( "<?php // a\n", 400 ),
		'plugins/a/readme.txt'     => 'readme',
		'uploads/2026/11/big.bin'  => str_repeat( random_bytes( 1024 ), 3000 ),
		'themes/t/style.css'       => '/* style */',
		'index.php'                => '<?php',
	);
	$dump  = "-- dump\nCREATE TABLE `wp_x` (`a` int);\n" . ( empty( $options['no_marker'] ) ? Dumper::END_MARKER . "\n" : '' );
	$sums  = hash( 'sha256', $dump ) . "  database.sql\n";
	foreach ( $files as $path => $data ) {
		$sums .= hash( 'sha256', $data ) . '  wp-content/' . $path . "\n";
	}
	foreach ( isset( $options['listed'] ) ? $options['listed'] : array() as $path => $hash ) {
		$sums .= $hash . '  ' . $path . "\n";
	}
	$manifest = array(
		'format'   => 'siteferry-backup',
		'database' => array( 'size' => isset( $options['db_size'] ) ? $options['db_size'] : strlen( $dump ) ),
		'files'    => array( 'files' => count( $files ) ),
	);

	$writer = Tar_Writer::create( $file, empty( $options['compress'] ) ? array() : array( 'compress' => true, 'block_size' => 65536 ) );
	if ( empty( $options['no_manifest'] ) ) {
		$writer->add_string( 'siteferry.json', json_encode( $manifest ) . "\n", 1700000000 );
	}
	$writer->add_string( 'database.sql', $dump, 1700000000 );
	$writer->add_directory( 'wp-content/', 1700000000 );
	foreach ( $files as $path => $data ) {
		$writer->add_string( 'wp-content/' . $path, $data, 1700000000 );
	}
	foreach ( isset( $options['extra'] ) ? $options['extra'] : array() as $name => $data ) {
		$writer->add_string( $name, $data, 1700000000 );
	}
	if ( empty( $options['no_sums'] ) ) {
		$writer->add_string( 'siteferry.sha256', $sums, 1700000000 );
	}
	$writer->finish();
	$writer->close();
}

/**
 * Checks a file the way the job runs: slice after slice, the job reloaded in between.
 *
 * @param string     $base    Jobs folder.
 * @param string     $file    Backup.
 * @param float|null $seconds Slice length (0.0: one chunk or entry per slice), null for one pass.
 * @return array{0:Job,1:int} The finished job and the number of slices.
 */
function sf_check( string $base, string $file, ?float $seconds = null ): array {
	list( $job ) = Job::create(
		$base,
		'check',
		Check::LOCAL_STEPS,
		array(
			'name'  => basename( $file ),
			'place' => Check::LOCAL,
			'file'  => $file,
			'size'  => filesize( $file ),
		)
	);
	$slices = 0;
	while ( ! $job->finished() && $slices < 2000 ) {
		Runner::run_slice( $job, null === $seconds ? Slice_Budget::unlimited() : new Slice_Budget( $seconds ) );
		$job = Job::load( $base, $job->id() );
		++$slices;
	}
	return array( $job, $slices );
}

$base = $dir . '/jobs';
mkdir( $base );

Sf_Test::run(
	'an intact .tar: every file matches, the outcome is recorded, the working files are gone',
	static function () use ( $dir, $base ) {
		$file = $dir . '/good-2026-11-05-aaaaaaaaaaaa.tar';
		sf_make( $file );
		list( $job ) = sf_check( $base, $file );
		Sf_Test::same( Job::STATUS_DONE, $job->status(), 'status (' . $job->error() . '):' );
		$checked = $job->result()['checked'];
		Sf_Test::same( true, $checked['ok'] );
		Sf_Test::same( 'this server', $checked['where'] );
		Sf_Test::same( 'All 6 files match their checksums; the database dump is complete.', $checked['summary'] );
		$outcome = Check::all()[ basename( $file ) ][ Check::LOCAL ];
		Sf_Test::same( true, $outcome['ok'] );
		Sf_Test::same( filesize( $file ), $outcome['size'] );
		Sf_Test::same( array( 'job.json', 'job.log', 'lock' ), array_values( array_diff( scandir( $job->dir() ), array( '.', '..' ) ) ), 'left in the job folder:' );
		Sf_Test::ok( is_file( $file ), 'the backup itself is untouched' );
	}
);

Sf_Test::run(
	'a .tar.gz in one-chunk slices: a file cut by a slice continues where it stopped',
	static function () use ( $dir, $base ) {
		$file = $dir . '/good-2026-11-05-bbbbbbbbbbbb.tar.gz';
		sf_make( $file, array( 'compress' => true ) );
		list( $job, $slices ) = sf_check( $base, $file, 0.0 );
		Sf_Test::same( Job::STATUS_DONE, $job->status(), 'status (' . $job->error() . '):' );
		Sf_Test::ok( $slices > 10, 'in many slices (' . $slices . ')' );
		Sf_Test::same( true, $job->result()['checked']['ok'] );
		Sf_Test::same( 1, $job->progress()['done'] / max( 1, $job->progress()['total'] ), 'progress at the end:' );
	}
);

Sf_Test::run(
	'a changed byte, a cut file and a missing file are named',
	static function () use ( $dir, $base ) {
		$file = $dir . '/byte-2026-11-05-cccccccccccc.tar';
		sf_make( $file );
		$data = (string) file_get_contents( $file );
		$at   = strpos( $data, '/* style */' );
		$data[ $at + 3 ] = 'X';
		file_put_contents( $file, $data );
		list( $job ) = sf_check( $base, $file, 0.0 );
		Sf_Test::same( Job::STATUS_FAILED, $job->status() );
		Sf_Test::same( 'The copy on this server is damaged: 1 files do not match their checksums: wp-content/themes/t/style.css.', $job->error() );
		Sf_Test::same( false, Check::all()[ basename( $file ) ][ Check::LOCAL ]['ok'] );
		Sf_Test::same( array( 'job.json', 'job.log', 'lock' ), array_values( array_diff( scandir( $job->dir() ), array( '.', '..' ) ) ), 'left in the job folder:' );

		$cut = $dir . '/cut-2026-11-05-dddddddddddd.tar';
		sf_make( $cut );
		$data = (string) file_get_contents( $cut );
		file_put_contents( $cut, substr( $data, 0, (int) ( strlen( $data ) * 0.6 ) ) );
		list( $job ) = sf_check( $base, $cut );
		Sf_Test::same( Job::STATUS_FAILED, $job->status() );
		Sf_Test::ok( 0 === strpos( (string) $job->error(), 'The copy on this server is damaged: it cannot be read to its end:' ), 'cut: ' . $job->error() );

		$missing = $dir . '/missing-2026-11-05-eeeeeeeeeeee.tar';
		sf_make( $missing, array( 'listed' => array( 'wp-content/plugins/gone.php' => str_repeat( 'a', 64 ) ) ) );
		list( $job ) = sf_check( $base, $missing );
		Sf_Test::same( 'The copy on this server is damaged: 1 files of its checksum list are missing: wp-content/plugins/gone.php.', $job->error() );
	}
);

Sf_Test::run(
	'the dump, the manifest, the checksum list and refused entries',
	static function () use ( $dir, $base ) {
		$cases = array(
			'no-marker'   => array( array( 'no_marker' => true ), 'its database dump is incomplete' ),
			'db-size'     => array( array( 'db_size' => 5 ), 'its database dump has ' ),
			'no-manifest' => array( array( 'no_manifest' => true ), 'its manifest (siteferry.json) is missing or damaged' ),
			'no-sums'     => array( array( 'no_sums' => true ), 'its checksum list (siteferry.sha256) is missing or damaged' ),
			'refused'     => array( array( 'extra' => array( 'wp-config.php' => '<?php' ) ), 'it holds entries an import refuses: wp-config.php' ),
		);
		$i = 0;
		foreach ( $cases as $label => list( $options, $expected ) ) {
			$file = $dir . '/' . $label . '-2026-11-05-' . str_repeat( dechex( $i++ ), 12 ) . '.tar';
			sf_make( $file, $options );
			list( $job ) = sf_check( $base, $file );
			Sf_Test::same( Job::STATUS_FAILED, $job->status(), $label . ':' );
			Sf_Test::ok( false !== strpos( (string) $job->error(), $expected ), $label . ': ' . $job->error() );
		}
	}
);

Sf_Test::run(
	'a .wpress is read to its end (it has no checksums); a cut one is damaged',
	static function () use ( $dir, $base ) {
		$file   = $dir . '/site-2026-11-05-ffffffffffff.wpress';
		$writer = Writer::create( $file );
		$writer->add_string( 'package.json', '.', '{"SiteURL":"http://x"}', 1700000000 );
		$writer->add_string( 'database.sql', '.', "-- dump\n", 1700000000 );
		$writer->add_string( 'big.bin', 'uploads/2026', str_repeat( 'w', 3000000 ), 1700000000 );
		$writer->seal();
		$writer->close();
		list( $job, $slices ) = sf_check( $base, $file, 0.0 );
		Sf_Test::same( Job::STATUS_DONE, $job->status(), 'status (' . $job->error() . '):' );
		Sf_Test::ok( $slices > 3, 'in slices (' . $slices . ')' );
		Sf_Test::same( 'Read to its end: 3 files. A .wpress file has no checksums to compare.', $job->result()['checked']['summary'] );

		$data = (string) file_get_contents( $file );
		file_put_contents( $file, substr( $data, 0, 2000000 ) );
		list( $job ) = sf_check( $base, $file );
		Sf_Test::same( Job::STATUS_FAILED, $job->status() );
		Sf_Test::ok( 0 === strpos( (string) $job->error(), 'The copy on this server is damaged: it cannot be read to its end:' ), 'cut: ' . $job->error() );
	}
);

Sf_Test::run(
	'outcomes: one per place, newest kept, forgotten per place or for the whole backup',
	static function () {
		$GLOBALS['sf_options'] = array();
		Check::record( 'a.tar', 'local', true, 10, 'fine' );
		Check::record( 'a.tar', 'dropbox', false, 9, 'damaged' );
		Check::record( 'b.tar', 'local', true, 5, 'fine' );
		Sf_Test::same( array( 'b.tar', 'a.tar' ), array_keys( Check::all() ), 'newest first:' );
		Sf_Test::same( array( 'local', 'dropbox' ), array_keys( Check::all()['a.tar'] ) );
		Check::forget( 'a.tar', 'dropbox' );
		Sf_Test::same( array( 'local' ), array_keys( Check::all()['a.tar'] ) );
		Check::forget( 'a.tar' );
		Sf_Test::same( array( 'b.tar' ), array_keys( Check::all() ) );
		for ( $i = 0; $i < Check::KEEP + 5; $i++ ) {
			Check::record( 'n' . $i . '.tar', 'local', true, 1, 'fine' );
		}
		Sf_Test::same( Check::KEEP, count( Check::all() ), 'at most this many backups:' );
	}
);

exit( Sf_Test::finish( 'test-check' ) );
