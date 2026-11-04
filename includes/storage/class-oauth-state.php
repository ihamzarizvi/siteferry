<?php
/**
 * One-time OAuth state and PKCE verifier.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Secret_Exception;
use SiteFerry\Security\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Ties the provider's answer to the request that the administrator started.
 *
 * start() makes a random state and a PKCE verifier and keeps them for the current user, for one
 * destination, for ten minutes (the state as a hash, the verifier encrypted). finish() accepts
 * the state once: any answer uses up what was kept, whether it matches or not.
 */
final class Oauth_State {

	/** Seconds the sign-in may take. */
	const TTL = 600;

	/**
	 * Starts a sign-in.
	 *
	 * @param string $destination Destination id.
	 * @return array{state:string,challenge:string}
	 */
	public static function start( string $destination ): array {
		$state     = bin2hex( random_bytes( 24 ) );
		$verifier  = self::base64url( random_bytes( 48 ) );
		$challenge = self::base64url( hash( 'sha256', $verifier, true ) );
		set_transient(
			self::key(),
			array(
				'destination' => $destination,
				'state'       => hash( 'sha256', $state ),
				'verifier'    => Secrets::site()->encrypt( $verifier ),
				'expires'     => time() + self::TTL,
			),
			self::TTL
		);
		return array(
			'state'     => $state,
			'challenge' => $challenge,
		);
	}

	/**
	 * Checks the state from the provider's answer and returns the PKCE verifier.
	 *
	 * @param string $destination Destination id.
	 * @param string $state       State from the answer.
	 * @throws Job_Exception If the state is unknown, used, expired or for another destination.
	 */
	public static function finish( string $destination, string $state ): string {
		$saved = get_transient( self::key() );
		delete_transient( self::key() );
		$valid = is_array( $saved ) && isset( $saved['destination'], $saved['state'], $saved['verifier'], $saved['expires'] )
			&& $saved['destination'] === $destination
			&& (int) $saved['expires'] >= time()
			&& preg_match( '/^[a-f0-9]{48}$/', $state )
			&& hash_equals( (string) $saved['state'], hash( 'sha256', $state ) );
		if ( ! $valid ) {
			throw new Job_Exception( 'This sign-in answer is not valid any more (each one works once, for 10 minutes, for the administrator who started it). Click "Connect" again.', Job_Exception::STEP );
		}
		try {
			return Secrets::site()->decrypt( (string) $saved['verifier'] );
		} catch ( Secret_Exception $e ) {
			throw new Job_Exception( $e->getMessage(), Job_Exception::STEP );
		}
	}

	/**
	 * Transient name for the current user.
	 */
	private static function key(): string {
		return 'siteferry_oauth_' . get_current_user_id();
	}

	/**
	 * Base64url without padding (RFC 7636).
	 *
	 * @param string $bytes Binary.
	 */
	public static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
