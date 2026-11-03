<?php
/**
 * Source files that change, grow, shrink or vanish while they are being packed, and writer misuse.
 *
 * Run: php tests/test-tar-changes.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Jobs\Slice_Budget;

echo "TAR changing-file tests\n";

const SF_MIB = 1048576;

$dir = Sf_Test::temp_dir( 'changes' );

/**
 * Checksum the writer should report for a file that changed between slices.
 *
 * PHP 8 saves the hash state, so the checksum of the stored bytes is known. PHP 7.4 cannot, and
 * re-reading a changed file would hash different bytes, so the writer reports null instead.
 *
 * @param string $stored Bytes stored in the archive.
 */
function sf_checksum_after_change( string $stored ): ?string {
	return SiteFerry\Archive\Resumable_Hash::can_serialize() ? hash( 'sha256', $stored ) : null;
}

/**
 * Packs one 3 MiB file, pausing after the first MiB, runs $change on the source, then finishes.
 *
 * @param string   $dir       Work folder.
 * @param string   $label     Unique label.
 * @param callable $change    function( string $source, string $original ): void.
 * @param bool     $serialize Writer 'serialize_hash' option.
 * @return array{result:array,original:string,stored:string}
 */
function sf_pack_with_change( string $dir, string $label, callable $change, bool $serialize = true ): array {
	$source   = $dir . '/' . $label . '.bin';
	$archive  = $dir . '/' . $label . '.tar';
	$original = random_bytes( 3 * SF_MIB );
	file_put_contents( $source, $original );

	$writer = Tar_Writer::create( $archive, array( 'serialize_hash' => $serialize ) );
	Sf_Test::same( null, $writer->add_file( $source, 'wp-content/' . $label . '.bin', new Slice_Budget( null, SF_MIB ) ), 'first slice should pause:' );
	$state = json_decode( (string) json_encode( $writer->checkpoint() ), true );
	$writer->close();

	$change( $source, $original );

	$writer = Tar_Writer::resume( $archive, $state );
	$result = $writer->continue_file();
	$writer->finish();

	$listing = sf_list( $archive );
	$stored  = '';
	$reader  = SiteFerry\Archive\Tar_Reader::open( $archive );
	$reader->next();
	while ( '' !== ( $chunk = $reader->read( SF_MIB ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$stored .= $chunk;
	}
	$reader->close();
	Sf_Test::same( 3 * SF_MIB, $listing[ 'wp-content/' . $label . '.bin' ]['size'], 'stored size:' );

	return array(
		'result'   => $result,
		'original' => $original,
		'stored'   => $stored,
	);
}

Sf_Test::run(
	'file shrinks between slices: zero-padded to its header size, flagged, checksum of stored bytes',
	static function () use ( $dir ) {
		$run = sf_pack_with_change(
			$dir,
			'shrink',
			static function ( string $source, string $original ) {
				file_put_contents( $source, substr( $original, 0, (int) ( 1.5 * SF_MIB ) ) );
			}
		);
		$expected = substr( $run['original'], 0, (int) ( 1.5 * SF_MIB ) ) . str_repeat( "\0", (int) ( 1.5 * SF_MIB ) );
		Sf_Test::same( true, $run['result']['changed'] );
		Sf_Test::same( hash( 'sha256', $expected ), hash( 'sha256', $run['stored'] ), 'stored bytes:' );
		Sf_Test::same( sf_checksum_after_change( $expected ), $run['result']['sha256'], 'reported checksum:' );
	}
);

Sf_Test::run(
	'file grows between slices: original size stored, flagged',
	static function () use ( $dir ) {
		$run = sf_pack_with_change(
			$dir,
			'grow',
			static function ( string $source ) {
				file_put_contents( $source, random_bytes( SF_MIB ), FILE_APPEND );
			}
		);
		Sf_Test::same( true, $run['result']['changed'] );
		Sf_Test::same( hash( 'sha256', $run['original'] ), hash( 'sha256', $run['stored'] ), 'stored bytes:' );
		Sf_Test::same( sf_checksum_after_change( $run['original'] ), $run['result']['sha256'], 'reported checksum:' );
	}
);

Sf_Test::run(
	'file rewritten in place (same size, new time): flagged, checksum of the mixed bytes stored',
	static function () use ( $dir ) {
		$replacement = random_bytes( 3 * SF_MIB );
		$run         = sf_pack_with_change(
			$dir,
			'rewrite',
			static function ( string $source ) use ( $replacement ) {
				file_put_contents( $source, $replacement );
				touch( $source, time() + 100 );
			}
		);
		$expected = substr( $run['original'], 0, SF_MIB ) . substr( $replacement, SF_MIB );
		Sf_Test::same( true, $run['result']['changed'] );
		Sf_Test::same( hash( 'sha256', $expected ), hash( 'sha256', $run['stored'] ), 'stored bytes:' );
		Sf_Test::same( sf_checksum_after_change( $expected ), $run['result']['sha256'], 'reported checksum:' );
	}
);

Sf_Test::run(
	'file deleted between slices: rest zero-padded, flagged',
	static function () use ( $dir ) {
		$run      = sf_pack_with_change(
			$dir,
			'vanish',
			static function ( string $source ) {
				unlink( $source );
			}
		);
		$expected = substr( $run['original'], 0, SF_MIB ) . str_repeat( "\0", 2 * SF_MIB );
		Sf_Test::same( true, $run['result']['changed'] );
		Sf_Test::same( hash( 'sha256', $expected ), hash( 'sha256', $run['stored'] ), 'stored bytes:' );
		Sf_Test::same( sf_checksum_after_change( $expected ), $run['result']['sha256'], 'reported checksum:' );
	}
);

Sf_Test::run(
	'unchanged file across slices with replayed hash state: correct checksum, not flagged',
	static function () use ( $dir ) {
		$run = sf_pack_with_change(
			$dir,
			'steady',
			static function () {
			},
			false
		);
		Sf_Test::same( false, $run['result']['changed'] );
		Sf_Test::same( hash( 'sha256', $run['original'] ), $run['result']['sha256'] );
	}
);

Sf_Test::run(
	'changed file with replayed hash state: no checksum rather than a wrong one',
	static function () use ( $dir ) {
		$run = sf_pack_with_change(
			$dir,
			'grow-replay',
			static function ( string $source ) {
				file_put_contents( $source, 'more', FILE_APPEND );
			},
			false
		);
		Sf_Test::same( true, $run['result']['changed'] );
		Sf_Test::same( null, $run['result']['sha256'] );
		Sf_Test::same( hash( 'sha256', $run['original'] ), hash( 'sha256', $run['stored'] ), 'stored bytes:' );
	}
);

Sf_Test::run(
	'resume cuts off anything written after the checkpoint (plain and gzip)',
	static function () use ( $dir ) {
		foreach ( array( false, true ) as $compress ) {
			$ext       = $compress ? '.tar.gz' : '.tar';
			$reference = $dir . '/cut-reference' . $ext;
			$writer    = Tar_Writer::create( $reference, array( 'compress' => $compress ) );
			$writer->add_string( 'a.txt', 'alpha', 1 );
			$writer->add_string( 'b.txt', 'beta', 1 );
			$writer->finish();

			$resumed = $dir . '/cut-resumed' . $ext;
			$writer  = Tar_Writer::create( $resumed, array( 'compress' => $compress ) );
			$writer->add_string( 'a.txt', 'alpha', 1 );
			$state = json_decode( (string) json_encode( $writer->checkpoint() ), true );
			$writer->close();
			file_put_contents( $resumed, random_bytes( 5 * SF_MIB ), FILE_APPEND );

			$writer = Tar_Writer::resume( $resumed, $state );
			$writer->add_string( 'b.txt', 'beta', 1 );
			$writer->finish();

			if ( $compress ) {
				Sf_Test::same( 2, sf_count_gzip_members( $resumed ), 'gzip members:' );
				Sf_Test::same( sf_gunzip_sha256( $reference ), sf_gunzip_sha256( $resumed ), 'decompressed:' );
			} else {
				Sf_Test::same( hash_file( 'sha256', $reference ), hash_file( 'sha256', $resumed ), 'bytes:' );
			}
			Sf_Test::same( array( 'a.txt', 'b.txt' ), array_keys( sf_list( $resumed ) ) );
		}
	}
);

Sf_Test::run(
	'missing sources and folders are skipped without writing anything',
	static function () use ( $dir ) {
		$archive = $dir . '/skip.tar';
		$writer  = Tar_Writer::create( $archive );
		Sf_Test::same( 'missing', $writer->add_file( $dir . '/no-such-file', 'wp-content/x' )['skipped'] );
		Sf_Test::same( 'missing', $writer->add_file( $dir, 'wp-content/y' )['skipped'] );
		Sf_Test::same( 0, $writer->position() );
		$writer->finish();
		Sf_Test::same( array(), sf_list( $archive ) );
	}
);

Sf_Test::run(
	'writer refuses unsafe names',
	static function () use ( $dir ) {
		$writer = Tar_Writer::create( $dir . '/names.tar' );
		foreach ( array( '../x', 'a/../../x', '/etc/passwd', 'C:/x', "a\nb", "bad\xff", 'a\\b', '' ) as $name ) {
			Sf_Test::throws(
				Archive_Exception::UNSAFE_PATH,
				static function () use ( $writer, $name ) {
					$writer->add_string( $name, 'x' );
				},
				json_encode( bin2hex( $name ) )
			);
		}
		$writer->finish();
	}
);

Sf_Test::run(
	'writer refuses misuse: overwrite, add while a file is pending, add after finish, bad resume',
	static function () use ( $dir ) {
		$archive = $dir . '/misuse.tar';
		file_put_contents( $dir . '/big.bin', random_bytes( 2 * SF_MIB ) );

		$writer = Tar_Writer::create( $archive );
		Sf_Test::same( null, $writer->add_file( $dir . '/big.bin', 'wp-content/big.bin', new Slice_Budget( null, 1 ) ) );
		Sf_Test::throws(
			Archive_Exception::BAD_STATE,
			static function () use ( $writer ) {
				$writer->add_string( 'wp-content/other.txt', 'x' );
			},
			'add while pending:'
		);
		$state = $writer->checkpoint();
		$writer->close();

		Sf_Test::throws(
			Archive_Exception::IO,
			static function () use ( $archive ) {
				Tar_Writer::create( $archive );
			},
			'overwrite:'
		);

		$size = filesize( $archive );
		$fh   = fopen( $archive, 'r+b' );
		ftruncate( $fh, $size - 100 );
		fclose( $fh );
		Sf_Test::throws(
			Archive_Exception::BAD_STATE,
			static function () use ( $archive, $state ) {
				Tar_Writer::resume( $archive, $state );
			},
			'archive shorter than checkpoint:'
		);

		$done = Tar_Writer::create( $dir . '/done.tar' );
		$done->add_string( 'a.txt', 'a' );
		$final = $done->finish();
		Sf_Test::throws(
			Archive_Exception::BAD_STATE,
			static function () use ( $dir, $final ) {
				Tar_Writer::resume( $dir . '/done.tar', $final );
			},
			'resume finished archive:'
		);
	}
);

exit( Sf_Test::finish( 'test-tar-changes' ) );
