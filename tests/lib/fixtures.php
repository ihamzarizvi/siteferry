<?php
/**
 * Fixture trees and pack/unpack helpers shared by the tests and tools.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Archive\Tar_Extractor;
use SiteFerry\Archive\Tar_Header;
use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Jobs\Slice_Budget;

/**
 * Relative paths used by the standard fixture, by purpose.
 *
 * @return array<string,string>
 */
function sf_fixture_paths(): array {
	$deep = 'wp-content/deep';
	foreach ( array( 'g', 'h', 'i', 'j', 'k' ) as $letter ) {
		$deep .= '/' . str_repeat( $letter, 60 );
	}
	return array(
		'exactly_100' => 'wp-content/' . str_repeat( 'p', 85 ) . '.txt',
		'split_186'   => 'wp-content/' . str_repeat( 'd', 80 ) . '/' . str_repeat( 'f', 90 ) . '.txt',
		'segment_165' => 'wp-content/' . str_repeat( 'x', 150 ) . '.txt',
		'over_255'    => $deep . '/' . str_repeat( 'z', 60 ) . '.txt',
		'japanese'    => 'wp-content/uploads/日本語/ファイル.txt',
		'accents'     => 'wp-content/uploads/café/naïve résumé.txt',
		'emoji'       => 'wp-content/uploads/emoji-😀/party-🎉.txt',
	);
}

/**
 * Creates the standard fixture tree in an existing folder.
 *
 * @param string   $root Folder.
 * @param string[] $omit Keys of sf_fixture_paths() to leave out (for tools that cannot handle them).
 */
function sf_make_fixture( string $root, array $omit = array() ): void {
	$paths = sf_fixture_paths();
	$files = array(
		'wp-content/plain.txt'        => "Hello, SiteFerry.\n",
		'wp-content/empty.txt'        => '',
		'wp-content/size-511.bin'     => str_repeat( 'a', 511 ),
		'wp-content/size-512.bin'     => str_repeat( 'b', 512 ),
		'wp-content/size-513.bin'     => str_repeat( 'c', 513 ),
		'wp-content/all-bytes.bin'    => str_repeat( implode( '', array_map( 'chr', range( 0, 255 ) ) ), 64 ),
		'wp-content/compressible.txt' => str_repeat( "The quick brown fox jumps over the lazy dog.\n", 120000 ),
		'wp-content/random.bin'       => random_bytes( 6 * 1048576 + 123 ),
		$paths['exactly_100']         => "exactly 100 bytes of path\n",
		$paths['split_186']           => "ustar prefix + name\n",
		$paths['segment_165']         => "single long segment\n",
		$paths['japanese']            => "unicode 1\n",
		$paths['accents']             => "unicode 2\n",
		$paths['emoji']               => "4-byte unicode\n",
		$paths['over_255']            => "path over 255 bytes\n",
	);
	foreach ( $omit as $key ) {
		unset( $files[ $paths[ $key ] ] );
	}

	foreach ( $files as $relative => $content ) {
		$target = $root . '/' . $relative;
		if ( ! is_dir( dirname( $target ) ) ) {
			mkdir( dirname( $target ), 0755, true );
		}
		file_put_contents( $target, $content );
	}
	mkdir( $root . '/wp-content/empty-dir', 0755, true );
	mkdir( $root . '/wp-content/uploads/2024/01', 0755, true );
}

/**
 * Directories and files under a folder, sorted, as [relative path, is_dir] pairs.
 *
 * @param string $root Folder.
 * @return array<int,array{0:string,1:bool}>
 */
function sf_items( string $root ): array {
	$items    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $info ) {
		$relative = str_replace( '\\', '/', substr( $info->getPathname(), strlen( $root ) + 1 ) );
		$items[]  = array( $relative, $info->isDir() );
	}
	usort(
		$items,
		static function ( array $a, array $b ): int {
			return strcmp( $a[0], $b[0] );
		}
	);
	return $items;
}

/**
 * Description of a tree: relative path => ['type', 'size', 'sha256'].
 *
 * @param string $root Folder.
 * @return array<string,array>
 */
function sf_tree( string $root ): array {
	$tree = array();
	foreach ( sf_items( $root ) as list( $relative, $is_dir ) ) {
		$path              = $root . '/' . $relative;
		$tree[ $relative ] = $is_dir
			? array(
				'type'   => 'dir',
				'size'   => 0,
				'sha256' => null,
			)
			: array(
				'type'   => 'file',
				'size'   => filesize( $path ),
				'sha256' => hash_file( 'sha256', $path ),
			);
	}
	ksort( $tree, SORT_STRING );
	return $tree;
}

/**
 * Packs a folder with Tar_Writer, optionally in byte-limited slices with a JSON round trip of the
 * saved state between slices (as the job runner does).
 *
 * @param string   $root        Folder to pack.
 * @param string   $archive     Archive to create.
 * @param array    $options     Tar_Writer options.
 * @param int|null $slice_bytes Bytes per slice, or null for one pass.
 * @param bool     $crash       Append junk to the archive after each checkpoint, as a request that
 *                              died after saving its state would leave it.
 * @return array{results:array,slices:int}
 */
function sf_pack( string $root, string $archive, array $options = array(), ?int $slice_bytes = null, bool $crash = false ): array {
	$items   = sf_items( $root );
	$results = array();
	$state   = null;
	$cursor  = 0;
	$slices  = 0;

	while ( true ) {
		++$slices;
		$writer = null === $state ? Tar_Writer::create( $archive, $options ) : Tar_Writer::resume( $archive, $state );
		$budget = null === $slice_bytes ? null : new Slice_Budget( null, $slice_bytes );
		$paused = false;

		if ( $writer->has_pending() ) {
			$result = $writer->continue_file( $budget );
			if ( null === $result ) {
				$paused = true;
			} else {
				$results[ $result['name'] ] = $result;
			}
		}

		while ( ! $paused && $cursor < count( $items ) && ( null === $budget || ! $budget->exhausted() ) ) {
			list( $relative, $is_dir ) = $items[ $cursor ];
			++$cursor;
			$path = $root . '/' . $relative;
			if ( $is_dir ) {
				$writer->add_directory( $relative, (int) filemtime( $path ) );
				continue;
			}
			$result = $writer->add_file( $path, $relative, $budget );
			if ( null === $result ) {
				$paused = true;
			} else {
				$results[ $result['name'] ] = $result;
			}
		}

		if ( ! $paused && $cursor >= count( $items ) ) {
			$writer->finish();
			return array(
				'results' => $results,
				'slices'  => $slices,
			);
		}

		$state = json_decode( (string) json_encode( $writer->checkpoint() ), true );
		$writer->close();
		if ( $crash ) {
			file_put_contents( $archive, random_bytes( 777 ), FILE_APPEND );
		}
	}
}

/**
 * Extracts an archive with Tar_Extractor, optionally in byte-limited slices.
 *
 * @param string   $archive     Archive.
 * @param string   $dest        Existing staging folder.
 * @param array    $options     Tar_Extractor options.
 * @param int|null $slice_bytes Bytes per slice, or null for one pass.
 * @param bool     $crash       Append junk to the partly written file after each slice.
 * @return array{entries:array,slices:int}
 */
function sf_unpack( string $archive, string $dest, array $options = array(), ?int $slice_bytes = null, bool $crash = false ): array {
	$entries = array();
	$state   = null;
	$slices  = 0;
	$collect = static function ( array $entry ) use ( &$entries ): void {
		$entries[ $entry['path'] ] = $entry;
	};

	while ( true ) {
		++$slices;
		$reader    = Tar_Reader::open( $archive, null === $state ? null : $state['reader'] );
		$extractor = new Tar_Extractor( $reader, $dest, $options, $state );
		$budget    = null === $slice_bytes ? null : new Slice_Budget( null, $slice_bytes );
		$done      = $extractor->run( $budget, $collect );
		$state     = json_decode( (string) json_encode( $extractor->state() ), true );
		$reader->close();

		if ( $done ) {
			ksort( $entries, SORT_STRING );
			return array(
				'entries' => $entries,
				'slices'  => $slices,
			);
		}
		if ( $crash && ! empty( $state['current'] ) ) {
			file_put_contents( $dest . '/' . $state['current']['path'], random_bytes( 333 ), FILE_APPEND );
		}
	}
}

/**
 * Lists an archive: name => ['type', 'size', 'sha256'] (directories without trailing slash).
 *
 * @param string $archive Archive.
 * @return array<string,array>
 */
function sf_list( string $archive ): array {
	$reader  = Tar_Reader::open( $archive );
	$entries = array();
	while ( null !== ( $entry = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$hash = hash_init( 'sha256' );
		while ( '' !== ( $chunk = $reader->read( 1048576 ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			hash_update( $hash, $chunk );
		}
		$name             = rtrim( $entry->name, '/' );
		$entries[ $name ] = $entry->is_dir()
			? array(
				'type'   => 'dir',
				'size'   => 0,
				'sha256' => null,
			)
			: array(
				'type'   => 'file',
				'size'   => $entry->size,
				'sha256' => hash_final( $hash ),
			);
	}
	$reader->close();
	ksort( $entries, SORT_STRING );
	return $entries;
}

/**
 * SHA-256 of a gzip file's decompressed content, decoded by zlib's own gzread()
 * (independent of SiteFerry's Gzip_Input).
 *
 * @param string $path .gz file.
 */
function sf_gunzip_sha256( string $path ): string {
	$gz   = gzopen( $path, 'rb' );
	$hash = hash_init( 'sha256' );
	while ( ! gzeof( $gz ) ) {
		$chunk = gzread( $gz, 1048576 );
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		hash_update( $hash, $chunk );
	}
	gzclose( $gz );
	return hash_final( $hash );
}

/**
 * Counts the gzip members in a file.
 *
 * @param string $path .gz file.
 */
function sf_count_gzip_members( string $path ): int {
	$data    = file_get_contents( $path );
	$offset  = 0;
	$members = 0;
	while ( $offset < strlen( $data ) && "\x1f\x8b" === substr( $data, $offset, 2 ) ) {
		$inflate = inflate_init( ZLIB_ENCODING_GZIP );
		inflate_add( $inflate, substr( $data, $offset ), ZLIB_SYNC_FLUSH );
		if ( ZLIB_STREAM_END !== inflate_get_status( $inflate ) ) {
			throw new RuntimeException( 'Unterminated gzip member at offset ' . $offset );
		}
		$offset += inflate_get_read_len( $inflate );
		++$members;
	}
	if ( $offset !== strlen( $data ) ) {
		throw new RuntimeException( 'Trailing bytes after the last gzip member.' );
	}
	return $members;
}

/**
 * One raw archive entry built with Tar_Header (no path validation), for crafted archives.
 *
 * @param string $name Entry name.
 * @param string $type Typeflag.
 * @param string $data Entry data.
 */
function sf_raw_entry( string $name, string $type, string $data = '' ): string {
	$padding = ( 512 - strlen( $data ) % 512 ) % 512;
	return Tar_Header::build( $name, $type, strlen( $data ), 1700000000, 0644 ) . $data . str_repeat( "\0", $padding );
}

/**
 * Writes a raw archive (entries plus end marker) to a file.
 *
 * @param string   $path    Target file.
 * @param string[] $entries Raw entries.
 */
function sf_write_raw_archive( string $path, array $entries ): void {
	file_put_contents( $path, implode( '', $entries ) . str_repeat( "\0", 1024 ) );
}

/**
 * Replaces a header field and recomputes the header checksum.
 *
 * @param string $block  512-byte header.
 * @param int    $offset Field offset.
 * @param string $value  New field bytes.
 */
function sf_set_field( string $block, int $offset, string $value ): string {
	$block = substr_replace( $block, $value, $offset, strlen( $value ) );
	$blank = substr_replace( $block, '        ', 148, 8 );
	return substr_replace( $blank, sprintf( '%06o', array_sum( unpack( 'C*', $blank ) ) ) . "\0 ", 148, 8 );
}

/**
 * Deletes a folder created by the tests, including paths longer than 260 characters.
 *
 * Symbolic links and Windows junctions are removed without touching what they point to
 * (PHP's is_link() does not recognise junctions, so a realpath() mismatch is checked too).
 *
 * @param string $dir Folder.
 */
function sf_remove_tree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $name ) {
		$path = $dir . DIRECTORY_SEPARATOR . $name;
		if ( is_link( $path ) || ( is_dir( $path ) && realpath( $path ) !== realpath( $dir ) . DIRECTORY_SEPARATOR . $name ) ) {
			// Windows removes directory links and junctions with rmdir(), other systems use unlink().
			if ( ! ( '\\' === DIRECTORY_SEPARATOR && is_dir( $path ) ? rmdir( $path ) : unlink( $path ) ) ) {
				throw new RuntimeException( 'Cannot remove link ' . $path );
			}
		} elseif ( is_dir( $path ) ) {
			sf_remove_tree( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}
