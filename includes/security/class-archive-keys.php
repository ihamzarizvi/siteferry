<?php
/**
 * The key of an encrypted backup while a job works with it.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Security;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Crypto;
use SiteFerry\Jobs\Job;

defined( 'ABSPATH' ) || exit;

/**
 * A job that makes or reads an encrypted backup keeps the derived key (never the password) in its
 * state, encrypted with the site's secret key, so the slices after the first do not need the
 * password again. The Runner wipes it when the job ends. A check of a copy on a storage cannot
 * derive the key before the copy is downloaded; it keeps the password the same way until then.
 */
final class Archive_Keys {

	const STATE = 'crypto';

	/**
	 * Keeps a key (and, for a new file, its header) for a job.
	 *
	 * @param Job    $job    The job.
	 * @param string $key    Key.
	 * @param string $header Header of the file the job creates, or ''.
	 */
	public static function store(
		Job $job,
		#[\SensitiveParameter]
		string $key,
		string $header = ''
	): void {
		$job->set_state(
			self::STATE,
			array(
				'key'    => Secrets::site()->encrypt( base64_encode( $key ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'header' => base64_encode( $header ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
	}

	/**
	 * Keeps a password until the file it opens is at hand (a check of a stored copy).
	 *
	 * @param Job    $job      The job.
	 * @param string $password Password.
	 */
	public static function store_password(
		Job $job,
		#[\SensitiveParameter]
		string $password
	): void {
		$job->set_state( self::STATE, array( 'password' => Secrets::site()->encrypt( $password ) ) );
	}

	/**
	 * The job's key, derived from a kept password with the file's header if need be.
	 *
	 * @param Job         $job  The job.
	 * @param string|null $file The encrypted file, to derive the key from a kept password.
	 * @throws Archive_Exception If the kept password is wrong.
	 */
	public static function key( Job $job, ?string $file = null ): ?string {
		$state = $job->state( self::STATE );
		if ( isset( $state['key'] ) ) {
			$key = base64_decode( Secrets::site()->decrypt( (string) $state['key'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $key ? null : $key;
		}
		if ( isset( $state['password'] ) && null !== $file ) {
			$key = Crypto::unlock( $file, Secrets::site()->decrypt( (string) $state['password'] ) );
			self::store( $job, $key ); // The password is no longer needed.
			return $key;
		}
		return null;
	}

	/**
	 * Header of the file the job creates, or null.
	 *
	 * @param Job $job The job.
	 */
	public static function header( Job $job ): ?string {
		$state  = $job->state( self::STATE );
		$header = isset( $state['header'] ) ? base64_decode( (string) $state['header'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $header || '' === $header ? null : $header;
	}

	/**
	 * The path to open a backup with: through the decrypting stream when it is encrypted.
	 *
	 * @param Job    $job  The job.
	 * @param string $file Backup file (or, while it is written, the .part file).
	 * @throws Archive_Exception If the backup is encrypted and the job has no key for it.
	 */
	public static function path( Job $job, string $file ): string {
		$header = self::header( $job );
		if ( null !== $header ) {
			return Crypto::url( $file, (string) self::key( $job ), $header ); // A file this job writes.
		}
		if ( ! Crypto::is_encrypted( $file ) ) {
			return $file;
		}
		$key = self::key( $job, $file );
		if ( null === $key ) {
			throw new Archive_Exception( __( 'This backup is protected with a password.', 'siteferry' ), Archive_Exception::LOCKED );
		}
		return Crypto::url( $file, $key );
	}

	/**
	 * Wipes what the job kept.
	 *
	 * @param Job $job The job.
	 */
	public static function forget( Job $job ): void {
		if ( array() !== $job->state( self::STATE ) ) {
			$job->set_state( self::STATE, array() );
		}
	}
}
