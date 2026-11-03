<?php
/**
 * Resumable scan of wp-content into a file list.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Export;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Path_Guard;
use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Walks a folder depth-first and writes one line per entry to a list file:
 *
 *     d <TAB> mtime <TAB> relative/path
 *     f <TAB> size <TAB> mtime <TAB> relative/path
 *
 * Each folder's line comes before its contents, and a folder's files before its subfolders, so
 * the archive can be written straight from the list. Folder links (symbolic links and Windows
 * junctions) are not followed; file links are followed and stored as regular files. Names that
 * cannot be stored safely (invalid UTF-8, control characters, backslashes) are skipped with a
 * warning.
 *
 * Progress is saved per folder: the list file is cut back to the saved size on resume.
 */
final class Scanner {

	/**
	 * Folder being scanned.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Exclusion rules.
	 *
	 * @var Exclusions
	 */
	private $exclusions;

	/**
	 * Whether junction detection is needed.
	 *
	 * @var bool
	 */
	private $windows;

	/**
	 * Constructor.
	 *
	 * @param string     $root       Folder to scan (wp-content).
	 * @param Exclusions $exclusions What to leave out.
	 */
	public function __construct( string $root, Exclusions $exclusions ) {
		$this->root       = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->exclusions = $exclusions;
		$this->windows    = '\\' === DIRECTORY_SEPARATOR;
	}

	/**
	 * Scans until done or the budget runs out.
	 *
	 * @param array        $state  In/out: progress (empty array to start).
	 * @param string       $list   List file to write.
	 * @param Slice_Budget $budget Work limit; at least one folder is scanned per call.
	 * @param callable     $warn   function( string $message ): void.
	 * @return bool True when the whole tree is listed.
	 * @throws Archive_Exception If the list file cannot be written.
	 */
	public function run( array &$state, string $list, Slice_Budget $budget, callable $warn ): bool {
		if ( ! isset( $state['stack'] ) ) {
			$state = array(
				'stack'     => array( '' ),
				'list_size' => 0,
				'files'     => 0,
				'dirs'      => 0,
				'bytes'     => 0,
				'skipped'   => 0,
			);
		}

		$handle = Io::open_or_fail( $list, 'c+b' );
		try {
			if ( ! ftruncate( $handle, $state['list_size'] ) || 0 !== fseek( $handle, $state['list_size'] ) ) {
				throw new Archive_Exception( 'Cannot rewind the file list.', Archive_Exception::IO );
			}
			while ( array() !== $state['stack'] ) {
				$this->scan_folder( (string) array_pop( $state['stack'] ), $handle, $state, $warn );
				if ( $budget->exhausted() ) {
					break;
				}
			}
			fflush( $handle );
			$state['list_size'] = (int) ftell( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return array() === $state['stack'];
	}

	/**
	 * Lists one folder: its own line, its files, then queues its subfolders.
	 *
	 * @param string   $relative Folder relative to the root ('' for the root).
	 * @param resource $handle   List file.
	 * @param array    $state    Progress.
	 * @param callable $warn     Warning callback.
	 */
	private function scan_folder( string $relative, $handle, array &$state, callable $warn ): void {
		$absolute = '' === $relative ? $this->root : $this->root . '/' . $relative;
		$names    = Io::quiet(
			static function () use ( $absolute ) {
				return scandir( $absolute );
			}
		);
		if ( false === $names ) {
			$warn( sprintf( 'Cannot read the folder "%s"; it was left out.', self::label( $relative ) ) );
			++$state['skipped'];
			return;
		}

		$stat = Io::stat( $absolute );
		Io::write_all( $handle, sprintf( "d\t%d\t%s\n", false === $stat ? 0 : $stat['mtime'], $relative ) );
		++$state['dirs'];

		$real_parent = $this->windows ? realpath( $absolute ) : false;
		$subfolders  = array();

		foreach ( $names as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$child = '' === $relative ? $name : $relative . '/' . $name;
			if ( $this->exclusions->excludes( $child ) ) {
				continue;
			}
			if ( ! self::storable( $child ) ) {
				$warn( sprintf( 'The name "%s" cannot be stored safely; it was left out.', Path_Guard::printable( self::label( $child ) ) ) );
				++$state['skipped'];
				continue;
			}

			$path = $absolute . '/' . $name;
			if ( is_dir( $path ) ) {
				if ( is_link( $path ) || $this->is_junction( $path, $real_parent, $name ) ) {
					$warn( sprintf( 'The folder "%s" is a link to another location and was not followed.', self::label( $child ) ) );
					++$state['skipped'];
					continue;
				}
				$subfolders[] = $child;
				continue;
			}

			$stat = Io::stat( $path );
			if ( false === $stat || ! is_file( $path ) ) {
				$warn( sprintf( '"%s" is not a regular file (or a broken link); it was left out.', self::label( $child ) ) );
				++$state['skipped'];
				continue;
			}
			Io::write_all( $handle, sprintf( "f\t%d\t%d\t%s\n", $stat['size'], $stat['mtime'], $child ) );
			++$state['files'];
			$state['bytes'] += (int) $stat['size'];
		}

		// Pushed in reverse so they are popped, and therefore listed, in name order.
		foreach ( array_reverse( $subfolders ) as $subfolder ) {
			$state['stack'][] = $subfolder;
		}
	}

	/**
	 * Parses one list line.
	 *
	 * @param string $line Line without the newline.
	 * @return array{type:string,size:int,mtime:int,path:string}|null Null for malformed lines.
	 */
	public static function parse_line( string $line ): ?array {
		$parts = explode( "\t", $line, 4 );
		if ( 'd' === $parts[0] && 3 === count( $parts ) ) {
			return array(
				'type'  => 'd',
				'size'  => 0,
				'mtime' => (int) $parts[1],
				'path'  => $parts[2],
			);
		}
		if ( 'f' === $parts[0] && 4 === count( $parts ) ) {
			return array(
				'type'  => 'f',
				'size'  => (int) $parts[1],
				'mtime' => (int) $parts[2],
				'path'  => $parts[3],
			);
		}
		return null;
	}

	/**
	 * Whether a folder is a Windows junction (PHP's is_link() does not report junctions).
	 *
	 * @param string       $path        Folder.
	 * @param string|false $real_parent realpath() of its parent, or false when not on Windows.
	 * @param string       $name        Folder name.
	 */
	private function is_junction( string $path, $real_parent, string $name ): bool {
		if ( false === $real_parent ) {
			return false;
		}
		$real = realpath( $path );
		return false === $real || 0 !== strcasecmp( $real, $real_parent . DIRECTORY_SEPARATOR . $name );
	}

	/**
	 * Whether a relative path can go into an archive unchanged.
	 *
	 * @param string $relative Path relative to wp-content.
	 */
	private static function storable( string $relative ): bool {
		try {
			return Path_Guard::check( 'wp-content/' . $relative, false ) === 'wp-content/' . $relative;
		} catch ( Archive_Exception $e ) {
			return false;
		}
	}

	/**
	 * Path for messages, as it appears on the site.
	 *
	 * @param string $relative Path relative to wp-content.
	 */
	private static function label( string $relative ): string {
		return '' === $relative ? 'wp-content' : 'wp-content/' . $relative;
	}
}
