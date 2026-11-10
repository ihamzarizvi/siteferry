<?php
/**
 * Starting and confirming an import.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Export\Site_Info;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Plugin;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates import jobs.
 *
 * Steps: inspect the backup (manifest, versions, disk space, replacements), wait for
 * confirmation, make a safety backup of the site as it is (unless declined), stage the files,
 * verify their checksums, load the database into temporary tables, search-replace, swap tables
 * and files under maintenance mode, then remove the old copies. Until the swap the live site is
 * untouched; a failure after it puts everything back.
 */
final class Import {

	const STEPS = array( 'import.inspect', 'import.confirm', 'import.safety', 'import.extract', 'import.verify', 'import.database', 'import.replace', 'import.swap', 'import.finish' );

	/** The last restore and the safety backup made before it, for "Undo this restore". */
	const UNDO_OPTION = 'siteferry_undo';

	/** Option names a caller may set, with defaults. */
	const DEFAULTS = array(
		'replace_url'        => '',
		'replace'            => array(),
		'include_mu_plugins' => false,
		'include_drop_ins'   => false,
		'force'              => false,
		'confirmed'          => false,
		'safety'             => true,
		// Partial restore: the database (all tables, or only these) and the files (all, or only
		// these paths in wp-content: a top-level item, or one plugin or theme).
		'restore_database'   => true,
		'restore_tables'     => array(),
		'restore_files'      => true,
		'restore_paths'      => array(),
	);

	/** Drop-ins tied to a server's own services; not copied unless asked for. */
	const DROP_INS = array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php' );

	/**
	 * Creates an import job.
	 *
	 * @param string $archive Backup file.
	 * @param array  $options Import options (see DEFAULTS).
	 * @return array{0:Job,1:string} The job and its token.
	 * @throws Job_Exception If the import cannot start.
	 */
	public static function start( string $archive, array $options = array() ): array {
		$real = realpath( $archive );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			throw new Job_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'Cannot read the backup "%s".', 'siteferry' ), $archive ), Job_Exception::STEP );
		}

		// A password-protected backup: the password must open it now; only the key is kept.
		$password = isset( $options['password'] ) && is_string( $options['password'] ) ? $options['password'] : '';
		unset( $options['password'] );
		$key = null;
		if ( \SiteFerry\Archive\Crypto::is_encrypted( $real ) ) {
			if ( '' === $password ) {
				throw new \SiteFerry\Archive\Archive_Exception( 'This backup is protected with a password; enter it to restore the backup.', \SiteFerry\Archive\Archive_Exception::LOCKED );
			}
			$key = \SiteFerry\Archive\Crypto::unlock( $real, $password );
		}

		$options = self::normalize( $options );
		$tag     = Storage::random_hex( 3 );
		$active  = (array) get_option( 'active_plugins', array() );
		// .wpress backups (All-in-One WP Migration) are read and converted by one more step first.
		$wpress = \SiteFerry\Wpress\Wpress::is_name( $real ) || \SiteFerry\Wpress\Reader::is_wpress( $real );

		$options['archive']     = $real;
		$options['format']      = $wpress ? 'wpress' : 'siteferry';
		$options['site']        = Site_Info::collect();
		$options['temp_prefix'] = 'sft' . $tag . '_';
		$options['old_prefix']  = 'sfo' . $tag . '_';
		$options['own_plugin']  = in_array( Plugin::basename(), $active, true ) ? Plugin::basename() : '';
		$options['own_folder']  = Plugin::folder();
		$options['own_network'] = is_multisite() && array_key_exists( Plugin::basename(), (array) get_site_option( 'active_sitewide_plugins', array() ) );

		$steps   = $wpress ? array_merge( array( \SiteFerry\Wpress\Import_Step::STEP ), self::STEPS ) : self::STEPS; // The safety step skips itself when declined.
		$created = Job::create( Storage::jobs_dir(), 'import', $steps, $options );
		if ( null !== $key ) {
			\SiteFerry\Security\Archive_Keys::store( $created[0], $key );
			$created[0]->save();
		}
		\SiteFerry\Hooks::action( 'siteferry_import_started', $created[0] );
		return $created;
	}

	/**
	 * Lets a job waiting after its inspection continue.
	 *
	 * @param Job       $job    Import job.
	 * @param bool|null $safety Make a safety backup first (null: as the job's options say).
	 * @throws Job_Exception If the job is not waiting for confirmation.
	 */
	public static function confirm( Job $job, ?bool $safety = null ): void {
		if ( Job::STATUS_WAITING !== $job->status() ) {
			throw new Job_Exception( __( 'This import is not waiting for confirmation.', 'siteferry' ), Job_Exception::STEP );
		}
		$job->set_state( 'confirm', array( 'confirmed' => true ) + ( null === $safety ? array() : array( 'safety' => $safety ) ) );
		$job->set_status( Job::STATUS_PENDING );
		$job->log( 'info', 'Import confirmed' . ( Safety_Step::wanted( $job ) ? '.' : ', without a safety backup.' ) );
		$job->save();
	}

	/**
	 * The last restore, while the safety backup made before it is still here: safety (its file
	 * name), backup (what was restored), time, from, to. Null otherwise.
	 */
	public static function undo_point(): ?array {
		$undo = get_option( self::UNDO_OPTION );
		if ( ! is_array( $undo ) || empty( $undo['safety'] ) ) {
			return null;
		}
		$dir = Storage::existing_backups_dir();
		if ( null === $dir || ! is_file( $dir . '/' . $undo['safety'] ) ) {
			return null;
		}
		return $undo;
	}

	/**
	 * Fills in defaults and validates values.
	 *
	 * @param array $options Raw options.
	 * @throws Job_Exception On invalid values.
	 */
	public static function normalize( array $options ): array {
		$unknown = array_diff( array_keys( $options ), array_keys( self::DEFAULTS ) );
		if ( array() !== $unknown ) {
			throw new Job_Exception( sprintf( /* translators: %s: option names, separated by commas */ __( 'Unknown import option: %s', 'siteferry' ), implode( ', ', $unknown ) ), Job_Exception::STEP );
		}
		$options = array_merge( self::DEFAULTS, $options );

		$url = trim( (string) $options['replace_url'] );
		if ( '' !== $url && ( ! preg_match( '#^https?://[^/\s]+#i', $url ) || false === wp_parse_url( $url ) ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a web address */ __( 'The new site address "%s" must start with http:// or https://.', 'siteferry' ), $url ), Job_Exception::STEP );
		}

		$pairs = array();
		foreach ( (array) $options['replace'] as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || '' === (string) $pair[0] ) {
				throw new Job_Exception( __( 'Each extra replacement needs a text to find and a replacement.', 'siteferry' ), Job_Exception::STEP );
			}
			$pairs[] = array( (string) $pair[0], (string) $pair[1] );
		}

		$tables = array();
		foreach ( (array) $options['restore_tables'] as $table ) {
			$table = trim( (string) $table );
			if ( ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $table ) ) {
				throw new Job_Exception( sprintf( /* translators: %s: a table name */ __( 'Invalid table name to restore: "%s".', 'siteferry' ), substr( $table, 0, 80 ) ), Job_Exception::STEP );
			}
			$tables[] = $table;
		}
		$paths = array();
		$whole = false;
		foreach ( (array) $options['restore_paths'] as $path ) {
			$path = trim( str_replace( '\\', '/', (string) $path ), '/' );
			if ( 'wp-content' === $path ) {
				$whole = true; // All of wp-content: the same as choosing no single paths.
				continue;
			}
			if ( 0 === strpos( $path, 'wp-content/' ) ) {
				$path = substr( $path, strlen( 'wp-content/' ) );
			}
			try {
				$path = \SiteFerry\Archive\Path_Guard::check( $path, false );
			} catch ( \SiteFerry\Archive\Archive_Exception $e ) {
				throw new Job_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'Invalid path to restore: "%s".', 'siteferry' ), \SiteFerry\Archive\Path_Guard::printable( $path ) ), Job_Exception::STEP );
			}
			$parts = explode( '/', $path );
			// Folders outside plugins and themes are replaced as a whole, so only their top level can be chosen.
			if ( count( $parts ) > 2 || ( 2 === count( $parts ) && ! in_array( $parts[0], array( 'plugins', 'themes' ), true ) ) ) {
				throw new Job_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'Only a whole folder in wp-content, or one plugin or theme, can be restored on its own; not "%s".', 'siteferry' ), \SiteFerry\Archive\Path_Guard::printable( $path ) ), Job_Exception::STEP );
			}
			$paths[] = $path;
		}
		$tables = array_values( array_unique( $tables ) );
		$paths  = $whole ? array() : array_values( array_unique( $paths ) );
		if ( count( $tables ) > 2000 || count( $paths ) > 500 ) {
			throw new Job_Exception( __( 'Too many items chosen to restore.', 'siteferry' ), Job_Exception::STEP );
		}

		return array(
			'replace_url'        => untrailingslashit( $url ),
			'replace'            => $pairs,
			'include_mu_plugins' => (bool) $options['include_mu_plugins'],
			'include_drop_ins'   => (bool) $options['include_drop_ins'],
			'force'              => (bool) $options['force'],
			'confirmed'          => (bool) $options['confirmed'],
			'safety'             => (bool) $options['safety'],
			'restore_database'   => (bool) $options['restore_database'],
			'restore_tables'     => $tables,
			'restore_files'      => (bool) $options['restore_files'],
			'restore_paths'      => $paths,
		);
	}

	/**
	 * Whether an import restores only some of the backup.
	 *
	 * @param Job $job Import job.
	 */
	public static function partial( Job $job ): bool {
		return ! $job->option( 'restore_database', true ) || array() !== (array) $job->option( 'restore_tables', array() )
			|| ! $job->option( 'restore_files', true ) || array() !== (array) $job->option( 'restore_paths', array() );
	}

	/**
	 * Whether a path in the backup is unpacked and checked: the manifest, the dump and the checksum
	 * list always; files in wp-content when the files are restored and the path is chosen (or a
	 * folder above a chosen one).
	 *
	 * @param Job    $job  Import job.
	 * @param string $path Path in the backup ("wp-content/plugins/x/x.php").
	 */
	public static function wanted_path( Job $job, string $path ): bool {
		$path = trim( $path, '/' );
		if ( 'wp-content' !== $path && 0 !== strpos( $path, 'wp-content/' ) ) {
			return true; // Not files of the site: the extractor's own rules decide.
		}
		if ( ! $job->option( 'restore_files', true ) ) {
			return false;
		}
		$chosen = (array) $job->option( 'restore_paths', array() );
		if ( array() === $chosen || 'wp-content' === $path ) {
			return true;
		}
		$relative = substr( $path, strlen( 'wp-content/' ) );
		foreach ( $chosen as $item ) {
			if ( $relative === $item || 0 === strpos( $relative, $item . '/' ) || 0 === strpos( $item . '/', $relative . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The tables to load, as suffixes after the backup's prefix; null for all of them.
	 *
	 * @param Job $job Import job.
	 * @return string[]|null
	 */
	public static function table_suffixes( Job $job ): ?array {
		if ( ! $job->option( 'restore_database', true ) ) {
			return array();
		}
		$chosen = (array) $job->option( 'restore_tables', array() );
		if ( array() === $chosen ) {
			return null;
		}
		$prefix = (string) $job->state( 'inspect' )['prefix'];
		return array_map(
			static function ( string $table ) use ( $prefix ): string {
				return 0 === strpos( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : $table;
			},
			$chosen
		);
	}
}
