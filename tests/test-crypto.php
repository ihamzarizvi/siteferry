<?php
/**
 * Encrypted backups, the parts that need no WordPress: the password check, archives written
 * through the encrypting stream in slices (with junk from dead requests cut off) and read back
 * plain and compressed, random access, and every kind of tampering refused.
 *
 * Run: php tests/test-crypto.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Crypto;
use SiteFerry\Archive\File_Input;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Jobs\Slice_Budget;

echo "Encryption tests\n";

if ( ! Crypto::available() ) {
	Sf_Test::skip( 'OpenSSL with AES-256-GCM is not available in this PHP build' );
	exit( Sf_Test::finish( 'test-crypto' ) );
}

$dir    = Sf_Test::temp_dir( 'crypto' );
$rounds = 10000; // Fewer than real backups use, to keep the tests quick.

/**
 * Writes the fixture tree into an encrypted archive in slices, as the export does: each slice
 * reopens the writer from its checkpoint; with $crash, junk is appended to the real file after
 * each slice (as a request that died after its checkpoint would leave).
 *
 * @param string $root    Fixture folder.
 * @param string $file    Encrypted file to create.
 * @param string $header  Header.
 * @param string $key     Key.
 * @param array  $options Tar writer options.
 * @param int    $slice   Bytes per slice.
 * @param bool   $crash   Append junk after each slice.
 * @return int Slices.
 */
function sf_write_encrypted( string $root, string $file, string $header, string $key, array $options, int $slice, bool $crash = false ): int {
	$url    = Crypto::url( $file, $key, $header );
	$items  = sf_items( $root );
	$state  = null;
	$cursor = 0;
	$slices = 0;
	for ( ;; ) {
		++$slices;
		$writer = null === $state ? Tar_Writer::create( $url, $options ) : Tar_Writer::resume( $url, $state );
		$budget = new Slice_Budget( null, $slice );
		$paused = $writer->has_pending() && null === $writer->continue_file( $budget );
		while ( ! $paused && $cursor < count( $items ) && ! $budget->exhausted() ) {
			list( $relative, $is_dir ) = $items[ $cursor ];
			++$cursor;
			if ( $is_dir ) {
				$writer->add_directory( $relative, 1700000000 );
			} elseif ( null === $writer->add_file( $root . '/' . $relative, $relative, $budget ) ) {
				$paused = true;
			}
		}
		if ( ! $paused && $cursor >= count( $items ) ) {
			$writer->finish();
			Crypto::seal( $file, $key );
			return $slices;
		}
		$state = json_decode( (string) json_encode( $writer->checkpoint() ), true );
		$writer->close();
		if ( $crash ) {
			file_put_contents( $file, random_bytes( 777 ), FILE_APPEND );
		}
	}
}

/**
 * Every entry of an archive: name => sha256 (folders: "dir").
 *
 * @param string $path Archive (or wrapper URL).
 */
function sf_read_all( string $path ): array {
	$reader = Tar_Reader::open( $path );
	$out    = array();
	try {
		while ( null !== ( $entry = $reader->next() ) ) {
			$hash = hash_init( 'sha256' );
			while ( '' !== ( $data = $reader->read( 65536 ) ) ) {
				hash_update( $hash, $data );
			}
			$out[ rtrim( $entry->name, '/' ) ] = $entry->is_dir() ? 'dir' : hash_final( $hash );
		}
	} finally {
		$reader->close();
	}
	ksort( $out );
	return $out;
}

$root = $dir . '/tree';
mkdir( $root );
sf_make_fixture( $root );
file_put_contents( $root . '/big.bin', random_bytes( 5 * 1048576 + 333 ) );
$plain = $dir . '/plain.tar';
sf_pack( $root, $plain );
$expected = sf_read_all( $plain );

Sf_Test::run(
	'the password check: right, wrong, and a changed header',
	static function () use ( $rounds ) {
		list( $header, $key ) = Crypto::create( 'correct horse battery', $rounds );
		Sf_Test::same( Crypto::HEADER_BYTES, strlen( $header ) );
		Sf_Test::same( $key, Crypto::key( $header, 'correct horse battery' ) );
		$e = Sf_Test::throws(
			Archive_Exception::LOCKED,
			static function () use ( $header ) {
				Crypto::key( $header, 'correct horse batterY' );
			}
		);
		Sf_Test::same( 'The password is wrong.', $e->getMessage() );
		$changed       = $header;
		$changed[ 20 ] = chr( ord( $changed[20] ) ^ 1 ); // In the salt.
		Sf_Test::throws(
			Archive_Exception::LOCKED,
			static function () use ( $changed ) {
				Crypto::key( $changed, 'correct horse battery' );
			}
		);
		$rounds_down      = $header;
		$rounds_down[ 10 ] = chr( ord( $rounds_down[10] ) + 1 );
		Sf_Test::throws(
			Archive_Exception::LOCKED,
			static function () use ( $rounds_down ) {
				Crypto::key( $rounds_down, 'correct horse battery' );
			},
			'fewer rounds written into the header'
		);
		list( $other ) = Crypto::create( 'correct horse battery', $rounds );
		Sf_Test::ok( $other !== $header, 'every file gets its own salt' );
	}
);

Sf_Test::run(
	'a .tar written in slices with junk after each one reads back exactly; the file is not plain',
	static function () use ( $dir, $root, $expected, $rounds ) {
		$file                 = $dir . '/slices.tar.enc';
		list( $header, $key ) = Crypto::create( 'pw-slices-123', $rounds );
		$slices               = sf_write_encrypted( $root, $file, $header, $key, array(), 300000, true );
		Sf_Test::ok( $slices > 10, 'slices: ' . $slices );
		Sf_Test::same( true, Crypto::is_encrypted( $file ) );
		Sf_Test::same( false, strpos( (string) file_get_contents( $file ), 'ustar' ), 'no TAR header in the clear:' );
		Sf_Test::same( $expected, sf_read_all( Crypto::url( $file, Crypto::unlock( $file, 'pw-slices-123' ) ) ) );
	}
);

Sf_Test::run(
	'a .tar.gz inside, and reading at any position',
	static function () use ( $dir, $root, $expected, $rounds, $plain ) {
		$file                 = $dir . '/compressed.tar.gz.enc';
		list( $header, $key ) = Crypto::create( 'pw-gz-12345', $rounds );
		sf_write_encrypted( $root, $file, $header, $key, array( 'compress' => true, 'block_size' => 262144 ), 700000 );
		$url = Crypto::url( $file, $key );
		Sf_Test::same( true, Tar_Reader::is_gzip( $url ), 'gzip inside:' );
		Sf_Test::same( $expected, sf_read_all( $url ) );

		// The same plain .tar encrypted in one go, then read at random places.
		$flat                 = $dir . '/flat.tar.enc';
		list( $header, $key ) = Crypto::create( 'pw-flat-1234', $rounds );
		$out                  = fopen( Crypto::url( $flat, $key, $header ), 'xb' );
		fwrite( $out, (string) file_get_contents( $plain ) );
		fclose( $out );
		Crypto::seal( $flat, $key );
		$data = (string) file_get_contents( $plain );
		foreach ( array( 0, 1, Crypto::CHUNK - 1, Crypto::CHUNK, 3 * Crypto::CHUNK + 17, strlen( $data ) - 5 ) as $at ) {
			$input = new File_Input( Crypto::url( $flat, $key ), array( 'position' => $at ) );
			Sf_Test::same( substr( $data, $at, 4096 ), $input->read( 4096 ), "at $at:" );
			$input->close();
		}
		$input = new File_Input( Crypto::url( $flat, $key ) );
		Sf_Test::same( strlen( $data ), $input->skip( PHP_INT_MAX >> 1 ), 'plain size:' );
		$input->close();
	}
);

Sf_Test::run(
	'tampering is refused: a changed byte, a cut file, swapped chunks, a chunk from another file',
	static function () use ( $dir, $plain, $rounds ) {
		$data                 = (string) file_get_contents( $plain );
		list( $header, $key ) = Crypto::create( 'pw-tamper-123', $rounds );
		$file                 = $dir . '/tamper.tar.enc';
		$out                  = fopen( Crypto::url( $file, $key, $header ), 'xb' );
		fwrite( $out, $data );
		fclose( $out );
		Crypto::seal( $file, $key );
		$good = (string) file_get_contents( $file );

		$read = static function ( string $bytes ) use ( $file, $key ) {
			file_put_contents( $file, $bytes );
			$input = new File_Input( Crypto::url( $file, $key ) );
			try {
				while ( '' !== $input->read( 1048576 ) ) {
					continue;
				}
			} finally {
				$input->close();
			}
		};

		$changed                                 = $good;
		$changed[ Crypto::HEADER_BYTES + 5000 ] = chr( ord( $changed[ Crypto::HEADER_BYTES + 5000 ] ) ^ 4 );
		Sf_Test::throws( Archive_Exception::CORRUPT, static function () use ( $read, $changed ) {
			$read( $changed );
		}, 'a changed byte' );

		Sf_Test::throws( Archive_Exception::TRUNCATED, static function () use ( $read, $good ) {
			$read( substr( $good, 0, -( Crypto::CHUNK_HEAD + Crypto::TAG ) ) );
		}, 'without the final chunk' );
		Sf_Test::throws( Archive_Exception::TRUNCATED, static function () use ( $read, $good ) {
			$read( substr( $good, 0, (int) ( strlen( $good ) / 2 ) ) );
		}, 'cut in the middle' );

		$first  = Crypto::HEADER_BYTES;
		$size   = Crypto::CHUNK_HEAD + Crypto::CHUNK + Crypto::TAG;
		$second = $first + $size;
		$swap   = substr( $good, 0, $first ) . substr( $good, $second, $size ) . substr( $good, $first, $size ) . substr( $good, $second + $size );
		Sf_Test::throws( Archive_Exception::CORRUPT, static function () use ( $read, $swap ) {
			$read( $swap );
		}, 'two chunks swapped' );

		list( $other_header ) = Crypto::create( 'pw-tamper-123', $rounds );
		Sf_Test::throws( Archive_Exception::CORRUPT, static function () use ( $read, $good, $other_header ) {
			$read( $other_header . substr( $good, Crypto::HEADER_BYTES ) );
		}, 'the chunks under another header' );

		$read( $good );
		Sf_Test::ok( true, 'the untouched file still reads' );
	}
);

Sf_Test::run(
	'sealing twice adds nothing; an unsealed file is refused; a complete one cannot be reopened for writing',
	static function () use ( $dir, $rounds ) {
		list( $header, $key ) = Crypto::create( 'pw-seal-12345', $rounds );
		$file                 = $dir . '/seal.tar.enc';
		$out                  = fopen( Crypto::url( $file, $key, $header ), 'xb' );
		fwrite( $out, str_repeat( 'x', 1000 ) );
		fclose( $out );
		Sf_Test::throws( Archive_Exception::TRUNCATED, static function () use ( $file, $key ) {
			new File_Input( Crypto::url( $file, $key ) );
		}, 'not sealed yet' );
		Crypto::seal( $file, $key );
		$size = filesize( $file );
		clearstatcache();
		Crypto::seal( $file, $key );
		clearstatcache();
		Sf_Test::same( $size, filesize( $file ) );
		$input = new File_Input( Crypto::url( $file, $key ) );
		Sf_Test::same( str_repeat( 'x', 1000 ), $input->read( 5000 ) );
		$input->close();
		Sf_Test::throws( Archive_Exception::BAD_STATE, static function () use ( $file, $key ) {
			fopen( Crypto::url( $file, $key ), 'r+b' );
		}, 'reopened for writing' );
	}
);

exit( Sf_Test::finish( 'test-crypto' ) );
