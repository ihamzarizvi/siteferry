<?php
/**
 * Import step: unpack the backup into a staging folder.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

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
			throw new Archive_Exception( 'Cannot create the staging folder.', Archive_Exception::IO );
		}

		$saved     = isset( $state['extractor'] ) ? $state['extractor']['reader'] : null;
		$wpress    = 'wpress' === $job->option( 'format' );
		$reader    = $wpress
			? Wpress_Reader::open(
				$job->option( 'archive' ),
				$saved,
				array(
					'prefix' => 'wp-content/',
					'skip'   => array( Wpress::class, 'skipped' ),
				)
			)
			: Tar_Reader::open( $job->option( 'archive' ), $saved );
		$extractor = new Tar_Extractor(
			$reader,
			$staging,
			array(
				'allow' => array( self::class, 'allowed' ),
				// A partial restore unpacks only what it restores.
				'skip'  => static function ( string $path ) use ( $job ): bool {
					return ! Import::wanted_path( $job, $path );
				},
			),
			isset( $state['extractor'] ) ? $state['extractor'] : null
		);
		$sums      = Io::open_or_fail( $job->path( 'extracted.sha256' ), 'c+b' );
		$size      = isset( $state['sums_size'] ) ? (int) $state['sums_size'] : 0;
		$bytes     = isset( $state['bytes'] ) ? (int) $state['bytes'] : 0;

		try {
			if ( ! ftruncate( $sums, $size ) || 0 !== fseek( $sums, $size ) ) {
				throw new Archive_Exception( 'Cannot rewind the list of extracted files.', Archive_Exception::IO );
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

		$job->set_state(
			'extract',
			array(
				'extractor' => $extractor->state(),
				'sums_size' => $size,
				'bytes'     => $bytes,
			)
		);
		$inspect = $job->state( 'inspect' );
		$total   = $inspect['bytes'] + $inspect['db_size'];
		$job->set_progress( sprintf( 'Unpacking: %s of %s', size_format( $bytes, 1 ), size_format( $total, 1 ) ), $bytes, $total );
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
	 * Which archive paths may be extracted.
	 *
	 * @param string    $path  Normalized path.
	 * @param Tar_Entry $entry Entry.
	 */
	public static function allowed( string $path, Tar_Entry $entry ): bool {
		if ( in_array( $path, array( Manifest::ENTRY, Manifest::DATABASE, Manifest::CHECKSUMS ), true ) ) {
			return $entry->is_file();
		}
		return 'wp-content' === $path || 0 === strpos( $path, 'wp-content/' );
	}
}
