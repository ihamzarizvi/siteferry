<?php
/**
 * Check step: read a backup from start to end and compare it with its own checksums.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Check;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Path_Guard;
use SiteFerry\Archive\Resumable_Hash;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Backups;
use SiteFerry\Database\Dumper;
use SiteFerry\Export\Manifest;
use SiteFerry\Import\Extract_Step;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;
use SiteFerry\Storage\Fetch_Step;
use SiteFerry\Wpress\Reader as Wpress_Reader;
use SiteFerry\Wpress\Wpress;

defined( 'ABSPATH' ) || exit;

/**
 * Reads every entry of the copy (a .tar.gz is decompressed on the way) and hashes the files
 * without writing them anywhere. A file cut by the end of a slice continues in the next one: the
 * hash state is saved (PHP 8), or rebuilt by reading the file's first bytes from the archive
 * again (PHP 7.4).
 *
 * At the end, a SiteFerry backup must have its manifest, a complete database dump of the size the
 * manifest gives, and every file of its checksum list with that checksum; entries an import would
 * refuse count as damage too. A .wpress file has no checksums: it must read to its end with
 * package.json and database.sql in it. The outcome is recorded either way; damage fails the job.
 */
final class Verify_Step implements Step {

	const STATE = 'check';

	/** Bytes read at a time. */
	const CHUNK = 1048576;

	/** Files in the job folder: the computed and the listed checksums, and the manifest. */
	const COMPUTED = 'computed.sha256';
	const EXPECTED = 'expected.sha256';
	const MANIFEST = 'manifest.json';

	/** Names listed in messages before "and N more". */
	const SHOW = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the copy is damaged or cannot be read.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$file  = self::file( $job );
		$state = $job->state( self::STATE ) + array(
			'wpress'   => null,
			'reader'   => null,
			'current'  => null,
			'hash'     => null,
			'tail'     => '',
			'entries'  => 0,
			'files'    => 0,
			'bytes'    => 0,
			'content'  => 0,
			'refused'  => array(),
			'sizes'    => array(),
			'database' => -1,
			'package'  => false,
			'offset'   => 0,
		);
		if ( null === $state['wpress'] ) {
			$state['wpress'] = Wpress::is_name( $file ) || Wpress_Reader::is_wpress( $file );
			$job->log( 'info', sprintf( 'Checking %s on %s (%s).', (string) $job->option( 'name' ), Check::where( (string) $job->option( 'place' ) ), size_format( (int) filesize( $file ), 1 ) ) );
		}

		try {
			// An encrypted copy is read through its decrypting stream (a wrong password stops here).
			$done = $this->read( $job, $state['wpress'] ? $file : \SiteFerry\Security\Archive_Keys::path( $job, $file ), $state, $budget );
		} catch ( Archive_Exception $e ) {
			if ( in_array( $e->getCode(), array( Archive_Exception::CORRUPT, Archive_Exception::TRUNCATED, Archive_Exception::UNSUPPORTED, Archive_Exception::UNSAFE_PATH ), true ) ) {
				$this->damaged( $job, 'it cannot be read to its end: ' . $e->getMessage() );
			}
			throw $e;
		}
		$job->set_state( self::STATE, $state );
		$size = (int) filesize( $file );
		$read = $done ? $size : min( $size, (int) $state['offset'] );
		$job->set_progress( sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Checking: %1$s of %2$s', 'siteferry' ), size_format( $read, 1 ), size_format( $size, 1 ) ), $read, $size );
		if ( ! $done ) {
			return false;
		}

		$this->conclude( $job, $state );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		foreach ( array( self::COMPUTED, self::EXPECTED, self::MANIFEST ) as $name ) {
			$job->delete_file( $name );
		}
	}

	/**
	 * The copy being checked: the backup on this server, or the download in the job's folder.
	 *
	 * @param Job $job The job.
	 */
	public static function file( Job $job ): string {
		return '' !== (string) $job->option( 'source', '' ) ? $job->path( Fetch_Step::PART ) : (string) $job->option( 'file' );
	}

	/**
	 * Reads entries until the end of the archive or of the budget.
	 *
	 * @param Job          $job    The job.
	 * @param string       $file   Archive.
	 * @param array        $state  In/out: check state.
	 * @param Slice_Budget $budget Work limit.
	 * @return bool True at the end of the archive.
	 */
	private function read( Job $job, string $file, array &$state, Slice_Budget $budget ): bool {
		$wpress = (bool) $state['wpress'];
		$reader = $wpress ? Wpress_Reader::open( $file, $state['reader'] ) : Tar_Reader::open( $file, $state['reader'] );
		$files  = array();
		foreach ( array( self::COMPUTED, self::EXPECTED, self::MANIFEST ) as $name ) {
			$files[ $name ] = Io::open_or_fail( $job->path( $name ), 'c+b' );
			$keep           = isset( $state['sizes'][ $name ] ) ? (int) $state['sizes'][ $name ] : 0;
			// Anything a dead request wrote after the last saved state is cut off.
			if ( ! ftruncate( $files[ $name ], $keep ) || 0 !== fseek( $files[ $name ], $keep ) ) {
				throw new Archive_Exception( __( 'Cannot write the check\'s working files.', 'siteferry' ), Archive_Exception::IO );
			}
		}

		try {
			$hash = null;
			if ( null !== $state['current'] ) {
				$current = $state['current'];
				$hash    = Resumable_Hash::import(
					(array) $state['hash'],
					static function ( int $length, callable $feed ) use ( $file, $wpress, $current ): bool {
						// PHP 7.4: the bytes hashed so far are read again from the archive.
						$again = $wpress ? Wpress_Reader::open( $file, $current['start'] ) : Tar_Reader::open( $file, $current['start'] );
						try {
							while ( $length > 0 ) {
								$data = $again->read( (int) min( self::CHUNK, $length ) );
								if ( '' === $data ) {
									return false;
								}
								$feed( $data );
								$length -= strlen( $data );
							}
						} finally {
							$again->close();
						}
						return true;
					}
				);
			}

			for ( ;; ) {
				if ( null === $state['current'] ) {
					$entry = $reader->next();
					if ( null === $entry ) {
						$state['reader'] = null;
						return true;
					}
					++$state['entries'];
					$name = rtrim( str_replace( '\\', '/', (string) $entry->name ), '/' );
					if ( ! $wpress ) {
						$this->screen( $name, $entry, $state );
					}
					if ( ! $entry->is_file() ) {
						$state['reader'] = $reader->state();
						continue;
					}
					$state['current'] = array(
						'name'  => $name,
						'size'  => (int) $entry->size,
						'start' => $reader->state(),
					);
					$hash             = new Resumable_Hash();
				}

				$name = (string) $state['current']['name'];
				while ( $reader->remaining() > 0 ) {
					$data = $reader->read( self::CHUNK );
					$hash->update( $data );
					$state['content'] += strlen( $data );
					if ( ! $wpress && Manifest::CHECKSUMS === $name ) {
						Io::write_all( $files[ self::EXPECTED ], $data );
					} elseif ( ! $wpress && Manifest::ENTRY === $name && $hash->length() <= Backups::MAX_MANIFEST_BYTES ) {
						Io::write_all( $files[ self::MANIFEST ], $data );
					}
					if ( Manifest::DATABASE === $name ) {
						$state['tail'] = base64_encode( substr( base64_decode( (string) $state['tail'] ) . $data, -512 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					}
					$budget->consume( strlen( $data ) );
					if ( $reader->remaining() > 0 && $budget->exhausted() ) {
						$state['hash']   = $hash->export();
						$state['reader'] = $reader->state();
						$this->save_sizes( $state, $files );
						return false;
					}
				}

				// The entry is complete.
				$digest = $hash->hex();
				Io::write_all( $files[ self::COMPUTED ], ( null === $digest ? '-' : $digest ) . '  ' . $name . "\n" );
				++$state['files'];
				$state['bytes'] += (int) $state['current']['size'];
				if ( Manifest::DATABASE === $name ) {
					$state['database'] = (int) $state['current']['size'];
				}
				if ( 'package.json' === $name ) {
					$state['package'] = true;
				}
				$state['current'] = null;
				$state['hash']    = null;
				$state['reader']  = $reader->state();
				$this->save_sizes( $state, $files );
				if ( $budget->exhausted() ) {
					return false;
				}
			}
		} finally {
			foreach ( $files as $handle ) {
				fflush( $handle );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$state['offset'] = self::offset( $reader );
			$reader->close();
		}
	}

	/**
	 * How far into the file the reader is (a .tar.gz reader counts uncompressed bytes; there the
	 * start of the gzip member it is in is used).
	 *
	 * @param Tar_Reader|Wpress_Reader $reader Reader.
	 */
	private static function offset( $reader ): int {
		$state = $reader->state();
		if ( ! empty( $state['compressed'] ) && isset( $state['input']['member_offset'] ) ) {
			return (int) $state['input']['member_offset'];
		}
		return (int) $reader->position();
	}

	/**
	 * Notes entries a SiteFerry import would refuse (unsafe paths, links, anything outside the
	 * manifest, the dump, the checksum list and wp-content).
	 *
	 * @param string                       $name  Entry name.
	 * @param \SiteFerry\Archive\Tar_Entry $entry Entry.
	 * @param array                        $state In/out: check state.
	 */
	private function screen( string $name, $entry, array &$state ): void {
		if ( count( $state['refused'] ) >= 20 ) {
			return;
		}
		try {
			$path = Path_Guard::check( $name, false );
		} catch ( Archive_Exception $e ) {
			$state['refused'][] = Path_Guard::printable( $name );
			return;
		}
		if ( ( ! $entry->is_file() && ! $entry->is_dir() ) || ! Extract_Step::allowed( $path, $entry ) ) {
			$state['refused'][] = $path;
		}
	}

	/**
	 * Remembers how much of each working file belongs to the saved state.
	 *
	 * @param array                $state In/out: check state.
	 * @param array<string,resource> $files Open working files.
	 */
	private function save_sizes( array &$state, array $files ): void {
		foreach ( $files as $name => $handle ) {
			fflush( $handle );
			$state['sizes'][ $name ] = (int) ftell( $handle );
		}
	}

	/**
	 * Compares what was read with what the backup says about itself, records the outcome, and
	 * removes a downloaded copy.
	 *
	 * @param Job   $job   The job.
	 * @param array $state Check state.
	 * @throws Job_Exception If the copy is damaged.
	 */
	private function conclude( Job $job, array $state ): void {
		$problems = array();
		$listed   = 0;
		$unlisted = 0;
		$in_base  = 0;
		$base     = null;
		$tail     = (string) base64_decode( (string) $state['tail'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$lines    = explode( "\n", rtrim( $tail ) );
		$complete = Dumper::END_MARKER === trim( (string) end( $lines ) );

		if ( $state['wpress'] ) {
			if ( ! $state['package'] ) {
				$problems[] = __( 'it has no package.json', 'siteferry' );
			}
			if ( $state['database'] < 0 ) {
				$problems[] = __( 'it has no database.sql', 'siteferry' );
			}
		} else {
			if ( array() !== $state['refused'] ) {
				/* translators: %s: names of the entries */
				$problems[] = sprintf( __( 'it holds entries an import refuses: %s', 'siteferry' ), self::names( $state['refused'] ) );
			}
			$manifest = json_decode( (string) file_get_contents( $job->path( self::MANIFEST ) ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $manifest ) || Manifest::FORMAT !== ( isset( $manifest['format'] ) ? $manifest['format'] : null ) ) {
				$problems[] = __( 'its manifest (siteferry.json) is missing or damaged', 'siteferry' );
				$manifest   = null;
			}
			if ( $state['database'] < 0 ) {
				$problems[] = __( 'it has no database dump', 'siteferry' );
			} elseif ( ! $complete ) {
				$problems[] = __( 'its database dump is incomplete', 'siteferry' );
			} elseif ( null !== $manifest && isset( $manifest['database']['size'] ) && (int) $manifest['database']['size'] !== $state['database'] ) {
				$problems[] = sprintf( /* translators: 1: a number, 2: a number */ __( 'its database dump has %1$s bytes, the manifest says %2$s', 'siteferry' ), number_format( $state['database'] ), number_format( (int) $manifest['database']['size'] ) );
			}

			$expected = self::read_list( $job->path( self::EXPECTED ) );
			$computed = self::read_list( $job->path( self::COMPUTED ) );
			if ( null === $expected ) {
				$problems[] = __( 'its checksum list (siteferry.sha256) is missing or damaged', 'siteferry' );
			} else {
				$missing = array();
				$changed = array();
				// A changes-only backup lists the whole site; the files it does not hold are in its full backup.
				$base = \SiteFerry\Incremental::base_of( $manifest );
				foreach ( $expected as $path => $sum ) {
					if ( ! isset( $computed[ $path ] ) ) {
						if ( null === $base ) {
							$missing[] = $path;
						} else {
							++$in_base;
						}
					} elseif ( ! hash_equals( $sum, $computed[ $path ] ) ) {
						$changed[] = $path;
					}
				}
				if ( array() !== $missing ) {
					$problems[] = sprintf( /* translators: 1: a number, 2: a list of file names */ _n( '%1$d file of its checksum list is missing: %2$s', '%1$d files of its checksum list are missing: %2$s', count( $missing ), 'siteferry' ), count( $missing ), self::names( $missing ) );
				}
				if ( array() !== $changed ) {
					$problems[] = sprintf( /* translators: 1: a number, 2: a list of file names */ _n( '%1$d file does not match its checksum: %2$s', '%1$d files do not match their checksums: %2$s', count( $changed ), 'siteferry' ), count( $changed ), self::names( $changed ) );
				}
				$listed   = count( $expected ) - count( $missing ) - count( $changed ) - $in_base;
				$unlisted = count( array_diff( array_keys( (array) $computed ), array_keys( $expected ), array( Manifest::ENTRY, Manifest::CHECKSUMS ) ) );
			}
		}

		$name  = (string) $job->option( 'name' );
		$place = (string) $job->option( 'place' );
		$where = Check::where( $place );
		$size  = (int) filesize( self::file( $job ) );
		if ( '' !== (string) $job->option( 'source', '' ) ) {
			$job->delete_file( Fetch_Step::PART ); // The download was only for the check.
		}
		$this->cleanup( $job );

		if ( array() !== $problems ) {
			$this->damaged( $job, implode( '; ', $problems ) );
		}

		if ( $state['wpress'] ) {
			$summary = sprintf( /* translators: %s: a number */ _n( 'Read to its end: %s file. A .wpress file has no checksums to compare.', 'Read to its end: %s files. A .wpress file has no checksums to compare.', $state['files'], 'siteferry' ), number_format( $state['files'] ) );
		} else {
			$summary = sprintf( /* translators: %s: a number */ _n( 'The %s file matches its checksum; the database dump is complete.', 'All %s files match their checksums; the database dump is complete.', $listed, 'siteferry' ), number_format( $listed ) );
			if ( $unlisted > 0 ) {
				$summary .= sprintf( /* translators: %s: a number */ _n( ' %s file has no checksum (it changed while the backup was made).', ' %s files have no checksum (they changed while the backup was made).', $unlisted, 'siteferry' ), number_format( $unlisted ) );
			}
			if ( null !== $base && $in_base > 0 ) {
				/* translators: 1: a number of files, 2: name of the full backup */
				$summary .= ' ' . sprintf( _n( 'This is a changes-only backup: %1$s more file is in its full backup %2$s, which this check does not read.', 'This is a changes-only backup: %1$s more files are in its full backup %2$s, which this check does not read.', $in_base, 'siteferry' ), number_format( $in_base ), $base['name'] );
			}
		}
		Check::record( $name, $place, true, $size, $summary );
		$job->set_result(
			array(
				'checked' => array(
					'name'    => $name,
					'place'   => $place,
					'where'   => $where,
					'ok'      => true,
					'files'   => $state['files'],
					'size'    => $size,
					'summary' => $summary,
				),
			)
		);
		$job->set_progress( sprintf( /* translators: %s: where the backup is, such as "this server" or "Dropbox" */ __( 'The copy on %s is intact', 'siteferry' ), $where ), 1, 1 );
		$job->log( 'info', sprintf( 'The copy of %s on %s is intact. %s', $name, $where, $summary ) );
	}

	/**
	 * Records a damaged copy and fails the job.
	 *
	 * @param Job    $job  The job.
	 * @param string $what What is wrong.
	 * @throws Job_Exception Always.
	 */
	private function damaged( Job $job, string $what ): void {
		$name  = (string) $job->option( 'name' );
		$place = (string) $job->option( 'place' );
		$file  = self::file( $job );
		Check::record( $name, $place, false, is_file( $file ) ? (int) filesize( $file ) : 0, ucfirst( $what ) . '.' );
		$job->set_result(
			array(
				'checked' => array(
					'name'    => $name,
					'place'   => $place,
					'where'   => Check::where( $place ),
					'ok'      => false,
					'summary' => ucfirst( $what ) . '.',
				),
			)
		);
		throw new Job_Exception( sprintf( /* translators: 1: where it is stored, such as Dropbox, 2: the reason */ __( 'The copy on %1$s is damaged: %2$s.', 'siteferry' ), Check::where( $place ), $what ), Job_Exception::STEP );
	}

	/**
	 * Reads a "hash  path" list into path => hash; null if the file is missing or malformed.
	 *
	 * @param string $file List file.
	 * @return array<string,string>|null
	 */
	private static function read_list( string $file ): ?array {
		$handle = is_file( $file ) && filesize( $file ) > 0 ? fopen( $file, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return null;
		}
		$list = array();
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition, WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}
			if ( ! preg_match( '/^([0-9a-f]{64}|-) [ *](.+)$/', $line, $match ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return null;
			}
			$list[ $match[2] ] = $match[1];
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $list;
	}

	/**
	 * First few names and a count of the rest.
	 *
	 * @param string[] $names Names.
	 */
	private static function names( array $names ): string {
		$shown = implode( ', ', array_slice( $names, 0, self::SHOW ) );
		return count( $names ) > self::SHOW ? sprintf( /* translators: 1: a comma-separated list of names, 2: how many more are not listed */ _n( '%1$s and %2$d more', '%1$s and %2$d more', count( $names ) - self::SHOW, 'siteferry' ), $shown, count( $names ) - self::SHOW ) : $shown;
	}
}
