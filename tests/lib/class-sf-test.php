<?php
/**
 * Minimal test harness: named cases, assertions, temporary folders, exit code.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * Thrown by Sf_Test::skip().
 */
final class Sf_Skip extends Exception {
}

/**
 * Test helpers.
 */
final class Sf_Test {

	/**
	 * Passed cases.
	 *
	 * @var int
	 */
	private static $passed = 0;

	/**
	 * Skipped cases with reasons.
	 *
	 * @var string[]
	 */
	private static $skipped = array();

	/**
	 * Failed case names.
	 *
	 * @var string[]
	 */
	private static $failed = array();

	/**
	 * Temporary folders to remove at the end.
	 *
	 * @var string[]
	 */
	private static $temp = array();

	/**
	 * Runs one named case.
	 *
	 * @param string   $name     Case name.
	 * @param callable $callback Test body; throws on failure.
	 */
	public static function run( string $name, callable $callback ): void {
		try {
			$callback();
			++self::$passed;
			echo '  ok    ', $name, PHP_EOL;
		} catch ( Sf_Skip $e ) {
			self::$skipped[] = $name . ' (' . $e->getMessage() . ')';
			echo '  skip  ', $name, ': ', $e->getMessage(), PHP_EOL;
		} catch ( Throwable $e ) {
			self::$failed[] = $name;
			printf( "  FAIL  %s\n        %s: %s\n        at %s:%d\n", $name, get_class( $e ), $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
		}
	}

	/**
	 * Skips the current case.
	 *
	 * @param string $reason Why.
	 * @throws Sf_Skip Always.
	 */
	public static function skip( string $reason ): void {
		throw new Sf_Skip( $reason );
	}

	/**
	 * Asserts strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  Context.
	 * @throws RuntimeException On mismatch.
	 */
	public static function same( $expected, $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException( trim( $message . ' expected ' . self::show( $expected ) . ', got ' . self::show( $actual ) ) );
		}
	}

	/**
	 * Asserts a condition.
	 *
	 * @param bool   $condition Condition.
	 * @param string $message   What was expected.
	 * @throws RuntimeException When false.
	 */
	public static function ok( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	/**
	 * Asserts that a callback throws an Archive_Exception with the given code.
	 *
	 * @param int      $code     Expected Archive_Exception code.
	 * @param callable $callback Code that must throw.
	 * @param string   $message  Context.
	 * @return SiteFerry\Archive\Archive_Exception The exception.
	 * @throws RuntimeException If nothing or something else is thrown.
	 */
	public static function throws( int $code, callable $callback, string $message = '' ): SiteFerry\Archive\Archive_Exception {
		try {
			$callback();
		} catch ( SiteFerry\Archive\Archive_Exception $e ) {
			if ( $e->getCode() !== $code ) {
				throw new RuntimeException( trim( $message . ' expected exception code ' . $code . ', got ' . $e->getCode() . ': ' . $e->getMessage() ) );
			}
			return $e;
		}
		throw new RuntimeException( trim( $message . ' expected an Archive_Exception with code ' . $code . ', nothing was thrown' ) );
	}

	/**
	 * Creates an empty temporary folder under tests/tmp/.
	 *
	 * @param string $label Readable part of the name.
	 */
	public static function temp_dir( string $label ): string {
		$base = __DIR__ . '/../tmp';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0755, true );
		}
		$dir = realpath( $base ) . DIRECTORY_SEPARATOR . $label . '-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		self::$temp[] = $dir;
		return $dir;
	}

	/**
	 * Prints the summary, removes temporary folders (unless --keep) and returns the exit code.
	 *
	 * @param string $suite Suite name.
	 */
	public static function finish( string $suite ): int {
		global $argv;
		if ( ! in_array( '--keep', (array) $argv, true ) ) {
			foreach ( self::$temp as $dir ) {
				sf_remove_tree( $dir );
			}
		}
		$total = self::$passed + count( self::$failed );
		printf( "%s: %d/%d passed on PHP %s", $suite, self::$passed, $total, PHP_VERSION );
		echo array() === self::$skipped ? PHP_EOL : sprintf( ", %d skipped\n", count( self::$skipped ) );
		foreach ( self::$failed as $name ) {
			echo '  failed: ', $name, PHP_EOL;
		}
		foreach ( self::$skipped as $name ) {
			echo '  skipped: ', $name, PHP_EOL;
		}
		return array() === self::$failed ? 0 : 1;
	}

	/**
	 * Short printable form of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function show( $value ): string {
		if ( is_string( $value ) ) {
			$shown = preg_match( '//u', $value ) && ! preg_match( '/[\x00-\x08\x0e-\x1f]/', $value ) ? $value : 'hex:' . bin2hex( $value );
			return '"' . ( strlen( $shown ) > 200 ? substr( $shown, 0, 200 ) . '...' : $shown ) . '"';
		}
		return var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
	}
}
