<?php
/**
 * Retries with backoff.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Repeats an operation that failed for a temporary reason (network, throttling, server errors),
 * waiting a little longer each time.
 */
final class Retry {

	/** Seconds to wait before the 2nd, 3rd and 4th attempt (plus up to 25% jitter). */
	const DELAYS = array( 1, 3, 8 );

	/**
	 * Runs $attempt until it succeeds, fails permanently or runs out of attempts.
	 *
	 * @param callable      $attempt Operation; throws Storage_Exception on failure.
	 * @param callable|null $sleep   Receives the delay in seconds (float); default usleep.
	 * @return mixed What $attempt returns.
	 * @throws Storage_Exception The last error.
	 */
	public static function run( callable $attempt, ?callable $sleep = null ) {
		$tries = 0;
		for ( ;; ) {
			try {
				return $attempt();
			} catch ( Storage_Exception $e ) {
				if ( ! $e->retryable() || $tries >= count( self::DELAYS ) ) {
					throw $e;
				}
				$delay = self::DELAYS[ $tries ] * ( 1 + random_int( 0, 250 ) / 1000 );
				++$tries;
				if ( null === $sleep ) {
					usleep( (int) ( $delay * 1000000 ) );
				} else {
					$sleep( $delay );
				}
			}
		}
	}
}
