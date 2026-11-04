<?php
/**
 * A small S3 look-alike for tests: objects, multipart uploads and listings in a folder, with
 * SigV4 signatures and payload hashes checked. Used in-process (Fake_S3_Transport) and over HTTP
 * (tests/tools/fake-s3-server.php under "php -S").
 *
 * It applies the stricter rules of real services: parts other than the last must be at least
 * min_part bytes and all of the same size (Cloudflare R2), and CompleteMultipartUpload must list
 * every part with its ETag.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Storage\Sigv4;
use SiteFerry\Storage\Storage_Exception;
use SiteFerry\Storage\Transport;

/**
 * The fake service.
 */
final class Fake_S3 {

	/**
	 * Data folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Settings: access_key, secret_key, region, page_size, min_part, base_host.
	 *
	 * @var array
	 */
	public $config;

	/**
	 * Status codes to answer the next requests with (fault injection).
	 *
	 * @var int[]
	 */
	public $fail_next = array();

	/**
	 * Answer the next CompleteMultipartUpload with 200 and an error document.
	 *
	 * @var bool
	 */
	public $complete_error_once = false;

	/**
	 * Answer the next CompleteMultipartUpload with 200 and something that is not XML.
	 *
	 * @var bool
	 */
	public $complete_garbage_once = false;

	/**
	 * Whitespace sent before the CompleteMultipartUpload result, as S3 does for long completions.
	 *
	 * @var string
	 */
	public $complete_padding = '';

	/**
	 * Requests handled: method and URI.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public $log = array();

	/**
	 * Constructor.
	 *
	 * @param string $dir    Data folder (created).
	 * @param array  $config Settings.
	 */
	public function __construct( string $dir, array $config ) {
		$this->dir    = rtrim( $dir, '/\\' );
		$this->config = $config + array(
			'region'    => 'us-east-1',
			'page_size' => 1000,
			'min_part'  => 5242880,
			'base_host' => '',
			'buckets'   => array( 'sftest' ),
		);
		foreach ( array( '', '/objects', '/uploads' ) as $sub ) {
			if ( ! is_dir( $this->dir . $sub ) ) {
				mkdir( $this->dir . $sub, 0755, true );
			}
		}
	}

	/**
	 * Handles one request.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $uri     Path and query as sent.
	 * @param array<string,string> $headers Headers (lower-case names), including host.
	 * @param string               $body    Body.
	 * @return array{status:int,headers:array<string,string>,body:string}
	 */
	public function handle( string $method, string $uri, array $headers, string $body ): array {
		$this->log[] = array( $method, $uri );
		if ( array() !== $this->fail_next ) {
			$status = array_shift( $this->fail_next );
			return $this->error( $status, 503 === $status ? 'SlowDown' : 'InternalError', 'Injected failure.' );
		}

		$denied = $this->check_signature( $method, $uri, $headers, $body );
		if ( null !== $denied ) {
			return $denied;
		}

		$path  = (string) parse_url( $uri, PHP_URL_PATH );
		$query = array();
		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $query );
		$raw_query = (string) parse_url( $uri, PHP_URL_QUERY );

		// Bucket from the host (virtual-hosted) or the first path segment (path-style).
		$host = strtolower( preg_replace( '/:\d+$/', '', isset( $headers['host'] ) ? $headers['host'] : '' ) );
		$base = strtolower( $this->config['base_host'] );
		if ( '' !== $base && strlen( $host ) > strlen( $base ) && substr( $host, -strlen( $base ) - 1 ) === '.' . $base ) {
			$bucket = substr( $host, 0, -strlen( $base ) - 1 );
			$key    = rawurldecode( ltrim( $path, '/' ) );
		} else {
			$segments = explode( '/', ltrim( $path, '/' ), 2 );
			$bucket   = rawurldecode( $segments[0] );
			$key      = isset( $segments[1] ) ? rawurldecode( $segments[1] ) : '';
		}
		if ( ! in_array( $bucket, $this->config['buckets'], true ) ) {
			return $this->error( 404, 'NoSuchBucket', 'The specified bucket does not exist' );
		}

		if ( '' === $key ) {
			if ( 'GET' === $method && isset( $query['list-type'] ) ) {
				return $this->list_objects( $bucket, $query );
			}
			return $this->error( 400, 'NotImplemented', 'Only ListObjectsV2 is supported on buckets.' );
		}

		if ( 'POST' === $method && preg_match( '/(^|&)uploads(=|&|$)/', $raw_query ) ) {
			return $this->create_upload( $bucket, $key );
		}
		if ( isset( $query['uploadId'] ) ) {
			$upload = $this->upload_dir( (string) $query['uploadId'] );
			if ( null === $upload ) {
				return $this->error( 404, 'NoSuchUpload', 'The specified upload does not exist.' );
			}
			if ( 'PUT' === $method && isset( $query['partNumber'] ) ) {
				return $this->put_part( $upload, (int) $query['partNumber'], $body );
			}
			if ( 'POST' === $method ) {
				return $this->complete( $upload, $bucket, $key, $body );
			}
			if ( 'DELETE' === $method ) {
				sf_remove_tree( $upload );
				return array( 'status' => 204, 'headers' => array(), 'body' => '' );
			}
		}

		$file = $this->object_file( $bucket, $key );
		switch ( $method ) {
			case 'PUT':
				$this->store( $bucket, $key, $body );
				return array( 'status' => 200, 'headers' => array( 'etag' => '"' . md5( $body ) . '"' ), 'body' => '' );
			case 'GET':
				return $this->get( $file, isset( $headers['range'] ) ? $headers['range'] : '' );
			case 'DELETE':
				foreach ( array( $file, $file . '.json' ) as $path_to_delete ) {
					if ( is_file( $path_to_delete ) ) {
						unlink( $path_to_delete );
					}
				}
				return array( 'status' => 204, 'headers' => array(), 'body' => '' );
		}
		return $this->error( 405, 'MethodNotAllowed', 'Not supported by the fake.' );
	}

	/**
	 * Unfinished multipart uploads.
	 */
	public function pending_uploads(): int {
		return count( glob( $this->dir . '/uploads/*', GLOB_ONLYDIR ) );
	}

	/**
	 * Stored object keys of a bucket.
	 *
	 * @param string $bucket Bucket.
	 * @return string[]
	 */
	public function keys( string $bucket = 'sftest' ): array {
		$keys = array();
		foreach ( glob( $this->dir . '/objects/*.json' ) as $meta ) {
			$data = json_decode( (string) file_get_contents( $meta ), true );
			if ( $data['bucket'] === $bucket ) {
				$keys[] = $data['key'];
			}
		}
		sort( $keys );
		return $keys;
	}

	/**
	 * Contents of a stored object.
	 *
	 * @param string $key    Key.
	 * @param string $bucket Bucket.
	 */
	public function read( string $key, string $bucket = 'sftest' ): ?string {
		$file = $this->object_file( $bucket, $key );
		return is_file( $file ) ? (string) file_get_contents( $file ) : null;
	}

	/**
	 * Checks the Authorization header and the payload hash.
	 *
	 * @param string $method  Method.
	 * @param string $uri     URI.
	 * @param array  $headers Headers.
	 * @param string $body    Body.
	 * @return array|null An error response, or null if the request is properly signed.
	 */
	private function check_signature( string $method, string $uri, array $headers, string $body ): ?array {
		$auth = isset( $headers['authorization'] ) ? $headers['authorization'] : '';
		if ( ! preg_match( '#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([a-f0-9]{64})$#', $auth, $m ) ) {
			return $this->error( 403, 'AccessDenied', 'Missing or malformed Authorization header.' );
		}
		if ( $m[1] !== $this->config['access_key'] ) {
			return $this->error( 403, 'InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.' );
		}
		if ( $m[3] !== $this->config['region'] ) {
			return $this->error( 400, 'AuthorizationHeaderMalformed', sprintf( "The authorization header is malformed; the region '%s' is wrong; expecting '%s'", $m[3], $this->config['region'] ) );
		}
		$date = isset( $headers['x-amz-date'] ) ? $headers['x-amz-date'] : '';
		$time = DateTime::createFromFormat( 'Ymd\THis\Z', $date, new DateTimeZone( 'UTC' ) );
		if ( false === $time || abs( $time->getTimestamp() - time() ) > 900 ) {
			return $this->error( 403, 'RequestTimeTooSkewed', 'The difference between the request time and the current time is too large.' );
		}
		$hash = isset( $headers['x-amz-content-sha256'] ) ? $headers['x-amz-content-sha256'] : '';
		if ( ! hash_equals( hash( 'sha256', $body ), $hash ) ) {
			return $this->error( 400, 'XAmzContentSHA256Mismatch', 'The provided x-amz-content-sha256 header does not match what was computed.' );
		}

		$signed = array();
		foreach ( explode( ';', $m[4] ) as $name ) {
			if ( ! isset( $headers[ $name ] ) ) {
				return $this->error( 403, 'AccessDenied', 'A signed header is missing: ' . $name );
			}
			$signed[ $name ] = $headers[ $name ];
		}
		$signer   = new Sigv4( $this->config['access_key'], $this->config['secret_key'], $this->config['region'], 's3' );
		$expected = $signer->sign( $method, 'http://' . $headers['host'] . $uri, $signed, $hash, $date );
		if ( ! hash_equals( $expected['signature'], $m[5] ) ) {
			return $this->error( 403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided. Check your key and signing method.' );
		}
		return null;
	}

	/**
	 * ListObjectsV2.
	 *
	 * @param string $bucket Bucket.
	 * @param array  $query  Query.
	 */
	private function list_objects( string $bucket, array $query ): array {
		$prefix    = isset( $query['prefix'] ) ? (string) $query['prefix'] : '';
		$delimiter = isset( $query['delimiter'] ) ? (string) $query['delimiter'] : '';
		$after     = isset( $query['continuation-token'] ) ? (string) base64_decode( (string) $query['continuation-token'] ) : '';
		$limit     = (int) $this->config['page_size'];

		$contents = '';
		$count    = 0;
		$last     = '';
		$more     = false;
		foreach ( $this->keys( $bucket ) as $key ) {
			if ( 0 !== strpos( $key, $prefix ) || ( '' !== $after && strcmp( $key, $after ) <= 0 ) ) {
				continue;
			}
			if ( '' !== $delimiter && false !== strpos( substr( $key, strlen( $prefix ) ), $delimiter ) ) {
				continue; // Inside a "subfolder" (a CommonPrefix); not needed by SiteFerry.
			}
			if ( $count >= $limit ) {
				$more = true;
				break;
			}
			$meta      = json_decode( (string) file_get_contents( $this->object_file( $bucket, $key ) . '.json' ), true );
			$contents .= sprintf(
				'<Contents><Key>%s</Key><LastModified>%s</LastModified><ETag>&quot;%s&quot;</ETag><Size>%d</Size><StorageClass>STANDARD</StorageClass></Contents>',
				htmlspecialchars( $key, ENT_XML1 ),
				gmdate( 'Y-m-d\TH:i:s.000\Z', $meta['modified'] ),
				$meta['md5'],
				$meta['size']
			);
			++$count;
			$last = $key;
		}
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Name>' . htmlspecialchars( $bucket, ENT_XML1 ) . '</Name>'
			. '<Prefix>' . htmlspecialchars( $prefix, ENT_XML1 ) . '</Prefix><KeyCount>' . $count . '</KeyCount><MaxKeys>' . $limit . '</MaxKeys>'
			. '<IsTruncated>' . ( $more ? 'true' : 'false' ) . '</IsTruncated>'
			. ( $more ? '<NextContinuationToken>' . base64_encode( $last ) . '</NextContinuationToken>' : '' )
			. $contents . '</ListBucketResult>';
		return array( 'status' => 200, 'headers' => array( 'content-type' => 'application/xml' ), 'body' => $xml );
	}

	/**
	 * CreateMultipartUpload.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 */
	private function create_upload( string $bucket, string $key ): array {
		$id  = bin2hex( random_bytes( 16 ) );
		$dir = $this->dir . '/uploads/' . $id;
		mkdir( $dir );
		file_put_contents( $dir . '/meta.json', json_encode( array( 'bucket' => $bucket, 'key' => $key ) ) );
		$xml = '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>'
			. htmlspecialchars( $bucket, ENT_XML1 ) . '</Bucket><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><UploadId>' . $id . '</UploadId></InitiateMultipartUploadResult>';
		return array( 'status' => 200, 'headers' => array(), 'body' => $xml );
	}

	/**
	 * UploadPart.
	 *
	 * @param string $upload Upload folder.
	 * @param int    $number Part number (1-based).
	 * @param string $body   Data.
	 */
	private function put_part( string $upload, int $number, string $body ): array {
		if ( $number < 1 || $number > 10000 ) {
			return $this->error( 400, 'InvalidArgument', 'Part number must be an integer between 1 and 10000.' );
		}
		file_put_contents( $upload . '/part-' . $number, $body );
		return array( 'status' => 200, 'headers' => array( 'etag' => '"' . md5( $body ) . '"' ), 'body' => '' );
	}

	/**
	 * CompleteMultipartUpload.
	 *
	 * @param string $upload Upload folder.
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param string $body   XML listing the parts.
	 */
	private function complete( string $upload, string $bucket, string $key, string $body ): array {
		if ( $this->complete_error_once ) {
			$this->complete_error_once = false;
			return array( 'status' => 200, 'headers' => array(), 'body' => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>We encountered an internal error. Please try again.</Message></Error>' );
		}
		if ( $this->complete_garbage_once ) {
			$this->complete_garbage_once = false;
			return array( 'status' => 200, 'headers' => array(), 'body' => 'Fatal error: something broke' );
		}
		$xml = simplexml_load_string( $body );
		if ( false === $xml ) {
			return $this->error( 400, 'MalformedXML', 'The XML you provided was not well-formed.' );
		}
		$files = array();
		$sizes = array();
		$next  = 1;
		foreach ( $xml->Part as $part ) {
			$number = (int) $part->PartNumber;
			$file   = $upload . '/part-' . $number;
			if ( $number !== $next++ || ! is_file( $file ) || '"' . md5_file( $file ) . '"' !== (string) $part->ETag ) {
				return $this->error( 400, 'InvalidPart', 'One or more of the specified parts could not be found or the ETag did not match.' );
			}
			$files[] = $file;
			$sizes[] = (int) filesize( $file );
		}
		if ( array() === $sizes ) {
			return $this->error( 400, 'MalformedXML', 'No parts.' );
		}
		$all_but_last = array_slice( $sizes, 0, -1 );
		foreach ( $all_but_last as $size ) {
			if ( $size < $this->config['min_part'] ) {
				return $this->error( 400, 'EntityTooSmall', 'Your proposed upload is smaller than the minimum allowed object size.' );
			}
			if ( $size !== $all_but_last[0] ) {
				return $this->error( 400, 'InvalidPart', 'All non-trailing parts must have the same length.' );
			}
		}
		if ( array() !== $all_but_last && end( $sizes ) > $all_but_last[0] ) {
			return $this->error( 400, 'InvalidPart', 'The last part is larger than the others.' );
		}
		// Joined on disk, part by part, so large objects do not need memory.
		$joined = $upload . '/joined';
		$out    = fopen( $joined, 'wb' );
		foreach ( $files as $file ) {
			$in = fopen( $file, 'rb' );
			stream_copy_to_stream( $in, $out );
			fclose( $in );
		}
		fclose( $out );
		$this->store_file( $bucket, $key, $joined );
		sf_remove_tree( $upload );
		$xml = $this->complete_padding . '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><Bucket>'
			. htmlspecialchars( $bucket, ENT_XML1 ) . '</Bucket><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key><ETag>&quot;' . md5( implode( ',', $sizes ) ) . '-' . count( $sizes ) . '&quot;</ETag></CompleteMultipartUploadResult>';
		return array( 'status' => 200, 'headers' => array(), 'body' => $xml );
	}

	/**
	 * SHA-256 of a stored object, or null.
	 *
	 * @param string $key    Key.
	 * @param string $bucket Bucket.
	 */
	public function hash( string $key, string $bucket = 'sftest' ): ?string {
		$file = $this->object_file( $bucket, $key );
		return is_file( $file ) ? hash_file( 'sha256', $file ) : null;
	}

	/**
	 * GetObject with an optional single range.
	 *
	 * @param string $file  Object file.
	 * @param string $range Range header.
	 */
	private function get( string $file, string $range ): array {
		if ( ! is_file( $file ) ) {
			return $this->error( 404, 'NoSuchKey', 'The specified key does not exist.' );
		}
		$size = (int) filesize( $file );
		if ( preg_match( '/^bytes=(\d+)-(\d+)$/', $range, $m ) ) {
			$start = (int) $m[1];
			$end   = min( (int) $m[2], $size - 1 );
			if ( $start >= $size || $start > $end ) {
				return $this->error( 416, 'InvalidRange', 'The requested range is not satisfiable' );
			}
			$handle = fopen( $file, 'rb' );
			fseek( $handle, $start );
			$body = (string) fread( $handle, $end - $start + 1 );
			fclose( $handle );
			return array(
				'status'  => 206,
				'headers' => array( 'content-range' => sprintf( 'bytes %d-%d/%d', $start, $end, $size ) ),
				'body'    => $body,
			);
		}
		return array( 'status' => 200, 'headers' => array(), 'body' => (string) file_get_contents( $file ) );
	}

	/**
	 * Saves an object.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param string $data   Data.
	 */
	private function store( string $bucket, string $key, string $data ): void {
		$temp = $this->dir . '/objects/incoming-' . bin2hex( random_bytes( 6 ) );
		file_put_contents( $temp, $data );
		$this->store_file( $bucket, $key, $temp );
	}

	/**
	 * Saves an object from a file (which is moved).
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 * @param string $source File with the data.
	 */
	private function store_file( string $bucket, string $key, string $source ): void {
		$file = $this->object_file( $bucket, $key );
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		rename( $source, $file );
		file_put_contents(
			$file . '.json',
			json_encode(
				array(
					'bucket'   => $bucket,
					'key'      => $key,
					'size'     => (int) filesize( $file ),
					'md5'      => md5_file( $file ),
					'modified' => time(),
				)
			)
		);
	}

	/**
	 * File that holds an object.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Key.
	 */
	private function object_file( string $bucket, string $key ): string {
		return $this->dir . '/objects/' . sha1( $bucket . "\n" . $key );
	}

	/**
	 * Folder of an unfinished upload, or null.
	 *
	 * @param string $id Upload id.
	 */
	private function upload_dir( string $id ): ?string {
		$dir = $this->dir . '/uploads/' . $id;
		return preg_match( '/^[a-f0-9]{32}$/', $id ) && is_dir( $dir ) ? $dir : null;
	}

	/**
	 * An S3 error document.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    S3 error code.
	 * @param string $message Message.
	 */
	private function error( int $status, string $code, string $message ): array {
		return array(
			'status'  => $status,
			'headers' => array( 'content-type' => 'application/xml' ),
			'body'    => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>' . $code . '</Code><Message>' . htmlspecialchars( $message, ENT_XML1 ) . '</Message><RequestId>fake</RequestId></Error>',
		);
	}
}

/**
 * Transport that hands requests to a Fake_S3 in the same process, as an HTTP client would send
 * them (Host header from the URL). Can also simulate network failures.
 */
final class Fake_S3_Transport implements Transport {

	/**
	 * The fake.
	 *
	 * @var Fake_S3
	 */
	public $s3;

	/**
	 * Requests to fail with a network error before they reach the fake.
	 *
	 * @var int
	 */
	public $drop_next = 0;

	/**
	 * Headers of the requests sent.
	 *
	 * @var array<int,array{method:string,url:string,headers:array}>
	 */
	public $sent = array();

	/**
	 * Constructor.
	 *
	 * @param Fake_S3 $s3 The fake.
	 */
	public function __construct( Fake_S3 $s3 ) {
		$this->s3 = $s3;
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
		$parts = parse_url( $url );
		$lower = array( 'host' => Sigv4::host( $parts ) );
		foreach ( $headers as $name => $value ) {
			$lower[ strtolower( $name ) ] = $value;
		}
		$uri = $parts['path'] . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		return $this->s3->handle( $method, $uri, $lower, $body );
	}
}
