<?php
/**
 * About how big a backup will be and how long it will take.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * Forecasts from measurements on this site, never from guesses about the server:
 *
 * - size: the uncompressed size the admin page works out from the space report; compressed, a
 *   range around the ratio of the last compressed backup (40 to 90 % before there is one);
 * - time to make it: size at the speed of the last backup of the same kind (compressed or not),
 *   in MB per second from start to finish; unknown until one was made;
 * - time to send it: size at the upload speed last measured to that storage; unknown until one
 *   was sent.
 *
 * Measurements are kept in one option (no personal data): per kind of backup and per storage the
 * last bytes and seconds. Ranges are wide on purpose: a forecast never blocks anything.
 */
final class Forecast {

	const OPTION = 'siteferry_speeds';

	/** Ranges around a measured speed (it varies with what else the server does). */
	const SLOWER = 1.4;
	const FASTER = 0.75;

	/** Compressed size as a share of the uncompressed size before any compressed backup was measured. */
	const RATIO_LOW  = 0.4;
	const RATIO_HIGH = 0.9;

	/** Measurements shorter than this say little (start-up dominates). */
	const MIN_SECONDS = 3.0;

	/**
	 * The forecast.
	 *
	 * @param int    $raw      Uncompressed size of what is backed up (bytes).
	 * @param array  $choices  compress (bool), send_to (storage id or '').
	 * @param array  $speeds   Saved measurements (speeds()).
	 * @return array{bytes:array{0:int,1:int},make:array{0:int,1:int}|null,send:array{0:int,1:int}|null,based_on:array}
	 *         Sizes in bytes and times in seconds, each as [low, high]; null when not measured yet.
	 */
	public static function estimate( int $raw, array $choices, array $speeds ): array {
		$raw      = max( 0, $raw );
		$compress = ! empty( $choices['compress'] );
		$based_on = array();
		$bytes    = array( $raw, $raw );
		if ( $compress ) {
			$ratio = isset( $speeds['ratio'] ) ? (float) $speeds['ratio'] : 0.0;
			if ( $ratio > 0.0 && $ratio <= 1.0 ) {
				$bytes      = array( (int) round( $raw * max( 0.05, $ratio * 0.8 ) ), (int) round( $raw * min( 1.0, $ratio * 1.2 ) ) );
				$based_on[] = 'ratio';
			} else {
				$bytes = array( (int) round( $raw * self::RATIO_LOW ), (int) round( $raw * self::RATIO_HIGH ) );
			}
		}

		$make = null;
		$kind = $compress ? 'backup_gz' : 'backup';
		$rate = self::rate( isset( $speeds[ $kind ] ) ? $speeds[ $kind ] : null );
		if ( null !== $rate ) {
			// The speed is of the uncompressed site: time follows what is read, not what is written.
			$make       = array( (int) round( $raw / $rate * self::FASTER ), (int) round( $raw / $rate * self::SLOWER ) );
			$based_on[] = $kind;
		}

		$send = null;
		$to   = isset( $choices['send_to'] ) ? (string) $choices['send_to'] : '';
		if ( '' !== $to ) {
			$rate = self::rate( isset( $speeds['up'][ $to ] ) ? $speeds['up'][ $to ] : null );
			if ( null !== $rate ) {
				$send       = array( (int) round( $bytes[0] / $rate * self::FASTER ), (int) round( $bytes[1] / $rate * self::SLOWER ) );
				$based_on[] = 'up:' . $to;
			}
		}
		return array(
			'bytes'    => $bytes,
			'make'     => $make,
			'send'     => $send,
			'based_on' => $based_on,
		);
	}

	/**
	 * Bytes per second of a measurement, or null when there is none worth using.
	 *
	 * @param mixed $measured bytes, seconds.
	 */
	public static function rate( $measured ): ?float {
		if ( ! is_array( $measured ) || ! isset( $measured['bytes'], $measured['seconds'] ) ) {
			return null;
		}
		$bytes   = (float) $measured['bytes'];
		$seconds = (float) $measured['seconds'];
		if ( $bytes <= 0.0 || $seconds < self::MIN_SECONDS ) {
			return null;
		}
		return $bytes / $seconds;
	}

	/**
	 * The saved measurements.
	 */
	public static function speeds(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Remembers how long a finished backup took.
	 *
	 * @param int   $raw        Uncompressed size of what it holds (bytes).
	 * @param int   $file       Size of the backup file (bytes).
	 * @param float $seconds    From start to finish.
	 * @param bool  $compressed Whether it was compressed.
	 */
	public static function record_backup( int $raw, int $file, float $seconds, bool $compressed ): void {
		if ( $raw <= 0 || $seconds < self::MIN_SECONDS ) {
			return;
		}
		$speeds                                       = self::speeds();
		$speeds[ $compressed ? 'backup_gz' : 'backup' ] = array(
			'bytes'   => $raw,
			'seconds' => round( $seconds, 1 ),
			'time'    => time(),
		);
		if ( $compressed && $file > 0 ) {
			$speeds['ratio'] = round( min( 1.0, $file / $raw ), 3 );
		}
		update_option( self::OPTION, $speeds, false );
	}

	/**
	 * Remembers how fast a storage took or gave a file.
	 *
	 * @param string $destination Storage id.
	 * @param string $direction   "up" or "down".
	 * @param int    $bytes       Bytes moved.
	 * @param float  $seconds     Time it took.
	 */
	public static function record_transfer( string $destination, string $direction, int $bytes, float $seconds ): void {
		if ( '' === $destination || ! in_array( $direction, array( 'up', 'down' ), true ) || $bytes <= 0 || $seconds < self::MIN_SECONDS ) {
			return;
		}
		$speeds                               = self::speeds();
		$speeds[ $direction ]                 = isset( $speeds[ $direction ] ) && is_array( $speeds[ $direction ] ) ? $speeds[ $direction ] : array();
		$speeds[ $direction ][ $destination ] = array(
			'bytes'   => $bytes,
			'seconds' => round( $seconds, 1 ),
			'time'    => time(),
		);
		update_option( self::OPTION, $speeds, false );
	}
}
