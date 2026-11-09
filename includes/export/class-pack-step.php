<?php
/**
 * Export step: write the archive.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Writes <backup>.part: siteferry.json, database.sql, wp-content/..., then siteferry.sha256.
 *
 * Checksum lines are appended to checksums.tmp as files are stored; like the archive, the file
 * is cut back to its saved size when a slice resumes.
 */
final class Pack_Step implements Step {

	/** Extra space required beyond the estimated archive size. */
	const SPACE_MARGIN = 52428800;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Archive_Exception On write errors.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$state = $job->state( 'pack' );
		if ( array() === $state ) {
			$this->check_space( $job );
			$state = array(
				'phase'       => 'manifest',
				'writer'      => null,
				'list_offset' => 0,
				'sums_size'   => 0,
				'files'       => 0,
				'bytes'       => 0,
				'changed'     => 0,
				'skipped'     => 0,
				'unhashed'    => 0,
			);
		}

		$part   = self::part_path( $job );
		$target = \SiteFerry\Security\Archive_Keys::path( $job, $part ); // Through the encrypting stream for a password-protected backup.
		$writer = null === $state['writer']
			? Tar_Writer::create(
				$target,
				array(
					'compress' => (bool) $job->option( 'compress' ),
					'level'    => (int) $job->option( 'level' ),
				)
			)
			: Tar_Writer::resume( $target, $state['writer'] );
		$sums   = Io::open_or_fail( $job->path( 'checksums.tmp' ), 'c+b' );

		try {
			if ( ! ftruncate( $sums, $state['sums_size'] ) || 0 !== fseek( $sums, $state['sums_size'] ) ) {
				throw new Archive_Exception( __( 'Cannot rewind the checksum list.', 'siteferry' ), Archive_Exception::IO );
			}
			$done = $this->work( $job, $writer, $sums, $state, $budget );
			fflush( $sums );
			$state['sums_size'] = (int) ftell( $sums );

			if ( $done ) {
				$final           = $writer->finish();
				$state['size']   = (int) $final['output']['size'];
				$state['writer'] = null;
			} else {
				$state['writer'] = $writer->checkpoint();
			}
		} finally {
			// Always release the archive, so a failed job can delete it (Windows cannot delete open files).
			$writer->close();
			fclose( $sums ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $done && $target !== $part ) {
			\SiteFerry\Archive\Crypto::seal( $part, (string) \SiteFerry\Security\Archive_Keys::key( $job ) );
		}
		$job->set_state( 'pack', $state );

		$total = (int) $job->state( 'scan' )['bytes'] + (int) $job->state( 'database' )['size'];
		$job->set_progress(
			sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Packing: %1$s of %2$s', 'siteferry' ), size_format( $state['bytes'], 1 ), size_format( $total, 1 ) ),
			$state['bytes'],
			$total
		);
		if ( $done ) {
			$job->log( 'info', sprintf( 'Archive written: %d files, %s.', $state['files'], size_format( $state['size'], 1 ) ) );
		}
		return $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$part = self::part_path( $job );
		if ( is_file( $part ) ) {
			Io::quiet(
				static function () use ( $part ) {
					return unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
		}
		$job->delete_file( 'checksums.tmp' );
	}

	/**
	 * Path of the archive while it is being written.
	 *
	 * @param Job $job The job.
	 */
	public static function part_path( Job $job ): string {
		return $job->option( 'output_dir' ) . '/' . $job->option( 'archive' ) . '.part';
	}

	/**
	 * Adds entries phase by phase until done or out of budget.
	 *
	 * @param Job          $job    The job.
	 * @param Tar_Writer   $writer Archive.
	 * @param resource     $sums   Checksum list.
	 * @param array        $state  Pack state.
	 * @param Slice_Budget $budget Work limit.
	 * @return bool True when every entry is written.
	 */
	private function work( Job $job, Tar_Writer $writer, $sums, array &$state, Slice_Budget $budget ): bool {
		$list = null;
		try {
			while ( true ) {
				if ( $writer->has_pending() ) {
					$result = $writer->continue_file( $budget );
					if ( null === $result ) {
						return false;
					}
					$this->record( $job, $state, $sums, $result );
				}

				switch ( $state['phase'] ) {
					case 'manifest':
						$json = wp_json_encode( Manifest::build( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
						if ( false === $json ) {
							throw new Job_Exception( __( 'Cannot encode the manifest.', 'siteferry' ), Job_Exception::STEP );
						}
						$writer->add_string( Manifest::ENTRY, $json . "\n", $job->created() );
						$state['phase'] = 'database';
						break;

					case 'database':
						$state['phase'] = 'files';
						$result         = $writer->add_file( $job->path( 'database.sql' ), Manifest::DATABASE, $budget );
						if ( null === $result ) {
							return false;
						}
						$this->record( $job, $state, $sums, $result );
						break;

					case 'files':
						if ( null === $list ) {
							$list = Io::open_or_fail( $job->path( 'files.list' ), 'rb' );
							fseek( $list, $state['list_offset'] );
						}
						$line = fgets( $list );
						if ( false === $line ) {
							$state['phase'] = 'checksums';
							break;
						}
						$state['list_offset'] = (int) ftell( $list );
						if ( ! $this->add_listed( $job, $writer, $sums, $state, rtrim( $line, "\n" ), $budget ) ) {
							return false;
						}
						break;

					case 'checksums':
						fflush( $sums );
						$state['phase'] = 'end';
						if ( null === $writer->add_file( $job->path( 'checksums.tmp' ), Manifest::CHECKSUMS, $budget ) ) {
							return false;
						}
						break;

					default:
						return true;
				}

				if ( $budget->exhausted() ) {
					return 'end' === $state['phase'] && ! $writer->has_pending();
				}
			}
		} finally {
			if ( null !== $list ) {
				fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
	}

	/**
	 * Adds one entry from files.list.
	 *
	 * @param Job          $job    The job.
	 * @param Tar_Writer   $writer Archive.
	 * @param resource     $sums   Checksum list.
	 * @param array        $state  Pack state.
	 * @param string       $line   List line.
	 * @param Slice_Budget $budget Work limit.
	 * @return bool False if a file was left partly written for the next slice.
	 * @throws Job_Exception On a damaged list.
	 */
	private function add_listed( Job $job, Tar_Writer $writer, $sums, array &$state, string $line, Slice_Budget $budget ): bool {
		$item = Scanner::parse_line( $line );
		if ( null === $item ) {
			throw new Job_Exception( __( 'The file list is damaged.', 'siteferry' ), Job_Exception::STEP );
		}
		$name = '' === $item['path'] ? 'wp-content' : 'wp-content/' . $item['path'];

		if ( 'd' === $item['type'] ) {
			$writer->add_directory( $name, $item['mtime'] );
			return true;
		}

		$source = $job->option( 'site' )['wp_content_dir'] . '/' . $item['path'];
		$result = $writer->add_file( $source, $name, $budget );
		if ( null === $result ) {
			return false;
		}
		$this->record( $job, $state, $sums, $result );
		return true;
	}

	/**
	 * Books a stored (or skipped) file: counters, warnings and its checksum line.
	 *
	 * @param Job      $job    The job.
	 * @param array    $state  Pack state.
	 * @param resource $sums   Checksum list.
	 * @param array    $result Tar_Writer result.
	 */
	private function record( Job $job, array &$state, $sums, array $result ): void {
		if ( isset( $result['skipped'] ) ) {
			++$state['skipped'];
			$job->warn( sprintf( /* translators: %s: a file or backup name */ __( '"%s" disappeared or could not be read; it was left out.', 'siteferry' ), $result['name'] ) );
			return;
		}
		if ( Manifest::CHECKSUMS === $result['name'] ) {
			return;
		}

		++$state['files'];
		$state['bytes'] += $result['size'];

		if ( $result['changed'] ) {
			++$state['changed'];
			$job->warn( sprintf( /* translators: %s: a file or backup name */ __( '"%s" changed while it was being saved; the backup holds the bytes that were read.', 'siteferry' ), $result['name'] ) );
		}
		if ( null === $result['sha256'] ) {
			++$state['unhashed'];
			$job->warn( sprintf( /* translators: %s: a file or backup name */ __( '"%s" has no checksum because it changed while being saved.', 'siteferry' ), $result['name'] ) );
			return;
		}
		Io::write_all( $sums, $result['sha256'] . '  ' . $result['name'] . "\n" );
	}

	/**
	 * Refuses to start when the disk cannot hold the archive.
	 *
	 * @param Job $job The job.
	 * @throws Job_Exception If space is short.
	 */
	private function check_space( Job $job ): void {
		$needed = (int) $job->state( 'scan' )['bytes'] + (int) $job->state( 'database' )['size'];
		$needed = (int) ( $needed * 1.01 ) + self::SPACE_MARGIN;
		$dir    = $job->option( 'output_dir' );
		$free   = function_exists( 'disk_free_space' ) ? Io::quiet(
			static function () use ( $dir ) {
				return disk_free_space( $dir );
			}
		) : false;

		if ( false !== $free && $free < $needed ) {
			throw new Job_Exception(
				sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Not enough disk space: the backup needs up to %1$s, but only %2$s is free.', 'siteferry' ), size_format( $needed, 1 ), size_format( $free, 1 ) ),
				Job_Exception::STEP
			);
		}
	}
}
