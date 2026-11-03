<?php
/**
 * Pack/list/extract round trips: plain and gzip, single pass and sliced with simulated crashes.
 *
 * Run: php tests/test-tar-roundtrip.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Resumable_Hash;
use SiteFerry\Archive\Tar_Reader;

echo "TAR round-trip tests\n";

$src = Sf_Test::temp_dir( 'src' );
sf_make_fixture( $src );
$expected = sf_tree( $src );
$work     = Sf_Test::temp_dir( 'work' );
$plain    = $work . '/single.tar';
$gz       = $work . '/single.tar.gz';

/**
 * Checks that every file checksum reported by the writer or extractor matches the source tree.
 *
 * @param array $expected Source tree.
 * @param array $reported name => result with 'sha256'.
 * @param string $what    Label.
 */
function sf_check_reported( array $expected, array $reported, string $what ): void {
	$files = array_filter(
		$expected,
		static function ( array $item ): bool {
			return 'file' === $item['type'];
		}
	);
	foreach ( $files as $path => $item ) {
		Sf_Test::ok( isset( $reported[ $path ] ), "$what: no result for $path" );
		Sf_Test::same( $item['sha256'], $reported[ $path ]['sha256'], "$what checksum of $path:" );
		if ( isset( $reported[ $path ]['changed'] ) ) {
			Sf_Test::same( false, $reported[ $path ]['changed'], "$what changed flag of $path:" );
		}
	}
}

Sf_Test::run(
	'fixture has the required path shapes',
	static function () use ( $expected ) {
		$paths = sf_fixture_paths();
		Sf_Test::same( 100, strlen( $paths['exactly_100'] ) );
		Sf_Test::same( 186, strlen( $paths['split_186'] ) );
		Sf_Test::ok( strlen( $paths['over_255'] ) > 255, 'deep path should exceed 255 bytes' );
		foreach ( $paths as $label => $path ) {
			Sf_Test::ok( isset( $expected[ $path ] ), "fixture is missing $label" );
		}
		Sf_Test::same( 0, $expected['wp-content/empty.txt']['size'] );
		Sf_Test::same( 'dir', $expected['wp-content/empty-dir']['type'] );
	}
);

Sf_Test::run(
	'plain TAR: listing matches the source tree; writer checksums are right',
	static function () use ( $src, $plain, $expected ) {
		$packed = sf_pack( $src, $plain );
		Sf_Test::same( $expected, sf_list( $plain ) );
		sf_check_reported( $expected, $packed['results'], 'writer' );
		Sf_Test::same( 0, filesize( $plain ) % 10240, 'record padding:' );
	}
);

Sf_Test::run(
	'plain TAR: extraction reproduces the tree, including empty files and folders',
	static function () use ( $plain, $expected ) {
		$out      = Sf_Test::temp_dir( 'out-plain' );
		$unpacked = sf_unpack( $plain, $out );
		Sf_Test::same( $expected, sf_tree( $out ) );
		sf_check_reported( $expected, $unpacked['entries'], 'extractor' );
	}
);

Sf_Test::run(
	'tar.gz: many gzip members, readable by zlib, same TAR stream as the plain archive',
	static function () use ( $src, $gz, $plain, $expected ) {
		sf_pack( $src, $gz, array( 'compress' => true, 'block_size' => 1048576 ) );
		Sf_Test::ok( Tar_Reader::is_gzip( $gz ), 'expected gzip magic' );
		$members = sf_count_gzip_members( $gz );
		Sf_Test::ok( $members >= 10, "expected at least 10 gzip members, got $members" );
		Sf_Test::same( hash_file( 'sha256', $plain ), sf_gunzip_sha256( $gz ), 'decompressed stream:' );
		Sf_Test::same( $expected, sf_list( $gz ) );
	}
);

Sf_Test::run(
	'tar.gz: extraction reproduces the tree',
	static function () use ( $gz, $expected ) {
		$out      = Sf_Test::temp_dir( 'out-gz' );
		$unpacked = sf_unpack( $gz, $out );
		Sf_Test::same( $expected, sf_tree( $out ) );
		sf_check_reported( $expected, $unpacked['entries'], 'extractor' );
	}
);

Sf_Test::run(
	'tar.gz: a saved read position resumes from its gzip member, not from the start',
	static function () use ( $gz, $src ) {
		$reader = Tar_Reader::open( $gz );
		do {
			$entry = $reader->next();
		} while ( 'wp-content/random.bin' !== $entry->name );
		$head  = $reader->read( 3 * 1048576 + 5 );
		$state = json_decode( (string) json_encode( $reader->state() ), true );
		$reader->close();

		$input = $state['input'];
		Sf_Test::ok( $input['member_offset'] > 0, 'member offset should be past the start' );
		Sf_Test::ok( $input['position'] - $input['member_position'] < 1048576 + 512, 're-inflation should stay within one member' );

		$reader = Tar_Reader::open( $gz, $state );
		Sf_Test::same( 'wp-content/random.bin', $reader->entry()->name );
		$rest = '';
		while ( '' !== ( $chunk = $reader->read( 1048576 ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$rest .= $chunk;
		}
		$reader->close();
		Sf_Test::same( hash_file( 'sha256', $src . '/wp-content/random.bin' ), hash( 'sha256', $head . $rest ) );
	}
);

Sf_Test::run(
	'Resumable_Hash: saved, replayed and lost states',
	static function () {
		$replay_abc = static function ( int $length, callable $feed ): bool {
			$feed( substr( 'abc', 0, $length ) );
			return true;
		};

		$hash = new Resumable_Hash();
		$hash->update( 'abc' );
		$restored = Resumable_Hash::import( json_decode( (string) json_encode( $hash->export() ), true ), $replay_abc );
		$restored->update( 'def' );
		Sf_Test::same( hash( 'sha256', 'abcdef' ), $restored->hex(), 'default path:' );

		$restored = Resumable_Hash::import( $hash->export( false ), $replay_abc );
		$restored->update( 'def' );
		Sf_Test::same( hash( 'sha256', 'abcdef' ), $restored->hex(), 'replay path:' );

		$refuse = static function (): bool {
			return false;
		};
		Sf_Test::same( null, Resumable_Hash::import( $hash->export( false ), $refuse )->hex(), 'replay refused:' );
		Sf_Test::same( null, Resumable_Hash::import( $hash->export( false ) )->hex(), 'no replay:' );

		$short = static function ( int $length, callable $feed ): bool {
			$feed( 'ab' );
			return true;
		};
		Sf_Test::same( null, Resumable_Hash::import( $hash->export( false ), $short )->hex(), 'short replay:' );
		Sf_Test::same( PHP_VERSION_ID >= 80000, Resumable_Hash::can_serialize(), 'can_serialize on PHP ' . PHP_VERSION . ':' );
	}
);

foreach ( array( 'saved hash state' => true, 'replayed hash state (PHP 7.4 path)' => false ) as $label => $serialize ) {
	Sf_Test::run(
		"plain TAR packed in 256 KiB slices with crashes, $label: byte-identical to one pass",
		static function () use ( $src, $work, $plain, $expected, $serialize ) {
			$file   = $work . '/sliced-' . (int) $serialize . '.tar';
			$packed = sf_pack( $src, $file, array( 'serialize_hash' => $serialize ), 262144, true );
			Sf_Test::ok( $packed['slices'] >= 10, 'expected many slices, got ' . $packed['slices'] );
			Sf_Test::same( hash_file( 'sha256', $plain ), hash_file( 'sha256', $file ) );
			sf_check_reported( $expected, $packed['results'], 'writer' );
		}
	);

	Sf_Test::run(
		"tar.gz packed in 256 KiB slices with crashes, $label: same TAR stream",
		static function () use ( $src, $work, $plain, $expected, $serialize ) {
			$file   = $work . '/sliced-' . (int) $serialize . '.tar.gz';
			$packed = sf_pack( $src, $file, array( 'compress' => true, 'serialize_hash' => $serialize ), 262144, true );
			Sf_Test::same( hash_file( 'sha256', $plain ), sf_gunzip_sha256( $file ) );
			Sf_Test::same( $expected, sf_list( $file ) );
			sf_check_reported( $expected, $packed['results'], 'writer' );
		}
	);

	Sf_Test::run(
		"plain and gzip extracted in 300 KB slices with crashes, $label",
		static function () use ( $plain, $gz, $expected, $serialize ) {
			foreach ( array( $plain, $gz ) as $archive ) {
				$out      = Sf_Test::temp_dir( 'out-sliced' );
				$unpacked = sf_unpack( $archive, $out, array( 'serialize_hash' => $serialize ), 300000, true );
				Sf_Test::ok( $unpacked['slices'] >= 10, 'expected many slices, got ' . $unpacked['slices'] );
				Sf_Test::same( $expected, sf_tree( $out ), basename( $archive ) . ':' );
				sf_check_reported( $expected, $unpacked['entries'], 'extractor' );
			}
		}
	);
}

exit( Sf_Test::finish( 'test-tar-roundtrip' ) );
