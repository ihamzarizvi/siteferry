<?php
/**
 * REST request helper for the WordPress tests.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Dispatches a REST request inside WordPress.
 *
 * @param string      $method  HTTP method.
 * @param string      $route   Route under /siteferry/v1.
 * @param int         $user    User id (0 = anonymous).
 * @param array       $headers Headers.
 * @param array|null  $json    JSON body.
 * @param string|null $raw     Raw body.
 * @return array{0:int,1:mixed} Status and data.
 */
function sf_rest( string $method, string $route, int $user, array $headers = array(), ?array $json = null, ?string $raw = null ): array {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, '/siteferry/v1' . $route );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	if ( null !== $json ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $json ) );
	}
	if ( null !== $raw ) {
		$request->set_header( 'Content-Type', 'application/octet-stream' );
		$request->set_body( $raw );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

/**
 * Runs a job through the REST API with its token until it ends.
 *
 * @param string $id      Job id.
 * @param string $token   Job token.
 * @param float  $seconds Slice length to ask for.
 * @return array The final job summary.
 */
function sf_rest_drive( string $id, string $token, float $seconds = 15.0 ): array {
	$slices = 0;
	do {
		list( $status, $job ) = sf_rest( 'POST', "/jobs/$id/run", 0, array( 'X-SiteFerry-Job' => $token ), array( 'seconds' => $seconds ) );
		Sf_Test::same( 200, $status, 'run: ' . wp_json_encode( $job ) );
		++$slices;
	} while ( ! in_array( $job['status'], array( 'done', 'failed', 'waiting' ), true ) && $slices < 500 );
	return $job;
}
