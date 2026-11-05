<?php
/**
 * A small Google look-alike for tests: the OAuth consent page and token endpoint (PKCE, one-time
 * codes, refresh, revoke) and the part of the Drive v3 API that SiteFerry uses (about, files
 * search with q, folders, resumable uploads, ranged downloads, trash, delete). State lives in a
 * folder, so it works in-process (Fake_Google_Transport) and over HTTP
 * (tests/tools/fake-google-server.php under "php -S").
 *
 * Like Drive, resumable chunks other than the last must be multiples of 256 KiB and must start
 * exactly where the received data ends.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Storage\Storage_Exception;
use SiteFerry\Storage\Transport;

/**
 * The fake service.
 */
final class Fake_Google {

	const CHUNK = 262144;

	/**
	 * Settings: base (URL of this service), client_id, client_secret, account, token_ttl,
	 * page_size, grant_scope, no_refresh, session_base.
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
	 * Faults for the next API requests: an HTTP status, "partial" (an upload chunk is half
	 * stored, then 503) or ["status" => 403, "reason" => "..."].
	 *
	 * @var array
	 */
	public $fail_next = array();

	/**
	 * Requests: method and path (no tokens).
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
			'token_ttl'    => 3600,
			'page_size'    => 1000,
			'grant_scope'  => 'https://www.googleapis.com/auth/drive.file',
			'no_refresh'   => false,
			'account'      => 'tester@example.com',
			'session_base' => '',
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
		$this->log[] = $method . ' ' . $path;
		try {
			if ( '/o/oauth2/v2/auth' === $path ) {
				return $this->consent( $query );
			}
			if ( '/token' === $path && 'POST' === $method ) {
				return $this->token( $body );
			}
			if ( '/revoke' === $path && 'POST' === $method ) {
				$form = array();
				parse_str( $body, $form );
				$this->revoke( isset( $form['token'] ) ? (string) $form['token'] : '' );
				return $this->json( 200, array() );
			}
			if ( 0 === strpos( $path, '/drive/v3/' ) || 0 === strpos( $path, '/upload/drive/v3/' ) ) {
				$denied = $this->check_token( $headers );
				if ( null !== $denied ) {
					return $denied;
				}
				if ( array() !== $this->fail_next ) {
					$fault = array_shift( $this->fail_next );
					if ( 'partial' === $fault && 'PUT' === $method && isset( $query['upload_id'] ) ) {
						$this->chunk( (string) $query['upload_id'], $headers, $body, true );
						return $this->drive_error( 503, 'backendError', 'Backend Error' );
					}
					if ( is_array( $fault ) ) {
						return $this->drive_error( (int) $fault['status'], (string) $fault['reason'], 'Injected.' );
					}
					return $this->drive_error( (int) $fault, 'backendError', 'Injected failure.' );
				}
				return 0 === strpos( $path, '/upload/' ) ? $this->upload( $method, $query, $headers, $body ) : $this->drive( $method, $path, $query, $headers, $body );
			}
			return $this->drive_error( 404, 'notFound', 'No such endpoint.' );
		} finally {
			$this->save();
		}
	}

	/**
	 * Files (not folders), for assertions: id => metadata.
	 *
	 * @param bool $with_trashed Include trashed files.
	 */
	public function files( bool $with_trashed = false ): array {
		$this->load();
		return array_filter(
			$this->state['files'],
			static function ( array $file ) use ( $with_trashed ): bool {
				return 'application/vnd.google-apps.folder' !== $file['mimeType'] && ( $with_trashed || ! $file['trashed'] );
			}
		);
	}

	/**
	 * Folders: id => metadata.
	 */
	public function folders(): array {
		$this->load();
		return array_filter(
			$this->state['files'],
			static function ( array $file ): bool {
				return 'application/vnd.google-apps.folder' === $file['mimeType'];
			}
		);
	}

	/**
	 * File that holds the stored bytes, or null.
	 *
	 * @param string $name File name.
	 */
	public function blob( string $name ): ?string {
		foreach ( array_reverse( $this->files(), true ) as $id => $file ) {
			if ( $file['name'] === $name ) {
				return $this->dir . '/blobs/' . $id;
			}
		}
		return null;
	}

	/**
	 * SHA-256 of the newest file with a name, or null.
	 *
	 * @param string $name File name.
	 */
	public function hash( string $name ): ?string {
		foreach ( array_reverse( $this->files(), true ) as $id => $file ) {
			if ( $file['name'] === $name ) {
				return hash_file( 'sha256', $this->dir . '/blobs/' . $id );
			}
		}
		return null;
	}

	/**
	 * Unfinished upload sessions.
	 */
	public function sessions(): int {
		$this->load();
		return count( $this->state['sessions'] );
	}

	/**
	 * Marks a file trashed (as a user would in Drive).
	 *
	 * @param string $id File id.
	 */
	public function trash( string $id ): void {
		$this->load();
		$this->state['files'][ $id ]['trashed'] = true;
		$this->save();
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
	 * The consent page: shows Allow / Deny links; with decision=allow or deny it redirects back.
	 *
	 * @param array $query Query.
	 */
	private function consent( array $query ): array {
		$client = isset( $query['client_id'] ) ? (string) $query['client_id'] : '';
		if ( $client !== $this->config['client_id'] ) {
			return array( 'status' => 400, 'headers' => array( 'content-type' => 'text/html' ), 'body' => '<p>Error 401: invalid_client</p>' );
		}
		$redirect = (string) $query['redirect_uri'];
		$state    = isset( $query['state'] ) ? (string) $query['state'] : '';
		$decision = isset( $query['decision'] ) ? (string) $query['decision'] : '';
		$join     = false === strpos( $redirect, '?' ) ? '?' : '&';
		if ( 'allow' === $decision ) {
			$code                          = 'code-' . bin2hex( random_bytes( 12 ) );
			$this->state['codes'][ $code ] = array(
				'redirect'  => $redirect,
				'challenge' => isset( $query['code_challenge'] ) ? (string) $query['code_challenge'] : '',
				'method'    => isset( $query['code_challenge_method'] ) ? (string) $query['code_challenge_method'] : '',
				'scope'     => $this->config['grant_scope'],
			);
			$location = $redirect . $join . http_build_query( array( 'state' => $state, 'code' => $code, 'scope' => $this->config['grant_scope'] ) );
			return array( 'status' => 302, 'headers' => array( 'location' => $location ), 'body' => '' );
		}
		if ( 'deny' === $decision ) {
			return array( 'status' => 302, 'headers' => array( 'location' => $redirect . $join . http_build_query( array( 'error' => 'access_denied', 'state' => $state ) ) ), 'body' => '' );
		}
		$self = $this->config['base'] . '/o/oauth2/v2/auth?' . http_build_query( $query );
		$html = '<!doctype html><meta charset="utf-8"><title>Fake Google consent</title><h1>Fake Google: allow access?</h1>'
			. '<p>Client ' . htmlspecialchars( $client ) . ' asks for: ' . htmlspecialchars( (string) $query['scope'] ) . '</p>'
			. '<p><a id="allow" href="' . htmlspecialchars( $self . '&decision=allow' ) . '">Allow</a> <a id="deny" href="' . htmlspecialchars( $self . '&decision=deny' ) . '">Deny</a></p>';
		return array( 'status' => 200, 'headers' => array( 'content-type' => 'text/html; charset=utf-8' ), 'body' => $html );
	}

	/**
	 * The token endpoint.
	 *
	 * @param string $body Form body.
	 */
	private function token( string $body ): array {
		$form = array();
		parse_str( $body, $form );
		$grant                                  = isset( $form['grant_type'] ) ? (string) $form['grant_type'] : '';
		$this->state['token_calls'][ $grant ] = ( isset( $this->state['token_calls'][ $grant ] ) ? $this->state['token_calls'][ $grant ] : 0 ) + 1;
		if ( ( isset( $form['client_id'] ) ? $form['client_id'] : '' ) !== $this->config['client_id'] || ! hash_equals( (string) $this->config['client_secret'], isset( $form['client_secret'] ) ? (string) $form['client_secret'] : '' ) ) {
			return $this->json( 401, array( 'error' => 'invalid_client', 'error_description' => 'Unauthorized' ) );
		}
		if ( 'authorization_code' === $grant ) {
			$code  = isset( $form['code'] ) ? (string) $form['code'] : '';
			$saved = isset( $this->state['codes'][ $code ] ) ? $this->state['codes'][ $code ] : null;
			unset( $this->state['codes'][ $code ] );
			$verifier  = isset( $form['code_verifier'] ) ? (string) $form['code_verifier'] : '';
			$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			if ( null === $saved || $saved['redirect'] !== ( isset( $form['redirect_uri'] ) ? $form['redirect_uri'] : '' ) || 'S256' !== $saved['method'] || ! hash_equals( $saved['challenge'], $challenge ) ) {
				return $this->json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Malformed auth code.' ) );
			}
			$refresh                              = 'refresh-' . bin2hex( random_bytes( 16 ) );
			$this->state['refresh'][ $refresh ] = true;
			$answer                               = $this->access( $refresh ) + array( 'scope' => $saved['scope'] );
			if ( ! $this->config['no_refresh'] ) {
				$answer['refresh_token'] = $refresh;
			}
			return $this->json( 200, $answer );
		}
		if ( 'refresh_token' === $grant ) {
			$refresh = isset( $form['refresh_token'] ) ? (string) $form['refresh_token'] : '';
			if ( empty( $this->state['refresh'][ $refresh ] ) ) {
				return $this->json( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ) );
			}
			return $this->json( 200, $this->access( $refresh ) + array( 'scope' => $this->config['grant_scope'] ) );
		}
		return $this->json( 400, array( 'error' => 'unsupported_grant_type' ) );
	}

	/**
	 * A new access token for a refresh token.
	 *
	 * @param string $refresh Refresh token.
	 */
	private function access( string $refresh ): array {
		$token                            = 'access-' . bin2hex( random_bytes( 16 ) );
		$this->state['access'][ $token ] = array(
			'refresh' => $refresh,
			'expires' => time() + (int) $this->config['token_ttl'],
		);
		return array(
			'access_token' => $token,
			'expires_in'   => (int) $this->config['token_ttl'],
			'token_type'   => 'Bearer',
		);
	}

	/**
	 * Revokes a refresh or access token (and the access tokens of a refresh token).
	 *
	 * @param string $token Token.
	 */
	private function revoke( string $token ): void {
		unset( $this->state['refresh'][ $token ] );
		foreach ( $this->state['access'] as $access => $data ) {
			if ( $access === $token || $data['refresh'] === $token ) {
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
			return $this->drive_error( 401, 'authError', 'Invalid Credentials' );
		}
		return null;
	}

	/**
	 * Drive API (not uploads).
	 *
	 * @param string $method  Method.
	 * @param string $path    Path.
	 * @param array  $query   Query.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 */
	private function drive( string $method, string $path, array $query, array $headers, string $body ): array {
		if ( '/drive/v3/about' === $path ) {
			return $this->json(
				200,
				array(
					'user' => array(
						'emailAddress' => $this->config['account'],
						'displayName'  => 'Test User',
					),
				)
			);
		}
		if ( '/drive/v3/files' === $path && 'GET' === $method ) {
			return $this->search( $query );
		}
		if ( '/drive/v3/files' === $path && 'POST' === $method ) {
			$meta = (array) json_decode( $body, true );
			$id   = $this->create( $meta, '' );
			return $this->json( 200, array( 'id' => $id ) );
		}
		if ( preg_match( '#^/drive/v3/files/([A-Za-z0-9_-]+)$#', $path, $m ) ) {
			$id = $m[1];
			if ( ! isset( $this->state['files'][ $id ] ) ) {
				return $this->drive_error( 404, 'notFound', 'File not found: ' . $id . '.' );
			}
			if ( 'GET' === $method && isset( $query['alt'] ) && 'media' === $query['alt'] ) {
				return $this->media( $id, isset( $headers['range'] ) ? (string) $headers['range'] : '' );
			}
			if ( 'GET' === $method ) {
				return $this->json( 200, $this->resource( $id ) );
			}
			if ( 'PATCH' === $method ) {
				$change = (array) json_decode( $body, true );
				if ( isset( $change['trashed'] ) ) {
					$this->state['files'][ $id ]['trashed'] = (bool) $change['trashed'];
				}
				return $this->json( 200, array( 'id' => $id ) );
			}
			if ( 'DELETE' === $method ) {
				unset( $this->state['files'][ $id ] );
				if ( is_file( $this->dir . '/blobs/' . $id ) ) {
					unlink( $this->dir . '/blobs/' . $id );
				}
				return array( 'status' => 204, 'headers' => array(), 'body' => '' );
			}
		}
		return $this->drive_error( 400, 'badRequest', 'Not supported by the fake.' );
	}

	/**
	 * files.list with the q forms SiteFerry uses.
	 *
	 * @param array $query Query.
	 */
	private function search( array $query ): array {
		$clauses = preg_split( '/\s+and\s+/', isset( $query['q'] ) ? (string) $query['q'] : '' );
		$tests   = array();
		$value   = "'((?:[^'\\\\]|\\\\.)*)'";
		foreach ( $clauses as $clause ) {
			$clause = trim( $clause );
			if ( preg_match( '/^' . $value . ' in parents$/', $clause, $m ) ) {
				$parent  = stripslashes( $m[1] );
				$tests[] = static function ( array $f ) use ( $parent ): bool {
					return in_array( $parent, $f['parents'], true );
				};
			} elseif ( preg_match( '/^trashed = (true|false)$/', $clause, $m ) ) {
				$want    = 'true' === $m[1];
				$tests[] = static function ( array $f ) use ( $want ): bool {
					return $f['trashed'] === $want;
				};
			} elseif ( preg_match( '/^(name|mimeType) (=|!=) ' . $value . '$/', $clause, $m ) ) {
				list( , $field, $op, $raw ) = $m;
				$wanted                    = stripslashes( $raw );
				$tests[]                   = static function ( array $f ) use ( $field, $op, $wanted ): bool {
					return '=' === $op ? $f[ $field ] === $wanted : $f[ $field ] !== $wanted;
				};
			} elseif ( '' !== $clause ) {
				return $this->drive_error( 400, 'invalid', 'Invalid Value: ' . $clause );
			}
		}
		$matches = array();
		foreach ( $this->state['files'] as $id => $file ) {
			$ok = true;
			foreach ( $tests as $test ) {
				$ok = $ok && $test( $file );
			}
			if ( $ok ) {
				$matches[] = $this->resource( $id );
			}
		}
		if ( isset( $query['orderBy'] ) && 'modifiedTime desc' === $query['orderBy'] ) {
			usort(
				$matches,
				static function ( array $a, array $b ): int {
					return strcmp( $b['modifiedTime'], $a['modifiedTime'] ) ?: strcmp( $b['id'], $a['id'] );
				}
			);
		}
		$size   = min( (int) ( isset( $query['pageSize'] ) ? $query['pageSize'] : 100 ), (int) $this->config['page_size'] );
		$offset = isset( $query['pageToken'] ) ? (int) $query['pageToken'] : 0;
		$page   = array_slice( $matches, $offset, $size );
		$answer = array( 'files' => $page );
		if ( $offset + $size < count( $matches ) ) {
			$answer['nextPageToken'] = (string) ( $offset + $size );
		}
		return $this->json( 200, $answer );
	}

	/**
	 * Resumable uploads.
	 *
	 * @param string $method  Method.
	 * @param array  $query   Query.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 */
	private function upload( string $method, array $query, array $headers, string $body ): array {
		if ( 'POST' === $method && isset( $query['uploadType'] ) && 'resumable' === $query['uploadType'] && ! isset( $query['upload_id'] ) ) {
			$id                                = bin2hex( random_bytes( 10 ) );
			$this->state['sessions'][ $id ] = array(
				'meta'     => (array) json_decode( $body, true ),
				'total'    => (int) ( isset( $headers['x-upload-content-length'] ) ? $headers['x-upload-content-length'] : -1 ),
				'received' => 0,
				'file'     => '',
			);
			touch( $this->dir . '/sessions/' . $id );
			$base = '' !== $this->config['session_base'] ? $this->config['session_base'] : $this->config['base'];
			return array( 'status' => 200, 'headers' => array( 'location' => $base . '/upload/drive/v3/files?uploadType=resumable&upload_id=' . $id ), 'body' => '' );
		}
		$id = isset( $query['upload_id'] ) ? (string) $query['upload_id'] : '';
		if ( ! isset( $this->state['sessions'][ $id ] ) ) {
			return $this->drive_error( 404, 'notFound', 'Upload session not found.' );
		}
		if ( 'DELETE' === $method ) {
			unset( $this->state['sessions'][ $id ] );
			@unlink( $this->dir . '/sessions/' . $id );
			return array( 'status' => 499, 'headers' => array(), 'body' => '' );
		}
		if ( 'PUT' === $method ) {
			return $this->chunk( $id, $headers, $body, false );
		}
		return $this->drive_error( 400, 'badRequest', 'Not supported by the fake.' );
	}

	/**
	 * One resumable PUT: a status query ("bytes * /total") or a chunk.
	 *
	 * @param string $id      Session.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @param bool   $partial Store only half of the chunk (fault injection).
	 */
	private function chunk( string $id, array $headers, string $body, bool $partial ): array {
		$session = &$this->state['sessions'][ $id ];
		$range   = isset( $headers['content-range'] ) ? (string) $headers['content-range'] : '';
		if ( '' !== $session['file'] ) {
			return $this->json( 200, $this->resource( $session['file'] ) );
		}
		if ( preg_match( '#^bytes \*/(\d+)$#', $range ) ) {
			return $this->incomplete( $session );
		}
		if ( ! preg_match( '#^bytes (\d+)-(\d+)/(\d+)$#', $range, $m ) ) {
			return $this->drive_error( 400, 'badContentRange', 'Invalid Content-Range.' );
		}
		list( , $start, $end, $total ) = array_map( 'intval', $m );
		if ( $start !== $session['received'] || $end - $start + 1 !== strlen( $body ) || $total !== $session['total'] ) {
			return $this->drive_error( 400, 'badContentRange', sprintf( 'The chunk does not continue the upload (have %d, got %d-%d).', $session['received'], $start, $end ) );
		}
		if ( $end + 1 < $total && 0 !== strlen( $body ) % self::CHUNK ) {
			return $this->drive_error( 400, 'badContentRange', 'Chunks must be multiples of 256 KiB.' );
		}
		if ( $partial ) {
			$body = substr( $body, 0, (int) ( strlen( $body ) / 2 ) );
		}
		file_put_contents( $this->dir . '/sessions/' . $id, $body, FILE_APPEND );
		$session['received'] += strlen( $body );
		if ( $session['received'] < $total ) {
			return $this->incomplete( $session );
		}
		$file            = $this->create( $session['meta'], $this->dir . '/sessions/' . $id );
		$session['file'] = $file;
		return $this->json( 200, $this->resource( $file ) );
	}

	/**
	 * A 308 answer with the received range.
	 *
	 * @param array $session Session.
	 */
	private function incomplete( array $session ): array {
		return array(
			'status'  => 308,
			'headers' => $session['received'] > 0 ? array( 'range' => 'bytes=0-' . ( $session['received'] - 1 ) ) : array(),
			'body'    => '',
		);
	}

	/**
	 * Creates a file or folder.
	 *
	 * @param array  $meta   Metadata.
	 * @param string $source File with the content ('' for none).
	 */
	private function create( array $meta, string $source ): string {
		$id                             = 'f' . bin2hex( random_bytes( 10 ) );
		$this->state['files'][ $id ] = array(
			'name'     => (string) $meta['name'],
			'mimeType' => isset( $meta['mimeType'] ) ? (string) $meta['mimeType'] : 'application/octet-stream',
			'parents'  => isset( $meta['parents'] ) ? array_values( (array) $meta['parents'] ) : array( 'root' ),
			'trashed'  => false,
			'modified' => gmdate( 'Y-m-d\TH:i:s' ) . sprintf( '.%03dZ', count( $this->state['files'] ) % 1000 ),
			'size'     => 0,
		);
		if ( '' !== $source ) {
			rename( $source, $this->dir . '/blobs/' . $id );
			$this->state['files'][ $id ]['size'] = (int) filesize( $this->dir . '/blobs/' . $id );
		}
		return $id;
	}

	/**
	 * A file's metadata as the API returns it.
	 *
	 * @param string $id File id.
	 */
	private function resource( string $id ): array {
		$file = $this->state['files'][ $id ];
		return array(
			'id'           => $id,
			'name'         => $file['name'],
			'mimeType'     => $file['mimeType'],
			'trashed'      => $file['trashed'],
			'size'         => (string) $file['size'],
			'modifiedTime' => $file['modified'],
		);
	}

	/**
	 * File content with an optional range.
	 *
	 * @param string $id    File id.
	 * @param string $range Range header.
	 */
	private function media( string $id, string $range ): array {
		$file = $this->dir . '/blobs/' . $id;
		$size = (int) filesize( $file );
		if ( preg_match( '/^bytes=(\d+)-(\d+)$/', $range, $m ) ) {
			$start = (int) $m[1];
			$end   = min( (int) $m[2], $size - 1 );
			if ( $start >= $size ) {
				return $this->drive_error( 416, 'requestedRangeNotSatisfiable', 'Range not satisfiable.' );
			}
			$handle = fopen( $file, 'rb' );
			fseek( $handle, $start );
			$body = (string) fread( $handle, $end - $start + 1 );
			fclose( $handle );
			return array( 'status' => 206, 'headers' => array( 'content-range' => sprintf( 'bytes %d-%d/%d', $start, $end, $size ) ), 'body' => $body );
		}
		return array( 'status' => 200, 'headers' => array(), 'body' => (string) file_get_contents( $file ) );
	}

	/**
	 * A Drive error document.
	 *
	 * @param int    $status  Status.
	 * @param string $reason  Reason.
	 * @param string $message Message.
	 */
	private function drive_error( int $status, string $reason, string $message ): array {
		return $this->json(
			$status,
			array(
				'error' => array(
					'code'    => $status,
					'message' => $message,
					'errors'  => array(
						array(
							'reason'  => $reason,
							'message' => $message,
						),
					),
				),
			)
		);
	}

	/**
	 * A JSON answer.
	 *
	 * @param int   $status Status.
	 * @param array $data   Data.
	 */
	private function json( int $status, array $data ): array {
		return array(
			'status'  => $status,
			'headers' => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'    => (string) json_encode( $data, JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * Loads the state.
	 */
	private function load(): void {
		$file        = $this->dir . '/state.json';
		$data        = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		$this->state = ( is_array( $data ) ? $data : array() ) + array(
			'codes'       => array(),
			'refresh'     => array(),
			'access'      => array(),
			'files'       => array(),
			'sessions'    => array(),
			'token_calls' => array(),
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

	/**
	 * Upload sessions that have not completed.
	 */
	public function open_sessions(): int {
		$this->load();
		return count(
			array_filter(
				$this->state['sessions'],
				static function ( array $session ): bool {
					return '' === $session['file'];
				}
			)
		);
	}

	/**
	 * Refresh tokens Google has handed out and not revoked.
	 *
	 * @return string[]
	 */
	public function refresh_tokens(): array {
		$this->load();
		return array_keys( $this->state['refresh'] );
	}
}

/**
 * Transport that hands requests to a Fake_Google in the same process.
 */
final class Fake_Google_Transport implements Transport {

	/**
	 * The fake.
	 *
	 * @var Fake_Google
	 */
	public $google;

	/**
	 * Requests to fail with a network error.
	 *
	 * @var int
	 */
	public $drop_next = 0;

	/**
	 * Requests sent: method, URL, headers.
	 *
	 * @var array
	 */
	public $sent = array();

	/**
	 * Constructor.
	 *
	 * @param Fake_Google $google The fake.
	 */
	public function __construct( Fake_Google $google ) {
		$this->google = $google;
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
		);
		if ( $this->drop_next > 0 ) {
			--$this->drop_next;
			throw new Storage_Exception( 'Network error: simulated timeout', Storage_Exception::TRANSIENT );
		}
		$lower = array();
		foreach ( $headers as $name => $value ) {
			$lower[ strtolower( $name ) ] = $value;
		}
		return $this->google->handle( $method, $url, $lower, $body );
	}
}
