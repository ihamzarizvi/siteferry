<?php
/**
 * Space report: what takes the room in a backup.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

use SiteFerry\Archive\Io;
use SiteFerry\Export\Exclusions;
use SiteFerry\Export\Export;
use SiteFerry\Export\Scanner;
use SiteFerry\Export\Site_Info;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Before a backup: how big the database and wp-content are and where the size is (media by year,
 * every plugin and theme, cache folders, the largest files, the biggest tables, revisions and spam
 * comments), and how much room this server has. The admin page works out from it, as the options
 * change, about how big the backup will be.
 *
 * wp-content is listed with the export's own scanner (what every backup leaves out is left out
 * here too), in steps of limited time, so a large site takes a few requests; the finished report
 * is kept for an hour.
 */
final class Space {

	/** Seconds a finished report is used before the site is measured again. */
	const TTL = 3600;

	/** Entries in the "largest" lists. */
	const TOP = 10;

	/**
	 * The report: finished, or how far the measuring got.
	 *
	 * @param float $seconds Time to spend now.
	 * @param bool  $fresh   Measure again even if a recent report exists.
	 * @return array{complete:bool,files:int,bytes:int,report:array|null}
	 */
	public static function report( float $seconds, bool $fresh = false ): array {
		$dir    = Storage::ensure( Storage::root() . '/space' );
		$saved  = self::read_json( $dir . '/report.json' );
		$state  = self::read_json( $dir . '/state.json' );
		if ( ! $fresh && is_array( $saved ) && time() - (int) $saved['time'] < self::TTL ) {
			return array(
				'complete' => true,
				'files'    => (int) $saved['files']['files'],
				'bytes'    => (int) $saved['files']['bytes'],
				'report'   => $saved,
			);
		}
		if ( $fresh && null !== $state && ! empty( $state['finished'] ) ) {
			$state = null;
		}
		if ( null === $state || empty( $state['started'] ) || time() - (int) $state['started'] > self::TTL ) {
			$state = array( 'started' => time() );
			Io::quiet(
				static function () use ( $dir ) {
					return ! is_file( $dir . '/files.list' ) || unlink( $dir . '/files.list' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
		}

		$site    = Site_Info::collect();
		$scanner = new Scanner( (string) $site['wp_content_dir'], self::exclusions( $site ) );
		$scan    = isset( $state['scan'] ) ? (array) $state['scan'] : array();
		$done    = $scanner->run(
			$scan,
			$dir . '/files.list',
			new Slice_Budget( $seconds ),
			static function () {
			}
		);
		$state['scan'] = $scan;
		if ( ! $done ) {
			self::write_json( $dir . '/state.json', $state );
			return array(
				'complete' => false,
				'files'    => (int) $scan['files'],
				'bytes'    => (int) $scan['bytes'],
				'report'   => null,
			);
		}

		$report = array(
			'time'     => time(),
			'files'    => self::summarize( self::lines( $dir . '/files.list' ), self::layout( $site ) ),
			'database' => self::database(),
			'free'     => self::free( Storage::root() ),
			'backups'  => array_sum( array_column( Backups::in( Storage::backups_dir() ), 'size' ) ),
		);
		self::write_json( $dir . '/report.json', $report );
		self::write_json( $dir . '/state.json', array( 'finished' => true ) );
		Io::quiet(
			static function () use ( $dir ) {
				return unlink( $dir . '/files.list' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		);
		return array(
			'complete' => true,
			'files'    => (int) $report['files']['files'],
			'bytes'    => (int) $report['files']['bytes'],
			'report'   => $report,
		);
	}

	/**
	 * Adds up a file list (lines of the export's scanner): parts of wp-content, media by year,
	 * plugins and themes, cache, the largest files and top-level items.
	 *
	 * @param iterable $lines  Scanner lines.
	 * @param array    $layout plugins, themes and uploads (relative to wp-content, or null), cache
	 *                         (folder names), active_plugins (folder or file names), active_themes.
	 */
	public static function summarize( iterable $lines, array $layout ): array {
		$parts   = array();
		foreach ( array( 'uploads', 'plugins', 'themes', 'cache', 'other' ) as $key ) {
			$parts[ $key ] = array(
				'bytes' => 0,
				'files' => 0,
			);
		}
		$years   = array();
		$plugins = array();
		$themes  = array();
		$items   = array();
		$largest = array();
		$total   = 0;
		$count   = 0;

		foreach ( $lines as $line ) {
			$entry = Scanner::parse_line( rtrim( (string) $line, "\r\n" ) );
			if ( null === $entry || 'f' !== $entry['type'] ) {
				continue;
			}
			$path  = (string) $entry['path'];
			$size  = (int) $entry['size'];
			$total += $size;
			++$count;

			$top               = (string) strtok( $path, '/' );
			$items[ $top ]     = ( isset( $items[ $top ] ) ? $items[ $top ] : 0 ) + $size;
			$largest[ $path ]  = $size;
			if ( count( $largest ) > 4 * self::TOP ) {
				arsort( $largest );
				$largest = array_slice( $largest, 0, self::TOP, true );
			}

			$part = 'other';
			$name = null;
			if ( null !== $layout['uploads'] && self::under( $path, $layout['uploads'] ) ) {
				$part = 'uploads';
				$name = self::first( $path, $layout['uploads'] );
				if ( null !== $name && preg_match( '/^\d{4}$/', $name ) ) {
					$years[ $name ] = ( isset( $years[ $name ] ) ? $years[ $name ] : 0 ) + $size;
				}
			} elseif ( null !== $layout['plugins'] && self::under( $path, $layout['plugins'] ) ) {
				$part = 'plugins';
				$name = self::first( $path, $layout['plugins'] );
				if ( null !== $name && 'index.php' !== $name ) {
					$plugins[ $name ] = ( isset( $plugins[ $name ] ) ? $plugins[ $name ] : 0 ) + $size;
				}
			} elseif ( null !== $layout['themes'] && self::under( $path, $layout['themes'] ) ) {
				$part = 'themes';
				$name = self::first( $path, $layout['themes'] );
				if ( null !== $name && 'index.php' !== $name ) {
					$themes[ $name ] = ( isset( $themes[ $name ] ) ? $themes[ $name ] : 0 ) + $size;
				}
			} elseif ( in_array( $top, (array) $layout['cache'], true ) ) {
				$part = 'cache';
			}
			$parts[ $part ]['bytes'] += $size;
			++$parts[ $part ]['files'];
		}

		arsort( $largest );
		arsort( $years );
		arsort( $items );
		$named = static function ( array $sizes, array $active ): array {
			arsort( $sizes );
			$out = array();
			foreach ( $sizes as $name => $bytes ) {
				$out[] = array(
					'name'   => (string) $name,
					'bytes'  => $bytes,
					'active' => in_array( (string) $name, $active, true ),
				);
			}
			return $out;
		};
		$plugin_list = $named( $plugins, (array) $layout['active_plugins'] );
		$theme_list  = $named( $themes, (array) $layout['active_themes'] );
		$inactive    = static function ( array $list ): int {
			return (int) array_sum(
				array_column(
					array_filter(
						$list,
						static function ( array $item ): bool {
							return ! $item['active'];
						}
					),
					'bytes'
				)
			);
		};
		$parts['plugins']['inactive'] = $inactive( $plugin_list );
		$parts['themes']['inactive']  = $inactive( $theme_list );

		$listed = static function ( array $sizes, string $key, int $limit ): array {
			$out = array();
			foreach ( array_slice( $sizes, 0, $limit, true ) as $name => $bytes ) {
				$out[] = array(
					$key    => (string) $name,
					'bytes' => $bytes,
				);
			}
			return $out;
		};
		return array(
			'files'   => $count,
			'bytes'   => $total,
			'parts'   => $parts,
			'years'   => $listed( $years, 'name', 40 ),
			'plugins' => $plugin_list,
			'themes'  => $theme_list,
			'items'   => $listed( $items, 'name', self::TOP * 3 ),
			'largest' => $listed( $largest, 'path', self::TOP ),
		);
	}

	/**
	 * The database: every table's size and rows, and what revisions and spam comments hold.
	 */
	public static function database(): array {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = array();
		$data   = 0;
		$index  = 0;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ) as $row ) {
			if ( null === $row['Engine'] ) {
				continue; // A view.
			}
			$tables[] = array(
				'name'  => (string) $row['Name'],
				'bytes' => (int) $row['Data_length'],
				'rows'  => (int) $row['Rows'],
			);
			$data    += (int) $row['Data_length'];
			$index   += (int) $row['Index_length'];
		}
		usort(
			$tables,
			static function ( array $a, array $b ): int {
				return $b['bytes'] - $a['bytes'];
			}
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$revisions = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)), 0) AS bytes FROM {$wpdb->posts} WHERE post_type = 'revision'", ARRAY_A );
		$spam      = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(comment_content) + LENGTH(comment_author) + LENGTH(comment_author_email) + LENGTH(comment_author_url)), 0) AS bytes FROM {$wpdb->comments} WHERE comment_approved = 'spam'", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return array(
			'bytes'     => $data,
			'indexes'   => $index,
			'tables'    => $tables,
			'revisions' => array(
				'count' => (int) $revisions['n'],
				'bytes' => (int) $revisions['bytes'],
			),
			'spam'      => array(
				'count' => (int) $spam['n'],
				'bytes' => (int) $spam['bytes'],
			),
		);
	}

	/**
	 * Forgets the kept report (after a backup or restore changes the sizes, or to measure again).
	 */
	public static function forget(): void {
		$dir = Storage::root() . '/space';
		foreach ( array( 'report.json', 'state.json', 'files.list' ) as $name ) {
			if ( is_file( $dir . '/' . $name ) ) {
				Io::quiet(
					static function () use ( $dir, $name ) {
						return unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					}
				);
			}
		}
	}

	/**
	 * Where things are in wp-content, and what is active.
	 *
	 * @param array $site Site_Info::collect().
	 */
	private static function layout( array $site ): array {
		$content = (string) $site['wp_content_dir'];
		$active  = array();
		foreach ( (array) $site['active_plugins'] as $basename ) {
			$slash    = strpos( $basename, '/' );
			$active[] = false === $slash ? $basename : substr( $basename, 0, $slash );
		}
		return array(
			'uploads'        => self::relative( (string) $site['uploads_dir'], $content ),
			'plugins'        => self::relative( (string) $site['plugins_dir'], $content ),
			'themes'         => self::relative( (string) $site['themes_dir'], $content ),
			'cache'          => Exclusions::CACHE_FOLDERS,
			'active_plugins' => $active,
			'active_themes'  => array_values( array_unique( array( (string) $site['stylesheet'], (string) $site['template'] ) ) ),
		);
	}

	/**
	 * What every backup leaves out (nothing that an option controls).
	 *
	 * @param array $site Site_Info::collect().
	 */
	private static function exclusions( array $site ): Exclusions {
		return Export::exclusions( Export::normalize( array( 'exclude_cache' => false ) ), $site );
	}

	/**
	 * Lines of a list file.
	 *
	 * @param string $file List file.
	 * @return \Generator<string>
	 */
	private static function lines( string $file ): \Generator {
		$handle = Io::open_or_fail( $file, 'rb' );
		try {
			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition, WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				yield $line;
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Whether a path is inside a folder.
	 *
	 * @param string $path   Path.
	 * @param string $folder Folder.
	 */
	private static function under( string $path, string $folder ): bool {
		return '' === $folder || 0 === strpos( $path, $folder . '/' );
	}

	/**
	 * The first name below a folder ("plugins/akismet/x.php" in "plugins": "akismet").
	 *
	 * @param string $path   Path.
	 * @param string $folder Folder.
	 */
	private static function first( string $path, string $folder ): ?string {
		$rest = '' === $folder ? $path : substr( $path, strlen( $folder ) + 1 );
		$name = (string) strtok( $rest, '/' );
		return '' === $name ? null : $name;
	}

	/**
	 * Path relative to wp-content, or null when it is elsewhere.
	 *
	 * @param string $path Absolute path.
	 * @param string $base wp-content.
	 */
	private static function relative( string $path, string $base ): ?string {
		$windows = '\\' === DIRECTORY_SEPARATOR;
		$prefix  = rtrim( $base, '/' ) . '/';
		$match   = $windows ? 0 === stripos( $path . '/', $prefix ) : 0 === strpos( $path . '/', $prefix );
		return $match ? trim( substr( $path, strlen( $prefix ) ), '/' ) : null;
	}

	/**
	 * Free bytes on the disk that holds a folder, or null.
	 *
	 * @param string $dir Folder.
	 */
	private static function free( string $dir ): ?int {
		$free = function_exists( 'disk_free_space' ) ? Io::quiet(
			static function () use ( $dir ) {
				return disk_free_space( $dir );
			}
		) : false;
		return false === $free ? null : (int) $free;
	}

	/**
	 * A JSON file as an array, or null.
	 *
	 * @param string $file File.
	 */
	private static function read_json( string $file ): ?array {
		$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Writes an array as JSON.
	 *
	 * @param string $file File.
	 * @param array  $data Data.
	 */
	private static function write_json( string $file, array $data ): void {
		file_put_contents( $file, (string) wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
