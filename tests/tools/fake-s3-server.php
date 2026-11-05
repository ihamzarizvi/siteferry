<?php
/**
 * Fake S3 service over HTTP, for WordPress and browser tests:
 *
 *     php -n -S 127.0.0.1:18765 tests/tools/fake-s3-server.php
 *
 * The environment variable SITEFERRY_FAKE_S3_DIR names its data folder, which must contain
 * config.json with access_key, secret_key and optionally region, page_size and buckets.
 * Listens only where "php -S" is told to (use 127.0.0.1). Test data only.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( 'cli-server' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
require dirname( __DIR__ ) . '/lib/i18n-stub.php';
SiteFerry\Autoloader::register();
require dirname( __DIR__ ) . '/lib/fixtures.php';
require dirname( __DIR__ ) . '/lib/class-fake-s3.php';

$sf_dir    = (string) getenv( 'SITEFERRY_FAKE_S3_DIR' );
$sf_config = is_file( $sf_dir . '/config.json' ) ? json_decode( (string) file_get_contents( $sf_dir . '/config.json' ), true ) : null;
if ( ! is_array( $sf_config ) ) {
	http_response_code( 500 );
	echo 'SITEFERRY_FAKE_S3_DIR/config.json is missing.';
	return true;
}

// Fault injection that survives between requests: a list of status codes in faults.json.
$sf_s3        = new Fake_S3( $sf_dir . '/data', $sf_config );
$sf_faults    = $sf_dir . '/faults.json';
$sf_fail_next = is_file( $sf_faults ) ? (array) json_decode( (string) file_get_contents( $sf_faults ), true ) : array();
if ( array() !== $sf_fail_next ) {
	$sf_s3->fail_next = array( (int) array_shift( $sf_fail_next ) );
	file_put_contents( $sf_faults, json_encode( $sf_fail_next ) );
}

$sf_headers = array();
foreach ( getallheaders() as $sf_name => $sf_value ) {
	$sf_headers[ strtolower( $sf_name ) ] = $sf_value;
}
$sf_response = $sf_s3->handle( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $sf_headers, (string) file_get_contents( 'php://input' ) );

http_response_code( $sf_response['status'] );
foreach ( $sf_response['headers'] as $sf_name => $sf_value ) {
	header( $sf_name . ': ' . $sf_value );
}
header( 'Content-Length: ' . strlen( $sf_response['body'] ) );
echo $sf_response['body'];
return true;
