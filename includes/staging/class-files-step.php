<?php
/**
 * Staging copy, step 1: the files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Io;
use SiteFerry\Export\Exclusions;
use SiteFerry\Export\Scanner;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Lists WordPress's folder (with the backup's exclusions, see Staging::exclusions()), checks there
 * is room for the copy, then copies the list into the staging folder, file by file and large files
 * in pieces, so every slice continues where the last one stopped.
 */
final class Files_Step implements Step {

	const STEP = 'staging.files';

	/** Bytes copied per read. */
	const CHUNK = 4194304;

	/** Room to keep free beyond the copy. */
	const MARGIN = 104857600;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the copy cannot be made.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$record = (array) $job->option( 'staging' );
		$root   = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$dest   = (string) $record['path'];
		$state  = $job->state( 'staging_files' ) + array(
			'phase'  => 'scan',
			'scan'   => array(),
			'offset' => 0,
			'part'   => 0,
			'copied' => 0,
			'bytes'  => 0,
		);

		if ( ! is_dir( $dest ) ) {
			if ( ! mkdir( $dest, 0755 ) && ! is_dir( $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- runs in cron and WP-CLI, where WP_Filesystem may need credentials.
				throw new Job_Exception( sprintf( /* translators: %s: a folder path */ __( 'Cannot create the folder "%s".', 'siteferry' ), $dest ), Job_Exception::STEP );
			}
		}
		// The marker first: whatever happens next, the folder is known as this copy's.
		$marker = $dest . '/' . Staging::MARKER;
		if ( ! is_file( $marker ) ) {
			file_put_contents( $marker, (string) $record['id'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$list = $job->path( 'staging.list' );
		if ( 'scan' === $state['phase'] ) {
			$scan    = $state['scan'];
			$scanner = new Scanner( $root, Exclusions::from_array( (array) $job->option( 'exclusions' ) ) );
			$done    = $scanner->run( $scan, $list, $budget, array( $job, 'warn' ) );
			$state['scan'] = $scan;
			$job->set_progress( sprintf( /* translators: 1: a number, 2: a size, such as 12 MB */ __( 'Listing files: %1$s files, %2$s', 'siteferry' ), number_format( (int) $scan['files'] ), size_format( (int) $scan['bytes'], 1 ) ) );
			if ( ! $done ) {
				$job->set_state( 'staging_files', $state );
				return false;
			}
			$this->check_space( $job, (int) $scan['bytes'], $root );
			$state['phase'] = 'copy';
			$job->log( 'info', sprintf( 'Staging: %d files, %d bytes to copy.', (int) $scan['files'], (int) $scan['bytes'] ) );
		}

		$total = max( 1, (int) $state['scan']['files'] );
		$in    = Io::open_or_fail( $list, 'rb' );
		try {
			fseek( $in, (int) $state['offset'] );
			while ( ! $budget->exhausted() ) {
				$line = fgets( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				if ( false === $line ) {
					$state['phase'] = 'done';
					break;
				}
				$entry = Scanner::parse_line( rtrim( $line, "\r\n" ) );
				if ( null !== $entry && '' !== $entry['path'] ) {
					$target = $dest . '/' . $entry['path'];
					if ( 'd' === $entry['type'] ) {
						if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) && ! is_dir( $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- runs in cron and WP-CLI, where WP_Filesystem may need credentials.
							throw new Job_Exception( sprintf( /* translators: %s: a folder path */ __( 'Cannot create the folder "%s".', 'siteferry' ), $target ), Job_Exception::STEP );
						}
					} elseif ( ! $this->copy( $root . '/' . $entry['path'], $target, $state, $budget, $job ) ) {
						break; // Out of time in the middle of a large file: continue it next slice.
					} else {
						++$state['copied'];
						$state['bytes'] += $entry['size'];
					}
				}
				$state['offset'] = (int) ftell( $in );
				$state['part']   = 0;
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$job->set_state( 'staging_files', $state );
		$job->set_progress( sprintf( /* translators: 1: a number, 2: a number */ __( 'Copying files: %1$s of %2$s', 'siteferry' ), number_format( (int) $state['copied'] ), number_format( $total ) ), (int) $state['copied'], $total );
		if ( 'done' === $state['phase'] ) {
			$job->log( 'info', sprintf( 'Staging: %d files copied.', (int) $state['copied'] ) );
			return true;
		}
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$job->delete_file( 'staging.list' );
	}

	/**
	 * Copies one file, from where an earlier slice stopped. False when time ran out first.
	 *
	 * @param string       $from   Source.
	 * @param string       $to     Target.
	 * @param array        $state  Progress (part: bytes of this file already copied).
	 * @param Slice_Budget $budget Work limit.
	 * @param Job          $job    The job (for warnings).
	 * @throws Job_Exception If the target cannot be written.
	 */
	private function copy( string $from, string $to, array &$state, Slice_Budget $budget, Job $job ): bool {
		$in = Io::open( $from, 'rb' );
		if ( false === $in ) {
			$job->warn( sprintf( /* translators: %s: a file path */ __( 'Not copied (it could not be read): %s', 'siteferry' ), $from ) );
			return true;
		}
		try {
			$out = Io::open_or_fail( $to, 0 === (int) $state['part'] ? 'wb' : 'cb' );
			try {
				if ( (int) $state['part'] > 0 ) {
					fseek( $in, (int) $state['part'] );
					fseek( $out, (int) $state['part'] );
					ftruncate( $out, (int) $state['part'] );
				}
				while ( ! feof( $in ) ) {
					$chunk = (string) fread( $in, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( '' === $chunk ) {
						break;
					}
					Io::write_all( $out, $chunk );
					$state['part'] += strlen( $chunk );
					$budget->consume( strlen( $chunk ) );
					if ( $budget->exhausted() && ! feof( $in ) ) {
						return false;
					}
				}
			} finally {
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$mtime = Io::quiet(
			static function () use ( $from ) {
				return filemtime( $from );
			}
		);
		if ( false !== $mtime ) {
			Io::quiet(
				static function () use ( $to, $mtime ) {
					return touch( $to, (int) $mtime ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- keeps the file's date in the copy.
				}
			);
		}
		return true;
	}

	/**
	 * Stops before copying when the disk cannot hold the files and the tables.
	 *
	 * @param Job    $job   The job.
	 * @param int    $bytes Bytes of files.
	 * @param string $root  WordPress's folder.
	 * @throws Job_Exception If there is not enough room.
	 */
	private function check_space( Job $job, int $bytes, string $root ): void {
		global $wpdb;
		$db = 0;
		foreach ( Staging::live_tables( $wpdb, (string) $job->option( 'live_prefix' ) ) as $table ) {
			$db += $table[1];
		}
		$needed = $bytes + $db + self::MARGIN;
		$free   = function_exists( 'disk_free_space' ) ? Io::quiet(
			static function () use ( $root ) {
				return disk_free_space( $root );
			}
		) : false;
		if ( false !== $free && $free < $needed ) {
			throw new Job_Exception( sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Not enough disk space: the staging copy needs about %1$s, but only %2$s is free.', 'siteferry' ), size_format( $needed, 1 ), size_format( $free, 1 ) ), Job_Exception::STEP );
		}
	}
}
