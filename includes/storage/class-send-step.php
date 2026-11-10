<?php
/**
 * Job step: send a backup to remote storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads the backup part by part (at least one part per slice) to the destination named by the
 * job option "send_to"; does nothing when it is empty (an export that stays on the server).
 *
 * Used as "export.send" after an export, and as "send.upload" for a stored backup (job option
 * "file"). Progress is saved after every part, so an interrupted slice repeats at most one part.
 * If the job fails or is cancelled, the unfinished upload is aborted on the storage; the local
 * backup is never touched unless the upload completed and "delete_after_send" is set.
 */
final class Send_Step implements Step {

	const STATE = 'send';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the backup is missing or changes.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$destination = (string) $job->option( 'send_to', '' );
		if ( '' === $destination ) {
			return true;
		}
		$file   = self::source( $job );
		$name   = basename( $file );
		$driver = Destinations::driver( $destination );
		$state  = $job->state( self::STATE );

		// A changes-only backup is useless on a storage without its full backup: that goes first.
		if ( ! array_key_exists( 'base_file', $state ) && ! isset( $state['upload'] ) ) {
			$state['base_file'] = self::base_to_send( $file, $driver );
			$job->set_state( self::STATE, $state );
		}
		$base_file = isset( $state['base_file'] ) ? (string) $state['base_file'] : '';
		if ( '' !== $base_file && empty( $state['base']['finished'] ) ) {
			$sub  = isset( $state['base'] ) ? (array) $state['base'] : array();
			$done = $this->transfer(
				$job,
				$driver,
				$destination,
				$base_file,
				$sub,
				$budget,
				static function ( array $sub ) use ( $job, &$state ) {
					$state['base'] = $sub;
					$job->set_state( self::STATE, $state );
				}
			);
			if ( ! $done ) {
				return false;
			}
			$job->log( 'info', sprintf( 'Sent the full backup %s first: the changes-only backup is built on it.', basename( $base_file ) ) );
			if ( $budget->exhausted() ) {
				return false;
			}
		}

		$main = array_diff_key( $state, array_flip( array( 'base_file', 'base' ) ) );
		$done = $this->transfer(
			$job,
			$driver,
			$destination,
			$file,
			$main,
			$budget,
			static function ( array $sub ) use ( $job, &$state ) {
				$state = array_merge( $state, $sub );
				$job->set_state( self::STATE, $state );
			}
		);
		if ( ! $done ) {
			return false;
		}

		$result         = $job->result();
		$result['sent'] = array(
			'destination' => $destination,
			'name'        => $name,
			'size'        => $main['size'],
			'parts'       => count( $main['pieces'] ),
			'where'       => $driver->describe(),
			'base'        => '' === $base_file ? '' : basename( $base_file ),
		);
		$job->log( 'info', count( $main['pieces'] ) > 1 ? sprintf( 'Sent %s to %s in %d parts.', $name, $driver->describe(), count( $main['pieces'] ) ) : sprintf( 'Sent %s to %s.', $name, $driver->describe() ) );

		if ( $job->option( 'delete_after_send', false ) ) {
			$deleted = Io::quiet(
				static function () use ( $file ) {
					return unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
			$result['deleted_local'] = (bool) $deleted;
			if ( $deleted ) {
				$job->log( 'info', 'Deleted the copy on this server, as asked.' );
			} else {
				$job->warn( __( 'The copy on this server could not be deleted.', 'siteferry' ) );
			}
		}
		$job->set_result( $result );
		$job->set_progress( sprintf( /* translators: %s: where it went, such as Dropbox or another site's host name */ __( 'Sent to %s', 'siteferry' ), $driver->describe() ), 1, 1 );
		return true;
	}

	/**
	 * Uploads one file part by part; $state holds its progress and $save stores it.
	 *
	 * @param Job          $job         The job.
	 * @param Driver       $driver      Storage.
	 * @param string       $destination Destination id.
	 * @param string       $file        File to send.
	 * @param array        $state       Upload progress (empty to start); updated.
	 * @param Slice_Budget $budget      Work limit.
	 * @param callable     $save        function( array $state ): void, called after every part.
	 * @return bool True when the whole file is stored.
	 * @throws Job_Exception If the file is missing or changes.
	 */
	private function transfer( Job $job, Driver $driver, string $destination, string $file, array &$state, Slice_Budget $budget, callable $save ): bool {
		$name = basename( $file );
		$size = is_file( $file ) ? (int) filesize( $file ) : -1;
		if ( ! empty( $state['finished'] ) ) {
			return true;
		}
		if ( array() === $state ) {
			if ( $size <= 0 ) {
				throw new Job_Exception( __( 'The backup to send is missing or empty.', 'siteferry' ), Job_Exception::STEP );
			}
			// A storage with a size limit gets the backup as numbered parts.
			$pieces = Parts::pieces( $name, $size, Destinations::split( $destination ) );
			$state  = array(
				'size'     => $size,
				'offset'   => 0,
				'index'    => 0,
				'pieces'   => $pieces,
				'piece'    => 0,
				'upload'   => $driver->begin( $pieces[0][0], $pieces[0][2] ),
				'finished' => false,
			);
			$save( $state );
			$job->save();
			$job->log(
				'info',
				count( $pieces ) > 1
					? sprintf( 'Sending %s (%s) to %s as %d parts of at most %s.', $name, size_format( $size, 1 ), $driver->describe(), count( $pieces ), size_format( $pieces[0][2], 1 ) )
					: sprintf( 'Sending %s (%s) to %s in parts of %s.', $name, size_format( $size, 1 ), $driver->describe(), size_format( $state['upload']['part_size'] ) )
			);
		}
		if ( $size !== $state['size'] ) {
			throw new Job_Exception( __( 'The backup changed or disappeared while it was being sent.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! isset( $state['pieces'] ) ) {
			$state['pieces'] = array( array( $name, 0, $size ) ); // Begun by an earlier version.
			$state['piece']  = 0;
		}

		$handle = Io::open_or_fail( $file, 'rb' );
		try {
			while ( ! $state['finished'] ) {
				list( , $start, $length ) = $state['pieces'][ $state['piece'] ];
				$end                      = $start + $length;
				if ( $state['offset'] < $end ) {
					$bytes = self::read( $handle, $state['offset'], (int) min( (int) $state['upload']['part_size'], $end - $state['offset'] ) );

					$state['upload'] = $driver->put_part( $state['upload'], $state['index'], $bytes );
					$state['offset'] += strlen( $bytes );
					++$state['index'];
				}
				if ( $state['offset'] >= $end ) {
					// This piece is stored; the next one starts (or the last one is done).
					$driver->finish( $state['upload'] );
					++$state['piece'];
					if ( $state['piece'] < count( $state['pieces'] ) ) {
						$next            = $state['pieces'][ $state['piece'] ];
						$state['upload'] = $driver->begin( $next[0], $next[2] );
						$state['index']  = 0;
					} else {
						$state['finished'] = true;
					}
				}
				$save( $state );
				$job->set_progress( sprintf( /* translators: 1: where it goes, such as Dropbox or another site's host name, 2: a size, such as 12 MB, 3: a size, such as 12 MB */ __( 'Sending to %1$s: %2$s of %3$s', 'siteferry' ), Destinations::short_label( $destination ), size_format( $state['offset'], 1 ), size_format( $state['size'], 1 ) ), $state['offset'], $state['size'] );
				$job->save();

				if ( ! $state['finished'] && $budget->exhausted() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return true;
	}

	/**
	 * The full backup to send before a changes-only backup: its path when the storage does not
	 * have it yet, '' when it does or the backup is a full one.
	 *
	 * @param string $file   Backup to send.
	 * @param Driver $driver Storage.
	 * @throws Job_Exception If the full backup is neither here nor on the storage.
	 */
	private static function base_to_send( string $file, Driver $driver ): string {
		$name = basename( $file );
		if ( \SiteFerry\Backups::encrypted_name( $name ) || \SiteFerry\Wpress\Wpress::is_name( $name ) ) {
			return '';
		}
		try {
			$base = \SiteFerry\Incremental::base_of( \SiteFerry\Backups::manifest( $file ) );
		} catch ( \Throwable $e ) {
			return '';
		}
		if ( null === $base || null !== Parts::find( $driver, $base['name'] ) ) {
			return '';
		}
		$path = dirname( $file ) . '/' . $base['name'];
		if ( ! is_file( $path ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a backup name */ __( 'This changes-only backup is built on the full backup %s, which is neither on this server nor on the storage; it could not be restored from there.', 'siteferry' ), $base['name'] ), Job_Exception::STEP );
		}
		return $path;
	}
	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$state = $job->state( self::STATE );
		if ( isset( $state['base']['upload'] ) && empty( $state['base']['finished'] ) ) {
			self::abandon( $job, (array) $state['base'] );
		}
		if ( ! isset( $state['upload'] ) || ! empty( $state['finished'] ) ) {
			return;
		}
		self::abandon( $job, $state );
	}

	/**
	 * Aborts an unfinished upload and removes the parts already stored.
	 *
	 * @param Job   $job   The job.
	 * @param array $state Upload progress.
	 */
	private static function abandon( Job $job, array $state ): void {
		$driver = Destinations::driver( (string) $job->option( 'send_to' ) );
		$driver->abort( $state['upload'] );
		// Parts already stored are removed too: half a set is no backup.
		for ( $i = 0; isset( $state['piece'], $state['pieces'] ) && $i < $state['piece']; $i++ ) {
			try {
				$driver->delete( (string) $state['pieces'][ $i ][0] );
			} catch ( \Throwable $e ) {
				$job->warn( sprintf( /* translators: 1: a file name, 2: the reason */ __( 'Part %1$s could not be removed from the storage: %2$s', 'siteferry' ), $state['pieces'][ $i ][0], $e->getMessage() ) );
			}
		}
		$job->log( 'info', 'Removed the unfinished upload from the storage.' );
	}

	/**
	 * The backup file this job sends.
	 *
	 * @param Job $job The job.
	 */
	private static function source( Job $job ): string {
		if ( 'export' === $job->type() ) {
			// The finished backup, which is a .wpress file when the export made one.
			$result = $job->result();
			return ! empty( $result['file'] ) ? (string) $result['file'] : $job->option( 'output_dir' ) . '/' . $job->option( 'archive' );
		}
		return (string) $job->option( 'file' );
	}

	/**
	 * Reads exactly $length bytes at $offset.
	 *
	 * @param resource $handle File handle.
	 * @param int      $offset Position.
	 * @param int      $length Bytes.
	 * @throws Job_Exception If the file ends early.
	 */
	private static function read( $handle, int $offset, int $length ): string {
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new Job_Exception( __( 'Cannot read the backup.', 'siteferry' ), Job_Exception::STEP );
		}
		$bytes = '';
		while ( strlen( $bytes ) < $length ) {
			$chunk = fread( $handle, $length - strlen( $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				throw new Job_Exception( __( 'The backup ended early while it was being sent.', 'siteferry' ), Job_Exception::STEP );
			}
			$bytes .= $chunk;
		}
		return $bytes;
	}
}
