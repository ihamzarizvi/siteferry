<?php
/**
 * Encryption of stored secrets (libsodium and the OpenSSL fallback).
 *
 * Run: php -d extension=sodium -d extension=openssl tests/test-secrets.php
 * (run-tests.ps1 loads both extensions when the PHP build has them).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Security\Secret_Exception;
use SiteFerry\Security\Secrets;

echo "Secrets tests\n";

/**
 * Asserts that decrypting fails.
 *
 * @param Secrets $secrets Instance.
 * @param string  $stored  Stored form.
 * @param string  $label   Context.
 */
function sf_expect_unreadable( Secrets $secrets, string $stored, string $label ): void {
	try {
		$secrets->decrypt( $stored );
	} catch ( Secret_Exception $e ) {
		Sf_Test::ok( false !== strpos( $e->getMessage(), 'cannot be read' ) || false !== strpos( $e->getMessage(), 'needs the PHP' ), $label . ': ' . $e->getMessage() );
		return;
	}
	throw new RuntimeException( $label . ': decrypted although it should not' );
}

$backends = array(
	'sodium'  => function_exists( 'sodium_crypto_secretbox' ),
	'openssl' => function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ),
);

foreach ( $backends as $backend => $available ) {
	Sf_Test::run(
		"$backend: round trip, random nonces, tampering and wrong keys are detected",
		static function () use ( $backend, $available ) {
			if ( ! $available ) {
				Sf_Test::skip( "this PHP has no $backend" );
			}
			$material = random_bytes( 64 );
			$secrets  = new Secrets( $material, $backend );
			$secret   = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY+üñí€';
			$stored   = $secrets->encrypt( $secret );

			Sf_Test::same( 'sodium' === $backend ? Secrets::SODIUM : Secrets::OPENSSL, substr( $stored, 0, 5 ), 'prefix:' );
			Sf_Test::ok( false === strpos( $stored, 'EXAMPLEKEY' ), 'not stored in plain text' );
			Sf_Test::same( $secret, $secrets->decrypt( $stored ) );
			Sf_Test::same( $secret, ( new Secrets( $material ) )->decrypt( $stored ), 'readable with the default backend:' );
			Sf_Test::ok( $stored !== $secrets->encrypt( $secret ), 'a new nonce every time' );
			Sf_Test::same( '', $secrets->decrypt( $secrets->encrypt( '' ) ), 'empty secret:' );

			$raw = base64_decode( substr( $stored, 5 ) );
			for ( $i = 0; $i < strlen( $raw ); $i += 7 ) {
				$bad      = $raw;
				$bad[ $i ] = chr( ord( $bad[ $i ] ) ^ 1 );
				sf_expect_unreadable( $secrets, substr( $stored, 0, 5 ) . base64_encode( $bad ), "flipped byte $i" );
			}
			sf_expect_unreadable( new Secrets( random_bytes( 64 ), $backend ), $stored, 'other key' );
			sf_expect_unreadable( $secrets, substr( $stored, 0, -4 ), 'truncated' );
			sf_expect_unreadable( $secrets, 'plain-text-secret', 'not encrypted' );
			sf_expect_unreadable( $secrets, substr( $stored, 0, 5 ) . '!!not base64!!', 'bad base64' );
		}
	);
}

Sf_Test::run(
	'no backend: saving a secret is refused with a clear message',
	static function () {
		try {
			( new Secrets( 'x', 'none' ) )->encrypt( 'secret' );
		} catch ( Secret_Exception $e ) {
			Sf_Test::ok( false !== strpos( $e->getMessage(), 'sodium or OpenSSL' ), $e->getMessage() );
			return;
		}
		throw new RuntimeException( 'encrypted without a backend' );
	}
);

exit( Sf_Test::finish( 'test-secrets' ) );
