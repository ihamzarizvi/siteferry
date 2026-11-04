<?php
/**
 * Errors from remote storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A storage request failed. Messages are plain text for the admin and never contain secrets.
 */
class Storage_Exception extends \RuntimeException {

	/** The settings are incomplete or invalid. */
	const CONFIG = 1;

	/** The service refused the credentials or the permission. */
	const AUTH = 2;

	/** The file or bucket does not exist. */
	const NOT_FOUND = 3;

	/** Network trouble, timeouts, throttling or server errors: worth retrying. */
	const TRANSIENT = 4;

	/** Any other refusal or an unexpected answer. */
	const REMOTE = 5;

	/**
	 * Whether repeating the request may succeed.
	 */
	public function retryable(): bool {
		return self::TRANSIENT === $this->getCode();
	}
}
