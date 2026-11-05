<?php
/**
 * Fake Dropbox (OAuth and API v2) over HTTP, for WordPress and browser tests:
 *
 *     php -n -S 127.0.0.1:18773 tests/tools/fake-dropbox-server.php
 *
 * The environment variable SITEFERRY_FAKE_DROPBOX_DIR names its data folder, which must contain
 * config.json with base (this server's URL), app_key and optionally account, page_size and
 * redirect_uris. faults.json in the data folder injects failures (see Fake_Dropbox::$fail_next).
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
require dirname( __DIR__ ) . '/lib/class-fake-dropbox.php';

$sf_dir    = (string) getenv( 'SITEFERRY_FAKE_DROPBOX_DIR' );
$sf_config = is_file( $sf_dir . '/config.json' ) ? json_decode( (string) file_get_contents( $sf_dir . '/config.json' ), true ) : null;
if ( ! is_array( $sf_config ) ) {
	http_response_code( 500 );
	echo 'SITEFERRY_FAKE_DROPBOX_DIR/config.json is missing.';
	return true;
}

$sf_headers = array();
foreach ( getallheaders() as $sf_name => $sf_value ) {
	$sf_headers[ strtolower( $sf_name ) ] = $sf_value;
}
$sf_dropbox  = new Fake_Dropbox( $sf_dir . '/data', $sf_config );
$sf_response = $sf_dropbox->handle( $_SERVER['REQUEST_METHOD'], 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], $sf_headers, (string) file_get_contents( 'php://input' ) );

foreach ( $sf_response['headers'] as $sf_name => $sf_value ) {
	header( $sf_name . ': ' . $sf_value );
}
header( 'Content-Length: ' . strlen( $sf_response['body'] ) );
// Last: a Location header would otherwise change the status code PHP sends.
http_response_code( $sf_response['status'] );
echo $sf_response['body'];
return true;
