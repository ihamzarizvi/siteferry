<?php
/**
 * Google Drive storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Google Drive through its REST API (v3), with the administrator's own OAuth client.
 *
 * Only the drive.file scope is requested: SiteFerry sees the files and folders it created
 * itself, nothing else in the Drive. Backups go into one folder (created at the top of My Drive).
 *
 * Uploads use Drive's resumable uploads in parts of 8 MiB (a multiple of 256 KiB, as Drive
 * requires). After a failed part, Drive is asked how many bytes it has, and the part continues
 * from there. Downloads use range requests. Access tokens are refreshed when they are about to
 * expire, or when Drive refuses one, and the new token is saved for the next request.
 *
 * The bearer token is only ever sent to the configured Google hosts.
 */
final class Gdrive_Driver implements Driver, Oauth_Driver {

	/** Bytes per upload part (32 x 256 KiB). */
	const PART_SIZE = 8388608;

	/** Drive's unit for resumable chunks. */
	const CHUNK_UNIT = 262144;

	/** The only scope requested. */
	const SCOPE = 'https://www.googleapis.com/auth/drive.file';

	const FOLDER_TYPE = 'application/vnd.google-apps.folder';

	/** Seconds for a request that carries a part. */
	const TIMEOUT = 120;

	/** Seconds for other requests. */
	const SHORT_TIMEOUT = 30;

	/** Google's endpoints (the filter "siteferry_gdrive_endpoints" can point them elsewhere for tests). */
	const ENDPOINTS = array(
		'auth'   => 'https://accounts.google.com/o/oauth2/v2/auth',
		'token'  => 'https://oauth2.googleapis.com/token',
		'revoke' => 'https://oauth2.googleapis.com/revoke',
		'api'    => 'https://www.googleapis.com/drive/v3',
		'upload' => 'https://www.googleapis.com/upload/drive/v3',
	);

	/**
	 * The sign-in relay for one-click sign-in ("relay" mode): it holds SiteFerry's Google app
	 * secret and answers sign-ins and token renewals. SITEFERRY_RELAY_URL or the filter
	 * "siteferry_relay_url" can point elsewhere (https, or http on localhost for tests).
	 */
	const RELAY_URL = 'https://auth.xsofty.com/siteferry';

	/** Modes: one-click sign-in through the relay, or the site owner's own OAuth client. */
	const MODES = array( 'relay', 'own' );

	/**
	 * Normalized settings: client_id, folder.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * OAuth client secret.
	 *
	 * @var string
	 */
	private $client_secret;

	/**
	 * HTTP.
	 *
	 * @var Transport
	 */
	private $transport;

	/**
	 * Endpoint URLs.
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
	 * Auth record: refresh_token, access_token, expires, account, folder_id, folder_name.
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
	 * Whether the folder was checked during this request.
	 *
	 * @var bool
	 */
	private $folder_ok = false;

	/**
	 * Backup name => file id, from the last listing.
	 *
	 * @var array<string,string>
	 */
	private $ids = array();

	/**
	 * Constructor.
	 *
	 * @param array         $settings      Normalized settings.
	 * @param string        $client_secret OAuth client secret.
	 * @param Transport     $transport     HTTP.
	 * @param array         $endpoints     Endpoint overrides.
	 * @param callable|null $sleep         Retry wait (tests).
	 * @param callable|null $clock         Current time (tests).
	 */
	public function __construct(
		array $settings,
		#[\SensitiveParameter]
		string $client_secret,
		Transport $transport,
		array $endpoints = array(),
		?callable $sleep = null,
		?callable $clock = null
	) {
		$this->settings      = $settings;
		$this->client_secret = $client_secret;
		$this->transport     = $transport;
		$known               = self::ENDPOINTS + array( 'relay' => self::RELAY_URL );
		$this->endpoints     = array_intersect_key( $endpoints, $known ) + $known;
		$this->endpoints['relay'] = rtrim( $this->endpoints['relay'], '/' );
		$this->sleep         = $sleep;
		$this->clock         = null === $clock ? 'time' : $clock;
		$this->use_auth( array(), null );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array                $settings  Settings.
	 * @param array<string,string> $secrets   Secrets.
	 * @param Transport            $transport HTTP.
	 */
	public static function create( array $settings, array $secrets, Transport $transport ): Driver {
		$endpoints = function_exists( 'apply_filters' ) ? (array) apply_filters( 'siteferry_gdrive_endpoints', self::ENDPOINTS ) : self::ENDPOINTS;
		foreach ( $endpoints as $key => $url ) {
			if ( ! is_string( $url ) || ! preg_match( '#^https?://[^/\s]+(/\S*)?$#', $url ) ) {
				unset( $endpoints[ $key ] );
			}
		}
		$endpoints['relay'] = self::relay_url();
		return new self( $settings, isset( $secrets['client_secret'] ) ? $secrets['client_secret'] : '', $transport, $endpoints );
	}

	/**
	 * The relay's address: SITEFERRY_RELAY_URL, the "siteferry_relay_url" filter, or RELAY_URL.
	 * Only https is accepted, except http on localhost (test servers).
	 */
	public static function relay_url(): string {
		$url = defined( 'SITEFERRY_RELAY_URL' ) ? (string) SITEFERRY_RELAY_URL : self::RELAY_URL;
		if ( function_exists( 'apply_filters' ) ) {
			$url = (string) apply_filters( 'siteferry_relay_url', $url );
		}
		$url   = rtrim( $url, '/' );
		$parts = parse_url( $url );
		$ok    = is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && ! isset( $parts['user'], $parts['query'], $parts['fragment'] )
			&& ( 'https' === strtolower( $parts['scheme'] ) || ( 'http' === strtolower( $parts['scheme'] ) && in_array( strtolower( $parts['host'] ), array( '127.0.0.1', 'localhost' ), true ) ) );
		return $ok ? $url : self::RELAY_URL;
	}

	/**
	 * Which client a connection belongs to (a different one drops the saved connection).
	 *
	 * @param array $settings Normalized settings.
	 */
	public static function client_key( array $settings ): string {
		// A client ID always ends in .apps.googleusercontent.com, so it never equals "relay".
		return 'own' === self::mode_of( $settings ) ? (string) $settings['client_id'] : 'relay';
	}

	/**
	 * The mode of saved settings (settings saved before modes existed were "own").
	 *
	 * @param array $settings Settings.
	 */
	private static function mode_of( array $settings ): string {
		if ( isset( $settings['mode'] ) && in_array( $settings['mode'], self::MODES, true ) ) {
			return $settings['mode'];
		}
		return empty( $settings['client_id'] ) ? 'relay' : 'own';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function secrets(): array {
		return array( 'client_secret' => 'client secret' );
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
			'scope'      => self::SCOPE,
			'relay_host' => (string) parse_url( self::relay_url(), PHP_URL_HOST ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * "relay" mode needs only the folder; "own" mode also the client ID and secret.
	 *
	 * @param array $input Raw input: mode, client_id, client_secret, folder.
	 * @throws Storage_Exception On invalid input.
	 */
	public static function normalize( array $input ): array {
		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : 'relay';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new Storage_Exception( 'Choose how to sign in to Google Drive.', Storage_Exception::CONFIG );
		}
		$folder = trim( isset( $input['folder'] ) ? (string) $input['folder'] : '' );
		$folder = '' === $folder ? 'SiteFerry' : $folder;
		if ( ! preg_match( '/^[^\x00-\x1f\x7f\/\\\\]{1,100}$/u', $folder ) ) {
			throw new Storage_Exception( 'The folder name may not contain slashes or control characters (one folder at the top of My Drive).', Storage_Exception::CONFIG );
		}
		if ( 'relay' === $mode ) {
			return array(
				'settings' => array(
					'mode'      => 'relay',
					'client_id' => '',
					'folder'    => $folder,
				),
				'secrets'  => array(), // No secret on the site: the relay holds the app's.
			);
		}

		$client_id = trim( isset( $input['client_id'] ) ? (string) $input['client_id'] : '' );
		if ( ! preg_match( '/^[A-Za-z0-9._-]{1,200}\.apps\.googleusercontent\.com$/', $client_id ) ) {
			throw new Storage_Exception( 'Enter the OAuth client ID (it ends with .apps.googleusercontent.com).', Storage_Exception::CONFIG );
		}
		$secret = trim( isset( $input['client_secret'] ) ? (string) $input['client_secret'] : '' );
		if ( '' !== $secret && ! preg_match( '/^[\x21-\x7e]{1,200}$/', $secret ) ) {
			throw new Storage_Exception( 'The client secret contains characters that are not allowed.', Storage_Exception::CONFIG );
		}
		return array(
			'settings' => array(
				'mode'      => 'own',
				'client_id' => $client_id,
				'folder'    => $folder,
			),
			'secrets'  => array( 'client_secret' => '' === $secret ? null : $secret ),
		);
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
			'folder_id'     => '',
			'folder_name'   => '',
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
			'Google Drive%s, folder "%s"',
			'' === $this->auth['account'] ? '' : ' of ' . $this->auth['account'],
			$this->settings['folder']
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $redirect  Redirect URI.
	 * @param string $state     State.
	 * @param string $challenge PKCE challenge.
	 */
	public function authorization_url( string $redirect, string $state, string $challenge ): string {
		if ( $this->relayed() ) {
			// The relay checks the return address, signs it with the state and sends the browser on to Google.
			return $this->endpoints['relay'] . '/google/start?' . self::query(
				array(
					'return'    => $redirect,
					'state'     => $state,
					'challenge' => $challenge,
				)
			);
		}
		return $this->endpoints['auth'] . '?' . http_build_query(
			array(
				'client_id'             => $this->settings['client_id'],
				'redirect_uri'          => $redirect,
				'response_type'         => 'code',
				'scope'                 => self::SCOPE,
				'access_type'           => 'offline',
				'prompt'                => 'consent',
				'state'                 => $state,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code     Code.
	 * @param string $verifier PKCE verifier.
	 * @param string $redirect Redirect URI.
	 * @throws Storage_Exception If Google refuses or the scope was not granted.
	 */
	public function connect( string $code, string $verifier, string $redirect ): array {
		$data = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $this->settings['client_id'],
				'client_secret' => $this->client_secret,
				'redirect_uri'  => $redirect,
				'code_verifier' => $verifier,
			)
		);
		if ( empty( $data['refresh_token'] ) ) {
			throw new Storage_Exception( 'Google did not send a refresh token. Remove SiteFerry\'s access in your Google account (Security, third-party access) and connect again.', Storage_Exception::REMOTE );
		}
		$scopes = explode( ' ', isset( $data['scope'] ) ? (string) $data['scope'] : '' );
		if ( ! in_array( self::SCOPE, $scopes, true ) ) {
			throw new Storage_Exception( 'Access to Google Drive was not granted. Connect again and allow SiteFerry to see and change the files it creates.', Storage_Exception::AUTH );
		}
		$this->use_auth(
			array(
				'refresh_token' => (string) $data['refresh_token'],
				'access_token'  => (string) $data['access_token'],
				'expires'       => $this->now() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 ),
			),
			$this->saver
		);
		$this->auth['account'] = $this->account();
		return $this->auth;
	}

	/**
	 * {@inheritDoc}
	 */
	public function disconnect(): void {
		$token = (string) $this->auth['refresh_token'];
		if ( '' !== $token ) {
			try {
				$this->transport->request( 'POST', $this->endpoints['revoke'], array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( array( 'token' => $token ) ), self::SHORT_TIMEOUT );
			} catch ( Storage_Exception $e ) {
				// The tokens are forgotten either way; the user can also remove access in the Google account.
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
		$folder   = $this->folder( true );
		$type     = '.gz' === substr( $name, -3 ) ? 'application/gzip' : ( '.tar' === substr( $name, -4 ) ? 'application/x-tar' : 'application/octet-stream' );
		$response = $this->request(
			'POST',
			$this->endpoints['upload'] . '/files?' . self::query(
				array(
					'uploadType' => 'resumable',
					'fields'     => 'id,name,size',
				)
			),
			array(
				'Content-Type'            => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type'   => $type,
				'X-Upload-Content-Length' => (string) $size,
			),
			self::encode(
				array(
					'name'     => $name,
					'parents'  => array( $folder ),
					'mimeType' => $type,
				)
			)
		);
		$session = isset( $response['headers']['location'] ) ? trim( $response['headers']['location'] ) : '';
		if ( '' === $session ) {
			throw new Storage_Exception( 'Google Drive did not start the upload (no session address in its answer).', Storage_Exception::REMOTE );
		}
		$this->check_host( $session );
		return array(
			'name'      => $name,
			'size'      => $size,
			'part_size' => $this->part_size( $size ),
			'session'   => $session,
			'file_id'   => '',
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
		$this->retrying(
			function () use ( &$state, $offset, $bytes ) {
				// Continue from what Drive has: after an interruption it may hold all or part of this
				// part, and Drive only accepts data that starts exactly where its copy ends.
				$status = $this->upload_status( $state );
				if ( null !== $status['file'] ) {
					$state['file_id'] = (string) $status['file']['id'];
					return;
				}
				if ( $status['received'] >= $offset + strlen( $bytes ) ) {
					return;
				}
				if ( $status['received'] < $offset ) {
					throw new Storage_Exception( sprintf( 'Google Drive has %d bytes of the upload instead of at least %d; start the transfer again.', $status['received'], $offset ), Storage_Exception::REMOTE );
				}
				$start    = $status['received'];
				$chunk    = (string) substr( $bytes, $start - $offset );
				$end      = $start + strlen( $chunk ) - 1;
				$response = $this->once(
					'PUT',
					$state['session'],
					array(
						'Content-Range' => sprintf( 'bytes %d-%d/%d', $start, $end, $state['size'] ),
						'Content-Type'  => 'application/octet-stream',
					),
					$chunk,
					array( 200, 201, 308 ),
					self::TIMEOUT
				);
				if ( 308 === $response['status'] ) {
					if ( self::received( $response ) < $end + 1 ) {
						throw new Storage_Exception( 'Google Drive accepted only part of the data.', Storage_Exception::TRANSIENT );
					}
					return;
				}
				$file             = self::json( $response );
				$state['file_id'] = isset( $file['id'] ) ? (string) $file['id'] : '';
			}
		);
		return $state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $state Upload state.
	 * @throws Storage_Exception If the upload is incomplete or the stored size differs.
	 */
	public function finish( array $state ): void {
		$id = (string) $state['file_id'];
		if ( '' === $id ) {
			// The answer to the last part was lost; Drive knows whether the upload completed.
			$status = $this->retrying(
				function () use ( $state ) {
					return $this->upload_status( $state );
				}
			);
			if ( null === $status['file'] ) {
				throw new Storage_Exception( 'The upload is not complete on Google Drive.', Storage_Exception::REMOTE );
			}
			$id = (string) $status['file']['id'];
		}
		$meta = self::json( $this->request( 'GET', $this->file_url( $id ) . '?' . self::query( array( 'fields' => 'id,name,size,trashed' ) ) ) );
		$size = isset( $meta['size'] ) ? (int) $meta['size'] : -1;
		if ( $size !== (int) $state['size'] ) {
			throw new Storage_Exception( sprintf( 'The file on Google Drive has %d bytes instead of %d.', $size, $state['size'] ), Storage_Exception::REMOTE );
		}
		// An earlier copy with the same name (the same backup sent again) is replaced.
		foreach ( $this->ids_named( (string) $state['name'] ) as $other ) {
			if ( $other !== $id ) {
				$this->trash( $other );
			}
		}
		$this->ids[ (string) $state['name'] ] = $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $state Upload state.
	 */
	public function abort( array $state ): void {
		$this->request( 'DELETE', $state['session'], array( 'Content-Length' => '0' ), '', array( 499, 200, 204, 400, 404, 410 ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function list(): array {
		$folder = $this->folder( false );
		if ( null === $folder ) {
			return array();
		}
		$items = array();
		$token = null;
		$pages = 0;
		do {
			$query = array(
				'q'        => sprintf( "'%s' in parents and trashed = false and mimeType != '%s'", self::quote( $folder ), self::FOLDER_TYPE ),
				'fields'   => 'nextPageToken,files(id,name,size,modifiedTime)',
				'pageSize' => '1000',
				'orderBy'  => 'modifiedTime desc',
				'spaces'   => 'drive',
			);
			if ( null !== $token ) {
				$query['pageToken'] = $token;
			}
			$data = self::json( $this->request( 'GET', $this->endpoints['api'] . '/files?' . self::query( $query ) ) );
			foreach ( isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array() as $file ) {
				$name = isset( $file['name'] ) ? (string) $file['name'] : '';
				if ( isset( $this->ids[ $name ] ) && isset( $items[ $name ] ) ) {
					continue; // Newest first: an older copy with the same name is not listed.
				}
				if ( strlen( $name ) <= 200 && preg_match( Backups::SAFE_NAME, $name ) && isset( $file['id'] ) ) {
					$this->ids[ $name ] = (string) $file['id'];
					$items[ $name ]     = array(
						'name'     => $name,
						'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
						'modified' => isset( $file['modifiedTime'] ) ? (int) strtotime( (string) $file['modifiedTime'] ) : 0,
					);
				}
			}
			$token = isset( $data['nextPageToken'] ) && '' !== $data['nextPageToken'] ? (string) $data['nextPageToken'] : null;
		} while ( null !== $token && ++$pages < 100 );

		$items = array_values( $items );
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
	 * @throws Storage_Exception If the file is missing.
	 */
	public function download_range( string $name, int $offset, int $length ): string {
		if ( ! isset( $this->ids[ $name ] ) ) {
			$this->list();
		}
		if ( ! isset( $this->ids[ $name ] ) ) {
			throw new Storage_Exception( sprintf( '%s is not in the Google Drive folder.', $name ), Storage_Exception::NOT_FOUND );
		}
		return $this->download_id( $this->ids[ $name ], $offset, $length );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Backups are moved to the Drive trash (Google deletes them for good after 30 days).
	 *
	 * @param string $name File name.
	 */
	public function delete( string $name ): void {
		foreach ( $this->ids_named( $name ) as $id ) {
			$this->trash( $id );
		}
		unset( $this->ids[ $name ] );
	}

	/**
	 * The checks of test(): fresh token and account, folder, a small file uploaded resumably in
	 * two pieces, read back, listing, delete.
	 *
	 * @throws Storage_Exception Naming the check that failed.
	 */
	private function run_test(): array {
		$lines   = array();
		$account = $this->check(
			'Signing in to Google',
			function (): string {
				$this->token( true );
				return $this->account();
			}
		);
		$lines[] = sprintf( 'Signed in to Google Drive as %s.', $account );

		$this->check(
			'Opening the folder',
			function () {
				$this->folder( true );
			}
		);
		$lines[] = sprintf( 'Using the folder "%s" at the top of My Drive (SiteFerry sees only the files it created).', $this->settings['folder'] );

		$name  = '.siteferry-test-' . bin2hex( random_bytes( 6 ) ) . '.bin';
		$data  = random_bytes( self::CHUNK_UNIT ) . 'SiteFerry connection test, ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC.';
		$state = $this->check(
			'Writing a test file with a resumable upload',
			function () use ( $name, $data ): array {
				$state              = $this->begin( $name, strlen( $data ) );
				$state['part_size'] = self::CHUNK_UNIT;
				$state              = $this->put_part( $state, 0, substr( $data, 0, self::CHUNK_UNIT ) );
				$state              = $this->put_part( $state, 1, substr( $data, self::CHUNK_UNIT ) );
				$this->finish( $state );
				return $state;
			}
		);
		$lines[] = 'Wrote a test file with a resumable upload in two pieces.';

		$this->check(
			'Reading the test file back',
			function () use ( $state, $data ) {
				$id = (string) $this->ids[ $state['name'] ];
				if ( $this->download_id( $id, 0, strlen( $data ) ) !== $data ) {
					throw new Storage_Exception( 'The file read back differs from what was written.', Storage_Exception::REMOTE );
				}
			}
		);
		$lines[] = 'Read it back unchanged.';

		$count   = $this->check(
			'Listing the folder',
			function (): int {
				return count( $this->list() );
			}
		);
		$lines[] = sprintf( 'Listed the folder: %d SiteFerry backup(s).', $count );

		$this->check(
			'Deleting the test file',
			function () use ( $state ) {
				$this->request( 'DELETE', $this->file_url( (string) $this->ids[ $state['name'] ] ), array(), '', array( 204, 200 ) );
			}
		);
		$lines[] = 'Deleted the test file.';
		return $lines;
	}

	/**
	 * Id of the backup folder, found or created; null if it does not exist and $create is false.
	 *
	 * @param bool $create Create it if missing.
	 * @throws Storage_Exception If Drive refuses.
	 */
	private function folder( bool $create ): ?string {
		if ( $this->folder_ok ) {
			return (string) $this->auth['folder_id'];
		}
		$name = $this->settings['folder'];
		$id   = (string) $this->auth['folder_id'];
		if ( '' !== $id && $name === $this->auth['folder_name'] ) {
			$response = $this->request( 'GET', $this->file_url( $id ) . '?' . self::query( array( 'fields' => 'id,name,mimeType,trashed' ) ), array(), '', array( 200, 404 ) );
			$file     = self::json( $response );
			if ( 200 === $response['status'] && empty( $file['trashed'] ) && self::FOLDER_TYPE === ( isset( $file['mimeType'] ) ? $file['mimeType'] : '' ) ) {
				$this->folder_ok = true;
				return $id;
			}
		}

		// A folder SiteFerry made earlier (drive.file shows only the app's own files).
		$query = array(
			'q'        => sprintf( "mimeType = '%s' and name = '%s' and 'root' in parents and trashed = false", self::FOLDER_TYPE, self::quote( $name ) ),
			'fields'   => 'files(id,name)',
			'spaces'   => 'drive',
			'pageSize' => '10',
		);
		$files = self::json( $this->request( 'GET', $this->endpoints['api'] . '/files?' . self::query( $query ) ) );
		$id    = isset( $files['files'][0]['id'] ) ? (string) $files['files'][0]['id'] : '';
		if ( '' === $id ) {
			if ( ! $create ) {
				return null;
			}
			$made = self::json(
				$this->request(
					'POST',
					$this->endpoints['api'] . '/files?' . self::query( array( 'fields' => 'id' ) ),
					array( 'Content-Type' => 'application/json; charset=UTF-8' ),
					self::encode(
						array(
							'name'     => $name,
							'mimeType' => self::FOLDER_TYPE,
							'parents'  => array( 'root' ),
						)
					)
				)
			);
			$id = isset( $made['id'] ) ? (string) $made['id'] : '';
			if ( '' === $id ) {
				throw new Storage_Exception( 'Google Drive did not create the folder.', Storage_Exception::REMOTE );
			}
		}
		$this->auth['folder_id']   = $id;
		$this->auth['folder_name'] = $name;
		$this->save_auth();
		$this->folder_ok = true;
		return $id;
	}

	/**
	 * Ids of the files with a name in the folder.
	 *
	 * @param string $name File name.
	 * @return string[]
	 */
	private function ids_named( string $name ): array {
		$folder = $this->folder( false );
		if ( null === $folder ) {
			return array();
		}
		$query = array(
			'q'        => sprintf( "'%s' in parents and name = '%s' and trashed = false", self::quote( $folder ), self::quote( $name ) ),
			'fields'   => 'files(id)',
			'spaces'   => 'drive',
			'pageSize' => '100',
		);
		$data  = self::json( $this->request( 'GET', $this->endpoints['api'] . '/files?' . self::query( $query ) ) );
		$ids   = array();
		foreach ( isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array() as $file ) {
			if ( isset( $file['id'] ) ) {
				$ids[] = (string) $file['id'];
			}
		}
		return $ids;
	}

	/**
	 * Moves a file to the trash.
	 *
	 * @param string $id File id.
	 */
	private function trash( string $id ): void {
		$this->request( 'PATCH', $this->file_url( $id ) . '?' . self::query( array( 'fields' => 'id' ) ), array( 'Content-Type' => 'application/json; charset=UTF-8' ), '{"trashed":true}', array( 200, 404 ) );
	}

	/**
	 * Reads part of a file by id.
	 *
	 * @param string $id     File id.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes.
	 * @throws Storage_Exception If the range cannot be read.
	 */
	private function download_id( string $id, int $offset, int $length ): string {
		$response = $this->request( 'GET', $this->file_url( $id ) . '?alt=media', array( 'Range' => sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 ) ), '', array( 206, 200 ), self::TIMEOUT );
		if ( 200 === $response['status'] ) {
			return (string) substr( $response['body'], $offset, $length );
		}
		$range = isset( $response['headers']['content-range'] ) ? $response['headers']['content-range'] : '';
		if ( ! preg_match( '/^bytes ' . $offset . '-/', $range ) ) {
			throw new Storage_Exception( 'Google Drive sent a different part of the file than requested.', Storage_Exception::REMOTE );
		}
		return $response['body'];
	}

	/**
	 * Asks Drive how much of a resumable upload it has.
	 *
	 * @param array $state Upload state.
	 * @return array{received:int,file:array|null} Bytes received, or the file once complete.
	 */
	private function upload_status( array $state ): array {
		$response = $this->once(
			'PUT',
			$state['session'],
			array(
				'Content-Range'  => 'bytes */' . (int) $state['size'],
				'Content-Length' => '0',
			),
			'',
			array( 200, 201, 308 )
		);
		if ( 308 === $response['status'] ) {
			return array(
				'received' => self::received( $response ),
				'file'     => null,
			);
		}
		$file = self::json( $response );
		return array(
			'received' => (int) $state['size'],
			'file'     => isset( $file['id'] ) ? $file : array( 'id' => '' ),
		);
	}

	/**
	 * Email address of the connected account.
	 */
	private function account(): string {
		$about   = self::json( $this->request( 'GET', $this->endpoints['api'] . '/about?' . self::query( array( 'fields' => 'user(emailAddress,displayName)' ) ) ) );
		$account = isset( $about['user']['emailAddress'] ) ? (string) $about['user']['emailAddress'] : '';
		if ( $account !== $this->auth['account'] ) {
			$this->auth['account'] = $account;
			$this->save_auth();
		}
		return $account;
	}

	/**
	 * A valid access token, refreshed when it expires within five minutes (or when forced).
	 *
	 * @param bool $force Refresh even if the token seems valid.
	 * @throws Storage_Exception If the storage is not connected or Google refuses.
	 */
	private function token( bool $force = false ): string {
		if ( ! $force && '' !== $this->auth['access_token'] && (int) $this->auth['expires'] - 300 > $this->now() ) {
			return (string) $this->auth['access_token'];
		}
		if ( ! $this->connected() ) {
			throw new Storage_Exception( 'Google Drive is not connected yet. Click "Connect to Google Drive" on the Storage tab.', Storage_Exception::AUTH );
		}
		$data                        = $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->auth['refresh_token'],
				'client_id'     => $this->settings['client_id'],
				'client_secret' => $this->client_secret,
			)
		);
		$this->auth['access_token'] = (string) $data['access_token'];
		$this->auth['expires']      = $this->now() + (int) ( isset( $data['expires_in'] ) ? $data['expires_in'] : 3600 );
		if ( ! empty( $data['refresh_token'] ) ) {
			$this->auth['refresh_token'] = (string) $data['refresh_token'];
		}
		$this->save_auth();
		return (string) $this->auth['access_token'];
	}

	/**
	 * A request to the token endpoint (form-encoded), with retries for temporary failures.
	 *
	 * @param array $fields Form fields.
	 * @return array The answer.
	 * @throws Storage_Exception If Google refuses.
	 */
	private function token_request( array $fields ): array {
		$relayed = $this->relayed();
		$url     = $this->endpoints['token'];
		if ( $relayed ) {
			// The relay adds the client ID, the secret and its own redirect URI.
			$url = $this->endpoints['relay'] . '/google/token';
			unset( $fields['client_id'], $fields['client_secret'], $fields['redirect_uri'] );
		}
		$service = $relayed ? 'The sign-in service ' . parse_url( $url, PHP_URL_HOST ) : 'Google\'s sign-in service';
		return $this->retrying(
			function () use ( $fields, $url, $relayed, $service ): array {
				try {
					$response = $this->transport->request( 'POST', $url, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $fields, '', '&' ), self::SHORT_TIMEOUT );
				} catch ( Storage_Exception $e ) {
					throw new Storage_Exception( $service . ' cannot be reached: ' . $e->getMessage(), $e->getCode(), $e );
				}
				$data = self::json( $response );
				if ( 200 === $response['status'] && ! empty( $data['access_token'] ) ) {
					return $data;
				}
				$code = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
				$text = isset( $data['error_description'] ) && is_string( $data['error_description'] ) ? ': ' . $data['error_description'] : '';
				$text = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $text );
				if ( $response['status'] >= 500 || 429 === $response['status'] ) {
					throw new Storage_Exception( sprintf( '%s answered HTTP %d; try again later.', $service, $response['status'] ), Storage_Exception::TRANSIENT );
				}
				if ( 'invalid_grant' === $code ) {
					throw new Storage_Exception( 'Google no longer accepts the saved authorization (invalid_grant' . $text . '). Connect Google Drive again on the Storage tab. If the app\'s consent screen is in "Testing", Google ends authorizations after 7 days; publish the app to keep them.', Storage_Exception::AUTH );
				}
				if ( in_array( $code, array( 'invalid_client', 'unauthorized_client' ), true ) ) {
					throw new Storage_Exception(
						$relayed
							? $service . ' is not set up correctly (' . $code . $text . '). This needs fixing at ' . parse_url( $url, PHP_URL_HOST ) . ', not on this site.'
							: 'Google refused the client ID or client secret (' . $code . $text . '). Check them on the Storage tab.',
						Storage_Exception::AUTH
					);
				}
				throw new Storage_Exception( sprintf( '%s refused (HTTP %d%s%s).', $service, $response['status'], '' === $code ? '' : ' ' . $code, $text ), Storage_Exception::REMOTE );
			}
		);
	}

	/**
	 * Whether sign-in and token renewals go through the relay.
	 */
	private function relayed(): bool {
		return 'relay' === self::mode_of( $this->settings );
	}

	/**
	 * A Drive request with retries for temporary failures.
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
	 * One Drive request with the bearer token; a refused token is refreshed and the request sent
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
	 * Refuses to send the token to any host but the configured Google API hosts.
	 *
	 * @param string $url URL.
	 * @throws Storage_Exception For other hosts.
	 */
	private function check_host( string $url ): void {
		$allowed = array();
		foreach ( array( 'api', 'upload' ) as $key ) {
			$allowed[] = strtolower( (string) parse_url( $this->endpoints[ $key ], PHP_URL_SCHEME ) . '://' . parse_url( $this->endpoints[ $key ], PHP_URL_HOST ) . ( null === parse_url( $this->endpoints[ $key ], PHP_URL_PORT ) ? '' : ':' . parse_url( $this->endpoints[ $key ], PHP_URL_PORT ) ) );
		}
		$host = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST ) . ( null === parse_url( $url, PHP_URL_PORT ) ? '' : ':' . parse_url( $url, PHP_URL_PORT ) ) );
		if ( ! in_array( $host, $allowed, true ) ) {
			throw new Storage_Exception( 'Refusing to send the Google token to ' . $host . '.', Storage_Exception::REMOTE );
		}
	}

	/**
	 * Turns an error answer into an exception with a readable message.
	 *
	 * @param array{status:int,headers:array,body:string} $response Response.
	 */
	private function error( array $response ): Storage_Exception {
		$status  = (int) $response['status'];
		$data    = self::json( $response );
		$error   = isset( $data['error'] ) ? $data['error'] : null;
		$message = '';
		$reason  = '';
		if ( is_array( $error ) ) {
			$message = isset( $error['message'] ) ? (string) $error['message'] : '';
			$reason  = isset( $error['errors'][0]['reason'] ) ? (string) $error['errors'][0]['reason'] : ( isset( $error['status'] ) ? (string) $error['status'] : '' );
		} elseif ( is_string( $error ) ) {
			$reason  = $error;
			$message = isset( $data['error_description'] ) ? (string) $data['error_description'] : '';
		}

		$hints = array(
			'storageQuotaExceeded'        => 'The Google Drive is full.',
			'insufficientPermissions'     => 'SiteFerry was not given access to Drive; connect again and allow it.',
			'insufficientFilePermissions' => 'SiteFerry may only use files it created itself.',
			'authError'                   => 'Connect Google Drive again on the Storage tab.',
		);
		$transient = $status >= 500 || 429 === $status || ( 403 === $status && in_array( $reason, array( 'rateLimitExceeded', 'userRateLimitExceeded', 'backendError' ), true ) );
		if ( $transient ) {
			$kind = Storage_Exception::TRANSIENT;
		} elseif ( 'storageQuotaExceeded' === $reason ) {
			$kind = Storage_Exception::REMOTE;
		} elseif ( 401 === $status || 403 === $status ) {
			$kind = Storage_Exception::AUTH;
		} elseif ( 404 === $status ) {
			$kind = Storage_Exception::NOT_FOUND;
		} else {
			$kind = Storage_Exception::REMOTE;
		}
		$text = sprintf( 'Google Drive answered HTTP %d', $status ) . ( '' === $reason ? '' : ' (' . $reason . ')' );
		$text .= '' === $message ? '.' : ': ' . $message;
		$text  = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $text );
		$text  = strlen( $text ) > 400 ? substr( $text, 0, 400 ) . '...' : $text;
		return new Storage_Exception( trim( $text . ' ' . ( isset( $hints[ $reason ] ) ? $hints[ $reason ] : '' ) ), $kind );
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
	 * URL of a file.
	 *
	 * @param string $id File id.
	 */
	private function file_url( string $id ): string {
		return $this->endpoints['api'] . '/files/' . rawurlencode( $id );
	}

	/**
	 * Bytes Drive has received, from the Range header of a 308 answer ("bytes=0-N").
	 *
	 * @param array $response Response.
	 */
	private static function received( array $response ): int {
		$range = isset( $response['headers']['range'] ) ? (string) $response['headers']['range'] : '';
		return preg_match( '/^bytes=0-(\d+)$/', trim( $range ), $match ) ? (int) $match[1] + 1 : 0;
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
	 * A value for a Drive search query, inside single quotes.
	 *
	 * @param string $value Value.
	 */
	private static function quote( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
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

