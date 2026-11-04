<?php
/**
 * Import step for .wpress backups: read the package and convert the database dump.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Runs first in imports of .wpress backups, so the usual steps can follow:
 *
 * 1. scan:      walk the headers; read package.json; find database.sql; count the files to unpack.
 * 2. copy:      copy database.sql out of the archive, checking its CRC-32.
 * 3. translate: rewrite it as a SiteFerry dump (Sql_In).
 * 4. manifest:  write a SiteFerry manifest built from package.json and the dump.
 *
 * Inspect then reads that manifest; Extract unpacks the files through Reader (checking each file's
 * CRC-32) and calls stage() to put the dump, the manifest and a checksum list into staging.
 */
final class Import_Step implements Step {

	const STEP = 'import.wpress';

	/** Job files. */
	const MANIFEST = 'wpress-manifest.json';
	const SOURCE   = 'wpress-source.sql';
	const DUMP     = 'wpress-database.sql';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the backup cannot be imported.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$state = $job->state( self::STEP ) + array(
			'phase'   => 'scan',
			'reader'  => null,
			'files'   => 0,
			'bytes'   => 0,
			'package' => null,
			'created' => 0,
			'dump'    => null,
			'copy'    => array(
				'done' => 0,
				'crc'  => 0,
			),
			'sql'     => null,
		);
		$archive = (string) $job->option( 'archive' );

		try {
			if ( 'scan' === $state['phase'] && ! $this->scan( $archive, $state, $budget ) ) {
				return $this->pause( $job, $state, sprintf( 'Reading the .wpress backup: %s files so far', number_format( $state['files'] ) ), 0, 0 );
			}
			if ( 'copy' === $state['phase'] ) {
				$state['copy'] = Wpress::copy_range( $archive, $state['dump']['offset'], $state['dump']['size'], $job->path( self::SOURCE ), $state['copy'], $budget );
				if ( $state['copy']['done'] < $state['dump']['size'] ) {
					return $this->pause( $job, $state, 'Reading the database from the .wpress backup', $state['copy']['done'], $state['dump']['size'] );
				}
				if ( null !== $state['dump']['crc'] && $state['dump']['crc'] !== $state['copy']['crc'] ) {
					throw new Job_Exception( 'The database in the .wpress backup is damaged (its CRC-32 does not match).', Job_Exception::STEP );
				}
				$state['phase'] = 'translate';
			}
			if ( 'translate' === $state['phase'] ) {
				$sql  = new Sql_In( $job->path( self::SOURCE ), $job->path( self::DUMP ), self::prefix( $state['package'] ), $state['sql'], Package::options( $state['package'] ) );
				$done = $sql->run( $budget );
				$state['sql'] = $sql->state();
				if ( ! $done ) {
					return $this->pause( $job, $state, 'Converting the database', (int) $state['sql']['offset'], (int) $state['dump']['size'] );
				}
				$state['phase'] = 'manifest';
			}
		} catch ( Archive_Exception $e ) {
			throw new Job_Exception( $e->getMessage(), Job_Exception::STEP );
		}

		$tables = array();
		foreach ( $state['sql']['tables'] as $name => $table ) {
			$tables[ $name ] = $table['rows'];
		}
		$manifest = Package::to_manifest(
			$state['package'],
			array(
				'created'    => $state['created'],
				'files'      => $state['files'],
				'bytes'      => $state['bytes'],
				'size'       => (int) filesize( $job->path( self::DUMP ) ),
				'tables'     => $tables,
				'db_version' => $state['sql']['db_version'],
				'charset'    => $state['sql']['charset'],
				'collate'    => $state['sql']['collate'],
			)
		);
		$handle = Io::open_or_fail( $job->path( self::MANIFEST ), 'wb' );
		Io::write_all( $handle, (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$job->delete_file( self::SOURCE );

		if ( $state['sql']['stray'] > 0 ) {
			$job->warn( sprintf( '%d database statements contain the placeholder prefix inside a value; those values were left as they are.', $state['sql']['stray'] ) );
		}
		$state['phase']  = 'done';
		$state['reader'] = null;
		$job->set_state( self::STEP, $state );
		$job->set_progress( 'The .wpress backup was read' );
		$job->log(
			'info',
			sprintf(
				'.wpress backup read: %s files (%s), %d tables, made with %s from %s.',
				number_format( $state['files'] ),
				size_format( $state['bytes'], 1 ),
				count( $tables ),
				$manifest['generator']['from'],
				$manifest['site']['home']
			)
		);
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		foreach ( array( self::SOURCE, self::DUMP, self::MANIFEST ) as $file ) {
			$job->delete_file( $file );
		}
	}

	/**
	 * The manifest built for a .wpress import.
	 *
	 * @param Job $job Import job after this step.
	 * @throws Job_Exception If it is missing.
	 */
	public static function manifest( Job $job ): array {
		$data = is_file( $job->path( self::MANIFEST ) ) ? json_decode( (string) file_get_contents( $job->path( self::MANIFEST ) ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			throw new Job_Exception( 'The converted .wpress backup is missing; start the import again.', Job_Exception::STEP );
		}
		return $data;
	}

	/**
	 * Puts the converted dump, the manifest and a checksum list into staging after the files are
	 * unpacked, so the following steps find what a SiteFerry backup has.
	 *
	 * The checksum list is made from the unpacked files themselves: each file's CRC-32 was already
	 * checked against the archive while it was unpacked.
	 *
	 * @param Job    $job     Import job.
	 * @param string $staging Staging folder.
	 * @param string $sums    extracted.sha256 of the job.
	 * @throws Job_Exception If a file cannot be written.
	 */
	public static function stage( Job $job, string $staging, string $sums ): void {
		$dump = $staging . '/' . Manifest::DATABASE;
		if ( ! is_file( $dump ) ) {
			$from  = $job->path( self::DUMP );
			$moved = Io::quiet(
				static function () use ( $from, $dump ) {
					return rename( $from, $dump ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
			);
			if ( ! $moved ) {
				throw new Job_Exception( 'Cannot move the converted database into the staging folder.', Job_Exception::STEP );
			}
		}
		if ( ! copy( $job->path( self::MANIFEST ), $staging . '/' . Manifest::ENTRY ) ) {
			throw new Job_Exception( 'Cannot write the manifest into the staging folder.', Job_Exception::STEP );
		}

		$in  = Io::open_or_fail( $sums, 'rb' );
		$out = Io::open_or_fail( $staging . '/' . Manifest::CHECKSUMS, 'wb' );
		try {
			while ( false !== ( $line = fgets( $in ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition, WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				if ( ! preg_match( '/^([0-9a-f]{64}|-)  (.+)$/', rtrim( $line, "\r\n" ), $match ) ) {
					continue;
				}
				$hash = '-' === $match[1] ? (string) hash_file( 'sha256', $staging . '/' . $match[2] ) : $match[1];
				Io::write_all( $out, $hash . '  ' . $match[2] . "\n" );
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Walks the archive's headers.
	 *
	 * @param string       $archive Archive.
	 * @param array        $state   Step state (changed).
	 * @param Slice_Budget $budget  Work limit.
	 * @return bool True when the whole archive was walked.
	 * @throws Job_Exception If the backup cannot be imported.
	 */
	private function scan( string $archive, array &$state, Slice_Budget $budget ): bool {
		$reader = Reader::open( $archive, $state['reader'] );
		try {
			while ( true ) {
				$entry = $reader->next();
				if ( null === $entry ) {
					break;
				}
				$raw      = $reader->raw();
				$relative = $entry->name;
				if ( 'package.json' === $relative ) {
					if ( $entry->size > Package::MAX_BYTES ) {
						throw new Job_Exception( 'The package.json of this .wpress backup is too large.', Job_Exception::STEP );
					}
					$state['package'] = Package::parse( $reader->read( $entry->size ) );
					$state['created'] = $entry->mtime;
					if ( Package::encrypted( $state['package'] ) ) {
						throw new Job_Exception( 'This .wpress backup is protected with a password, and SiteFerry cannot open password-protected .wpress backups yet. Make the backup again without a password.', Job_Exception::STEP );
					}
				} elseif ( 'database.sql' === $relative ) {
					$state['dump'] = array(
						'offset' => $reader->position(),
						'size'   => $entry->size,
						'crc'    => $raw['crc'],
					);
				} elseif ( 'multisite.json' === $relative ) {
					throw new Job_Exception( 'This .wpress backup comes from a multisite network, which this version of SiteFerry cannot import.', Job_Exception::STEP );
				} elseif ( ! Wpress::skipped( $relative ) ) {
					++$state['files'];
					$state['bytes'] += $entry->size;
				}
				$budget->consume( 4096 );
				if ( $budget->exhausted() ) {
					$state['reader'] = $reader->state();
					return false;
				}
			}
		} finally {
			$reader->close();
		}

		if ( null === $state['package'] ) {
			throw new Job_Exception( 'This file is not a .wpress backup (it has no package.json).', Job_Exception::STEP );
		}
		if ( null === $state['dump'] ) {
			throw new Job_Exception( 'This .wpress backup has no database (it was made with "Exclude database"), so SiteFerry cannot import it.', Job_Exception::STEP );
		}
		self::prefix( $state['package'] );
		$state['phase']  = 'copy';
		$state['reader'] = null;
		return true;
	}

	/**
	 * The table prefix from package.json.
	 *
	 * @param array $package Parsed package.json.
	 * @throws Job_Exception If it is missing or unusable.
	 */
	private static function prefix( array $package ): string {
		$prefix = isset( $package['Database']['Prefix'] ) && is_string( $package['Database']['Prefix'] ) ? $package['Database']['Prefix'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', $prefix ) ) {
			throw new Job_Exception( 'The .wpress backup does not state a usable table prefix.', Job_Exception::STEP );
		}
		return $prefix;
	}

	/**
	 * Saves progress and ends the slice.
	 *
	 * @param Job    $job     The job.
	 * @param array  $state   Step state.
	 * @param string $message Progress message.
	 * @param int    $done    Done.
	 * @param int    $total   Total.
	 */
	private function pause( Job $job, array $state, string $message, int $done, int $total ): bool {
		$job->set_state( self::STEP, $state );
		$job->set_progress( $message, $done, $total );
		return false;
	}
}
