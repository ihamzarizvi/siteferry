<?php
/**
 * How far a storage's connection test has got, for the admin page to show while it runs.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * The drivers name each check of their test (connecting, writing a test file, ...). While a
 * listener is set, every check that starts, ends or fails is handed to it as the whole list so
 * far; the REST API keeps that list in a short-lived transient, which the admin page reads every
 * half second while the test runs. Without a listener (WP-CLI, scheduled jobs) nothing happens.
 */
final class Test_Progress {

	/**
	 * Receives the progress, or null.
	 *
	 * @var callable|null
	 */
	private static $listener = null;

	/**
	 * Checks so far: label and state (running, done or failed).
	 *
	 * @var array<int,array{label:string,state:string}>
	 */
	private static $steps = array();

	/**
	 * How many checks the test makes.
	 *
	 * @var int
	 */
	private static $total = 0;

	/**
	 * Starts (or with null, stops) reporting to a listener.
	 *
	 * @param callable|null $listener Gets array{total:int,steps:array}.
	 */
	public static function listen( ?callable $listener ): void {
		self::$listener = $listener;
		self::$steps    = array();
		self::$total    = 0;
	}

	/**
	 * The number of checks the test makes (for the bar).
	 *
	 * @param int $total Checks.
	 */
	public static function total( int $total ): void {
		self::$total = $total;
		self::emit();
	}

	/**
	 * A check starts.
	 *
	 * @param string $label What it does, such as "Writing a test file".
	 */
	public static function step( string $label ): void {
		self::$steps[] = array(
			'label' => $label,
			'state' => 'running',
		);
		self::emit();
	}

	/**
	 * The running check ends: done (true) or failed (false).
	 *
	 * @param bool $ok Outcome.
	 */
	public static function end( bool $ok ): void {
		$last = count( self::$steps ) - 1;
		if ( $last >= 0 && 'running' === self::$steps[ $last ]['state'] ) {
			self::$steps[ $last ]['state'] = $ok ? 'done' : 'failed';
			self::emit();
		}
	}

	/**
	 * Runs one named check of a test, reporting it, and names it in the error when it fails.
	 *
	 * @param string   $label    What it does, such as "Writing a test file".
	 * @param callable $callback The check.
	 * @return mixed What the check returns.
	 * @throws Storage_Exception Prefixed with the check's name.
	 */
	public static function check( string $label, callable $callback ) {
		self::step( $label );
		try {
			$result = $callback();
		} catch ( Storage_Exception $e ) {
			self::end( false );
			throw new Storage_Exception(
				/* translators: 1: a step of the connection test, such as Writing a test file, 2: the reason */
				sprintf( __( '%1$s failed. %2$s', 'siteferry' ), $label, $e->getMessage() ),
				$e->getCode(),
				$e
			);
		}
		self::end( true );
		return $result;
	}

	/**
	 * Hands the list so far to the listener.
	 */
	private static function emit(): void {
		if ( null !== self::$listener ) {
			call_user_func(
				self::$listener,
				array(
					'total' => max( self::$total, count( self::$steps ) ),
					'steps' => self::$steps,
				)
			);
		}
	}
}
