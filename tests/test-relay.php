<?php
/**
 * The sign-in relay (relay/src/class-relay.php) with a fake Google token endpoint.
 *
 * Run: php tests/test-relay.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';
require dirname( __DIR__ ) . '/relay/src/class-relay.php';

use SiteFerry_Relay\Relay;

echo "Relay tests\n";

const SF_R_RETURN = 'https://example.com/blog/wp-admin/admin.php?page=siteferry&oauth=gdrive';

/**
 * A relay with a recording fake for Google's token endpoint.
 *
 * @param array         $sent  Receives what was forwarded to Google.
 * @param callable|null $clock Clock.
 * @param array|null    $reply What "Google" answers (null: a network failure).
 */
function sf_relay( ?array &$sent = null, ?callable $clock = null, ?array $reply = array( 'status' => 200, 'body' => '{"access_token":"a","expires_in":3600}' ) ): Relay {
	$sent = array();
	return new Relay(
		array(
			'base_url'             => 'https://auth.example.test/siteferry',
			'signing_key'          => str_repeat( 'k', 40 ),
			'google_client_id'     => 'relay-client.apps.googleusercontent.com',
			'google_client_secret' => 'relay-secret',
		),
		static function ( string $url, array $fields ) use ( &$sent, $reply ) {
			$sent[] = array( 'url' => $url, 'fields' => $fields );
			return $reply;
		},
		$clock
	);
}

/**
 * The query of a redirect's Location.
 *
 * @param array $response Response.
 */
function sf_location_query( array $response ): array {
	Sf_Test::same( 302, $response['status'], 'redirect: ' . $response['body'] );
	$query = array();
	parse_str( (string) parse_url( $response['headers']['Location'], PHP_URL_QUERY ), $query );
	return $query;
}

$sf_state     = str_repeat( 'ab', 24 );
$sf_challenge = str_repeat( 'C', 43 );

Sf_Test::run(
	'start: sends the browser to Google with the relay\'s client, callback, drive.file and the site\'s challenge',
	static function () use ( $sf_state, $sf_challenge ) {
		$relay = sf_relay();
		$start = $relay->handle( 'GET', '/siteferry/google/start', array( 'return' => SF_R_RETURN, 'state' => $sf_state, 'challenge' => $sf_challenge ), '' );
		$query = sf_location_query( $start );
		Sf_Test::same( 'https://accounts.google.com/o/oauth2/v2/auth', strtok( $start['headers']['Location'], '?' ) );
		Sf_Test::same( 'relay-client.apps.googleusercontent.com', $query['client_id'] );
		Sf_Test::same( 'https://auth.example.test/siteferry/google/callback', $query['redirect_uri'] );
		Sf_Test::same( 'https://www.googleapis.com/auth/drive.file', $query['scope'] );
		Sf_Test::same( 'offline', $query['access_type'] );
		Sf_Test::same( $sf_challenge, $query['code_challenge'] );
		Sf_Test::same( 'S256', $query['code_challenge_method'] );
		Sf_Test::ok( $sf_state !== $query['state'] && false !== strpos( $query['state'], '.' ), 'the state sent to Google is signed by the relay' );
		Sf_Test::ok( false === strpos( $start['headers']['Location'], 'relay-secret' ), 'the secret never leaves the relay' );
		Sf_Test::same( 'no-store', $start['headers']['Cache-Control'] );
		Sf_Test::same( 'no-referrer', $start['headers']['Referrer-Policy'] );
	}
);

Sf_Test::run(
	'start refuses return addresses that are not a SiteFerry page, and malformed state or challenge',
	static function () use ( $sf_state, $sf_challenge ) {
		$relay = sf_relay();
		$bad   = array(
			'https://evil.example/phish',
			'https://example.com/wp-admin/admin.php?page=siteferry&oauth=gdrive&next=https://evil.example',
			'https://example.com/wp-admin/admin.php?page=other&oauth=gdrive',
			'https://example.com/wp-login.php?page=siteferry&oauth=gdrive',
			'https://user:pass@example.com/wp-admin/admin.php?page=siteferry&oauth=gdrive',
			'https://example.com/wp-admin/admin.php?page=siteferry&oauth=gdrive#x',
			'javascript:alert(1)//wp-admin/admin.php?page=siteferry&oauth=gdrive',
			'ftp://example.com/wp-admin/admin.php?page=siteferry&oauth=gdrive',
			"https://example.com/wp-admin/admin.php?page=siteferry&oauth=gdrive\r\nX: y",
			'',
		);
		foreach ( $bad as $return ) {
			$answer = $relay->handle( 'GET', '/siteferry/google/start', array( 'return' => $return, 'state' => $sf_state, 'challenge' => $sf_challenge ), '' );
			Sf_Test::same( 400, $answer['status'], json_encode( $return ) . ':' );
		}
		Sf_Test::ok( Relay::valid_return( 'http://localhost:10009/wp-admin/admin.php?oauth=gdrive&page=siteferry', 'gdrive' ), 'local http sites and any parameter order are fine' );
		foreach ( array( array( 'state' => 'short' ), array( 'challenge' => 'x' ), array( 'state' => array( 'a' ) ) ) as $change ) {
			$answer = $relay->handle( 'GET', '/siteferry/google/start', $change + array( 'return' => SF_R_RETURN, 'state' => $sf_state, 'challenge' => $sf_challenge ), '' );
			Sf_Test::same( 400, $answer['status'], json_encode( $change ) . ':' );
		}
	}
);

Sf_Test::run(
	'callback: returns the browser to the site with the code and its own state; checks signature and age',
	static function () use ( $sf_state, $sf_challenge ) {
		$now   = time();
		$clock = static function () use ( &$now ) {
			return $now;
		};
		$relay  = sf_relay( $sent, $clock );
		$signed = sf_location_query( $relay->handle( 'GET', '/siteferry/google/start', array( 'return' => SF_R_RETURN, 'state' => $sf_state, 'challenge' => $sf_challenge ), '' ) )['state'];

		$back = $relay->handle( 'GET', '/siteferry/google/callback', array( 'code' => '4/0Ab-code_x', 'state' => $signed, 'scope' => 'x' ), '' );
		Sf_Test::same( 302, $back['status'] );
		Sf_Test::same( 0, strpos( $back['headers']['Location'], SF_R_RETURN . '&' ), 'back to the site page: ' . $back['headers']['Location'] );
		$query = sf_location_query( $back );
		Sf_Test::same( '4/0Ab-code_x', $query['code'] );
		Sf_Test::same( $sf_state, $query['state'], 'the site gets its own state back:' );

		$denied = sf_location_query( $relay->handle( 'GET', '/siteferry/google/callback', array( 'error' => 'access_denied', 'state' => $signed ), '' ) );
		Sf_Test::same( 'access_denied', $denied['error'] );
		Sf_Test::ok( ! isset( $denied['code'] ), 'no code on refusal' );

		$tampered = substr( $signed, 0, -2 ) . ( 'AA' === substr( $signed, -2 ) ? 'BB' : 'AA' );
		Sf_Test::same( 400, $relay->handle( 'GET', '/siteferry/google/callback', array( 'code' => 'c', 'state' => $tampered ), '' )['status'], 'tampered state:' );
		list( $payload ) = explode( '.', $signed );
		$forged          = json_decode( (string) base64_decode( strtr( $payload, '-_', '+/' ) ), true );
		$forged['r']     = 'https://evil.example/wp-admin/admin.php?page=siteferry&oauth=gdrive';
		$forged_payload  = rtrim( strtr( base64_encode( (string) json_encode( $forged, JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
		Sf_Test::same( 400, $relay->handle( 'GET', '/siteferry/google/callback', array( 'code' => 'c', 'state' => $forged_payload . '.' . explode( '.', $signed )[1] ), '' )['status'], 'changed return address:' );
		Sf_Test::same( 400, $relay->handle( 'GET', '/siteferry/google/callback', array( 'code' => "c\r\nd", 'state' => $signed ), '' )['status'], 'code with a line break:' );

		$now += Relay::STATE_TTL + 1;
		Sf_Test::same( 400, $relay->handle( 'GET', '/siteferry/google/callback', array( 'code' => 'c', 'state' => $signed ), '' )['status'], 'expired:' );
		Sf_Test::same( array(), $sent, 'nothing forwarded to Google by start and callback:' );
	}
);

Sf_Test::run(
	'token: adds the client credentials, forwards only what Google needs, returns Google\'s answer',
	static function () {
		$relay  = sf_relay( $sent, null, array( 'status' => 200, 'body' => '{"access_token":"at","refresh_token":"rt","expires_in":3599,"scope":"https://www.googleapis.com/auth/drive.file"}' ) );
		$answer = $relay->handle( 'POST', '/siteferry/google/token', array(), http_build_query( array( 'grant_type' => 'authorization_code', 'code' => '4/abc', 'code_verifier' => str_repeat( 'v', 64 ), 'client_secret' => 'smuggled', 'redirect_uri' => 'https://evil.example' ) ) );
		Sf_Test::same( 200, $answer['status'] );
		Sf_Test::same( 'rt', json_decode( $answer['body'], true )['refresh_token'] );
		Sf_Test::same( 'https://oauth2.googleapis.com/token', $sent[0]['url'] );
		Sf_Test::same(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => '4/abc',
				'code_verifier' => str_repeat( 'v', 64 ),
				'redirect_uri'  => 'https://auth.example.test/siteferry/google/callback',
				'client_id'     => 'relay-client.apps.googleusercontent.com',
				'client_secret' => 'relay-secret',
			),
			$sent[0]['fields'],
			'forwarded fields (the caller cannot choose the redirect URI or the secret):'
		);

		$relay->handle( 'POST', '/siteferry/google/token', array(), http_build_query( array( 'grant_type' => 'refresh_token', 'refresh_token' => '1//rt' ) ) );
		Sf_Test::same( array( 'grant_type', 'refresh_token', 'client_id', 'client_secret' ), array_keys( $sent[1]['fields'] ), 'refresh fields:' );

		foreach ( array( array( 'grant_type' => 'password' ), array( 'grant_type' => 'authorization_code', 'code' => 'c' ), array( 'grant_type' => 'refresh_token' ) ) as $form ) {
			$bad = $relay->handle( 'POST', '/siteferry/google/token', array(), http_build_query( $form ) );
			Sf_Test::same( 400, $bad['status'], json_encode( $form ) . ':' );
		}
		Sf_Test::same( 405, $relay->handle( 'GET', '/siteferry/google/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => 'x' ), '' )['status'], 'GET refused:' );
		Sf_Test::same( 413, $relay->handle( 'POST', '/siteferry/google/token', array(), str_repeat( 'x', Relay::MAX_BODY + 1 ) )['status'], 'oversized body:' );
		Sf_Test::same( 2, count( $sent ), 'refused requests are not forwarded:' );

		$down = sf_relay( $ignored, null, null )->handle( 'POST', '/siteferry/google/token', array(), http_build_query( array( 'grant_type' => 'refresh_token', 'refresh_token' => 'x' ) ) );
		Sf_Test::same( 502, $down['status'] );
		Sf_Test::same( 'temporarily_unavailable', json_decode( $down['body'], true )['error'] );
	}
);

Sf_Test::run(
	'health check, unknown paths, missing configuration',
	static function () {
		Sf_Test::same( 200, sf_relay()->handle( 'GET', '/siteferry/', array(), '' )['status'] );
		Sf_Test::same( 404, sf_relay()->handle( 'GET', '/siteferry/other', array(), '' )['status'] );
		$empty = new Relay( array( 'base_url' => 'https://x', 'signing_key' => 'short' ), static function () {} );
		Sf_Test::same( 503, $empty->handle( 'GET', '/google/start', array(), '' )['status'], 'refuses to run half configured:' );
	}
);

exit( Sf_Test::finish( 'test-relay' ) );
