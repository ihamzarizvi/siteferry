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
			: Backups::manifest( $job->option( 'archive' ), \SiteFerry\Security\Archive_Keys::key( $job ) );
		$site     = $job->option( 'site' );
		$warnings = array();

		if ( (int) $manifest['format_version'] > Manifest::FORMAT_VERSION ) {
			throw new Job_Exception( __( 'This backup was made by a newer version of SiteFerry. Update SiteFerry on this site first.', 'siteferry' ), Job_Exception::STEP );
		}
		// Networks: a whole network goes into a network of the same kind, and nothing else does.
		$from_network = isset( $manifest['site']['network'] ) && is_array( $manifest['site']['network'] ) ? $manifest['site']['network'] : null;
		$to_network   = isset( $site['network'] ) && is_array( $site['network'] ) ? $site['network'] : null;
		if ( ! empty( $manifest['site']['multisite'] ) && null === $from_network ) {
			throw new Job_Exception( __( 'This backup comes from a multisite network but was made by an older SiteFerry that does not record the network; it cannot be restored.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( null !== $from_network && null === $to_network ) {
			throw new Job_Exception( __( 'This backup is of a whole multisite network. Restore it into a multisite network (of the same kind) instead of a single site.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( null === $from_network && null !== $to_network ) {
			throw new Job_Exception( __( 'This site is a multisite network; it can only take a backup of a whole network. Restoring a single site into a network is not supported yet.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( null !== $from_network ) {
			if ( (bool) $from_network['subdomain_install'] !== (bool) $to_network['subdomain_install'] ) {
				throw new Job_Exception( sprintf( /* translators: 1: "subdomains" or "subfolders", 2: "subdomains" or "subfolders" */ __( 'The backup\'s network uses %1$s for its sites, this network uses %2$s; they must be the same.', 'siteferry' ), $from_network['subdomain_install'] ? __( 'subdomains', 'siteferry' ) : __( 'subfolders', 'siteferry' ), $to_network['subdomain_install'] ? __( 'subdomains', 'siteferry' ) : __( 'subfolders', 'siteferry' ) ), Job_Exception::STEP );
			}
			if ( '' !== (string) $job->option( 'replace_url' ) ) {
				throw new Job_Exception( __( 'A network keeps the address set in wp-config.php; a new address cannot be set for it here.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( Import::partial( $job ) ) {
				throw new Job_Exception( __( 'A network is restored whole; restoring only parts of it is not supported yet.', 'siteferry' ), Job_Exception::STEP );
			}
		}
		$prefix = (string) $manifest['database']['prefix'];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
			throw new Job_Exception( __( 'The backup has an invalid table prefix.', 'siteferry' ), Job_Exception::STEP );
		}

		// A database from a newer WordPress may not work with this site's older WordPress files.
		if ( (int) $manifest['versions']['db_version'] > (int) $site['db_version'] ) {
			$message = sprintf(
				/* translators: 1: a WordPress version, such as 6.8, 2: the WordPress version of this site */
				__( 'The backup comes from WordPress %1$s, which is newer than WordPress %2$s on this site. Update WordPress here first.', 'siteferry' ),
				$manifest['versions']['wordpress'],
				$site['wordpress']
			);
			if ( ! $job->option( 'force' ) ) {
				// Only WP-CLI has a way to import anyway (its --force flag); its output stays English.
				throw new Job_Exception( $message . ( defined( 'WP_CLI' ) && WP_CLI ? ' (To import anyway, use --force.)' : '' ), Job_Exception::STEP );
			}
			$warnings[] = $message;
		}
		if ( version_compare( self::minor( $manifest['versions']['php'] ), self::minor( PHP_VERSION ), '>' ) ) {
			$warnings[] = sprintf(
				/* translators: 1: a PHP version, such as 8.3, 2: the PHP version of this server */
				__( 'The backup comes from PHP %1$s; this site runs PHP %2$s. Some plugins may need the newer PHP.', 'siteferry' ),
				$manifest['versions']['php'],
				PHP_VERSION
			);
		}

		foreach ( $manifest['database']['tables'] as $table ) {
			$suffix = substr( (string) $table['name'], strlen( $prefix ) );
			foreach ( array( $job->option( 'temp_prefix' ), $job->option( 'old_prefix' ), $site['prefix'] ) as $new_prefix ) {
				if ( strlen( $new_prefix . $suffix ) > 64 ) {
					throw new Job_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'The table name %s would be too long with this site\'s prefix.', 'siteferry' ), $table['name'] ), Job_Exception::STEP );
				}
			}
		}

		// A partial restore: the chosen tables must be in the backup; something must be chosen; a
		// new address needs the options table (where it is stored).
		$names         = array_column( $manifest['database']['tables'], 'name' );
		$chosen_tables = (array) $job->option( 'restore_tables', array() );
		foreach ( $chosen_tables as $table ) {
			if ( ! in_array( $table, $names, true ) ) {
				throw new Job_Exception( sprintf( /* translators: %s: a table name */ __( 'The backup has no table "%s".', 'siteferry' ), $table ), Job_Exception::STEP );
			}
		}
		$restore_db    = (bool) $job->option( 'restore_database', true );
		$restore_files = (bool) $job->option( 'restore_files', true );
		if ( ! $restore_db && ! $restore_files ) {
			throw new Job_Exception( __( 'Choose something to restore: the database, the files, or both.', 'siteferry' ), Job_Exception::STEP );
		}
		$options_restored = $restore_db && ( array() === $chosen_tables || in_array( $prefix . 'options', $chosen_tables, true ) );
		if ( ! $options_restored && '' !== (string) $job->option( 'replace_url' ) ) {
			throw new Job_Exception( __( 'A new site address can only be set when the options table is restored too.', 'siteferry' ), Job_Exception::STEP );
		}

		$needed = (int) $manifest['files']['bytes'] + 2 * (int) $manifest['database']['size'] + self::SPACE_MARGIN;
		$root   = Storage::root();
		$free   = function_exists( 'disk_free_space' ) ? Io::quiet(
			static function () use ( $root ) {
				return disk_free_space( $root );
			}
		) : false;
		if ( false !== $free && $free < $needed ) {
			throw new Job_Exception( sprintf( /* translators: 1: a size, such as 12 MB, 2: a size, such as 12 MB */ __( 'Not enough disk space: the import needs about %1$s, but only %2$s is free.', 'siteferry' ), size_format( $needed, 1 ), size_format( $free, 1 ) ), Job_Exception::STEP );
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
		$map    = null;
		if ( null !== $from_network ) {
			$map = self::network_map( $from_network, $to_network, (string) wp_parse_url( $target['home'], PHP_URL_SCHEME ) );
			foreach ( $map['blogs'] as $blog ) {
				// Subsites on other hosts (subdomains) need their own pairs; subfolders are covered above.
				$pairs += Replacements::build( array( 'home' => $blog['old_url'] ), array( 'home' => $blog['url'] ) );
			}
		}

		$job->set_state(
			'inspect',
			array(
				'source'    => $source,
				'target'    => $target,
				'prefix'    => $prefix,
				'charset'   => (string) $manifest['database']['charset'],
				'tables'    => $names,
				'network'   => $map,
				'rows'      => (int) $manifest['database']['rows'],
				'table_rows' => array_combine( $names, array_map( 'intval', array_column( $manifest['database']['tables'], 'rows' ) ) ),
				'rows_exact' => ! empty( $manifest['database']['rows_exact'] ),
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
					'restores'     => self::restores( $job, count( $names ) ),
					'holds'        => \SiteFerry\Backups::holds( $manifest ),
					'warnings'     => $warnings,
				),
			)
		);
		$job->set_progress( __( 'Backup checked', 'siteferry' ) );
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
		// (A network's subsites are mapped by network_map().)
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
	 * Where every subsite of the backup's network goes in this network: the same subsites,
	 * under this network's domain and path. Subdomains keep their first label
	 * ("shop.old.example" becomes "shop.new.example"); a subsite on a domain of its own keeps it.
	 *
	 * @param array  $from   The backup's network.
	 * @param array  $to     This network.
	 * @param string $scheme "http" or "https" (this site's).
	 * @return array{id:int,domain:string,path:string,url:string,blogs:array}
	 */
	public static function network_map( array $from, array $to, string $scheme ): array {
		$blogs = array();
		foreach ( (array) $from['blogs'] as $blog ) {
			$domain = (string) $blog['domain'];
			$path   = (string) $blog['path'];
			$rest   = 0 === strpos( $path, (string) $from['path'] ) ? substr( $path, strlen( (string) $from['path'] ) ) : ltrim( $path, '/' );
			if ( $domain === $from['domain'] ) {
				$new_domain = (string) $to['domain'];
			} elseif ( '.' . $from['domain'] === substr( $domain, -strlen( '.' . $from['domain'] ) ) ) {
				$new_domain = substr( $domain, 0, -strlen( $from['domain'] ) ) . $to['domain'];
			} else {
				$new_domain = $domain;
			}
			$new_path = (string) $to['path'] . $rest;
			$blogs[]  = array(
				'id'      => (int) $blog['id'],
				'old_url' => $scheme . '://' . $domain . rtrim( $path, '/' ),
				'domain'  => $new_domain,
				'path'    => $new_path,
				'url'     => $scheme . '://' . $new_domain . rtrim( $new_path, '/' ),
			);
		}
		return array(
			'id'        => (int) $from['id'],
			'main_site' => (int) $from['main_site'],
			'domain'    => (string) $to['domain'],
			'path'      => (string) $to['path'],
			'url'       => $scheme . '://' . $to['domain'] . $to['path'],
			'blogs'     => $blogs,
		);
	}
	/**
	 * What a partial restore restores, in words ('' when it restores everything).
	 *
	 * @param Job $job    The job.
	 * @param int $tables Tables in the backup.
	 */
	private static function restores( Job $job, int $tables ): string {
		if ( ! Import::partial( $job ) ) {
			return '';
		}
		$what   = array();
		$chosen = (array) $job->option( 'restore_tables', array() );
		if ( $job->option( 'restore_database', true ) ) {
			$what[] = array() === $chosen
				? __( 'the database', 'siteferry' )
				/* translators: 1: tables chosen, 2: tables in the backup, 3: their names */
				: sprintf( __( '%1$d of %2$d tables (%3$s)', 'siteferry' ), count( $chosen ), $tables, implode( ', ', array_slice( $chosen, 0, 5 ) ) . ( count( $chosen ) > 5 ? ', ...' : '' ) );
		}
		if ( $job->option( 'restore_files', true ) ) {
			$paths  = (array) $job->option( 'restore_paths', array() );
			$what[] = array() === $paths
				? __( 'the files', 'siteferry' )
				: implode(
					', ',
					array_map(
						static function ( string $path ): string {
							return 'wp-content/' . $path;
						},
						array_slice( $paths, 0, 8 )
					)
				) . ( count( $paths ) > 8 ? ', ...' : '' );
		}
		return 2 === count( $what ) ? sprintf( /* translators: 1: a part of a backup, such as "the database", 2: another part */ __( 'Only %1$s and %2$s', 'siteferry' ), $what[0], $what[1] ) : sprintf( /* translators: %s: a part of a backup, such as "the database" */ __( 'Only %s', 'siteferry' ), $what[0] );
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
