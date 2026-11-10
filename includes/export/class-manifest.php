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
	/** In a changes-only backup: the files a restore takes from its full backup, one path per line. */
	const BASE_LIST      = 'siteferry.base';

	/**
	 * The installed plugins or themes whose files are in the backup (not left out by its choices),
	 * with their names and versions, for the restore preview.
	 *
	 * @param Job    $job       Export job.
	 * @param string $kind      "plugins" or "themes" (their folder in wp-content).
	 * @param array  $installed id => [name, version]; a plugin's id is its file, a theme's its folder.
	 */
	private static function held( Job $job, string $kind, array $installed ): array {
		$rules = $job->option( 'exclusions' );
		if ( ! is_array( $rules ) || ! isset( $rules['paths'], $rules['patterns'], $rules['names'] ) ) {
			return $installed;
		}
		$exclusions = Exclusions::from_array( $rules );
		if ( $exclusions->excludes( $kind ) ) {
			return array();
		}
		$held = array();
		foreach ( $installed as $id => $item ) {
			$folder = false === strpos( (string) $id, '/' ) ? (string) $id : (string) strstr( (string) $id, '/', true );
			if ( ! $exclusions->excludes( $kind . '/' . $folder ) ) {
				$held[ $id ] = $item;
			}
		}
		return $held;
	}

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

		$manifest = array(
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
				'network'   => isset( $site['network'] ) ? $site['network'] : null,
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
			'plugins'        => array(
				'active'    => $site['active_plugins'],
				'installed' => self::held( $job, 'plugins', isset( $site['plugins_installed'] ) ? $site['plugins_installed'] : array() ),
			),
			'themes'         => array( 'installed' => self::held( $job, 'themes', isset( $site['themes_installed'] ) ? $site['themes_installed'] : array() ) ),
			'content'        => isset( $site['content'] ) ? $site['content'] : array(),
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

		// A changes-only backup: which full backup it is built on, what changed and what is gone.
		// "files" above describes the whole site; "changes" what this file holds itself.
		$base = $job->state( 'base' );
		if ( null !== $job->option( 'base' ) && array() !== $base ) {
			$manifest['format_version'] = \SiteFerry\Incremental::FORMAT_VERSION;
			$manifest['base']           = array(
				'name'        => (string) $base['name'],
				'created'     => (string) $base['created'],
				'fingerprint' => (string) $base['fingerprint'],
				'files'       => (int) $base['files'],
			);
			$manifest['changes']        = array(
				'files' => (int) $base['changed'],
				'bytes' => (int) $base['changed_bytes'],
			);
			$manifest['deleted']        = array_values( (array) $base['deleted'] );
		}
		return $manifest;
	}
}
