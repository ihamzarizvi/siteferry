<?php
/**
 * How much work one request (a "slice") may still do.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A time limit and/or byte limit for one slice of a job.
 *
 * Long operations check exhausted() between chunks and save their progress when it returns true.
 * They always do at least one chunk of work per call, so a slice never stalls.
 */
final class Slice_Budget {

	/**
	 * Unix time (with microseconds) at which the slice should stop, or null for no time limit.
	 *
	 * @var float|null
	 */
	private $deadline;

	/**
	 * Bytes the slice may still process, or null for no byte limit.
	 *
	 * @var int|null
	 */
	private $bytes_left;

	/**
	 * Constructor.
	 *
	 * @param float|null $seconds   Seconds from now, or null for no time limit.
	 * @param int|null   $max_bytes Bytes to process, or null for no byte limit.
	 */
	public function __construct( ?float $seconds = null, ?int $max_bytes = null ) {
		$this->deadline   = null === $seconds ? null : microtime( true ) + $seconds;
		$this->bytes_left = $max_bytes;
	}

	/**
	 * A budget that never runs out (WP-CLI).
	 */
	public static function unlimited(): self {
		return new self();
	}

	/**
	 * Records processed bytes.
	 *
	 * @param int $bytes Bytes just processed.
	 */
	public function consume( int $bytes ): void {
		if ( null !== $this->bytes_left ) {
			$this->bytes_left -= $bytes;
		}
	}

	/**
	 * Whether the slice should stop and save its progress.
	 */
	public function exhausted(): bool {
		if ( null !== $this->bytes_left && $this->bytes_left <= 0 ) {
			return true;
		}
		return null !== $this->deadline && microtime( true ) >= $this->deadline;
	}
}
