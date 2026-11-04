<?php
/**
 * Import step: check the backup against this site.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Archive\Io;
use SiteFerry\Backups;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the manifest, refuses backups this site cannot take, works out the replacements and
 * records a summary for the confirmation.
 */
final class Inspect_Step implements Step {

	/** Free space required beyond the estimate. */
	const SPACE_MARGIN = 104857600;

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused; this step is quick).
	 * @throws Job_Exception If the backup cannot be imported here.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$manifest = 'wpress' === $job->option( 'format' )
			? \SiteFerry\Wpress\Import_Step::manifest( $job )
			: Backups::manifest( $job->option( 'archive' ) );
		$site     = $job->option( 'site' );
		$warnings = array();

		if ( (int) $manifest['format_version'] > Manifest::FORMAT_VERSION ) {
			throw new Job_Exception( 'This backup was made by a newer version of SiteFerry. Update SiteFerry on this site first.', Job_Exception::STEP );
		}
		if ( ! empty( $manifest['site']['multisite'] ) ) {
			throw new Job_Exception( 'This backup comes from a multisite network, which this version of SiteFerry cannot import.', Job_Exception::STEP );
		}
		$prefix = (string) $manifest['database']['prefix'];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new Job_Exception( 'The backup has an invalid table prefix.', Job_Exception::STEP );
		}

		// A database from a newer WordPress may not work with this site's older WordPress files.
		if ( (int) $manifest['versions']['db_version'] > (int) $site['db_version'] ) {
			$message = sprintf(
				'The backup comes from WordPress %s, which is newer than WordPress %s on this site. Update WordPress here first.',
				$manifest['versions']['wordpress'],
				$site['wordpress']
			);
			if ( ! $job->option( 'force' ) ) {
				throw new Job_Exception( $message . ' (To import anyway, use the force option.)', Job_Exception::STEP );
			}
			$warnings[] = $message;
		}
		if ( version_compare( self::minor( $manifest['versions']['php'] ), self::minor( PHP_VERSION ), '>' ) ) {
			$warnings[] = sprintf( 'The backup comes from PHP %s; this site runs PHP %s. Some plugins may need the newer PHP.', $manifest['versions']['php'], PHP_VERSION );
		}

		foreach ( $manifest['database']['tables'] as $table ) {
			$suffix = substr( (string) $table['name'], strlen( $prefix ) );
			foreach ( array( $job->option( 'temp_prefix' ), $job->option( 'old_prefix' ), $site['prefix'] ) as $new_prefix ) {
				if ( strlen( $new_prefix . $suffix ) > 64 ) {
					throw new Job_Exception( sprintf( 'The table name %s would be too long with this site\'s prefix.', $table['name'] ), Job_Exception::STEP );
				}
			}
		}

		$needed = (int) $manifest['files']['bytes'] + 2 * (int) $manifest['database']['size'] + self::SPACE_MARGIN;
		$root   = Storage::root();
		$free   = function_exists( 'disk_free_space' ) ? Io::quiet(
			static function () use ( $root ) {
				return disk_free_space( $root );
			}
		) : false;
		if ( false !== $free && $free < $needed ) {
			throw new Job_Exception( sprintf( 'Not enough disk space: the import needs about %s, but only %s is free.', size_format( $needed, 1 ), size_format( $free, 1 ) ), Job_Exception::STEP );
		}

		$target = self::target( $site, (string) $job->option( 'replace_url' ) );
		$source = array(
			'home'           => $manifest['site']['home'],
			'siteurl'        => $manifest['site']['siteurl'],
			'wp_content_url' => $manifest['urls']['wp_content_url'],
			'uploads_url'    => $manifest['urls']['uploads_url'],
			'abspath'        => $manifest['paths']['abspath'],
			'wp_content_dir' => $manifest['paths']['wp_content_dir'],
			'uploads_dir'    => $manifest['paths']['uploads_dir'],
		);
		$pairs  = Replacements::build( $source, $target, $job->option( 'replace', array() ) );

		$job->set_state(
			'inspect',
			array(
				'source'    => $source,
				'target'    => $target,
				'prefix'    => $prefix,
				'charset'   => (string) $manifest['database']['charset'],
				'tables'    => array_column( $manifest['database']['tables'], 'name' ),
				'rows'      => (int) $manifest['database']['rows'],
				'files'     => (int) $manifest['files']['files'],
				'bytes'     => (int) $manifest['files']['bytes'],
				'db_size'   => (int) $manifest['database']['size'],
				'pairs'     => $pairs,
				'plugins'   => $manifest['paths']['plugins_dir'],
				'themes'    => $manifest['paths']['themes_dir'],
				'versions'  => $manifest['versions'],
				'created'   => $manifest['created'],
			)
		);
		foreach ( $warnings as $warning ) {
			$job->warn( $warning );
		}

		$job->set_result(
			array(
				'inspection' => array(
					'backup'       => basename( (string) $job->option( 'archive' ) ),
					'created'      => $manifest['created'],
					'from'         => $source['home'],
					'to'           => $target['home'],
					'wordpress'    => $manifest['versions']['wordpress'],
					'php'          => $manifest['versions']['php'],
					'mysql'        => $manifest['versions']['mysql'],
					'tables'       => count( $manifest['database']['tables'] ),
					'files'        => (int) $manifest['files']['files'],
					'bytes'        => (int) $manifest['files']['bytes'],
					'replacements' => count( $pairs ),
					'prefix'       => $prefix . ( $prefix === $site['prefix'] ? '' : ' (becomes ' . $site['prefix'] . ')' ),
					'warnings'     => $warnings,
				),
			)
		);
		$job->set_progress( 'Backup checked' );
		$job->log( 'info', sprintf( 'Backup checked: %s from %s, %d replacement pairs.', basename( (string) $job->option( 'archive' ) ), $source['home'], count( $pairs ) ) );
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
	 * Addresses and paths the imported site will have.
	 *
	 * The site keeps its own address unless a new one is given, and always its own folders.
	 *
	 * @param array  $site        This site's facts (Site_Info).
	 * @param string $replace_url New address, or '' to keep this site's address.
	 */
	public static function target( array $site, string $replace_url ): array {
		$target = array(
			'home'           => $site['home'],
			'siteurl'        => $site['siteurl'],
			'wp_content_url' => $site['wp_content_url'],
			'uploads_url'    => $site['uploads_url'],
			'abspath'        => $site['abspath'],
			'wp_content_dir' => $site['wp_content_dir'],
			'uploads_dir'    => $site['uploads_dir'],
		);
		if ( '' !== $replace_url ) {
			foreach ( array( 'home', 'siteurl', 'wp_content_url', 'uploads_url' ) as $key ) {
				$target[ $key ] = 0 === strpos( $target[ $key ], $site['home'] )
					? $replace_url . substr( $target[ $key ], strlen( $site['home'] ) )
					: $target[ $key ];
			}
		}
		return $target;
	}

	/**
	 * "8.2" from "8.2.29".
	 *
	 * @param string $version Version.
	 */
	private static function minor( string $version ): string {
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}
}
