<?php
/**
 * Exception for job handling.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Job error. Messages are plain text and must be escaped for output.
 */
class Job_Exception extends \RuntimeException {

	/** No job with that id. */
	const NOT_FOUND = 1;

	/** Another request is running a slice of this job. */
	const BUSY = 2;

	/** Saved job data is unreadable. */
	const INVALID = 3;

	/** A step refused to continue (bad options, not enough disk space, ...). */
	const STEP = 4;
}
