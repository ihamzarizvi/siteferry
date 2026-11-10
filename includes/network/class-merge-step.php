<?php
/**
 * Import step: a single-site backup becomes a new site of this network.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Network;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- tables of a site that is being made are renamed and filled directly; nothing here can be cached.

use SiteFerry\Archive\Io;
use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Sql;
use SiteFerry\Import\Database_Step;
use SiteFerry\Import\Extract_Step;
use SiteFerry\Import\Replace_Step;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * After the backup's tables are loaded under the temporary prefix and its addresses replaced
 * (with the new site's address as the target), in one request:
 *
 * 1. creates the site (wp_insert_site) at the planned address, and checks it got the planned id
 *    (the uploads address in the content uses it);
 * 2. drops the empty tables WordPress made for it and renames the backup's tables into their
 *    place ({base}{id}_*); the users tables are not renamed, they are merged;
 * 3. merges the users: a backup user whose e-mail address the network has is that person
 *    (account, password and super-admin rights untouched); the others become new users with
 *    their password hashes (a taken login gets "-2" and so on). Each gets the backup's role on
 *    the new site only. Authors of posts, comments and links, and "_edit_last", follow the new
 *    user ids; plugin tables that store user ids are not rewritten;
 * 4. puts the media into uploads/sites/{id}/, and copies plugins and themes the network does not
 *    have (existing ones are never replaced; a different version is reported);
 * 5. keeps the backup's active plugins active on the new site (those that exist), clears the
 *    upload folder settings and removes SiteFerry's own settings from the new site's options.
 *
 * A failure undoes it all: the site is deleted with its tables, the users made are removed and
 * the files moved are put back.
 */
final class Merge_Step implements Step {

	/**
	 * Checks a merge before anything changes, and works out the new site's address, id and
	 * target addresses (used by the address replacement).
	 *
	 * @param Job        $job          Import job.
	 * @param array      $manifest     The backup's manifest.
	 * @param array      $site         This site's facts.
	 * @param array|null $from_network The backup's network record.
	 * @param array|null $to_network   This network's record.
	 * @return array{domain:string,path:string,url:string,id:int,target:array,summary:array}
	 * @throws Job_Exception When it cannot be done.
	 */
	public static function plan( Job $job, array $manifest, array $site, ?array $from_network, ?array $to_network ): array {
		global $wpdb;
		if ( null === $to_network || ! is_multisite() ) {
			throw new Job_Exception( __( 'This site is not a multisite network; a backup can only be added as a new site to a network.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( null !== $from_network ) {
			throw new Job_Exception( __( 'This is a backup of a whole network; only a single-site backup can be added as a new site. Take one site out of it first ("Extract one site").', 'siteferry' ), Job_Exception::STEP );
		}
		if ( 'wpress' === $job->option( 'format' ) ) {
			throw new Job_Exception( __( 'A .wpress backup cannot be added as a new site yet; restore it into a single site first.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( \SiteFerry\Import\Import::partial( $job ) || '' !== (string) $job->option( 'replace_url' ) ) {
			throw new Job_Exception( __( 'A new site is made from the whole backup at the address you give; parts of it or another address cannot be chosen too.', 'siteferry' ), Job_Exception::STEP );
		}
		$scheme  = (string) wp_parse_url( (string) $site['home'], PHP_URL_SCHEME );
		$address = Rules::new_site( (string) $job->option( 'new_site' ), $to_network, '' === $scheme ? 'http' : $scheme );
		if ( domain_exists( $address['domain'], $address['path'], (int) $to_network['id'] ) ) {
			/* translators: %s: a web address */
			throw new Job_Exception( sprintf( __( 'The network already has a site at %s; choose another name.', 'siteferry' ), $address['url'] ), Job_Exception::STEP );
		}
		if ( 1 === preg_match( '#^/[^/]+/$#', $address['path'] ) && '/' === (string) $to_network['path'] ) {
			$slug = trim( $address['path'], '/' );
			if ( get_page_by_path( $slug ) instanceof \WP_Post ) {
				/* translators: %s: a folder name */
				throw new Job_Exception( sprintf( __( 'The main site has a page at "%s"; a site with that name would hide it. Choose another name.', 'siteferry' ), $slug ), Job_Exception::STEP );
			}
		}
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->blogs ), ARRAY_A );
		$id     = isset( $status['Auto_increment'] ) ? (int) $status['Auto_increment'] : 0;
		if ( $id < 2 ) {
			$id = 1 + (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" );
		}
		$base = (string) $site['prefix'];
		$long = Rules::too_long( array_column( (array) $manifest['database']['tables'], 'name' ), (string) $manifest['database']['prefix'], $base . $id . '_' );
		if ( null !== $long ) {
			/* translators: %s: a table name */
			throw new Job_Exception( sprintf( __( 'The table %s would get a name too long for MySQL in the new site.', 'siteferry' ), $long ), Job_Exception::STEP );
		}
		$target = array(
			'home'           => $address['url'],
			'siteurl'        => $address['url'],
			'wp_content_url' => $site['wp_content_url'],
			'uploads_url'    => rtrim( (string) $site['uploads_url'], '/' ) . '/sites/' . $id,
			'abspath'        => $site['abspath'],
			'wp_content_dir' => $site['wp_content_dir'],
			'uploads_dir'    => rtrim( (string) $site['uploads_dir'], '/' ) . '/sites/' . $id,
		);
		$people = self::backup_users( $manifest );
		return array(
			'domain'  => $address['domain'],
			'path'    => $address['path'],
			'url'     => $address['url'],
			'id'      => $id,
			'target'  => $target,
			'summary' => array(
				'url'   => $address['url'],
				'id'    => $id,
				'users' => $people,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit (unused: done in one go).
	 * @throws Job_Exception On any failure (cleanup() then undoes it).
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;
		unset( $budget );
		$state = $job->state( 'merge' );
		if ( ! empty( $state['done'] ) ) {
			return true;
		}
		Io::quiet(
			static function () {
				return set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- making the site is done in one request.
			}
		);
		$inspect = $job->state( 'inspect' );
		$plan    = (array) $inspect['merge'];
		$temp    = (string) $job->option( 'temp_prefix' );
		$base    = (string) $wpdb->base_prefix;
		$state   = array(
			'site'    => 0,
			'users'   => array(),
			'moved'   => array(),
			'done'    => false,
			'created' => 0,
			'matched' => 0,
			'renamed' => array(),
		);

		// 1. The site.
		$title = (string) current( Raw_Query::column( $wpdb, 'SELECT option_value FROM ' . Sql::identifier( $temp . 'options' ) . " WHERE option_name = 'blogname'" ) );
		$made  = wp_insert_site(
			array(
				'domain'     => $plan['domain'],
				'path'       => $plan['path'],
				'network_id' => get_current_network_id(),
				'title'      => '' === $title ? $plan['url'] : $title,
			)
		);
		if ( is_wp_error( $made ) ) {
			/* translators: %s: error message */
			throw new Job_Exception( sprintf( __( 'WordPress could not make the new site: %s', 'siteferry' ), $made->get_error_message() ), Job_Exception::STEP );
		}
		$id            = (int) $made;
		$state['site'] = $id;
		$job->set_state( 'merge', $state );
		$job->save();
		if ( $id !== (int) $plan['id'] ) {
			throw new Job_Exception( __( 'Another site was added to the network meanwhile, so the new site got a different number. Nothing was kept; start again.', 'siteferry' ), Job_Exception::STEP );
		}
		$to = $base . $id . '_';
		$job->log( 'info', sprintf( 'Site %d made at %s.', $id, $plan['url'] ) );

		// 2. Tables: the backup's in place of the empty ones.
		$loaded  = (array) $job->state( 'database' )['tables'];
		$renames = array();
		foreach ( $loaded as $table ) {
			$suffix = substr( (string) $table, strlen( $temp ) );
			if ( in_array( $suffix, Rules::SHARED_TABLES, true ) ) {
				continue;
			}
			// WordPress's fresh empty table goes; any table the backup lacks stays as WordPress made it.
			Raw_Query::run( $wpdb, 'DROP TABLE IF EXISTS ' . Sql::identifier( $to . $suffix ) );
			$renames[] = Sql::identifier( (string) $table ) . ' TO ' . Sql::identifier( $to . $suffix );
		}
		if ( array() !== $renames ) {
			Raw_Query::run( $wpdb, 'RENAME TABLE ' . implode( ', ', $renames ) );
		}
		// The replace step already moved "<backup prefix>user_roles" to the base prefix.
		$options = Sql::identifier( $to . 'options' );
		Raw_Query::run( $wpdb, 'UPDATE ' . $options . ' SET option_name = ' . Sql::string_literal( $to . 'user_roles' ) . ' WHERE option_name = ' . Sql::string_literal( $base . 'user_roles' ) );
		Raw_Query::run( $wpdb, 'UPDATE ' . $options . " SET option_value = '' WHERE option_name IN ('upload_path', 'upload_url_path', 'fileupload_url')" );
		Raw_Query::run( $wpdb, 'DELETE FROM ' . $options . " WHERE option_name LIKE 'siteferry\\_%' OR option_name LIKE '\\_transient%siteferry%'" );

		// 3. Users.
		$map = $this->merge_users( $job, $temp, $base, $to, $state );
		$this->rewrite_ids( $to, $map );

		// 4. Files.
		$this->move_files( $job, $plan, $id, $state );

		// 5. Plugins active on the new site: those that exist.
		wp_clean_plugins_cache( false );
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$there  = get_plugins();
		$active = current( Raw_Query::column( $wpdb, 'SELECT option_value FROM ' . $options . " WHERE option_name = 'active_plugins'" ) );
		$list   = is_string( $active ) ? @unserialize( $active, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- WordPress stores the list serialized; no objects allowed.
		$keep   = array_values(
			array_filter(
				is_array( $list ) ? array_map( 'strval', $list ) : array(),
				static function ( string $file ) use ( $there ): bool {
					return isset( $there[ $file ] );
				}
			)
		);
		Raw_Query::run( $wpdb, 'UPDATE ' . $options . ' SET option_value = ' . Sql::string_literal( serialize( $keep ) ) . " WHERE option_name = 'active_plugins'" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the format WordPress stores the list in.
		if ( is_array( $list ) && count( $list ) > count( $keep ) ) {
			/* translators: %s: plugin files */
			$job->warn( sprintf( __( 'Not turned on in the new site because they are not installed: %s', 'siteferry' ), implode( ', ', array_diff( array_map( 'strval', $list ), $keep ) ) ) );
		}

		Database_Step::drop_prefixed( $wpdb, $temp );
		clean_blog_cache( $id );
		wp_cache_flush();
		$state['done'] = true;
		$job->set_state( 'merge', $state );
		$job->set_result(
			array_merge(
				$job->result(),
				array(
					'merged' => array(
						'site'          => $id,
						'url'           => $plan['url'],
						'users_created' => $state['created'],
						'users_matched' => $state['matched'],
						'renamed'       => $state['renamed'],
						'moved'         => count( $state['moved'] ),
					),
				)
			)
		);
		$job->set_progress( __( 'New site made', 'siteferry' ) );
		$job->log( 'info', sprintf( 'New site %d: %d users made, %d matched by e-mail.', $id, $state['created'], $state['matched'] ) );
		return true;
	}

	/**
	 * Undoes a merge that did not finish.
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		global $wpdb;
		$state = $job->state( 'merge' );
		if ( ! is_array( $state ) || ! empty( $state['done'] ) ) {
			return;
		}
		foreach ( array_reverse( (array) $state['moved'] ) as $move ) {
			Io::quiet(
				static function () use ( $move ) {
					return rename( (string) $move['to'], (string) $move['from'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				}
			);
		}
		if ( ! empty( $state['site'] ) ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			wp_delete_site( (int) $state['site'] );
		}
		foreach ( (array) $state['users'] as $user ) {
			if ( ! function_exists( 'wpmu_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
			}
			wpmu_delete_user( (int) $user );
		}
		Database_Step::drop_prefixed( $wpdb, (string) $job->option( 'temp_prefix' ) );
		$job->log( 'info', 'New site undone.' );
	}

	/**
	 * Merges the backup's users into the network; old id => new id.
	 *
	 * @param Job    $job   The job.
	 * @param string $temp  Temporary prefix (the backup's users tables).
	 * @param string $base  Base prefix.
	 * @param string $to    The new site's prefix.
	 * @param array  $state Step state (changed: users made).
	 * @return array<int,int>
	 */
	private function merge_users( Job $job, string $temp, string $base, string $to, array &$state ): array {
		global $wpdb;
		$users_t = Sql::identifier( $temp . 'users' );
		$meta_t  = Sql::identifier( $temp . 'usermeta' );
		$columns = Raw_Query::column( $wpdb, 'SHOW COLUMNS FROM ' . $users_t );
		$users   = array();
		foreach ( Raw_Query::rows( $wpdb, 'SELECT * FROM ' . $users_t ) as $values ) {
			$row = array_combine( $columns, $values );
			$users[ (int) $row['ID'] ] = array(
				'login' => (string) $row['user_login'],
				'email' => (string) $row['user_email'],
				'row'   => $row,
			);
		}
		$by_email = array();
		$logins   = array();
		foreach ( (array) $wpdb->get_results( "SELECT ID, user_login, user_email FROM {$wpdb->users}", ARRAY_A ) as $user ) {
			$by_email[ strtolower( (string) $user['user_email'] ) ] = (int) $user['ID'];
			$logins[ strtolower( (string) $user['user_login'] ) ]   = true;
		}
		$plan = Rules::map_users( $users, $by_email, $logins );
		$map  = array();
		foreach ( $plan as $old => $what ) {
			if ( 'match' === $what['action'] ) {
				$map[ $old ] = (int) $what['id'];
				++$state['matched'];
				continue;
			}
			$row = $users[ $old ]['row'];
			unset( $row['ID'] );
			$row['user_login']    = $what['login'];
			$row['user_nicename'] = substr( sanitize_title( $what['login'] ), 0, 50 );
			$known                = array_flip( $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->users}" ) );
			$row                  = array_intersect_key( $row, $known );
			if ( false === $wpdb->insert( $wpdb->users, $row ) ) {
				/* translators: %s: a user login */
				throw new Job_Exception( sprintf( __( 'The user %s could not be added to the network.', 'siteferry' ), $what['login'] ), Job_Exception::STEP );
			}
			$map[ $old ]        = (int) $wpdb->insert_id;
			$state['users'][]   = $map[ $old ];
			$state['created']  += 1;
			if ( $what['renamed'] ) {
				$state['renamed'][] = $users[ $old ]['login'] . ' → ' . $what['login'];
			}
			$job->set_state( 'merge', $state );
			$job->save();
		}

		// Meta: new users get all theirs (minus sessions); everyone gets the role on the new site.
		foreach ( Raw_Query::rows( $wpdb, 'SELECT user_id, meta_key, meta_value FROM ' . $meta_t ) as $values ) {
			$meta = array_combine( array( 'user_id', 'meta_key', 'meta_value' ), $values );
			$old = (int) $meta['user_id'];
			if ( ! isset( $map[ $old ] ) ) {
				continue;
			}
			$key = (string) $meta['meta_key'];
			$new = 'create' === $plan[ $old ]['action'];
			if ( 0 === strpos( $key, $base ) ) {
				$rest = substr( $key, strlen( $base ) );
				if ( preg_match( '/^\d+_/', $rest ) ) {
					continue; // Another site's key (the backup was a single site; should not happen).
				}
				$key = $to . $rest; // Role, level and settings belong to the new site.
			} elseif ( ! $new || 'session_tokens' === $key ) {
				continue; // An existing user's own data stays theirs.
			}
			$wpdb->insert(
				$wpdb->usermeta,
				array(
					'user_id'    => $map[ $old ],
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one insert per row, not a query.
					'meta_value' => (string) $meta['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- one insert per row, not a query.
				)
			);
		}
		foreach ( $map as $new_id ) {
			clean_user_cache( $new_id );
		}
		return $map;
	}

	/**
	 * Points authors and owners in the new site's tables at the network's user ids.
	 *
	 * @param string         $to  The new site's prefix.
	 * @param array<int,int> $map Old id => new id.
	 */
	private function rewrite_ids( string $to, array $map ): void {
		global $wpdb;
		$changes = array_filter(
			$map,
			static function ( $new, $old ) {
				return (int) $new !== (int) $old;
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( array() === $changes ) {
			return;
		}
		$case = 'CASE %s';
		foreach ( $changes as $old => $new ) {
			$case .= ' WHEN ' . (int) $old . ' THEN ' . (int) $new;
		}
		$case .= ' ELSE %s END';
		$in    = implode( ',', array_map( 'intval', array_keys( $changes ) ) );
		$cols  = array(
			'posts'    => 'post_author',
			'comments' => 'user_id',
			'links'    => 'link_owner',
		);
		$have  = array_flip( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $to ) . '%' ) ) );
		foreach ( $cols as $table => $column ) {
			if ( isset( $have[ $to . $table ] ) ) {
				$col = Sql::identifier( $column );
				Raw_Query::run( $wpdb, 'UPDATE ' . Sql::identifier( $to . $table ) . ' SET ' . $col . ' = ' . sprintf( $case, $col, $col ) . ' WHERE ' . $col . ' IN (' . $in . ')' );
			}
		}
		if ( isset( $have[ $to . 'postmeta' ] ) ) {
			Raw_Query::run( $wpdb, 'UPDATE ' . Sql::identifier( $to . 'postmeta' ) . ' SET meta_value = ' . sprintf( $case, 'meta_value', 'meta_value' ) . " WHERE meta_key = '_edit_last' AND meta_value IN ('" . implode( "','", array_map( 'intval', array_keys( $changes ) ) ) . "')" );
		}
	}

	/**
	 * Media into uploads/sites/{id}/; plugins and themes the network lacks into place.
	 *
	 * @param Job   $job   The job.
	 * @param array $plan  The plan.
	 * @param int   $id    New site id.
	 * @param array $state Step state (changed: what was moved).
	 * @throws Job_Exception If a file cannot be moved.
	 */
	private function move_files( Job $job, array $plan, int $id, array &$state ): void {
		$staged = Extract_Step::staging( $job ) . '/wp-content';
		if ( ! is_dir( $staged ) ) {
			return;
		}
		$uploads = rtrim( (string) $plan['target']['uploads_dir'], '/' );
		$places  = array(
			'uploads' => $uploads,
			'plugins' => WP_PLUGIN_DIR,
			'themes'  => get_theme_root(),
		);
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$skipped = array();
		foreach ( (array) scandir( $staged ) as $top ) {
			if ( '.' === $top || '..' === $top ) {
				continue;
			}
			if ( ! isset( $places[ $top ] ) ) {
				$skipped[] = 'wp-content/' . $top;
				continue;
			}
			$into = $places[ $top ];
			if ( ! is_dir( $into ) && ! wp_mkdir_p( $into ) ) {
				/* translators: %s: a folder */
				throw new Job_Exception( sprintf( __( 'Cannot make the folder %s.', 'siteferry' ), $into ), Job_Exception::STEP );
			}
			foreach ( (array) scandir( $staged . '/' . $top ) as $item ) {
				if ( '.' === $item || '..' === $item || ( 'uploads' === $top && 'sites' === $item ) ) {
					continue;
				}
				$from = $staged . '/' . $top . '/' . $item;
				$dest = $into . '/' . $item;
				if ( file_exists( $dest ) ) {
					if ( 'uploads' !== $top ) {
						$skipped[] = $top . '/' . $item . ' (' . __( 'already on the network; kept as it is', 'siteferry' ) . ')';
					}
					continue;
				}
				if ( ! Io::quiet(
					static function () use ( $from, $dest ) {
						return rename( $from, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					}
				) ) {
					/* translators: %s: a file or folder */
					throw new Job_Exception( sprintf( __( 'Cannot move %s into place.', 'siteferry' ), $item ), Job_Exception::STEP );
				}
				$state['moved'][] = array(
					'from' => $from,
					'to'   => $dest,
				);
				$job->set_state( 'merge', $state );
			}
		}
		$job->save();
		if ( array() !== $skipped ) {
			/* translators: %s: list of files and folders */
			$job->warn( sprintf( __( 'Not copied into the network: %s', 'siteferry' ), implode( '; ', $skipped ) ) );
		}
		$job->log( 'info', sprintf( 'Files of the new site %d moved into place (%d items).', $id, count( $state['moved'] ) ) );
	}

	/**
	 * The backup's users as the summary shows them: how many, and how many share an e-mail
	 * address with a user of this network.
	 *
	 * @param array $manifest Manifest.
	 * @return array{count:int|null}
	 */
	private static function backup_users( array $manifest ): array {
		unset( $manifest );
		return array( 'count' => null ); // Known only once the database is loaded; the result tells.
	}
}
