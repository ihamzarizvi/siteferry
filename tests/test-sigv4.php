<?php
/**
 * AWS Signature Version 4 against the examples AWS publishes in its documentation
 * (example credentials, fixed timestamps, expected signatures).
 *
 * Run: php tests/test-sigv4.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Storage\Sigv4;

echo "SigV4 tests\n";

// Amazon S3 API Reference, "Signature Calculations for the Authorization Header: Transferring
// Payload in a Single Chunk" (bucket examplebucket, us-east-1, 2013-05-24).
const SF_S3_ACCESS = 'AKIAIOSFODNN7EXAMPLE';
const SF_S3_SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
const SF_S3_DATE   = '20130524T000000Z';

Sf_Test::run(
	'S3 example: GET object with a Range header',
	static function () {
		$signer = new Sigv4( SF_S3_ACCESS, SF_S3_SECRET, 'us-east-1', 's3' );
		$signed = $signer->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/test.txt',
			array(
				'Range'                => 'bytes=0-9',
				'x-amz-content-sha256' => Sigv4::EMPTY_HASH,
				'x-amz-date'           => SF_S3_DATE,
			),
			Sigv4::EMPTY_HASH,
			SF_S3_DATE
		);
		$expected_canonical = "GET\n/test.txt\n\nhost:examplebucket.s3.amazonaws.com\nrange:bytes=0-9\nx-amz-content-sha256:" . Sigv4::EMPTY_HASH . "\nx-amz-date:20130524T000000Z\n\nhost;range;x-amz-content-sha256;x-amz-date\n" . Sigv4::EMPTY_HASH;
		Sf_Test::same( $expected_canonical, $signed['canonical_request'], 'canonical request:' );
		Sf_Test::same( '7344ae5b7ee6c3e7e6b0fe0640412a37625d1fbfff95c48bbb2dc43964946972', hash( 'sha256', $signed['canonical_request'] ), 'canonical request hash:' );
		Sf_Test::same( 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $signed['signature'] );
		Sf_Test::same(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$signed['authorization']
		);
	}
);

Sf_Test::run(
	'S3 example: PUT object with a "$" in the key and a storage class',
	static function () {
		$payload = 'Welcome to Amazon S3.';
		$hash    = hash( 'sha256', $payload );
		Sf_Test::same( '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072', $hash, 'payload hash:' );
		$signer = new Sigv4( SF_S3_ACCESS, SF_S3_SECRET, 'us-east-1', 's3' );
		$signed = $signer->sign(
			'PUT',
			'https://examplebucket.s3.amazonaws.com/' . rawurlencode( 'test$file.text' ),
			array(
				'Date'                 => 'Fri, 24 May 2013 00:00:00 GMT',
				'x-amz-date'           => SF_S3_DATE,
				'x-amz-storage-class'  => 'REDUCED_REDUNDANCY',
				'x-amz-content-sha256' => $hash,
			),
			$hash,
			SF_S3_DATE
		);
		Sf_Test::ok( false !== strpos( $signed['canonical_request'], "PUT\n/test%24file.text\n" ), 'key encoded once: ' . $signed['canonical_request'] );
		Sf_Test::same( 'date;host;x-amz-content-sha256;x-amz-date;x-amz-storage-class', $signed['signed_headers'] );
		Sf_Test::same( '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $signed['signature'] );
	}
);

Sf_Test::run(
	'S3 example: GET bucket lifecycle (parameter without value)',
	static function () {
		$signer = new Sigv4( SF_S3_ACCESS, SF_S3_SECRET, 'us-east-1', 's3' );
		$signed = $signer->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/?lifecycle',
			array(
				'x-amz-date'           => SF_S3_DATE,
				'x-amz-content-sha256' => Sigv4::EMPTY_HASH,
			),
			Sigv4::EMPTY_HASH,
			SF_S3_DATE
		);
		Sf_Test::ok( false !== strpos( $signed['canonical_request'], "GET\n/\nlifecycle=\n" ), 'canonical query: ' . $signed['canonical_request'] );
		Sf_Test::same( 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', $signed['signature'] );
	}
);

Sf_Test::run(
	'S3 example: list objects (query parameters sorted)',
	static function () {
		$signer = new Sigv4( SF_S3_ACCESS, SF_S3_SECRET, 'us-east-1', 's3' );
		$signed = $signer->sign(
			'GET',
			'https://examplebucket.s3.amazonaws.com/?prefix=J&max-keys=2',
			array(
				'x-amz-date'           => SF_S3_DATE,
				'x-amz-content-sha256' => Sigv4::EMPTY_HASH,
			),
			Sigv4::EMPTY_HASH,
			SF_S3_DATE
		);
		Sf_Test::ok( false !== strpos( $signed['canonical_request'], "\nmax-keys=2&prefix=J\n" ), 'sorted query: ' . $signed['canonical_request'] );
		Sf_Test::same( '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $signed['signature'] );
	}
);

// General Reference, "Signature Version 4 signing process" example (IAM ListUsers, 2015-08-30).
Sf_Test::run(
	'IAM example: signing key and signature (other service, form content type)',
	static function () {
		$signer = new Sigv4( 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'iam' );
		Sf_Test::same( 'c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9', bin2hex( $signer->signing_key( '20150830' ) ), 'signing key:' );
		$signed = $signer->sign(
			'GET',
			'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08',
			array(
				'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
				'X-Amz-Date'   => '20150830T123600Z',
			),
			Sigv4::EMPTY_HASH,
			'20150830T123600Z'
		);
		Sf_Test::same( 'f536975d06c0309214f805bb90ccff089219ecd68b2577efef23edd43b7e1a59', hash( 'sha256', $signed['canonical_request'] ), 'canonical request hash:' );
		Sf_Test::same( '5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7', $signed['signature'] );
	}
);

// aws-sig-v4-test-suite "get-vanilla" (service "service", 2015-08-30).
Sf_Test::run(
	'test suite: get-vanilla',
	static function () {
		$signer = new Sigv4( 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 'us-east-1', 'service' );
		$signed = $signer->sign( 'GET', 'https://example.amazonaws.com/', array( 'X-Amz-Date' => '20150830T123600Z' ), Sigv4::EMPTY_HASH, '20150830T123600Z' );
		Sf_Test::same( '5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31', $signed['signature'] );
	}
);

Sf_Test::run(
	'canonical forms: query encoding, header whitespace, non-default ports',
	static function () {
		// "~" is unreserved in RFC 3986 and stays as it is; "/" in a value is encoded.
		Sf_Test::same( 'a=&b=1&b=2&c%20d=%2F~', Sigv4::canonical_query( 'c%20d=/%7E&b=2&a&b=1' ) );
		Sf_Test::same( 'uploads=', Sigv4::canonical_query( 'uploads' ) );
		Sf_Test::same( '127.0.0.1:9000', Sigv4::host( (array) parse_url( 'http://127.0.0.1:9000/x' ) ) );
		Sf_Test::same( 'example.com', Sigv4::host( (array) parse_url( 'https://Example.COM:443/x' ) ) );
		$signer = new Sigv4( 'A', 'B', 'auto', 's3' );
		$signed = $signer->sign( 'GET', 'http://h:81/', array( 'X-Custom' => "  two   spaces\tand tab  " ), Sigv4::EMPTY_HASH, '20260101T000000Z' );
		Sf_Test::ok( false !== strpos( $signed['canonical_request'], "host:h:81\nx-custom:two spaces and tab\n" ), $signed['canonical_request'] );
	}
);

exit( Sf_Test::finish( 'test-sigv4' ) );
