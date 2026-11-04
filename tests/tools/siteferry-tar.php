<?php
/**
 * Command-line front end to the archive library, used by tests/interop-tar.ps1.
 *
 * Usage:
 *   php siteferry-tar.php fixture <dir> [--omit=a,b]       Create the standard fixture tree, leaving
 *                                                          out keys of sf_fixture_paths().
 *   php siteferry-tar.php pack <dir> <archive> [--gz]      Pack a folder.
 *   php siteferry-tar.php unpack <archive> <dir>           Extract into an existing empty folder.
 *   php siteferry-tar.php tree <dir> [--exclude=a,b]       Print "type size sha256 path" lines,
 *                                                          leaving out relative paths ("*" allowed).
 *   php siteferry-tar.php list <archive>                   Same, for archive contents.
 *   php siteferry-tar.php manifest <archive>               Print the backup's manifest (JSON).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require dirname( __DIR__ ) . '/bootstrap.php';

/**
 * Prints a tree description.
 *
 * @param array $tree Path => ['type', 'size', 'sha256'].
 */
function sf_print_tree( array $tree ): void {
	foreach ( $tree as $path => $item ) {
		printf( "%s %d %s %s\n", $item['type'], $item['size'], null === $item['sha256'] ? '-' : $item['sha256'], $path );
	}
}

$args    = array_slice( $argv, 1 );
$command = isset( $args[0] ) ? $args[0] : '';

try {
	switch ( $command ) {
		case 'fixture':
			$omit = array();
			foreach ( $args as $arg ) {
				if ( 0 === strpos( $arg, '--omit=' ) ) {
					$omit = array_filter( explode( ',', substr( $arg, 7 ) ) );
				}
			}
			sf_make_fixture( $args[1], $omit );
			break;
		case 'pack':
			sf_pack( rtrim( $args[1], '/\\' ), $args[2], array( 'compress' => in_array( '--gz', $args, true ) ) );
			break;
		case 'unpack':
			sf_unpack( $args[1], $args[2] );
			break;
		case 'tree':
			$exclude = array();
			foreach ( $args as $arg ) {
				if ( 0 === strpos( $arg, '--exclude=' ) ) {
					$exclude = array_filter( explode( ',', substr( $arg, 10 ) ) );
				}
			}
			sf_print_tree( sf_tree( rtrim( $args[1], '/\\' ), $exclude ) );
			break;
		case 'list':
			sf_print_tree( sf_list( $args[1] ) );
			break;
		case 'manifest':
			echo json_encode( SiteFerry\Backups::manifest( $args[1] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
			break;
		default:
			fwrite( STDERR, "Unknown command. See the header of this file.\n" );
			exit( 2 );
	}
} catch ( Throwable $e ) {
	fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" );
	exit( 1 );
}
