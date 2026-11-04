<?php
/**
 * A transport that refuses every request.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Used to build a driver only for describe(), without decrypting secrets or making requests.
 */
final class Null_Transport implements Transport {

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @throws Storage_Exception Always.
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 60 ): array {
		throw new Storage_Exception( 'No requests are made here.', Storage_Exception::CONFIG );
	}
}
