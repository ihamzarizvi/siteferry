<?php
/**
 * SiteFerry sign-in relay: one-click Google Drive and Dropbox sign-in for SiteFerry sites.
 *
 * @package SiteFerry_Relay
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry_Relay;

/**
 * Every SiteFerry site signs in with the same Google and Dropbox apps, and the providers only
 * send the browser back to the one redirect address registered for each app: this relay's. The
 * relay passes the browser through, holds the Google client secret so that the plugin does not
 * have to, and does nothing else.
 *
 * Google Drive:
 * - GET  /google/start     The site's browser arrives with its return address, its one-time state
 *                          and its PKCE challenge. The relay signs return address + state (valid 10
 *                          minutes) and sends the browser to Google's consent page.
 * - GET  /google/callback  Google returns the browser with a code. The relay checks its signature
 *                          and sends the browser back to the site's SiteFerry page with the code and
 *                          the site's state. The code is useless without the site's PKCE verifier.
 * - POST /google/token     The site exchanges the code (with its verifier) or renews its access
 *                          (with its refresh token). The relay adds the client ID and secret,
 *                          forwards the request to Google and returns Google's answer unchanged.
 *
 * Dropbox (no app secret: Dropbox accepts PKCE alone, so the tokens never pass through here):
 * - GET  /google/picker    The public details of Google's folder picker (API key, project number),
 *                           so a site's admin page can open it. Nothing secret.
 * - GET  /dropbox/start     As /google/start, to Dropbox's authorization page.
 * - GET  /dropbox/callback  As /google/callback.
 * - GET  /dropbox/app       The app key and this redirect address (both public), which the site
 *                           needs to exchange the code with Dropbox itself and to renew its access.
 *
 * - GET  /                  Health check.
 *
 * Nothing is stored or logged: no tokens, no codes, no request bodies. Return addresses must be
 * a SiteFerry page (…/wp-admin/admin.php?page=siteferry&oauth=gdrive or =dropbox), so the relay
 * cannot be used to send people anywhere else.
 */
final class Relay {

	/** Seconds a sign-in may take. */
	const STATE_TTL = 600;

	/** The only Google scope requested. */
	const SCOPE = 'https://www.googleapis.com/auth/drive.file';

	/** The Dropbox permissions requested. */
	const DROPBOX_SCOPES = 'account_info.read files.metadata.read files.content.read files.content.write';

	const GOOGLE_AUTH  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
	const DROPBOX_AUTH = 'https://www.dropbox.com/oauth2/authorize';

	/** Dropbox returns at most 500 bytes of state. */
	const DROPBOX_STATE_MAX = 500;

	/** Provider => the SiteFerry storage id in the return address. */
	const PROVIDERS = array(
		'google'  => 'gdrive',
		'dropbox' => 'dropbox',
	);

	/** Path => what it does. */
	const ROUTES = array(
		'google/start'     => 'start',
		'google/callback'  => 'callback',
		'google/token'     => 'token',
		'google/picker'    => 'picker',
		'dropbox/start'    => 'start',
		'dropbox/callback' => 'callback',
		'dropbox/app'      => 'app',
	);

	/** Largest request body accepted. */
	const MAX_BODY = 16384;

	/**
	 * Settings: base_url, signing_key, google_client_id, google_client_secret, google_picker_key,
	 * google_app_id (the Google Cloud project number), dropbox_app_key,
	 * and for tests google_auth_url, google_token_url and dropbox_auth_url.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Sends a form POST: (string $url, array $fields) => ['status' => int, 'body' => string] or null.
	 *
	 * @var callable
	 */
	private $post;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param array         $config Settings.
	 * @param callable      $post   HTTP POST (see $post).
	 * @param callable|null $clock  Current time (tests).
	 */
	public function __construct( array $config, callable $post, ?callable $clock = null ) {
		$this->config = $config + array(
			'google_auth_url'  => self::GOOGLE_AUTH,
			'google_token_url' => self::GOOGLE_TOKEN,
			'dropbox_auth_url' => self::DROPBOX_AUTH,
		);
		$this->post   = $post;
		$this->clock  = null === $clock ? 'time' : $clock;
	}

	/**
	 * Handles one request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path.
	 * @param array  $query  Query parameters.
	 * @param string $body   Request body.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public function handle( string $method, string $path, array $query, string $body ): array {
		if ( ! $this->configured() ) {
			return self::text( 503, 'The SiteFerry relay is not configured.' );
		}
		$base = rtrim( (string) parse_url( (string) $this->config['base_url'], PHP_URL_PATH ), '/' );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = (string) substr( $path, strlen( $base ) );
		}
		$route = '/' . trim( $path, '/' );

		if ( '/' === $route && 'GET' === $method ) {
			return self::text( 200, 'SiteFerry relay' );
		}
		$key = trim( $route, '/' );
		if ( ! isset( self::ROUTES[ $key ] ) ) {
			return self::text( 404, 'Not found.' );
		}
		$provider = strtok( $key, '/' );
		$action   = self::ROUTES[ $key ];
		if ( ! $this->offers( $provider ) ) {
			$message = sprintf( '%s sign-in is not set up on this relay.', 'google' === $provider ? 'Google Drive' : 'Dropbox' );
			return in_array( $action, array( 'token', 'app', 'picker' ), true ) ? self::json( 503, array( 'error' => 'temporarily_unavailable', 'error_description' => $message ) ) : self::text( 503, $message );
		}
		if ( 'token' === $action ) {
			return 'POST' === $method ? $this->token( $body ) : self::json( 405, array( 'error' => 'invalid_request', 'error_description' => 'Use POST.' ) );
		}
		if ( 'GET' !== $method ) {
			return self::text( 405, 'Use GET.' );
		}
		if ( 'start' === $action ) {
			return $this->start( $provider, $query );
		}
		if ( 'callback' === $action ) {
			return $this->callback( $provider, $query );
		}
		if ( 'picker' === $action ) {
			return $this->picker();
		}
		return self::json(
			200,
			array(
				'app_key'      => $this->config['dropbox_app_key'],
				'redirect_uri' => $this->callback_url( 'dropbox' ),
			)
		);
	}

	/**
	 * GET /google/picker: the Picker's browser key and the project number (both public by design;
	 * the key should be restricted to the Google Picker API in the Cloud console).
	 */
	private function picker(): array {
		$key = isset( $this->config['google_picker_key'] ) && is_string( $this->config['google_picker_key'] ) ? $this->config['google_picker_key'] : '';
		$app = isset( $this->config['google_app_id'] ) ? (string) $this->config['google_app_id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $key ) || ! preg_match( '/^[0-9]{6,20}$/', $app ) ) {
			return self::json( 503, array( 'error' => 'temporarily_unavailable', 'error_description' => 'The Google Drive folder picker is not set up on this relay.' ) );
		}
		return self::json(
			200,
			array(
				'api_key' => $key,
				'app_id'  => $app,
			)
		);
	}

	/**
	 * GET /<provider>/start: checks the request and sends the browser to the provider.
	 *
	 * @param string $provider google or dropbox.
	 * @param array  $query    Query.
	 */
	private function start( string $provider, array $query ): array {
		$return    = self::param( $query, 'return' );
		$state     = self::param( $query, 'state' );
		$challenge = self::param( $query, 'challenge' );
		if ( ! self::valid_return( $return, self::PROVIDERS[ $provider ] ) || ! preg_match( '/^[a-f0-9]{48}$/', $state ) || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $challenge ) ) {
			return self::text( 400, 'This sign-in request is not valid. Start again from the SiteFerry page of your site.' );
		}
		$signed = $this->sign(
			array(
				'p' => $provider,
				'r' => $return,
				's' => $state,
				'e' => $this->now() + self::STATE_TTL,
			)
		);
		if ( 'google' === $provider ) {
			$fields = array(
				'client_id'             => $this->config['google_client_id'],
				'redirect_uri'          => $this->callback_url( 'google' ),
				'response_type'         => 'code',
				'scope'                 => self::SCOPE,
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'state'                 => $signed,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			);
			return self::redirect( $this->config['google_auth_url'] . '?' . http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ) );
		}
		if ( strlen( $signed ) > self::DROPBOX_STATE_MAX ) {
			return self::text( 400, 'The address of this site is too long for Dropbox\'s sign-in. Use your own Dropbox app instead (SiteFerry, Storage tab, Dropbox).' );
		}
		$fields = array(
			'client_id'             => $this->config['dropbox_app_key'],
			'redirect_uri'          => $this->callback_url( 'dropbox' ),
			'response_type'         => 'code',
			'token_access_type'     => 'offline',
			'scope'                 => self::DROPBOX_SCOPES,
			'state'                 => $signed,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		return self::redirect( $this->config['dropbox_auth_url'] . '?' . http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ) );
	}

	/**
	 * GET /<provider>/callback: sends the browser back to the site with the code (or the refusal).
	 *
	 * @param string $provider google or dropbox.
	 * @param array  $query    Query.
	 */
	private function callback( string $provider, array $query ): array {
		$data = $this->verify( self::param( $query, 'state' ) );
		if ( null === $data || $provider !== $data['p'] ) {
			return self::text( 400, 'This sign-in answer is not valid any more (it works once, for 10 minutes). Start again from the SiteFerry page of your site.' );
		}
		$error = self::param( $query, 'error' );
		if ( '' !== $error ) {
			$error = (string) preg_replace( '/[^a-z_]/', '', strtolower( $error ) );
			return self::redirect( $data['r'] . '&' . http_build_query( array( 'error' => '' === $error ? 'access_denied' : $error, 'state' => $data['s'] ), '', '&', PHP_QUERY_RFC3986 ) );
		}
		$code = self::param( $query, 'code' );
		if ( ! preg_match( '/^[\x21-\x7e]{1,2048}$/', $code ) ) {
			return self::text( 400, sprintf( '%s sent no authorization code. Start again from the SiteFerry page of your site.', 'google' === $provider ? 'Google' : 'Dropbox' ) );
		}
		return self::redirect( $data['r'] . '&' . http_build_query( array( 'code' => $code, 'state' => $data['s'] ), '', '&', PHP_QUERY_RFC3986 ) );
	}

	/**
	 * POST /google/token: adds the client credentials and forwards to Google.
	 *
	 * @param string $body Form body.
	 */
	private function token( string $body ): array {
		if ( strlen( $body ) > self::MAX_BODY ) {
			return self::json( 413, array( 'error' => 'invalid_request', 'error_description' => 'Request too large.' ) );
		}
		$form = array();
		parse_str( $body, $form );
		$grant = self::param( $form, 'grant_type' );
		$base  = array(
			'client_id'     => $this->config['google_client_id'],
			'client_secret' => $this->config['google_client_secret'],
		);
		if ( 'authorization_code' === $grant ) {
			$code     = self::param( $form, 'code' );
			$verifier = self::param( $form, 'code_verifier' );
			if ( ! preg_match( '/^[\x21-\x7e]{1,2048}$/', $code ) || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
				return self::json( 400, array( 'error' => 'invalid_request', 'error_description' => 'A code and a PKCE code verifier are required.' ) );
			}
			$fields = array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $verifier,
				'redirect_uri'  => $this->callback_url( 'google' ),
			) + $base;
		} elseif ( 'refresh_token' === $grant ) {
			$refresh = self::param( $form, 'refresh_token' );
			if ( ! preg_match( '/^[\x21-\x7e]{1,2048}$/', $refresh ) ) {
				return self::json( 400, array( 'error' => 'invalid_request', 'error_description' => 'A refresh token is required.' ) );
			}
			$fields = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			) + $base;
		} else {
			return self::json( 400, array( 'error' => 'unsupported_grant_type' ) );
		}

		$answer = call_user_func( $this->post, (string) $this->config['google_token_url'], $fields );
		if ( ! is_array( $answer ) || ! is_array( json_decode( (string) $answer['body'], true ) ) ) {
			return self::json( 502, array( 'error' => 'temporarily_unavailable', 'error_description' => 'Google could not be reached; try again later.' ) );
		}
		return array(
			'status'  => (int) $answer['status'],
			'headers' => self::headers( 'application/json; charset=utf-8' ),
			'body'    => (string) $answer['body'],
		);
	}

	/**
	 * Whether a return address is a SiteFerry page: http(s), a host, the path
	 * …/wp-admin/admin.php and exactly the query page=siteferry&oauth=<destination>.
	 *
	 * @param string $url         Address.
	 * @param string $destination Storage id.
	 */
	public static function valid_return( string $url, string $destination ): bool {
		if ( '' === $url || strlen( $url ) > 2000 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'], $parts['query'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || ! preg_match( '/^[A-Za-z0-9.-]+$/', $parts['host'] ) || ! preg_match( '#/wp-admin/admin\.php$#', $parts['path'] ) ) {
			return false;
		}
		$query = array();
		parse_str( $parts['query'], $query );
		ksort( $query );
		return array(
			'oauth' => $destination,
			'page'  => 'siteferry',
		) === $query;
	}

	/**
	 * Signs data: base64url(JSON) "." base64url(HMAC-SHA256).
	 *
	 * @param array $data Data.
	 */
	private function sign( array $data ): string {
		$payload = self::base64url( (string) json_encode( $data, JSON_UNESCAPED_SLASHES ) );
		return $payload . '.' . self::base64url( hash_hmac( 'sha256', $payload, (string) $this->config['signing_key'], true ) );
	}

	/**
	 * Checks a signed value and its expiry.
	 *
	 * @param string $signed Signed value.
	 * @return array|null The data, or null if it was changed or has expired.
	 */
	private function verify( string $signed ): ?array {
		$parts = explode( '.', $signed );
		if ( 2 !== count( $parts ) || strlen( $signed ) > 4096 ) {
			return null;
		}
		$expected = self::base64url( hash_hmac( 'sha256', $parts[0], (string) $this->config['signing_key'], true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
		if ( ! is_array( $data ) || ! isset( $data['p'], $data['r'], $data['s'], $data['e'] ) || (int) $data['e'] < $this->now() ) {
			return null;
		}
		return $data;
	}

	/**
	 * Whether the settings every route needs are there: the address and a signing key.
	 */
	private function configured(): bool {
		return ! empty( $this->config['base_url'] ) && is_string( $this->config['base_url'] )
			&& isset( $this->config['signing_key'] ) && is_string( $this->config['signing_key'] ) && strlen( $this->config['signing_key'] ) >= 32;
	}

	/**
	 * Whether the relay is set up for a provider (a relay may offer only one).
	 *
	 * @param string $provider google or dropbox.
	 */
	private function offers( string $provider ): bool {
		if ( 'dropbox' === $provider ) {
			return isset( $this->config['dropbox_app_key'] ) && is_string( $this->config['dropbox_app_key'] ) && 1 === preg_match( '/^[A-Za-z0-9]{8,64}$/', $this->config['dropbox_app_key'] );
		}
		foreach ( array( 'google_client_id', 'google_client_secret' ) as $key ) {
			if ( empty( $this->config[ $key ] ) || ! is_string( $this->config[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The redirect address registered with a provider.
	 *
	 * @param string $provider google or dropbox.
	 */
	public function callback_url( string $provider = 'google' ): string {
		return rtrim( (string) $this->config['base_url'], '/' ) . '/' . $provider . '/callback';
	}

	/**
	 * Current Unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * A string parameter ('' when missing or not a string).
	 *
	 * @param array  $from Parameters.
	 * @param string $key  Name.
	 */
	private static function param( array $from, string $key ): string {
		return isset( $from[ $key ] ) && is_string( $from[ $key ] ) ? $from[ $key ] : '';
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Headers sent with every answer.
	 *
	 * @param string $type Content type.
	 */
	private static function headers( string $type ): array {
		return array(
			'Content-Type'           => $type,
			'Cache-Control'          => 'no-store',
			'Referrer-Policy'        => 'no-referrer',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * A redirect.
	 *
	 * @param string $url Target.
	 */
	private static function redirect( string $url ): array {
		return array(
			'status'  => 302,
			'headers' => array( 'Location' => $url ) + self::headers( 'text/plain; charset=utf-8' ),
			'body'    => '',
		);
	}

	/**
	 * A plain-text answer.
	 *
	 * @param int    $status Status.
	 * @param string $text   Text.
	 */
	private static function text( int $status, string $text ): array {
		return array(
			'status'  => $status,
			'headers' => self::headers( 'text/plain; charset=utf-8' ),
			'body'    => $text . "\n",
		);
	}

	/**
	 * A JSON answer.
	 *
	 * @param int   $status Status.
	 * @param array $data   Data.
	 */
	private static function json( int $status, array $data ): array {
		return array(
			'status'  => $status,
			'headers' => self::headers( 'application/json; charset=utf-8' ),
			'body'    => (string) json_encode( $data, JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Form POST with curl, certificates checked.
	 *
	 * @param string $url    Address.
	 * @param array  $fields Form fields.
	 * @return array{status:int,body:string}|null Null when the request failed (or curl is missing).
	 */
	public static function http_post( string $url, array $fields ): ?array {
		if ( ! function_exists( 'curl_init' ) ) {
			return null;
		}
		$curl = curl_init( $url );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => http_build_query( $fields, '', '&' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => array( 'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json' ),
			)
		);
		$answer = curl_exec( $curl );
		$status = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $curl ); // From PHP 8 the handle closes itself (and curl_close() is deprecated in 8.5).
		}
		return false === $answer || 0 === $status ? null : array(
			'status' => $status,
			'body'   => (string) $answer,
		);
	}
}
