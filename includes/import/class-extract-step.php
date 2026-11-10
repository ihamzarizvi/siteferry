<?php
/**
 * Import step: unpack the backup into a staging folder.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Tar_Entry;
use SiteFerry\Archive\Tar_Extractor;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;
use SiteFerry\Wpress\Import_Step;
use SiteFerry\Wpress\Reader as Wpress_Reader;
use SiteFerry\Wpress\Wpress;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts into <job>/staging/ with Tar_Extractor (which refuses links, devices and unsafe paths)
 * and records the SHA-256 of every file in extracted.sha256 for the next step.
 *
 * Only the manifest, the dump, the checksum list and wp-content/... are accepted.
 */
final class Extract_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Archive_Exception On unsafe or damaged archives.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$state   = $job->state( 'extract' );
		$staging = self::staging( $job );
		if ( ! is_dir( $staging ) && ! mkdir( $staging, 0755, true ) && ! is_dir( $staging ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			throw new Archive_Exception( __( 'Cannot create the staging folder.', 'siteferry' ), Archive_Exception::IO );
		}

		// A changes-only backup: first its own entries, then the unchanged files from its full backup.
		$base   = isset( $job->state( 'inspect' )['base'] ) ? $job->state( 'inspect' )['base'] : null;
		$second = null !== $base && ! empty( $state['main_done'] );
		$key    = $second ? 'base_extractor' : 'extractor';
		$saved  = isset( $state[ $key ] ) ? $state[ $key ]['reader'] : null;
		$wpress = 'wpress' === $job->option( 'format' );
		if ( $second ) {
			$reader = Tar_Reader::open( (string) $base, $saved );
			$wanted = self::base_files( $staging );
			$skip   = static function ( string $path, Tar_Entry $entry ) use ( $job, $wanted ): bool {
				// Only files the changes-only backup takes from it (never its manifest, dump or folders).
				return ! $entry->is_file() || ! isset( $wanted[ $path ] ) || ! Import::wanted_path( $job, $path );
			};
		} else {
			$reader = $wpress
				? Wpress_Reader::open(
					$job->option( 'archive' ),
					$saved,
					array(
						'prefix' => 'wp-content/',
						'skip'   => array( Wpress::class, 'skipped' ),
					)
				)
				: Tar_Reader::open( \SiteFerry\Security\Archive_Keys::path( $job, (string) $job->option( 'archive' ) ), $saved );
			// A partial restore unpacks only what it restores.
			$skip = static function ( string $path ) use ( $job ): bool {
				return ! Import::wanted_path( $job, $path );
			};
		}
		$extractor = new Tar_Extractor(
			$reader,
			$staging,
			array(
				'allow' => array( self::class, 'allowed' ),
				'skip'  => $skip,
			),
			isset( $state[ $key ] ) ? $state[ $key ] : null
		);
		$sums      = Io::open_or_fail( $job->path( 'extracted.sha256' ), 'c+b' );
		$size      = isset( $state['sums_size'] ) ? (int) $state['sums_size'] : 0;
		$bytes     = isset( $state['bytes'] ) ? (int) $state['bytes'] : 0;

		try {
			if ( ! ftruncate( $sums, $size ) || 0 !== fseek( $sums, $size ) ) {
				throw new Archive_Exception( __( 'Cannot rewind the list of extracted files.', 'siteferry' ), Archive_Exception::IO );
			}
			$done = $extractor->run(
				$budget,
				static function ( array $entry ) use ( $sums, &$bytes ) {
					if ( 'file' === $entry['type'] ) {
						Io::write_all( $sums, ( null === $entry['sha256'] ? '-' : $entry['sha256'] ) . '  ' . $entry['path'] . "\n" );
						$bytes += $entry['size'];
					}
				}
			);
			fflush( $sums );
			$size = (int) ftell( $sums );
		} finally {
			fclose( $sums ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$reader->close();
		}

		$state[ $key ]      = $extractor->state();
		$state['sums_size'] = $size;
		$state['bytes']     = $bytes;
		if ( $done && null !== $base && ! $second ) {
			// The changes are unpacked; the full backup's unchanged files come next.
			$state['main_done'] = true;
			$done               = false;
			$job->log( 'info', sprintf( 'Changes unpacked; unpacking the unchanged files from %s.', basename( (string) $base ) ) );
		}
		$job->set_state( 'extract', $state );
		$inspect = $job->state( 'inspect' );
		$total   = $inspect['bytes'] + $inspect['db_size'];
		$job->set_progress( sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Unpacking: %1$s of %2$s', 'siteferry' ), size_format( $bytes, 1 ), size_format( $total, 1 ) ), $bytes, $total );
		if ( $done && $wpress ) {
			Import_Step::stage( $job, $staging, $job->path( 'extracted.sha256' ) );
		}
		if ( $done ) {
			$job->log( 'info', sprintf( 'Backup unpacked: %s.', size_format( $bytes, 1 ) ) );
		}
		return $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		Io::remove_tree( self::staging( $job ) );
		$job->delete_file( 'extracted.sha256' );
	}

	/**
	 * Staging folder of a job.
	 *
	 * @param Job $job The job.
	 */
	public static function staging( Job $job ): string {
		return $job->path( 'staging' );
	}

	/**
	 * The files a changes-only backup takes from its full backup (from its staged base list).
	 *
	 * @param string $staging Staging folder.
	 * @return array<string,bool>
	 * @throws Archive_Exception If the list is missing.
	 */
	private static function base_files( string $staging ): array {
		$file = $staging . '/' . Manifest::BASE_LIST;
		if ( ! is_file( $file ) ) {
			throw new Archive_Exception( __( 'The changes-only backup has no list of the files to take from its full backup.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		$wanted = array();
		foreach ( explode( "\n", (string) file_get_contents( $file ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$line = trim( $line, "\r" );
			if ( 0 === strpos( $line, 'wp-content/' ) ) {
				$wanted[ $line ] = true;
			}
		}
		return $wanted;
	}

	/**
	 * Which archive paths may be extracted.
	 *
	 * @param string    $path  Normalized path.
	 * @param Tar_Entry $entry Entry.
	 */
	public static function allowed( string $path, Tar_Entry $entry ): bool {
		if ( in_array( $path, array( Manifest::ENTRY, Manifest::DATABASE, Manifest::CHECKSUMS, Manifest::BASE_LIST ), true ) ) {
			return $entry->is_file();
		}
		return 'wp-content' === $path || 0 === strpos( $path, 'wp-content/' );
	}
}
