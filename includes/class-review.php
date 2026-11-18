<?php
/**
 * Asking for a review on WordPress.org, once, after SiteFerry has proved useful.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Counts successful backups and restores, and decides whether SiteFerry's page may ask for a
 * review: after a few backups or a first restore, until someone answers. "Rate SiteFerry" and
 * "Don't ask again" end the question for good; "Maybe later" asks again after 30 days. Nothing is
 * offered in return, and nothing is sent anywhere: the page only links to the review form.
 */
final class Review {

	/** Option: backups, restores, answer ('', 'rated', 'never'), later (when to ask again). */
	const OPTION = 'siteferry_review';

	/** Where a review is written. */
	const URL = 'https://wordpress.org/support/plugin/siteferry/reviews/#new-post';

	/** Backups made before asking (one restore is enough on its own). */
	const BACKUPS = 5;

	/** "Maybe later" waits this long. */
	const LATER = 30 * DAY_IN_SECONDS;

	/** Answers the page sends. */
	const ANSWERS = array( 'rated', 'never', 'later' );

	/**
	 * Counts one successful backup or restore.
	 *
	 * @param string $kind 'backups' or 'restores'.
	 */
	public static function count( string $kind ): void {
		$state = self::state();
		if ( '' !== $state['answer'] || ! in_array( $kind, array( 'backups', 'restores' ), true ) ) {
			return; // Answered already: nothing more to count.
		}
		++$state[ $kind ];
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Whether the page should ask now.
	 *
	 * @param array|null $state State (default: the stored one).
	 * @param int|null   $now   Time (default: now).
	 */
	public static function due( ?array $state = null, ?int $now = null ): bool {
		$state = null === $state ? self::state() : self::clean( $state );
		$now   = null === $now ? time() : $now;
		if ( '' !== $state['answer'] || $state['later'] > $now ) {
			return false;
		}
		return $state['backups'] >= self::BACKUPS || $state['restores'] >= 1;
	}

	/**
	 * Records the answer from the page.
	 *
	 * @param string $answer 'rated', 'never' or 'later'.
	 * @return bool Whether the answer was valid.
	 */
	public static function answer( string $answer ): bool {
		if ( ! in_array( $answer, self::ANSWERS, true ) ) {
			return false;
		}
		$state = self::state();
		if ( 'later' === $answer ) {
			$state['later'] = time() + self::LATER;
		} else {
			$state['answer'] = $answer;
		}
		update_option( self::OPTION, $state, false );
		return true;
	}

	/**
	 * The stored state.
	 */
	public static function state(): array {
		$saved = get_option( self::OPTION, array() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * A state with every key, in its type.
	 *
	 * @param array $state Raw state.
	 */
	private static function clean( array $state ): array {
		return array(
			'backups'  => isset( $state['backups'] ) ? max( 0, (int) $state['backups'] ) : 0,
			'restores' => isset( $state['restores'] ) ? max( 0, (int) $state['restores'] ) : 0,
			'answer'   => isset( $state['answer'] ) && in_array( $state['answer'], array( 'rated', 'never' ), true ) ? $state['answer'] : '',
			'later'    => isset( $state['later'] ) ? (int) $state['later'] : 0,
		);
	}
}
