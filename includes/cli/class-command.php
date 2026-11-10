<?php
/**
 * WP-CLI commands: wp siteferry ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Export\Export;
use SiteFerry\Import\Import;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Back up, restore and migrate WordPress sites with SiteFerry.
 */
final class Command {

	/** Seconds per slice; WP-CLI has no time limit, slices only pace the progress output. */
	const SLICE_SECONDS = 5.0;

	/** Seconds between progress lines within one step. */
	const PROGRESS_EVERY = 15;

	/** Values accepted by --exclude, mapped to export options. */
	const EXCLUDE_ITEMS = array(
		'media'            => 'exclude_media',
		'plugins'          => 'exclude_plugins',
		'themes'           => 'exclude_themes',
		'inactive-plugins' => 'exclude_inactive_plugins',
		'inactive-themes'  => 'exclude_inactive_themes',
		'spam-comments'    => 'exclude_spam_comments',
		'revisions'        => 'exclude_revisions',
	);

	/**
	 * Registers the commands.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'siteferry', self::class );
		WP_CLI::add_command( 'siteferry backups', Backups_Command::class );
		WP_CLI::add_command( 'siteferry storage', Storage_Command::class );
		WP_CLI::add_command( 'siteferry schedule', Schedule_Command::class );
		WP_CLI::add_command( 'siteferry staging', Staging_Command::class );
	}

	/**
	 * Exports the database and wp-content into one backup file.
	 *
	 * Cache folders, WordPress's upgrade folders, other backup plugins' folders, .git and
	 * node_modules are always left out (cache can be kept with --include-cache). SiteFerry's own
	 * settings and storage credentials are never exported.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : tar: a SiteFerry backup. wpress: a .wpress backup, the format of the All-in-One WP Migration
	 * plugin, which that plugin can import. json: a tar backup (or --archive's), and print only a
	 * JSON object (the same as --json).
	 * ---
	 * default: tar
	 * options:
	 *   - tar
	 *   - wpress
	 *   - json
	 * ---
	 *
	 * [--archive=<type>]
	 * : tar or wpress: the kind of backup file, for use with --json (where --format means json).
	 * ---
	 * options:
	 *   - tar
	 *   - wpress
	 * ---
	 *
	 * [--compress]
	 * : Write a .tar.gz instead of a .tar (SiteFerry format only).
	 *
	 * [--level=<level>]
	 * : gzip compression level, 1 (fastest) to 9 (smallest).
	 * ---
	 * default: 6
	 * ---
	 *
	 * [--exclude=<items>]
	 * : Comma-separated list of things to leave out: media, plugins, themes, inactive-plugins,
	 * inactive-themes, spam-comments, revisions.
	 *
	 * [--include-cache]
	 * : Keep cache folders, which are left out by default.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated table names to leave out.
	 *
	 * [--exclude-paths=<paths>]
	 * : Comma-separated paths inside wp-content to leave out, for example uploads/2019. "*" matches
	 * any characters within one folder name.
	 *
	 * [--dir=<folder>]
	 * : Write the backup and its work files to this folder instead of SiteFerry's storage folder.
	 * Nothing is then written to the site, so this also works on a site where SiteFerry is not
	 * installed: wp --require=/path/to/siteferry/siteferry.php siteferry export --dir=...
	 *
	 * [--send=<id>]
	 * : Send the backup to remote storage set up on the Storage tab (s3) when it is ready.
	 *
	 * [--delete-after-send]
	 * : With --send: delete the copy on this server once the upload is complete.
	 *
	 * [--password[=<password>]]
	 * : Protect the backup with a password (AES-256-GCM; the file name ends in .enc). Without a
	 * value the password is taken from the SITEFERRY_PASSWORD environment variable, or asked for.
	 * It is not saved anywhere: without it the backup cannot be restored.
	 *
	 * [--site=<site>]
	 * : On a multisite network: back up only this site (its id or address) as a single-site backup,
	 * which imports into any single site. A whole-network backup without the other sites is made
	 * first, then the site is taken out of it and that backup is deleted. Not with --format=wpress,
	 * --password, --changes-only, --send or --dir.
	 *
	 * [--changes-only[=<backup>]]
	 * : Make a changes-only backup: the whole database, and only the files that are new or changed
	 * since a full backup on this server (by default the newest suitable one; or give its file
	 * name). It takes that backup's choices of what to leave out. Restoring it needs that full
	 * backup too. Not with --password, --format=wpress or --dir.
	 *
	 * [--porcelain]
	 * : Print only the path of the backup file.
	 *
	 * [--json]
	 * : Print only a JSON object: ok, name, path, size, created, files, bytes, seconds, format,
	 * encrypted, base, sent, deleted_local, warnings. (WP-CLI turns --json into --format=json; choose
	 * a .wpress file with --archive=wpress then.)
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry export
	 *     wp siteferry export --json
	 *     wp siteferry export --archive=wpress --json
	 *     wp siteferry export --changes-only
	 *     wp siteferry export --exclude=media,inactive-themes --compress
	 *     wp siteferry export --dir=/home/me/backups --porcelain
	 *     wp siteferry export --send=s3 --delete-after-send
	 *     wp siteferry export --format=wpress
	 *     wp siteferry export --password
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		// --format is tar, wpress or json (WP-CLI turns --json into --format=json); --archive gives
		// the kind of file when --format is json.
		$archive = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'tar' );
		if ( 'json' === $archive ) {
			$archive = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'archive', 'tar' );
		} elseif ( isset( $assoc_args['archive'] ) && $assoc_args['archive'] !== $archive && 'tar' !== $archive ) {
			WP_CLI::error( 'Use --format=wpress or --archive=wpress, not both with different values.' );
		} elseif ( isset( $assoc_args['archive'] ) ) {
			$archive = (string) $assoc_args['archive'];
		}
		$options = array(
			'format'            => $archive,
			'compress'          => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'compress', false ),
			'level'             => (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'level', 6 ),
			'exclude_cache'     => ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-cache', false ),
			'exclude_tables'    => self::csv( $assoc_args, 'exclude-tables' ),
			'exclude_paths'     => self::csv( $assoc_args, 'exclude-paths' ),
			'snapshot'          => true,
			'send_to'           => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'send', '' ),
			'delete_after_send' => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'delete-after-send', false ),
			'password'          => self::password( $assoc_args, true ),
		);
		$mode = Output::mode( $assoc_args );
		foreach ( self::csv( $assoc_args, 'exclude' ) as $item ) {
			if ( ! isset( self::EXCLUDE_ITEMS[ $item ] ) ) {
				Output::fail( $mode, sprintf( 'Unknown --exclude value "%s". Use: %s.', $item, implode( ', ', array_keys( self::EXCLUDE_ITEMS ) ) ) );
			}
			$options[ self::EXCLUDE_ITEMS[ $item ] ] = true;
		}

		$changes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'changes-only', null );
		if ( null !== $changes && false !== $changes ) {
			$base = true === $changes || '' === (string) $changes ? \SiteFerry\Incremental::latest_base() : (string) $changes;
			if ( null === $base ) {
				Output::fail( $mode, 'There is no full backup of this site on this server to build a changes-only backup on. Make a full backup first.' );
			}
			$options['changes_since'] = $base;
		}

		$one = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site', null );
		if ( null !== $one ) {
			$this->export_one_site( (string) $one, $options, $assoc_args, $mode );
			return;
		}

		$dir = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', null );
		if ( null !== $dir ) {
			$dir = self::prepare_dir( (string) $dir );
		}

		try {
			list( $job ) = Export::start( $options, $dir );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}

		Output::note( $mode, sprintf( 'Export started (job %s).', $job->id() ) );
		self::drive( $job, Output::HUMAN !== $mode );

		if ( Job::STATUS_FAILED === $job->status() ) {
			Output::fail( $mode, sprintf( 'Export failed: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}

		$result = $job->result();
		$this->print_warnings( $job, Output::HUMAN !== $mode );
		$fields = array(
			'name'          => (string) $result['name'],
			'path'          => (string) $result['file'],
			'size'          => (int) $result['size'],
			'created'       => gmdate( 'Y-m-d\TH:i:s\Z', $job->created() ),
			'files'         => (int) $result['files'],
			'bytes'         => (int) $result['bytes'],
			'seconds'       => (int) $result['seconds'],
			'format'        => (string) $job->option( 'format', 'tar' ),
			'encrypted'     => (bool) $job->option( 'encrypted', false ),
			'base'          => (string) $result['base'],
			'sent'          => isset( $result['sent'] ) ? self::sent_fields( $result ) : null,
			'deleted_local' => ! empty( $result['deleted_local'] ),
		);
		if ( isset( $result['sent'] ) ) {
			Output::note( $mode, sprintf( 'Sent to %s as %s.', $result['sent']['where'], $result['sent']['name'] ) );
		}
		if ( ! empty( $result['base'] ) ) {
			Output::note( $mode, sprintf( 'Changes only, built on %s (restoring it needs that backup too).', $result['base'] ) );
		}
		$message = ! empty( $result['deleted_local'] )
			? sprintf( 'Backup made and sent (%s); the copy on this server was deleted.', size_format( $result['size'], 1 ) )
			: sprintf(
				'Backup written to %s (%s; %s files, %s of site data; %s).',
				$result['file'],
				size_format( $result['size'], 1 ),
				number_format( $result['files'] ),
				size_format( $result['bytes'], 1 ),
				human_time_diff( 0, max( 1, $result['seconds'] ) )
			);
		Output::done( $mode, $fields, (string) $result['file'], $message, $job->warnings() );
	}

	/**
	 * Where a backup was sent, for --json: one entry per storage.
	 *
	 * @param array $result Job result with "sent" (and "sent_all" for several storages).
	 * @return array<int,array{place:string,where:string,name:string,ok:bool,error:string}>
	 */
	private static function sent_fields( array $result ): array {
		$all = isset( $result['sent_all'] ) ? (array) $result['sent_all'] : array( $result['sent'] + array( 'ok' => true ) );
		return array_map(
			static function ( array $one ): array {
				return array(
					'place' => isset( $one['destination'] ) ? (string) $one['destination'] : '',
					'where' => isset( $one['where'] ) ? (string) $one['where'] : '',
					'name'  => isset( $one['name'] ) ? (string) $one['name'] : '',
					'ok'    => ! empty( $one['ok'] ),
					'error' => isset( $one['error'] ) ? (string) $one['error'] : '',
				);
			},
			$all
		);
	}

	/**
	 * Imports a SiteFerry backup into this site, replacing its database and wp-content items.
	 *
	 * The backup is checked first (manifest, versions, disk space, checksums) and you are asked to
	 * confirm. The live site keeps running while files are staged and the database is loaded into
	 * temporary tables; then tables and files are swapped under maintenance mode. Any failure after
	 * that puts the previous tables and files back. SiteFerry's own settings and this site's
	 * SiteFerry plugin are kept. After the import, log in with the imported site's users.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Backup file: a .tar or .tar.gz made by SiteFerry, or a .wpress made by All-in-One WP Migration
	 * (without a password).
	 *
	 * [--replace-url=<url>]
	 * : Address the imported site gets. Default: this site's current address.
	 *
	 * [--replace=<pairs>]
	 * : Extra text to replace in the database, as "old=>new", several separated by ";".
	 *
	 * [--include-mu-plugins]
	 * : Also copy must-use plugins from the backup.
	 *
	 * [--include-drop-ins]
	 * : Also copy server-specific drop-ins (object-cache.php, advanced-cache.php, db.php).
	 *
	 * [--force]
	 * : Import even if the backup comes from a newer WordPress than this site runs.
	 *
	 * [--skip-safety-backup]
	 * : Do not make a safety backup of this site first. (By default SiteFerry backs up the whole
	 * site as it is before anything changes, so the import can be undone by restoring that backup.)
	 *
	 * [--password[=<password>]]
	 * : Password of a protected backup (.enc). Without a value it is taken from the
	 * SITEFERRY_PASSWORD environment variable, or asked for.
	 *
	 * [--only-database]
	 * : Restore only the database; the files on this site stay as they are.
	 *
	 * [--only-files]
	 * : Restore only the files in wp-content; the database stays as it is.
	 *
	 * [--tables=<tables>]
	 * : Restore only these tables, comma-separated, named as in the backup (e.g. wp_posts,wp_postmeta).
	 * Unless --paths is given too, no files are restored.
	 *
	 * [--paths=<paths>]
	 * : Restore only these items of wp-content, comma-separated: a top-level item (uploads,
	 * languages), or one plugin or theme (plugins/akismet, themes/twentytwentyfive). Unless
	 * --tables is given too, the database is not restored.
	 *
	 * [--as-new-site=<name>]
	 * : On a multisite network: add the single-site backup as a NEW site of the network instead of
	 * restoring (its folder name, or its subdomain on a subdomain network). Nothing of the network is
	 * replaced; users with an e-mail address the network has are matched to those users.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--porcelain]
	 * : Print only the name of the safety backup made first (nothing when there is none). Needs --yes.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, from, to, tables, files, rows_changed, partial,
	 * seconds, safety, inspection, warnings). Needs --yes. Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar --yes --json
	 *     wp siteferry import backup.tar.gz --replace-url=https://staging.example.com --yes
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar --paths=plugins/akismet
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar --tables=wp_posts,wp_postmeta
	 *
	 * @param string[] $args       Positional arguments: the backup file.
	 * @param array    $assoc_args Options.
	 */
	public function import( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		if ( Output::HUMAN !== $mode && empty( $assoc_args['yes'] ) ) {
			Output::fail( $mode, '--json and --porcelain need --yes (they cannot ask for confirmation).' );
		}
		$pairs = array();
		foreach ( array_filter( explode( ';', (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'replace', '' ) ), 'strlen' ) as $pair ) {
			$parts = explode( '=>', $pair, 2 );
			if ( 2 !== count( $parts ) || '' === $parts[0] ) {
				Output::fail( $mode, sprintf( 'Cannot read the replacement "%s"; use "old=>new".', $pair ) );
			}
			$pairs[] = $parts;
		}
		$options = array(
			'replace_url'        => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'replace-url', '' ),
			'replace'            => $pairs,
			'include_mu_plugins' => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-mu-plugins', false ),
			'include_drop_ins'   => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-drop-ins', false ),
			'force'              => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ),
			'safety'             => ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-safety-backup', false ),
			'password'           => self::password( $assoc_args ),
			'new_site'           => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'as-new-site', '' ),
		) + self::restore_parts( $assoc_args );
		if ( '' !== $options['new_site'] ) {
			$options['safety'] = false; // Nothing is replaced.
		}

		$quiet = Output::HUMAN !== $mode;
		try {
			list( $job ) = Import::start( (string) $args[0], $options );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Output::note( $mode, sprintf( 'Import started (job %s).', $job->id() ) );
		self::drive( $job, $quiet );
		if ( Job::STATUS_FAILED === $job->status() ) {
			Output::fail( $mode, sprintf( 'Import stopped before any change to this site: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}

		$inspection = $job->result()['inspection'];
		if ( ! $quiet ) {
			$this->print_inspection( $inspection );
		}
		WP_CLI::confirm( ! empty( $inspection['new_site'] ) ? sprintf( 'Add this backup to the network as a new site at %s?', $inspection['new_site']['url'] ) : ( '' !== (string) $inspection['restores'] ? 'Replace these parts of this site with the backup\'s?' : 'Replace this site\'s database and files with the backup?' ), $assoc_args );
		Import::confirm( $job );

		self::drive( $job, $quiet );
		if ( Job::STATUS_FAILED === $job->status() ) {
			$result = $job->result();
			$undone = empty( $result['rollback_errors'] ) ? 'The site was left as it was before the import.' : 'Restoring the previous site did not fully succeed; see the log.';
			Output::fail( $mode, sprintf( 'Import failed: %s %s (log: %s)', $job->error(), $undone, $job->path( 'job.log' ) ) );
		}

		$this->print_warnings( $job, $quiet );
		$result = $job->result();
		$safety = isset( $result['safety']['name'] ) ? (string) $result['safety']['name'] : '';
		if ( '' !== $safety ) {
			Output::note( $mode, sprintf( 'Safety backup of the site as it was: %s. Restore it to undo this import.', $safety ) );
		}
		$imported = $result['imported'];
		if ( isset( $result['merged'] ) ) {
			$merged = $result['merged'];
			Output::done(
				$mode,
				array(
					'name'          => basename( (string) $job->option( 'archive' ) ),
					'site'          => (int) $merged['site'],
					'url'           => (string) $merged['url'],
					'users_created' => (int) $merged['users_created'],
					'users_matched' => (int) $merged['users_matched'],
					'renamed'       => (array) $merged['renamed'],
					'tables'        => (int) $imported['tables'],
					'files'         => (int) $imported['files'],
					'seconds'       => (int) $imported['seconds'],
				),
				(string) $merged['url'],
				sprintf( 'Added as new site %d of the network: %s (%d users made, %d matched by e-mail%s).', $merged['site'], $merged['url'], $merged['users_created'], $merged['users_matched'], $merged['renamed'] ? '; renamed: ' . implode( ', ', $merged['renamed'] ) : '' ),
				$job->warnings()
			);
			return;
		}
		$fields   = array(
			'name'         => basename( (string) $job->option( 'archive' ) ),
			'from'         => (string) $imported['from'],
			'to'           => (string) $imported['to'],
			'tables'       => (int) $imported['tables'],
			'files'        => (int) $imported['files'],
			'rows_changed' => (int) $imported['rows_changed'],
			'partial'      => (string) $imported['partial'],
			'seconds'      => (int) $imported['seconds'],
			'safety'       => '' === $safety ? null : $safety,
			'inspection'   => $inspection,
		);
		$message = '' !== (string) $imported['partial']
			? sprintf( 'Restored %s from %s (%s); everything else on this site stayed as it was.', lcfirst( (string) $imported['partial'] ), $imported['from'], human_time_diff( 0, max( 1, $imported['seconds'] ) ) )
			: sprintf(
				'Imported %s into %s: %d tables, %s files, %s rows updated for the new address (%s). Log in with the imported site\'s users.',
				$imported['from'],
				$imported['to'],
				$imported['tables'],
				number_format( $imported['files'] ),
				number_format( $imported['rows_changed'] ),
				human_time_diff( 0, max( 1, $imported['seconds'] ) )
			);
		Output::done( $mode, $fields, $safety, $message, $job->warnings() );
	}

	/**
	 * Backs up one site of this network as a single-site backup: a network backup without the
	 * other sites' tables and media, then the site taken out of it (that backup is then deleted).
	 *
	 * @param string $which      Site id or address.
	 * @param array  $options    Export options.
	 * @param array  $assoc_args Command options.
	 * @param string $mode       Output mode.
	 */
	private function export_one_site( string $which, array $options, array $assoc_args, string $mode ): void {
		global $wpdb;
		if ( ! is_multisite() ) {
			Output::fail( $mode, '--site works on a multisite network; this is a single site.' );
		}
		foreach ( array( 'password', 'changes-only', 'send', 'dir', 'delete-after-send' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				Output::fail( $mode, sprintf( '--site cannot be combined with --%s.', $flag ) );
			}
		}
		if ( 'tar' !== $options['format'] ) {
			Output::fail( $mode, '--site makes a .tar backup; --format=wpress is not possible with it.' );
		}
		$id = ctype_digit( $which ) ? (int) $which : 0;
		if ( 0 === $id ) {
			$parts = wp_parse_url( false === strpos( $which, '://' ) ? 'http://' . $which : $which );
			$host  = isset( $parts['host'] ) ? $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';
			$path  = '/' . trim( isset( $parts['path'] ) ? $parts['path'] : '', '/' );
			$found = get_sites(
				array(
					'domain' => $host,
					'path'   => '/' === $path ? '/' : $path . '/',
					'number' => 1,
				)
			);
			$id    = $found ? (int) $found[0]->blog_id : 0;
		}
		if ( $id < 1 || ! get_site( $id ) ) {
			Output::fail( $mode, sprintf( 'This network has no site "%s".', $which ) );
		}
		$base   = (string) $wpdb->base_prefix;
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $base ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the list of tables, read once.
		$keep   = \SiteFerry\Network\Rules::table_map( $tables, $base, $id );
		$keep[ $base . 'sitemeta' ] = $base . 'sitemeta'; // Super admins and network plugins are read from it.
		$options['exclude_tables']  = array_values( array_diff( $tables, array_keys( $keep ) ) );
		$uploads                    = wp_upload_dir( null, false );
		$root                       = (string) $uploads['basedir'];
		if ( is_multisite() && 1 !== get_current_blog_id() ) {
			$root = (string) preg_replace( '#/sites/\d+$#', '', $root );
		}
		$paths = array();
		foreach ( (array) scandir( $root ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			if ( 'sites' === $item ) {
				foreach ( (array) scandir( $root . '/sites' ) as $sub ) {
					if ( ctype_digit( (string) $sub ) && (int) $sub !== $id ) {
						$paths[] = 'uploads/sites/' . $sub;
					}
				}
			} elseif ( 1 !== $id ) {
				$paths[] = 'uploads/' . $item;
			}
		}
		$options['exclude_paths'] = array_merge( $options['exclude_paths'], $paths );
		try {
			list( $job ) = Export::start( $options );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		Output::note( $mode, sprintf( 'Backing up the network without the other sites first (job %s).', $job->id() ) );
		self::drive( $job, Output::HUMAN !== $mode );
		if ( Job::STATUS_FAILED === $job->status() ) {
			Output::fail( $mode, sprintf( 'Export failed: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		$network = (string) $job->result()['file'];
		Output::note( $mode, sprintf( 'Taking site %d out of it...', $id ) );
		try {
			$made = \SiteFerry\Network\Extract::run( $network, $id );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, 'The site could not be taken out: ' . $e->getMessage() );
			return;
		} finally {
			if ( is_file( $network ) ) {
				wp_delete_file( $network );
				\SiteFerry\Contents::forget( basename( $network ) );
			}
		}
		Output::done(
			$mode,
			$made,
			(string) $made['path'],
			sprintf( 'Backup of site %d (%s) written to %s (%s; %d tables, %s files, %d users). It imports into any single site.', $id, $made['url'], $made['path'], size_format( $made['size'], 1 ), $made['tables'], number_format( $made['files'] ), $made['users'] )
		);
	}

	/**
	 * Prints what an import is about to do.
	 *
	 * @param array $inspection Inspection summary.
	 */
	private function print_inspection( array $inspection ): void {
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Backup:      %s (made %s)', $inspection['backup'], $inspection['created'] ) );
		WP_CLI::log( sprintf( 'From site:   %s (WordPress %s, PHP %s, MySQL %s)', $inspection['from'], $inspection['wordpress'], $inspection['php'], $inspection['mysql'] ) );
		WP_CLI::log( sprintf( 'Into site:   %s', $inspection['to'] ) );
		WP_CLI::log( sprintf( 'Contents:    %d tables (prefix %s), %s files, %s', $inspection['tables'], $inspection['prefix'], number_format( $inspection['files'] ), size_format( $inspection['bytes'], 1 ) ) );
		if ( '' !== (string) $inspection['restores'] ) {
			WP_CLI::log( sprintf( 'Restores:    %s', $inspection['restores'] ) );
		}
		WP_CLI::log( sprintf( 'Addresses:   %d search/replace pairs', $inspection['replacements'] ) );
		foreach ( $inspection['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		WP_CLI::log( '' );
	}

	/**
	 * Makes a key that lets another site move itself here (it works once, for 24 hours), or shows
	 * or cancels the current one.
	 *
	 * ## OPTIONS
	 *
	 * [--hours=<hours>]
	 * : How long the key works (1 to 168). Default: 24.
	 *
	 * [--status]
	 * : Show whether a key waits and what has arrived, without making a key.
	 *
	 * [--cancel]
	 * : Cancel the key (and anything half-received).
	 *
	 * [--porcelain]
	 * : Print only the key (or, with --status, only the state).
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (key and expires; with --status: state and received). Same
	 * as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry receive
	 *     wp siteferry receive --status
	 *     wp siteferry receive --json
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function receive( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		try {
			if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'cancel', false ) ) {
				\SiteFerry\Move\Receiver::cancel();
				Output::done( $mode, array( 'state' => 'cancelled' ), 'cancelled', 'The key is cancelled.' );
				return;
			}
			if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', false ) ) {
				$made = \SiteFerry\Move\Receiver::create( (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'hours', \SiteFerry\Move\Receiver::HOURS ) );
				if ( Output::HUMAN !== $mode ) {
					Output::done(
						$mode,
						array(
							'key'     => (string) $made['key'],
							'expires' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $made['expires'] ),
						),
						(string) $made['key'],
						''
					);
					return;
				}
				WP_CLI::line( $made['key'] );
				WP_CLI::log( sprintf( 'Run on the old site: wp siteferry move <key>   (valid until %s)', wp_date( 'Y-m-d H:i', $made['expires'] ) ) );
				return;
			}
			$status = \SiteFerry\Move\Receiver::status();
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		if ( Output::HUMAN !== $mode ) {
			$received = 'received' === $status['state'] && isset( $status['received'] ) ? array(
				'name' => (string) $status['received']['name'],
				'from' => (string) $status['received']['from'],
			) : null;
			Output::done(
				$mode,
				array(
					'state'    => (string) $status['state'],
					'received' => $received,
				),
				(string) $status['state'],
				''
			);
			return;
		}
		WP_CLI::log( 'Key: ' . $status['state'] );
		if ( 'received' === $status['state'] ) {
			WP_CLI::log( sprintf( 'Arrived from %s: %s. Restore it with: wp siteferry import %s', $status['received']['from'], $status['received']['name'], $status['received']['name'] ) );
		}
	}

	/**
	 * Moves this site to another server: backs it up, encrypted with the key, and sends it straight
	 * to the site that made the key ("wp siteferry receive" or Move on its SiteFerry page).
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The key from the new site.
	 *
	 * [--keep-local]
	 * : Keep the backup on this server too.
	 *
	 * [--porcelain]
	 * : Print only the backup's name on the new site.
	 *
	 * [--format=<format>]
	 * : json: print only a JSON object (ok, name, to, warnings). Same as --json.
	 *
	 * [--json]
	 * : The same as --format=json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry move SFMOVE1.eyJ1Ijoi...
	 *
	 * @param string[] $args       Positional arguments: the key.
	 * @param array    $assoc_args Options.
	 */
	public function move( $args, $assoc_args ): void {
		$mode = Output::mode( $assoc_args );
		try {
			$hello = \SiteFerry\Move\Sender::hello( \SiteFerry\Move\Sender::parse( (string) $args[0] ) );
			Output::note( $mode, sprintf( 'Moving to %s (WordPress %s).', $hello['site'], $hello['wordpress'] ) );
			list( $job ) = \SiteFerry\Move\Sender::start( (string) $args[0], (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-local', false ) );
		} catch ( \Throwable $e ) {
			Output::fail( $mode, $e->getMessage() );
			return;
		}
		self::drive( $job, Output::HUMAN !== $mode );
		if ( Job::STATUS_FAILED === $job->status() ) {
			Output::fail( $mode, sprintf( 'The move stopped: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		$moved = $job->result()['moved'];
		Output::done(
			$mode,
			array(
				'name' => (string) $moved['name'],
				'to'   => (string) $moved['to'],
			),
			(string) $moved['name'],
			sprintf( 'Sent to %s as %s. Finish the move there: "Move it here" on its SiteFerry page, or wp siteferry import %s.', $moved['to'], $moved['name'], $moved['name'] ),
			$job->warnings()
		);
	}
	/**
	 * Shows what takes the room: the database, media, plugins, themes, cache and the rest of
	 * wp-content, the largest files and tables, and the free space on this server.
	 *
	 * ## OPTIONS
	 *
	 * [--fresh]
	 * : Measure again even if the site was measured in the last hour.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry space
	 *     wp siteferry space --fresh --format=json
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function space( $args, $assoc_args ): void {
		$this->space_report( $assoc_args );
	}

	/**
	 * Lists SiteFerry's activity: backups, restores, uploads, downloads, checks and staging copies,
	 * newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Only from this day on (YYYY-MM-DD, in the site's time zone).
	 *
	 * [--type=<type>]
	 * : Only this kind: export, import, send, fetch, check, staging or staging-delete.
	 *
	 * [--format=<format>]
	 * : Output format. csv is the same file as "Download CSV" on the Activity tab.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry activity
	 *     wp siteferry activity --since=2026-11-01 --type=export --format=csv > activity.csv
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function activity( $args, $assoc_args ): void {
		unset( $args );
		$type = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', '' );
		try {
			$rows = \SiteFerry\Activity::all( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'since', '' ), $type );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$format = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		if ( 'csv' === $format ) {
			fwrite( STDOUT, \SiteFerry\Activity::csv( $rows ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI output.
			return;
		}
		if ( 'json' === $format ) {
			WP_CLI::line( Output::encode( $rows ) );
			return;
		}
		if ( 'table' === $format && array() === $rows ) {
			WP_CLI::log( 'No activity.' );
			return;
		}
		$fields = 'table' === $format ? array( 'time', 'type', 'what', 'result', 'duration', 'started_by', 'message' ) : array_keys( (array) reset( $rows ) );
		\WP_CLI\Utils\format_items( $format, $rows, array() === $fields ? array( 'time' ) : $fields );
	}

	/**
	 * The space report (wp siteferry space).
	 *
	 * @param array $assoc_args Options.
	 */
	private function space_report( $assoc_args ): void {
		$fresh = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'fresh', false );
		do {
			$result = \SiteFerry\Space::report( self::SLICE_SECONDS, $fresh );
			$fresh  = false;
		} while ( ! $result['complete'] );
		$report = $result['report'];
		if ( 'json' === \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$files = $report['files'];
		$db    = $report['database'];
		$rows  = array(
			array( 'part' => 'Database', 'size' => size_format( $db['bytes'], 1 ), 'note' => count( $db['tables'] ) . ' tables' ),
			array( 'part' => 'Media (uploads)', 'size' => size_format( $files['parts']['uploads']['bytes'], 1 ), 'note' => $files['parts']['uploads']['files'] . ' files' ),
			array( 'part' => 'Plugins', 'size' => size_format( $files['parts']['plugins']['bytes'], 1 ), 'note' => size_format( $files['parts']['plugins']['inactive'], 1 ) . ' inactive' ),
			array( 'part' => 'Themes', 'size' => size_format( $files['parts']['themes']['bytes'], 1 ), 'note' => size_format( $files['parts']['themes']['inactive'], 1 ) . ' inactive' ),
			array( 'part' => 'Cache', 'size' => size_format( $files['parts']['cache']['bytes'], 1 ), 'note' => 'left out by default' ),
			array( 'part' => 'Everything else', 'size' => size_format( $files['parts']['other']['bytes'], 1 ), 'note' => $files['parts']['other']['files'] . ' files' ),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'part', 'size', 'note' ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Largest files:' );
		foreach ( $files['largest'] as $file ) {
			WP_CLI::log( sprintf( '  %10s  %s', size_format( $file['bytes'], 1 ), $file['path'] ) );
		}
		WP_CLI::log( 'Largest tables:' );
		foreach ( array_slice( $db['tables'], 0, 5 ) as $table ) {
			WP_CLI::log( sprintf( '  %10s  %s (%d rows)', size_format( $table['bytes'], 1 ), $table['name'], $table['rows'] ) );
		}
		WP_CLI::log( sprintf( 'Post revisions: %d (%s). Spam comments: %d (%s).', $db['revisions']['count'], size_format( $db['revisions']['bytes'], 1 ), $db['spam']['count'], size_format( $db['spam']['bytes'], 1 ) ) );
		WP_CLI::success(
			sprintf(
				'The whole site is %s (%s files); %s free on this server; backups here take %s.',
				size_format( $files['bytes'] + $db['bytes'], 1 ),
				number_format( $files['files'] ),
				null === $report['free'] ? 'unknown space' : size_format( $report['free'], 1 ),
				size_format( $report['backups'], 1 )
			)
		);
	}

	/**
	 * The --password flag: its value, the SITEFERRY_PASSWORD environment variable, or a prompt.
	 *
	 * @param array $assoc_args Options.
	 * @param bool  $confirm    Ask twice (for a new backup).
	 */
	public static function password( array $assoc_args, bool $confirm = false ): string {
		$value = \WP_CLI\Utils\get_flag_value( $assoc_args, 'password', null );
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true !== $value ) {
			return (string) $value;
		}
		$env = getenv( 'SITEFERRY_PASSWORD' );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		$value = (string) \cli\prompt( 'Password', false, ': ', true );
		if ( $confirm && (string) \cli\prompt( 'The same password again', false, ': ', true ) !== $value ) {
			WP_CLI::error( 'The passwords do not match.' );
		}
		return $value;
	}

	/**
	 * Runs a job until it ends or waits for confirmation, printing progress.
	 *
	 * @param Job  $job   The job.
	 * @param bool $quiet No progress output.
	 */
	public static function drive( Job $job, bool $quiet ): void {
		$step    = null;
		$printed = 0;
		while ( ! $job->finished() && Job::STATUS_WAITING !== $job->status() ) {
			Runner::run_slice( $job, new Slice_Budget( self::SLICE_SECONDS ) );
			if ( $quiet ) {
				continue;
			}
			$progress = $job->progress();
			$changed  = $job->current_step() !== $step;
			if ( $changed || time() - $printed >= self::PROGRESS_EVERY || $job->finished() ) {
				$percent = $progress['total'] > 0 ? sprintf( ' (%d%%)', (int) floor( 100 * $progress['done'] / $progress['total'] ) ) : '';
				WP_CLI::log( $progress['message'] . $percent );
				$step    = $job->current_step();
				$printed = time();
			}
		}
	}

	/**
	 * Prints the warning count and the first warnings.
	 *
	 * @param Job  $job   The job.
	 * @param bool $quiet Only the count goes to STDERR.
	 */
	private function print_warnings( Job $job, bool $quiet ): void {
		$count = $job->warning_count();
		if ( 0 === $count ) {
			return;
		}
		WP_CLI::warning( sprintf( '%d warning(s). Full list in %s', $count, $job->path( 'job.log' ) ) );
		if ( ! $quiet ) {
			foreach ( array_slice( $job->warnings(), 0, 20 ) as $warning ) {
				WP_CLI::log( '  - ' . $warning );
			}
			if ( $count > 20 ) {
				WP_CLI::log( sprintf( '  ... and %d more.', $count - 20 ) );
			}
		}
	}

	/**
	 * Creates the --dir folder if needed and returns its absolute path.
	 *
	 * @param string $dir Folder from the command line.
	 */
	private static function prepare_dir( string $dir ): string {
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			WP_CLI::error( sprintf( 'Cannot create the folder "%s".', $dir ) );
		}
		$real = realpath( $dir );
		if ( false === $real || ! is_writable( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			WP_CLI::error( sprintf( 'The folder "%s" is not writable.', $dir ) );
		}
		return $real;
	}

	/**
	 * Import options for --only-database, --only-files, --tables and --paths.
	 *
	 * @param array $assoc_args Options.
	 */
	private static function restore_parts( array $assoc_args ): array {
		$only_db    = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-database', false );
		$only_files = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'only-files', false );
		$tables     = self::csv( $assoc_args, 'tables' );
		$paths      = self::csv( $assoc_args, 'paths' );
		if ( $only_db && $only_files ) {
			WP_CLI::error( 'Use --only-database or --only-files, not both.' );
		}
		if ( ( $only_db && array() !== $paths ) || ( $only_files && array() !== $tables ) ) {
			WP_CLI::error( '--only-database cannot be used with --paths, nor --only-files with --tables.' );
		}
		return array(
			'restore_database' => ! $only_files && ( array() === $paths || array() !== $tables ),
			'restore_tables'   => $tables,
			'restore_files'    => ! $only_db && ( array() === $tables || array() !== $paths ),
			'restore_paths'    => $paths,
		);
	}

	/**
	 * A comma-separated option as a list.
	 *
	 * @param array  $assoc_args Options.
	 * @param string $name       Option name.
	 * @return string[]
	 */
	private static function csv( array $assoc_args, string $name ): array {
		$value = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, $name, '' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
	}
}
