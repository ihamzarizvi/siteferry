<?php
/**
 * Restoring one file, or one post or page, from a backup, with an undo.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Path_Guard;
use SiteFerry\Archive\Tar_Entry;
use SiteFerry\Database\Dump_Rows;
use SiteFerry\Export\Exclusions;
use SiteFerry\Export\Manifest;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Puts back a single file of wp-content, or a single post (with its custom fields and terms), from
 * a backup on this server, while the rest of the site stays as it is.
 *
 * Before anything is replaced, what is there now is kept in SiteFerry's storage folder (undo/), so
 * the change can be undone; the 20 newest of these are kept.
 *
 * A file is checked against the backup's checksum list (a .wpress file: against its CRC) before it
 * is moved into place. A post is read from the backup's database dump, which is unpacked into the
 * storage folder for the request and deleted straight after; the whole dump is read each time.
 */
final class Single_Restore {

	/** Undo records kept. */
	const KEEP = 20;

	/** Posts listed at most. */
	const MAX_POSTS = 2000;

	/** Kinds of posts not listed. */
	const SKIP_TYPES = array( 'revision', 'nav_menu_item', 'attachment', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles' );

	/** Statuses not listed. */
	const SKIP_STATUSES = array( 'auto-draft', 'inherit', 'trash' );

	/** Undo tokens. */
	const TOKEN = '/^[a-f0-9]{24}$/';

	// ------------------------------------------------------------------ one file

	/**
	 * The full backup next to a changes-only backup, or null (a full backup, or it is not here).
	 *
	 * @param string      $backup Backup file.
	 * @param string|null $key    Key of an encrypted backup (those are never changes-only backups).
	 */
	private static function full_backup_of(
		string $backup,
		#[\SensitiveParameter]
		?string $key
	): ?string {
		if ( null !== $key || \SiteFerry\Backups::encrypted_name( basename( $backup ) ) || \SiteFerry\Wpress\Wpress::is_name( basename( $backup ) ) ) {
			return null;
		}
		try {
			$base = \SiteFerry\Incremental::base_of( \SiteFerry\Backups::manifest( $backup ) );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( null === $base ) {
			return null;
		}
		$path = dirname( $backup ) . '/' . $base['name'];
		return is_file( $path ) && null === \SiteFerry\Incremental::base_of( \SiteFerry\Backups::manifest( $path ) ) ? $path : null;
	}

	/**
	 * Restores one file of wp-content from a backup.
	 *
	 * @param string      $backup Backup file on this server.
	 * @param string      $inner  Its path in the backup ("wp-content/...").
	 * @param string|null $key    Key of an encrypted backup.
	 * @return array{path:string,size:int,replaced:bool,checked:bool,undo:string}
	 * @throws Job_Exception If the file may not be restored, is not in the backup or does not match.
	 */
	public static function restore_file(
		string $backup,
		string $inner,
		#[\SensitiveParameter]
		?string $key
	): array {
		$relative = self::content_path( $inner );
		$target   = self::target( $relative );

		Io::quiet(
			static function () {
				return set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- restoring one file may take longer than the default limit.
			}
		);
		$temp  = dirname( $target ) . '/.siteferry-' . Storage::random_hex( 6 ) . '.tmp';
		$found = null;
		$sums  = null;
		try {
			$wpress = Contents::walk(
				$backup,
				$key,
				static function ( string $path, Tar_Entry $entry, $reader ) use ( $inner, $temp, &$found, &$sums ): bool {
					if ( $path === $inner ) {
						if ( ! $entry->is_file() ) {
							throw new Job_Exception( __( 'Only a file can be restored this way, not a folder or a link.', 'siteferry' ), Job_Exception::STEP );
						}
						$found = self::copy_out( $reader, $temp, (int) $entry->size );
					} elseif ( Manifest::CHECKSUMS === $path && $entry->is_file() ) {
						$sums = self::sum_of( $reader, $inner );
					}
					return null === $found || null === $sums;
				}
			);
			if ( null === $found ) {
				// A changes-only backup: an unchanged file comes from its full backup.
				$full = self::full_backup_of( $backup, $key );
				if ( null !== $full ) {
					return self::restore_file( $full, $inner, null );
				}
				throw new Job_Exception( __( 'That file is not in the backup.', 'siteferry' ), Job_Exception::NOT_FOUND );
			}
			// A .wpress reader has checked the file's CRC while reading it.
			$checked = $wpress || ( is_string( $sums ) && '' !== $sums );
			if ( is_string( $sums ) && '' !== $sums && ! hash_equals( $sums, $found ) ) {
				throw new Job_Exception( __( 'The file in the backup is damaged: its checksum does not match. Nothing was changed.', 'siteferry' ), Job_Exception::STEP );
			}

			$token    = self::new_token();
			$replaced = is_file( $target );
			if ( $replaced ) {
				self::move( $target, self::undo_dir() . '/' . $token . '.bin' );
			}
			try {
				self::move( $temp, $target );
			} catch ( \Throwable $e ) {
				if ( $replaced ) {
					self::move( self::undo_dir() . '/' . $token . '.bin', $target );
				}
				throw $e;
			}
		} finally {
			if ( is_file( $temp ) ) {
				self::remove( $temp );
			}
		}
		self::save_record(
			$token,
			array(
				'type'         => 'file',
				'path'         => $relative,
				'had_previous' => $replaced,
				'backup'       => basename( $backup ),
			)
		);
		return array(
			'path'     => 'wp-content/' . $relative,
			'size'     => (int) filesize( $target ),
			'replaced' => $replaced,
			'checked'  => $checked,
			'undo'     => $token,
		);
	}

	/**
	 * A path in the backup as a path in wp-content, when such a file may be restored.
	 *
	 * @param string $inner Path in the backup.
	 * @throws Job_Exception If it is outside wp-content or SiteFerry's own.
	 */
	public static function content_path( string $inner ): string {
		$inner = trim( str_replace( '\\', '/', $inner ), '/' );
		if ( 0 !== strpos( $inner, 'wp-content/' ) ) {
			throw new Job_Exception( __( 'Only files of wp-content can be restored on their own.', 'siteferry' ), Job_Exception::STEP );
		}
		try {
			$relative = Path_Guard::check( substr( $inner, strlen( 'wp-content/' ) ) );
		} catch ( Archive_Exception $e ) {
			throw new Job_Exception( sprintf( /* translators: %s: a path */ __( 'This file cannot be restored here: "%s".', 'siteferry' ), Path_Guard::printable( $inner ) ), Job_Exception::STEP );
		}
		$parts = explode( '/', $relative );
		$own   = 'plugins' === $parts[0] && isset( $parts[1] ) && Plugin::folder() === $parts[1];
		foreach ( Exclusions::ALWAYS as $pattern ) {
			$own = $own || fnmatch( $pattern, $parts[0] );
		}
		if ( $own || count( $parts ) < 2 && in_array( $relative, Import\Import::DROP_INS, true ) ) {
			throw new Job_Exception( __( 'SiteFerry\'s own files, its storage folder and WordPress\'s update folders are not restored.', 'siteferry' ), Job_Exception::STEP );
		}
		return $relative;
	}

	/**
	 * Where a file of wp-content goes, with its folders made; never through a link.
	 *
	 * @param string $relative Path in wp-content.
	 * @throws Job_Exception If a folder on the way is a link, or the place is a folder.
	 */
	private static function target( string $relative ): string {
		$root  = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' );
		$parts = explode( '/', $relative );
		$file  = array_pop( $parts );
		$dir   = $root;
		foreach ( $parts as $part ) {
			$dir .= '/' . $part;
			if ( Io::is_link_or_junction( $dir ) ) {
				throw new Job_Exception( __( 'A folder on the way to this file is a link; SiteFerry does not restore through links.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( ! is_dir( $dir ) && ! Io::quiet(
				static function () use ( $dir ) {
					return mkdir( $dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a folder of the restored file, inside wp-content.
				}
			) && ! is_dir( $dir ) ) {
				throw new Job_Exception( sprintf( /* translators: %s: a folder path */ __( 'Cannot create the folder %s for the file.', 'siteferry' ), 'wp-content' . substr( $dir, strlen( $root ) ) ), Job_Exception::STEP );
			}
		}
		$target = $dir . '/' . $file;
		if ( is_dir( $target ) || Io::is_link_or_junction( $target ) ) {
			throw new Job_Exception( __( 'There is a folder or a link where the file goes; it was left as it is.', 'siteferry' ), Job_Exception::STEP );
		}
		return $target;
	}

	/**
	 * Copies the current entry's data into a new file and returns its SHA-256.
	 *
	 * @param object $reader Archive reader at the entry's data.
	 * @param string $file   New file.
	 * @param int    $size   Expected size.
	 * @throws Job_Exception If the file cannot be written or is cut short.
	 */
	private static function copy_out( $reader, string $file, int $size ): string {
		$out  = Io::open( $file, 'xb' );
		if ( false === $out ) {
			throw new Job_Exception( __( 'Cannot write next to the file being restored. Check the folder\'s permissions.', 'siteferry' ), Job_Exception::STEP );
		}
		$hash    = hash_init( 'sha256' );
		$written = 0;
		try {
			while ( true ) {
				$data = $reader->read( 1048576 );
				if ( '' === $data ) {
					break;
				}
				Io::write_all( $out, $data );
				hash_update( $hash, $data );
				$written += strlen( $data );
			}
		} finally {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( $written !== $size ) {
			throw new Job_Exception( __( 'The file in the backup is cut short. Nothing was changed.', 'siteferry' ), Job_Exception::STEP );
		}
		return hash_final( $hash );
	}

	/**
	 * The SHA-256 a checksum list gives for a path, or '' when it has none.
	 *
	 * @param object $reader Reader at the list's data.
	 * @param string $path   Path in the backup.
	 */
	private static function sum_of( $reader, string $path ): string {
		$tail   = '  ' . $path;
		$buffer = '';
		while ( true ) {
			$data    = $reader->read( 1048576 );
			$buffer .= $data;
			$lines   = explode( "\n", $buffer );
			$buffer  = '' === $data ? '' : (string) array_pop( $lines );
			foreach ( $lines as $line ) {
				$line = rtrim( $line, "\r" );
				if ( strlen( $line ) === 64 + strlen( $tail ) && substr( $line, 64 ) === $tail && preg_match( '/^[0-9a-f]{64}/', $line ) ) {
					return substr( $line, 0, 64 );
				}
			}
			if ( '' === $data ) {
				return '';
			}
		}
	}

	// ------------------------------------------------------------------ one post

	/**
	 * The posts and pages in a backup of this site, newest first, with how each is on the site now.
	 *
	 * @param string      $backup Backup file.
	 * @param string|null $key    Key of an encrypted backup.
	 * @param string      $search Text the title must contain ('' for all).
	 * @return array{posts:array,total:int,more:bool}
	 * @throws Job_Exception If posts cannot be restored from this backup.
	 */
	public static function posts(
		string $backup,
		#[\SensitiveParameter]
		?string $key,
		string $search = ''
	): array {
		global $wpdb;
		$tables = self::tables( $backup, $key );
		$found  = array();
		self::with_dump(
			$backup,
			$key,
			static function ( string $dump ) use ( $tables, $search, &$found ) {
				Dump_Rows::scan(
					$dump,
					array( $tables['from'] . 'posts' ),
					static function ( string $table, array $columns, array $fields ) use ( $search, &$found ): bool {
						$at = array_flip( $columns );
						foreach ( array( 'ID', 'post_title', 'post_type', 'post_status', 'post_date', 'post_modified' ) as $column ) {
							if ( ! isset( $at[ $column ] ) ) {
								return false;
							}
						}
						$type   = (string) Dump_Rows::literal( $fields[ $at['post_type'] ] );
						$status = (string) Dump_Rows::literal( $fields[ $at['post_status'] ] );
						if ( in_array( $type, self::SKIP_TYPES, true ) || in_array( $status, self::SKIP_STATUSES, true ) ) {
							return true;
						}
						$title = (string) Dump_Rows::literal( $fields[ $at['post_title'] ] );
						if ( '' !== $search && false === ( function_exists( 'mb_stripos' ) ? mb_stripos( $title, $search ) : stripos( $title, $search ) ) ) {
							return true;
						}
						$found[] = array(
							'id'       => (int) Dump_Rows::literal( $fields[ $at['ID'] ] ),
							'title'    => $title,
							'type'     => $type,
							'status'   => $status,
							'date'     => (string) Dump_Rows::literal( $fields[ $at['post_date'] ] ),
							'modified' => (string) Dump_Rows::literal( $fields[ $at['post_modified'] ] ),
						);
						return true;
					}
				);
			}
		);
		usort(
			$found,
			static function ( array $a, array $b ): int {
				return strcmp( $b['date'], $a['date'] ) ?: $b['id'] - $a['id'];
			}
		);
		$total = count( $found );
		$found = array_slice( $found, 0, self::MAX_POSTS );

		// How each is on the site now.
		$live  = array();
		$table = $tables['to'] . 'posts';
		foreach ( array_chunk( array_column( $found, 'id' ), 500 ) as $ids ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_modified FROM ' . Database\Sql::identifier( $table ) . ' WHERE ID IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are backquoted identifiers of this site's prefix; placeholders only; posts by id, read once.
			foreach ( (array) $rows as $row ) {
				$live[ (int) $row['ID'] ] = (string) $row['post_modified'];
			}
		}
		foreach ( $found as &$post ) {
			$post['now'] = ! isset( $live[ $post['id'] ] ) ? 'missing' : ( $live[ $post['id'] ] === $post['modified'] ? 'same' : 'changed' );
		}
		unset( $post );
		return array(
			'posts' => $found,
			'total' => $total,
			'more'  => $total > count( $found ),
		);
	}

	/**
	 * Restores one post from a backup: its row, custom fields and terms (those whose term still
	 * exists here). What is there now is kept for undoing.
	 *
	 * @param string      $backup Backup file.
	 * @param int         $id     Post ID.
	 * @param string|null $key    Key of an encrypted backup.
	 * @return array{id:int,title:string,meta:int,terms:int,skipped_terms:int,undo:string}
	 * @throws Job_Exception If the post is not in the backup or the database refuses.
	 */
	public static function restore_post(
		string $backup,
		int $id,
		#[\SensitiveParameter]
		?string $key
	): array {
		global $wpdb;
		$tables = self::tables( $backup, $key );
		$from   = $tables['from'];
		$to     = $tables['to'];
		$post   = null;
		$meta   = array();
		$terms  = array();
		self::with_dump(
			$backup,
			$key,
			static function ( string $dump ) use ( $from, $id, &$post, &$meta, &$terms ) {
				Dump_Rows::scan(
					$dump,
					array( $from . 'posts', $from . 'postmeta', $from . 'term_relationships' ),
					static function ( string $table, array $columns, array $fields ) use ( $from, $id, &$post, &$meta, &$terms ): bool {
						$owner = array(
							$from . 'posts'              => 'ID',
							$from . 'postmeta'           => 'post_id',
							$from . 'term_relationships' => 'object_id',
						)[ $table ];
						$at = array_search( $owner, $columns, true );
						if ( false === $at || (string) $id !== (string) Dump_Rows::literal( $fields[ $at ] ) ) {
							return true;
						}
						$row = Dump_Rows::row( $columns, $fields );
						if ( $from . 'posts' === $table ) {
							$post = $row;
						} elseif ( $from . 'postmeta' === $table ) {
							$meta[] = $row;
						} else {
							$terms[] = $row;
						}
						return true;
					}
				);
			}
		);
		if ( null === $post ) {
			throw new Job_Exception( __( 'That post is not in the backup.', 'siteferry' ), Job_Exception::NOT_FOUND );
		}

		// What is there now, for undoing.
		$token  = self::new_token();
		$before = self::snapshot( $to, $id );
		self::save_record(
			$token,
			array(
				'type'   => 'post',
				'id'     => $id,
				'title'  => (string) $post['post_title'],
				'backup' => basename( $backup ),
				'before' => $before,
			)
		);

		$known   = self::columns( $to . 'posts' );
		$row     = array_intersect_key( $post, array_flip( $known ) );
		$meta_in = array();
		foreach ( $meta as $item ) {
			$meta_in[] = array(
				'post_id'    => $id,
				'meta_key'   => $item['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a row written back as it was.
				'meta_value' => $item['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a row written back as it was.
			);
		}
		$existing = self::existing_term_taxonomies( $to, array_column( $terms, 'term_taxonomy_id' ) );
		$terms_in = array();
		foreach ( $terms as $item ) {
			if ( isset( $existing[ (int) $item['term_taxonomy_id'] ] ) ) {
				$terms_in[] = array(
					'object_id'        => $id,
					'term_taxonomy_id' => (int) $item['term_taxonomy_id'],
					'term_order'       => isset( $item['term_order'] ) ? (int) $item['term_order'] : 0,
				);
			}
		}
		self::write_post( $to, $id, $row, $meta_in, $terms_in );
		self::after_post_change( $to, $id, array_merge( array_column( $before['terms'], 'term_taxonomy_id' ), array_column( $terms_in, 'term_taxonomy_id' ) ) );

		return array(
			'id'            => $id,
			'title'         => (string) $post['post_title'],
			'type'          => (string) $post['post_type'],
			'meta'          => count( $meta_in ),
			'terms'         => count( $terms_in ),
			'skipped_terms' => count( $terms ) - count( $terms_in ),
			'existed'       => null !== $before['post'],
			'undo'          => $token,
		);
	}

	/**
	 * The backup's table prefix and this site's, when posts can be restored from the backup.
	 *
	 * @param string      $backup Backup file.
	 * @param string|null $key    Key.
	 * @return array{from:string,to:string}
	 * @throws Job_Exception If it is a .wpress backup or a backup of another site.
	 */
	private static function tables(
		string $backup,
		#[\SensitiveParameter]
		?string $key
	): array {
		global $wpdb;
		if ( Wpress\Wpress::is_name( $backup ) || ( ! Archive\Crypto::is_encrypted( $backup ) && Wpress\Reader::is_wpress( $backup ) ) ) {
			throw new Job_Exception( __( 'Single posts can only be restored from SiteFerry backups, not from .wpress files.', 'siteferry' ), Job_Exception::STEP );
		}
		$manifest = Backups::manifest( $backup, $key );
		$from     = isset( $manifest['database']['prefix'] ) ? (string) $manifest['database']['prefix'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $from ) ) {
			throw new Job_Exception( __( 'The backup has an invalid table prefix.', 'siteferry' ), Job_Exception::STEP );
		}
		$home = isset( $manifest['site']['home'] ) ? (string) $manifest['site']['home'] : '';
		if ( ! self::same_site( $home, (string) get_option( 'home' ) ) ) {
			throw new Job_Exception( __( 'This backup is of another site. Single posts can only be restored from backups of this site, because their addresses would need changing.', 'siteferry' ), Job_Exception::STEP );
		}
		return array(
			'from' => $from,
			'to'   => (string) $wpdb->base_prefix, // A network: its main site's tables.
		);
	}

	/**
	 * Whether two site addresses are the same site (the scheme may differ).
	 *
	 * @param string $a Address.
	 * @param string $b Address.
	 */
	public static function same_site( string $a, string $b ): bool {
		$norm = static function ( string $url ): string {
			$url  = untrailingslashit( trim( $url ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$port = wp_parse_url( $url, PHP_URL_PORT );
			return $host . ( $port ? ':' . $port : '' ) . untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		};
		return '' !== $a && '' !== $b && $norm( $a ) === $norm( $b );
	}

	/**
	 * Unpacks the backup's database dump next to SiteFerry's other files for $use, then deletes it.
	 *
	 * @param string      $backup Backup file.
	 * @param string|null $key    Key.
	 * @param callable    $use    function ( string $dump ).
	 * @throws Job_Exception If the backup has no dump or it does not match its checksum.
	 */
	private static function with_dump(
		string $backup,
		#[\SensitiveParameter]
		?string $key,
		callable $use
	): void {
		Io::quiet(
			static function () {
				return set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- the database dump of a big site takes a while to read.
			}
		);
		$dump  = Storage::ensure( Storage::root() . '/undo' ) . '/dump-' . Storage::random_hex( 8 ) . '.sql';
		$found = null;
		$sums  = null;
		try {
			Contents::walk(
				$backup,
				$key,
				static function ( string $path, Tar_Entry $entry, $reader ) use ( $dump, &$found, &$sums ): bool {
					if ( Manifest::DATABASE === $path && $entry->is_file() ) {
						$found = self::copy_out( $reader, $dump, (int) $entry->size );
					} elseif ( Manifest::CHECKSUMS === $path && $entry->is_file() ) {
						$sums = self::sum_of( $reader, Manifest::DATABASE );
					}
					return null === $found || null === $sums;
				}
			);
			if ( null === $found ) {
				throw new Job_Exception( __( 'This backup has no database.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( is_string( $sums ) && '' !== $sums && ! hash_equals( $sums, $found ) ) {
				throw new Job_Exception( __( 'The database in the backup is damaged: its checksum does not match.', 'siteferry' ), Job_Exception::STEP );
			}
			$use( $dump );
		} finally {
			if ( is_file( $dump ) ) {
				self::remove( $dump );
			}
		}
	}

	/**
	 * A post as it is now: its row (null if there is none), custom fields and term links.
	 *
	 * @param string $prefix Table prefix.
	 * @param int    $id     Post ID.
	 */
	private static function snapshot( string $prefix, int $id ): array {
		global $wpdb;
		return array(
			'post'  => $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database\Sql::identifier( $prefix . 'posts' ) . ' WHERE ID = %d', $id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read once to be able to undo; table names are backquoted identifiers of this site's prefix, values use placeholders.
			'meta'  => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Database\Sql::identifier( $prefix . 'postmeta' ) . ' WHERE post_id = %d ORDER BY meta_id', $id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read once to be able to undo; table names are backquoted identifiers of this site's prefix, values use placeholders.
			'terms' => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Database\Sql::identifier( $prefix . 'term_relationships' ) . ' WHERE object_id = %d', $id ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- read once to be able to undo; table names are backquoted identifiers of this site's prefix, values use placeholders.
		);
	}

	/**
	 * Writes a post's row, custom fields and term links in one transaction (or deletes the row
	 * when $row is null).
	 *
	 * @param string     $prefix Table prefix.
	 * @param int        $id     Post ID.
	 * @param array|null $row    The posts row, or null for no post.
	 * @param array      $meta   postmeta rows.
	 * @param array      $terms  term_relationships rows.
	 * @throws Job_Exception If the database refuses (everything is rolled back).
	 */
	private static function write_post( string $prefix, int $id, ?array $row, array $meta, array $terms ): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
		try {
			$ok = null === $row
				? false !== $wpdb->delete( $prefix . 'posts', array( 'ID' => $id ), array( '%d' ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
				: false !== $wpdb->replace( $prefix . 'posts', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
			$ok = $ok && false !== $wpdb->delete( $prefix . 'postmeta', array( 'post_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
			foreach ( $meta as $item ) {
				$ok = $ok && false !== $wpdb->insert( $prefix . 'postmeta', $item ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
			}
			$ok = $ok && false !== $wpdb->delete( $prefix . 'term_relationships', array( 'object_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
			foreach ( $terms as $item ) {
				$ok = $ok && false !== $wpdb->insert( $prefix . 'term_relationships', $item ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
			}
			if ( ! $ok ) {
				throw new Job_Exception( sprintf( /* translators: %s: the database's error message */ __( 'The database refused the change: %s. Nothing was changed.', 'siteferry' ), '' === (string) $wpdb->last_error ? __( 'unknown error', 'siteferry' ) : (string) $wpdb->last_error ), Job_Exception::STEP );
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one post's rows replaced in a transaction; caches are cleaned afterwards.
			throw $e;
		}
	}

	/**
	 * Term counts and caches after a post changed.
	 *
	 * @param string $prefix Table prefix.
	 * @param int    $id     Post ID.
	 * @param array  $tt_ids term_taxonomy ids it was or is linked to.
	 */
	private static function after_post_change( string $prefix, int $id, array $tt_ids ): void {
		global $wpdb;
		$tt_ids = array_values( array_unique( array_map( 'intval', $tt_ids ) ) );
		if ( array() !== $tt_ids ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT term_taxonomy_id, taxonomy FROM ' . Database\Sql::identifier( $prefix . 'term_taxonomy' ) . ' WHERE term_taxonomy_id IN (' . implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) ) . ')', $tt_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are backquoted identifiers of this site's prefix; placeholders only.
			$by   = array();
			foreach ( (array) $rows as $row ) {
				$by[ (string) $row['taxonomy'] ][] = (int) $row['term_taxonomy_id'];
			}
			foreach ( $by as $taxonomy => $ids ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					wp_update_term_count_now( $ids, $taxonomy );
				}
				clean_term_cache( $ids, $taxonomy, false );
			}
		}
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
	}

	/**
	 * Which of some term_taxonomy ids exist here.
	 *
	 * @param string $prefix Table prefix.
	 * @param array  $ids    Ids.
	 * @return array<int,true>
	 */
	private static function existing_term_taxonomies( string $prefix, array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$found = $wpdb->get_col( $wpdb->prepare( 'SELECT term_taxonomy_id FROM ' . Database\Sql::identifier( $prefix . 'term_taxonomy' ) . ' WHERE term_taxonomy_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are backquoted identifiers of this site's prefix; placeholders only.
		return array_fill_keys( array_map( 'intval', (array) $found ), true );
	}

	/**
	 * Column names of a live table.
	 *
	 * @param string $table Table.
	 * @return string[]
	 */
	private static function columns( string $table ): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . Database\Sql::identifier( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are backquoted identifiers of this site's prefix; the table's columns, read once.
	}

	// ------------------------------------------------------------------ undo

	/**
	 * Undoes a restore of one file or one post.
	 *
	 * @param string $token Undo token.
	 * @return array{type:string,path?:string,id?:int,title?:string}
	 * @throws Job_Exception If there is no such undo, or it cannot be done.
	 */
	public static function undo( string $token ): array {
		$record = self::record( $token );
		if ( 'file' === $record['type'] ) {
			$target = self::target( self::content_path( 'wp-content/' . $record['path'] ) );
			$saved  = self::undo_dir() . '/' . $token . '.bin';
			if ( ! empty( $record['had_previous'] ) ) {
				if ( ! is_file( $saved ) ) {
					throw new Job_Exception( __( 'The file kept for undoing is gone.', 'siteferry' ), Job_Exception::NOT_FOUND );
				}
				self::move( $saved, $target );
			} elseif ( is_file( $target ) ) {
				self::remove( $target );
			}
			self::forget( $token );
			return array(
				'type' => 'file',
				'path' => 'wp-content/' . $record['path'],
			);
		}

		global $wpdb;
		$prefix = (string) $wpdb->base_prefix;
		$id     = (int) $record['id'];
		$before = (array) $record['before'];
		$now    = self::snapshot( $prefix, $id );
		$row    = is_array( $before['post'] ) ? array_intersect_key( $before['post'], array_flip( self::columns( $prefix . 'posts' ) ) ) : null;
		self::write_post( $prefix, $id, $row, (array) $before['meta'], (array) $before['terms'] );
		self::after_post_change( $prefix, $id, array_merge( array_column( $now['terms'], 'term_taxonomy_id' ), array_column( (array) $before['terms'], 'term_taxonomy_id' ) ) );
		self::forget( $token );
		return array(
			'type'  => 'post',
			'id'    => $id,
			'title' => (string) $record['title'],
		);
	}

	/**
	 * The undo folder.
	 */
	private static function undo_dir(): string {
		return Storage::ensure( Storage::root() . '/undo' );
	}

	/**
	 * A new undo token.
	 */
	private static function new_token(): string {
		return Storage::random_hex( 12 );
	}

	/**
	 * Saves an undo record and drops the oldest beyond KEEP.
	 *
	 * @param string $token  Token.
	 * @param array  $record Record.
	 */
	private static function save_record( string $token, array $record ): void {
		$dir = self::undo_dir();
		file_put_contents( $dir . '/' . $token . '.json', (string) wp_json_encode( array( 'time' => time() ) + $record ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- inside SiteFerry's protected storage folder.
		$records = (array) glob( $dir . '/*.json' );
		if ( count( $records ) <= self::KEEP ) {
			return;
		}
		usort(
			$records,
			static function ( $a, $b ): int {
				return (int) filemtime( (string) $a ) - (int) filemtime( (string) $b );
			}
		);
		foreach ( array_slice( $records, 0, count( $records ) - self::KEEP ) as $old ) {
			self::forget( basename( (string) $old, '.json' ) );
		}
	}

	/**
	 * An undo record.
	 *
	 * @param string $token Token.
	 * @throws Job_Exception If there is none.
	 */
	private static function record( string $token ): array {
		$file = self::undo_dir() . '/' . $token . '.json';
		if ( ! preg_match( self::TOKEN, $token ) || ! is_file( $file ) ) {
			throw new Job_Exception( __( 'This can no longer be undone.', 'siteferry' ), Job_Exception::NOT_FOUND );
		}
		$record = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $record ) || ! in_array( isset( $record['type'] ) ? $record['type'] : '', array( 'file', 'post' ), true ) ) {
			throw new Job_Exception( __( 'This can no longer be undone.', 'siteferry' ), Job_Exception::NOT_FOUND );
		}
		return $record;
	}

	/**
	 * Removes an undo record and its saved file.
	 *
	 * @param string $token Token.
	 */
	private static function forget( string $token ): void {
		if ( ! preg_match( self::TOKEN, $token ) ) {
			return;
		}
		foreach ( array( '.json', '.bin' ) as $ending ) {
			$file = self::undo_dir() . '/' . $token . $ending;
			if ( is_file( $file ) ) {
				self::remove( $file );
			}
		}
	}

	/**
	 * Moves a file, replacing what is at the destination.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @throws Job_Exception If it cannot be moved.
	 */
	private static function move( string $from, string $to ): void {
		$moved = Io::quiet(
			static function () use ( $from, $to ) {
				if ( '\\' === DIRECTORY_SEPARATOR && is_file( $to ) ) {
					unlink( $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Windows cannot rename over a file.
				}
				return rename( $from, $to ) || ( copy( $from, $to ) && unlink( $from ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink -- moving a file between wp-content and SiteFerry's storage folder (another disk falls back to a copy).
			}
		);
		if ( ! $moved ) {
			throw new Job_Exception( __( 'Cannot move the file into place. Check the folder\'s permissions.', 'siteferry' ), Job_Exception::STEP );
		}
	}

	/**
	 * Deletes a file quietly.
	 *
	 * @param string $file File.
	 */
	private static function remove( string $file ): void {
		Io::quiet(
			static function () use ( $file ) {
				return unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- a temporary or undo file of SiteFerry's, or the restored file being undone.
			}
		);
	}
}
