<?php
/**
 * Starting and confirming an import.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

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
 * confirmation, stage the files, verify their checksums, load the database into temporary
 * tables, search-replace, swap tables and files under maintenance mode, then remove the old
 * copies. Until the swap the live site is untouched; a failure after it puts everything back.
 */
final class Import {

	const STEPS = array( 'import.inspect', 'import.confirm', 'import.extract', 'import.verify', 'import.database', 'import.replace', 'import.swap', 'import.finish' );

	/** Option names a caller may set, with defaults. */
	const DEFAULTS = array(
		'replace_url'        => '',
		'replace'            => array(),
		'include_mu_plugins' => false,
		'include_drop_ins'   => false,
		'force'              => false,
		'confirmed'          => false,
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
		if ( is_multisite() ) {
			throw new Job_Exception( 'Multisite networks are not supported by this version of SiteFerry.', Job_Exception::STEP );
		}
		$real = realpath( $archive );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			throw new Job_Exception( sprintf( 'Cannot read the backup "%s".', $archive ), Job_Exception::STEP );
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

		$steps = $wpress ? array_merge( array( \SiteFerry\Wpress\Import_Step::STEP ), self::STEPS ) : self::STEPS;
		return Job::create( Storage::jobs_dir(), 'import', $steps, $options );
	}

	/**
	 * Lets a job waiting after its inspection continue.
	 *
	 * @param Job $job Import job.
	 * @throws Job_Exception If the job is not waiting for confirmation.
	 */
	public static function confirm( Job $job ): void {
		if ( Job::STATUS_WAITING !== $job->status() ) {
			throw new Job_Exception( 'This import is not waiting for confirmation.', Job_Exception::STEP );
		}
		$job->set_state( 'confirm', array( 'confirmed' => true ) );
		$job->set_status( Job::STATUS_PENDING );
		$job->log( 'info', 'Import confirmed.' );
		$job->save();
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
			throw new Job_Exception( 'Unknown import option: ' . implode( ', ', $unknown ), Job_Exception::STEP );
		}
		$options = array_merge( self::DEFAULTS, $options );

		$url = trim( (string) $options['replace_url'] );
		if ( '' !== $url && ( ! preg_match( '#^https?://[^/\s]+#i', $url ) || false === wp_parse_url( $url ) ) ) {
			throw new Job_Exception( sprintf( 'The new site address "%s" must start with http:// or https://.', $url ), Job_Exception::STEP );
		}

		$pairs = array();
		foreach ( (array) $options['replace'] as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || '' === (string) $pair[0] ) {
				throw new Job_Exception( 'Each extra replacement needs a text to find and a replacement.', Job_Exception::STEP );
			}
			$pairs[] = array( (string) $pair[0], (string) $pair[1] );
		}

		return array(
			'replace_url'        => untrailingslashit( $url ),
			'replace'            => $pairs,
			'include_mu_plugins' => (bool) $options['include_mu_plugins'],
			'include_drop_ins'   => (bool) $options['include_drop_ins'],
			'force'              => (bool) $options['force'],
			'confirmed'          => (bool) $options['confirmed'],
		);
	}
}
