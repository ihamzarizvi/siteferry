<?php
/**
 * Rules for taking one site out of a network and adding a single site to a network.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Network;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Jobs\Job_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decisions (no database, no files), shared by extracting and merging:
 *
 * - which tables of a network belong to one of its sites, and their single-site names;
 * - which user meta keys belong to that site, and their single-site names;
 * - where a file of the network backup goes in the single-site backup;
 * - the address of a new site in a network;
 * - how a backup's users map onto the network's users (same e-mail: the same person).
 */
final class Rules {

	/** Tables only a network has (with the base prefix). */
	const NETWORK_TABLES = array( 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log' );

	/** Tables shared by every site of a network (with the base prefix). */
	const SHARED_TABLES = array( 'users', 'usermeta' );

	/** Paths WordPress keeps for itself; a subfolder site cannot use them. */
	const RESERVED_PATHS = array( 'page', 'comments', 'blog', 'files', 'feed', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'embed' );

	/** Longest MySQL table name. */
	const MAX_TABLE = 64;

	/**
	 * The tables of site $id in a network, old name => single-site name (base prefix): the site's
	 * own tables, and the shared users tables. Site 1 owns the base tables that are neither the
	 * network's own nor another site's; its tables keep their names.
	 *
	 * @param string[] $tables All tables of the network.
	 * @param string   $base   Base prefix.
	 * @param int      $id     Site id.
	 * @return array<string,string>
	 */
	public static function table_map( array $tables, string $base, int $id ): array {
		$map  = array();
		$own  = $base . $id . '_';
		$skip = array();
		foreach ( self::NETWORK_TABLES as $name ) {
			$skip[ $base . $name ] = true;
		}
		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( 0 !== strpos( $table, $base ) ) {
				continue; // Another prefix: not WordPress's.
			}
			$rest = substr( $table, strlen( $base ) );
			if ( in_array( $rest, self::SHARED_TABLES, true ) ) {
				$map[ $table ] = $table;
			} elseif ( 1 !== $id ) {
				if ( 0 === strpos( $table, $own ) && strlen( $table ) > strlen( $own ) ) {
					$map[ $table ] = $base . substr( $table, strlen( $own ) );
				}
			} elseif ( ! isset( $skip[ $table ] ) && ! preg_match( '/^\d+_/', $rest ) ) {
				$map[ $table ] = $table;
			}
		}
		return $map;
	}

	/**
	 * A user meta key as it becomes in the single site of $id, or null to leave it out (another
	 * site's role and settings).
	 *
	 * @param string $key  Meta key.
	 * @param string $base Base prefix.
	 * @param int    $id   Site id.
	 */
	public static function meta_key( string $key, string $base, int $id ): ?string {
		if ( 0 !== strpos( $key, $base ) ) {
			return $key; // Not tied to a site.
		}
		$rest = substr( $key, strlen( $base ) );
		if ( preg_match( '/^(\d+)_(.+)$/', $rest, $match ) ) {
			return (int) $match[1] === $id && 1 !== $id ? $base . $match[2] : null;
		}
		return 1 === $id ? $key : null; // The main site's keys.
	}

	/**
	 * Where a path of a network backup goes in the single-site backup of $id, or null to leave
	 * it out (other sites' media).
	 *
	 * @param string $path Path in the backup ("wp-content/uploads/sites/2/2026/01/a.jpg").
	 * @param int    $id   Site id.
	 */
	public static function file_path( string $path, int $id ): ?string {
		$path = trim( str_replace( '\\', '/', $path ), '/' );
		if ( 'wp-content/blogs.dir' === $path || 0 === strpos( $path, 'wp-content/blogs.dir/' ) ) {
			return null;
		}
		if ( 0 !== strpos( $path . '/', 'wp-content/uploads/' ) ) {
			return $path; // Plugins, themes and the rest: like a normal backup.
		}
		if ( 'wp-content/uploads' === $path ) {
			return $path;
		}
		$sites = 'wp-content/uploads/sites';
		if ( 1 === $id ) {
			return ( $sites === $path || 0 === strpos( $path, $sites . '/' ) ) ? null : $path;
		}
		$own = $sites . '/' . $id;
		if ( $own === $path ) {
			return 'wp-content/uploads';
		}
		if ( 0 === strpos( $path, $own . '/' ) ) {
			return 'wp-content/uploads/' . substr( $path, strlen( $own ) + 1 );
		}
		return null;
	}

	/**
	 * Domain, path and address of a new site in a network.
	 *
	 * Subfolder networks take a name ("shop" or "/shop/"); subdomain networks a label ("shop")
	 * or a whole domain under the network's ("shop.example.com").
	 *
	 * @param string $input   What was asked for.
	 * @param array  $network The network (Site_Info::network()).
	 * @param string $scheme  "http" or "https".
	 * @return array{domain:string,path:string,url:string}
	 * @throws Job_Exception If it is not a usable address.
	 */
	public static function new_site( string $input, array $network, string $scheme ): array {
		$input  = strtolower( trim( $input ) );
		$domain = (string) $network['domain'];
		$root   = '/' . trim( (string) $network['path'], '/' );
		$root   = '/' === $root ? '/' : $root . '/';
		if ( ! empty( $network['subdomain_install'] ) ) {
			$label = $input;
			if ( '.' . $domain === substr( $input, -strlen( '.' . $domain ) ) ) {
				$label = substr( $input, 0, -strlen( '.' . $domain ) );
			}
			if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				throw new Job_Exception( __( 'Give the new site a subdomain made of letters, digits and hyphens, such as "shop".', 'siteferry' ), Job_Exception::STEP );
			}
			$host = $label . '.' . preg_replace( '/:\d+$/', '', $domain );
			$port = preg_match( '/(:\d+)$/', $domain, $match ) ? $match[1] : '';
			return array(
				'domain' => $host . $port,
				'path'   => $root,
				'url'    => $scheme . '://' . $host . $port . rtrim( $root, '/' ),
			);
		}
		$name = trim( $input, '/' );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name ) ) {
			throw new Job_Exception( __( 'Give the new site a short name made of letters, digits and hyphens, such as "shop"; it becomes its folder in the network.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( in_array( $name, self::RESERVED_PATHS, true ) ) {
			/* translators: %s: a folder name */
			throw new Job_Exception( sprintf( __( '"%s" is a name WordPress keeps for itself; choose another one.', 'siteferry' ), $name ), Job_Exception::STEP );
		}
		$path = $root . $name . '/';
		return array(
			'domain' => $domain,
			'path'   => $path,
			'url'    => $scheme . '://' . $domain . rtrim( $path, '/' ),
		);
	}

	/**
	 * How a backup's users join the network: a user whose e-mail address the network already has
	 * is that person (their account, password and rights stay as they are; they get the backup's
	 * role on the new site); the others become new users, keeping their password hash. A new
	 * user whose login is taken gets "-2", "-3" and so on.
	 *
	 * @param array<int,array{login:string,email:string}> $users      The backup's users by id.
	 * @param array<string,int>                           $by_email   The network's users: lower-case e-mail => id.
	 * @param array<string,bool>                          $logins     The network's logins (lower case) => true.
	 * @return array<int,array{action:string,id:int,login:string,renamed:bool}> Old id => what happens ("match": id is the network user's; "create": id is 0 until made).
	 */
	public static function map_users( array $users, array $by_email, array $logins ): array {
		$out   = array();
		$taken = $logins;
		ksort( $users );
		foreach ( $users as $old => $user ) {
			$email = strtolower( trim( (string) $user['email'] ) );
			if ( '' !== $email && isset( $by_email[ $email ] ) ) {
				$out[ (int) $old ] = array(
					'action'  => 'match',
					'id'      => (int) $by_email[ $email ],
					'login'   => (string) $user['login'],
					'renamed' => false,
				);
				continue;
			}
			$login = (string) $user['login'];
			$try   = $login;
			$n     = 1;
			while ( isset( $taken[ strtolower( $try ) ] ) ) {
				++$n;
				$suffix = '-' . $n;
				$try    = substr( $login, 0, 60 - strlen( $suffix ) ) . $suffix;
			}
			$taken[ strtolower( $try ) ] = true;
			$out[ (int) $old ]           = array(
				'action'  => 'create',
				'id'      => 0,
				'login'   => $try,
				'renamed' => $try !== $login,
			);
		}
		return $out;
	}

	/**
	 * Whether every table of the backup fits under the new site's prefix.
	 *
	 * @param string[] $tables Table names in the backup.
	 * @param string   $from   The backup's prefix.
	 * @param string   $to     The new site's prefix.
	 * @return string|null The first table that would be too long, or null.
	 */
	public static function too_long( array $tables, string $from, string $to ): ?string {
		foreach ( $tables as $table ) {
			$rest = 0 === strpos( (string) $table, $from ) ? substr( (string) $table, strlen( $from ) ) : (string) $table;
			if ( strlen( $to . $rest ) > self::MAX_TABLE ) {
				return (string) $table;
			}
		}
		return null;
	}
}
