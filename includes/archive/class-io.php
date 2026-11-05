<?php
/**
 * Small file helpers that report failures as exceptions instead of PHP warnings.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * File helpers shared by the archive classes.
 */
final class Io {

	/**
	 * Runs a callback with PHP warnings suppressed and returns its result.
	 *
	 * Used for calls whose failure is expected and handled through the return value
	 * (a file vanishing between scan and read, for example).
	 *
	 * @param callable $callback Callback to run.
	 * @return mixed
	 */
	public static function quiet( callable $callback ) {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function () {
				return true;
			}
		);
		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Opens a file, returning false instead of raising a warning.
	 *
	 * @param string $path File path.
	 * @param string $mode fopen() mode.
	 * @return resource|false
	 */
	public static function open( string $path, string $mode ) {
		return self::quiet(
			static function () use ( $path, $mode ) {
				return fopen( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			}
		);
	}

	/**
	 * Opens a file or throws.
	 *
	 * @param string $path File path.
	 * @param string $mode fopen() mode.
	 * @return resource
	 * @throws Archive_Exception When the file cannot be opened.
	 */
	public static function open_or_fail( string $path, string $mode ) {
		$handle = self::open( $path, $mode );
		if ( false === $handle ) {
			throw new Archive_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'Cannot open "%s".', 'siteferry' ), $path ), Archive_Exception::IO );
		}
		return $handle;
	}

	/**
	 * Fresh stat() of a path, or false if it is gone or unreadable.
	 *
	 * @param string $path File path.
	 * @return array|false
	 */
	public static function stat( string $path ) {
		clearstatcache( true, $path );
		return self::quiet(
			static function () use ( $path ) {
				return stat( $path );
			}
		);
	}

	/**
	 * Writes all of $data or throws (a short write usually means the disk is full).
	 *
	 * @param resource $handle Open file handle.
	 * @param string   $data   Bytes to write.
	 * @throws Archive_Exception On a failed or short write.
	 */
	public static function write_all( $handle, string $data ): void {
		$length = strlen( $data );
		$done   = 0;
		while ( $done < $length ) {
			$written = fwrite( $handle, 0 === $done ? $data : substr( $data, $done ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			if ( false === $written || 0 === $written ) {
				throw new Archive_Exception( __( 'Write failed. The disk may be full.', 'siteferry' ), Archive_Exception::IO );
			}
			$done += $written;
		}
	}

	/**
	 * Size of an open file according to fstat().
	 *
	 * @param resource $handle Open file handle.
	 */
	public static function size( $handle ): int {
		$stat = fstat( $handle );
		return false === $stat ? 0 : (int) $stat['size'];
	}

	/**
	 * Whether a path is a symbolic link or a Windows junction (PHP's is_link() misses junctions).
	 *
	 * @param string $path Existing path.
	 */
	public static function is_link_or_junction( string $path ): bool {
		if ( is_link( $path ) ) {
			return true;
		}
		if ( '\\' !== DIRECTORY_SEPARATOR || ! is_dir( $path ) ) {
			return false;
		}
		$real   = realpath( $path );
		$parent = realpath( dirname( $path ) );
		return false === $real || false === $parent || 0 !== strcasecmp( $real, $parent . DIRECTORY_SEPARATOR . basename( $path ) );
	}

	/**
	 * Deletes a folder SiteFerry created, with everything in it.
	 *
	 * Links and junctions inside are removed without touching what they point to, so a cleanup can
	 * never reach outside the folder.
	 *
	 * @param string $dir Folder.
	 * @return bool True if it is gone.
	 */
	public static function remove_tree( string $dir ): bool {
		if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
			return true;
		}
		if ( self::is_link_or_junction( $dir ) || ! is_dir( $dir ) ) {
			return self::remove_entry( $dir );
		}
		$names = self::quiet(
			static function () use ( $dir ) {
				return scandir( $dir );
			}
		);
		foreach ( false === $names ? array() : $names as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				self::remove_tree( $dir . DIRECTORY_SEPARATOR . $name );
			}
		}
		return self::quiet(
			static function () use ( $dir ) {
				return rmdir( $dir );
			}
		) || ! file_exists( $dir );
	}

	/**
	 * Removes a file, link or junction (not following it).
	 *
	 * @param string $path Path.
	 */
	private static function remove_entry( string $path ): bool {
		return (bool) self::quiet(
			static function () use ( $path ) {
				// Windows removes directory links and junctions with rmdir(), everything else with unlink().
				if ( '\\' === DIRECTORY_SEPARATOR && is_dir( $path ) ) {
					return rmdir( $path );
				}
				return unlink( $path );
			}
		);
	}
}
