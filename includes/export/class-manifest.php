<?php
/**
 * The siteferry.json manifest.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

use SiteFerry\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the manifest, the first entry of every backup.
 *
 * It is written after the scan and database dump, so it can state sizes and table lists, but
 * before the files, so an importer can show it without reading the whole archive. File
 * checksums are only known at the end and live in the last entry, siteferry.sha256.
 */
final class Manifest {

	const FORMAT         = 'siteferry-backup';
	const FORMAT_VERSION = 1;
	const ENTRY          = 'siteferry.json';
	const CHECKSUMS      = 'siteferry.sha256';
	const DATABASE       = 'database.sql';

	/**
	 * Manifest data for an export job.
	 *
	 * @param Job $job Export job after its scan and database steps.
	 * @return array
	 */
	public static function build( Job $job ): array {
		$site     = $job->option( 'site' );
		$scan     = $job->state( 'scan' );
		$database = $job->state( 'database' );

		// Rows written per table; a job begun by an older version only has the server's estimates.
		$tables = array();
		$exact  = true;
		foreach ( $database['tables'] as $table ) {
			$exact   = $exact && isset( $table['dumped'] );
			$tables[] = array(
				'name' => $table['name'],
				'rows' => isset( $table['dumped'] ) ? (int) $table['dumped'] : (int) $table['rows'],
			);
		}

		$options = array();
		foreach ( array_keys( Export::DEFAULTS ) as $key ) {
			$options[ $key ] = $job->option( $key );
		}

		return array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'generator'      => array(
				'name'    => 'SiteFerry',
				'version' => SITEFERRY_VERSION,
			),
			'created'        => gmdate( 'Y-m-d\TH:i:s\Z', $job->created() ),
			'site'           => array(
				'home'      => $site['home'],
				'siteurl'   => $site['siteurl'],
				'name'      => $site['name'],
				'multisite' => $site['multisite'],
			),
			'paths'          => array(
				'abspath'        => $site['abspath'],
				'wp_content_dir' => $site['wp_content_dir'],
				'plugins_dir'    => $site['plugins_dir'],
				'themes_dir'     => $site['themes_dir'],
				'uploads_dir'    => $site['uploads_dir'],
				'os'             => $site['os'],
			),
			'urls'           => array(
				'wp_content_url' => $site['wp_content_url'],
				'uploads_url'    => $site['uploads_url'],
			),
			'versions'       => array(
				'wordpress'  => $site['wordpress'],
				'db_version' => $site['db_version'],
				'php'        => $site['php'],
				'mysql'      => $site['mysql'],
			),
			'database'       => array(
				'file'    => self::DATABASE,
				'prefix'  => $site['prefix'],
				'charset' => $site['charset'],
				'collate' => $site['collate'],
				'size'    => $database['size'],
				'rows'       => $database['rows_done'],
				'rows_exact' => $exact,
				'tables'     => $tables,
			),
			'files'          => array(
				'root'       => 'wp-content',
				'files'      => $scan['files'],
				'folders'    => $scan['dirs'],
				'bytes'      => $scan['bytes'],
				'exclusions' => $job->option( 'exclusions' ),
			),
			'plugins'        => array( 'active' => $site['active_plugins'] ),
			'theme'          => array(
				'stylesheet' => $site['stylesheet'],
				'template'   => $site['template'],
			),
			'options'        => $options,
			'checksums'      => array(
				'file'      => self::CHECKSUMS,
				'algorithm' => 'sha256',
				'format'    => 'sha256sum',
			),
		);
	}
}
