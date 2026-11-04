<?php
/**
 * Fake Google (OAuth and Drive) over HTTP, for WordPress and browser tests:
 *
 *     php -n -S 127.0.0.1:18769 tests/tools/fake-google-server.php
 *
 * The environment variable SITEFERRY_FAKE_GOOGLE_DIR names its data folder, which must contain
 * config.json with base (this server's URL), client_id, client_secret and optionally account and
 * page_size. faults.json in the same folder injects failures (see Fake_Google::$fail_next).
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
SiteFerry\Autoloader::register();
require dirname( __DIR__ ) . '/lib/fixtures.php';
require dirname( __DIR__ ) . '/lib/class-fake-google.php';

$sf_dir    = (string) getenv( 'SITEFERRY_FAKE_GOOGLE_DIR' );
$sf_config = is_file( $sf_dir . '/config.json' ) ? json_decode( (string) file_get_contents( $sf_dir . '/config.json' ), true ) : null;
if ( ! is_array( $sf_config ) ) {
	http_response_code( 500 );
	echo 'SITEFERRY_FAKE_GOOGLE_DIR/config.json is missing.';
	return true;
}

$sf_headers = array();
foreach ( getallheaders() as $sf_name => $sf_value ) {
	$sf_headers[ strtolower( $sf_name ) ] = $sf_value;
}
$sf_google   = new Fake_Google( $sf_dir . '/data', $sf_config );
$sf_response = $sf_google->handle( $_SERVER['REQUEST_METHOD'], 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], $sf_headers, (string) file_get_contents( 'php://input' ) );

foreach ( $sf_response['headers'] as $sf_name => $sf_value ) {
	header( $sf_name . ': ' . $sf_value );
}
header( 'Content-Length: ' . strlen( $sf_response['body'] ) );
// Last: header( 'Location: ...' ) would otherwise turn a 200 (Drive's upload session) into a 302.
http_response_code( $sf_response['status'] );
echo $sf_response['body'];
return true;
