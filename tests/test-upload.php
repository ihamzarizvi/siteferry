<?php
/**
 * Chunked upload sessions.
 *
 * Run: php tests/test-upload.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Backups;
use SiteFerry\Import\Upload;
use SiteFerry\Jobs\Job_Exception;

echo "Upload tests\n";

$base    = Sf_Test::temp_dir( 'uploads' );
$backups = Sf_Test::temp_dir( 'backups' );

/**
 * Asserts a Job_Exception with a given code.
 *
 * @param int      $code     Expected code.
 * @param callable $callback Code that must throw.
 * @param string   $label    Context.
 */
function sf_expect_job_error( int $code, callable $callback, string $label ): void {
	try {
		$callback();
	} catch ( Job_Exception $e ) {
		Sf_Test::same( $code, $e->getCode(), $label . ' (' . $e->getMessage() . ')' );
		return;
	}
	throw new RuntimeException( $label . ': expected a Job_Exception' );
}

/**
 * A minimal SiteFerry backup (manifest only) as bytes.
 *
 * @param string $dir Work folder.
 */
function sf_tiny_backup( string $dir ): string {
	$file   = $dir . '/tiny-' . bin2hex( random_bytes( 3 ) ) . '.tar';
	$writer = Tar_Writer::create( $file );
	$writer->add_string( 'siteferry.json', json_encode( array( 'format' => 'siteferry-backup', 'format_version' => 1 ) ) );
	$writer->add_string( 'padding.bin', random_bytes( 3 * 1048576 ) );
	$writer->finish();
	$bytes = (string) file_get_contents( $file );
	unlink( $file );
	return $bytes;
}

Sf_Test::run(
	'names and sizes are checked when an upload starts',
	static function () use ( $base ) {
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $base ) { Upload::create( $base, 'evil.php', 10 ); }, 'php file' );
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $base ) { Upload::create( $base, 'site.zip', 10 ); }, 'zip file' );
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $base ) { Upload::create( $base, 'site.tar', 0 ); }, 'empty' );
		$upload = Upload::create( $base, '..\\..\\evil dir/My Site 2024.tar.gz', 10 );
		Sf_Test::same( 'My-Site-2024.tar.gz', $upload->to_array()['name'], 'name reduced to its safe base name:' );
		$upload->delete();
		sf_expect_job_error( Job_Exception::NOT_FOUND, static function () use ( $base ) { Upload::load( $base, '../../etc' ); }, 'bad id' );
	}
);

Sf_Test::run(
	'chunks are accepted only at the current end and only with a matching SHA-256',
	static function () use ( $base, $backups ) {
		$data   = sf_tiny_backup( $base );
		$size   = strlen( $data );
		$chunk  = 1048576;
		$upload = Upload::create( $base, 'mysite-2026-11-04-0123456789ab.tar', $size );
		$id     = $upload->to_array()['id'];

		Sf_Test::same( $chunk, $upload->append( 0, substr( $data, 0, $chunk ), hash( 'sha256', substr( $data, 0, $chunk ) ) ) );
		sf_expect_job_error( Job_Exception::BUSY, static function () use ( $upload, $data, $chunk ) { $upload->append( 0, substr( $data, 0, $chunk ), hash( 'sha256', substr( $data, 0, $chunk ) ) ); }, 'repeated chunk' );
		sf_expect_job_error( Job_Exception::BUSY, static function () use ( $upload, $data, $chunk ) { $upload->append( 2 * $chunk, substr( $data, 2 * $chunk, $chunk ), hash( 'sha256', substr( $data, 2 * $chunk, $chunk ) ) ); }, 'gap' );
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $upload, $data, $chunk ) { $upload->append( $chunk, substr( $data, $chunk, $chunk ), str_repeat( '0', 64 ) ); }, 'bad hash' );

		// A request that died after writing but before saving leaves junk; the next chunk cuts it off.
		file_put_contents( $base . '/' . $id . '/data.part', random_bytes( 777 ), FILE_APPEND );
		$reloaded = Upload::load( $base, $id );
		Sf_Test::same( $chunk, $reloaded->to_array()['offset'], 'offset survives a reload:' );
		$offset = $chunk;
		while ( $offset < $size ) {
			$part   = substr( $data, $offset, $chunk );
			$offset = $reloaded->append( $offset, $part, hash( 'sha256', $part ) );
		}
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $reloaded, $size ) { $reloaded->append( $size, 'x', hash( 'sha256', 'x' ) ); }, 'past the end' );

		$name = $reloaded->finish( $backups );
		Sf_Test::same( 'mysite-2026-11-04-0123456789ab.tar', $name, 'a SiteFerry-style name is kept:' );
		Sf_Test::same( hash( 'sha256', $data ), hash_file( 'sha256', $backups . '/' . $name ), 'uploaded bytes:' );
		Sf_Test::ok( ! is_dir( $base . '/' . $id ), 'session removed after finishing' );
	}
);

Sf_Test::run(
	'other names get an unguessable one; non-backups and incomplete uploads are refused',
	static function () use ( $base, $backups ) {
		$data   = sf_tiny_backup( $base );
		$upload = Upload::create( $base, 'Copy of backup.tar', strlen( $data ) );
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $upload, $backups ) { $upload->finish( $backups ); }, 'incomplete' );
		$upload->append( 0, $data, hash( 'sha256', $data ) );
		$name = $upload->finish( $backups );
		Sf_Test::ok( (bool) preg_match( Backups::NAME_PATTERN, $name ), 'renamed to the backup pattern: ' . $name );
		Sf_Test::ok( 0 === strpos( $name, 'copy-of-backup-' ), $name );

		$junk  = str_repeat( 'not a tar ', 200 );
		$fake  = Upload::create( $base, 'fake.tar', strlen( $junk ) );
		$fake->append( 0, $junk, hash( 'sha256', $junk ) );
		$fake_id = $fake->to_array()['id'];
		sf_expect_job_error( Job_Exception::STEP, static function () use ( $fake, $backups ) { $fake->finish( $backups ); }, 'not a backup' );
		Sf_Test::ok( ! is_dir( $base . '/' . $fake_id ), 'rejected upload removed' );
	}
);

Sf_Test::run(
	'a second copy of a SiteFerry backup keeps its site and date, with a new random part',
	static function () use ( $base, $backups ) {
		$name = 'copytest-2026-10-31-0123456789ab.tar';
		foreach ( array( 1, 2 ) as $round ) {
			$file = $base . '/incoming-' . $round . '.tar';
			file_put_contents( $file, sf_tiny_backup( $base ) );
			$kept = Backups::adopt( $file, $name, $backups );
			if ( 1 === $round ) {
				Sf_Test::same( $name, $kept, 'first copy:' );
			} else {
				Sf_Test::ok( $kept !== $name && (bool) preg_match( '/^copytest-2026-10-31-[a-f0-9]{12}\.tar$/', $kept ), 'second copy: ' . $kept );
			}
		}
	}
);

Sf_Test::run(
	'backup file names are looked up safely',
	static function () use ( $backups ) {
		foreach ( array( '../x.tar', 'a/b.tar', '..\\x.tar', '.htaccess', 'x.php', 'x.tar.php', '', 'missing-2026-01-01-0123456789ab.tar' ) as $name ) {
			sf_expect_job_error( Job_Exception::NOT_FOUND, static function () use ( $backups, $name ) { Backups::path( $backups, $name ); }, json_encode( $name ) );
		}
	}
);

exit( Sf_Test::finish( 'test-upload' ) );
