<?php
/**
 * Dropbox driver against an in-process fake Dropbox (tests/lib/class-fake-dropbox.php): OAuth
 * with PKCE and no app secret, token renewal, upload sessions, listing, ranged downloads, delete,
 * and one-click sign-in through the real relay.
 *
 * Run: php tests/test-dropbox.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/class-fake-dropbox.php';
require dirname( __DIR__ ) . '/relay/src/class-relay.php';

use SiteFerry\Storage\Dropbox_Driver;
use SiteFerry\Storage\Oauth_State;
use SiteFerry\Storage\Storage_Exception;
use SiteFerry\Storage\Transport;

echo "Dropbox driver tests\n";

const SF_D_API      = 'https://api.fake-dropbox.test';
const SF_D_CONTENT  = 'https://content.fake-dropbox.test';
const SF_D_KEY      = 'abcd1234efgh567';
const SF_D_REDIRECT = 'http://localhost:10009/wp-admin/admin.php?page=siteferry&oauth=dropbox';

/**
 * A fake Dropbox and a driver for it (not connected yet).
 *
 * @param array         $fake     Fake config overrides.
 * @param array         $settings Settings overrides (before normalize()).
 * @param array|null    $delays   Receives retry delays.
 * @param callable|null $clock    Clock.
 * @return array{0:Dropbox_Driver,1:Fake_Dropbox_Transport,2:array}
 */
function sf_dropbox( array $fake = array(), array $settings = array(), ?array &$delays = null, ?callable $clock = null ): array {
	$dropbox   = new Fake_Dropbox(
		Sf_Test::temp_dir( 'dropbox' ),
		$fake + array(
			'base'    => SF_D_API,
			'content' => SF_D_CONTENT,
			'app_key' => SF_D_KEY,
		)
	);
	$transport = new Fake_Dropbox_Transport( $dropbox );
	$normal    = Dropbox_Driver::normalize( $settings + array( 'mode' => 'own', 'app_key' => SF_D_KEY ) );
	$delays    = array();
	$sleep     = static function ( float $seconds ) use ( &$delays ) {
		$delays[] = $seconds;
	};
	$endpoints = array(
		'auth'    => SF_D_API . '/oauth2/authorize',
		'token'   => SF_D_API . '/oauth2/token',
		'api'     => SF_D_API . '/2',
		'content' => SF_D_CONTENT . '/2',
	);
	return array( new Dropbox_Driver( $normal['settings'], $transport, $endpoints, $sleep, $clock ), $transport, $endpoints );
}

/**
 * Goes through the authorization page like a user clicking "Allow" and connects the driver.
 *
 * @param Dropbox_Driver         $driver    Driver.
 * @param Fake_Dropbox_Transport $transport Transport.
 * @param array|null             $saved     Receives what the driver saves.
 * @param string                 $decision  allow or deny.
 * @return array The query of the redirect back, plus the verifier.
 */
function sf_dconsent( Dropbox_Driver $driver, Fake_Dropbox_Transport $transport, ?array &$saved = null, string $decision = 'allow' ): array {
	$verifier  = Oauth_State::base64url( random_bytes( 48 ) );
	$challenge = Oauth_State::base64url( hash( 'sha256', $verifier, true ) );
	$state     = bin2hex( random_bytes( 24 ) );
	$url       = $driver->authorization_url( SF_D_REDIRECT, $state, $challenge );
	$page      = $transport->dropbox->handle( 'GET', $url, array(), '' );
	Sf_Test::same( 200, $page['status'], 'authorization page: ' . $page['body'] );
	$answer = $transport->dropbox->handle( 'GET', $url . '&decision=' . $decision, array(), '' );
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
		$saved = $driver->connect( $query['code'], $verifier, SF_D_REDIRECT );
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
function sf_dexpect( int $code, callable $callback, string $contains = '' ): Storage_Exception {
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
 * @param Dropbox_Driver $driver Driver.
 * @param string         $name   Name.
 * @param string         $data   Data.
 * @param int            $part   Part size.
 */
function sf_dsend( Dropbox_Driver $driver, string $name, string $data, int $part = 300000 ): array {
	$state              = $driver->begin( $name, strlen( $data ) );
	$state['part_size'] = $part;
	for ( $i = 0; $i * $part < strlen( $data ); $i++ ) {
		$state = $driver->put_part( $state, $i, substr( $data, $i * $part, $part ) );
	}
	$driver->finish( $state );
	return $state;
}

/**
 * A connected driver.
 *
 * @param array         $fake     Fake config overrides.
 * @param array         $settings Settings overrides.
 * @param array|null    $delays   Receives retry delays.
 * @param callable|null $clock    Clock.
 * @return array{0:Dropbox_Driver,1:Fake_Dropbox_Transport,2:array}
 */
function sf_dconnected( array $fake = array(), array $settings = array(), ?array &$delays = null, ?callable $clock = null ): array {
	$made = sf_dropbox( $fake, $settings, $delays, $clock );
	sf_dconsent( $made[0], $made[1] );
	return $made;
}

Sf_Test::run(
	'settings: one-click mode needs nothing but a folder; own-app mode an app key; never a secret',
	static function () {
		$relay = Dropbox_Driver::normalize( array() );
		Sf_Test::same( array( 'mode' => 'relay', 'app_key' => '', 'folder' => '' ), $relay['settings'], 'one-click sign-in into the app folder by default:' );
		Sf_Test::same( array(), $relay['secrets'] );
		Sf_Test::same( array(), Dropbox_Driver::secrets(), 'Dropbox needs no secret in either mode:' );
		Sf_Test::same( 'relay', Dropbox_Driver::client_key( $relay['settings'] ) );

		$own = Dropbox_Driver::normalize( array( 'mode' => 'own', 'app_key' => ' abcd1234EFGH567 ', 'folder' => ' /Sites/example.com/ ' ) );
		Sf_Test::same( 'abcd1234EFGH567', $own['settings']['app_key'] );
		Sf_Test::same( 'Sites/example.com', $own['settings']['folder'], 'slashes and spaces at the ends trimmed:' );
		Sf_Test::same( 'abcd1234EFGH567', Dropbox_Driver::client_key( $own['settings'] ) );
		Sf_Test::same( '', Dropbox_Driver::normalize( array( 'mode' => 'relay', 'app_key' => 'abcd1234efgh567' ) )['settings']['app_key'], 'an app key is ignored in one-click mode:' );
		Sf_Test::same( 'Sauvegardes é', Dropbox_Driver::normalize( array( 'folder' => 'Sauvegardes é' ) )['settings']['folder'] );

		$bad = array(
			array( 'mode' => 'device' ),
			array( 'mode' => 'own' ),
			array( 'mode' => 'own', 'app_key' => 'short' ),
			array( 'mode' => 'own', 'app_key' => 'abcd 1234efgh' ),
			array( 'folder' => 'a//b' ),
			array( 'folder' => 'a/../b' ),
			array( 'folder' => 'a/./b' ),
			array( 'folder' => 'a\\b' ),
			array( 'folder' => 'what?' ),
			array( 'folder' => 'ends with dot.' ),
			array( 'folder' => 'a/ b' ),
			array( 'folder' => "a\nb" ),
			array( 'folder' => "bad \xff utf-8" ),
			array( 'folder' => str_repeat( 'x', 201 ) ),
		);
		foreach ( $bad as $input ) {
			try {
				Dropbox_Driver::normalize( $input );
			} catch ( Storage_Exception $e ) {
				Sf_Test::same( Storage_Exception::CONFIG, $e->getCode() );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . json_encode( $input ) );
		}
	}
);

Sf_Test::run(
	'authorization URL: the app key, offline access, the four permissions and PKCE S256',
	static function () {
		list( $driver ) = sf_dropbox();
		$url            = $driver->authorization_url( SF_D_REDIRECT, 'st', 'ch' );
		$query          = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		Sf_Test::same( SF_D_API . '/oauth2/authorize', strtok( $url, '?' ) );
		Sf_Test::same( SF_D_KEY, $query['client_id'] );
		Sf_Test::same( SF_D_REDIRECT, $query['redirect_uri'] );
		Sf_Test::same( 'code', $query['response_type'] );
		Sf_Test::same( 'offline', $query['token_access_type'] );
		Sf_Test::same( 'account_info.read files.metadata.read files.content.read files.content.write', $query['scope'] );
		Sf_Test::same( 'S256', $query['code_challenge_method'] );
		Sf_Test::same( 'ch', $query['code_challenge'] );
		Sf_Test::same( 'st', $query['state'] );
	}
);

Sf_Test::run(
	'connecting: code exchange with PKCE and no secret; a reused code, a wrong verifier, a missing permission or refresh token fail',
	static function () {
		list( $driver, $transport ) = sf_dropbox();
		$query                      = sf_dconsent( $driver, $transport, $saved );
		Sf_Test::ok( $driver->connected(), 'connected' );
		Sf_Test::ok( 0 === strpos( $saved['refresh_token'], 'refresh-' ) && 0 === strpos( $saved['access_token'], 'sl.access-' ), 'tokens kept' );
		Sf_Test::same( SF_D_KEY, $saved['app_key'], 'the app the tokens belong to:' );
		Sf_Test::same( 'tester@example.com', $saved['account'] );
		Sf_Test::same( 'Dropbox of tester@example.com, the app folder', $driver->describe() );
		Sf_Test::same( 0, $transport->dropbox->secrets_sent(), 'no app secret sent:' );

		list( $again, $t2 ) = sf_dropbox();
		sf_dexpect( Storage_Exception::AUTH, static function () use ( $again, $query ) { $again->connect( $query['code'], $query['verifier'], SF_D_REDIRECT ); }, 'invalid_grant' );
		$denied = sf_dconsent( $again, $t2, $ignored, 'deny' );
		Sf_Test::same( 'access_denied', $denied['error'], 'deny:' );

		list( $pkce, $t3 ) = sf_dropbox();
		$url               = $pkce->authorization_url( SF_D_REDIRECT, str_repeat( 'a', 48 ), Oauth_State::base64url( hash( 'sha256', 'right-verifier-0123456789012345678901234567890', true ) ) );
		$back              = $t3->dropbox->handle( 'GET', $url . '&decision=allow', array(), '' );
		parse_str( (string) parse_url( $back['headers']['location'], PHP_URL_QUERY ), $got );
		sf_dexpect( Storage_Exception::AUTH, static function () use ( $pkce, $got ) { $pkce->connect( $got['code'], 'wrong-verifier-0123456789012345678901234567890', SF_D_REDIRECT ); }, 'invalid_grant' );

		list( $scope, $t4 ) = sf_dropbox( array( 'grant_scope' => 'account_info.read files.metadata.read files.content.read' ) );
		$e                  = sf_dexpect( Storage_Exception::AUTH, static function () use ( $scope, $t4 ) { sf_dconsent( $scope, $t4 ); }, 'files.content.write' );
		Sf_Test::ok( false !== strpos( $e->getMessage(), 'Permissions tab' ), 'own app: says where to tick the permission' );
		Sf_Test::ok( ! $scope->connected(), 'not connected without the permission' );

		list( $none, $t5 ) = sf_dropbox( array( 'no_refresh' => true ) );
		sf_dexpect( Storage_Exception::REMOTE, static function () use ( $none, $t5 ) { sf_dconsent( $none, $t5 ); }, 'refresh token' );

		list( $wrong, $t6 ) = sf_dropbox( array(), array( 'app_key' => 'zzzz9999yyyy888' ) );
		$t6->dropbox->config['app_key'] = 'zzzz9999yyyy888';
		$url                            = $wrong->authorization_url( SF_D_REDIRECT, str_repeat( 'b', 48 ), str_repeat( 'C', 43 ) );
		$back                           = $t6->dropbox->handle( 'GET', $url . '&decision=allow', array(), '' );
		parse_str( (string) parse_url( $back['headers']['location'], PHP_URL_QUERY ), $got );
		$t6->dropbox->config['app_key'] = SF_D_KEY; // The key was deleted from the App Console meanwhile.
		sf_dexpect( Storage_Exception::AUTH, static function () use ( $wrong, $got ) { $wrong->connect( $got['code'], 'v', SF_D_REDIRECT ); }, 'Check it on the Storage tab' );
	}
);

Sf_Test::run(
	'tokens: renewed before they expire and after a 401, the new one saved; a revoked grant asks to connect again',
	static function () {
		$now   = time();
		$clock = static function () use ( &$now ) {
			return $now;
		};
		list( $driver, $transport ) = sf_dropbox( array(), array(), $delays, $clock );
		sf_dconsent( $driver, $transport, $saved );
		$first = $saved['access_token'];
		$driver->list();
		Sf_Test::same( 0, $transport->dropbox->token_calls( 'refresh_token' ), 'a fresh token is used as it is:' );

		$now += 14400 - 200;
		$driver->list();
		Sf_Test::same( 1, $transport->dropbox->token_calls( 'refresh_token' ), 'renewed within five minutes of expiry:' );
		Sf_Test::ok( $first !== $saved['access_token'], 'the new token was saved' );

		$transport->dropbox->expire_access_tokens();
		$driver->list();
		Sf_Test::same( 2, $transport->dropbox->token_calls( 'refresh_token' ), 'renewed after a 401:' );
		foreach ( $transport->sent as $request ) {
			if ( SF_D_API . '/oauth2/token' === $request['url'] ) {
				parse_str( $request['body'], $form );
				Sf_Test::ok( ! isset( $form['client_secret'] ), 'renewals carry no secret' );
			}
		}

		$transport->dropbox->revoke_all();
		sf_dexpect( Storage_Exception::AUTH, static function () use ( $driver ) { $driver->list(); }, 'Connect Dropbox again' );

		list( $unconnected ) = sf_dropbox();
		sf_dexpect( Storage_Exception::AUTH, static function () use ( $unconnected ) { $unconnected->list(); }, 'not connected yet' );
	}
);

Sf_Test::run(
	'uploads: parts in order, a repeated part, a lost answer to an append or to the finish, replacing an earlier copy',
	static function () {
		list( $driver, $transport ) = sf_dconnected( array(), array( 'folder' => 'Sites/example' ), $delays );
		$data                       = random_bytes( 1000000 );
		$name                       = 'example-com-2026-11-05-120000-0123456789ab.tar';
		$state                      = $driver->begin( $name, strlen( $data ) );
		Sf_Test::same( Dropbox_Driver::PART_SIZE, $state['part_size'] );
		Sf_Test::same( '/Sites/example/' . $name, $state['path'] );
		$state['part_size'] = 300000;
		$state              = $driver->put_part( $state, 0, substr( $data, 0, 300000 ) );
		$state              = $driver->put_part( $state, 0, substr( $data, 0, 300000 ) ); // Sent again after an interrupted slice.
		$transport->dropbox->fail_next = array( 'lost' );
		$state              = $driver->put_part( $state, 1, substr( $data, 300000, 300000 ) );
		Sf_Test::same( 1, count( $delays ), 'the lost answer was retried:' );
		$state = $driver->put_part( $state, 2, substr( $data, 600000, 300000 ) );
		$state = $driver->put_part( $state, 3, substr( $data, 900000 ) );
		$transport->dropbox->fail_next = array( 'lost' );
		$driver->finish( $state );
		Sf_Test::same( hash( 'sha256', $data ), $transport->dropbox->hash( $name ), 'stored bytes:' );
		Sf_Test::same( array( '/sites/example/' . $name ), array_keys( $transport->dropbox->files() ) );
		Sf_Test::same( 0, $transport->dropbox->open_sessions(), 'no open session left:' );

		$other = random_bytes( 5000 );
		sf_dsend( $driver, $name, $other );
		Sf_Test::same( 1, count( $transport->dropbox->files() ), 'the same name replaces the earlier copy:' );
		Sf_Test::same( hash( 'sha256', $other ), $transport->dropbox->hash( $name ) );

		// A part that starts after what Dropbox has (a part was skipped): start again.
		$state              = $driver->begin( 'b-2026-11-05-120000-0123456789ab.tar', 600000 );
		$state['part_size'] = 300000;
		sf_dexpect( Storage_Exception::REMOTE, static function () use ( $driver, $state ) { $driver->put_part( $state, 1, str_repeat( 'x', 300000 ) ); }, 'start the transfer again' );

		// An expired session: the finish fails and nothing is stored.
		$state              = $driver->begin( 'c-2026-11-05-120000-0123456789ab.tar', 10 );
		$state['session']   = 'sess-gone';
		sf_dexpect( Storage_Exception::REMOTE, static function () use ( $driver, $state ) { $driver->finish( $state ); }, 'start the transfer again' );
		$driver->abort( $state );
		Sf_Test::same( array( $name ), $transport->dropbox->names(), 'only the finished backup is stored:' );
	}
);

Sf_Test::run(
	'request format: JSON for calls, octet-stream for uploads, downloads by GET without a body or Content-Type, ASCII-only arguments',
	static function () {
		list( $driver, $transport ) = sf_dconnected( array(), array( 'folder' => 'Sauvegardes é' ) );
		$name                       = 'x-2026-11-05-120000-0123456789ab.tar';
		sf_dsend( $driver, $name, 'hello dropbox' );
		Sf_Test::same( 'hello', $driver->download_range( $name, 0, 5 ) );
		Sf_Test::same( '/sauvegardes é/' . $name, array_keys( $transport->dropbox->files() )[0], 'the folder name arrives intact:' );
		foreach ( $transport->sent as $request ) {
			$route = (string) parse_url( $request['url'], PHP_URL_PATH );
			if ( isset( $request['headers']['Dropbox-API-Arg'] ) ) {
				Sf_Test::ok( ! preg_match( '/[^\x20-\x7e]/', $request['headers']['Dropbox-API-Arg'] ), 'Dropbox-API-Arg is plain ASCII: ' . $request['headers']['Dropbox-API-Arg'] );
			}
			if ( '/2/files/download' === $route ) {
				Sf_Test::same( 'GET', $request['method'] );
				Sf_Test::ok( ! isset( $request['headers']['Content-Type'] ) && '' === $request['body'], 'a download has no Content-Type and no body' );
			}
			if ( isset( $request['headers']['Authorization'] ) ) {
				$origin = strtolower( (string) parse_url( $request['url'], PHP_URL_SCHEME ) . '://' . parse_url( $request['url'], PHP_URL_HOST ) );
				Sf_Test::ok( in_array( $origin, array( SF_D_API, SF_D_CONTENT ), true ), 'the token goes only to the API hosts: ' . $request['url'] );
				Sf_Test::ok( SF_D_API . '/oauth2/token' !== $request['url'], 'no bearer token to the token endpoint' );
			}
		}
		$hosts = array();
		foreach ( $transport->sent as $request ) {
			$hosts[ (string) parse_url( $request['url'], PHP_URL_PATH ) ] = (string) parse_url( $request['url'], PHP_URL_HOST );
		}
		Sf_Test::same( 'content.fake-dropbox.test', $hosts['/2/files/upload_session/append_v2'], 'uploads go to the content host:' );
		Sf_Test::same( 'api.fake-dropbox.test', $hosts['/2/users/get_current_account'], 'calls go to the API host:' );
	}
);

Sf_Test::run(
	'listing: paged, only backups (no folders or other files), newest first; a folder not made yet is empty',
	static function () {
		list( $driver, $transport, $endpoints ) = sf_dropbox( array( 'page_size' => 2 ), array( 'folder' => 'backups' ) );
		sf_dconsent( $driver, $transport, $saved );
		Sf_Test::same( array(), $driver->list(), 'nothing sent yet:' );
		$names = array( 'a-2026-11-01-000000-0123456789ab.tar', 'b-2026-11-02-000000-0123456789ab.tar.gz', 'c-2026-11-03-000000-0123456789ab.wpress', 'notes.txt' );
		foreach ( $names as $name ) {
			sf_dsend( $driver, $name, $name );
		}
		// A backup in a folder below: listed as a folder, which is left out.
		$nested = new Dropbox_Driver( Dropbox_Driver::normalize( array( 'mode' => 'own', 'app_key' => SF_D_KEY, 'folder' => 'backups/old' ) )['settings'], $transport, $endpoints, static function () {} );
		$nested->use_auth( $saved, null );
		sf_dsend( $nested, 'nested-2026-11-04-000000-0123456789ab.tar', 'x' );
		$list = $driver->list();
		Sf_Test::same( array( 'c-2026-11-03-000000-0123456789ab.wpress', 'b-2026-11-02-000000-0123456789ab.tar.gz', 'a-2026-11-01-000000-0123456789ab.tar' ), array_column( $list, 'name' ), 'newest first, notes.txt and the sub-folder left out:' );
		Sf_Test::same( strlen( $names[2] ), $list[0]['size'] );
		Sf_Test::same( array( 'nested-2026-11-04-000000-0123456789ab.tar' ), array_column( $nested->list(), 'name' ), 'the sub-folder lists on its own:' );
		Sf_Test::ok( $list[0]['modified'] > 0, 'modified time' );
		Sf_Test::ok( count( array_filter( $transport->dropbox->log, static function ( string $line ): bool { return 'POST files/list_folder/continue' === $line; } ) ) >= 2, 'paged with list_folder/continue' );
	}
);

Sf_Test::run(
	'downloads: byte ranges; a missing file is NOT_FOUND; delete removes and tolerates a file already gone',
	static function () {
		list( $driver, $transport ) = sf_dconnected();
		$data                       = random_bytes( 70000 );
		$name                       = 'd-2026-11-05-120000-0123456789ab.tar';
		sf_dsend( $driver, $name, $data, 30000 );
		Sf_Test::same( substr( $data, 1000, 5000 ), $driver->download_range( $name, 1000, 5000 ) );
		Sf_Test::same( substr( $data, 65536 ), $driver->download_range( $name, 65536, 65536 ), 'the last range is shorter:' );
		sf_dexpect( Storage_Exception::NOT_FOUND, static function () use ( $driver ) { $driver->download_range( 'nope-2026-11-05-120000-0123456789ab.tar', 0, 10 ); }, 'not in the Dropbox folder' );
		$driver->delete( $name );
		Sf_Test::same( array(), $transport->dropbox->names() );
		$driver->delete( $name );
	}
);

Sf_Test::run(
	'temporary failures are retried (429, 503, network); a full Dropbox is not',
	static function () {
		list( $driver, $transport ) = sf_dconnected( array(), array(), $delays );
		$transport->dropbox->fail_next = array( 429, 503 );
		$transport->drop_next          = 1;
		Sf_Test::same( array(), $driver->list() );
		Sf_Test::same( 3, count( $delays ), 'three retries:' );

		$transport->dropbox->config['quota'] = 100;
		$state                               = $driver->begin( 'big-2026-11-05-120000-0123456789ab.tar', 200 );
		$state                               = $driver->put_part( $state, 0, str_repeat( 'x', 200 ) );
		sf_dexpect( Storage_Exception::REMOTE, static function () use ( $driver, $state ) { $driver->finish( $state ); }, 'The Dropbox is full.' );

		$transport->dropbox->fail_next = array( 503, 503, 503, 503 );
		sf_dexpect( Storage_Exception::TRANSIENT, static function () use ( $driver ) { $driver->list(); }, 'HTTP 503' );
	}
);

Sf_Test::run(
	'connection test: six checks, the test file removed; a failing check is named; disconnect revokes the access',
	static function () {
		list( $driver, $transport ) = sf_dconnected( array(), array( 'folder' => 'site' ) );
		$lines                      = $driver->test();
		Sf_Test::same( 6, count( $lines ), implode( ' | ', $lines ) );
		Sf_Test::same( 'Signed in to Dropbox as tester@example.com.', $lines[0] );
		Sf_Test::same( array(), $transport->dropbox->names(), 'test file removed:' );

		$transport->dropbox->fail_next = array( 503 );
		sf_dexpect( Storage_Exception::TRANSIENT, static function () use ( $driver ) { $driver->test(); }, 'Signing in to Dropbox failed.' );
		$transport->dropbox->fail_next = array( 'pass', array( 'status' => 409, 'summary' => 'path/no_write_permission/..', 'error' => array( '.tag' => 'path' ) ) );
		sf_dexpect( Storage_Exception::REMOTE, static function () use ( $driver ) { $driver->test(); }, 'Writing a test file with an upload session failed. Dropbox answered HTTP 409 (path/no_write_permission). SiteFerry may not write to this folder.' );

		Sf_Test::same( 1, count( $transport->dropbox->refresh_tokens() ) );
		$driver->disconnect();
		Sf_Test::same( array(), $transport->dropbox->refresh_tokens(), 'revoked at Dropbox:' );
		Sf_Test::ok( ! $driver->connected(), 'forgotten' );
		$driver->disconnect(); // Nothing to revoke: no error.
	}
);

/**
 * Sends requests for the relay's address to the relay, everything else to Dropbox.
 */
final class Sf_Dropbox_Relay_Transport implements Transport {

	/**
	 * Relay address.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * The relay (null: unreachable).
	 *
	 * @var SiteFerry_Relay\Relay|null
	 */
	private $relay;

	/**
	 * Transport to the fake Dropbox.
	 *
	 * @var Fake_Dropbox_Transport
	 */
	private $dropbox;

	/**
	 * Requests to the relay: method, URL, body.
	 *
	 * @var array
	 */
	public $relayed = array();

	/**
	 * Constructor.
	 *
	 * @param string                     $base    Relay address.
	 * @param SiteFerry_Relay\Relay|null $relay   Relay.
	 * @param Fake_Dropbox_Transport     $dropbox Dropbox transport.
	 */
	public function __construct( string $base, ?SiteFerry_Relay\Relay $relay, Fake_Dropbox_Transport $dropbox ) {
		$this->base    = $base;
		$this->relay   = $relay;
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
	 * @throws Storage_Exception When the relay is unreachable.
	 */
	public function request( string $method, string $url, array $headers, string $body = '', int $timeout = 60 ): array {
		if ( 0 !== strpos( $url, $this->base ) ) {
			return $this->dropbox->request( $method, $url, $headers, $body, $timeout );
		}
		$this->relayed[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);
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
	'one-click sign-in through the relay: the browser passes through it, the tokens never do',
	static function () {
		$base                  = 'https://relay.test/siteferry';
		list( , $dropbox_t, $endpoints ) = sf_dropbox( array( 'app_key' => 'relayapp0000key1', 'redirect_uris' => array( $base . '/dropbox/callback' ) ) );
		$dropbox               = $dropbox_t->dropbox;
		$relay                 = new SiteFerry_Relay\Relay(
			array(
				'base_url'         => $base,
				'signing_key'      => str_repeat( 's', 40 ),
				'dropbox_app_key'  => 'relayapp0000key1',
				'dropbox_auth_url' => SF_D_API . '/oauth2/authorize',
			),
			static function () {
				throw new RuntimeException( 'the relay never posts anything for Dropbox' );
			}
		);
		$transport = new Sf_Dropbox_Relay_Transport( $base, $relay, $dropbox_t );
		$settings  = Dropbox_Driver::normalize( array( 'folder' => 'site-a' ) )['settings'];
		$driver    = new Dropbox_Driver( $settings, $transport, array( 'relay' => $base ) + $endpoints, static function () {} );

		$verifier  = Oauth_State::base64url( random_bytes( 48 ) );
		$challenge = Oauth_State::base64url( hash( 'sha256', $verifier, true ) );
		$state     = bin2hex( random_bytes( 24 ) );
		$start     = $driver->authorization_url( SF_D_REDIRECT, $state, $challenge );
		Sf_Test::ok( 0 === strpos( $start, $base . '/dropbox/start?' ), 'the browser goes to the relay first: ' . $start );

		$to_dropbox = $transport->request( 'GET', $start, array() );
		Sf_Test::same( 302, $to_dropbox['status'], 'relay start: ' . $to_dropbox['body'] );
		$consent = $to_dropbox['headers']['location'];
		parse_str( (string) parse_url( $consent, PHP_URL_QUERY ), $asked );
		Sf_Test::same( 'relayapp0000key1', $asked['client_id'], 'Dropbox sees the relay\'s app:' );
		Sf_Test::same( $base . '/dropbox/callback', $asked['redirect_uri'] );
		Sf_Test::same( 'offline', $asked['token_access_type'] );
		Sf_Test::ok( strlen( $asked['state'] ) <= 500, 'the signed state fits Dropbox\'s 500 bytes' );
		$to_relay = $dropbox->handle( 'GET', $consent . '&decision=allow', array(), '' );
		Sf_Test::same( 302, $to_relay['status'], 'Dropbox accepts the relay\'s registered redirect: ' . $to_relay['body'] );
		$to_site = $transport->request( 'GET', $to_relay['headers']['location'], array() );
		parse_str( (string) parse_url( $to_site['headers']['location'], PHP_URL_QUERY ), $back );
		Sf_Test::same( 0, strpos( $to_site['headers']['location'], SF_D_REDIRECT . '&' ), 'back on the site page:' );
		Sf_Test::same( $state, $back['state'], 'the site gets its own state back:' );

		$saved = array();
		$driver->use_auth(
			array(),
			static function ( array $auth ) use ( &$saved ) {
				$saved = $auth;
			}
		);
		$auth = $driver->connect( $back['code'], $verifier, SF_D_REDIRECT );
		Sf_Test::ok( $driver->connected(), 'connected' );
		Sf_Test::same( 'relayapp0000key1', $auth['app_key'], 'the relay\'s app key is kept for renewals:' );
		Sf_Test::same( 'Dropbox of tester@example.com, folder "site-a" in the app folder', $driver->describe() );
		sf_dsend( $driver, 'r-2026-11-05-120000-0123456789ab.tar', 'relay mode works' );
		$dropbox->expire_access_tokens();
		Sf_Test::same( array( 'r-2026-11-05-120000-0123456789ab.tar' ), array_column( $driver->list(), 'name' ), 'listing after a renewal:' );
		Sf_Test::same( 2, $dropbox->token_calls( 'authorization_code' ) + $dropbox->token_calls( 'refresh_token' ), 'exchange and renewal went to Dropbox directly:' );

		$tokens = array_merge( $dropbox->refresh_tokens(), array( $saved['access_token'] ) );
		foreach ( $transport->relayed as $request ) {
			Sf_Test::same( 'GET', $request['method'], 'only GETs to the relay: ' . $request['url'] );
			foreach ( $tokens as $token ) {
				Sf_Test::ok( false === strpos( $request['url'] . $request['body'] . json_encode( $request['headers'] ), $token ), 'no token ever reaches the relay' );
			}
		}
		Sf_Test::same( $base . '/dropbox/app', end( $transport->relayed )['url'], 'the site asked the relay for the app key:' );

		$down = new Sf_Dropbox_Relay_Transport( $base, null, $dropbox_t );
		$cut  = new Dropbox_Driver( $settings, $down, array( 'relay' => $base ) + $endpoints, static function () {} );
		sf_dexpect( Storage_Exception::TRANSIENT, static function () use ( $cut ) { $cut->connect( 'code', str_repeat( 'v', 64 ), SF_D_REDIRECT ); }, 'The sign-in service relay.test cannot be reached' );

		$google_only = new SiteFerry_Relay\Relay( array( 'base_url' => $base, 'signing_key' => str_repeat( 's', 40 ), 'google_client_id' => 'x.apps.googleusercontent.com', 'google_client_secret' => 'y' ), static function () {} );
		$no_dropbox  = new Dropbox_Driver( $settings, new Sf_Dropbox_Relay_Transport( $base, $google_only, $dropbox_t ), array( 'relay' => $base ) + $endpoints, static function () {} );
		sf_dexpect( Storage_Exception::TRANSIENT, static function () use ( $no_dropbox ) { $no_dropbox->connect( 'code', str_repeat( 'v', 64 ), SF_D_REDIRECT ); }, 'Dropbox sign-in is not set up on this relay' );
	}
);

exit( Sf_Test::finish( 'test-dropbox' ) );
