<?php
/**
 * A small Dropbox look-alike for tests: the OAuth authorization page and token endpoint (PKCE
 * without an app secret, one-time codes, offline refresh tokens) and the part of the API v2 that
 * SiteFerry uses (account, revoke, list_folder, get_metadata, delete_v2, upload sessions, ranged
 * downloads). State lives in a folder, so it works in-process (Fake_Dropbox_Transport) and over
 * HTTP (tests/tools/fake-dropbox-server.php under "php -S").
 *
 * Like Dropbox it is strict about the request format: RPC calls need Content-Type
 * application/json, uploads application/octet-stream, downloads no Content-Type and no body; the
 * Dropbox-API-Arg header must be JSON in plain ASCII; appends must start exactly where the
 * session's data ends; and it hands back at most 500 bytes of state.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Storage\Storage_Exception;
use SiteFerry\Storage\Transport;

/**
 * The fake service.
 */
final class Fake_Dropbox {

	/** Routes on the content host. */
	const CONTENT = array( 'files/upload_session/start', 'files/upload_session/append_v2', 'files/upload_session/finish', 'files/download' );

	/** Routes on the API host. */
	const RPC = array( 'users/get_current_account', 'auth/token/revoke', 'files/list_folder', 'files/list_folder/continue', 'files/get_metadata', 'files/delete_v2' );

	/**
	 * Settings: base (URL of the API host and the OAuth pages), content (URL of the content host,
	 * default base), app_key, account, token_ttl, page_size, grant_scope, no_refresh, quota,
	 * redirect_uris (when set, the only redirect addresses accepted).
	 *
	 * @var array
	 */
	public $config;

	/**
	 * Data folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Faults for the next API requests: an HTTP status, "lost" (the request is done, then its answer
	 * is a 503), "pass" (no fault for this one) or ["status" => 409, "summary" => "...", "error" => [...]].
	 *
	 * @var array
	 */
	public $fail_next = array();

	/**
	 * Requests: method and route (no tokens).
	 *
	 * @var string[]
	 */
	public $log = array();

	/**
	 * Loaded state.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Whether fail_next came from faults.json (server mode), so what is left is written back.
	 *
	 * @var bool
	 */
	private $faults_from_file = false;

	/**
	 * Constructor.
	 *
	 * @param string $dir    Data folder (created).
	 * @param array  $config Settings.
	 */
	public function __construct( string $dir, array $config ) {
		$this->dir    = rtrim( $dir, '/\\' );
		$this->config = $config + array(
			'content'       => isset( $config['base'] ) ? $config['base'] : '',
			'account'       => 'tester@example.com',
			'token_ttl'     => 14400,
			'page_size'     => 2000,
			'grant_scope'   => 'account_info.read files.metadata.read files.content.read files.content.write',
			'no_refresh'    => false,
			'quota'         => 0,
			'redirect_uris' => array(),
		);
		foreach ( array( '', '/blobs', '/sessions' ) as $sub ) {
			if ( ! is_dir( $this->dir . $sub ) ) {
				mkdir( $this->dir . $sub, 0755, true );
			}
		}
	}

	/**
	 * Handles one request.
	 *
	 * @param string $method  Method.
	 * @param string $url     Full URL.
	 * @param array  $headers Headers (lower-case names).
	 * @param string $body    Body.
	 * @return array{status:int,headers:array,body:string}
	 */
	public function handle( string $method, string $url, array $headers, string $body ): array {
		$this->load();
		$path  = (string) parse_url( $url, PHP_URL_PATH );
		$query = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		try {
			if ( '/oauth2/authorize' === $path ) {
				$this->log[] = 'GET authorize';
				return $this->consent( $query );
			}
			if ( '/oauth2/token' === $path && 'POST' === $method ) {
				$this->log[] = 'POST token';
				return $this->token( $body );
			}
			$route = 0 === strpos( $path, '/2/' ) ? substr( $path, 3 ) : '';
			$this->log[] = $method . ' ' . $route;
			$content     = in_array( $route, self::CONTENT, true );
			if ( ! $content && ! in_array( $route, self::RPC, true ) ) {
				return self::text( 404, 'Unknown API function: "' . $route . '"' );
			}
			if ( self::origin( $url ) !== self::origin( $content ? $this->config['content'] : $this->config['base'] ) ) {
				return self::text( 404, 'Unknown API function on this host: "' . $route . '"' );
			}
			$type = isset( $headers['content-type'] ) ? trim( (string) $headers['content-type'] ) : '';
			if ( 'files/download' === $route ) {
				if ( '' !== $type ) {
					return self::text( 400, 'Error in call to API function "files/download": You provided a non-empty HTTP "Content-Type" header ("' . $type . '").  This API function requires that the header be missing or empty.' );
				}
				if ( '' !== $body || ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
					return self::text( 400, 'Error in call to API function "files/download": The request body is supposed to be empty.' );
				}
			} elseif ( 'POST' !== $method ) {
				return self::text( 405, 'Use POST.' );
			} elseif ( $content && 'application/octet-stream' !== strtolower( $type ) ) {
				return self::text( 400, 'Error in call to API function "' . $route . '": Bad HTTP "Content-Type" header: "' . $type . '".  Expecting one of "application/octet-stream", "text/plain; charset=dropbox-cors-hack".' );
			} elseif ( ! $content && ! in_array( strtolower( $type ), array( 'application/json', 'application/json; charset=utf-8' ), true ) ) {
				return self::text( 400, 'Error in call to API function "' . $route . '": Bad HTTP "Content-Type" header: "' . $type . '".  Expecting one of "application/json", "application/json; charset=utf-8", "text/plain; charset=dropbox-cors-hack".' );
			}
			if ( $content ) {
				$raw = isset( $headers['dropbox-api-arg'] ) ? (string) $headers['dropbox-api-arg'] : '';
				if ( preg_match( '/[^\x20-\x7e]/', $raw ) ) {
					return self::text( 400, 'Error in call to API function "' . $route . '": HTTP header "Dropbox-API-Arg": contains characters outside ASCII; escape them in the JSON.' );
				}
				$arg = json_decode( $raw, true );
			} else {
				$arg = 'null' === trim( $body ) ? null : json_decode( $body, true );
			}
			if ( ! is_array( $arg ) && ! ( null === $arg && in_array( $route, array( 'users/get_current_account', 'auth/token/revoke' ), true ) ) ) {
				return self::text( 400, 'Error in call to API function "' . $route . '": could not decode input as JSON' );
			}

			$denied = $this->check_token( $headers );
			if ( null !== $denied ) {
				return $denied;
			}
			$fault = array() === $this->fail_next ? null : array_shift( $this->fail_next );
			if ( 'pass' === $fault ) {
				$fault = null; // This request goes through; the next fault is for the one after.
			}
			if ( null !== $fault && 'lost' !== $fault ) {
				if ( is_array( $fault ) ) {
					return $this->api_error( (int) $fault['status'], (string) $fault['summary'], isset( $fault['error'] ) ? (array) $fault['error'] : array( '.tag' => 'other' ) );
				}
				if ( 429 === (int) $fault ) {
					return array(
						'status'  => 429,
						'headers' => array( 'content-type' => 'application/json', 'retry-after' => '1' ),
						'body'    => '{"error_summary": "too_many_requests/..", "error": {"reason": {".tag": "too_many_requests"}, "retry_after": 1}}',
					);
				}
				return self::text( (int) $fault, 'Injected failure.' );
			}
			$answer = $this->route( $route, (array) $arg, $headers, $body );
			if ( 'lost' === $fault ) {
				return self::text( 503, 'The work was done, but this answer was lost.' );
			}
			return $answer;
		} finally {
			$this->save();
		}
	}

	/**
	 * Runs an API route.
	 *
	 * @param string $route   Route.
	 * @param array  $arg     Argument.
	 * @param array  $headers Headers.
	 * @param string $body    Body (uploads).
	 */
	private function route( string $route, array $arg, array $headers, string $body ): array {
		switch ( $route ) {
			case 'users/get_current_account':
				return $this->json(
					200,
					array(
						'account_id' => 'dbid:fake-account',
						'name'       => array( 'display_name' => 'Test User' ),
						'email'      => $this->config['account'],
					)
				);
			case 'auth/token/revoke':
				$token = substr( (string) $headers['authorization'], 7 );
				$this->revoke_refresh( $this->state['access'][ $token ]['refresh'] );
				return $this->json( 200, null );
			case 'files/list_folder':
				return $this->list_folder( isset( $arg['path'] ) ? (string) $arg['path'] : '', 0, isset( $arg['limit'] ) ? (int) $arg['limit'] : 2000 );
			case 'files/list_folder/continue':
				$cursor = json_decode( (string) base64_decode( isset( $arg['cursor'] ) ? (string) $arg['cursor'] : '' ), true );
				if ( ! is_array( $cursor ) ) {
					return $this->api_error( 409, 'reset/..', array( '.tag' => 'reset' ) );
				}
				return $this->list_folder( (string) $cursor['p'], (int) $cursor['o'], (int) $cursor['l'] );
			case 'files/get_metadata':
				$key = self::lower( isset( $arg['path'] ) ? (string) $arg['path'] : '' );
				if ( isset( $this->state['files'][ $key ] ) ) {
					return $this->json( 200, $this->metadata( $key ) );
				}
				if ( $this->folder_exists( $key ) && '' !== $key ) {
					return $this->json( 200, array( '.tag' => 'folder', 'name' => basename( $key ), 'path_lower' => $key, 'path_display' => $key, 'id' => 'id:folder' ) );
				}
				return $this->api_error( 409, 'path/not_found/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'not_found' ) ) );
			case 'files/delete_v2':
				$key = self::lower( isset( $arg['path'] ) ? (string) $arg['path'] : '' );
				if ( ! isset( $this->state['files'][ $key ] ) ) {
					return $this->api_error( 409, 'path_lookup/not_found/..', array( '.tag' => 'path_lookup', 'path_lookup' => array( '.tag' => 'not_found' ) ) );
				}
				$meta = $this->metadata( $key );
				@unlink( $this->dir . '/blobs/' . $this->state['files'][ $key ]['blob'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				unset( $this->state['files'][ $key ] );
				return $this->json( 200, array( 'metadata' => $meta ) );
			case 'files/upload_session/start':
				$id = 'sess-' . bin2hex( random_bytes( 10 ) );
				file_put_contents( $this->dir . '/sessions/' . $id, $body );
				$this->state['sessions'][ $id ] = array(
					'offset' => strlen( $body ),
					'closed' => ! empty( $arg['close'] ),
				);
				return $this->json( 200, array( 'session_id' => $id ) );
			case 'files/upload_session/append_v2':
				$cursor = isset( $arg['cursor'] ) ? (array) $arg['cursor'] : array();
				$lookup = $this->lookup( $cursor );
				if ( null !== $lookup ) {
					return $this->api_error( 409, $lookup['.tag'] . '/..', $lookup );
				}
				$id = (string) $cursor['session_id'];
				file_put_contents( $this->dir . '/sessions/' . $id, $body, FILE_APPEND );
				$this->state['sessions'][ $id ]['offset'] += strlen( $body );
				$this->state['sessions'][ $id ]['closed']  = ! empty( $arg['close'] );
				return $this->json( 200, null );
			case 'files/upload_session/finish':
				$cursor = isset( $arg['cursor'] ) ? (array) $arg['cursor'] : array();
				$lookup = $this->lookup( $cursor, true );
				if ( null !== $lookup ) {
					return $this->api_error( 409, 'lookup_failed/' . $lookup['.tag'] . '/..', array( '.tag' => 'lookup_failed', 'lookup_failed' => $lookup ) );
				}
				$id = (string) $cursor['session_id'];
				file_put_contents( $this->dir . '/sessions/' . $id, $body, FILE_APPEND );
				return $this->commit( $id, isset( $arg['commit'] ) ? (array) $arg['commit'] : array() );
			default: // files/download.
				$key = self::lower( isset( $arg['path'] ) ? (string) $arg['path'] : '' );
				if ( ! isset( $this->state['files'][ $key ] ) ) {
					return $this->api_error( 409, 'path/not_found/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'not_found' ) ) );
				}
				return $this->download( $key, isset( $headers['range'] ) ? (string) $headers['range'] : '' );
		}
	}

	/**
	 * Checks an upload session cursor.
	 *
	 * @param array $cursor  Cursor: session_id, offset.
	 * @param bool  $closing Whether the session is being finished (a closed session may be).
	 * @return array|null The lookup error, or null if the cursor fits.
	 */
	private function lookup( array $cursor, bool $closing = false ): ?array {
		$id = isset( $cursor['session_id'] ) ? (string) $cursor['session_id'] : '';
		if ( ! isset( $this->state['sessions'][ $id ] ) ) {
			return array( '.tag' => 'not_found' );
		}
		$session = $this->state['sessions'][ $id ];
		if ( $session['closed'] && ! $closing ) {
			return array( '.tag' => 'closed' );
		}
		if ( ! isset( $cursor['offset'] ) || (int) $cursor['offset'] !== (int) $session['offset'] ) {
			return array(
				'.tag'           => 'incorrect_offset',
				'correct_offset' => (int) $session['offset'],
			);
		}
		return null;
	}

	/**
	 * Turns a finished upload session into a file.
	 *
	 * @param string $id     Session id.
	 * @param array  $commit Commit info: path, mode, autorename, mute.
	 */
	private function commit( string $id, array $commit ): array {
		$display = isset( $commit['path'] ) ? (string) $commit['path'] : '';
		if ( '' === $display || '/' !== $display[0] || '/' === substr( $display, -1 ) || false !== strpos( $display, '//' ) ) {
			return $this->api_error( 409, 'path/malformed_path/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'malformed_path' ) ) );
		}
		$key  = self::lower( $display );
		$mode = isset( $commit['mode'] ) ? ( is_array( $commit['mode'] ) ? (string) $commit['mode']['.tag'] : (string) $commit['mode'] ) : 'add';
		if ( isset( $this->state['files'][ $key ] ) && 'overwrite' !== $mode ) {
			return $this->api_error( 409, 'path/conflict/file/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'conflict', 'conflict' => array( '.tag' => 'file' ) ) ) );
		}
		$size = (int) filesize( $this->dir . '/sessions/' . $id );
		if ( $this->config['quota'] > 0 && $this->used() - ( isset( $this->state['files'][ $key ] ) ? $this->state['files'][ $key ]['size'] : 0 ) + $size > $this->config['quota'] ) {
			return $this->api_error( 409, 'path/insufficient_space/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'insufficient_space' ) ) );
		}
		if ( isset( $this->state['files'][ $key ] ) ) {
			@unlink( $this->dir . '/blobs/' . $this->state['files'][ $key ]['blob'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$blob = bin2hex( random_bytes( 8 ) ); // The data's file name ("id:" is not allowed in Windows file names).
		rename( $this->dir . '/sessions/' . $id, $this->dir . '/blobs/' . $blob );
		unset( $this->state['sessions'][ $id ] );
		$this->state['clock']         = max( time(), (int) $this->state['clock'] + 1 );
		$this->state['files'][ $key ] = array(
			'name'     => basename( $display ),
			'display'  => $display,
			'id'       => 'id:' . $blob,
			'blob'     => $blob,
			'size'     => $size,
			'modified' => $this->state['clock'],
		);
		return $this->json( 200, $this->metadata( $key ) );
	}

	/**
	 * A page of a folder listing.
	 *
	 * @param string $path   Folder ('' for the top).
	 * @param int    $offset Entries already listed.
	 * @param int    $limit  Page size asked for.
	 */
	private function list_folder( string $path, int $offset, int $limit ): array {
		if ( '' !== $path && ( '/' !== $path[0] || '/' === substr( $path, -1 ) ) ) {
			return $this->api_error( 409, 'path/malformed_path/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'malformed_path' ) ) );
		}
		$key = self::lower( $path );
		if ( '' !== $key && ! $this->folder_exists( $key ) ) {
			return $this->api_error( 409, 'path/not_found/..', array( '.tag' => 'path', 'path' => array( '.tag' => 'not_found' ) ) );
		}
		$entries = array();
		foreach ( array_keys( $this->state['files'] ) as $file ) {
			if ( 0 !== strpos( $file, $key . '/' ) ) {
				continue;
			}
			$rest = substr( $file, strlen( $key ) + 1 );
			if ( false === strpos( $rest, '/' ) ) {
				$entries[ $rest ] = $this->metadata( $file );
			} else {
				$folder             = substr( $rest, 0, (int) strpos( $rest, '/' ) );
				$entries[ $folder ] = array(
					'.tag'         => 'folder',
					'name'         => $folder,
					'path_lower'   => $key . '/' . $folder,
					'path_display' => $key . '/' . $folder,
					'id'           => 'id:folder-' . md5( $key . '/' . $folder ),
				);
			}
		}
		ksort( $entries );
		$size    = max( 1, min( $limit, (int) $this->config['page_size'] ) );
		$page    = array_slice( array_values( $entries ), $offset, $size );
		$more    = $offset + $size < count( $entries );
		return $this->json(
			200,
			array(
				'entries'  => $page,
				'cursor'   => base64_encode( (string) json_encode( array( 'p' => $key, 'o' => $offset + $size, 'l' => $limit ) ) ),
				'has_more' => $more,
			)
		);
	}

	/**
	 * A file download, whole or a byte range.
	 *
	 * @param string $key   Lower-case path.
	 * @param string $range Range header.
	 */
	private function download( string $key, string $range ): array {
		$file = $this->state['files'][ $key ];
		$data = (string) file_get_contents( $this->dir . '/blobs/' . $file['blob'] );
		$meta = (string) json_encode( $this->metadata( $key ) );
		if ( preg_match( '/^bytes=(\d+)-(\d+)$/', $range, $match ) ) {
			$from = (int) $match[1];
			$to   = min( (int) $match[2], strlen( $data ) - 1 );
			if ( $from > $to ) {
				return self::text( 416, 'Range not satisfiable.' );
			}
			return array(
				'status'  => 206,
				'headers' => array(
					'content-type'       => 'application/octet-stream',
					'content-range'      => sprintf( 'bytes %d-%d/%d', $from, $to, strlen( $data ) ),
					'dropbox-api-result' => $meta,
				),
				'body'    => (string) substr( $data, $from, $to - $from + 1 ),
			);
		}
		return array(
			'status'  => 200,
			'headers' => array(
				'content-type'       => 'application/octet-stream',
				'dropbox-api-result' => $meta,
			),
			'body'    => $data,
		);
	}

	/**
	 * Metadata of a file as Dropbox shows it.
	 *
	 * @param string $key Lower-case path.
	 */
	private function metadata( string $key ): array {
		$file = $this->state['files'][ $key ];
		$when = gmdate( 'Y-m-d\TH:i:s\Z', (int) $file['modified'] );
		return array(
			'.tag'            => 'file',
			'name'            => $file['name'],
			'path_lower'      => $key,
			'path_display'    => $file['display'],
			'id'              => $file['id'],
			'client_modified' => $when,
			'server_modified' => $when,
			'rev'             => substr( md5( $file['id'] ), 0, 12 ),
			'size'            => (int) $file['size'],
		);
	}

	/**
	 * Whether a folder exists (it holds a file, at any depth).
	 *
	 * @param string $key Lower-case path.
	 */
	private function folder_exists( string $key ): bool {
		foreach ( array_keys( $this->state['files'] ) as $file ) {
			if ( 0 === strpos( $file, $key . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Bytes stored.
	 */
	private function used(): int {
		return (int) array_sum( array_column( $this->state['files'], 'size' ) );
	}

	/**
	 * The authorization page: shows Allow / Deny links; with decision=allow or deny it redirects back.
	 *
	 * @param array $query Query.
	 */
	private function consent( array $query ): array {
		$client   = isset( $query['client_id'] ) ? (string) $query['client_id'] : '';
		$redirect = isset( $query['redirect_uri'] ) ? (string) $query['redirect_uri'] : '';
		$state    = isset( $query['state'] ) ? (string) $query['state'] : '';
		if ( $client !== $this->config['app_key'] ) {
			return self::html( 400, 'Invalid client_id: "' . htmlspecialchars( $client ) . '"' );
		}
		if ( array() !== $this->config['redirect_uris'] && ! in_array( $redirect, (array) $this->config['redirect_uris'], true ) ) {
			return self::html( 400, 'Invalid redirect_uri. It must exactly match one of the redirect URIs you\'ve pre-configured for your app.' );
		}
		if ( strlen( $state ) > 500 ) {
			return self::html( 400, 'Invalid state: at most 500 bytes.' );
		}
		if ( 'code' !== ( isset( $query['response_type'] ) ? $query['response_type'] : '' ) || 'S256' !== ( isset( $query['code_challenge_method'] ) ? $query['code_challenge_method'] : '' ) || empty( $query['code_challenge'] ) ) {
			return self::html( 400, 'This fake Dropbox needs response_type=code and a PKCE S256 challenge.' );
		}
		$decision = isset( $query['decision'] ) ? (string) $query['decision'] : '';
		$join     = false === strpos( $redirect, '?' ) ? '?' : '&';
		if ( 'allow' === $decision ) {
			$code                          = 'code-' . bin2hex( random_bytes( 12 ) );
			$this->state['codes'][ $code ] = array(
				'client'    => $client,
				'redirect'  => $redirect,
				'challenge' => (string) $query['code_challenge'],
				'offline'   => 'offline' === ( isset( $query['token_access_type'] ) ? $query['token_access_type'] : '' ),
			);
			return array( 'status' => 302, 'headers' => array( 'location' => $redirect . $join . http_build_query( array( 'code' => $code, 'state' => $state ) ) ), 'body' => '' );
		}
		if ( 'deny' === $decision ) {
			return array( 'status' => 302, 'headers' => array( 'location' => $redirect . $join . http_build_query( array( 'error' => 'access_denied', 'error_description' => 'The user chose not to give your app access to their Dropbox account.', 'state' => $state ) ) ), 'body' => '' );
		}
		$self = $this->config['base'] . '/oauth2/authorize?' . http_build_query( $query );
		return self::html(
			200,
			'<h1>Fake Dropbox: allow access?</h1><p>App ' . htmlspecialchars( $client ) . ' asks for: ' . htmlspecialchars( isset( $query['scope'] ) ? (string) $query['scope'] : '(app permissions)' ) . '</p>'
			. '<p><a id="allow" href="' . htmlspecialchars( $self . '&decision=allow' ) . '">Allow</a> <a id="deny" href="' . htmlspecialchars( $self . '&decision=deny' ) . '">Cancel</a></p>'
		);
	}

	/**
	 * The token endpoint (no app secret needed: PKCE).
	 *
	 * @param string $body Form body.
	 */
	private function token( string $body ): array {
		$form = array();
		parse_str( $body, $form );
		$grant = isset( $form['grant_type'] ) ? (string) $form['grant_type'] : '';
		$this->state['token_calls'][ $grant ] = ( isset( $this->state['token_calls'][ $grant ] ) ? $this->state['token_calls'][ $grant ] : 0 ) + 1;
		if ( isset( $form['client_secret'] ) ) {
			++$this->state['secrets_sent'];
		}
		if ( ( isset( $form['client_id'] ) ? $form['client_id'] : '' ) !== $this->config['app_key'] ) {
			return $this->json( 400, array( 'error' => 'invalid_client', 'error_description' => 'Invalid client_id or client_secret' ) );
		}
		if ( 'authorization_code' === $grant ) {
			$code  = isset( $form['code'] ) ? (string) $form['code'] : '';
			$saved = isset( $this->state['codes'][ $code ] ) ? $this->state['codes'][ $code ] : null;
			unset( $this->state['codes'][ $code ] );
			$verifier  = isset( $form['code_verifier'] ) ? (string) $form['code_verifier'] : '';
			$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			if ( null === $saved ) {
				return $this->json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'code doesn\'t exist or has expired' ) );
			}
			if ( $saved['redirect'] !== ( isset( $form['redirect_uri'] ) ? $form['redirect_uri'] : '' ) ) {
				return $this->json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'redirect_uri mismatch' ) );
			}
			if ( ! hash_equals( $saved['challenge'], $challenge ) ) {
				return $this->json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'invalid code verifier' ) );
			}
			$refresh                            = 'refresh-' . bin2hex( random_bytes( 16 ) );
			$this->state['refresh'][ $refresh ] = true;
			$answer                             = $this->access( $refresh ) + array(
				'scope'      => $this->config['grant_scope'],
				'account_id' => 'dbid:fake-account',
				'uid'        => '12345',
			);
			if ( $saved['offline'] && ! $this->config['no_refresh'] ) {
				$answer['refresh_token'] = $refresh;
			}
			return $this->json( 200, $answer );
		}
		if ( 'refresh_token' === $grant ) {
			$refresh = isset( $form['refresh_token'] ) ? (string) $form['refresh_token'] : '';
			if ( empty( $this->state['refresh'][ $refresh ] ) ) {
				return $this->json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'refresh token is invalid or revoked' ) );
			}
			return $this->json( 200, $this->access( $refresh ) );
		}
		return $this->json( 400, array( 'error' => 'unsupported_grant_type', 'error_description' => 'grant_type is not supported' ) );
	}

	/**
	 * A new access token for a refresh token.
	 *
	 * @param string $refresh Refresh token.
	 */
	private function access( string $refresh ): array {
		$token                           = 'sl.access-' . bin2hex( random_bytes( 16 ) );
		$this->state['access'][ $token ] = array(
			'refresh' => $refresh,
			'expires' => time() + (int) $this->config['token_ttl'],
		);
		return array(
			'access_token' => $token,
			'token_type'   => 'bearer',
			'expires_in'   => (int) $this->config['token_ttl'],
		);
	}

	/**
	 * Revokes a refresh token and every access token issued for it.
	 *
	 * @param string $refresh Refresh token.
	 */
	private function revoke_refresh( string $refresh ): void {
		unset( $this->state['refresh'][ $refresh ] );
		foreach ( $this->state['access'] as $access => $data ) {
			if ( $data['refresh'] === $refresh ) {
				unset( $this->state['access'][ $access ] );
			}
		}
	}

	/**
	 * Checks the bearer token.
	 *
	 * @param array $headers Headers.
	 * @return array|null An error, or null if valid.
	 */
	private function check_token( array $headers ): ?array {
		$auth  = isset( $headers['authorization'] ) ? (string) $headers['authorization'] : '';
		$token = 0 === strpos( $auth, 'Bearer ' ) ? substr( $auth, 7 ) : '';
		$data  = isset( $this->state['access'][ $token ] ) ? $this->state['access'][ $token ] : null;
		if ( null === $data || $data['expires'] < time() || empty( $this->state['refresh'][ $data['refresh'] ] ) ) {
			return $this->api_error( 401, 'expired_access_token/', array( '.tag' => 'expired_access_token' ) );
		}
		return null;
	}

	/**
	 * A Dropbox API error.
	 *
	 * @param int    $status  Status.
	 * @param string $summary error_summary.
	 * @param array  $error   error.
	 */
	private function api_error( int $status, string $summary, array $error ): array {
		return $this->json(
			$status,
			array(
				'error_summary' => $summary,
				'error'         => $error,
			)
		);
	}

	/**
	 * A JSON answer.
	 *
	 * @param int        $status Status.
	 * @param array|null $data   Data.
	 */
	private function json( int $status, ?array $data ): array {
		return array(
			'status'  => $status,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => null === $data ? 'null' : (string) json_encode( $data, JSON_UNESCAPED_SLASHES ),
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
			'headers' => array( 'content-type' => 'text/plain; charset=utf-8' ),
			'body'    => $text,
		);
	}

	/**
	 * An HTML page.
	 *
	 * @param int    $status Status.
	 * @param string $html   Body.
	 */
	private static function html( int $status, string $html ): array {
		return array(
			'status'  => $status,
			'headers' => array( 'content-type' => 'text/html; charset=utf-8' ),
			'body'    => '<!doctype html><meta charset="utf-8"><title>Fake Dropbox</title>' . $html,
		);
	}

	/**
	 * A path in lower case, ASCII letters only (PHP 7's strtolower() follows the locale and can
	 * break UTF-8).
	 *
	 * @param string $path Path.
	 */
	private static function lower( string $path ): string {
		return strtr( $path, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Scheme, host and port of a URL.
	 *
	 * @param string $url URL.
	 */
	private static function origin( string $url ): string {
		$port = parse_url( $url, PHP_URL_PORT );
		return strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) . '://' . parse_url( $url, PHP_URL_HOST ) . ( null === $port ? '' : ':' . $port ) );
	}

	/**
	 * Files: lower-case path => name, display, id, size, modified.
	 */
	public function files(): array {
		$this->load();
		return $this->state['files'];
	}

	/**
	 * File names, at any depth.
	 *
	 * @return string[]
	 */
	public function names(): array {
		return array_values( array_column( $this->files(), 'name' ) );
	}

	/**
	 * SHA-256 of the file with a name (at any depth), or null.
	 *
	 * @param string $name File name.
	 */
	public function hash( string $name ): ?string {
		foreach ( $this->files() as $file ) {
			if ( $file['name'] === $name ) {
				return hash_file( 'sha256', $this->dir . '/blobs/' . $file['blob'] );
			}
		}
		return null;
	}

	/**
	 * Upload sessions not finished.
	 */
	public function open_sessions(): int {
		$this->load();
		return count( $this->state['sessions'] );
	}

	/**
	 * Invalidates every access token (refresh tokens stay valid).
	 */
	public function expire_access_tokens(): void {
		$this->load();
		$this->state['access'] = array();
		$this->save();
	}

	/**
	 * Revokes every refresh token.
	 */
	public function revoke_all(): void {
		$this->load();
		$this->state['refresh'] = array();
		$this->state['access']  = array();
		$this->save();
	}

	/**
	 * Counts token endpoint calls by grant type.
	 *
	 * @param string $grant Grant type.
	 */
	public function token_calls( string $grant ): int {
		$this->load();
		return isset( $this->state['token_calls'][ $grant ] ) ? (int) $this->state['token_calls'][ $grant ] : 0;
	}

	/**
	 * Token requests that carried an app secret.
	 */
	public function secrets_sent(): int {
		$this->load();
		return (int) $this->state['secrets_sent'];
	}

	/**
	 * Refresh tokens handed out and not revoked.
	 *
	 * @return string[]
	 */
	public function refresh_tokens(): array {
		$this->load();
		return array_keys( $this->state['refresh'] );
	}

	/**
	 * Loads the state.
	 */
	private function load(): void {
		$file        = $this->dir . '/state.json';
		$data        = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$this->state = ( is_array( $data ) ? $data : array() ) + array(
			'codes'        => array(),
			'refresh'      => array(),
			'access'       => array(),
			'files'        => array(),
			'sessions'     => array(),
			'token_calls'  => array(),
			'secrets_sent' => 0,
			'clock'        => 0,
		);
		$faults = $this->dir . '/faults.json';
		if ( is_file( $faults ) ) {
			$list = json_decode( (string) file_get_contents( $faults ), true );
			if ( is_array( $list ) ) {
				$this->fail_next        = $list;
				$this->faults_from_file = true;
			}
		}
	}

	/**
	 * Saves the state (and, in server mode, the faults not used yet).
	 */
	private function save(): void {
		file_put_contents( $this->dir . '/state.json', json_encode( $this->state, JSON_UNESCAPED_SLASHES ) );
		if ( $this->faults_from_file ) {
			file_put_contents( $this->dir . '/faults.json', json_encode( array_values( $this->fail_next ) ) );
		}
	}
}

/**
 * Transport that hands requests to a Fake_Dropbox in the same process.
 */
final class Fake_Dropbox_Transport implements Transport {

	/**
	 * The fake.
	 *
	 * @var Fake_Dropbox
	 */
	public $dropbox;

	/**
	 * Requests to fail with a network error.
	 *
	 * @var int
	 */
	public $drop_next = 0;

	/**
	 * Requests sent: method, URL, headers, body.
	 *
	 * @var array
	 */
	public $sent = array();

	/**
	 * Constructor.
	 *
	 * @param Fake_Dropbox $dropbox The fake.
	 */
	public function __construct( Fake_Dropbox $dropbox ) {
		$this->dropbox = $dropbox;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param int    $timeout Seconds.
	 * @throws Storage_Exception For simulated network failures.
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 60 ): array {
		$this->sent[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);
		if ( $this->drop_next > 0 ) {
			--$this->drop_next;
			throw new Storage_Exception( 'Network error: simulated timeout', Storage_Exception::TRANSIENT );
		}
		$lower = array();
		foreach ( $headers as $name => $value ) {
			$lower[ strtolower( $name ) ] = $value;
		}
		return $this->dropbox->handle( $method, $url, $lower, $body );
	}
}
