<?php
/**
 * Export step for a changes-only backup: compare the files with the full backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Io;
use SiteFerry\Contents;
use SiteFerry\Incremental;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * After the scan: reads the full backup's file list (its index, built in slices) and checksum
 * list, compares them with the scanned files, and writes base.unchanged ("hash  path" for each
 * file the restore takes from the full backup). The pack step leaves those files out and carries
 * their checksums; the manifest names the full backup and the files it has that are gone now.
 */
final class Base_Step implements Step {

	const STEP = 'export.base';

	/** Job file: the unchanged files and their checksums. */
	const UNCHANGED = 'base.unchanged';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the full backup is gone, changed or cannot be read.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$base = (array) $job->option( 'base' );
		$path = (string) $base['path'];
		if ( ! is_file( $path ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a backup name */ __( 'The full backup %s is no longer on this server.', 'siteferry' ), basename( $path ) ), Job_Exception::STEP );
		}

		$index = Contents::index( $path, $budget->seconds_left() );
		if ( ! $index['complete'] ) {
			$job->set_progress( sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Reading the full backup: %1$s of %2$s', 'siteferry' ), size_format( $index['read'], 1 ), size_format( $index['size'], 1 ) ), $index['read'], $index['size'] );
			return false;
		}

		list( $sums, $fingerprint ) = Incremental::checksums( $path );
		$now                        = array();
		$list                       = Io::open_or_fail( $job->path( 'files.list' ), 'rb' );
		try {
			while ( false !== ( $line = fgets( $list ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition, WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				$item = Scanner::parse_line( rtrim( $line, "\n" ) );
				if ( null !== $item && 'f' === $item['type'] ) {
					$now[ 'wp-content/' . $item['path'] ] = array( $item['size'], $item['mtime'] );
				}
			}
		} finally {
			fclose( $list ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$result = Incremental::compare( Incremental::files_of( $index['entries'] ), $sums, $now );
		$out    = Io::open_or_fail( $job->path( self::UNCHANGED ), 'wb' );
		try {
			foreach ( $result['unchanged'] as $file => $hash ) {
				Io::write_all( $out, $hash . '  ' . $file . "\n" );
			}
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$manifest = Incremental::base_of( array( 'base' => array( 'name' => basename( $path ) ) ) );
		$created  = '';
		try {
			$created = (string) \SiteFerry\Backups::manifest( $path )['created'];
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$job->set_state(
			'base',
			array(
				'name'          => null === $manifest ? basename( $path ) : $manifest['name'],
				'created'       => $created,
				'fingerprint'   => $fingerprint,
				'files'         => count( $result['unchanged'] ),
				'deleted'       => $result['deleted'],
				'changed'       => $result['changed'],
				'changed_bytes' => $result['changed_bytes'],
			)
		);
		$job->set_progress( sprintf( /* translators: %d: a number of files */ _n( '%d file changed since the full backup', '%d files changed since the full backup', $result['changed'], 'siteferry' ), $result['changed'] ), 1, 1 );
		$job->log( 'info', sprintf( 'Changes since %s: %d files new or changed (%s), %d unchanged, %d gone.', basename( $path ), $result['changed'], size_format( $result['changed_bytes'], 1 ), count( $result['unchanged'] ), count( $result['deleted'] ) ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$job->delete_file( self::UNCHANGED );
	}

	/**
	 * The unchanged files of a changes-only export: path => sha256 (empty for a full backup).
	 *
	 * @param Job $job Export job.
	 * @return array<string,string>
	 */
	public static function unchanged( Job $job ): array {
		if ( null === $job->option( 'base' ) || ! is_file( $job->path( self::UNCHANGED ) ) ) {
			return array();
		}
		return Incremental::parse_sums( (string) file_get_contents( $job->path( self::UNCHANGED ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
