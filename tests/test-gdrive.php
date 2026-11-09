<?php
/**
 * Google Drive driver against an in-process fake Google (tests/lib/class-fake-google.php):
 * OAuth with PKCE, token refresh, folders, resumable uploads, listing, ranged downloads, trash.
 *
 * Run: php tests/test-gdrive.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/class-fake-google.php';

use SiteFerry\Storage\Gdrive_Driver;
use SiteFerry\Storage\Oauth_State;
use SiteFerry\Storage\Storage_Exception;

echo "Google Drive driver tests\n";

const SF_G_BASE     = 'https://fake.google.test';
const SF_G_CLIENT   = '123456-test.apps.googleusercontent.com';
const SF_G_SECRET   = 'GOCSPX-test-secret-0123456789';
const SF_G_REDIRECT = 'http://localhost:10009/wp-admin/admin.php?page=siteferry&oauth=gdrive';

/**
 * A fake Google and a driver for it (not connected yet).
 *
 * @param array         $fake     Fake config overrides.
 * @param array         $settings Settings overrides (before normalize()).
 * @param array|null    $delays   Receives retry delays.
 * @param callable|null $clock    Clock.
 * @return array{0:Gdrive_Driver,1:Fake_Google_Transport,2:array}
 */
function sf_gdrive( array $fake = array(), array $settings = array(), ?array &$delays = null, ?callable $clock = null ): array {
	$google    = new Fake_Google(
		Sf_Test::temp_dir( 'google' ),
		$fake + array(
			'base'          => SF_G_BASE,
			'client_id'     => SF_G_CLIENT,
			'client_secret' => SF_G_SECRET,
		)
	);
	$transport = new Fake_Google_Transport( $google );
	$normal    = Gdrive_Driver::normalize( $settings + array( 'mode' => 'own', 'client_id' => SF_G_CLIENT ) );
	$delays    = array();
	$sleep     = static function ( float $seconds ) use ( &$delays ) {
		$delays[] = $seconds;
	};
	$endpoints = array(
		'auth'   => SF_G_BASE . '/o/oauth2/v2/auth',
		'token'  => SF_G_BASE . '/token',
		'revoke' => SF_G_BASE . '/revoke',
		'api'    => SF_G_BASE . '/drive/v3',
		'upload' => SF_G_BASE . '/upload/drive/v3',
	);
	$driver    = new Gdrive_Driver( $normal['settings'], isset( $settings['client_secret'] ) ? $settings['client_secret'] : SF_G_SECRET, $transport, $endpoints, $sleep, $clock );
	return array( $driver, $transport, $endpoints );
}

/**
 * Goes through the consent page like a user clicking "Allow" and connects the driver.
 *
 * @param Gdrive_Driver         $driver    Driver.
 * @param Fake_Google_Transport $transport Transport.
 * @param array|null            $saved     Receives what the driver saves.
 * @param string                $decision  allow or deny.
 * @return array The query of the redirect back.
 */
function sf_consent( Gdrive_Driver $driver, Fake_Google_Transport $transport, ?array &$saved = null, string $decision = 'allow' ): array {
	$verifier  = Oauth_State::base64url( random_bytes( 48 ) );
	$challenge = Oauth_State::base64url( hash( 'sha256', $verifier, true ) );
	$state     = bin2hex( random_bytes( 24 ) );
	$url       = $driver->authorization_url( SF_G_REDIRECT, $state, $challenge );
	$page      = $transport->google->handle( 'GET', $url, array(), '' );
	Sf_Test::same( 200, $page['status'], 'consent page:' );
	$answer = $transport->google->handle( 'GET', $url . '&decision=' . $decision, array(), '' );
	Sf_Test::same( 302, $answer['status'] );
	$query = array();
	parse_str( (string) parse_url( $answer['headers']['location'], PHP_URL_QUERY ), $query );
	Sf_Test::same( $state, $query['state'], 'state returned unchanged:' );
	if ( 'allow' === $decision ) {
		$saved = array();
		$driver->use_auth(
			array(),
			static function ( array $auth ) use ( &$saved ) {
				$saved = $auth;
			}
		);
		$auth  = $driver->connect( $query['code'], $verifier, SF_G_REDIRECT );
		$saved = $auth;
	}
	$query['verifier'] = $verifier;
	return $query;
}

/**
 * Asserts a Storage_Exception.
 *
 * @param int      $code     Expected code.
 * @param callable $callback Code that must throw.
 * @param string   $contains Text the message must contain.
 */
function sf_expect_storage_error( int $code, callable $callback, string $contains = '' ): Storage_Exception {
	try {
		$callback();
	} catch ( Storage_Exception $e ) {
		Sf_Test::same( $code, $e->getCode(), 'error code for "' . $e->getMessage() . '":' );
		Sf_Test::ok( '' === $contains || false !== strpos( $e->getMessage(), $contains ), 'message should contain "' . $contains . '": ' . $e->getMessage() );
		return $e;
	}
	throw new RuntimeException( 'expected a Storage_Exception' );
}

/**
 * Sends data in parts of $part bytes.
 *
 * @param Gdrive_Driver $driver Driver.
 * @param string        $name   Name.
 * @param string        $data   Data.
 * @param int           $part   Part size.
 */
function sf_gsend( Gdrive_Driver $driver, string $name, string $data, int $part = 524288 ): array {
	$state              = $driver->begin( $name, strlen( $data ) );
	$state['part_size'] = $part;
	for ( $i = 0; $i * $part < strlen( $data ); $i++ ) {
		$state = $driver->put_part( $state, $i, substr( $data, $i * $part, $part ) );
	}
	$driver->finish( $state );
	return $state;
}

Sf_Test::run(
	'settings: one-click mode needs only a folder; own-client mode a client ID and secret',
	static function () {
		$relay = Gdrive_Driver::normalize( array( 'folder' => ' Backups ' ) );
		Sf_Test::same( 'relay', $relay['settings']['mode'], 'one-click sign-in by default:' );
		Sf_Test::same( 'Backups', $relay['settings']['folder'] );
		Sf_Test::same( array(), $relay['secrets'], 'no secret on the site in relay mode:' );
		Sf_Test::same( 'relay', Gdrive_Driver::client_key( $relay['settings'] ) );

		$ok = Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => ' 12-ab.apps.googleusercontent.com ', 'client_secret' => 'GOCSPX-x', 'folder' => ' My Backups ' ) );
		Sf_Test::same( '12-ab.apps.googleusercontent.com', $ok['settings']['client_id'] );
		Sf_Test::same( 'My Backups', $ok['settings']['folder'] );
		Sf_Test::same( 'GOCSPX-x', $ok['secrets']['client_secret'] );
		Sf_Test::same( '12-ab.apps.googleusercontent.com', Gdrive_Driver::client_key( $ok['settings'] ) );
		Sf_Test::same( '12-ab.apps.googleusercontent.com', Gdrive_Driver::client_key( array( 'client_id' => '12-ab.apps.googleusercontent.com', 'folder' => 'x' ) ), 'settings saved before modes existed count as own-client:' );
		Sf_Test::same( 'SiteFerry', Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => '12-ab.apps.googleusercontent.com' ) )['settings']['folder'], 'default folder:' );
		Sf_Test::same( null, Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => '12-ab.apps.googleusercontent.com' ) )['secrets']['client_secret'], 'empty secret means "keep":' );
		foreach ( array( array( 'mode' => 'device' ), array( 'mode' => 'own', 'client_id' => '' ), array( 'mode' => 'own', 'client_id' => 'abc' ), array( 'mode' => 'own', 'client_id' => 'a b.apps.googleusercontent.com' ), array( 'folder' => 'a/b' ), array( 'mode' => 'own', 'client_id' => '12-ab.apps.googleusercontent.com', 'folder' => "a\nb" ) ) as $input ) {
			try {
				Gdrive_Driver::normalize( $input );
			} catch ( Storage_Exception $e ) {
				Sf_Test::same( Storage_Exception::CONFIG, $e->getCode() );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . json_encode( $input ) );
		}
	}
);

Sf_Test::run(
	'consent URL asks only for drive.file, offline access and PKCE S256',
	static function () {
		list( $driver ) = sf_gdrive();
		$url            = $driver->authorization_url( SF_G_REDIRECT, 'st', 'ch' );
		$query          = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		Sf_Test::same( SF_G_BASE . '/o/oauth2/v2/auth', strtok( $url, '?' ) );
		Sf_Test::same( 'https://www.googleapis.com/auth/drive.file', $query['scope'] );
		Sf_Test::same( 'offline', $query['access_type'] );
		Sf_Test::same( 'consent', $query['prompt'] );
		Sf_Test::same( 'code', $query['response_type'] );
		Sf_Test::same( 'S256', $query['code_challenge_method'] );
		Sf_Test::same( 'ch', $query['code_challenge'] );
		Sf_Test::same( 'st', $query['state'] );
		Sf_Test::same( SF_G_REDIRECT, $query['redirect_uri'] );
		Sf_Test::ok( false === strpos( $url, SF_G_SECRET ), 'the client secret is not in the URL' );
	}
);

Sf_Test::run(
	'connecting: code exchange with PKCE; a reused code, a wrong verifier, a missing scope or refresh token fail',
	static function () {
		list( $driver, $transport ) = sf_gdrive();
		$query                      = sf_consent( $driver, $transport, $saved );
		Sf_Test::ok( $driver->connected(), 'connected' );
		Sf_Test::ok( 0 === strpos( $saved['refresh_token'], 'refresh-' ) && 0 === strpos( $saved['access_token'], 'access-' ), 'tokens kept' );
		Sf_Test::same( 'tester@example.com', $saved['account'] );
		Sf_Test::same( 'Google Drive of tester@example.com, folder "SiteFerry"', $driver->describe() );
		foreach ( $transport->sent as $request ) {
			Sf_Test::ok( false === strpos( $request['url'], SF_G_SECRET ), 'the client secret is never in a URL' );
		}

		list( $again, $t2 ) = sf_gdrive();
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $again, $query ) { $again->connect( $query['code'], $query['verifier'], SF_G_REDIRECT ); }, 'invalid_grant' );

		$other = sf_consent( $again, $t2, $ignored, 'deny' );
		Sf_Test::same( 'access_denied', $other['error'], 'deny:' );
		list( $pkce, $t3 ) = sf_gdrive();
		$verifier          = Oauth_State::base64url( random_bytes( 48 ) );
		$url               = $pkce->authorization_url( SF_G_REDIRECT, 's', Oauth_State::base64url( hash( 'sha256', $verifier, true ) ) );
		$answer            = $t3->google->handle( 'GET', $url . '&decision=allow', array(), '' );
		parse_str( (string) parse_url( $answer['headers']['location'], PHP_URL_QUERY ), $back );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $pkce, $back ) { $pkce->connect( $back['code'], 'not-the-verifier-not-the-verifier-not-the-verifier', SF_G_REDIRECT ); }, 'invalid_grant' );

		list( $scope, $t4 ) = sf_gdrive( array( 'grant_scope' => 'openid' ) );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $scope, $t4 ) { sf_consent( $scope, $t4 ); }, 'was not granted' );

		list( $none, $t5 ) = sf_gdrive( array( 'no_refresh' => true ) );
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $none, $t5 ) { sf_consent( $none, $t5 ); }, 'did not send a refresh token' );

		list( $wrong, $t6 ) = sf_gdrive( array(), array( 'client_secret' => 'wrong' ) );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $wrong, $t6 ) { sf_consent( $wrong, $t6 ); }, 'invalid_client' );
	}
);

Sf_Test::run(
	'tokens: refreshed before they expire and after a refusal; new tokens are saved; a revoked grant asks to reconnect',
	static function () {
		$now                        = time();
		$clock                      = static function () use ( &$now ) {
			return $now;
		};
		list( $driver, $transport ) = sf_gdrive( array(), array(), $delays, $clock );
		sf_consent( $driver, $transport, $saved );
		$first = $saved['access_token'];
		$driver->list();
		Sf_Test::same( 0, $transport->google->token_calls( 'refresh_token' ), 'a fresh token is used as it is:' );

		$now += 3500; // Less than five minutes left.
		$driver->list();
		Sf_Test::same( 1, $transport->google->token_calls( 'refresh_token' ), 'refreshed before expiry:' );
		Sf_Test::ok( $saved['access_token'] !== $first, 'the new token was handed to the saver' );

		$transport->google->expire_access_tokens();
		Sf_Test::same( array(), $driver->list(), 'listing after the token was refused:' );
		Sf_Test::same( 2, $transport->google->token_calls( 'refresh_token' ), 'refreshed after a 401:' );

		$transport->google->revoke_all();
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $driver ) { $driver->list(); }, 'Connect Google Drive again' );

		list( $unconnected ) = sf_gdrive();
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $unconnected ) { $unconnected->list(); }, 'not connected yet' );
	}
);

Sf_Test::run(
	'folder: created once, found again, re-created after it was trashed or renamed',
	static function () {
		list( $driver, $transport ) = sf_gdrive();
		sf_consent( $driver, $transport, $saved );
		Sf_Test::same( array(), $driver->list(), 'no folder yet, nothing listed:' );
		Sf_Test::same( 0, count( $transport->google->folders() ), 'listing does not create the folder:' );
		sf_gsend( $driver, 'a-2026-01-01-000000000000.tar', 'abc' );
		$folders = $transport->google->folders();
		Sf_Test::same( 1, count( $folders ) );
		Sf_Test::same( 'SiteFerry', reset( $folders )['name'] );
		Sf_Test::same( array( 'root' ), reset( $folders )['parents'], 'at the top of My Drive:' );
		Sf_Test::same( key( $folders ), $saved['folder_id'], 'folder id saved:' );

		// A new driver (next request) with the saved record uses the same folder.
		list( , , $endpoints ) = sf_gdrive();
		$next                  = new Gdrive_Driver( Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => SF_G_CLIENT ) )['settings'], SF_G_SECRET, $transport, $endpoints, static function () {} );
		$next->use_auth( $saved, null );
		sf_gsend( $next, 'b-2026-01-01-000000000000.tar', 'def' );
		Sf_Test::same( 1, count( $transport->google->folders() ), 'no second folder:' );

		$transport->google->trash( key( $folders ) );
		$after = new Gdrive_Driver( Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => SF_G_CLIENT ) )['settings'], SF_G_SECRET, $transport, $endpoints, static function () {} );
		$after->use_auth( $saved, null );
		sf_gsend( $after, 'c-2026-01-01-000000000000.tar', 'ghi' );
		Sf_Test::same( 2, count( $transport->google->folders() ), 'a new folder after the old one was trashed:' );

		$renamed = new Gdrive_Driver( Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => SF_G_CLIENT, 'folder' => 'Other' ) )['settings'], SF_G_SECRET, $transport, $endpoints, static function () {} );
		$renamed->use_auth( $saved, null );
		Sf_Test::same( array(), $renamed->list(), 'another folder name starts empty:' );
	}
);

Sf_Test::run(
	'resumable upload in parts (one repeated, one interrupted halfway), listing, ranged download, trash',
	static function () {
		list( $driver, $transport ) = sf_gdrive( array( 'page_size' => 2 ), array(), $delays );
		sf_consent( $driver, $transport, $saved );
		$name = 'mysite-2026-11-04-0123456789ab.tar';
		$part = 2 * Fake_Google::CHUNK;
		$data = random_bytes( 3 * $part + 1000 );

		$state              = $driver->begin( $name, strlen( $data ) );
		$state['part_size'] = $part;
		$state              = json_decode( json_encode( $state ), true );
		$state              = $driver->put_part( $state, 0, substr( $data, 0, $part ) );
		$state              = $driver->put_part( $state, 1, substr( $data, $part, $part ) );
		$state              = $driver->put_part( $state, 1, substr( $data, $part, $part ) ); // Repeated after an interruption.
		$transport->google->fail_next = array( 'partial' );
		$state                        = $driver->put_part( $state, 2, substr( $data, 2 * $part, $part ) );
		Sf_Test::same( 1, count( $delays ), 'one retry for the half-stored part:' );
		$state = $driver->put_part( $state, 3, substr( $data, 3 * $part ) );
		Sf_Test::ok( '' !== $state['file_id'], 'the last part returned the file' );
		$driver->finish( $state );
		Sf_Test::same( hash( 'sha256', $data ), $transport->google->hash( $name ), 'stored bytes:' );
		Sf_Test::same( 0, count( array_filter( $transport->sent, static function ( $r ) { return false !== strpos( $r['url'], 'upload_id' ) && 'PUT' !== $r['method']; } ) ), 'no other requests to the session' );

		// Sending the same backup again replaces the first copy.
		sf_gsend( $driver, $name, $data, $part );
		Sf_Test::same( 1, count( array_filter( $transport->google->files(), static function ( $f ) use ( $name ) { return $f['name'] === $name; } ) ), 'one copy left:' );

		foreach ( array( 'b-2026-01-01-000000000000.tar', 'c-2026-01-01-000000000000.tar.gz', 'notes.txt' ) as $other ) {
			sf_gsend( $driver, $other, 'x' );
		}
		$listed = array_column( $driver->list(), 'name' );
		sort( $listed );
		Sf_Test::same( array( 'b-2026-01-01-000000000000.tar', 'c-2026-01-01-000000000000.tar.gz', $name ), $listed, 'backups over several pages, other files left out:' );

		$back = '';
		for ( $offset = 0; $offset < strlen( $data ); $offset += 400000 ) {
			$back .= $driver->download_range( $name, $offset, (int) min( 400000, strlen( $data ) - $offset ) );
		}
		Sf_Test::same( hash( 'sha256', $data ), hash( 'sha256', $back ), 'downloaded in ranges:' );

		$driver->delete( $name );
		Sf_Test::ok( ! in_array( $name, array_column( $driver->list(), 'name' ), true ), 'deleted backups are not listed' );
		$trashed = array_filter( $transport->google->files( true ), static function ( $f ) use ( $name ) { return $f['name'] === $name; } );
		Sf_Test::ok( array() !== $trashed && reset( $trashed )['trashed'], 'it is in the Drive trash' );
		sf_expect_storage_error( Storage_Exception::NOT_FOUND, static function () use ( $driver, $name ) { $driver->download_range( $name, 0, 10 ); }, 'not in the Google Drive folder' );

		$before = $transport->google->sessions();
		$open   = $driver->begin( 'open-2026-01-01-000000000000.tar', 10 );
		Sf_Test::same( $before + 1, $transport->google->sessions(), 'one more session open:' );
		$driver->abort( $open );
		Sf_Test::same( $before, $transport->google->sessions(), 'aborted session removed:' );
	}
);

Sf_Test::run(
	'errors: rate limits and server errors are retried, a full Drive and a foreign upload host are not',
	static function () {
		list( $driver, $transport ) = sf_gdrive( array(), array(), $delays );
		sf_consent( $driver, $transport, $saved );
		$transport->google->fail_next = array( array( 'status' => 403, 'reason' => 'userRateLimitExceeded' ), 500 );
		$transport->drop_next         = 0;
		Sf_Test::same( array(), $driver->list() );
		Sf_Test::same( 2, count( $delays ), 'two retries:' );

		$transport->google->fail_next = array( array( 'status' => 403, 'reason' => 'storageQuotaExceeded' ) );
		$before                       = count( $delays );
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $driver ) { $driver->begin( 'x-2026-01-01-000000000000.tar', 5 ); }, 'The Google Drive is full' );
		Sf_Test::same( $before, count( $delays ), 'no retry for a full Drive:' );

		list( $evil, $t2 ) = sf_gdrive( array( 'session_base' => 'https://attacker.example' ) );
		sf_consent( $evil, $t2 );
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $evil ) { $evil->begin( 'x-2026-01-01-000000000000.tar', 5 ); }, 'Refusing to send the Google token to https://attacker.example' );
	}
);

Sf_Test::run(
	'connection test: six checks, no file left behind, no retries; disconnect revokes',
	static function () {
		list( $driver, $transport ) = sf_gdrive( array(), array(), $delays );
		sf_consent( $driver, $transport, $saved );
		$lines = $driver->test();
		Sf_Test::same( 6, count( $lines ), implode( ' | ', $lines ) );
		Sf_Test::ok( false !== strpos( $lines[0], 'tester@example.com' ), $lines[0] );
		Sf_Test::same( array(), $transport->google->files( true ), 'test file deleted for good:' );

		$transport->google->fail_next = array( 503 );
		sf_expect_storage_error( Storage_Exception::TRANSIENT, static function () use ( $driver ) { $driver->test(); } );
		Sf_Test::same( array(), $delays, 'no retry during test():' );

		$driver->disconnect();
		Sf_Test::ok( ! $driver->connected(), 'tokens forgotten' );
		Sf_Test::same( 'POST /revoke', end( $transport->google->log ) );
		Sf_Test::same( array(), $transport->google->refresh_tokens(), 'revoked at Google:' );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $driver ) { $driver->list(); }, 'not connected yet' );
	}
);

require_once dirname( __DIR__ ) . '/relay/src/class-relay.php';

/**
 * Sends relay addresses to an in-process relay and everything else to the fake Google.
 */
final class Sf_Relay_Routing_Transport implements SiteFerry\Storage\Transport {

	/**
	 * Relay base URL.
	 *
	 * @var string
	 */
	public $base;

	/**
	 * The relay (null: unreachable).
	 *
	 * @var SiteFerry_Relay\Relay|null
	 */
	public $relay;

	/**
	 * Fake Google transport.
	 *
	 * @var Fake_Google_Transport
	 */
	public $google;

	/**
	 * Requests sent: method, URL, body.
	 *
	 * @var array
	 */
	public $sent = array();

	/**
	 * Constructor.
	 *
	 * @param string                     $base   Relay base URL.
	 * @param SiteFerry_Relay\Relay|null $relay  Relay.
	 * @param Fake_Google_Transport      $google Google.
	 */
	public function __construct( string $base, ?SiteFerry_Relay\Relay $relay, Fake_Google_Transport $google ) {
		$this->base   = $base;
		$this->relay  = $relay;
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
	 * @throws Storage_Exception When the relay is unreachable.
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 60 ): array {
		$this->sent[] = array(
			'method' => $method,
			'url'    => $url,
			'body'   => $body,
		);
		if ( 0 !== strpos( $url, $this->base ) ) {
			return $this->google->request( $method, $url, $headers, $body, $timeout );
		}
		if ( null === $this->relay ) {
			throw new Storage_Exception( 'Network error: cURL error 7: Failed to connect', Storage_Exception::TRANSIENT );
		}
		$query = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$answer = $this->relay->handle( $method, (string) parse_url( $url, PHP_URL_PATH ), $query, $body );
		return array(
			'status'  => $answer['status'],
			'headers' => array_change_key_case( $answer['headers'], CASE_LOWER ),
			'body'    => $answer['body'],
		);
	}
}

Sf_Test::run(
	'one-click sign-in through the relay: consent, code exchange and renewals go through it; the site holds no secret',
	static function () {
		list( , $google_transport, $endpoints ) = sf_gdrive();
		$google                                 = $google_transport->google;
		$base                                   = 'https://relay.test/siteferry';
		$relay                                  = new SiteFerry_Relay\Relay(
			array(
				'base_url'             => $base,
				'signing_key'          => str_repeat( 's', 40 ),
				'google_client_id'     => SF_G_CLIENT,
				'google_client_secret' => SF_G_SECRET,
				'google_auth_url'      => SF_G_BASE . '/o/oauth2/v2/auth',
				'google_token_url'     => SF_G_BASE . '/token',
			),
			static function ( string $url, array $fields ) use ( $google ) {
				return $google->handle( 'POST', $url, array(), http_build_query( $fields ) );
			}
		);
		$transport = new Sf_Relay_Routing_Transport( $base, $relay, $google_transport );
		$driver    = new Gdrive_Driver( Gdrive_Driver::normalize( array( 'folder' => 'SiteFerry' ) )['settings'], '', $transport, array( 'relay' => $base ) + $endpoints, static function () {} );

		$verifier  = Oauth_State::base64url( random_bytes( 48 ) );
		$challenge = Oauth_State::base64url( hash( 'sha256', $verifier, true ) );
		$state     = bin2hex( random_bytes( 24 ) );
		$start     = $driver->authorization_url( SF_G_REDIRECT, $state, $challenge );
		Sf_Test::ok( 0 === strpos( $start, $base . '/google/start?' ), 'the browser goes to the relay first: ' . $start );

		$to_google = $transport->request( 'GET', $start, array() );
		$consent   = $to_google['headers']['location'];
		parse_str( (string) parse_url( $consent, PHP_URL_QUERY ), $asked );
		Sf_Test::same( SF_G_CLIENT, $asked['client_id'], 'Google sees the relay\'s client:' );
		Sf_Test::same( $base . '/google/callback', $asked['redirect_uri'] );
		$to_relay = $google->handle( 'GET', $consent . '&decision=allow', array(), '' );
		$to_site  = $transport->request( 'GET', $to_relay['headers']['location'], array() );
		$back     = array();
		parse_str( (string) parse_url( $to_site['headers']['location'], PHP_URL_QUERY ), $back );
		Sf_Test::same( 0, strpos( $to_site['headers']['location'], SF_G_REDIRECT ), 'back on the site page:' );
		Sf_Test::same( $state, $back['state'], 'the site gets its own state back:' );

		$driver->use_auth(
			array(),
			static function ( array $auth ) use ( &$saved ) {
				$saved = $auth;
			}
		);
		$auth = $driver->connect( $back['code'], $verifier, SF_G_REDIRECT );
		Sf_Test::ok( $driver->connected() && '' !== $auth['refresh_token'], 'connected' );
		Sf_Test::same( 'tester@example.com', $auth['account'] );
		sf_gsend( $driver, 'r-2026-01-01-000000000000.tar', 'relay mode works' );

		$google->expire_access_tokens();
		Sf_Test::same( array( 'r-2026-01-01-000000000000.tar' ), array_column( $driver->list(), 'name' ), 'listing after a renewal:' );
		$token_posts = array_filter(
			$transport->sent,
			static function ( array $r ) use ( $base ) {
				return 'POST' === $r['method'] && $base . '/google/token' === $r['url'];
			}
		);
		Sf_Test::same( 2, count( $token_posts ), 'the code exchange and the renewal both went to the relay:' );
		foreach ( $transport->sent as $request ) {
			Sf_Test::ok( false === strpos( $request['body'] . $request['url'], SF_G_SECRET ), 'the site never sends (or knows) the secret' );
			Sf_Test::ok( SF_G_BASE . '/token' !== $request['url'], 'the site never calls Google\'s token endpoint itself' );
		}

		$down = new Sf_Relay_Routing_Transport( $base, null, $google_transport );
		$cut  = new Gdrive_Driver( Gdrive_Driver::normalize( array() )['settings'], '', $down, array( 'relay' => $base ) + $endpoints, static function () {} );
		sf_expect_storage_error( Storage_Exception::TRANSIENT, static function () use ( $cut, $back, $verifier ) { $cut->connect( $back['code'], $verifier, SF_G_REDIRECT ); }, 'The sign-in service relay.test cannot be reached' );
	}
);

Sf_Test::run(
	'a folder picked in Google Drive: the backup folder is made inside it and found again; back to the top of My Drive',
	static function () {
		$picked                                = array(
			'parent_id'   => 'fPickedClientsFolder01',
			'parent_path' => 'Clients',
			'folder'      => 'xsofty.com backups',
		);
		list( $driver, $transport, $endpoints ) = sf_gdrive( array(), $picked );
		sf_consent( $driver, $transport, $saved );
		Sf_Test::ok( false !== strpos( $driver->describe(), 'folder "Clients / xsofty.com backups"' ), 'described with its place: ' . $driver->describe() );
		sf_gsend( $driver, 'a-2026-01-01-000000000000.tar', 'abc' );
		$folders = $transport->google->folders();
		Sf_Test::same( 1, count( $folders ) );
		Sf_Test::same( 'xsofty.com backups', reset( $folders )['name'] );
		Sf_Test::same( array( 'fPickedClientsFolder01' ), reset( $folders )['parents'], 'inside the picked folder:' );
		Sf_Test::same( 'fPickedClientsFolder01', $saved['folder_parent'], 'its place saved:' );

		$settings = Gdrive_Driver::normalize( $picked + array( 'mode' => 'own', 'client_id' => SF_G_CLIENT ) )['settings'];
		$next     = new Gdrive_Driver( $settings, SF_G_SECRET, $transport, $endpoints, static function () {} );
		$next->use_auth( $saved, null );
		Sf_Test::same( array( 'a-2026-01-01-000000000000.tar' ), array_column( $next->list(), 'name' ), 'found again by the next request:' );
		sf_gsend( $next, 'b-2026-01-01-000000000000.tar', 'def' );
		Sf_Test::same( 1, count( $transport->google->folders() ), 'no second folder:' );
		Sf_Test::ok( false !== strpos( implode( ' ', $next->test() ), 'inside "Clients"' ), 'the test says where' );

		// Back to the top of My Drive: a folder of that name there, not the one in "Clients".
		$top = new Gdrive_Driver( Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => SF_G_CLIENT, 'folder' => 'xsofty.com backups' ) )['settings'], SF_G_SECRET, $transport, $endpoints, static function () {} );
		$top->use_auth( $saved, null );
		Sf_Test::same( array(), $top->list(), 'the top of My Drive starts empty:' );
		sf_gsend( $top, 'c-2026-01-01-000000000000.tar', 'ghi' );
		$parents = array_column( $transport->google->folders(), 'parents' );
		Sf_Test::ok( in_array( array( 'root' ), $parents, true ) && in_array( array( 'fPickedClientsFolder01' ), $parents, true ), 'one folder in each place' );
	}
);

Sf_Test::run(
	'the picked folder\'s settings are checked: a malformed id is refused, the shown name is cleaned and cut',
	static function () {
		sf_expect_storage_error(
			Storage_Exception::CONFIG,
			static function () {
				Gdrive_Driver::normalize( array( 'parent_id' => "../../etc'" ) );
			},
			'choose it again'
		);
		$n = Gdrive_Driver::normalize(
			array(
				'parent_id'   => 'fPickedClientsFolder01',
				'parent_path' => "Cli\nents\x07" . str_repeat( 'é', 200 ),
			)
		)['settings'];
		Sf_Test::ok( 0 === strpos( $n['parent_path'], 'Cli ents' ), 'control characters become spaces: ' . substr( $n['parent_path'], 0, 12 ) );
		Sf_Test::same( 150, preg_match_all( '/./us', $n['parent_path'] ), 'cut to 150 characters, whole characters only:' );
		Sf_Test::same( '', Gdrive_Driver::normalize( array() )['settings']['parent_id'], 'none picked: the top of My Drive' );
		sf_expect_storage_error(
			Storage_Exception::CONFIG,
			static function () {
				Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => SF_G_CLIENT, 'app_id' => 'abc' ) );
			},
			'project number'
		);
		sf_expect_storage_error(
			Storage_Exception::CONFIG,
			static function () {
				Gdrive_Driver::normalize( array( 'mode' => 'own', 'client_id' => SF_G_CLIENT, 'picker_key' => 'key with spaces' ) );
			},
			'Picker API key'
		);
	}
);

Sf_Test::run(
	'the folder picker\'s details: a token, the key and the project number, from the settings or the relay',
	static function () {
		// Your own client without a Picker key: a clear message.
		list( $driver, $transport ) = sf_gdrive();
		sf_consent( $driver, $transport, $saved );
		sf_expect_storage_error(
			Storage_Exception::CONFIG,
			static function () use ( $driver ) {
				$driver->picker();
			},
			'Picker API key and project number'
		);
		// With them.
		list( $own, $own_transport ) = sf_gdrive(
			array(),
			array(
				'picker_key' => 'AIzaSyTestPickerKey_0123456789abcdef',
				'app_id'     => '340488123456',
			)
		);
		sf_consent( $own, $own_transport, $own_saved );
		$details = $own->picker();
		Sf_Test::same( array( 'AIzaSyTestPickerKey_0123456789abcdef', '340488123456' ), array( $details['api_key'], $details['app_id'] ) );
		Sf_Test::ok( '' !== $details['token'] && $details['expires'] > time(), 'a current access token' );

		// One-click sign-in: from the relay, or a message when it has no picker set up.
		list( , $google_transport, $endpoints ) = sf_gdrive();
		$google = $google_transport->google;
		$base   = 'https://relay.test/siteferry';
		$config = array(
			'base_url'             => $base,
			'signing_key'          => str_repeat( 's', 40 ),
			'google_client_id'     => SF_G_CLIENT,
			'google_client_secret' => SF_G_SECRET,
			'google_auth_url'      => SF_G_BASE . '/o/oauth2/v2/auth',
			'google_token_url'     => SF_G_BASE . '/token',
		);
		$post   = static function ( string $url, array $fields ) use ( $google ) {
			return $google->handle( 'POST', $url, array(), http_build_query( $fields ) );
		};
		foreach ( array( false, true ) as $with_picker ) {
			$relay     = new SiteFerry_Relay\Relay(
				$with_picker ? $config + array( 'google_picker_key' => 'AIzaSyRelayPickerKey_0123456789ab', 'google_app_id' => '340488999999' ) : $config,
				$post
			);
			$transport = new Sf_Relay_Routing_Transport( $base, $relay, $google_transport );
			$driver    = new Gdrive_Driver( Gdrive_Driver::normalize( array() )['settings'], '', $transport, array( 'relay' => $base ) + $endpoints, static function () {} );
			$verifier  = Oauth_State::base64url( random_bytes( 48 ) );
			$state     = bin2hex( random_bytes( 24 ) );
			$to_google = $transport->request( 'GET', $driver->authorization_url( SF_G_REDIRECT, $state, Oauth_State::base64url( hash( 'sha256', $verifier, true ) ) ), array() );
			$to_relay  = $google->handle( 'GET', $to_google['headers']['location'] . '&decision=allow', array(), '' );
			$to_site   = $transport->request( 'GET', $to_relay['headers']['location'], array() );
			$back      = array();
			parse_str( (string) parse_url( $to_site['headers']['location'], PHP_URL_QUERY ), $back );
			$driver->use_auth( array(), static function () {} );
			$driver->connect( $back['code'], $verifier, SF_G_REDIRECT );
			if ( $with_picker ) {
				$details = $driver->picker();
				Sf_Test::same( array( 'AIzaSyRelayPickerKey_0123456789ab', '340488999999' ), array( $details['api_key'], $details['app_id'] ), 'from the relay:' );
			} else {
				sf_expect_storage_error(
					Storage_Exception::CONFIG,
					static function () use ( $driver ) {
						$driver->picker();
					},
					'not set up on the sign-in service'
				);
			}
		}
	}
);

exit( Sf_Test::finish( 'test-gdrive' ) );
