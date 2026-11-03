<?php
/**
 * Which wp-content paths an export leaves out.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Matches paths relative to wp-content ("uploads/2024/a.jpg") against exclusion rules.
 *
 * Three kinds of rule:
 * - paths: a file or folder and everything below it ("cache", "plugins/akismet");
 * - names: a file or folder name at any depth (".git", "node_modules");
 * - patterns: like paths, where "*" matches any characters except "/" ("backupwordpress-*-backups").
 */
final class Exclusions {

	/** Folders of other backup and migration plugins, relative to wp-content. */
	const BACKUP_PLUGIN_FOLDERS = array(
		'ai1wm-backups',
		'updraft',
		'backups-dup-lite',
		'backups-dup-pro',
		'wp-snapshots',
		'wpvividbackups',
		'aiowps_backups',
		'backupwordpress-*-backups',
		'uploads/backwpup-*-backups',
		'uploads/backupbuddy_backups',
		'uploads/backup-guard',
		'uploads/wp-staging/backups',
	);

	/** Cache folders, relative to wp-content. */
	const CACHE_FOLDERS = array( 'cache', 'et-cache', 'litespeed' );

	/** Always left out: WordPress's own temporary folders and every SiteFerry storage folder. */
	const ALWAYS = array( 'upgrade', 'upgrade-temp-backup', 'siteferry-*' );

	/** Names left out at any depth. */
	const NAMES = array( '.git', 'node_modules' );

	/**
	 * Exact paths.
	 *
	 * @var array<string,bool>
	 */
	private $paths = array();

	/**
	 * Names at any depth.
	 *
	 * @var array<string,bool>
	 */
	private $names = array();

	/**
	 * Compiled patterns.
	 *
	 * @var string[]
	 */
	private $regexes = array();

	/**
	 * Rules as given, for the manifest.
	 *
	 * @var array{paths:string[],names:string[],patterns:string[]}
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param string[] $paths Paths or patterns relative to wp-content (patterns contain "*").
	 * @param string[] $names Names excluded at any depth.
	 */
	public function __construct( array $paths, array $names = self::NAMES ) {
		$plain    = array();
		$patterns = array();
		foreach ( $paths as $path ) {
			$path = trim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( '' === $path ) {
				continue;
			}
			if ( false !== strpos( $path, '*' ) ) {
				$patterns[]      = $path;
				$this->regexes[] = '#^' . str_replace( '\*', '[^/]*', preg_quote( $path, '#' ) ) . '(/|$)#';
			} else {
				$plain[]              = $path;
				$this->paths[ $path ] = true;
			}
		}
		foreach ( $names as $name ) {
			$this->names[ (string) $name ] = true;
		}
		$this->rules = array(
			'paths'    => array_values( array_unique( $plain ) ),
			'names'    => array_keys( $this->names ),
			'patterns' => array_values( array_unique( $patterns ) ),
		);
	}

	/**
	 * Rebuilds exclusions from to_array() output.
	 *
	 * @param array $rules Saved rules.
	 */
	public static function from_array( array $rules ): self {
		return new self( array_merge( $rules['paths'], $rules['patterns'] ), $rules['names'] );
	}

	/**
	 * Rules as data (for job state and the manifest).
	 *
	 * @return array{paths:string[],names:string[],patterns:string[]}
	 */
	public function to_array(): array {
		return $this->rules;
	}

	/**
	 * Whether a path relative to wp-content is left out.
	 *
	 * Callers check each folder before descending into it, so testing the path itself and its
	 * last segment is enough; parents were checked on the way down.
	 *
	 * @param string $relative Path with "/" separators and no leading slash.
	 */
	public function excludes( string $relative ): bool {
		if ( isset( $this->paths[ $relative ] ) ) {
			return true;
		}
		$slash = strrpos( $relative, '/' );
		$name  = false === $slash ? $relative : substr( $relative, $slash + 1 );
		if ( isset( $this->names[ $name ] ) ) {
			return true;
		}
		foreach ( $this->regexes as $regex ) {
			if ( preg_match( $regex, $relative ) ) {
				return true;
			}
		}
		return false;
	}
}
