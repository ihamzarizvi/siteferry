<?php
/**
 * Benchmark (not part of run-tests): how a stored backup is read back from FTPS, as a restore or a
 * check does it — 8 MB ranges in a row through one driver — counting the logins it takes and the
 * time. Against the fake FTPS server on 127.0.0.1, so it shows the protocol work, not network speed.
 *
 * Run: php -n -d extension_dir=... -d extension=openssl -d extension=ftp tests/bench-ftp-download.php [MB]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/fake-ftp.php';

use SiteFerry\Storage\Ftp_Driver;

$mb     = isset( $argv[1] ) ? max( 8, (int) $argv[1] ) : 200;
$size   = $mb * 1048576;
$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp-bench' ) );
mkdir( $server->root . '/backups/site', 0777, true );
$file = $server->root . '/backups/site/bench.tar';
$out  = fopen( $file, 'wb' );
$part = random_bytes( 1048576 );
for ( $i = 0; $i < $mb; $i++ ) {
	fwrite( $out, $part );
}
fclose( $out );

$normal = Ftp_Driver::normalize(
	array(
		'host'       => '127.0.0.1',
		'port'       => (string) $server->port,
		'encryption' => 'ftps',
		'username'   => $server->user,
		'password'   => $server->pass,
		'folder'     => 'backups/site',
	)
);
$driver = new Ftp_Driver( $normal['settings'], $server->pass );
$hash   = hash_init( 'sha256' );
$chunk  = 8388608;
$start  = microtime( true );
for ( $offset = 0; $offset < $size; $offset += $chunk ) {
	hash_update( $hash, $driver->download_range( 'bench.tar', $offset, min( $chunk, $size - $offset ) ) );
}
$seconds = microtime( true ) - $start;
unset( $driver );

$same   = hash_final( $hash ) === hash_file( 'sha256', $file );
$logins = substr_count( $server->commands(), 'USER ' );
printf( "%d MB in %.1f s (%.1f MB/s), %d login(s), content %s\n", $mb, $seconds, $mb / $seconds, $logins, $same ? 'identical' : 'DIFFERENT' );
exit( $same ? 0 : 1 );
