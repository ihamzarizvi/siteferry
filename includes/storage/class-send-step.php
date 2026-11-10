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
	 * With one storage (send_to), its progress lives at the top of the step state, as before 0.38.0.
	 * With more (send_also), each storage has its own progress under "to", and they are sent one
	 * after the other; one that fails is recorded and the others still get the backup. The job
	 * fails only when none got it.
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the backup is missing or changes, or no storage got it.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$targets = self::targets( $job );
		if ( array() === $targets ) {
			return true;
		}
		$file = self::source( $job );
		if ( 1 === count( $targets ) ) {
			$state = $job->state( self::STATE );
			$sent  = $this->deliver(
				$job,
				$targets[0],
				$file,
				$state,
				$budget,
				static function ( array $sub ) use ( $job ) {
					$job->set_state( self::STATE, $sub );
				}
			);
			if ( null === $sent ) {
				return false;
			}
			$result         = $job->result();
			$result['sent'] = $sent;
			$this->finish_sending( $job, $file, $result, true );
			$job->set_progress( sprintf( /* translators: %s: where it went, such as Dropbox or another site's host name */ __( 'Sent to %s', 'siteferry' ), $sent['where'] ), 1, 1 );
			return true;
		}

		$state = $job->state( self::STATE );
		$state += array(
			'to'      => array(),
			'results' => array(),
		);
		foreach ( $targets as $destination ) {
			if ( isset( $state['results'][ $destination ] ) ) {
				continue;
			}
			$sub = isset( $state['to'][ $destination ] ) ? (array) $state['to'][ $destination ] : array();
			try {
				$sent = $this->deliver(
					$job,
					$destination,
					$file,
					$sub,
					$budget,
					static function ( array $changed ) use ( $job, &$state, $destination ) {
						$state['to'][ $destination ] = $changed;
						$job->set_state( self::STATE, $state );
					}
				);
			} catch ( \Throwable $e ) {
				if ( ! is_file( $file ) ) {
					throw $e; // The backup itself is gone: no storage can get it.
				}
				if ( isset( $sub['upload'] ) && empty( $sub['finished'] ) ) {
					self::abandon_quietly( $job, $destination, $sub );
				}
				$state['results'][ $destination ] = array(
					'destination' => $destination,
					'ok'          => false,
					'error'       => $e->getMessage(),
					'where'       => Destinations::short_label( $destination ),
				);
				unset( $state['to'][ $destination ] );
				$job->set_state( self::STATE, $state );
				$job->warn( sprintf( /* translators: 1: a storage, such as Dropbox, 2: what went wrong */ __( 'Not sent to %1$s: %2$s', 'siteferry' ), Destinations::short_label( $destination ), $e->getMessage() ) );
				$job->save();
				continue;
			}
			if ( null === $sent ) {
				return false;
			}
			$state['results'][ $destination ] = $sent + array( 'ok' => true );
			$job->set_state( self::STATE, $state );
			$job->save();
			if ( $budget->exhausted() && count( $state['results'] ) < count( $targets ) ) {
				return false;
			}
		}

		$all = array();
		foreach ( $targets as $destination ) {
			$all[] = $state['results'][ $destination ];
		}
		$ok = array_values(
			array_filter(
				$all,
				static function ( array $one ): bool {
					return ! empty( $one['ok'] );
				}
			)
		);
		if ( array() === $ok ) {
			throw new Job_Exception(
				sprintf(
					/* translators: %s: each storage with what went wrong */
					__( 'The backup could not be sent to any storage: %s', 'siteferry' ),
					implode(
						'; ',
						array_map(
							static function ( array $one ): string {
								return $one['where'] . ': ' . $one['error'];
							},
							$all
						)
					)
				),
				Job_Exception::STEP
			);
		}
		$result             = $job->result();
		$result['sent']     = $ok[0];
		$result['sent_all'] = $all;
		// The copy here is deleted only when every storage has the backup.
		$this->finish_sending( $job, $file, $result, count( $ok ) === count( $all ) );
		$job->set_progress(
			sprintf(
				/* translators: 1: number of storages that got the backup, 2: number of storages chosen */
				__( 'Sent to %1$d of %2$d storages', 'siteferry' ),
				count( $ok ),
				count( $all )
			),
			1,
			1
		);
		return true;
	}

	/**
	 * The storages this job sends to, in order: send_to, then send_also.
	 *
	 * @param Job $job The job.
	 * @return string[]
	 */
	public static function targets( Job $job ): array {
		$first = (string) $job->option( 'send_to', '' );
		if ( '' === $first ) {
			return array();
		}
		$targets = array( $first );
		foreach ( (array) $job->option( 'send_also', array() ) as $also ) {
			$also = (string) $also;
			if ( '' !== $also && ! in_array( $also, $targets, true ) ) {
				$targets[] = $also;
			}
		}
		return $targets;
	}

	/**
	 * Sends the backup (and, for a changes-only backup, its full backup first) to one storage.
	 *
	 * @param Job          $job         The job.
	 * @param string       $destination Destination id.
	 * @param string       $file        The backup.
	 * @param array        $state       This storage's progress (empty to start); updated.
	 * @param Slice_Budget $budget      Work limit.
	 * @param callable     $save        function( array $state ): void.
	 * @return array|null What was sent (destination, name, size, parts, where, base), or null when not finished yet.
	 * @throws Job_Exception If the backup is missing or changes.
	 */
	private function deliver( Job $job, string $destination, string $file, array &$state, Slice_Budget $budget, callable $save ): ?array {
		$name   = basename( $file );
		$driver = Destinations::driver( $destination );
		if ( ! isset( $state['started_at'] ) ) {
			$state['started_at'] = microtime( true ); // For the forecast's upload speed.
			$save( $state );
		}

		// A changes-only backup is useless on a storage without its full backup: that goes first.
		if ( ! array_key_exists( 'base_file', $state ) && ! isset( $state['upload'] ) ) {
			$state['base_file'] = self::base_to_send( $file, $driver );
			$save( $state );
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
				static function ( array $sub ) use ( &$state, $save ) {
					$state['base'] = $sub;
					$save( $state );
				}
			);
			if ( ! $done ) {
				return null;
			}
			$job->log( 'info', sprintf( 'Sent the full backup %s to %s first: the changes-only backup is built on it.', basename( $base_file ), $driver->describe() ) );
			if ( $budget->exhausted() ) {
				return null;
			}
		}

		$main = array_diff_key( $state, array_flip( array( 'base_file', 'base', 'started_at' ) ) );
		$done = $this->transfer(
			$job,
			$driver,
			$destination,
			$file,
			$main,
			$budget,
			static function ( array $sub ) use ( &$state, $save ) {
				$state = array_merge( $state, $sub );
				$save( $state );
			}
		);
		if ( ! $done ) {
			return null;
		}
		$job->log( 'info', count( $main['pieces'] ) > 1 ? sprintf( 'Sent %s to %s in %d parts.', $name, $driver->describe(), count( $main['pieces'] ) ) : sprintf( 'Sent %s to %s.', $name, $driver->describe() ) );
		\SiteFerry\Forecast::record_transfer(
			$destination,
			'up',
			(int) $main['size'] + ( '' === $base_file ? 0 : (int) Io::quiet(
				static function () use ( $base_file ) {
					return filesize( $base_file );
				}
			) ),
			microtime( true ) - (float) $state['started_at']
		);
		return array(
			'destination' => $destination,
			'name'        => $name,
			'size'        => $main['size'],
			'parts'       => count( $main['pieces'] ),
			'where'       => $driver->describe(),
			'base'        => '' === $base_file ? '' : basename( $base_file ),
		);
	}

	/**
	 * Stores the result and, when asked and allowed, deletes the copy on this server.
	 *
	 * @param Job    $job       The job.
	 * @param string $file      The backup.
	 * @param array  $result    Job result with "sent".
	 * @param bool   $may_delete Whether every storage has the backup.
	 */
	private function finish_sending( Job $job, string $file, array $result, bool $may_delete ): void {
		if ( $job->option( 'delete_after_send', false ) ) {
			if ( ! $may_delete ) {
				$job->warn( __( 'The copy on this server was kept: not every storage got the backup.', 'siteferry' ) );
			} else {
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
		}
		$job->set_result( $result );
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
		$state   = $job->state( self::STATE );
		$targets = self::targets( $job );
		if ( isset( $state['to'] ) && is_array( $state['to'] ) ) {
			foreach ( $state['to'] as $destination => $sub ) {
				self::abandon_all( $job, (string) $destination, (array) $sub );
			}
			return;
		}
		if ( isset( $targets[0] ) ) {
			self::abandon_all( $job, $targets[0], $state );
		}
	}

	/**
	 * Aborts one storage's unfinished uploads (the full backup sent first, and the backup).
	 *
	 * @param Job    $job         The job.
	 * @param string $destination Destination id.
	 * @param array  $state       That storage's progress.
	 */
	private static function abandon_all( Job $job, string $destination, array $state ): void {
		if ( isset( $state['base']['upload'] ) && empty( $state['base']['finished'] ) ) {
			self::abandon( $job, $destination, (array) $state['base'] );
		}
		if ( isset( $state['upload'] ) && empty( $state['finished'] ) ) {
			self::abandon( $job, $destination, $state );
		}
	}

	/**
	 * Like abandon_all(), but never throws: used while carrying on with the other storages.
	 *
	 * @param Job    $job         The job.
	 * @param string $destination Destination id.
	 * @param array  $state       That storage's progress.
	 */
	private static function abandon_quietly( Job $job, string $destination, array $state ): void {
		try {
			self::abandon_all( $job, $destination, $state );
		} catch ( \Throwable $e ) {
			$job->warn( sprintf( /* translators: 1: a storage, such as Dropbox, 2: what went wrong */ __( 'The unfinished upload to %1$s could not be removed: %2$s', 'siteferry' ), Destinations::short_label( $destination ), $e->getMessage() ) );
		}
	}

	/**
	 * Aborts an unfinished upload and removes the parts already stored.
	 *
	 * @param Job    $job         The job.
	 * @param string $destination Destination id.
	 * @param array  $state       Upload progress.
	 */
	private static function abandon( Job $job, string $destination, array $state ): void {
		$driver = Destinations::driver( $destination );
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
