<?php
/**
 * The 3-2-1 rule for scheduled backups.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * How well the copies of one backup follow the 3-2-1 rule: 3 copies, on 2 different kinds of
 * storage, 1 of them away from this server. Kinds: this server, cloud storage (S3-compatible,
 * Google Drive, Dropbox) and an FTP server. Works from the places a backup is in; reads nothing.
 */
final class Copies {

	/** Kinds of place. */
	const HERE  = 'server';
	const CLOUD = 'cloud';
	const FTP   = 'ftp';

	/**
	 * The kind of a place: "local" (this server) or a storage id.
	 *
	 * @param string $place "local" or a destination id (s3, ftp, gdrive, dropbox).
	 */
	public static function kind( string $place ): string {
		if ( 'local' === $place ) {
			return self::HERE;
		}
		return 'ftp' === $place ? self::FTP : self::CLOUD;
	}

	/**
	 * The meter for a backup in these places.
	 *
	 * @param string[] $places "local" and/or destination ids.
	 * @return array{copies:int,kinds:int,offsite:int,three:bool,two:bool,one:bool,ok:bool,missing:string[]}
	 *         missing: what would complete it: "copies", "kind", "offsite".
	 */
	public static function meter( array $places ): array {
		$places  = array_values( array_unique( array_filter( array_map( 'strval', $places ), 'strlen' ) ) );
		$kinds   = array_values( array_unique( array_map( array( self::class, 'kind' ), $places ) ) );
		$offsite = count(
			array_filter(
				$places,
				static function ( string $place ): bool {
					return 'local' !== $place;
				}
			)
		);
		$out     = array(
			'copies'  => count( $places ),
			'kinds'   => count( $kinds ),
			'offsite' => $offsite,
			'three'   => count( $places ) >= 3,
			'two'     => count( $kinds ) >= 2,
			'one'     => $offsite >= 1,
		);
		$missing = array();
		if ( ! $out['one'] ) {
			$missing[] = 'offsite';
		}
		if ( ! $out['two'] ) {
			$missing[] = 'kind';
		}
		if ( ! $out['three'] ) {
			$missing[] = 'copies';
		}
		$out['ok']      = array() === $missing;
		$out['missing'] = $missing;
		return $out;
	}

	/**
	 * The places a scheduled backup made with these settings would be in.
	 *
	 * @param array $settings Schedule settings.
	 * @return string[]
	 */
	public static function planned( array $settings ): array {
		$targets = Schedule::targets_of( $settings );
		$places  = array();
		if ( array() === $targets || ! empty( $settings['keep_local'] ) ) {
			$places[] = 'local';
		}
		return array_merge( $places, $targets );
	}

	/**
	 * The places the newest recorded scheduled backup is in, or null when there is none.
	 *
	 * @param array $runs Recorded runs (local, destination, destinations).
	 * @return string[]|null
	 */
	public static function newest( array $runs ): ?array {
		if ( array() === $runs ) {
			return null;
		}
		$run    = Schedule::newest_first( $runs )[0];
		$places = empty( $run['local'] ) ? array() : array( 'local' );
		return array_merge( $places, Schedule::places_of( $run ) );
	}
}
