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
	 * plugin, which that plugin can import.
	 * ---
	 * default: tar
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
	 * [--changes-only[=<backup>]]
	 * : Make a changes-only backup: the whole database, and only the files that are new or changed
	 * since a full backup on this server (by default the newest suitable one; or give its file
	 * name). It takes that backup's choices of what to leave out. Restoring it needs that full
	 * backup too. Not with --password, --format=wpress or --dir.
	 *
	 * [--porcelain]
	 * : Print only the path of the backup file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry export
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
		$options = array(
			'format'            => (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'tar' ),
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
		foreach ( self::csv( $assoc_args, 'exclude' ) as $item ) {
			if ( ! isset( self::EXCLUDE_ITEMS[ $item ] ) ) {
				WP_CLI::error( sprintf( 'Unknown --exclude value "%s". Use: %s.', $item, implode( ', ', array_keys( self::EXCLUDE_ITEMS ) ) ) );
			}
			$options[ self::EXCLUDE_ITEMS[ $item ] ] = true;
		}

		$changes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'changes-only', null );
		if ( null !== $changes && false !== $changes ) {
			$base = true === $changes || '' === (string) $changes ? \SiteFerry\Incremental::latest_base() : (string) $changes;
			if ( null === $base ) {
				WP_CLI::error( 'There is no full backup of this site on this server to build a changes-only backup on. Make a full backup first.' );
			}
			$options['changes_since'] = $base;
		}

		$dir = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', null );
		if ( null !== $dir ) {
			$dir = self::prepare_dir( (string) $dir );
		}
		$porcelain = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		try {
			list( $job ) = Export::start( $options, $dir );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( ! $porcelain ) {
			WP_CLI::log( sprintf( 'Export started (job %s).', $job->id() ) );
		}
		self::drive( $job, $porcelain );

		if ( Job::STATUS_FAILED === $job->status() ) {
			WP_CLI::error( sprintf( 'Export failed: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}

		$result = $job->result();
		$this->print_warnings( $job, $porcelain );
		if ( $porcelain ) {
			WP_CLI::line( $result['file'] );
			return;
		}
		if ( isset( $result['sent'] ) ) {
			WP_CLI::log( sprintf( 'Sent to %s as %s.', $result['sent']['where'], $result['sent']['name'] ) );
			if ( ! empty( $result['deleted_local'] ) ) {
				WP_CLI::success( sprintf( 'Backup made and sent (%s); the copy on this server was deleted.', size_format( $result['size'], 1 ) ) );
				return;
			}
		}
		if ( ! empty( $result['base'] ) ) {
			WP_CLI::log( sprintf( 'Changes only, built on %s (restoring it needs that backup too).', $result['base'] ) );
		}
		WP_CLI::success(
			sprintf(
				'Backup written to %s (%s; %s files, %s of site data; %s).',
				$result['file'],
				size_format( $result['size'], 1 ),
				number_format( $result['files'] ),
				size_format( $result['bytes'], 1 ),
				human_time_diff( 0, max( 1, $result['seconds'] ) )
			)
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
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar
	 *     wp siteferry import backup.tar.gz --replace-url=https://staging.example.com --yes
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar --paths=plugins/akismet
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar --tables=wp_posts,wp_postmeta
	 *
	 * @param string[] $args       Positional arguments: the backup file.
	 * @param array    $assoc_args Options.
	 */
	public function import( $args, $assoc_args ): void {
		$pairs = array();
		foreach ( array_filter( explode( ';', (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'replace', '' ) ), 'strlen' ) as $pair ) {
			$parts = explode( '=>', $pair, 2 );
			if ( 2 !== count( $parts ) || '' === $parts[0] ) {
				WP_CLI::error( sprintf( 'Cannot read the replacement "%s"; use "old=>new".', $pair ) );
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
		) + self::restore_parts( $assoc_args );

		try {
			list( $job ) = Import::start( (string) $args[0], $options );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		WP_CLI::log( sprintf( 'Import started (job %s).', $job->id() ) );
		self::drive( $job, false );
		if ( Job::STATUS_FAILED === $job->status() ) {
			WP_CLI::error( sprintf( 'Import stopped before any change to this site: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}

		$inspection = $job->result()['inspection'];
		$this->print_inspection( $inspection );
		WP_CLI::confirm( '' !== (string) $inspection['restores'] ? 'Replace these parts of this site with the backup\'s?' : 'Replace this site\'s database and files with the backup?', $assoc_args );
		Import::confirm( $job );

		self::drive( $job, false );
		if ( Job::STATUS_FAILED === $job->status() ) {
			$result = $job->result();
			$undone = empty( $result['rollback_errors'] ) ? 'The site was left as it was before the import.' : 'Restoring the previous site did not fully succeed; see the log.';
			WP_CLI::error( sprintf( 'Import failed: %s %s (log: %s)', $job->error(), $undone, $job->path( 'job.log' ) ) );
		}

		$this->print_warnings( $job, false );
		$result = $job->result();
		if ( isset( $result['safety']['name'] ) ) {
			WP_CLI::log( sprintf( 'Safety backup of the site as it was: %s. Restore it to undo this import.', $result['safety']['name'] ) );
		}
		$imported = $result['imported'];
		if ( '' !== (string) $imported['partial'] ) {
			WP_CLI::success( sprintf( 'Restored %s from %s (%s); everything else on this site stayed as it was.', lcfirst( (string) $imported['partial'] ), $imported['from'], human_time_diff( 0, max( 1, $imported['seconds'] ) ) ) );
			return;
		}
		WP_CLI::success(
			sprintf(
				'Imported %s into %s: %d tables, %s files, %s rows updated for the new address (%s). Log in with the imported site\'s users.',
				$imported['from'],
				$imported['to'],
				$imported['tables'],
				number_format( $imported['files'] ),
				number_format( $imported['rows_changed'] ),
				human_time_diff( 0, max( 1, $imported['seconds'] ) )
			)
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
	 * ## EXAMPLES
	 *
	 *     wp siteferry receive
	 *     wp siteferry receive --status
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function receive( $args, $assoc_args ): void {
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'cancel', false ) ) {
			\SiteFerry\Move\Receiver::cancel();
			WP_CLI::success( 'The key is cancelled.' );
			return;
		}
		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', false ) ) {
			$made = \SiteFerry\Move\Receiver::create( (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'hours', \SiteFerry\Move\Receiver::HOURS ) );
			WP_CLI::line( $made['key'] );
			WP_CLI::log( sprintf( 'Run on the old site: wp siteferry move <key>   (valid until %s)', wp_date( 'Y-m-d H:i', $made['expires'] ) ) );
			return;
		}
		$status = \SiteFerry\Move\Receiver::status();
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
	 * ## EXAMPLES
	 *
	 *     wp siteferry move SFMOVE1.eyJ1Ijoi...
	 *
	 * @param string[] $args       Positional arguments: the key.
	 * @param array    $assoc_args Options.
	 */
	public function move( $args, $assoc_args ): void {
		try {
			$hello = \SiteFerry\Move\Sender::hello( \SiteFerry\Move\Sender::parse( (string) $args[0] ) );
			WP_CLI::log( sprintf( 'Moving to %s (WordPress %s).', $hello['site'], $hello['wordpress'] ) );
			list( $job ) = \SiteFerry\Move\Sender::start( (string) $args[0], (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'keep-local', false ) );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}
		self::drive( $job, false );
		if ( Job::STATUS_FAILED === $job->status() ) {
			WP_CLI::error( sprintf( 'The move stopped: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}
		$moved = $job->result()['moved'];
		WP_CLI::success( sprintf( 'Sent to %s as %s. Finish the move there: "Move it here" on its SiteFerry page, or wp siteferry import %s.', $moved['to'], $moved['name'], $moved['name'] ) );
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
			WP_CLI::line( (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( array() === $rows ) {
			WP_CLI::log( 'No activity.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'time', 'type', 'what', 'result', 'duration', 'started_by', 'message' ) );
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
