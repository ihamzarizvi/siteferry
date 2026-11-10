<?php
/**
 * One site of a network, taken out of a network backup as a single-site backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Network;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Io;
use SiteFerry\Archive\Tar_Entry;
use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Backups;
use SiteFerry\Contents;
use SiteFerry\Database\Sql;
use SiteFerry\Database\Statement_Reader;
use SiteFerry\Database\Dump_Rows;
use SiteFerry\Database\Dumper;
use SiteFerry\Export\Export;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;
use SiteFerry\Wpress\Sql_Values;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a whole-network SiteFerry backup and writes a new, ordinary single-site backup of one of
 * its sites, without touching the live network:
 *
 * - database: the site's own tables, renamed to the base prefix ("wp_2_posts" becomes
 *   "wp_posts"), and the users table and user meta limited to the site's users (those with a
 *   role on it, and the network's super admins, who become administrators). User meta keys of the
 *   site lose its number ("wp_2_capabilities" becomes "wp_capabilities"); other sites' keys are
 *   left out. In its options "wp_2_user_roles" becomes "wp_user_roles", the upload folder
 *   settings are cleared, and plugins active on the whole network stay active;
 * - files: everything a normal backup holds, except media: the site's own media
 *   (uploads/sites/2/) become uploads/ (site 1 keeps uploads/ without the other sites');
 * - manifest: a single site at the subsite's address, with its uploads address, so the normal
 *   import (with address replacement) works on any single site.
 *
 * Runs in one request (set_time_limit(0)); password-protected and changes-only network backups
 * are refused. The new backup gets an "extracted" record naming the network backup and site.
 */
final class Extract {

	/**
	 * Makes the single-site backup.
	 *
	 * @param string $backup Network backup on this server.
	 * @param int    $id     Site id in that network.
	 * @return array{name:string,path:string,size:int,site:int,url:string,tables:int,users:int,files:int}
	 * @throws Job_Exception If the backup or site cannot be extracted.
	 */
	public static function run( string $backup, int $id ): array {
		if ( \SiteFerry\Archive\Crypto::is_encrypted( $backup ) ) {
			throw new Job_Exception( __( 'A site cannot be taken out of a password-protected backup yet.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( \SiteFerry\Wpress\Wpress::is_name( $backup ) ) {
			throw new Job_Exception( __( 'A site can only be taken out of a SiteFerry network backup, not a .wpress file.', 'siteferry' ), Job_Exception::STEP );
		}
		$manifest = Backups::manifest( $backup, null );
		$network  = isset( $manifest['site']['network'] ) && is_array( $manifest['site']['network'] ) ? $manifest['site']['network'] : null;
		if ( null === $network ) {
			throw new Job_Exception( __( 'This backup is of a single site, not of a network; there is no site to take out of it.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( null !== \SiteFerry\Incremental::base_of( $manifest ) ) {
			throw new Job_Exception( __( 'This is a changes-only backup; take the site out of a full network backup instead.', 'siteferry' ), Job_Exception::STEP );
		}
		$blog = null;
		foreach ( (array) $network['blogs'] as $one ) {
			if ( (int) $one['id'] === $id ) {
				$blog = $one;
			}
		}
		if ( null === $blog ) {
			/* translators: %d: a site id */
			throw new Job_Exception( sprintf( __( 'The network in this backup has no site %d.', 'siteferry' ), $id ), Job_Exception::STEP );
		}
		Io::quiet(
			static function () {
				return set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- reading and writing a whole backup takes a while.
			}
		);

		$base   = (string) $manifest['database']['prefix'];
		$tables = array_column( (array) $manifest['database']['tables'], 'name' );
		$map    = Rules::table_map( $tables, $base, $id );
		$work   = Storage::ensure( Storage::root() . '/extract-' . Storage::random_hex( 6 ) );
		$part   = null;
		try {
			$dump = $work . '/network.sql';
			self::dump_out( $backup, $dump );
			$people = self::people( $dump, $base, $id );
			$new    = $work . '/' . Manifest::DATABASE;
			$facts  = self::rewrite( $dump, $new, $base, $id, $map, $people );
			Io::quiet(
				static function () use ( $dump ) {
					return unlink( $dump ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);

			$index = Contents::index( $backup, 3600.0, null );
			$files = 0;
			$dirs  = 0;
			$bytes = 0;
			foreach ( (array) $index['entries'] as $entry ) {
				$to = Rules::file_path( (string) $entry[0], $id );
				if ( null === $to || 0 !== strpos( $to . '/', 'wp-content/' ) ) {
					continue;
				}
				if ( 'f' === $entry[1] ) {
					++$files;
					$bytes += (int) $entry[2];
				} elseif ( 'd' === $entry[1] ) {
					++$dirs;
				}
			}

			$home  = '' !== $facts['home'] ? $facts['home'] : untrailingslashit( (string) $blog['domain'] );
			$out   = self::manifest( $manifest, $id, $blog, $facts, filesize( $new ), $files, $dirs, $bytes, basename( $backup ) );
			$name  = Export::archive_name( $home, false );
			$dir   = Storage::backups_dir();
			$final = $dir . '/' . $name; // A new name made by archive_name(): a plain backup name.
			$part  = $final . '.part';
			$sums  = array();

			$writer = Tar_Writer::create( $part );
			$writer->add_string( Manifest::ENTRY, (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), time() );
			$added  = $writer->add_file( $new, Manifest::DATABASE );
			$sums[] = $added['sha256'] . '  ' . Manifest::DATABASE;
			$copy   = $work . '/file.tmp';
			Contents::walk(
				$backup,
				null,
				static function ( string $path, Tar_Entry $entry, $reader ) use ( $id, $writer, $copy, &$sums ): bool {
					$to = Rules::file_path( $path, $id );
					if ( null === $to || 0 !== strpos( $to . '/', 'wp-content/' ) ) {
						return true;
					}
					if ( $entry->is_dir() ) {
						$writer->add_directory( $to, (int) $entry->mtime );
						return true;
					}
					if ( ! $entry->is_file() ) {
						return true;
					}
					$handle = Io::open_or_fail( $copy, 'wb' );
					try {
						$left = (int) $entry->size;
						while ( $left > 0 ) {
							$chunk = $reader->read( (int) min( 1048576, $left ) );
							if ( '' === $chunk ) {
								throw new Job_Exception( __( 'The network backup ended early.', 'siteferry' ), Job_Exception::STEP );
							}
							Io::write_all( $handle, $chunk );
							$left -= strlen( $chunk );
						}
					} finally {
						fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
					Io::quiet(
						static function () use ( $copy, $entry ) {
							return touch( $copy, max( 1, (int) $entry->mtime ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- keeps the file's own time, which changes-only backups compare.
						}
					);
					$added = $writer->add_file( $copy, $to );
					if ( isset( $added['sha256'] ) ) {
						$sums[] = $added['sha256'] . '  ' . $to;
					}
					return true;
				}
			);
			$writer->add_string( Manifest::CHECKSUMS, implode( "\n", $sums ) . "\n", time() );
			$writer->finish();
			$writer->close();
			if ( ! Io::quiet(
				static function () use ( $part, $final ) {
					return rename( $part, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
			) ) {
				throw new Job_Exception( __( 'The new backup could not be saved.', 'siteferry' ), Job_Exception::STEP );
			}
			$part = null;
			return array(
				'name'   => basename( $final ),
				'path'   => $final,
				'size'   => (int) filesize( $final ),
				'site'   => $id,
				'url'    => $home,
				'tables' => count( $out['database']['tables'] ),
				'users'  => count( $people['users'] ),
				'files'  => $files,
			);
		} finally {
			if ( null !== $part && is_file( $part ) ) {
				Io::quiet(
					static function () use ( $part ) {
						return unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				);
			}
			Io::remove_tree( $work );
		}
	}

	/**
	 * Copies the network backup's database dump into $to, checked against its checksum.
	 *
	 * @param string $backup Backup.
	 * @param string $to     File.
	 * @throws Job_Exception If there is no dump or it is damaged.
	 */
	private static function dump_out( string $backup, string $to ): void {
		$found = false;
		$want  = '';
		$hash  = '';
		Contents::walk(
			$backup,
			null,
			static function ( string $path, Tar_Entry $entry, $reader ) use ( $to, &$found, &$want, &$hash ): bool {
				if ( Manifest::DATABASE === $path && $entry->is_file() ) {
					$handle = Io::open_or_fail( $to, 'wb' );
					$ctx    = hash_init( 'sha256' );
					try {
						$left = (int) $entry->size;
						while ( $left > 0 ) {
							$chunk = $reader->read( (int) min( 1048576, $left ) );
							if ( '' === $chunk ) {
								break;
							}
							hash_update( $ctx, $chunk );
							Io::write_all( $handle, $chunk );
							$left -= strlen( $chunk );
						}
					} finally {
						fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
					$hash  = hash_final( $ctx );
					$found = true;
				} elseif ( Manifest::CHECKSUMS === $path && $entry->is_file() ) {
					$list = (string) $reader->read( (int) min( 67108864, (int) $entry->size ) );
					if ( preg_match( '/^([0-9a-f]{64})  ' . preg_quote( Manifest::DATABASE, '/' ) . '$/m', $list, $match ) ) {
						$want = $match[1];
					}
				}
				return ! $found || '' === $want;
			}
		);
		if ( ! $found ) {
			throw new Job_Exception( __( 'The network backup has no database.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( '' !== $want && ! hash_equals( $want, $hash ) ) {
			throw new Job_Exception( __( 'The database in the network backup is damaged: its checksum does not match.', 'siteferry' ), Job_Exception::STEP );
		}
	}

	/**
	 * The site's users: those with a role on it, and the network's super admins.
	 *
	 * @param string $dump Network dump.
	 * @param string $base Base prefix.
	 * @param int    $id   Site id.
	 * @return array{users:array<int,bool>,admins:array<int,bool>,roles:array<int,bool>}
	 */
	private static function people( string $dump, string $base, int $id ): array {
		$caps    = 1 === $id ? $base . 'capabilities' : $base . $id . '_capabilities';
		$logins  = array();
		$roles   = array();
		$supers  = array();
		Dump_Rows::scan(
			$dump,
			array( $base . 'users', $base . 'usermeta', $base . 'sitemeta' ),
			static function ( string $table, array $columns, array $fields ) use ( $base, $caps, &$logins, &$roles, &$supers ): bool {
				$row = Dump_Rows::row( $columns, $fields );
				if ( $base . 'users' === $table ) {
					$logins[ (string) $row['user_login'] ] = (int) $row['ID'];
				} elseif ( $base . 'usermeta' === $table ) {
					if ( $caps === $row['meta_key'] ) {
						$value = is_string( $row['meta_value'] ) ? @unserialize( $row['meta_value'], array( 'allowed_classes' => false ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores roles serialized; no objects allowed.
						if ( is_array( $value ) && array() !== array_filter( $value ) ) {
							$roles[ (int) $row['user_id'] ] = true;
						}
					}
				} elseif ( 'site_admins' === $row['meta_key'] && is_string( $row['meta_value'] ) ) {
					$value = @unserialize( $row['meta_value'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores the list serialized; no objects allowed.
					foreach ( is_array( $value ) ? $value : array() as $login ) {
						$supers[ (string) $login ] = true;
					}
				}
				return true;
			}
		);
		$admins = array();
		foreach ( array_keys( $supers ) as $login ) {
			if ( isset( $logins[ $login ] ) ) {
				$admins[ $logins[ $login ] ] = true;
			}
		}
		return array(
			'users'  => $roles + $admins,
			'admins' => $admins,
			'roles'  => $roles,
		);
	}

	/**
	 * Writes the single-site dump and returns what was learnt on the way (address, name, theme,
	 * active plugins, rows per table).
	 *
	 * @param string $from   Network dump.
	 * @param string $to     New dump.
	 * @param string $base   Base prefix.
	 * @param int    $id     Site id.
	 * @param array  $map    Old table => new table.
	 * @param array  $people From people().
	 * @return array{home:string,siteurl:string,name:string,stylesheet:string,template:string,active:array,rows:array<string,int>,network_active:array}
	 * @throws Job_Exception If a statement cannot be read.
	 */
	private static function rewrite( string $from, string $to, string $base, int $id, array $map, array $people ): array {
		$facts = array(
			'home'           => '',
			'siteurl'        => '',
			'name'           => '',
			'stylesheet'     => '',
			'template'       => '',
			'active'         => array(),
			'rows'           => array(),
			'network_active' => array(),
		);
		// Plugins active on the whole network stay active in the single site.
		Dump_Rows::scan(
			$from,
			array( $base . 'sitemeta' ),
			static function ( string $table, array $columns, array $fields ) use ( &$facts ): bool {
				unset( $table );
				$row = Dump_Rows::row( $columns, $fields );
				if ( 'active_sitewide_plugins' === $row['meta_key'] && is_string( $row['meta_value'] ) ) {
					$value                   = @unserialize( $row['meta_value'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores the list serialized; no objects allowed.
					$facts['network_active'] = is_array( $value ) ? array_keys( $value ) : array();
				}
				return true;
			}
		);

		$options = $map ? array_search( $base . 'options', $map, true ) : false;
		$out     = Io::open_or_fail( $to, 'wb' );
		$reader  = new Statement_Reader( $from );
		try {
			Io::write_all( $out, "-- SiteFerry database dump\n-- One site (" . $id . ") taken out of a network backup\n\n" );
			while ( null !== ( $statement = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$table = self::table_of( $statement );
				if ( null === $table ) {
					if ( 0 === strncasecmp( $statement, 'SET ', 4 ) ) {
						Io::write_all( $out, $statement . ";\n" );
					}
					continue;
				}
				if ( ! isset( $map[ $table ] ) ) {
					continue; // Another site's table, or the network's own.
				}
				$new       = $map[ $table ];
				$statement = self::rename_head( $statement, $table, $new );
				if ( ! isset( $facts['rows'][ $new ] ) ) {
					$facts['rows'][ $new ] = 0; // Listed in the manifest even when it has no rows.
				}
				if ( 0 !== strncmp( $statement, 'INSERT INTO ', 12 ) ) {
					Io::write_all( $out, ( 0 === strncmp( $statement, 'DROP TABLE', 10 ) ? "\n" : '' ) . $statement . ";\n" );
					continue;
				}
				list( $head, $columns, $values ) = self::split_insert( $statement );
				$kept                            = array();
				foreach ( Sql_Values::rows( $values ) as $row ) {
					$fields = Sql_Values::fields( $row );
					if ( count( $fields ) !== count( $columns ) ) {
						throw new Job_Exception( __( 'An INSERT statement in the database dump is malformed.', 'siteferry' ), Job_Exception::STEP );
					}
					$fields = self::fix_row( $table, $base, $id, $columns, $fields, $people, $facts, $options );
					if ( null !== $fields ) {
						$kept[] = '(' . implode( ',', $fields ) . ')';
					}
				}
				if ( array() !== $kept ) {
					$facts['rows'][ $new ] = ( isset( $facts['rows'][ $new ] ) ? $facts['rows'][ $new ] : 0 ) + count( $kept );
					Io::write_all( $out, $head . implode( ",\n", $kept ) . ";\n" );
				}
			}
			// Super admins without a role on the site become its administrators.
			$extra = array();
			foreach ( array_keys( $people['admins'] ) as $user ) {
				if ( empty( $people['roles'][ $user ] ) ) {
					$extra[] = '(' . (int) $user . ',' . Sql::string_literal( $base . 'capabilities' ) . ',' . Sql::string_literal( serialize( array( 'administrator' => true ) ) ) . ')'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the format WordPress stores roles in.
					$extra[] = '(' . (int) $user . ',' . Sql::string_literal( $base . 'user_level' ) . ',' . Sql::string_literal( '10' ) . ')';
				}
			}
			if ( array() !== $extra ) {
				$meta                   = $base . 'usermeta';
				$facts['rows'][ $meta ] = ( isset( $facts['rows'][ $meta ] ) ? $facts['rows'][ $meta ] : 0 ) + count( $extra );
				Io::write_all( $out, 'INSERT INTO ' . Sql::identifier( $meta ) . ' (`user_id`, `meta_key`, `meta_value`) VALUES ' . implode( ",\n", $extra ) . ";\n" );
			}
			Io::write_all( $out, "SET FOREIGN_KEY_CHECKS = 1;\n" . Dumper::END_MARKER . "\n" );
		} finally {
			$reader->close();
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $facts;
	}

	/**
	 * One row as it goes into the single site, or null to leave it out.
	 *
	 * @param string      $table   Original table.
	 * @param string      $base    Base prefix.
	 * @param int         $id      Site id.
	 * @param array       $columns Column names.
	 * @param array       $fields  Raw values.
	 * @param array       $people  The site's users.
	 * @param array       $facts   Collected facts (changed).
	 * @param string|bool $options The site's options table, or false.
	 */
	private static function fix_row( string $table, string $base, int $id, array $columns, array $fields, array $people, array &$facts, $options ): ?array {
		$at = array_flip( $columns );
		if ( $base . 'users' === $table ) {
			return isset( $at['ID'], $people['users'][ (int) Dump_Rows::literal( $fields[ $at['ID'] ] ) ] ) ? $fields : null;
		}
		if ( $base . 'usermeta' === $table ) {
			if ( ! isset( $at['user_id'], $at['meta_key'] ) || ! isset( $people['users'][ (int) Dump_Rows::literal( $fields[ $at['user_id'] ] ) ] ) ) {
				return null;
			}
			$key = (string) Dump_Rows::literal( $fields[ $at['meta_key'] ] );
			$new = Rules::meta_key( $key, $base, $id );
			if ( null === $new ) {
				return null;
			}
			if ( $new !== $key ) {
				$fields[ $at['meta_key'] ] = Sql::string_literal( $new );
			}
			return $fields;
		}
		if ( $table === $options && isset( $at['option_name'], $at['option_value'] ) ) {
			$name  = (string) Dump_Rows::literal( $fields[ $at['option_name'] ] );
			$value = Dump_Rows::literal( $fields[ $at['option_value'] ] );
			switch ( $name ) {
				case 'home':
				case 'siteurl':
					$facts[ $name ] = untrailingslashit( (string) $value );
					break;
				case 'blogname':
					$facts['name'] = (string) $value;
					break;
				case 'stylesheet':
				case 'template':
					$facts[ $name ] = (string) $value;
					break;
				case 'upload_path':
				case 'upload_url_path':
				case 'fileupload_url':
					$fields[ $at['option_value'] ] = Sql::string_literal( '' );
					break;
				case 'active_plugins':
					$list = is_string( $value ) ? @unserialize( $value, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores the list serialized; no objects allowed.
					$list = array_values( array_unique( array_merge( is_array( $list ) ? array_map( 'strval', $list ) : array(), $facts['network_active'] ) ) );
					$facts['active']               = $list;
					$fields[ $at['option_value'] ] = Sql::string_literal( serialize( $list ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the format WordPress stores the list in.
					break;
				default:
					if ( 1 !== $id && $base . $id . '_user_roles' === $name ) {
						$fields[ $at['option_name'] ] = Sql::string_literal( $base . 'user_roles' );
					}
			}
		}
		return $fields;
	}

	/**
	 * The table a DROP, CREATE, INSERT, ALTER or LOCK statement is about, or null.
	 *
	 * @param string $statement Statement.
	 */
	public static function table_of( string $statement ): ?string {
		if ( preg_match( '/^(?:DROP TABLE IF EXISTS|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|ALTER TABLE|LOCK TABLES)\s+`((?:[^`]|``)+)`/', $statement, $match ) ) {
			return str_replace( '``', '`', $match[1] );
		}
		return null;
	}

	/**
	 * The statement with its table renamed (only the leading name).
	 *
	 * @param string $statement Statement.
	 * @param string $old       Old name.
	 * @param string $new       New name.
	 */
	public static function rename_head( string $statement, string $old, string $new ): string {
		if ( $old === $new ) {
			return $statement;
		}
		$quoted = Sql::identifier( $old );
		$at     = strpos( $statement, $quoted );
		return false === $at ? $statement : substr( $statement, 0, $at ) . Sql::identifier( $new ) . substr( $statement, $at + strlen( $quoted ) );
	}

	/**
	 * An INSERT split into its head ("INSERT INTO `t` (`a`, `b`) VALUES "), columns and values.
	 *
	 * @param string $statement Statement.
	 * @return array{0:string,1:string[],2:string}
	 * @throws Job_Exception If it is not of that form.
	 */
	public static function split_insert( string $statement ): array {
		if ( ! preg_match( '/^(INSERT INTO\s+`(?:[^`]|``)+`\s*\(((?:\s*`(?:[^`]|``)+`\s*,?)+)\)\s*VALUES\s*)/s', $statement, $match ) ) {
			throw new Job_Exception( __( 'An INSERT statement in the database dump is malformed.', 'siteferry' ), Job_Exception::STEP );
		}
		preg_match_all( '/`((?:[^`]|``)+)`/', $match[2], $names );
		return array(
			$match[1],
			array_map(
				static function ( string $name ): string {
					return str_replace( '``', '`', $name );
				},
				$names[1]
			),
			substr( $statement, strlen( $match[1] ) ),
		);
	}

	/**
	 * The single-site manifest.
	 *
	 * @param array  $manifest Network manifest.
	 * @param int    $id       Site id.
	 * @param array  $blog     The site in the network record.
	 * @param array  $facts    From rewrite().
	 * @param int    $db_size  New dump size.
	 * @param int    $files    Files.
	 * @param int    $dirs     Folders.
	 * @param int    $bytes    Bytes of files.
	 * @param string $from     Network backup name.
	 */
	private static function manifest( array $manifest, int $id, array $blog, array $facts, int $db_size, int $files, int $dirs, int $bytes, string $from ): array {
		$out                         = $manifest;
		$home                        = $facts['home'];
		$out['created']              = gmdate( 'Y-m-d\TH:i:s\Z' );
		$out['site']['home']         = $home;
		$out['site']['siteurl']      = '' !== $facts['siteurl'] ? $facts['siteurl'] : $home;
		$out['site']['name']         = $facts['name'];
		$out['site']['multisite']    = false;
		$out['site']['network']      = null;
		$also = array();
		if ( 1 !== $id ) {
			$root                        = rtrim( (string) $manifest['urls']['uploads_url'], '/' );
			$out['urls']['uploads_url']  = $root . '/sites/' . $id;
			$out['paths']['uploads_dir'] = rtrim( (string) $manifest['paths']['uploads_dir'], '/' ) . '/sites/' . $id;
			// Seen from inside a subfolder site, its media are under its own address too.
			$main = rtrim( (string) $manifest['site']['siteurl'], '/' );
			if ( 0 === strpos( $root, $main . '/' ) && '' !== $out['site']['siteurl'] && $out['site']['siteurl'] !== $main ) {
				$also[] = $out['site']['siteurl'] . substr( $root, strlen( $main ) ) . '/sites/' . $id;
			}
		}
		$tables = array();
		$rows   = 0;
		foreach ( $facts['rows'] as $name => $count ) {
			$tables[] = array(
				'name' => $name,
				'rows' => $count,
			);
			$rows    += $count;
		}
		$out['database']['tables']     = $tables;
		$out['database']['rows']       = $rows;
		$out['database']['rows_exact'] = true;
		$out['database']['size']       = $db_size;
		$out['files']['files']         = $files;
		$out['files']['folders']       = $dirs;
		$out['files']['bytes']         = $bytes;
		$out['plugins']['active']      = $facts['active'];
		if ( '' !== $facts['stylesheet'] ) {
			$out['theme'] = array(
				'stylesheet' => $facts['stylesheet'],
				'template'   => '' !== $facts['template'] ? $facts['template'] : $facts['stylesheet'],
			);
		}
		unset( $out['content'], $out['base'], $out['deleted'] );
		$out['extracted'] = array(
			'from'         => $from,
			'site'         => $id,
			'domain'       => (string) $blog['domain'],
			'path'         => (string) $blog['path'],
			// Other addresses of the same media, replaced on import like the uploads address.
			'uploads_urls' => $also,
		);
		return $out;
	}
}
