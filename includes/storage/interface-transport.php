<?php
/**
 * HTTP for storage drivers.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Sends one HTTP request. Drivers depend on this instead of WordPress, so the tests can run them
 * against an in-process fake service.
 */
interface Transport {

	/**
	 * Sends a request without following redirects.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Full URL.
	 * @param array<string,string> $headers Request headers (Host comes from the URL).
	 * @param string               $body    Request body.
	 * @param int                  $timeout Seconds.
	 * @return array{status:int,headers:array<string,string>,body:string} Header names in lower case.
	 * @throws Storage_Exception On network errors (TRANSIENT).
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 60 ): array;
}
