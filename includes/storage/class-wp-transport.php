<?php
/**
 * HTTP through WordPress.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * Sends storage requests with the WordPress HTTP API (which honours the site's proxy settings and
 * CA bundle). Requests go only to the endpoint the administrator configured; redirects are not
 * followed, responses are not decompressed and the user agent does not name the site.
 */
final class Wp_Transport implements Transport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @throws Storage_Exception On network errors.
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 60 ): array {
		$response = wp_remote_request(
			$url,
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => '' === $body ? null : $body,
				'timeout'     => $timeout,
				'redirection' => 0,
				'decompress'  => false,
				'sslverify'   => true,
				'user-agent'  => 'SiteFerry/' . SITEFERRY_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Storage_Exception( sprintf( /* translators: %s: the reason */ __( 'Network error: %s', 'siteferry' ), $response->get_error_message() ), Storage_Exception::TRANSIENT );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
