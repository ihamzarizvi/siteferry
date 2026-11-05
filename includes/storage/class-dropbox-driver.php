<?php
/**
 * Dropbox storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Dropbox through its HTTP API (v2).
 *
 * Signing in uses OAuth 2 with PKCE and no app secret: Dropbox lets an app exchange the code, and
 * later renew the access, with its app key and the PKCE verifier alone. In one-click mode
 * ("relay") the browser passes through SiteFerry's sign-in service, because Dropbox only sends it
 * back to the redirect address registered for SiteFerry's app. The code that comes back that way
 * is useless without this site's verifier, and the tokens go straight between this site and
 * Dropbox: the sign-in service never sees them. In "own" mode the administrator uses a Dropbox
 * app of their own.
 *
 * With "App folder" access (SiteFerry's app has it) the app sees only its own folder, Dropbox >
 * Apps > the app's name; backups go into that folder or a folder inside it.
 *
 * Uploads use upload sessions, in parts of 8 MiB. After an interruption Dropbox names the offset
 * it has, and the part continues from there. Downloads are ranged GET requests. Access tokens
 * (valid for four hours) are renewed when they are about to expire, or when Dropbox refuses one.
 *
 * The bearer token is only ever sent to the configured Dropbox API hosts.
 */
final class Dropbox_Driver implements Driver, Oauth_Driver {

	/** Bytes per upload part. */
	const PART_SIZE = 8388608;

	/** The permissions asked for. */
	const SCOPES = array( 'account_info.read', 'files.metadata.read', 'files.content.read', 'files.content.write' );

	/** Seconds for a request that carries a part. */
	const TIMEOUT = 120;

	/** Seconds for other requests. */
	const SHORT_TIMEOUT = 30;

	/** Dropbox's endpoints (the filter "siteferry_dropbox_endpoints" can point them elsewhere for tests). */
	const ENDPOINTS = array(
		'auth'    => 'https://www.dropbox.com/oauth2/authorize',
		'token'   => 'https://api.dropboxapi.com/oauth2/token',
		'api'     => 'https://api.dropboxapi.com/2',
		'content' => 'https://content.dropboxapi.com/2',
	);

	/** Modes: one-click sign-in through the sign-in service, or the site owner's own Dropbox app. */
	const MODES = array( 'relay', 'own' );

	/** An app key, as the App Console shows it. */
	const APP_KEY = '/^[A-Za-z0-9]{8,64}$/';

	/**
	 * Normalized settings: mode, app_key, folder.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * HTTP.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Endpoint URLs, and "relay".
	 *
	 * @var array<string,string>
	 */
	private $endpoints;

	/**
	 * Waits between retries (null: really sleep).
	 *
	 * @var callable|null
	 */
	private $sleep;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Auth record: refresh_token, access_token, expires, account, app_key (the app the tokens
	 * belong to).
	 *
	 * @var array
	 */
	private $auth = array();

	/**
	 * Saves the auth record when it changes.
	 *
	 * @var callable|null
	 */
	private $saver = null;

	/**
	 * Whether temporary failures are retried (not during test()).
	 *
	 * @var bool
	 */
	private $retry = true;

	/**
	 * Constructor.
	 *
	 * @param array         $settings  Normalized settings.
	 * @param Transport     $transport HTTP.
	 * @param array         $endpoints Endpoint overrides (and "relay").
	 * @param callable|null $sleep     Retry wait (tests).
	 * @param callable|null $clock     Current time (tests).
	 */
	public function __construct( array $settings, Transport $transport, array $endpoints = array(), ?callable $sleep = null, ?callable $clock = null ) {
		$this->settings  = $settings + array(
			'mode'    => 'relay',
			'app_key' => '',
			'folder'  => '',
		);
		$this->transport = $transport;
		$known           = self::ENDPOINTS + array( 'relay' => Gdrive_Driver::RELAY_URL );
		$this->endpoints = array_intersect_key( $endpoints, $known ) + $known;
		foreach ( $this->endpoints as $key => $url ) {
			$this->endpoints[ $key ] = rtrim( $url, '/' );
		}
		$this->sleep = $sleep;
		$this->clock = null === $clock ? 'time' : $clock;
		$this->use_auth( array(), null );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array                $settings  Settings.
	 * @param array<string,string> $secrets   Secrets (none).
	 * @param Transport            $transport HTTP.
	 */
	public static function create( array $settings, array $secrets, Transport $transport ): Driver {
		$endpoints = function_exists( 'apply_filters' ) ? (array) apply_filters( 'siteferry_dropbox_endpoints', self::ENDPOINTS ) : self::ENDPOINTS;
		foreach ( $endpoints as $key => $url ) {
			if ( ! is_string( $url ) || ! preg_match( '#^https?://[^/\s]+(/\S*)?$#', $url ) ) {
				unset( $endpoints[ $key ] );
			}
		}
		$endpoints['relay'] = Gdrive_Driver::relay_url(); // The same sign-in service as Google Drive.
		return new self( $settings, $transport, $endpoints );
	}

	/**
	 * Which app a connection belongs to (another one drops the saved connection).
	 *
	 * @param array $settings Normalized settings.
	 */
	public static function client_key( array $settings ): string {
		// An app key has at least 8 characters, so it never equals "relay".
		return isset( $settings['mode'] ) && 'own' === $settings['mode'] ? (string) $settings['app_key'] : 'relay';
	}

	/**
	 * {@inheritDoc}
	 *
	 * Dropbox needs no secret: the app key is public, and PKCE stands in for the app secret.
	 */
	public static function secrets(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function token_fields(): array {
		return array( 'refresh_token', 'access_token' );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function info(): array {
		return array(
			'oauth'      => true,
			'scopes'     => self::SCOPES,
			'relay_host' => (string) parse_url( Gdrive_Driver::relay_url(), PHP_URL_HOST ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * "relay" mode needs only the folder; "own" mode also the app key.
	 *
	 * @param array $input Raw input: mode, app_key, folder.
	 * @throws Storage_Exception On invalid input.
	 */
	public static function normalize( array $input ): array {
		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'relay';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new Storage_Exception( __( 'Choose how to sign in to Dropbox.', 'siteferry' ), Storage_Exception::CONFIG );
		}
		$folder = self::clean_folder( isset( $input['folder'] ) ? (string) $input['folder'] : '' );
		$key    = '';
		if ( 'own' === $mode ) {
			$key = trim( isset( $input['app_key'] ) ? (string) $input['app_key'] : '' );
			if ( ! preg_match( self::APP_KEY, $key ) ) {
				throw new Storage_Exception( __( 'Enter the app key of your Dropbox app (App Console, your app, Settings).', 'siteferry' ), Storage_Exception::CONFIG );
			}
		}
		return array(
			'settings' => array(
				'mode'    => $mode,
				'app_key' => $key,
				'folder'  => $folder,
			),
			'secrets'  => array(),
		);
	}

	/**
	 * A folder path inside the app folder, without slashes at either end ('' for the app folder).
	 *
	 * @param string $folder Input.
	 * @throws Storage_Exception If it is not a usable Dropbox path.
	 */
	private static function clean_folder( string $folder ): string {
		$folder = trim( trim( $folder ), '/' );
		if ( '' === $folder ) {
			return '';
		}
		if ( strlen( $folder ) > 200 || ! preg_match( '//u', $folder ) || preg_match( '/[\x00-\x1f\x7f\\\\<>:"|?*]/', $folder ) ) {
			throw new Storage_Exception( __( 'The folder may not contain \\ < > : " | ? * or control characters, and has at most 200 characters.', 'siteferry' ), Storage_Exception::CONFIG );
		}
		foreach ( explode( '/', $folder ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || trim( $part ) !== $part || '.' === substr( $part, -1 ) ) {
				throw new Storage_Exception( __( 'Each folder name needs at least one character, and may not be "." or "..", start or end with a space, or end with a dot.', 'siteferry' ), Storage_Exception::CONFIG );
			}
		}
		return $folder;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array         $auth  Auth record.
	 * @param callable|null $saver Saver.
	 */
	public function use_auth( array $auth, ?callable $saver ): void {
		$this->auth  = $auth + array(
			'refresh_token' => '',
			'access_token'  => '',
			'expires'       => 0,
			'account'       => '',
			'app_key'       => '',
		);
		$this->saver = $saver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function connected(): bool {
		return '' !== (string) $this->auth['refresh_token'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe(): string {
		return sprintf(
			'Dropbox%s, %s',
			'' === $this->auth['account'] ? '' : ' of ' . $this->auth['account'],
			'' === $this->settings['folder'] ? 'the app folder' : sprintf( 'folder "%s" in the app folder', $this->settings['folder'] )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $redirect  Redirect URI (this site's page).
	 * @param string $state     State.
	 * @param string $challenge PKCE challenge.
	 */
	public function authorization_url( string $redirect, string $state, string $challenge ): string {
		if ( $this->relayed() ) {
			// The sign-in service checks the return address, signs it with the state and sends the browser on to Dropbox.
			return $this->endpoints['relay'] . '/dropbox/start?' . self::query(
				array(
					'return'    => $redirect,
					'state'     => $state,
					'challenge' => $challenge,
				)
			);
		}
		return $this->endpoints['auth'] . '?' . self::query(
			array(
				'client_id'             => $this->settings['app_key'],
				'redirect_uri'          => $redirect,
				'response_type'         => 'code',
				'token_access_type'     => 'offline',
				'scope'                 => implode( ' ', self::SCOPES ),
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * In one-click mode the code was issued to SiteFerry's app for the sign-in service's redirect
	 * address; the service names both (they are public), and the exchange itself goes straight to
	 * Dropbox.
	 *
	 * @param string $code     Code.
	 * @param string $verifier PKCE verifier.
	 * @param string $redirect Redirect URI (this site's page).
	 * @throws Storage_Exception If Dropbox refuses or a permission was not granted.
	 */
	public function connect( string $code, string $verifier, string $redirect ): array {
		$key = (string) $this->settings['app_key'];
		if ( $this->relayed() ) {
			$app      = $this->relay_app();
			$key      = $app['app_key'];
			$redirect = $app['redirect_uri'];
		}
		$data = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'code_verifier' => $verifier,
				'client_id'     => $key,
				'redirect_uri'  => $redirect,
			)
		);
		if ( empty( $data['refresh_token'] ) ) {
			throw new Storage_Exception( __( 'Dropbox did not send a refresh token, so the access would end within hours. Connect again.', 'siteferry' ), Storage_Exception::REMOTE );
		}
		if ( isset( $data['scope'] ) && is_string( $data['scope'] ) ) {
			$missing = array_diff( self::SCOPES, explode( ' ', $data['scope'] ) );
			if ( array() !== $missing ) {
				throw new Storage_Exception(
					sprintf( /* translators: %s: permission names, separated by commas */ __( 'Dropbox did not grant every permission SiteFerry needs (missing: %s).', 'siteferry' ), implode( ', ', $missing ) )
					. ( $this->relayed() ? '' : ' In the App Console, tick them on your app\'s Permissions tab, click Submit, and connect again.' ),
					Storage_Exception::AUTH
				);
			}
		}
		$this->use_auth(
			array(
				'refresh_token' => (string) $data['refresh_token'],
				'access_token'  => (string) $data['access_token'],
				'expires'       => $this->now() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 14400 ),
				'app_key'       => $key,
			),
			$this->saver
		);
		$this->auth['account'] = $this->account();
		return $this->auth;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Revoking the access token at Dropbox also ends the refresh token behind it.
	 */
	public function disconnect(): void {
		if ( $this->connected() ) {
			try {
				$this->once( 'POST', $this->endpoints['api'] . '/auth/token/revoke', array( 'Content-Type' => 'application/json' ), 'null' );
			} catch ( Storage_Exception $e ) {
				// The tokens are forgotten either way; the user can also remove the app in the Dropbox settings (Connected apps).
				unset( $e );
			}
		}
		$this->use_auth( array(), $this->saver );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws Storage_Exception Naming the check that failed.
	 */
	public function test(): array {
		$this->retry = false;
		try {
			return $this->run_test();
		} finally {
			$this->retry = true;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $size File size.
	 */
	public function part_size( int $size ): int {
		return self::PART_SIZE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name File name.
	 * @param int    $size File size.
	 * @throws Storage_Exception If the upload cannot be started.
	 */
	public function begin( string $name, int $size ): array {
		$data = self::json( $this->request( 'POST', $this->endpoints['content'] . '/files/upload_session/start', self::upload_headers( array( 'close' => false ) ), '' ) );
		if ( empty( $data['session_id'] ) || ! is_string( $data['session_id'] ) ) {
			throw new Storage_Exception( __( 'Dropbox did not start the upload (no session in its answer).', 'siteferry' ), Storage_Exception::REMOTE );
		}
		return array(
			'name'      => $name,
			'size'      => $size,
			'part_size' => $this->part_size( $size ),
			'session'   => $data['session_id'],
			'path'      => $this->path( $name ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $state Upload state.
	 * @param int    $index Part number from 0.
	 * @param string $bytes Data.
	 */
	public function put_part( array $state, int $index, string $bytes ): array {
		$offset = $index * (int) $state['part_size'];
		$end    = $offset + strlen( $bytes );
		$this->retrying(
			function () use ( $state, $offset, $end, $bytes ) {
				// Dropbox only accepts data that starts exactly where its copy ends. After an
				// interruption it may hold all or part of this part already; it says how much.
				$from = $offset;
				for ( $round = 0; $round < 3 && $from < $end; $round++ ) {
					$response = $this->once(
						'POST',
						$this->endpoints['content'] . '/files/upload_session/append_v2',
						self::upload_headers(
							array(
								'cursor' => array(
									'session_id' => $state['session'],
									'offset'     => $from,
								),
								'close'  => false,
							)
						),
						(string) substr( $bytes, $from - $offset ),
						array( 200, 409 ),
						self::TIMEOUT
					);
					if ( 200 === $response['status'] ) {
						return;
					}
					$error = self::error_body( $response );
					if ( 'incorrect_offset' !== self::tag( $error ) || ! isset( $error['correct_offset'] ) ) {
						throw $this->error( $response );
					}
					$has = (int) $error['correct_offset'];
					if ( $has < $offset ) {
						throw new Storage_Exception( sprintf( /* translators: 1: has, 2: a number */ __( 'Dropbox has %1$d bytes of the upload instead of at least %2$d; start the transfer again.', 'siteferry' ), $has, $offset ), Storage_Exception::REMOTE );
					}
					$from = $has; // At or past $end: Dropbox already has the whole part.
				}
				if ( $from < $end ) {
					throw new Storage_Exception( __( 'Dropbox kept asking for a different part of the upload.', 'siteferry' ), Storage_Exception::TRANSIENT );
				}
			}
		);
		return $state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The upload replaces an earlier file with the same name (the same backup sent again).
	 *
	 * @param array $state Upload state.
	 * @throws Storage_Exception If the upload is incomplete or the stored size differs.
	 */
	public function finish( array $state ): void {
		$file = $this->retrying(
			function () use ( $state ): array {
				$response = $this->once(
					'POST',
					$this->endpoints['content'] . '/files/upload_session/finish',
					self::upload_headers(
						array(
							'cursor' => array(
								'session_id' => $state['session'],
								'offset'     => (int) $state['size'],
							),
							'commit' => array(
								'path'       => $state['path'],
								'mode'       => 'overwrite',
								'autorename' => false,
								'mute'       => true,
							),
						)
					),
					'',
					array( 200, 409 ),
					self::TIMEOUT
				);
				if ( 200 === $response['status'] ) {
					return self::json( $response );
				}
				$error = self::error_body( $response );
				if ( 'lookup_failed' === self::tag( $error ) && isset( $error['lookup_failed'] ) && is_array( $error['lookup_failed'] ) ) {
					$lookup = $error['lookup_failed'];
					if ( in_array( self::tag( $lookup ), array( 'not_found', 'closed' ), true ) ) {
						// Finished already (its answer was lost), or expired: the file says which.
						$file = $this->metadata( (string) $state['path'] );
						if ( null === $file ) {
							throw new Storage_Exception( __( 'The upload is not complete on Dropbox (its upload session has ended); start the transfer again.', 'siteferry' ), Storage_Exception::REMOTE );
						}
						return $file;
					}
					if ( 'incorrect_offset' === self::tag( $lookup ) && isset( $lookup['correct_offset'] ) ) {
						throw new Storage_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'Dropbox has %1$d bytes of the upload instead of %2$d; start the transfer again.', 'siteferry' ), (int) $lookup['correct_offset'], (int) $state['size'] ), Storage_Exception::REMOTE );
					}
				}
				throw $this->error( $response );
			}
		);
		$size = isset( $file['size'] ) ? (int) $file['size'] : -1;
		if ( $size !== (int) $state['size'] ) {
			throw new Storage_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'The file on Dropbox has %1$d bytes instead of %2$d.', 'siteferry' ), $size, $state['size'] ), Storage_Exception::REMOTE );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * Nothing to remove: an upload session that is never finished never becomes a file, and
	 * Dropbox discards it after a week.
	 *
	 * @param array $state Upload state.
	 */
	public function abort( array $state ): void {
		unset( $state );
	}

	/**
	 * {@inheritDoc}
	 */
	public function list(): array {
		$folder = '' === $this->settings['folder'] ? '' : '/' . $this->settings['folder'];
		$data   = $this->rpc(
			'files/list_folder',
			array(
				'path'            => $folder,
				'recursive'       => false,
				'include_deleted' => false,
				'limit'           => 2000,
			),
			true
		);
		if ( null === $data ) {
			return array(); // No folder yet: nothing has been sent.
		}
		$items = array();
		$pages = 0;
		for ( ;; ) {
			foreach ( isset( $data['entries'] ) && is_array( $data['entries'] ) ? $data['entries'] : array() as $entry ) {
				$name = isset( $entry['name'] ) ? (string) $entry['name'] : '';
				if ( 'file' === self::tag( $entry ) && Parts::stored_name( $name ) ) {
					$items[] = array(
						'name'     => $name,
						'size'     => isset( $entry['size'] ) ? (int) $entry['size'] : 0,
						'modified' => isset( $entry['server_modified'] ) ? (int) strtotime( (string) $entry['server_modified'] ) : 0,
					);
				}
			}
			if ( empty( $data['has_more'] ) || empty( $data['cursor'] ) || ++$pages >= 100 ) {
				break;
			}
			$data = (array) $this->rpc( 'files/list_folder/continue', array( 'cursor' => (string) $data['cursor'] ) );
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['modified'] - $a['modified'];
			}
		);
		return $items;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name   File name.
	 * @param int    $offset First byte.
	 * @param int    $length Number of bytes.
	 * @throws Storage_Exception If the file is missing or Dropbox sends another part.
	 */
	public function download_range( string $name, int $offset, int $length ): string {
		// A download takes its argument in a header and no body (and so no Content-Type); GET
		// keeps HTTP libraries from adding one.
		$response = $this->request(
			'GET',
			$this->endpoints['content'] . '/files/download',
			array(
				'Dropbox-API-Arg' => self::arg( array( 'path' => $this->path( $name ) ) ),
				'Range'           => sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 ),
			),
			'',
			array( 206, 200, 409 ),
			self::TIMEOUT
		);
		if ( 409 === $response['status'] ) {
			$error = self::error_body( $response );
			if ( 'path' === self::tag( $error ) && isset( $error['path'] ) && 'not_found' === self::tag( (array) $error['path'] ) ) {
				throw new Storage_Exception( sprintf( /* translators: %s: a file or backup name */ __( '%s is not in the Dropbox folder.', 'siteferry' ), $name ), Storage_Exception::NOT_FOUND );
			}
			throw $this->error( $response );
		}
		if ( 200 === $response['status'] ) {
			return (string) substr( $response['body'], $offset, $length );
		}
		$range = isset( $response['headers']['content-range'] ) ? $response['headers']['content-range'] : '';
		if ( ! preg_match( '/^bytes ' . $offset . '-/', $range ) ) {
			throw new Storage_Exception( __( 'Dropbox sent a different part of the file than requested.', 'siteferry' ), Storage_Exception::REMOTE );
		}
		return $response['body'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * Dropbox keeps deleted files restorable for a while (how long depends on the plan).
	 *
	 * @param string $name File name.
	 */
	public function delete( string $name ): void {
		$this->rpc( 'files/delete_v2', array( 'path' => $this->path( $name ) ), true );
	}

	/**
	 * The checks of test(): fresh token and account, a small file uploaded in two parts, read
	 * back, listing, delete.
	 *
	 * @throws Storage_Exception Naming the check that failed.
	 */
	private function run_test(): array {
		$lines   = array();
		$account = $this->check(
			'Signing in to Dropbox',
			function (): string {
				$this->token( true );
				return $this->account();
			}
		);
		$lines[] = sprintf( /* translators: %s: account */ __( 'Signed in to Dropbox as %s.', 'siteferry' ), $account );
		$lines[] = '' === $this->settings['folder']
			? __( 'Backups go into the app folder (Dropbox > Apps > the app\'s name).', 'siteferry' )
			/* translators: %s: folder name */
			: sprintf( __( 'Backups go into the folder "%s" in the app folder (Dropbox > Apps > the app\'s name); Dropbox creates it with the first file.', 'siteferry' ), $this->settings['folder'] );

		$part = 65536;
		$name = '.siteferry-test-' . bin2hex( random_bytes( 6 ) ) . '.bin';
		$data = random_bytes( $part ) . 'SiteFerry connection test, ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC.';
		$this->check(
			'Writing a test file with an upload session',
			function () use ( $name, $data, $part ) {
				$state              = $this->begin( $name, strlen( $data ) );
				$state['part_size'] = $part;
				$state              = $this->put_part( $state, 0, substr( $data, 0, $part ) );
				$state              = $this->put_part( $state, 1, substr( $data, $part ) );
				$this->finish( $state );
			}
		);
		$lines[] = __( 'Wrote a test file in two parts.', 'siteferry' );

		$this->check(
			'Reading the test file back',
			function () use ( $name, $data ) {
				if ( $this->download_range( $name, 0, strlen( $data ) ) !== $data ) {
					throw new Storage_Exception( __( 'The file read back differs from what was written.', 'siteferry' ), Storage_Exception::REMOTE );
				}
			}
		);
		$lines[] = __( 'Read it back unchanged.', 'siteferry' );

		$count   = $this->check(
			'Listing the folder',
			function (): int {
				return count( $this->list() );
			}
		);
		$lines[] = sprintf( /* translators: %d: a number */ __( 'Listed the folder: %d SiteFerry backup(s).', 'siteferry' ), $count );

		$this->check(
			'Deleting the test file',
			function () use ( $name ) {
				$this->delete( $name );
			}
		);
		$lines[] = __( 'Deleted the test file.', 'siteferry' );
		return $lines;
	}

	/**
	 * Metadata of a file, or null if there is none.
	 *
	 * @param string $path Dropbox path.
	 */
	private function metadata( string $path ): ?array {
		$data = $this->rpc( 'files/get_metadata', array( 'path' => $path ), true );
		return null === $data || 'file' !== self::tag( $data ) ? null : $data;
	}

	/**
	 * Email address of the connected account (saved when it changes).
	 */
	private function account(): string {
		$data    = (array) $this->rpc( 'users/get_current_account', null );
		$account = isset( $data['email'] ) ? (string) $data['email'] : '';
		if ( '' === $account && isset( $data['name']['display_name'] ) ) {
			$account = (string) $data['name']['display_name'];
		}
		if ( $account !== $this->auth['account'] ) {
			$this->auth['account'] = $account;
			$this->save_auth();
		}
		return $account;
	}

	/**
	 * The app key and redirect address of SiteFerry's Dropbox app, from the sign-in service.
	 *
	 * @return array{app_key:string,redirect_uri:string}
	 * @throws Storage_Exception If the service cannot be reached or answers something else.
	 */
	private function relay_app(): array {
		$url     = $this->endpoints['relay'] . '/dropbox/app';
		$service = 'The sign-in service ' . parse_url( $url, PHP_URL_HOST );
		return $this->retrying(
			function () use ( $url, $service ): array {
				try {
					$response = $this->transport->request( 'GET', $url, array( 'Accept' => 'application/json' ), '', self::SHORT_TIMEOUT );
				} catch ( Storage_Exception $e ) {
					throw new Storage_Exception( $service . ' cannot be reached: ' . $e->getMessage(), $e->getCode(), $e );
				}
				$data = self::json( $response );
				if ( 200 !== $response['status'] ) {
					$text = isset( $data['error_description'] ) && is_string( $data['error_description'] ) ? ': ' . preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $data['error_description'] ) : '';
					$kind = $response['status'] >= 500 || 429 === $response['status'] ? Storage_Exception::TRANSIENT : Storage_Exception::REMOTE;
					throw new Storage_Exception( sprintf( /* translators: 1: a service, such as Dropbox, 2: an HTTP status code, 3: empty, or ": " and the error message */ __( '%1$s answered HTTP %2$d%3$s', 'siteferry' ), $service, $response['status'], $text ), $kind );
				}
				$key      = isset( $data['app_key'] ) && is_string( $data['app_key'] ) ? $data['app_key'] : '';
				$redirect = isset( $data['redirect_uri'] ) && is_string( $data['redirect_uri'] ) ? $data['redirect_uri'] : '';
				if ( ! preg_match( self::APP_KEY, $key ) || ! $this->is_relay_callback( $redirect ) ) {
					throw new Storage_Exception( $service . ' sent an unexpected answer about SiteFerry\'s Dropbox app.', Storage_Exception::REMOTE );
				}
				return array(
					'app_key'      => $key,
					'redirect_uri' => $redirect,
				);
			}
		);
	}

	/**
	 * Whether an address is the sign-in service's Dropbox callback: same scheme, host and port as
	 * the service, a path ending in /dropbox/callback, no query.
	 *
	 * @param string $url Address.
	 */
	private function is_relay_callback( string $url ): bool {
		$relay = parse_url( $this->endpoints['relay'] );
		$parts = parse_url( $url );
		if ( ! is_array( $relay ) || ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		foreach ( array( 'scheme', 'host', 'port' ) as $key ) {
			if ( strtolower( (string) ( isset( $relay[ $key ] ) ? $relay[ $key ] : '' ) ) !== strtolower( (string) ( isset( $parts[ $key ] ) ? $parts[ $key ] : '' ) ) ) {
				return false;
			}
		}
		return isset( $parts['path'] ) && '/dropbox/callback' === substr( $parts['path'], -17 );
	}

	/**
	 * A valid access token, renewed when it expires within five minutes (or when forced).
	 *
	 * @param bool $force Renew even if the token seems valid.
	 * @throws Storage_Exception If the storage is not connected or Dropbox refuses.
	 */
	private function token( bool $force = false ): string {
		if ( ! $force && '' !== $this->auth['access_token'] && (int) $this->auth['expires'] - 300 > $this->now() ) {
			return (string) $this->auth['access_token'];
		}
		if ( ! $this->connected() ) {
			throw new Storage_Exception( __( 'Dropbox is not connected yet. Click "Connect to Dropbox" on the Storage tab.', 'siteferry' ), Storage_Exception::AUTH );
		}
		$data                       = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->auth['refresh_token'],
				'client_id'     => '' !== $this->auth['app_key'] ? $this->auth['app_key'] : $this->settings['app_key'],
			)
		);
		$this->auth['access_token'] = (string) $data['access_token'];
		$this->auth['expires']      = $this->now() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 14400 );
		if ( ! empty( $data['refresh_token'] ) ) {
			$this->auth['refresh_token'] = (string) $data['refresh_token'];
		}
		$this->save_auth();
		return (string) $this->auth['access_token'];
	}

	/**
	 * A request to Dropbox's token endpoint (form-encoded), with retries for temporary failures.
	 *
	 * @param array $fields Form fields.
	 * @return array The answer.
	 * @throws Storage_Exception If Dropbox refuses.
	 */
	private function token_request( array $fields ): array {
		return $this->retrying(
			function () use ( $fields ): array {
				try {
					$response = $this->transport->request( 'POST', $this->endpoints['token'], array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $fields, '', '&' ), self::SHORT_TIMEOUT );
				} catch ( Storage_Exception $e ) {
					throw new Storage_Exception( sprintf( /* translators: %s: the reason */ __( 'Dropbox\'s sign-in service cannot be reached: %s', 'siteferry' ), $e->getMessage() ), $e->getCode(), $e );
				}
				$data = self::json( $response );
				if ( 200 === $response['status'] && ! empty( $data['access_token'] ) ) {
					return $data;
				}
				$code = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
				$text = isset( $data['error_description'] ) && is_string( $data['error_description'] ) ? ': ' . $data['error_description'] : '';
				$text = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $text );
				if ( $response['status'] >= 500 || 429 === $response['status'] ) {
					throw new Storage_Exception( sprintf( /* translators: %d: an HTTP status code */ __( 'Dropbox\'s sign-in service answered HTTP %d; try again later.', 'siteferry' ), $response['status'] ), Storage_Exception::TRANSIENT );
				}
				if ( 'invalid_grant' === $code ) {
					throw new Storage_Exception( sprintf( /* translators: %s: empty, or ": " and the error message */ __( 'Dropbox no longer accepts the saved authorization (invalid_grant%s). Connect Dropbox again on the Storage tab.', 'siteferry' ), $text ), Storage_Exception::AUTH );
				}
				if ( in_array( $code, array( 'invalid_client', 'unauthorized_client' ), true ) ) {
					throw new Storage_Exception(
						$this->relayed()
							? 'Dropbox refused SiteFerry\'s app (' . $code . $text . '). This needs fixing at ' . parse_url( $this->endpoints['relay'], PHP_URL_HOST ) . ', not on this site.'
							: 'Dropbox refused the app key (' . $code . $text . '). Check it on the Storage tab.',
						Storage_Exception::AUTH
					);
				}
				throw new Storage_Exception( sprintf( /* translators: 1: an HTTP status code, 2: empty, or an error code, 3: empty, or ": " and the error message */ __( 'Dropbox\'s sign-in service refused (HTTP %1$d%2$s%3$s).', 'siteferry' ), $response['status'], '' === $code ? '' : ' ' . $code, $text ), Storage_Exception::REMOTE );
			}
		);
	}

	/**
	 * Whether signing in goes through the sign-in service.
	 */
	private function relayed(): bool {
		return 'own' !== $this->settings['mode'];
	}

	/**
	 * A call to an RPC endpoint (JSON in, JSON out).
	 *
	 * @param string     $route     Route below /2/.
	 * @param array|null $args      Arguments (null for routes without any).
	 * @param bool       $not_found Return null (instead of throwing) when the path does not exist.
	 * @return array|null The answer.
	 * @throws Storage_Exception On errors.
	 */
	private function rpc( string $route, ?array $args, bool $not_found = false ): ?array {
		$response = $this->request( 'POST', $this->endpoints['api'] . '/' . $route, array( 'Content-Type' => 'application/json' ), null === $args ? 'null' : self::encode( $args ), array( 200, 409 ) );
		if ( 409 === $response['status'] ) {
			if ( $not_found && self::is_not_found( self::error_body( $response ) ) ) {
				return null;
			}
			throw $this->error( $response );
		}
		return self::json( $response );
	}

	/**
	 * Whether an endpoint's error says the path does not exist (path/not_found, path_lookup/not_found).
	 *
	 * @param array $error The "error" member.
	 */
	private static function is_not_found( array $error ): bool {
		$tag = self::tag( $error );
		return in_array( $tag, array( 'path', 'path_lookup' ), true ) && isset( $error[ $tag ] ) && is_array( $error[ $tag ] ) && 'not_found' === self::tag( $error[ $tag ] );
	}

	/**
	 * A Dropbox request with retries for temporary failures.
	 *
	 * @param string               $method  Method.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 * @param int[]                $ok      Accepted status codes.
	 * @param int                  $timeout Seconds.
	 * @return array{status:int,headers:array,body:string}
	 */
	private function request( string $method, string $url, array $headers = array(), string $body = '', array $ok = array( 200 ), int $timeout = self::SHORT_TIMEOUT ): array {
		return $this->retrying(
			function () use ( $method, $url, $headers, $body, $ok, $timeout ): array {
				return $this->once( $method, $url, $headers, $body, $ok, $timeout );
			}
		);
	}

	/**
	 * One Dropbox request with the bearer token; a refused token is renewed and the request sent
	 * once more.
	 *
	 * @param string               $method  Method.
	 * @param string               $url     URL.
	 * @param array<string,string> $headers Headers.
	 * @param string               $body    Body.
	 * @param int[]                $ok      Accepted status codes.
	 * @param int                  $timeout Seconds.
	 * @return array{status:int,headers:array,body:string}
	 * @throws Storage_Exception On errors.
	 */
	private function once( string $method, string $url, array $headers = array(), string $body = '', array $ok = array( 200 ), int $timeout = self::SHORT_TIMEOUT ): array {
		$this->check_host( $url );
		if ( 'POST' === $method && '' === $body ) {
			$headers['Content-Length'] = '0';
		}
		$force = false;
		for ( $round = 0; $round < 2; $round++ ) {
			$response = $this->transport->request( $method, $url, array( 'Authorization' => 'Bearer ' . $this->token( $force ) ) + $headers, $body, $timeout );
			if ( 401 === $response['status'] && ! $force ) {
				$force = true;
				continue;
			}
			break;
		}
		if ( ! in_array( $response['status'], $ok, true ) ) {
			throw $this->error( $response );
		}
		return $response;
	}

	/**
	 * Refuses to send the token to any host but the configured Dropbox API hosts.
	 *
	 * @param string $url URL.
	 * @throws Storage_Exception For other hosts.
	 */
	private function check_host( string $url ): void {
		$allowed = array( self::origin( $this->endpoints['api'] ), self::origin( $this->endpoints['content'] ) );
		$origin  = self::origin( $url );
		if ( ! in_array( $origin, $allowed, true ) ) {
			throw new Storage_Exception( sprintf( /* translators: %s: origin */ __( 'Refusing to send the Dropbox token to %s.', 'siteferry' ), $origin ), Storage_Exception::REMOTE );
		}
	}

	/**
	 * Scheme, host and port of a URL, in lower case.
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): string {
		$port = parse_url( $url, PHP_URL_PORT );
		return strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST ) . ( null === $port ? '' : ':' . $port ) );
	}

	/**
	 * Turns an error answer into an exception with a readable message.
	 *
	 * @param array{status:int,headers:array,body:string} $response Response.
	 */
	private function error( array $response ): Storage_Exception {
		$status  = (int) $response['status'];
		$data    = self::json( $response );
		$summary = isset( $data['error_summary'] ) && is_string( $data['error_summary'] ) ? rtrim( $data['error_summary'], './' ) : '';
		if ( '' === $summary && ! isset( $data['error'] ) ) {
			// Some refusals (a malformed request) come as plain text.
			$summary = trim( substr( (string) $response['body'], 0, 300 ) );
		}
		$summary = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $summary );

		$hints = array(
			'insufficient_space'        => 'The Dropbox is full.',
			'missing_scope'             => 'SiteFerry lacks a permission it needs; connect Dropbox again.',
			'expired_access_token'      => 'Connect Dropbox again on the Storage tab.',
			'invalid_access_token'      => 'Connect Dropbox again on the Storage tab.',
			'disallowed_name'           => 'Dropbox does not allow this file or folder name.',
			'no_write_permission'       => 'SiteFerry may not write to this folder.',
			'too_many_write_operations' => 'Dropbox is busy with other changes in this account.',
		);
		$hint = '';
		foreach ( $hints as $key => $text ) {
			if ( false !== strpos( $summary, $key ) ) {
				$hint = $text;
				break;
			}
		}
		$transient = $status >= 500 || 429 === $status || false !== strpos( $summary, 'too_many_write_operations' ) || false !== strpos( $summary, 'too_many_requests' );
		if ( $transient ) {
			$kind = Storage_Exception::TRANSIENT;
		} elseif ( false !== strpos( $summary, 'insufficient_space' ) ) {
			$kind = Storage_Exception::REMOTE;
		} elseif ( 401 === $status || 403 === $status ) {
			$kind = Storage_Exception::AUTH;
		} elseif ( false !== strpos( $summary, 'not_found' ) ) {
			$kind = Storage_Exception::NOT_FOUND;
		} else {
			$kind = Storage_Exception::REMOTE;
		}
		$text = sprintf( 'Dropbox answered HTTP %d', $status ) . ( '' === $summary ? '.' : ' (' . $summary . ').' );
		$text = strlen( $text ) > 400 ? substr( $text, 0, 400 ) . '...' : $text;
		return new Storage_Exception( trim( $text . ' ' . $hint ), $kind );
	}

	/**
	 * Runs an operation with retries for temporary failures (none during test()).
	 *
	 * @param callable $operation Operation.
	 * @return mixed What it returns.
	 */
	private function retrying( callable $operation ) {
		return $this->retry ? Retry::run( $operation, $this->sleep ) : $operation();
	}

	/**
	 * Runs one check of test() and names it in any error.
	 *
	 * @param string   $label    What is being checked.
	 * @param callable $callback The check.
	 * @return mixed What the check returns.
	 * @throws Storage_Exception Prefixed with the label.
	 */
	private function check( string $label, callable $callback ) {
		try {
			return $callback();
		} catch ( Storage_Exception $e ) {
			throw new Storage_Exception( $label . ' failed. ' . $e->getMessage(), $e->getCode(), $e );
		}
	}

	/**
	 * Hands the changed auth record to the saver.
	 */
	private function save_auth(): void {
		if ( null !== $this->saver ) {
			call_user_func( $this->saver, $this->auth );
		}
	}

	/**
	 * Current Unix time.
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/**
	 * Dropbox path of a backup.
	 *
	 * @param string $name File name.
	 */
	private function path( string $name ): string {
		return ( '' === $this->settings['folder'] ? '' : '/' . $this->settings['folder'] ) . '/' . $name;
	}

	/**
	 * Headers of a content-upload request.
	 *
	 * @param array $arg The call's argument.
	 * @return array<string,string>
	 */
	private static function upload_headers( array $arg ): array {
		return array(
			'Content-Type'    => 'application/octet-stream',
			'Dropbox-API-Arg' => self::arg( $arg ),
		);
	}

	/**
	 * The Dropbox-API-Arg header: JSON with every character outside ASCII escaped, as Dropbox requires.
	 *
	 * @param array $arg Argument.
	 */
	private static function arg( array $arg ): string {
		return (string) json_encode( $arg, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The ".tag" of a Dropbox union value.
	 *
	 * @param array $value Value.
	 */
	private static function tag( array $value ): string {
		return isset( $value['.tag'] ) && is_string( $value['.tag'] ) ? $value['.tag'] : '';
	}

	/**
	 * The "error" member of an error answer.
	 *
	 * @param array $response Response.
	 */
	private static function error_body( array $response ): array {
		$data = self::json( $response );
		return isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
	}

	/**
	 * A query string with RFC 3986 encoding.
	 *
	 * @param array $query Parameters.
	 */
	private static function query( array $query ): string {
		return http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * JSON for a request body.
	 *
	 * @param array $value Value.
	 */
	private static function encode( array $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The JSON body of a response, or an empty array.
	 *
	 * @param array $response Response.
	 */
	private static function json( array $response ): array {
		$data = json_decode( (string) $response['body'], true );
		return is_array( $data ) ? $data : array();
	}
}
