<?php
/**
 * WP-CLI commands: wp siteferry ...
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Cli;

use SiteFerry\Export\Export;
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
	 * [--compress]
	 * : Write a .tar.gz instead of a .tar.
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
	 * [--porcelain]
	 * : Print only the path of the backup file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry export
	 *     wp siteferry export --exclude=media,inactive-themes --compress
	 *     wp siteferry export --dir=/home/me/backups --porcelain
	 *
	 * @param string[] $args       Positional arguments (none).
	 * @param array    $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		$options = array(
			'compress'       => (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'compress', false ),
			'level'          => (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'level', 6 ),
			'exclude_cache'  => ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-cache', false ),
			'exclude_tables' => self::csv( $assoc_args, 'exclude-tables' ),
			'exclude_paths'  => self::csv( $assoc_args, 'exclude-paths' ),
			'snapshot'       => true,
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
		$this->drive( $job, $porcelain );

		if ( Job::STATUS_FAILED === $job->status() ) {
			WP_CLI::error( sprintf( 'Export failed: %s (log: %s)', $job->error(), $job->path( 'job.log' ) ) );
		}

		$result = $job->result();
		$this->print_warnings( $job, $porcelain );
		if ( $porcelain ) {
			WP_CLI::line( $result['file'] );
			return;
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
	 * Runs a job to the end, printing progress.
	 *
	 * @param Job  $job   The job.
	 * @param bool $quiet No progress output.
	 */
	private function drive( Job $job, bool $quiet ): void {
		$step    = null;
		$printed = 0;
		while ( ! $job->finished() ) {
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
