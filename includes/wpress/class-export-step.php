<?php
/**
 * Export step: turn the finished SiteFerry backup into a .wpress backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Runs after export.finish when the export's format is "wpress":
 *
 * 1. head:      read the manifest and the start of the dump from the TAR.
 * 2. dump:      copy the dump out of the TAR.
 * 3. translate: rewrite it for .wpress importers (Sql_Out).
 * 4. files:     write package.json and every file into <name>.wpress.part.
 * 5. database:  write the dump as database.sql, last, like the format's own backups.
 * 6. seal:      compute the archive CRC-32 and write the closing header.
 * 7. Rename the .part file, delete the TAR and point the job's result at the .wpress file.
 */
final class Export_Step implements Step {

	const STEP = 'export.wpress';

	/** Job files. */
	const SOURCE = 'wpress-in.sql';
	const DUMP   = 'wpress-out.sql';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the backup cannot be converted.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$state = $job->state( self::STEP ) + array(
			'phase'   => 'head',
			'reader'  => null,
			'writer'  => null,
			'package' => null,
			'prefix'  => '',
			'created' => 0,
			'files'   => 0,
			'sql'     => null,
			'db'      => 0,
		);
		$tar   = self::tar( $job );
		$part  = self::target( $job ) . '.part';

		try {
			if ( 'head' === $state['phase'] ) {
				$this->head( $tar, $job, $state );
			}
			if ( 'dump' === $state['phase'] && ! $this->dump( $tar, $job, $state, $budget ) ) {
				return $this->pause( $job, $state, 'Preparing the .wpress database' );
			}
			if ( 'translate' === $state['phase'] ) {
				$sql          = new Sql_Out( $job->path( self::SOURCE ), $job->path( self::DUMP ), $state['prefix'], $state['sql'] );
				$done         = $sql->run( $budget );
				$state['sql'] = $sql->state();
				if ( ! $done ) {
					return $this->pause( $job, $state, 'Preparing the .wpress database' );
				}
				$job->delete_file( self::SOURCE );
				$state['phase'] = 'files';
			}
			if ( 'files' === $state['phase'] && ! $this->files( $tar, $part, $state, $budget ) ) {
				return $this->pause( $job, $state, sprintf( 'Writing the .wpress file: %s files', number_format( $state['files'] ) ) );
			}
			if ( 'database' === $state['phase'] && ! $this->database( $job, $part, $state, $budget ) ) {
				return $this->pause( $job, $state, 'Writing the .wpress file: database' );
			}
			if ( 'seal' === $state['phase'] ) {
				$writer          = Writer::resume( $part, $state['writer'] );
				$sealed          = $writer->seal( $budget );
				$state['writer'] = $writer->checkpoint();
				$writer->close();
				if ( ! $sealed ) {
					return $this->pause( $job, $state, 'Checking the .wpress file' );
				}
				$state['phase'] = 'publish';
			}
		} catch ( Archive_Exception $e ) {
			throw new Job_Exception( $e->getMessage(), Job_Exception::STEP );
		}

		$final = self::target( $job );
		if ( ! is_file( $final ) ) {
			$renamed = Io::quiet(
				static function () use ( $part, $final ) {
					return rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
			);
			if ( ! $renamed ) {
				throw new Job_Exception( 'Cannot rename the finished .wpress backup.', Job_Exception::STEP );
			}
		}
		Io::quiet(
			static function () use ( $tar ) {
				return ! is_file( $tar ) || unlink( $tar ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		);
		$job->delete_file( self::DUMP );
		if ( $job->option( 'save_labels', false ) ) {
			\SiteFerry\Labels::rename( (string) $job->option( 'archive' ), basename( $final ) );
		}

		$result         = $job->result();
		$result['file'] = $final;
		$result['name'] = basename( $final );
		$result['size'] = (int) filesize( $final );
		$job->set_result( $result );
		$state['phase'] = 'done';
		$job->set_state( self::STEP, $state );
		$job->set_progress( 'The .wpress backup is ready', 1, 1 );
		$job->log( 'info', sprintf( '.wpress backup written: %s (%s).', basename( $final ), size_format( $result['size'], 1 ) ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$part = self::target( $job ) . '.part';
		Io::quiet(
			static function () use ( $part ) {
				return ! is_file( $part ) || unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		);
		$job->delete_file( self::SOURCE );
		$job->delete_file( self::DUMP );
	}

	/**
	 * The .wpress name for an export's TAR name.
	 *
	 * @param string $archive TAR name.
	 */
	public static function name_for( string $archive ): string {
		return (string) preg_replace( '/\.tar(\.gz)?$/', '', $archive ) . Wpress::EXTENSION;
	}

	/**
	 * The TAR made by the export.
	 *
	 * @param Job $job Export job.
	 */
	private static function tar( Job $job ): string {
		return $job->option( 'output_dir' ) . '/' . $job->option( 'archive' );
	}

	/**
	 * The .wpress file to make.
	 *
	 * @param Job $job Export job.
	 */
	private static function target( Job $job ): string {
		return $job->option( 'output_dir' ) . '/' . self::name_for( (string) $job->option( 'archive' ) );
	}

	/**
	 * Reads the manifest and moves the reader to the dump.
	 *
	 * @param string $tar   TAR.
	 * @param Job    $job   The job.
	 * @param array  $state Step state (changed).
	 * @throws Job_Exception If the TAR does not start as a SiteFerry backup does.
	 */
	private function head( string $tar, Job $job, array &$state ): void {
		$reader = Tar_Reader::open( $tar );
		try {
			$entry = $reader->next();
			if ( null === $entry || Manifest::ENTRY !== $entry->name ) {
				throw new Job_Exception( 'The backup has no manifest.', Job_Exception::STEP );
			}
			$manifest = json_decode( $reader->read( $entry->size ), true );
			$entry    = $reader->next();
			if ( ! is_array( $manifest ) || null === $entry || Manifest::DATABASE !== $entry->name ) {
				throw new Job_Exception( 'The backup does not start with its manifest and database.', Job_Exception::STEP );
			}
			$state['reader'] = $reader->state();
		} finally {
			$reader->close();
		}
		$state['package'] = Package::from_manifest( $manifest );
		$state['prefix']  = (string) $manifest['database']['prefix'];
		$state['created'] = $job->created();
		$state['phase']   = 'dump';
		$job->delete_file( self::SOURCE );
	}

	/**
	 * Copies the dump out of the TAR.
	 *
	 * @param string       $tar    TAR.
	 * @param Job          $job    The job.
	 * @param array        $state  Step state (changed).
	 * @param Slice_Budget $budget Work limit.
	 */
	private function dump( string $tar, Job $job, array &$state, Slice_Budget $budget ): bool {
		$reader  = Tar_Reader::open( $tar, $state['reader'] );
		$entry   = $reader->entry();
		$written = $entry->size - $reader->remaining();
		$out     = Io::open_or_fail( $job->path( self::SOURCE ), 'c+b' );
		try {
			if ( ! ftruncate( $out, $written ) || 0 !== fseek( $out, $written ) ) {
				throw new Archive_Exception( 'Cannot continue copying the database.', Archive_Exception::IO );
			}
			$done            = $reader->copy_to( $out, $budget );
			$state['reader'] = $reader->state();
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$reader->close();
		}
		if ( $done ) {
			$state['phase'] = 'translate';
		}
		return $done;
	}

	/**
	 * Writes package.json and the files.
	 *
	 * @param string       $tar    TAR.
	 * @param string       $part   .wpress being written.
	 * @param array        $state  Step state (changed).
	 * @param Slice_Budget $budget Work limit.
	 */
	private function files( string $tar, string $part, array &$state, Slice_Budget $budget ): bool {
		if ( null === $state['writer'] ) {
			$writer = Writer::create( $part );
			$writer->add_string( 'package.json', '.', (string) wp_json_encode( $state['package'], JSON_UNESCAPED_SLASHES ), (int) $state['created'] );
		} else {
			$writer = Writer::resume( $part, $state['writer'] );
		}
		$reader = Tar_Reader::open( $tar, $state['reader'] );
		$done   = false;
		try {
			while ( true ) {
				while ( $writer->has_open_entry() ) {
					$data = $reader->read( (int) min( Reader::CHUNK, $writer->remaining() ) );
					if ( '' === $data ) {
						throw new Archive_Exception( 'The backup ended in the middle of a file.', Archive_Exception::TRUNCATED );
					}
					$writer->write( $data );
					$budget->consume( strlen( $data ) );
					if ( $writer->has_open_entry() && $budget->exhausted() ) {
						break 2;
					}
				}
				if ( $budget->exhausted() ) {
					break;
				}
				$entry = $reader->next();
				if ( null === $entry ) {
					$done = true;
					break;
				}
				if ( ! $entry->is_file() || 0 !== strpos( $entry->name, 'wp-content/' ) ) {
					continue; // Folders, and the checksum list (the .wpress has CRCs instead).
				}
				$relative = substr( $entry->name, strlen( 'wp-content/' ) );
				$slash    = strrpos( $relative, '/' );
				$folder   = false === $slash ? '.' : substr( $relative, 0, $slash );
				$writer->begin( false === $slash ? $relative : substr( $relative, $slash + 1 ), $folder, $entry->size, $entry->mtime );
				++$state['files'];
				$budget->consume( 4096 );
			}
			$state['reader'] = $reader->state();
			$state['writer'] = $writer->checkpoint();
		} finally {
			$reader->close();
			$writer->close();
		}
		if ( $done ) {
			$state['phase'] = 'database';
		}
		return $done;
	}

	/**
	 * Writes the converted dump as database.sql.
	 *
	 * @param Job          $job    The job.
	 * @param string       $part   .wpress being written.
	 * @param array        $state  Step state (changed).
	 * @param Slice_Budget $budget Work limit.
	 */
	private function database( Job $job, string $part, array &$state, Slice_Budget $budget ): bool {
		$writer = Writer::resume( $part, $state['writer'] );
		$source = $job->path( self::DUMP );
		$in     = Io::open_or_fail( $source, 'rb' );
		try {
			if ( ! $writer->has_open_entry() && 0 === (int) $state['db'] ) {
				$writer->begin( Manifest::DATABASE, '.', (int) filesize( $source ), (int) $state['created'] );
			}
			fseek( $in, (int) $state['db'] );
			while ( $writer->has_open_entry() ) {
				$data = (string) fread( $in, (int) min( Reader::CHUNK, $writer->remaining() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( '' === $data ) {
					throw new Archive_Exception( 'The converted database is shorter than expected.', Archive_Exception::IO );
				}
				$writer->write( $data );
				$state['db'] += strlen( $data );
				$budget->consume( strlen( $data ) );
				if ( $writer->has_open_entry() && $budget->exhausted() ) {
					break;
				}
			}
			$state['writer'] = $writer->checkpoint();
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$writer->close();
		}
		if ( $writer->has_open_entry() ) {
			return false;
		}
		$state['phase'] = 'seal';
		return true;
	}

	/**
	 * Saves progress and ends the slice.
	 *
	 * @param Job    $job     The job.
	 * @param array  $state   Step state.
	 * @param string $message Progress message.
	 */
	private function pause( Job $job, array $state, string $message ): bool {
		$job->set_state( self::STEP, $state );
		$job->set_progress( $message );
		return false;
	}
}
