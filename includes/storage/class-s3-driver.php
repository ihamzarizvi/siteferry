<?php
/**
 * S3-compatible storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * Amazon S3 and services that speak its API (Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean
 * Spaces, MinIO, ...), with requests signed by Sigv4 and no SDK.
 *
 * Backups are stored as <folder>/<backup name>. Uploads use multipart upload with parts of one
 * fixed size (Cloudflare R2 requires that), at least 5 MiB and large enough to stay within
 * 10,000 parts. Every request carries the SHA-256 of its body, which the service checks.
 */
final class S3_Driver implements Driver {

	/** Default part size: 8 MiB. */
	const PART_SIZE = 8388608;

	/** Smallest part S3 accepts (except the last). */
	const MIN_PART = 5242880;

	/** Most parts in one upload. */
	const MAX_PARTS = 10000;

	/** Seconds for a request that carries a part. */
	const TIMEOUT = 120;

	/** Seconds for other requests. */
	const SHORT_TIMEOUT = 30;

	/** Namespace of S3 XML documents. */
	const XMLNS = 'http://s3.amazonaws.com/doc/2006-03-01/';

	/**
	 * Providers: label, endpoint template ({region} is filled in), default region, path-style
	 * default and a hint for the Storage tab.
	 */
	const PRESETS = array(
		'aws'    => array(
			'label'      => 'Amazon S3',
			'endpoint'   => 's3.{region}.amazonaws.com',
			'region'     => 'us-east-1',
			'path_style' => false,
			'help'       => 'Use an IAM user whose access is limited to this bucket. The endpoint follows from the region.',
		),
		'b2'     => array(
			'label'      => 'Backblaze B2',
			'endpoint'   => 's3.{region}.backblazeb2.com',
			'region'     => 'us-west-004',
			'path_style' => false,
			'help'       => 'The region is part of the bucket\'s S3 endpoint (for example us-west-004). Use an application key limited to this bucket.',
		),
		'wasabi' => array(
			'label'      => 'Wasabi',
			'endpoint'   => 's3.{region}.wasabisys.com',
			'region'     => 'us-east-1',
			'path_style' => false,
			'help'       => 'Region such as us-east-1 or eu-central-1.',
		),
		'r2'     => array(
			'label'      => 'Cloudflare R2',
			'endpoint'   => '<account-id>.r2.cloudflarestorage.com',
			'region'     => 'auto',
			'path_style' => true,
			'help'       => 'Endpoint: <account-id>.r2.cloudflarestorage.com (see the R2 overview page). Region: auto. Use an R2 API token with Object Read & Write for this bucket.',
		),
		'spaces' => array(
			'label'      => 'DigitalOcean Spaces',
			'endpoint'   => '{region}.digitaloceanspaces.com',
			'region'     => 'nyc3',
			'path_style' => false,
			'help'       => 'Region such as nyc3, ams3 or sgp1. Use a Spaces access key.',
		),
		'custom' => array(
			'label'      => 'Other S3-compatible service',
			'endpoint'   => '',
			'region'     => 'us-east-1',
			'path_style' => true,
			'help'       => 'For MinIO and similar services. Enter the endpoint; http:// is accepted here (for test servers), https:// is used otherwise.',
		),
	);

	/**
	 * Normalized settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Request signer.
	 *
	 * @var Sigv4
	 */
	private $signer;

	/**
	 * HTTP.
	 *
	 * @var Transport
	 */
	private $transport;

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
	 * Whether temporary failures are retried (not during test(), which should answer quickly).
	 *
	 * @var bool
	 */
	private $retry = true;

	/**
	 * Constructor.
	 *
	 * @param array         $settings   Normalized settings.
	 * @param string        $secret_key Secret access key.
	 * @param Transport     $transport  HTTP.
	 * @param callable|null $sleep      Retry wait (tests).
	 * @param callable|null $clock      Current time (tests).
	 */
	public function __construct(
		array $settings,
		#[\SensitiveParameter]
		string $secret_key,
		Transport $transport,
		?callable $sleep = null,
		?callable $clock = null
	) {
		$this->settings  = $settings;
		$this->signer    = new Sigv4( $settings['access_key'], $secret_key, $settings['region'], 's3' );
		$this->transport = $transport;
		$this->sleep     = $sleep;
		$this->clock     = null === $clock ? 'time' : $clock;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array                $settings  Settings.
	 * @param array<string,string> $secrets   Secrets.
	 * @param Transport            $transport HTTP.
	 */
	public static function create( array $settings, array $secrets, Transport $transport ): Driver {
		return new self( $settings, isset( $secrets['secret_key'] ) ? $secrets['secret_key'] : '', $transport );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function secrets(): array {
		return array( 'secret_key' => 'secret access key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function info(): array {
		return array( 'presets' => self::PRESETS );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Raw input: preset, endpoint, region, bucket, folder, access_key,
	 *                     secret_key, path_style.
	 * @throws Storage_Exception On invalid input.
	 */
	public static function normalize( array $input ): array {
		$preset = isset( $input['preset'] ) ? (string) $input['preset'] : 'aws';
		if ( ! isset( self::PRESETS[ $preset ] ) ) {
			throw new Storage_Exception( __( 'Choose a storage provider.', 'siteferry' ), Storage_Exception::CONFIG );
		}

		$region = strtolower( trim( isset( $input['region'] ) ? (string) $input['region'] : '' ) );
		if ( '' === $region ) {
			$region = self::PRESETS[ $preset ]['region'];
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $region ) ) {
			throw new Storage_Exception( __( 'The region may contain only letters, digits and hyphens (for example us-east-1).', 'siteferry' ), Storage_Exception::CONFIG );
		}

		$endpoint = self::endpoint( trim( isset( $input['endpoint'] ) ? (string) $input['endpoint'] : '' ), $preset, $region );

		$bucket = trim( isset( $input['bucket'] ) ? (string) $input['bucket'] : '' );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{1,62}$/', $bucket ) || false !== strpos( $bucket, '..' ) ) {
			throw new Storage_Exception( __( 'Enter the bucket name (3 to 63 letters, digits, dots, hyphens or underscores).', 'siteferry' ), Storage_Exception::CONFIG );
		}

		$folder = trim( str_replace( '\\', '/', isset( $input['folder'] ) ? (string) $input['folder'] : 'siteferry' ), " /\t" );
		foreach ( '' === $folder ? array() : explode( '/', $folder ) as $segment ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}$/', $segment ) || '..' === $segment ) {
				throw new Storage_Exception( __( 'The folder may contain only letters, digits, ".", "-", "_" and "/" between folder names.', 'siteferry' ), Storage_Exception::CONFIG );
			}
		}

		$access = trim( isset( $input['access_key'] ) ? (string) $input['access_key'] : '' );
		if ( ! preg_match( '/^[\x21-\x7e]{1,128}$/', $access ) ) {
			throw new Storage_Exception( __( 'Enter the access key ID.', 'siteferry' ), Storage_Exception::CONFIG );
		}

		$secret = trim( isset( $input['secret_key'] ) ? (string) $input['secret_key'] : '' );
		if ( '' !== $secret && ! preg_match( '/^[\x21-\x7e]{1,256}$/', $secret ) ) {
			throw new Storage_Exception( __( 'The secret access key contains characters that are not allowed.', 'siteferry' ), Storage_Exception::CONFIG );
		}

		$path_style = array_key_exists( 'path_style', $input ) ? (bool) $input['path_style'] : self::PRESETS[ $preset ]['path_style'];
		if ( ! $path_style && false !== strpos( $bucket, '.' ) && 0 === strpos( $endpoint, 'https://' ) ) {
			throw new Storage_Exception( __( 'Bucket names with dots do not work with https in bucket.endpoint form. Tick "path-style requests".', 'siteferry' ), Storage_Exception::CONFIG );
		}

		return array(
			'settings' => array(
				'preset'     => $preset,
				'endpoint'   => $endpoint,
				'region'     => $region,
				'bucket'     => $bucket,
				'folder'     => $folder,
				'access_key' => $access,
				'path_style' => $path_style,
			),
			'secrets'  => array( 'secret_key' => '' === $secret ? null : $secret ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe(): string {
		$label = self::PRESETS[ $this->settings['preset'] ]['label'];
		return '' === $this->settings['folder']
			? sprintf( '%s bucket "%s"', $label, $this->settings['bucket'] )
			: sprintf( '%s bucket "%s", folder "%s"', $label, $this->settings['bucket'], $this->settings['folder'] );
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
	 * The checks of test().
	 *
	 * @throws Storage_Exception Naming the check that failed.
	 */
	private function run_test(): array {
		Test_Progress::total( 4 );
		$name  = '.siteferry-test-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		$key   = $this->key( $name );
		$body  = 'SiteFerry connection test, ' . gmdate( 'Y-m-d H:i:s' ) . " UTC.\n";
		$lines = array();

		$this->check(
			__( 'Writing a test file', 'siteferry' ),
			function () use ( $key, $body ) {
				$this->call( 'PUT', $key, array(), $body, array( 'Content-Type' => 'text/plain' ) );
			}
		);
		$lines[] = sprintf( /* translators: %s: where it is stored, such as Dropbox */ __( 'Wrote a test file to %s.', 'siteferry' ), $this->describe() );

		$this->check(
			__( 'Reading the test file back', 'siteferry' ),
			function () use ( $key, $body ) {
				$response = $this->call( 'GET', $key );
				if ( $response['body'] !== $body ) {
					throw new Storage_Exception( __( 'The file read back differs from what was written.', 'siteferry' ), Storage_Exception::REMOTE );
				}
			}
		);
		$lines[] = __( 'Read it back unchanged.', 'siteferry' );

		$count = $this->check(
			__( 'Listing the folder', 'siteferry' ),
			function (): int {
				return count( $this->list() );
			}
		);
		$lines[] = sprintf( /* translators: %d: a number */ __( 'Listed the folder: %d SiteFerry backup(s).', 'siteferry' ), $count );

		$this->check(
			__( 'Deleting the test file', 'siteferry' ),
			function () use ( $key ) {
				$this->call( 'DELETE', $key, array(), '', array(), array( 204, 200 ) );
			}
		);
		$lines[] = __( 'Deleted the test file.', 'siteferry' );
		return $lines;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $size File size.
	 */
	public function part_size( int $size ): int {
		$part   = self::PART_SIZE;
		$needed = (int) ceil( $size / self::MAX_PARTS );
		if ( $needed > $part ) {
			$part = (int) ( ceil( $needed / 1048576 ) * 1048576 );
		}
		return max( self::MIN_PART, $part );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name File name.
	 * @param int    $size File size.
	 * @throws Storage_Exception If the upload cannot be started.
	 */
	public function begin( string $name, int $size ): array {
		$key      = $this->key( $name );
		$type     = '.gz' === substr( $name, -3 ) ? 'application/gzip' : 'application/x-tar';
		$response = $this->call( 'POST', $key, array( 'uploads' => '' ), '', array( 'Content-Type' => $type ) );
		$xml      = self::xml( $response['body'] );
		$id       = null === $xml ? '' : (string) $xml->UploadId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( '' === $id ) {
			throw new Storage_Exception( __( 'The storage did not start the upload (no upload ID in its answer).', 'siteferry' ), Storage_Exception::REMOTE );
		}
		return array(
			'key'       => $key,
			'upload_id' => $id,
			'size'      => $size,
			'part_size' => $this->part_size( $size ),
			'etags'     => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $state Upload state.
	 * @param int    $index Part number from 0.
	 * @param string $bytes Data.
	 * @throws Storage_Exception If the part is refused.
	 */
	public function put_part( array $state, int $index, string $bytes ): array {
		if ( $index < 0 || $index >= self::MAX_PARTS ) {
			throw new Storage_Exception( __( 'Too many parts for one upload.', 'siteferry' ), Storage_Exception::REMOTE );
		}
		$response = $this->call(
			'PUT',
			$state['key'],
			array(
				'partNumber' => (string) ( $index + 1 ),
				'uploadId'   => $state['upload_id'],
			),
			$bytes,
			array( 'Content-Type' => 'application/octet-stream' )
		);
		$etag = isset( $response['headers']['etag'] ) ? trim( $response['headers']['etag'] ) : '';
		if ( '' === $etag ) {
			throw new Storage_Exception( __( 'The storage accepted a part but did not return its ETag.', 'siteferry' ), Storage_Exception::REMOTE );
		}
		$state['etags'][ $index ] = $etag;
		return $state;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $state Upload state.
	 * @throws Storage_Exception If the upload cannot be completed or the stored size differs.
	 */
	public function finish( array $state ): void {
		$count = max( 1, (int) ceil( $state['size'] / $state['part_size'] ) );
		$parts = '';
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! isset( $state['etags'][ $i ] ) ) {
				throw new Storage_Exception( sprintf( /* translators: %d: i + 1 */ __( 'Part %d of the upload is missing.', 'siteferry' ), $i + 1 ), Storage_Exception::REMOTE );
			}
			$parts .= sprintf( '<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>', $i + 1, htmlspecialchars( $state['etags'][ $i ], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) );
		}
		$body = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload xmlns="' . self::XMLNS . '">' . $parts . '</CompleteMultipartUpload>';

		$this->call(
			'POST',
			$state['key'],
			array( 'uploadId' => $state['upload_id'] ),
			$body,
			array( 'Content-Type' => 'application/xml' ),
			array( 200 ),
			function ( array $response ) {
				// S3 can answer 200 and still report an error in the body; such errors are retried.
				// Anything but a completion document means the upload did not complete.
				$xml = self::xml( $response['body'] );
				if ( null !== $xml && 'Error' === $xml->getName() ) {
					throw $this->error( array( 'status' => 500 ) + $response );
				}
				if ( null === $xml || 'CompleteMultipartUploadResult' !== $xml->getName() ) {
					throw new Storage_Exception( __( 'The storage sent an unexpected answer when completing the upload.', 'siteferry' ), Storage_Exception::REMOTE );
				}
			}
		);

		$stored = $this->stored_size( $state['key'] );
		if ( $stored !== (int) $state['size'] ) {
			throw new Storage_Exception( sprintf( /* translators: 1: a number, 2: a number */ __( 'The stored file has %1$d bytes instead of %2$d.', 'siteferry' ), $stored, $state['size'] ), Storage_Exception::REMOTE );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $state Upload state.
	 */
	public function abort( array $state ): void {
		$this->call( 'DELETE', $state['key'], array( 'uploadId' => $state['upload_id'] ), '', array(), array( 204, 200, 404 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws Storage_Exception If the listing fails.
	 */
	public function list(): array {
		$prefix = '' === $this->settings['folder'] ? '' : $this->settings['folder'] . '/';
		$items  = array();
		$token  = null;
		$pages  = 0;
		do {
			$query = array(
				'list-type' => '2',
				'prefix'    => $prefix,
				'delimiter' => '/',
			);
			if ( null !== $token ) {
				$query['continuation-token'] = $token;
			}
			$xml = self::xml( $this->call( 'GET', '', $query )['body'] );
			if ( null === $xml || 'ListBucketResult' !== $xml->getName() ) {
				throw new Storage_Exception( __( 'The storage sent an unexpected answer to a listing.', 'siteferry' ), Storage_Exception::REMOTE );
			}
			foreach ( $xml->Contents as $object ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$key  = (string) $object->Key; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$name = (string) substr( $key, strlen( $prefix ) );
				if ( 0 === strpos( $key, $prefix ) && Parts::stored_name( $name ) ) {
					$items[] = array(
						'name'     => $name,
						'size'     => (int) (string) $object->Size, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						'modified' => (int) strtotime( (string) $object->LastModified ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					);
				}
			}
			$truncated = 'true' === strtolower( (string) $xml->IsTruncated ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$token     = $truncated ? (string) $xml->NextContinuationToken : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		} while ( null !== $token && '' !== $token && ++$pages < 1000 );

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
	 * @throws Storage_Exception If the range cannot be read.
	 */
	public function download_range( string $name, int $offset, int $length ): string {
		$response = $this->call( 'GET', $this->key( $name ), array(), '', array( 'Range' => sprintf( 'bytes=%d-%d', $offset, $offset + $length - 1 ) ), array( 206, 200 ) );
		if ( 200 === $response['status'] ) {
			// The service ignored the range and sent the whole file.
			return (string) substr( $response['body'], $offset, $length );
		}
		$range = isset( $response['headers']['content-range'] ) ? $response['headers']['content-range'] : '';
		if ( ! preg_match( '/^bytes ' . $offset . '-/', $range ) ) {
			throw new Storage_Exception( __( 'The storage sent a different part of the file than requested.', 'siteferry' ), Storage_Exception::REMOTE );
		}
		return $response['body'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name File name.
	 */
	public function delete( string $name ): void {
		$this->call( 'DELETE', $this->key( $name ), array(), '', array(), array( 204, 200 ) );
	}

	/**
	 * Size of a stored object, read with a one-byte range request (no HEAD, which some proxies and
	 * test servers handle badly).
	 *
	 * @param string $key Object key.
	 * @throws Storage_Exception If the object is missing.
	 */
	private function stored_size( string $key ): int {
		$response = $this->call( 'GET', $key, array(), '', array( 'Range' => 'bytes=0-0' ), array( 206, 200 ) );
		if ( 206 === $response['status'] && isset( $response['headers']['content-range'] ) && preg_match( '#/(\d+)$#', $response['headers']['content-range'], $match ) ) {
			return (int) $match[1];
		}
		return strlen( $response['body'] );
	}

	/**
	 * Object key for a backup name.
	 *
	 * @param string $name File name.
	 */
	private function key( string $name ): string {
		return '' === $this->settings['folder'] ? $name : $this->settings['folder'] . '/' . $name;
	}

	/**
	 * Request URL for an object key ('' for the bucket itself).
	 *
	 * @param string               $key   Object key.
	 * @param array<string,string> $query Query parameters ('' values are sent without "=").
	 */
	public function url( string $key, array $query = array() ): string {
		$endpoint = (array) parse_url( $this->settings['endpoint'] );
		$host     = $endpoint['host'] . ( isset( $endpoint['port'] ) ? ':' . $endpoint['port'] : '' );
		$path     = '' === $key ? '' : '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
		if ( $this->settings['path_style'] ) {
			$path = '/' . rawurlencode( $this->settings['bucket'] ) . $path;
		} else {
			$host = $this->settings['bucket'] . '.' . $host;
			$path = '' === $path ? '/' : $path;
		}

		ksort( $query, SORT_STRING );
		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[] = '' === $value ? rawurlencode( $name ) : rawurlencode( $name ) . '=' . rawurlencode( $value );
		}
		return $endpoint['scheme'] . '://' . $host . $path . ( array() === $pairs ? '' : '?' . implode( '&', $pairs ) );
	}

	/**
	 * Sends a signed request, retrying temporary failures.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $key    Object key ('' for the bucket).
	 * @param array<string,string> $query  Query parameters.
	 * @param string               $body   Body.
	 * @param array<string,string> $extra  More headers (signed as well).
	 * @param int[]                $ok     Accepted status codes.
	 * @param callable|null        $check  Further check of an accepted response; may throw.
	 * @return array{status:int,headers:array,body:string}
	 * @throws Storage_Exception When the request fails for good.
	 */
	private function call( string $method, string $key, array $query = array(), string $body = '', array $extra = array(), array $ok = array( 200 ), ?callable $check = null ): array {
		$timeout = strlen( $body ) > 1048576 || isset( $extra['Range'] ) ? self::TIMEOUT : self::SHORT_TIMEOUT;
		$attempt = function () use ( $method, $key, $query, $body, $extra, $ok, $check, $timeout ): array {
			$url     = $this->url( $key, $query );
			$hash    = hash( 'sha256', $body );
			$date    = gmdate( 'Ymd\THis\Z', (int) call_user_func( $this->clock ) );
			$headers = $extra + array(
				'x-amz-content-sha256' => $hash,
				'x-amz-date'           => $date,
			);

			$headers['Authorization'] = $this->signer->sign( $method, $url, $headers, $hash, $date )['authorization'];
			$response                 = $this->transport->request( $method, $url, $headers, $body, $timeout );
			if ( ! in_array( $response['status'], $ok, true ) ) {
				throw $this->error( $response );
			}
			if ( null !== $check ) {
				$check( $response );
			}
			return $response;
		};
		return $this->retry ? Retry::run( $attempt, $this->sleep ) : $attempt();
	}

	/**
	 * Turns an error response into an exception with a readable message.
	 *
	 * @param array{status:int,headers:array,body:string} $response Response.
	 */
	private function error( array $response ): Storage_Exception {
		$status  = (int) $response['status'];
		$xml     = self::xml( $response['body'] );
		$code    = null === $xml ? '' : (string) $xml->Code; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$message = null === $xml ? '' : (string) $xml->Message; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$hints = array(
			'SignatureDoesNotMatch'       => 'Check the secret access key.',
			'InvalidAccessKeyId'          => 'Check the access key ID.',
			'AccessDenied'                => 'The key has no permission for this bucket or action.',
			'NoSuchBucket'                => 'Check the bucket name, region and endpoint.',
			'RequestTimeTooSkewed'        => 'This server\'s clock is wrong; S3 accepts a difference of up to 15 minutes.',
			'AuthorizationHeaderMalformed' => 'Check the region.',
			'PermanentRedirect'           => 'The bucket is in another region; check the region and endpoint.',
			'NoSuchUpload'                => 'The unfinished upload no longer exists on the storage.',
		);
		$hint = isset( $hints[ $code ] ) ? $hints[ $code ] : '';
		if ( '' === $hint && in_array( $status, array( 301, 307 ), true ) ) {
			$hint = 'The storage redirected the request: check the region, the endpoint and the path-style setting.';
		}

		$transient = $status >= 500 || 429 === $status || in_array( $code, array( 'RequestTimeout', 'SlowDown', 'InternalError', 'ServiceUnavailable' ), true );
		if ( $transient ) {
			$kind = Storage_Exception::TRANSIENT;
		} elseif ( 401 === $status || 403 === $status ) {
			$kind = Storage_Exception::AUTH;
		} elseif ( 404 === $status ) {
			$kind = Storage_Exception::NOT_FOUND;
		} else {
			$kind = Storage_Exception::REMOTE;
		}

		$text = sprintf( 'The storage answered HTTP %d', $status ) . ( '' === $code ? '' : ' ' . $code );
		$text .= '' === $message ? '.' : ': ' . $message;
		$text  = (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $text );
		$text  = strlen( $text ) > 400 ? substr( $text, 0, 400 ) . '...' : $text;
		return new Storage_Exception( trim( $text . ' ' . $hint ), $kind );
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
		return Test_Progress::check( $label, $callback ); // Reported live to the admin page.
	}

	/**
	 * Parses an XML answer. Documents with a DOCTYPE or entities are refused (no XXE, no entity
	 * expansion); network access while parsing is off. Leading whitespace is dropped: S3 sends
	 * spaces to keep the connection open while a large upload is being completed.
	 *
	 * @param string $body Response body.
	 */
	private static function xml( string $body ): ?\SimpleXMLElement {
		$body = ltrim( $body );
		if ( '' === $body || false !== stripos( $body, '<!DOCTYPE' ) || false !== stripos( $body, '<!ENTITY' ) || ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return false === $xml ? null : $xml;
	}

	/**
	 * Endpoint URL from input, or from the preset's template.
	 *
	 * @param string $raw    Input: host, host:port or a URL.
	 * @param string $preset Preset id.
	 * @param string $region Region.
	 * @throws Storage_Exception If it is missing or invalid.
	 */
	private static function endpoint( string $raw, string $preset, string $region ): string {
		$template = self::PRESETS[ $preset ]['endpoint'];
		if ( '' === $raw || $raw === $template ) {
			if ( '' === $template || false !== strpos( $template, '<' ) ) {
				throw new Storage_Exception( __( 'Enter the endpoint of the storage service.', 'siteferry' ), Storage_Exception::CONFIG );
			}
			$raw = str_replace( '{region}', $region, $template );
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}
		$parts  = parse_url( rtrim( $raw, '/' ) );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['path'] ) && '' !== $parts['path'] ) ) {
			throw new Storage_Exception( __( 'Enter the endpoint as a host name, such as s3.example.com (no path).', 'siteferry' ), Storage_Exception::CONFIG );
		}
		if ( 'http' === $scheme && 'custom' !== $preset ) {
			throw new Storage_Exception( __( 'Use https for this provider.', 'siteferry' ), Storage_Exception::CONFIG );
		}
		return $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
