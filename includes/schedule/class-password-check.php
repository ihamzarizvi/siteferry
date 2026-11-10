<?php
/**
 * The automatic backups' password: advice to set one, a test, and a monthly reminder.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Schedule;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Copies sent to a storage leave this server; a password keeps whoever reaches the storage from
 * reading the database in them. This class:
 *
 * - advises turning the password on when scheduled backups go to a storage without one (per
 *   user, dismissible); a schedule set up for the first time has it ticked already (on the page);
 * - lets an administrator type the password to check they still know it: compared in constant
 *   time with the stored one, never shown, at most five tries in fifteen minutes per user;
 * - reminds once a month, when backups are protected, to test it (a successful test or "remind me
 *   later" waits another month).
 *
 * Changes-only backups cannot have a password yet: the schedule refuses that combination with a
 * clear message (full backups every run, or no password).
 */
final class Password_Check {

	const TRIES  = 5;
	const WINDOW = 900;
	const MONTH  = 2592000;

	const CHECKED_META   = 'siteferry_password_checked';
	const DISMISSED_META = 'siteferry_password_advice_dismissed';

	/**
	 * What the schedule panel shows about the password for a user.
	 *
	 * @param array $settings Schedule settings.
	 * @param int   $user     User id.
	 * @param int   $now      Time.
	 * @return array{advise:bool,remind:bool,new_setup:bool}
	 */
	public static function overview( array $settings, int $user, int $now ): array {
		$sends     = array() !== Schedule::targets_of( $settings );
		$protected = ! empty( $settings['encrypt'] ) && null !== Schedule::password();
		$checked   = (int) get_user_meta( $user, self::CHECKED_META, true );
		if ( $protected && 0 === $checked && $user > 0 ) {
			// The first time it is seen protected: start the month now rather than nagging at once.
			update_user_meta( $user, self::CHECKED_META, $now );
			$checked = $now;
		}
		return array(
			'advise'    => self::advise( ! empty( $settings['enabled'] ), $sends, ! empty( $settings['encrypt'] ), (int) get_user_meta( $user, self::DISMISSED_META, true ) > 0 ),
			'remind'    => self::remind( $protected, $checked, $now ),
			// No schedule saved yet: ticking a storage ticks the password too.
			'new_setup' => false === get_option( Schedule::OPTION ),
		);
	}

	/**
	 * Whether to advise a password: scheduled backups on, sent to a storage, without a password,
	 * and the advice not dismissed.
	 *
	 * @param bool $enabled   Schedule on.
	 * @param bool $sends     Sent to at least one storage.
	 * @param bool $encrypted Protected with a password.
	 * @param bool $dismissed The user dismissed the advice.
	 */
	public static function advise( bool $enabled, bool $sends, bool $encrypted, bool $dismissed ): bool {
		return $enabled && $sends && ! $encrypted && ! $dismissed;
	}

	/**
	 * Whether to remind: protected, and the last test (or "later") was a month ago or more.
	 *
	 * @param bool $protected Backups are protected and the password is stored.
	 * @param int  $checked   Last test or snooze.
	 * @param int  $now       Time.
	 */
	public static function remind( bool $protected, int $checked, int $now ): bool {
		return $protected && $checked > 0 && $now - $checked >= self::MONTH;
	}

	/**
	 * Whether a typed password is the stored one. Counts the try first; refuses after five in
	 * fifteen minutes.
	 *
	 * @param int    $user     User id.
	 * @param string $password Typed password.
	 * @throws Job_Exception When there is no stored password, or too many tries.
	 */
	public static function test(
		int $user,
		#[\SensitiveParameter]
		string $password
	): bool {
		$stored = Schedule::password();
		if ( null === $stored ) {
			throw new Job_Exception( __( 'No password is stored for the automatic backups.', 'siteferry' ), Job_Exception::STEP );
		}
		$key   = 'siteferry_pwtest_' . $user;
		$tries = get_transient( $key );
		$tries = is_array( $tries ) ? $tries : array( 'count' => 0, 'since' => time() );
		if ( ! self::allowed( (int) $tries['count'], (int) $tries['since'], time() ) ) {
			throw new Job_Exception( __( 'Too many tries. Wait fifteen minutes, then test again.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( time() - (int) $tries['since'] >= self::WINDOW ) {
			$tries = array( 'count' => 0, 'since' => time() );
		}
		++$tries['count'];
		set_transient( $key, $tries, self::WINDOW );
		$ok = self::same( $stored, $password );
		if ( $ok ) {
			delete_transient( $key );
			update_user_meta( $user, self::CHECKED_META, time() );
		}
		return $ok;
	}

	/**
	 * Whether another try is allowed.
	 *
	 * @param int $count Tries in the window.
	 * @param int $since Start of the window.
	 * @param int $now   Time.
	 */
	public static function allowed( int $count, int $since, int $now ): bool {
		return $now - $since >= self::WINDOW || $count < self::TRIES;
	}

	/**
	 * Constant-time comparison of two passwords (hashed first, so lengths do not leak).
	 *
	 * @param string $stored The stored password.
	 * @param string $typed  The typed one.
	 */
	public static function same(
		#[\SensitiveParameter]
		string $stored,
		#[\SensitiveParameter]
		string $typed
	): bool {
		return hash_equals( hash( 'sha256', $stored ), hash( 'sha256', $typed ) );
	}

	/**
	 * "Not now" for the advice (per user).
	 *
	 * @param int $user User id.
	 */
	public static function dismiss_advice( int $user ): void {
		update_user_meta( $user, self::DISMISSED_META, time() );
	}

	/**
	 * "Remind me next month" (per user).
	 *
	 * @param int $user User id.
	 */
	public static function snooze( int $user ): void {
		update_user_meta( $user, self::CHECKED_META, time() );
	}
}
