<?php
/**
 * AWS Signature Version 4.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Signs HTTP requests with AWS Signature Version 4 (header-based, single-chunk payload),
 * written from AWS's public description of the algorithm:
 *
 * 1. Canonical request: method, URI path, sorted query, sorted lower-case headers, the list of
 *    signed header names, and the hex SHA-256 of the payload.
 * 2. String to sign: algorithm, timestamp, credential scope (date/region/service/aws4_request) and
 *    the hex SHA-256 of the canonical request.
 * 3. Signing key: HMAC-SHA256 chain over date, region, service and "aws4_request", starting from
 *    "AWS4" + secret key. The signature is the hex HMAC of the string to sign with that key.
 *
 * The URL's path must already be URI-encoded the way it is sent (S3 encodes each path segment
 * once, so the signer uses the path as given). Query parameters are decoded and re-encoded.
 */
final class Sigv4 {

	const ALGORITHM = 'AWS4-HMAC-SHA256';

	/** SHA-256 of an empty payload. */
	const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/**
	 * Access key ID.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Region, for example us-east-1.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Service, for example s3.
	 *
	 * @var string
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param string $access_key Access key ID.
	 * @param string $secret_key Secret access key.
	 * @param string $region     Region.
	 * @param string $service    Service name.
	 */
	public function __construct(
		string $access_key,
		#[\SensitiveParameter]
		string $secret_key,
		string $region,
		string $service = 's3'
	) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->region     = $region;
		$this->service    = $service;
	}

	/**
	 * Signs a request.
	 *
	 * @param string               $method       HTTP method.
	 * @param string               $url          Full URL (path already URI-encoded).
	 * @param array<string,string> $headers      Headers to sign and send. Host is taken from the URL
	 *                                           unless given.
	 * @param string               $payload_hash Hex SHA-256 of the body.
	 * @param string               $amz_date     Time as YYYYMMDD'T'HHMMSS'Z' (UTC).
	 * @return array{authorization:string,signature:string,signed_headers:string,canonical_request:string,string_to_sign:string}
	 */
	public function sign( string $method, string $url, array $headers, string $payload_hash, string $amz_date ): array {
		$parts = (array) wp_parse_url( $url );

		$canonical_headers = array();
		foreach ( $headers as $name => $value ) {
			$canonical_headers[ strtolower( trim( (string) $name ) ) ] = trim( (string) preg_replace( '/\s+/', ' ', (string) $value ) );
		}
		if ( ! isset( $canonical_headers['host'] ) ) {
			$canonical_headers['host'] = self::host( $parts );
		}
		ksort( $canonical_headers, SORT_STRING );

		$header_lines = '';
		foreach ( $canonical_headers as $name => $value ) {
			$header_lines .= $name . ':' . $value . "\n";
		}
		$signed_headers = implode( ';', array_keys( $canonical_headers ) );

		$canonical_request = implode(
			"\n",
			array(
				strtoupper( $method ),
				isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/',
				self::canonical_query( isset( $parts['query'] ) ? $parts['query'] : '' ),
				$header_lines,
				$signed_headers,
				$payload_hash,
			)
		);

		$date           = substr( $amz_date, 0, 8 );
		$scope          = $date . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$string_to_sign = self::ALGORITHM . "\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signature      = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date ) );

		return array(
			'authorization'     => sprintf( '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s', self::ALGORITHM, $this->access_key, $scope, $signed_headers, $signature ),
			'signature'         => $signature,
			'signed_headers'    => $signed_headers,
			'canonical_request' => $canonical_request,
			'string_to_sign'    => $string_to_sign,
		);
	}

	/**
	 * The derived signing key for a day (binary).
	 *
	 * @param string $date YYYYMMDD.
	 */
	public function signing_key( string $date ): string {
		$key = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$key = hash_hmac( 'sha256', $this->region, $key, true );
		$key = hash_hmac( 'sha256', $this->service, $key, true );
		return hash_hmac( 'sha256', 'aws4_request', $key, true );
	}

	/**
	 * Query string in canonical form: every name and value URI-encoded (RFC 3986), sorted by name
	 * and then value, "name=value" pairs joined with "&" (a parameter without value gets "=").
	 *
	 * @param string $query Raw query string.
	 */
	public static function canonical_query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$split   = explode( '=', $pair, 2 );
			$pairs[] = array( rawurlencode( rawurldecode( $split[0] ) ), rawurlencode( rawurldecode( isset( $split[1] ) ? $split[1] : '' ) ) );
		}
		usort(
			$pairs,
			static function ( array $a, array $b ): int {
				return 0 !== strcmp( $a[0], $b[0] ) ? strcmp( $a[0], $b[0] ) : strcmp( $a[1], $b[1] );
			}
		);
		return implode(
			'&',
			array_map(
				static function ( array $pair ): string {
					return $pair[0] . '=' . $pair[1];
				},
				$pairs
			)
		);
	}

	/**
	 * Host header value for a parsed URL: the host, plus the port when it is not the default.
	 *
	 * @param array $parts wp_parse_url() result.
	 */
	public static function host( array $parts ): string {
		$host    = strtolower( isset( $parts['host'] ) ? (string) $parts['host'] : '' );
		$scheme  = strtolower( isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https' );
		$port    = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$default = 'http' === $scheme ? 80 : 443;
		return 0 === $port || $default === $port ? $host : $host . ':' . $port;
	}
}
