<?php
/**
 * Record of file moves, for rollback.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only list of completed moves ("backup" = live item moved aside, "install" = staged item
 * moved into place). Each line is flushed as soon as the move succeeds, so a rollback after a
 * crash knows exactly what was done.
 */
final class Journal {

	/**
	 * Journal file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Lines written in this request.
	 *
	 * @var int
	 */
	private $count;

	/**
	 * Constructor.
	 *
	 * @param string $file Journal file (created if missing).
	 */
	public function __construct( string $file ) {
		$this->file  = $file;
		$this->count = count( self::read( $file ) );
	}

	/**
	 * Records one completed move.
	 *
	 * @param string $type "backup" or "install".
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 */
	public function add( string $type, string $from, string $to ): void {
		$handle = Io::open_or_fail( $this->file, 'ab' );
		Io::write_all( $handle, $type . "\t" . $from . "\t" . $to . "\n" );
		fflush( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		++$this->count;
	}

	/**
	 * Number of moves recorded.
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * All recorded moves, oldest first.
	 *
	 * @param string $file Journal file.
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public static function read( string $file ): array {
		$moves = array();
		$text  = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( explode( "\n", $text ) as $line ) {
			$parts = explode( "\t", $line );
			if ( 3 === count( $parts ) && in_array( $parts[0], array( 'backup', 'install' ), true ) ) {
				$moves[] = array( $parts[0], $parts[1], $parts[2] );
			}
		}
		return $moves;
	}
}
