<?php
/**
 * Front controller of the SiteFerry sign-in relay. Point the relay's web root at this folder;
 * config.php sits one level up, outside the web root (or set SITEFERRY_RELAY_CONFIG).
 *
 * @package SiteFerry_Relay
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require dirname( __DIR__ ) . '/src/class-relay.php';

$siteferry_config_file = getenv( 'SITEFERRY_RELAY_CONFIG' );
$siteferry_config_file = is_string( $siteferry_config_file ) && '' !== $siteferry_config_file ? $siteferry_config_file : dirname( __DIR__ ) . '/config.php';
$siteferry_config      = is_file( $siteferry_config_file ) ? require $siteferry_config_file : null;

$siteferry_relay    = new SiteFerry_Relay\Relay( is_array( $siteferry_config ) ? $siteferry_config : array(), array( SiteFerry_Relay\Relay::class, 'http_post' ) );
$siteferry_response = $siteferry_relay->handle(
	isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET',
	(string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH ),
	$_GET,
	(string) file_get_contents( 'php://input', false, null, 0, SiteFerry_Relay\Relay::MAX_BODY + 1 )
);

foreach ( $siteferry_response['headers'] as $siteferry_name => $siteferry_value ) {
	header( $siteferry_name . ': ' . $siteferry_value );
}
// Last: a Location header would otherwise change the status code PHP sends.
http_response_code( $siteferry_response['status'] );
echo $siteferry_response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text or JSON built above.
return true;
