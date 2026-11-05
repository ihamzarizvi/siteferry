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
	 * [--porcelain]
	 * : Print only the path of the backup file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry export
	 *     wp siteferry export --exclude=media,inactive-themes --compress
	 *     wp siteferry export --dir=/home/me/backups --porcelain
	 *     wp siteferry export --send=s3 --delete-after-send
	 *     wp siteferry export --format=wpress
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
		);
		foreach ( self::csv( $assoc_args, 'exclude' ) as $item ) {
			if ( ! isset( self::EXCLUDE_ITEMS[ $item ] ) ) {
				WP_CLI::error( sprintf( 'Unknown --exclude value "%s". Use: %s.', $item, implode( ', ', array_keys( self::EXCLUDE_ITEMS ) ) ) );
			}
			$options[ self::EXCLUDE_ITEMS[ $item ] ] = true;
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
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry import mysite-2026-11-03-1a2b3c4d5e6f.tar
	 *     wp siteferry import backup.tar.gz --replace-url=https://staging.example.com --yes
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
		);

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

		$this->print_inspection( $job->result()['inspection'] );
		WP_CLI::confirm( 'Replace this site\'s database and files with the backup?', $assoc_args );
		Import::confirm( $job );

		self::drive( $job, false );
		if ( Job::STATUS_FAILED === $job->status() ) {
			$result = $job->result();
			$undone = empty( $result['rollback_errors'] ) ? 'The site was left as it was before the import.' : 'Restoring the previous site did not fully succeed; see the log.';
			WP_CLI::error( sprintf( 'Import failed: %s %s (log: %s)', $job->error(), $undone, $job->path( 'job.log' ) ) );
		}

		$this->print_warnings( $job, false );
		$imported = $job->result()['imported'];
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
		WP_CLI::log( sprintf( 'Addresses:   %d search/replace pairs', $inspection['replacements'] ) );
		foreach ( $inspection['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		WP_CLI::log( '' );
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
