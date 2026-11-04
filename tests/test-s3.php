<?php
/**
 * S3 driver against an in-process fake S3 service (tests/lib/class-fake-s3.php), which checks
 * every signature and payload hash.
 *
 * Run: php tests/test-s3.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/class-fake-s3.php';

use SiteFerry\Storage\Retry;
use SiteFerry\Storage\S3_Driver;
use SiteFerry\Storage\Storage_Exception;

echo "S3 driver tests\n";

const SF_FAKE_ACCESS = 'SFTESTACCESSKEY0001';
const SF_FAKE_SECRET = 'sf/test+secret/0123456789abcdefghijABCDEFGHIJ';

/**
 * A driver connected to a fresh fake service.
 *
 * @param array         $settings Settings overrides (before normalize()).
 * @param array         $fake     Fake config overrides.
 * @param array|null    $delays   Receives retry delays.
 * @param callable|null $clock    Clock.
 * @return array{0:S3_Driver,1:Fake_S3_Transport}
 */
function sf_s3( array $settings = array(), array $fake = array(), ?array &$delays = null, ?callable $clock = null ): array {
	$s3        = new Fake_S3(
		Sf_Test::temp_dir( 'fake-s3' ),
		$fake + array(
			'access_key' => SF_FAKE_ACCESS,
			'secret_key' => SF_FAKE_SECRET,
			'base_host'  => 's3.fake.test',
		)
	);
	$transport = new Fake_S3_Transport( $s3 );
	$normal    = S3_Driver::normalize(
		$settings + array(
			'preset'     => 'custom',
			'endpoint'   => 'https://s3.fake.test',
			'region'     => 'us-east-1',
			'bucket'     => 'sftest',
			'folder'     => 'site/backups',
			'access_key' => SF_FAKE_ACCESS,
			'secret_key' => SF_FAKE_SECRET,
		)
	);
	$delays = array();
	$sleep  = static function ( float $seconds ) use ( &$delays ) {
		$delays[] = $seconds;
	};
	$secret = isset( $settings['secret_key'] ) ? $settings['secret_key'] : SF_FAKE_SECRET;
	return array( new S3_Driver( $normal['settings'], $secret, $transport, $sleep, $clock ), $transport );
}

/**
 * A valid little SiteFerry-style payload of a given size.
 *
 * @param int $size Bytes.
 */
function sf_payload( int $size ): string {
	$data = '';
	while ( strlen( $data ) < $size ) {
		$data .= hash( 'sha256', (string) strlen( $data ), true ) . random_bytes( 4064 );
	}
	return substr( $data, 0, $size );
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
 * A private method of S3_Driver, callable from the tests.
 *
 * @param string $name Method name.
 */
function sf_private( string $name ): ReflectionMethod {
	$method = new ReflectionMethod( S3_Driver::class, $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$method->setAccessible( true );
	}
	return $method;
}

/**
 * Sends a whole file through begin/put_part/finish.
 *
 * @param S3_Driver $driver Driver.
 * @param string    $name   Name.
 * @param string    $data   Data.
 */
function sf_send( S3_Driver $driver, string $name, string $data ): array {
	$state = $driver->begin( $name, strlen( $data ) );
	$part  = $state['part_size'];
	for ( $i = 0, $offset = 0; $offset < strlen( $data ); $i++, $offset += $part ) {
		$state = $driver->put_part( $state, $i, substr( $data, $offset, $part ) );
	}
	$driver->finish( $state );
	return $state;
}

Sf_Test::run(
	'settings: presets, endpoints, validation',
	static function () {
		$aws = S3_Driver::normalize( array( 'preset' => 'aws', 'region' => 'eu-west-1', 'bucket' => 'my-bucket', 'access_key' => 'AKIA', 'secret_key' => ' s3cret ' ) );
		Sf_Test::same( 'https://s3.eu-west-1.amazonaws.com', $aws['settings']['endpoint'] );
		Sf_Test::same( false, $aws['settings']['path_style'] );
		Sf_Test::same( 'siteferry', $aws['settings']['folder'], 'default folder:' );
		Sf_Test::same( 's3cret', $aws['secrets']['secret_key'], 'secret trimmed:' );
		Sf_Test::ok( ! isset( $aws['settings']['secret_key'] ), 'the secret is not among the settings' );

		$b2 = S3_Driver::normalize( array( 'preset' => 'b2', 'region' => 'us-west-004', 'bucket' => 'MyBucket', 'access_key' => 'k', 'secret_key' => '' ) );
		Sf_Test::same( 'https://s3.us-west-004.backblazeb2.com', $b2['settings']['endpoint'] );
		Sf_Test::same( null, $b2['secrets']['secret_key'], 'empty secret means "keep":' );

		$spaces = S3_Driver::normalize( array( 'preset' => 'spaces', 'region' => 'ams3', 'bucket' => 'b-1', 'access_key' => 'k', 'folder' => '/a/b/' ) );
		Sf_Test::same( 'https://ams3.digitaloceanspaces.com', $spaces['settings']['endpoint'] );
		Sf_Test::same( 'a/b', $spaces['settings']['folder'] );

		$r2 = S3_Driver::normalize( array( 'preset' => 'r2', 'endpoint' => 'https://0123abcd.r2.cloudflarestorage.com/', 'bucket' => 'b-1', 'access_key' => 'k' ) );
		Sf_Test::same( 'auto', $r2['settings']['region'] );
		Sf_Test::same( true, $r2['settings']['path_style'] );
		Sf_Test::same( 'https://0123abcd.r2.cloudflarestorage.com', $r2['settings']['endpoint'] );

		$minio = S3_Driver::normalize( array( 'preset' => 'custom', 'endpoint' => 'http://127.0.0.1:9000', 'bucket' => 'b-1', 'access_key' => 'k' ) );
		Sf_Test::same( 'http://127.0.0.1:9000', $minio['settings']['endpoint'] );

		$bad = array(
			'R2 without account endpoint' => array( 'preset' => 'r2', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'R2 with the template'        => array( 'preset' => 'r2', 'endpoint' => '<account-id>.r2.cloudflarestorage.com', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'custom without endpoint'     => array( 'preset' => 'custom', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'http for AWS'                => array( 'preset' => 'aws', 'endpoint' => 'http://s3.amazonaws.com', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'endpoint with a path'        => array( 'preset' => 'custom', 'endpoint' => 'https://h.example/s3', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'endpoint with credentials'   => array( 'preset' => 'custom', 'endpoint' => 'https://u:p@h.example', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'ftp endpoint'                => array( 'preset' => 'custom', 'endpoint' => 'ftp://h.example', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'unknown preset'              => array( 'preset' => 'gcs', 'bucket' => 'b-1', 'access_key' => 'k' ),
			'bucket with a slash'         => array( 'bucket' => 'a/b', 'access_key' => 'k' ),
			'short bucket'                => array( 'bucket' => 'a', 'access_key' => 'k' ),
			'folder with ..'              => array( 'bucket' => 'b-1', 'access_key' => 'k', 'folder' => 'a/../b' ),
			'folder with a space'         => array( 'bucket' => 'b-1', 'access_key' => 'k', 'folder' => 'my folder' ),
			'no access key'               => array( 'bucket' => 'b-1' ),
			'secret with a newline'       => array( 'bucket' => 'b-1', 'access_key' => 'k', 'secret_key' => "a\nb" ),
			'dotted bucket, https, vhost' => array( 'bucket' => 'my.bucket', 'access_key' => 'k', 'path_style' => false ),
			'region with spaces'          => array( 'bucket' => 'b-1', 'access_key' => 'k', 'region' => 'us east' ),
		);
		foreach ( $bad as $label => $input ) {
			try {
				S3_Driver::normalize( $input );
			} catch ( Storage_Exception $e ) {
				Sf_Test::same( Storage_Exception::CONFIG, $e->getCode(), $label );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . $label );
		}
	}
);

Sf_Test::run(
	'URLs: path-style and virtual-hosted, keys encoded once, sorted query',
	static function () {
		list( $path ) = sf_s3( array( 'path_style' => true ) );
		Sf_Test::same( 'https://s3.fake.test/sftest/site/backups/a%20b.tar?partNumber=1&uploadId=x%2By', $path->url( 'site/backups/a b.tar', array( 'uploadId' => 'x+y', 'partNumber' => '1' ) ) );
		Sf_Test::same( 'https://s3.fake.test/sftest?list-type=2&prefix=site%2F', $path->url( '', array( 'prefix' => 'site/', 'list-type' => '2' ) ) );
		list( $vhost ) = sf_s3( array( 'path_style' => false ) );
		Sf_Test::same( 'https://sftest.s3.fake.test/site/backups/x.tar?uploads', $vhost->url( 'site/backups/x.tar', array( 'uploads' => '' ) ) );
		Sf_Test::same( 'https://sftest.s3.fake.test/', $vhost->url( '' ) );
		list( $port ) = sf_s3( array( 'preset' => 'custom', 'endpoint' => 'http://127.0.0.1:9000' ) );
		Sf_Test::same( 'http://127.0.0.1:9000/sftest/site/backups/x.tar', $port->url( 'site/backups/x.tar' ) );
	}
);

Sf_Test::run(
	'connection test writes, reads, lists and deletes a test file (path-style and virtual-hosted)',
	static function () {
		foreach ( array( true, false ) as $path_style ) {
			list( $driver, $transport ) = sf_s3( array( 'path_style' => $path_style ) );
			$lines                      = $driver->test();
			Sf_Test::same( 4, count( $lines ) );
			Sf_Test::ok( false !== strpos( $lines[0], 'bucket "sftest", folder "site/backups"' ), $lines[0] );
			Sf_Test::same( array(), $transport->s3->keys(), 'test file removed:' );
			foreach ( $transport->sent as $request ) {
				Sf_Test::ok( false === strpos( json_encode( $request ), SF_FAKE_SECRET ), 'the secret never appears in a request' );
				Sf_Test::ok( 0 === strpos( $request['url'], $path_style ? 'https://s3.fake.test/sftest' : 'https://sftest.s3.fake.test/' ), $request['url'] );
			}
		}
	}
);

Sf_Test::run(
	'multipart upload: 3 parts of 5 MiB+, listing, ranged download, delete',
	static function () {
		list( $driver, $transport ) = sf_s3();
		$data  = sf_payload( 2 * S3_Driver::MIN_PART + 12345 );
		$name  = 'mysite-2026-11-04-0123456789ab.tar';
		$state = $driver->begin( $name, strlen( $data ) );
		Sf_Test::same( S3_Driver::PART_SIZE, $state['part_size'], 'default part size:' );

		// Force 5 MiB parts so the file has three.
		$state['part_size'] = S3_Driver::MIN_PART;
		$state              = $driver->put_part( $state, 0, substr( $data, 0, S3_Driver::MIN_PART ) );
		$state              = $driver->put_part( $state, 1, substr( $data, S3_Driver::MIN_PART, S3_Driver::MIN_PART ) );
		// Repeating a part (an interrupted slice) is harmless.
		$state = $driver->put_part( $state, 1, substr( $data, S3_Driver::MIN_PART, S3_Driver::MIN_PART ) );
		$state = $driver->put_part( $state, 2, substr( $data, 2 * S3_Driver::MIN_PART ) );
		$state = json_decode( json_encode( $state ), true ); // Survives the job file.
		$driver->finish( $state );
		Sf_Test::same( 0, $transport->s3->pending_uploads(), 'upload completed:' );
		Sf_Test::same( $data, $transport->s3->read( 'site/backups/' . $name ), 'stored bytes:' );

		$listed = $driver->list();
		Sf_Test::same( 1, count( $listed ) );
		Sf_Test::same( $name, $listed[0]['name'] );
		Sf_Test::same( strlen( $data ), $listed[0]['size'] );
		Sf_Test::ok( abs( $listed[0]['modified'] - time() ) < 60, 'modified time parsed' );

		$back = '';
		for ( $offset = 0; $offset < strlen( $data ); $offset += 3000000 ) {
			$back .= $driver->download_range( $name, $offset, (int) min( 3000000, strlen( $data ) - $offset ) );
		}
		Sf_Test::same( hash( 'sha256', $data ), hash( 'sha256', $back ), 'downloaded in ranges:' );

		$driver->delete( $name );
		Sf_Test::same( array(), $driver->list() );
	}
);

Sf_Test::run(
	'listing: pages, other folders and non-backup files are left out',
	static function () {
		list( $driver, $transport ) = sf_s3( array(), array( 'page_size' => 2 ) );
		$names = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$names[] = sprintf( 'site-2026-11-0%d-%012d.tar', $i + 1, $i );
			sf_send( $driver, end( $names ), 'x' . $i );
		}
		$put = sf_private( 'call' );
		foreach ( array( 'site/backups/notes.txt', 'site/backups/sub/inner-2026-01-01-000000000000.tar', 'site/elsewhere-2026-01-01-000000000000.tar', 'site/backups/evil..tar' ) as $key ) {
			$put->invoke( $driver, 'PUT', $key, array(), 'x' );
		}
		$listed = array_column( $driver->list(), 'name' );
		sort( $listed );
		Sf_Test::same( $names, $listed, 'five backups over three pages:' );
		$pages = 0;
		foreach ( $transport->s3->log as list( $method, $uri ) ) {
			$pages += ( 'GET' === $method && false !== strpos( $uri, 'list-type=2' ) ) ? 1 : 0;
		}
		Sf_Test::ok( $pages >= 3, 'continuation tokens followed: ' . $pages );
	}
);

Sf_Test::run(
	'part sizes: fixed, at least 5 MiB, growing to stay within 10,000 parts',
	static function () {
		list( $driver ) = sf_s3();
		Sf_Test::same( S3_Driver::PART_SIZE, $driver->part_size( 1 ) );
		Sf_Test::same( S3_Driver::PART_SIZE, $driver->part_size( 78 * 1024 * 1048576 ), '8 MiB parts cover up to 78 GiB:' );
		Sf_Test::same( 9 * 1048576, $driver->part_size( 80 * 1024 * 1048576 ), '80 GiB needs 9 MiB parts:' );
		$huge = 200 * 1024 * 1048576;
		$part = $driver->part_size( $huge );
		Sf_Test::ok( $part * S3_Driver::MAX_PARTS >= $huge && 0 === $part % 1048576, 'part size for 200 GiB: ' . $part );
	}
);

Sf_Test::run(
	'the service rejects what real services reject; the driver reports it',
	static function () {
		list( $driver, $transport ) = sf_s3();
		$state                      = $driver->begin( 'a-2026-01-01-000000000000.tar', 3 * 1048576 + 5 );
		$state['part_size']         = 1048576;
		for ( $i = 0; $i < 4; $i++ ) {
			$state = $driver->put_part( $state, $i, str_repeat( chr( 65 + $i ), 3 === $i ? 5 : 1048576 ) );
		}
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $driver, $state ) { $driver->finish( $state ); }, 'EntityTooSmall' );
		unset( $state['etags'][2] );
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $driver, $state ) { $driver->finish( $state ); }, 'Part 3 of the upload is missing' );

		$driver->abort( $state );
		Sf_Test::same( 0, $transport->s3->pending_uploads(), 'aborted:' );
		$driver->abort( $state ); // Aborting twice is harmless.
	}
);

Sf_Test::run(
	'errors: wrong secret, unknown key, wrong region, missing bucket, clock skew',
	static function () {
		list( $wrong ) = sf_s3( array( 'secret_key' => 'not-the-secret' ) );
		$e             = sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $wrong ) { $wrong->test(); }, 'SignatureDoesNotMatch' );
		Sf_Test::ok( false !== strpos( $e->getMessage(), 'Writing a test file failed' ) && false !== strpos( $e->getMessage(), 'Check the secret access key' ), $e->getMessage() );
		Sf_Test::ok( false === strpos( $e->getMessage(), 'not-the-secret' ), 'the secret is not in the message' );

		list( $unknown ) = sf_s3( array( 'access_key' => 'NOPE' ) );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $unknown ) { $unknown->list(); }, 'Check the access key ID' );

		list( $region ) = sf_s3( array( 'region' => 'eu-west-1' ) );
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $region ) { $region->list(); }, 'Check the region' );

		list( $bucket ) = sf_s3( array( 'bucket' => 'other-bucket' ) );
		sf_expect_storage_error( Storage_Exception::NOT_FOUND, static function () use ( $bucket ) { $bucket->list(); }, 'Check the bucket name' );

		list( $late ) = sf_s3( array(), array(), $delays, static function () {
			return time() - 3600;
		} );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $late ) { $late->list(); }, 'clock is wrong' );

		list( $missing ) = sf_s3();
		sf_expect_storage_error( Storage_Exception::NOT_FOUND, static function () use ( $missing ) { $missing->download_range( 'none-2026-01-01-000000000000.tar', 0, 10 ); }, 'NoSuchKey' );
	}
);

Sf_Test::run(
	'retries: throttling, server errors, network drops and a 200 with an error document',
	static function () {
		list( $driver, $transport ) = sf_s3( array(), array(), $delays );
		$transport->s3->fail_next   = array( 503, 500 );
		$transport->drop_next       = 1;
		Sf_Test::same( array(), $driver->list() );
		Sf_Test::same( 3, count( $delays ), 'three waits before the 4th attempt:' );
		Sf_Test::ok( $delays[0] >= 1 && $delays[1] >= 3 && $delays[2] >= 8 && $delays[2] <= 10, 'growing delays: ' . json_encode( $delays ) );

		$transport->s3->fail_next = array( 503, 503, 503, 503 );
		sf_expect_storage_error( Storage_Exception::TRANSIENT, static function () use ( $driver ) { $driver->list(); }, 'SlowDown' );

		// The connection test answers at once instead of retrying.
		$waits                    = count( $delays );
		$transport->s3->fail_next = array( 503 );
		sf_expect_storage_error( Storage_Exception::TRANSIENT, static function () use ( $driver ) { $driver->test(); }, 'Writing a test file failed' );
		Sf_Test::same( $waits, count( $delays ), 'no retry during test():' );
		Sf_Test::same( array(), $driver->list(), 'retries are back on afterwards:' );

		$transport->s3->complete_error_once = true;
		$data                               = 'small backup';
		sf_send( $driver, 'b-2026-01-01-000000000000.tar', $data );
		Sf_Test::same( $data, $transport->s3->read( 'site/backups/b-2026-01-01-000000000000.tar' ), 'completed after the retry:' );

		// Keep-alive spaces before the result are fine; a 200 that is not a result is not.
		$transport->s3->complete_padding = str_repeat( ' ', 2000 ) . "\n";
		sf_send( $driver, 'c-2026-01-01-000000000000.tar', $data );
		Sf_Test::same( $data, $transport->s3->read( 'site/backups/c-2026-01-01-000000000000.tar' ), 'completed despite leading spaces:' );
		$transport->s3->complete_garbage_once = true;
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $driver, $data ) { sf_send( $driver, 'd-2026-01-01-000000000000.tar', $data ); }, 'unexpected answer when completing' );

		// Permanent errors are not retried.
		$before = count( $transport->sent );
		list( $wrong ) = sf_s3( array( 'secret_key' => 'nope' ) );
		$count         = count( $transport->sent );
		try {
			$wrong->list();
		} catch ( Storage_Exception $e ) {
			Sf_Test::same( Storage_Exception::AUTH, $e->getCode() );
		}
		Sf_Test::same( $count, count( $transport->sent ), 'other driver, other transport:' );
		Sf_Test::ok( $before === $count, 'no extra request on the first transport' );
	}
);

Sf_Test::run(
	'XML with a DOCTYPE or entities is not parsed',
	static function () {
		$xml = sf_private( 'xml' );
		Sf_Test::same( null, $xml->invoke( null, '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Error><Code>&e;</Code></Error>' ) );
		Sf_Test::same( null, $xml->invoke( null, 'not xml' ) );
		Sf_Test::same( 'Error', $xml->invoke( null, '<Error><Code>X</Code></Error>' )->getName() );
	}
);

Sf_Test::run(
	'retry helper gives up after the last delay and passes permanent errors through',
	static function () {
		$calls  = 0;
		$waited = array();
		try {
			Retry::run(
				static function () use ( &$calls ) {
					++$calls;
					throw new Storage_Exception( 'boom', Storage_Exception::TRANSIENT );
				},
				static function ( float $s ) use ( &$waited ) {
					$waited[] = $s;
				}
			);
		} catch ( Storage_Exception $e ) {
			Sf_Test::same( 'boom', $e->getMessage() );
		}
		Sf_Test::same( 1 + count( Retry::DELAYS ), $calls, 'attempts:' );
		$calls = 0;
		try {
			Retry::run(
				static function () use ( &$calls ) {
					++$calls;
					throw new Storage_Exception( 'denied', Storage_Exception::AUTH );
				},
				static function () {}
			);
		} catch ( Storage_Exception $e ) {
			Sf_Test::same( 1, $calls, 'permanent errors are not retried:' );
		}
	}
);

exit( Sf_Test::finish( 'test-s3' ) );
