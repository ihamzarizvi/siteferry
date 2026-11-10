<?php
/**
 * Which scheduled backups to keep: the newest few, or the newest of each day, week and month.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Pure selection of the scheduled backups to keep; nothing is deleted here.
 *
 * "Daily, weekly and monthly" keeps the newest backup of each of the last D days, W weeks and M
 * months, counted back from now in the site's time zone: calendar days, ISO weeks (Monday to
 * Sunday) and calendar months. A backup can count for several (the newest of today is also the
 * newest of this week and month). Whatever the mode, a full backup that a kept changes-only
 * backup is built on is kept too, and the newest backup is always kept.
 */
final class Retention {

	/** Modes. */
	const NEWEST = 'newest';
	const PERIODS = 'periods';

	/**
	 * The runs to keep and to delete.
	 *
	 * @param array         $runs     Runs: name, time (Unix seconds), base ('' or the full backup's name), ...
	 * @param array         $settings retain_mode, retain, keep_daily, keep_weekly, keep_monthly.
	 * @param int           $now      Now (Unix seconds).
	 * @param \DateTimeZone $zone     The site's time zone.
	 * @return array{keep:array<string,string[]>,expired:array,held:string[]} keep: name => reasons
	 *         (newest, day, week, month, base); expired: runs to delete, oldest first; held: full
	 *         backups kept only because changes-only backups need them.
	 */
	public static function select( array $runs, array $settings, int $now, \DateTimeZone $zone ): array {
		$runs = Schedule::newest_first( $runs ); // Ties: the one recorded later is newer.
		$keep = array();
		$mode = isset( $settings['retain_mode'] ) ? (string) $settings['retain_mode'] : self::NEWEST;
		if ( self::PERIODS === $mode ) {
			$periods = array(
				'day'   => array( max( 0, (int) $settings['keep_daily'] ), 'Y-m-d', 'days' ),
				'week'  => array( max( 0, (int) $settings['keep_weekly'] ), 'o-\WW', 'weeks' ),
				'month' => array( max( 0, (int) $settings['keep_monthly'] ), 'Y-m', 'months' ),
			);
			$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
			foreach ( $periods as $reason => list( $count, $format, $unit ) ) {
				if ( $count <= 0 ) {
					continue;
				}
				// The labels of the last $count periods, this one first (months counted from the 1st,
				// so going back from the 31st never skips a short month).
				$wanted = array();
				$start  = 'month' === $reason ? $today->modify( 'first day of this month' ) : $today;
				for ( $i = 0; $i < $count; $i++ ) {
					$wanted[ $start->modify( '-' . $i . ' ' . $unit )->format( $format ) ] = true;
				}
				$seen = array();
				foreach ( $runs as $run ) {
					$label = ( new \DateTimeImmutable( '@' . (int) $run['time'] ) )->setTimezone( $zone )->format( $format );
					if ( isset( $wanted[ $label ] ) && ! isset( $seen[ $label ] ) ) {
						$seen[ $label ]                    = true;
						$keep[ (string) $run['name'] ][] = $reason; // The newest of that period (runs are newest first).
					}
				}
			}
		} else {
			$count = isset( $settings['retain'] ) ? (int) $settings['retain'] : 0;
			foreach ( $runs as $i => $run ) {
				if ( $count <= 0 || $i < $count ) {
					$keep[ (string) $run['name'] ][] = 'newest';
				}
			}
		}
		if ( array() !== $runs && ! isset( $keep[ (string) $runs[0]['name'] ] ) ) {
			$keep[ (string) $runs[0]['name'] ][] = 'newest'; // The backup just made always stays.
		}

		// Full backups that kept changes-only backups are built on.
		$held = array();
		foreach ( $runs as $run ) {
			$base = isset( $run['base'] ) ? (string) $run['base'] : '';
			if ( '' !== $base && isset( $keep[ (string) $run['name'] ] ) && ! isset( $keep[ $base ] ) ) {
				foreach ( $runs as $other ) {
					if ( (string) $other['name'] === $base ) {
						$keep[ $base ][] = 'base';
						$held[]          = $base;
						break;
					}
				}
			}
		}

		$expired = array();
		foreach ( array_reverse( $runs ) as $run ) {
			if ( ! isset( $keep[ (string) $run['name'] ] ) ) {
				$expired[] = $run;
			}
		}
		return array(
			'keep'    => $keep,
			'expired' => $expired,
			'held'    => array_values( array_unique( $held ) ),
		);
	}
}
