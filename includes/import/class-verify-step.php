<?php
/**
 * Import step: check every staged file against the backup's checksums.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Database\Dumper;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Compares extracted.sha256 with siteferry.sha256 and checks that the dump is complete.
 *
 * A missing or changed file stops the import before anything on the site is touched. Files the
 * list does not cover (they changed while the backup was made, so they have no checksum) are
 * reported as warnings.
 */
final class Verify_Step implements Step {

	/** Names listed in error messages before "and N more". */
	const SHOW = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused).
	 * @throws Job_Exception If files are missing or damaged.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$staging  = Extract_Step::staging( $job );
		$expected = self::read_list( $staging . '/' . Manifest::CHECKSUMS, true );
		$actual   = self::read_list( $job->path( 'extracted.sha256' ), false );

		$missing = array();
		$changed = array();
		foreach ( $expected as $path => $hash ) {
			if ( ! Import::wanted_path( $job, $path ) ) {
				unset( $expected[ $path ] );
				continue; // Not unpacked: a partial restore leaves it out.
			}
			if ( ! isset( $actual[ $path ] ) ) {
				$missing[] = $path;
				continue;
			}
			$got = $actual[ $path ];
			if ( '-' === $got ) {
				$got = (string) hash_file( 'sha256', $staging . '/' . $path );
			}
			if ( ! hash_equals( $hash, $got ) ) {
				$changed[] = $path;
			}
		}
		if ( array() !== $missing ) {
			throw new Job_Exception( sprintf( /* translators: %s: a list of file names */ __( 'The backup is incomplete; missing: %s', 'siteferry' ), self::names( $missing ) ), Job_Exception::STEP );
		}
		if ( array() !== $changed ) {
			throw new Job_Exception( sprintf( /* translators: %s: a list of file names */ __( 'The backup is damaged; checksum mismatch for: %s', 'siteferry' ), self::names( $changed ) ), Job_Exception::STEP );
		}

		$unverified = array_diff( array_keys( $actual ), array_keys( $expected ), array( Manifest::ENTRY, Manifest::CHECKSUMS ) );
		foreach ( array_slice( $unverified, 0, 50 ) as $path ) {
			$job->warn( sprintf( /* translators: %s: a file or backup name */ __( '"%s" has no checksum in the backup (it changed while the backup was made).', 'siteferry' ), $path ) );
		}

		if ( ! self::dump_complete( $staging . '/' . Manifest::DATABASE ) ) {
			throw new Job_Exception( __( 'The database dump in the backup is incomplete.', 'siteferry' ), Job_Exception::STEP );
		}

		$job->set_progress( sprintf( /* translators: %s: a number */ __( 'Checked %s files', 'siteferry' ), number_format( count( $expected ) ) ) );
		$job->log( 'info', sprintf( 'Checksums verified for %d files.', count( $expected ) ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}

	/**
	 * Reads a "hash  path" list into path => hash.
	 *
	 * @param string $file   List file.
	 * @param bool   $strict Whether every line must be a valid SHA-256 line.
	 * @return array<string,string>
	 * @throws Job_Exception If the file is missing or malformed.
	 */
	private static function read_list( string $file, bool $strict ): array {
		$handle = is_file( $file ) ? fopen( $file, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new Job_Exception( __( 'The backup has no checksum list.', 'siteferry' ), Job_Exception::STEP );
		}
		$list = array();
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition, WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^([0-9a-f]{64}|-) [ *](.+)$/', $line, $match ) || ( $strict && '-' === $match[1] ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				throw new Job_Exception( __( 'The checksum list in the backup is malformed.', 'siteferry' ), Job_Exception::STEP );
			}
			$list[ $match[2] ] = $match[1];
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $list;
	}

	/**
	 * Whether a dump ends with the SiteFerry end marker.
	 *
	 * @param string $file Dump file.
	 */
	private static function dump_complete( string $file ): bool {
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		if ( $size < strlen( Dumper::END_MARKER ) ) {
			return false;
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fseek( $handle, max( 0, $size - 256 ) );
		$tail = (string) fread( $handle, 256 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = explode( "\n", rtrim( $tail ) );
		return Dumper::END_MARKER === trim( (string) end( $lines ) );
	}

	/**
	 * First few names and a count of the rest.
	 *
	 * @param string[] $names Names.
	 */
	private static function names( array $names ): string {
		$shown = implode( ', ', array_slice( $names, 0, self::SHOW ) );
		return count( $names ) > self::SHOW ? sprintf( '%s and %d more', $shown, count( $names ) - self::SHOW ) : $shown;
	}
}
