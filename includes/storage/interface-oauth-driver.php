<?php
/**
 * Drivers that sign in with OAuth 2.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A storage the administrator connects through the provider's consent screen.
 *
 * The authorization record ("auth") holds the tokens and whatever the driver learns about the
 * account; Destinations stores it with the token fields encrypted and never sends it to the
 * browser.
 */
interface Oauth_Driver {

	/**
	 * Names of the auth fields that are secret (encrypted at rest).
	 *
	 * @return string[]
	 */
	public static function token_fields(): array;

	/**
	 * The provider's consent page for this client.
	 *
	 * @param string $redirect  Redirect URI registered with the provider.
	 * @param string $state     One-time value tying the answer to this request.
	 * @param string $challenge PKCE code challenge (S256).
	 */
	public function authorization_url( string $redirect, string $state, string $challenge ): string;

	/**
	 * Exchanges an authorization code for tokens.
	 *
	 * @param string $code     Code from the provider.
	 * @param string $verifier PKCE code verifier.
	 * @param string $redirect The same redirect URI.
	 * @return array The new auth record.
	 * @throws Storage_Exception If the provider refuses.
	 */
	public function connect( string $code, string $verifier, string $redirect ): array;

	/**
	 * Revokes the tokens at the provider (best effort) and forgets them.
	 */
	public function disconnect(): void;

	/**
	 * Gives the driver its saved auth record and a callback that saves changes to it (a refreshed
	 * access token, a created folder).
	 *
	 * @param array         $auth  Auth record (decrypted).
	 * @param callable|null $saver Receives the changed record.
	 */
	public function use_auth( array $auth, ?callable $saver ): void;

	/**
	 * Whether the driver holds a refresh token.
	 */
	public function connected(): bool;
}
