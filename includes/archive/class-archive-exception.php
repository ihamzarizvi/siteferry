<?php
/**
 * Exception thrown by the archive library.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Archive error. The code says what went wrong; messages are plain text and must be escaped for output.
 */
class Archive_Exception extends \RuntimeException {

	/** Reading or writing a file failed. */
	const IO = 1;

	/** The archive is damaged (bad header, bad checksum, bad gzip data). */
	const CORRUPT = 2;

	/** The archive ends early. */
	const TRUNCATED = 3;

	/** An entry name could escape the target folder or is not allowed. */
	const UNSAFE_PATH = 4;

	/** An entry type is not allowed (links, devices, FIFOs, sparse files). */
	const UNSUPPORTED = 5;

	/** The caller used the API in the wrong order or passed a bad saved state. */
	const BAD_STATE = 6;
}
