<?php
/**
 * TAR header encoding/decoding and reader robustness.
 *
 * Run: php tests/test-tar-header.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Tar_Header;
use SiteFerry\Archive\Tar_Reader;

echo "TAR header tests\n";

$dir = Sf_Test::temp_dir( 'header' );

Sf_Test::run(
	'pax record length counts itself across digit boundaries',
	static function () {
		foreach ( array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 90, 91, 92, 93, 94, 95, 96, 97, 98, 990, 991, 992, 993, 994, 995, 996, 997 ) as $length ) {
			$record = Tar_Header::pax_record( 'k', str_repeat( 'v', $length ) );
			$space  = strpos( $record, ' ' );
			Sf_Test::same( strlen( $record ), (int) substr( $record, 0, $space ), "value length $length:" );
		}
	}
);

Sf_Test::run(
	'pax records round trip, including "=", newlines and UTF-8 in values',
	static function () {
		$values = array(
			'path'    => "wp-content/a=b\nc/日本語 😀.txt",
			'size'    => '9663676416',
			'comment' => '',
		);
		$data   = '';
		foreach ( $values as $key => $value ) {
			$data .= Tar_Header::pax_record( $key, $value );
		}
		Sf_Test::same( $values, Tar_Header::parse_pax( $data ) );
	}
);

Sf_Test::run(
	'malformed pax data is rejected',
	static function () {
		foreach ( array( "abc path=x\n", "99 path=x\n", "12 path=x\nX", "9 =value\n", "10 pathx\nz" ) as $bad ) {
			Sf_Test::throws(
				Archive_Exception::CORRUPT,
				static function () use ( $bad ) {
					Tar_Header::parse_pax( $bad );
				},
				json_encode( $bad )
			);
		}
	}
);

Sf_Test::run(
	'ustar prefix/name splitting',
	static function () {
		Sf_Test::same( array( '', str_repeat( 'a', 100 ) ), Tar_Header::split_name( str_repeat( 'a', 100 ) ) );
		Sf_Test::same( null, Tar_Header::split_name( str_repeat( 'a', 101 ) ), 'no slash:' );
		$path = str_repeat( 'p', 155 ) . '/' . str_repeat( 'n', 100 );
		Sf_Test::same( array( str_repeat( 'p', 155 ), str_repeat( 'n', 100 ) ), Tar_Header::split_name( $path ), '256 bytes:' );
		Sf_Test::same( null, Tar_Header::split_name( 'x' . $path ), '257 bytes:' );
		Sf_Test::same( null, Tar_Header::split_name( str_repeat( 'p', 156 ) . '/' . str_repeat( 'n', 99 ) ), 'prefix too long:' );
		Sf_Test::same( null, Tar_Header::split_name( 'a/' . str_repeat( 'n', 101 ) ), 'name too long:' );
		$dir = str_repeat( 'd', 120 ) . '/' . str_repeat( 'e', 50 ) . '/';
		Sf_Test::same( array( str_repeat( 'd', 120 ), str_repeat( 'e', 50 ) . '/' ), Tar_Header::split_name( $dir ), 'directory:' );
	}
);

Sf_Test::run(
	'header block layout: size, magic, checksum',
	static function () {
		$header = Tar_Header::build( 'wp-content/a.txt', '0', 1234, 1700000000, 0644 );
		Sf_Test::same( 512, strlen( $header ) );
		Sf_Test::same( "ustar\0" . '00', substr( $header, 257, 8 ) );
		Sf_Test::same( sprintf( '%011o', 1234 ) . "\0", substr( $header, 124, 12 ) );
		$parsed = Tar_Header::parse( $header );
		Sf_Test::same( 'wp-content/a.txt', $parsed['name'] );
		Sf_Test::same( 1234, $parsed['size'] );
		Sf_Test::same( 1700000000, $parsed['mtime'] );
		Sf_Test::same( 0644, $parsed['mode'] );
		Sf_Test::same( '0', $parsed['type'] );
	}
);

Sf_Test::run(
	'pax is used only when needed',
	static function () {
		Sf_Test::same( 512, strlen( Tar_Header::build( str_repeat( 'a', 100 ), '0', 0, 0, 0644 ) ), '100-byte ASCII name:' );
		Sf_Test::same( 512, strlen( Tar_Header::build( str_repeat( 'a', 120 ) . '/' . str_repeat( 'b', 90 ), '0', 0, 0, 0644 ) ), 'splittable:' );
		Sf_Test::same( 'x', Tar_Header::build( str_repeat( 'a', 101 ), '0', 0, 0, 0644 )[156], 'unsplittable:' );
		Sf_Test::same( 'x', Tar_Header::build( 'café.txt', '0', 0, 0, 0644 )[156], 'non-ASCII:' );
		Sf_Test::same( 'x', Tar_Header::build( 'a.txt', '0', Tar_Header::MAX_OCTAL + 1, 0, 0644 )[156], '8 GiB:' );
		Sf_Test::same( '0', Tar_Header::build( 'a.txt', '0', Tar_Header::MAX_OCTAL, 0, 0644 )[156], '8 GiB - 1:' );
	}
);

Sf_Test::run(
	'checksum mismatch is detected',
	static function () {
		$header     = Tar_Header::build( 'a.txt', '0', 5, 0, 0644 );
		$header[10] = 'X';
		Sf_Test::throws(
			Archive_Exception::CORRUPT,
			static function () use ( $header ) {
				Tar_Header::parse( $header );
			}
		);
	}
);

Sf_Test::run(
	'GNU base-256 numbers are read; negative ones rejected',
	static function () {
		$size = 12884901888; // 12 GiB.
		Sf_Test::same( $size, Tar_Header::parse_number( "\x80\0\0\0" . pack( 'J', $size ) ) );
		Sf_Test::throws(
			Archive_Exception::CORRUPT,
			static function () {
				Tar_Header::parse_number( "\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xff\xfe" );
			}
		);
		Sf_Test::throws(
			Archive_Exception::CORRUPT,
			static function () {
				Tar_Header::parse_number( "12x45\0" );
			}
		);
	}
);

Sf_Test::run(
	'entries over 8 GiB use a pax size record (header only)',
	static function () use ( $dir ) {
		$size = 9663676416; // 9 GiB.
		$file = $dir . '/huge-header.tar';
		file_put_contents( $file, Tar_Header::build( 'wp-content/huge.bin', '0', $size, 1700000000, 0644 ) );
		$reader = Tar_Reader::open( $file );
		$entry  = $reader->next();
		Sf_Test::same( 'wp-content/huge.bin', $entry->name );
		Sf_Test::same( $size, $entry->size );
		Sf_Test::same( $size, $reader->remaining() );
		Sf_Test::throws(
			Archive_Exception::TRUNCATED,
			static function () use ( $reader ) {
				$reader->next();
			},
			'data missing:'
		);
		$reader->close();
	}
);

Sf_Test::run(
	'negative and huge mtimes round trip through pax',
	static function () use ( $dir ) {
		$file = $dir . '/mtime.tar';
		sf_write_raw_archive(
			$file,
			array(
				Tar_Header::build( 'old.txt', '0', 0, -86400, 0644 ),
				Tar_Header::build( 'future.txt', '0', 0, 99999999999, 0644 ),
			)
		);
		$reader = Tar_Reader::open( $file );
		Sf_Test::same( -86400, $reader->next()->mtime );
		Sf_Test::same( 99999999999, $reader->next()->mtime );
		Sf_Test::same( null, $reader->next() );
		$reader->close();
	}
);

Sf_Test::run(
	'GNU long names, base-256 sizes and global pax headers are understood',
	static function () use ( $dir ) {
		$long   = 'wp-content/' . str_repeat( 'L', 300 ) . '.txt';
		$sized  = sf_set_field( Tar_Header::build( 'b256.txt', '0', 0, 0, 0644 ), 124, "\x80" . str_repeat( "\0", 10 ) . "\x05" );
		$file   = $dir . '/gnu.tar';
		sf_write_raw_archive(
			$file,
			array(
				sf_raw_entry( 'pax_global_header', 'g', Tar_Header::pax_record( 'comment', 'ignored' ) ),
				sf_raw_entry( '././@LongLink', 'L', $long . "\0" ),
				sf_raw_entry( 'truncated-name.txt', '0', 'long' ),
				$sized . str_pad( 'five!', 512, "\0" ),
			)
		);
		$reader = Tar_Reader::open( $file );
		$entry  = $reader->next();
		Sf_Test::same( $long, $entry->name );
		Sf_Test::same( 'long', $reader->read( 100 ) );
		$entry = $reader->next();
		Sf_Test::same( 'b256.txt', $entry->name );
		Sf_Test::same( 'five!', $reader->read( 100 ) );
		Sf_Test::same( null, $reader->next() );
		$reader->close();
	}
);

Sf_Test::run(
	'old-style directory (regular entry ending in "/") is a directory',
	static function () use ( $dir ) {
		$file = $dir . '/v7.tar';
		sf_write_raw_archive( $file, array( sf_raw_entry( 'olddir/', '0' ) ) );
		$reader = Tar_Reader::open( $file );
		Sf_Test::ok( $reader->next()->is_dir(), 'expected a directory' );
		$reader->close();
	}
);

Sf_Test::run(
	'truncated archives are reported, not silently accepted',
	static function () use ( $dir ) {
		$full = sf_raw_entry( 'a.txt', '0', str_repeat( 'x', 2000 ) ) . str_repeat( "\0", 1024 );
		$cuts = array(
			'no end marker'   => strlen( $full ) - 1024,
			'inside data'     => 1000,
			'inside a header' => 300,
		);

		// A cut inside the second end-marker block loses no data and is accepted.
		$file = $dir . '/cut-marker.tar';
		file_put_contents( $file, substr( $full, 0, strlen( $full ) - 512 + 100 ) );
		Sf_Test::same( array( 'a.txt' ), array_keys( sf_list( $file ) ) );

		foreach ( $cuts as $label => $length ) {
			$file = $dir . '/cut-' . $length . '.tar';
			file_put_contents( $file, substr( $full, 0, $length ) );
			Sf_Test::throws(
				Archive_Exception::TRUNCATED,
				static function () use ( $file ) {
					sf_list( $file );
				},
				$label . ':'
			);
		}
	}
);

Sf_Test::run(
	'non-TAR input is rejected as corrupt',
	static function () use ( $dir ) {
		$file = $dir . '/junk.tar';
		file_put_contents( $file, str_repeat( 'not a tar file ', 100 ) );
		Sf_Test::throws(
			Archive_Exception::CORRUPT,
			static function () use ( $file ) {
				sf_list( $file );
			}
		);
		$file = $dir . '/junk.tar.gz';
		file_put_contents( $file, "\x1f\x8b" . str_repeat( 'garbage', 100 ) );
		Sf_Test::throws(
			Archive_Exception::CORRUPT,
			static function () use ( $file ) {
				sf_list( $file );
			}
		);
	}
);

Sf_Test::run(
	'oversized pax header is refused (memory bound)',
	static function () use ( $dir ) {
		$file   = $dir . '/bigpax.tar';
		$header = Tar_Header::build( 'PaxHeader/x', 'x', 2 * 1048576, 0, 0644 );
		sf_write_raw_archive( $file, array( $header . str_repeat( "\0", 2 * 1048576 ) ) );
		Sf_Test::throws(
			Archive_Exception::CORRUPT,
			static function () use ( $file ) {
				sf_list( $file );
			}
		);
	}
);

exit( Sf_Test::finish( 'test-tar-header' ) );
