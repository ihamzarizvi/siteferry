<?php
/**
 * Safe, resumable extraction into a staging folder.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts regular files and directories into one folder and refuses everything else.
 *
 * The whole extraction stops with an exception at the first entry that is a link, device, FIFO or
 * sparse file, or whose path is absolute, has a drive letter, contains "..", or is outside the
 * folders the caller allows. Every written path is also checked with realpath() to be inside the
 * staging folder.
 */
final class Tar_Extractor {

	/**
	 * Archive being read (a Tar_Reader, or a .wpress reader).
	 *
	 * @var Entry_Reader
	 */
	private $reader;

	/**
	 * Real path of the staging folder, without a trailing separator.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Apply Windows name rules.
	 *
	 * @var bool|null
	 */
	private $windows;

	/**
	 * Optional filter: function( string $path, Tar_Entry $entry ): bool, false rejects the entry.
	 *
	 * @var callable|null
	 */
	private $allow;

	/**
	 * Save hash state directly when PHP allows it.
	 *
	 * @var bool
	 */
	private $serialize_hash;

	/**
	 * File being written: ['path', 'size', 'mtime'], or null.
	 *
	 * @var array|null
	 */
	private $current = null;

	/**
	 * Checksum of the file being written.
	 *
	 * @var Resumable_Hash|null
	 */
	private $hash = null;

	/**
	 * Folders already created and checked during this request (relative path => true).
	 *
	 * @var array<string,bool>
	 */
	private $known_dirs = array();

	/**
	 * Constructor.
	 *
	 * @param Entry_Reader $reader  Reader, opened fresh or from the 'reader' part of a saved state.
	 * @param string       $root    Existing staging folder.
	 * @param array        $options {
	 *     Optional settings.
	 *
	 *     @type callable $allow          function( string $path, Tar_Entry $entry ): bool.
	 *     @type bool     $windows        Force Windows name rules on or off (default: this server's OS).
	 *     @type bool     $serialize_hash Save hash state directly when possible. Default true.
	 * }
	 * @param array|null   $state   Data from state() to continue a previous slice.
	 * @throws Archive_Exception If the staging folder does not exist.
	 */
	public function __construct( Entry_Reader $reader, string $root, array $options = array(), ?array $state = null ) {
		$real = realpath( $root );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new Archive_Exception( 'The staging folder does not exist.', Archive_Exception::IO );
		}

		$this->reader         = $reader;
		$this->root           = rtrim( $real, '/\\' );
		$this->windows        = isset( $options['windows'] ) ? (bool) $options['windows'] : null;
		$this->allow          = isset( $options['allow'] ) ? $options['allow'] : null;
		$this->serialize_hash = ! isset( $options['serialize_hash'] ) || (bool) $options['serialize_hash'];

		if ( null !== $state && ! empty( $state['current'] ) ) {
			$this->current = (array) $state['current'];
			$this->hash    = Resumable_Hash::import( isset( $state['hash'] ) ? (array) $state['hash'] : array(), $this->replay_current() );
		}
	}

	/**
	 * Extracts entries until the archive ends or the budget runs out.
	 *
	 * @param Slice_Budget|null $budget   Work limit; at least one entry or chunk is processed per call.
	 * @param callable|null     $on_entry Called after each finished entry with
	 *                                    ['path', 'type' => 'file'|'dir', 'size', 'sha256'].
	 * @return bool True when the whole archive is extracted.
	 * @throws Archive_Exception On unsafe entries or damaged archives.
	 */
	public function run( ?Slice_Budget $budget = null, ?callable $on_entry = null ): bool {
		if ( null !== $this->current ) {
			$handle = $this->reopen_current();
			if ( ! $this->copy( $handle, $budget, $on_entry ) ) {
				return false;
			}
			if ( null !== $budget && $budget->exhausted() ) {
				return false;
			}
		}

		while ( true ) {
			$entry = $this->reader->next();
			if ( null === $entry ) {
				return true;
			}

			if ( ! $entry->is_file() && ! $entry->is_dir() ) {
				throw new Archive_Exception(
					sprintf( 'The archive contains a %s ("%s"), which is not allowed.', $entry->type_label(), Path_Guard::printable( $entry->name ) ),
					Archive_Exception::UNSUPPORTED
				);
			}
			$path = Path_Guard::check( $entry->name, $this->windows );
			if ( null !== $this->allow && true !== call_user_func( $this->allow, $path, $entry ) ) {
				throw new Archive_Exception(
					sprintf( 'The archive contains "%s", which is outside the allowed folders.', Path_Guard::printable( $path ) ),
					Archive_Exception::UNSAFE_PATH
				);
			}

			if ( $entry->is_dir() ) {
				$this->make_dir( $path );
				if ( null !== $on_entry ) {
					$on_entry(
						array(
							'path'   => $path,
							'type'   => 'dir',
							'size'   => 0,
							'sha256' => null,
						)
					);
				}
			} else {
				$handle = $this->start_file( $path, $entry );
				if ( ! $this->copy( $handle, $budget, $on_entry ) ) {
					return false;
				}
			}

			if ( null !== $budget && $budget->exhausted() ) {
				return false;
			}
		}
	}

	/**
	 * State to save between slices. Reopen the reader with ['reader'] and pass the whole array back.
	 *
	 * @return array JSON-safe state.
	 */
	public function state(): array {
		return array(
			'reader'  => $this->reader->state(),
			'current' => $this->current,
			'hash'    => null === $this->hash ? null : $this->hash->export( $this->serialize_hash ),
		);
	}

	/**
	 * Creates the target file for an entry.
	 *
	 * @param string    $path  Validated relative path.
	 * @param Tar_Entry $entry Entry.
	 * @return resource
	 * @throws Archive_Exception If the file cannot be created safely.
	 */
	private function start_file( string $path, Tar_Entry $entry ) {
		$slash = strrpos( $path, '/' );
		if ( false !== $slash ) {
			$this->make_dir( substr( $path, 0, $slash ) );
		}

		$target = $this->target( $path );
		if ( is_link( $target ) || is_dir( $target ) ) {
			throw new Archive_Exception( sprintf( 'Cannot write "%s": a folder or link is in the way.', Path_Guard::printable( $path ) ), Archive_Exception::UNSAFE_PATH );
		}

		$handle = Io::open_or_fail( $target, 'wb' );
		$this->assert_inside( $target );

		$this->current = array(
			'path'  => $path,
			'size'  => $entry->size,
			'mtime' => $entry->mtime,
		);
		$this->hash    = new Resumable_Hash();
		return $handle;
	}

	/**
	 * Reopens the partly written file and cuts it back to what the reader has delivered.
	 *
	 * @return resource
	 * @throws Archive_Exception If the file is missing.
	 */
	private function reopen_current() {
		$written = $this->current['size'] - $this->reader->remaining();
		$target  = $this->target( $this->current['path'] );
		$handle  = Io::open_or_fail( $target, 'r+b' );
		if ( Io::size( $handle ) < $written || ! ftruncate( $handle, $written ) || 0 !== fseek( $handle, $written ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			throw new Archive_Exception( sprintf( 'The staged copy of "%s" is shorter than expected.', Path_Guard::printable( $this->current['path'] ) ), Archive_Exception::BAD_STATE );
		}
		return $handle;
	}

	/**
	 * Copies the current entry's data into the open file.
	 *
	 * @param resource          $handle   Target file.
	 * @param Slice_Budget|null $budget   Work limit.
	 * @param callable|null     $on_entry Completion callback.
	 * @return bool True when the file is complete.
	 */
	private function copy( $handle, ?Slice_Budget $budget, ?callable $on_entry ): bool {
		try {
			$done = $this->reader->copy_to( $handle, $budget, $this->hash );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( ! $done ) {
			return false;
		}

		$target = $this->target( $this->current['path'] );
		if ( $this->current['mtime'] > 0 ) {
			$mtime = $this->current['mtime'];
			Io::quiet(
				static function () use ( $target, $mtime ) {
					return touch( $target, $mtime );
				}
			);
		}

		$result        = array(
			'path'   => $this->current['path'],
			'type'   => 'file',
			'size'   => $this->current['size'],
			'sha256' => $this->hash->hex(),
		);
		$this->current = null;
		$this->hash    = null;

		if ( null !== $on_entry ) {
			$on_entry( $result );
		}
		return true;
	}

	/**
	 * Creates a folder (and its parents) inside the staging folder, one segment at a time.
	 *
	 * Each segment is checked before anything is created below it, so a link planted in the
	 * staging folder (or a path that slipped past Path_Guard) cannot redirect the extraction.
	 *
	 * @param string $path Validated relative path.
	 * @throws Archive_Exception If a file or link is in the way or a folder cannot be created.
	 */
	private function make_dir( string $path ): void {
		if ( isset( $this->known_dirs[ $path ] ) ) {
			return;
		}

		$current = $this->root;
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new Archive_Exception( sprintf( 'Unsafe folder name in "%s".', Path_Guard::printable( $path ) ), Archive_Exception::UNSAFE_PATH );
			}
			$current .= DIRECTORY_SEPARATOR . $segment;

			if ( is_link( $current ) ) {
				throw new Archive_Exception( sprintf( 'Cannot create folder "%s": a link is in the way.', Path_Guard::printable( $path ) ), Archive_Exception::UNSAFE_PATH );
			}
			if ( ! is_dir( $current ) ) {
				if ( file_exists( $current ) ) {
					throw new Archive_Exception( sprintf( 'Cannot create folder "%s": a file is in the way.', Path_Guard::printable( $path ) ), Archive_Exception::UNSAFE_PATH );
				}
				$dir  = $current;
				$made = Io::quiet(
					static function () use ( $dir ) {
						return mkdir( $dir, 0755 );
					}
				);
				if ( ! $made && ! is_dir( $current ) ) {
					throw new Archive_Exception( sprintf( 'Cannot create folder "%s".', Path_Guard::printable( $path ) ), Archive_Exception::IO );
				}
			}
			$this->assert_inside( $current );
		}

		if ( count( $this->known_dirs ) >= 5000 ) {
			$this->known_dirs = array();
		}
		$this->known_dirs[ $path ] = true;
	}

	/**
	 * Absolute path for a validated relative path.
	 *
	 * @param string $path Relative path with "/" separators.
	 */
	private function target( string $path ): string {
		return $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
	}

	/**
	 * Final defense: the resolved path must be inside the staging folder.
	 *
	 * @param string $target Existing absolute path.
	 * @throws Archive_Exception If it resolves elsewhere.
	 */
	private function assert_inside( string $target ): void {
		$real = realpath( $target );
		if ( false === $real || 0 !== strpos( $real, $this->root . DIRECTORY_SEPARATOR ) ) {
			throw new Archive_Exception( 'An archive entry resolved outside the staging folder.', Archive_Exception::UNSAFE_PATH );
		}
	}

	/**
	 * Replay callback for Resumable_Hash: re-reads what was already written to the staged file.
	 *
	 * @return callable
	 */
	private function replay_current(): callable {
		$target = $this->target( $this->current['path'] );
		return static function ( int $length, callable $feed ) use ( $target ): bool {
			$handle = Io::open( $target, 'rb' );
			if ( false === $handle ) {
				return false;
			}
			$left = $length;
			while ( $left > 0 ) {
				$chunk = fread( $handle, min( Tar_Reader::CHUNK, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$feed( $chunk );
				$left -= strlen( $chunk );
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return 0 === $left;
		};
	}
}
