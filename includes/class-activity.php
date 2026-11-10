<?php
/**
 * The Activity list as rows, for a CSV download and WP-CLI.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

use SiteFerry\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * One row per job (backup, restore, upload, download, check, staging): when (site time zone and
 * UTC), what kind, which backup, the result, how long it took, the size, where, who started it
 * and the message.
 *
 * CSV cells that a spreadsheet would read as a formula (starting with = + - @, a tab or a
 * carriage return) get a leading apostrophe; the file starts with a UTF-8 byte order mark so
 * Excel reads accents and emoji correctly.
 */
final class Activity {

	/** Column keys, in order. */
	const COLUMNS = array( 'time', 'time_utc', 'type', 'what', 'result', 'duration', 'size', 'where', 'started_by', 'message' );

	/**
	 * Column titles.
	 */
	public static function titles(): array {
		return array(
			__( 'Time', 'siteferry' ),
			__( 'Time (UTC)', 'siteferry' ),
			__( 'Type', 'siteferry' ),
			__( 'Backup', 'siteferry' ),
			__( 'Result', 'siteferry' ),
			__( 'Duration', 'siteferry' ),
			__( 'Size', 'siteferry' ),
			__( 'Where', 'siteferry' ),
			__( 'Started by', 'siteferry' ),
			__( 'Message', 'siteferry' ),
		);
	}

	/**
	 * Rows for the jobs on this site, newest first, optionally only since a date and of a type.
	 *
	 * @param string $since "YYYY-MM-DD" in the site time zone, or ''.
	 * @param string $type  Job type ("export", "import", ...), or ''.
	 * @return array<int,array<string,string>>
	 */
	public static function all( string $since = '', string $type = '' ): array {
		$dir = Security\Storage::existing_jobs_dir();
		if ( null === $dir ) {
			return array();
		}
		$tz   = wp_timezone();
		$from = self::since( $since, $tz );
		$rows = array();
		foreach ( Job::all( $dir ) as $job ) {
			if ( '' !== (string) $job->option( 'parent', '' ) ) {
				continue; // Part of another job (a restore's safety backup), shown with it.
			}
			$summary = $job->summary();
			if ( ( '' !== $type && $summary['type'] !== $type ) || ( null !== $from && (int) $summary['created'] < $from ) ) {
				continue;
			}
			$rows[] = self::row( $summary, self::places( $job ), $tz );
		}
		return $rows;
	}

	/**
	 * The start of a day in the site time zone, as a timestamp; null for none.
	 *
	 * @param string        $since "YYYY-MM-DD" or ''.
	 * @param \DateTimeZone $tz    Site time zone.
	 * @throws \InvalidArgumentException On a malformed date.
	 */
	public static function since( string $since, \DateTimeZone $tz ): ?int {
		if ( '' === $since ) {
			return null;
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $since, $tz );
		if ( false === $day || $day->format( 'Y-m-d' ) !== $since ) {
			throw new \InvalidArgumentException( 'Use a date such as 2026-11-01.' );
		}
		return $day->getTimestamp();
	}

	/**
	 * Where a job worked: this server and/or storages, from its options.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private static function places( Job $job ): array {
		$places = array();
		foreach ( array( 'send_to', 'source', 'place' ) as $key ) {
			$value = $job->option( $key, '' );
			if ( is_string( $value ) && '' !== $value ) {
				$places[] = $value;
			}
		}
		$also = $job->option( 'send_also', array() );
		foreach ( is_array( $also ) ? $also : array() as $value ) {
			$places[] = (string) $value;
		}
		if ( array() === $places && in_array( $job->type(), array( 'export', 'import', 'staging', 'staging-delete' ), true ) ) {
			$places[] = 'local';
		}
		return array_values( array_unique( $places ) );
	}

	/**
	 * One row from a job summary (Job::summary()) and the places it worked with.
	 *
	 * @param array         $summary Job summary.
	 * @param string[]      $places  Storage ids, or "local".
	 * @param \DateTimeZone $tz      Site time zone.
	 * @return array<string,string>
	 */
	public static function row( array $summary, array $places, \DateTimeZone $tz ): array {
		$created = (int) $summary['created'];
		$updated = (int) $summary['updated'];
		$result  = isset( $summary['result'] ) && is_array( $summary['result'] ) ? $summary['result'] : array();
		$size    = isset( $result['size'] ) ? (int) $result['size'] : ( isset( $result['checked']['size'] ) ? (int) $result['checked']['size'] : 0 );
		$names   = array_map(
			static function ( string $place ): string {
				return in_array( $place, array( '', 'local' ), true ) ? 'this server' : $place;
			},
			$places
		);
		$message = '';
		if ( ! empty( $summary['error'] ) ) {
			$message = (string) $summary['error'];
		} elseif ( isset( $summary['progress']['message'] ) ) {
			$message = (string) $summary['progress']['message'];
		}
		if ( ! empty( $summary['warnings']['count'] ) ) {
			$message .= ( '' === $message ? '' : ' ' ) . sprintf( '(%d warnings)', (int) $summary['warnings']['count'] );
		}
		$finished = in_array( $summary['status'], array( Job::STATUS_DONE, Job::STATUS_FAILED ), true );
		return array(
			'time'       => ( new \DateTimeImmutable( '@' . $created ) )->setTimezone( $tz )->format( 'Y-m-d H:i:s T' ),
			'time_utc'   => gmdate( 'Y-m-d\TH:i:s\Z', $created ),
			'type'       => (string) $summary['type'],
			'what'       => (string) ( isset( $summary['backup'] ) ? $summary['backup'] : '' ),
			'result'     => (string) $summary['status'],
			'duration'   => $finished ? self::duration( max( 0, $updated - $created ) ) : '',
			'size'       => $size > 0 ? (string) $size : '',
			'where'      => implode( ', ', $names ),
			'started_by' => self::by( $summary ),
			'message'    => trim( (string) preg_replace( '/\s+/', ' ', $message ) ),
		);
	}

	/**
	 * Who started a job: a user's login, "schedule", "WP-CLI", "automatic" or "" when not known.
	 *
	 * @param array $summary Job summary.
	 */
	private static function by( array $summary ): string {
		if ( isset( $summary['by'] ) && '' !== (string) $summary['by'] ) {
			return (string) $summary['by'];
		}
		if ( isset( $summary['origin'] ) && in_array( $summary['origin'], array( 'schedule', 'update' ), true ) ) {
			return 'schedule' === $summary['origin'] ? 'schedule' : 'automatic';
		}
		return '';
	}

	/**
	 * Seconds as "1:02:03" or "4:05".
	 *
	 * @param int $seconds Seconds.
	 */
	public static function duration( int $seconds ): string {
		$hours = intdiv( $seconds, 3600 );
		$rest  = $seconds % 3600;
		return $hours > 0 ? sprintf( '%d:%02d:%02d', $hours, intdiv( $rest, 60 ), $rest % 60 ) : sprintf( '%d:%02d', intdiv( $rest, 60 ), $rest % 60 );
	}

	/**
	 * The rows as CSV text: a byte order mark, the column titles, then one line per row.
	 *
	 * @param array<int,array<string,string>> $rows Rows.
	 */
	public static function csv( array $rows ): string {
		$lines = array( self::csv_line( self::titles() ) );
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( self::COLUMNS as $key ) {
				$cells[] = isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
			}
			$lines[] = self::csv_line( $cells );
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line: every cell quoted, quotes doubled, formula-looking cells defused.
	 *
	 * @param string[] $cells Cells.
	 */
	public static function csv_line( array $cells ): string {
		return implode(
			',',
			array_map(
				static function ( string $cell ): string {
					if ( '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ) {
						$cell = "'" . $cell;
					}
					return '"' . str_replace( '"', '""', $cell ) . '"';
				},
				$cells
			)
		);
	}
}
