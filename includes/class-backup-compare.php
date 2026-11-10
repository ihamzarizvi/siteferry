<?php
/**
 * What differs between two backups, from their manifests.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Compares two SiteFerry backups using only what their manifests record (no dump or file is
 * read): how and when each was made, the site's address, plugins and themes (versions since
 * 0.29.0, which ones were turned on), the theme in use, content counts, database tables with
 * their row counts and the dump's size, and the files held.
 *
 * The result always runs from the older backup to the newer one, whatever order they are given in.
 * Read only.
 */
final class Backup_Compare {

	/** Content counted in manifests since 0.29.0. */
	const CONTENT = array( 'posts', 'pages', 'media', 'users', 'comments' );

	/**
	 * Compares two backups on this server.
	 *
	 * @param string      $a     File name of one backup.
	 * @param string      $b     File name of the other.
	 * @param string|null $key_a Key of $a when it is encrypted.
	 * @param string|null $key_b Key of $b when it is encrypted.
	 * @throws Archive_Exception If a backup cannot be read or is password-protected without a key.
	 * @throws Job_Exception If both names are the same or one is a .wpress file.
	 */
	public static function files(
		string $a,
		string $b,
		#[\SensitiveParameter]
		?string $key_a = null,
		#[\SensitiveParameter]
		?string $key_b = null
	): array {
		if ( $a === $b ) {
			throw new Job_Exception( __( 'Choose two different backups to compare.', 'siteferry' ), Job_Exception::STEP );
		}
		$dir   = Storage::backups_dir();
		$sides = array();
		foreach ( array( array( $a, $key_a ), array( $b, $key_b ) ) as list( $name, $key ) ) {
			if ( Wpress\Wpress::is_name( $name ) ) {
				throw new Job_Exception( sprintf( /* translators: %s: a backup file name */ __( '%s is a .wpress file; .wpress backups cannot be compared yet.', 'siteferry' ), $name ), Job_Exception::STEP );
			}
			$path    = Backups::path( $dir, $name );
			$sides[] = array(
				'name'      => $name,
				'size'      => (int) filesize( $path ),
				'encrypted' => Archive\Crypto::is_encrypted( $path ),
				'manifest'  => Backups::manifest( $path, $key ),
			);
		}
		return self::compare( $sides[0], $sides[1] );
	}

	/**
	 * The differences.
	 *
	 * @param array $one   One backup: manifest (array) and optionally name, size, encrypted.
	 * @param array $other The other.
	 * @return array{older:array,newer:array,versions:array,address:?array,plugins:array,plugins_same:int,plugins_known:bool,themes:array,themes_same:int,theme:?array,content:?array,tables:array,files:array}
	 */
	public static function compare( array $one, array $other ): array {
		$time = static function ( array $side ): int {
			$created = isset( $side['manifest']['created'] ) ? strtotime( (string) $side['manifest']['created'] ) : false;
			return false === $created ? 0 : (int) $created;
		};
		list( $old, $new ) = $time( $one ) <= $time( $other ) ? array( $one, $other ) : array( $other, $one );
		$om                = (array) $old['manifest'];
		$nm                = (array) $new['manifest'];

		// Versions it was made with.
		$versions = array();
		foreach ( array(
			'siteferry' => array( 'generator', 'version' ),
			'wordpress' => array( 'versions', 'wordpress' ),
			'php'       => array( 'versions', 'php' ),
			'mysql'     => array( 'versions', 'mysql' ),
		) as $what => list( $group, $key ) ) {
			$before = self::get( $om, $group, $key );
			$after  = self::get( $nm, $group, $key );
			if ( $before !== $after ) {
				$versions[] = array(
					'what'  => $what,
					'older' => $before,
					'newer' => $after,
				);
			}
		}
		$address = null;
		$home_a  = self::get( $om, 'site', 'home' );
		$home_b  = self::get( $nm, 'site', 'home' );
		if ( strtolower( rtrim( $home_a, '/' ) ) !== strtolower( rtrim( $home_b, '/' ) ) ) {
			$address = array(
				'older' => $home_a,
				'newer' => $home_b,
			);
		}

		// Plugins and themes.
		$known                                = isset( $om['plugins']['installed'], $nm['plugins']['installed'] ) && is_array( $om['plugins']['installed'] ) && is_array( $nm['plugins']['installed'] );
		list( $plugins, $plugins_same )       = self::items(
			$known ? $om['plugins']['installed'] : null,
			$known ? $nm['plugins']['installed'] : null,
			isset( $om['plugins']['active'] ) ? array_map( 'strval', (array) $om['plugins']['active'] ) : array(),
			isset( $nm['plugins']['active'] ) ? array_map( 'strval', (array) $nm['plugins']['active'] ) : array(),
			self::names( $nm, $om, 'plugins' )
		);
		$themes_known                         = isset( $om['themes']['installed'], $nm['themes']['installed'] ) && is_array( $om['themes']['installed'] ) && is_array( $nm['themes']['installed'] );
		list( $themes, $themes_same )         = $themes_known ? self::items( $om['themes']['installed'], $nm['themes']['installed'], array(), array(), self::names( $nm, $om, 'themes' ) ) : array( array(), 0 );
		$theme                                = null;
		$used_a                               = self::get( $om, 'theme', 'stylesheet' );
		$used_b                               = self::get( $nm, 'theme', 'stylesheet' );
		if ( '' !== $used_a && '' !== $used_b && $used_a !== $used_b ) {
			$theme = array(
				'older' => self::name_of( $used_a, self::names( $nm, $om, 'themes' ) ),
				'newer' => self::name_of( $used_b, self::names( $nm, $om, 'themes' ) ),
			);
		}

		// Content counts, when both recorded them.
		$content = null;
		if ( isset( $om['content'], $nm['content'] ) && is_array( $om['content'] ) && is_array( $nm['content'] ) ) {
			$content = array();
			foreach ( self::CONTENT as $what ) {
				if ( isset( $om['content'][ $what ], $nm['content'][ $what ] ) ) {
					$content[ $what ] = array(
						'older' => (int) $om['content'][ $what ],
						'newer' => (int) $nm['content'][ $what ],
					);
				}
			}
			$content = array() === $content ? null : $content;
		}

		return array(
			'older'         => self::side( $old ),
			'newer'         => self::side( $new ),
			'versions'      => $versions,
			'address'       => $address,
			'plugins'       => $plugins,
			'plugins_same'  => $plugins_same,
			'plugins_known' => $known,
			'themes'        => $themes,
			'themes_same'   => $themes_same,
			'theme'         => $theme,
			'content'       => $content,
			'tables'        => self::tables( $om, $nm ),
			'files'         => array(
				'older' => self::files_of( $om ),
				'newer' => self::files_of( $nm ),
			),
		);
	}

	/**
	 * What to show about one backup: name, when, kind, size, where it came from.
	 *
	 * @param array $side Backup.
	 */
	private static function side( array $side ): array {
		$m    = (array) $side['manifest'];
		$base = Incremental::base_of( $m );
		$tags = isset( $m['options']['tags'] ) ? array_map( 'strval', (array) $m['options']['tags'] ) : array();
		$kind = null === $base ? 'full' : 'changes';
		return array(
			'name'      => isset( $side['name'] ) ? (string) $side['name'] : '',
			'created'   => self::get( $m, 'created' ),
			'kind'      => $kind,
			'base'      => null === $base ? null : (string) $base['name'],
			'tags'      => array_values( $tags ),
			'size'      => isset( $side['size'] ) ? (int) $side['size'] : null,
			'encrypted' => ! empty( $side['encrypted'] ),
			'home'      => self::get( $m, 'site', 'home' ),
			'wordpress' => self::get( $m, 'versions', 'wordpress' ),
		);
	}

	/**
	 * Plugins or themes that differ: "added", "removed", "up" or "down" (version), and "on" or
	 * "off" (turned on or off, plugins). Without installed lists ($older/$newer null) only being
	 * turned on or off is compared.
	 *
	 * @param array|null $older      id => [name, version] in the older backup.
	 * @param array|null $newer      id => [name, version] in the newer backup.
	 * @param string[]   $active_old Active in the older backup.
	 * @param string[]   $active_new Active in the newer backup.
	 * @param array      $names      id => [name, version] from any list that has names.
	 * @return array{0:array,1:int}
	 */
	private static function items( ?array $older, ?array $newer, array $active_old, array $active_new, array $names ): array {
		$ids = array_merge( array_keys( (array) $older ), array_keys( (array) $newer ), $active_old, $active_new );
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		sort( $ids );
		$list = array();
		$same = 0;
		foreach ( $ids as $id ) {
			$before = null !== $older && isset( $older[ $id ] ) ? self::version( $older[ $id ] ) : null;
			$after  = null !== $newer && isset( $newer[ $id ] ) ? self::version( $newer[ $id ] ) : null;
			$change = '';
			if ( null !== $older && null !== $newer ) {
				if ( null === $before && null !== $after ) {
					$change = 'added';
				} elseif ( null !== $before && null === $after ) {
					$change = 'removed';
				} elseif ( null !== $before && $before !== $after ) {
					$change = version_compare( (string) $after, $before, '>' ) ? 'up' : 'down';
				}
			}
			$on_old   = in_array( $id, $active_old, true );
			$on_new   = in_array( $id, $active_new, true );
			$activity = $on_old === $on_new ? '' : ( $on_new ? 'on' : 'off' );
			if ( '' === $change && '' === $activity ) {
				++$same;
				continue;
			}
			$list[] = array(
				'id'       => $id,
				'name'     => self::name_of( $id, $names ),
				'older'    => $before,
				'newer'    => $after,
				'change'   => $change,
				'activity' => $activity,
			);
		}
		return array( $list, $same );
	}

	/**
	 * Installed lists of both backups, newer first, for names.
	 *
	 * @param array  $nm   Newer manifest.
	 * @param array  $om   Older manifest.
	 * @param string $kind "plugins" or "themes".
	 */
	private static function names( array $nm, array $om, string $kind ): array {
		$names = array();
		foreach ( array( $om, $nm ) as $m ) {
			if ( isset( $m[ $kind ]['installed'] ) && is_array( $m[ $kind ]['installed'] ) ) {
				$names = $m[ $kind ]['installed'] + $names;
			}
		}
		return $names;
	}

	/**
	 * Tables added and removed (by name after each backup's prefix), row counts that changed, and
	 * the dump's size.
	 *
	 * @param array $om Older manifest.
	 * @param array $nm Newer manifest.
	 */
	private static function tables( array $om, array $nm ): array {
		$rows = static function ( array $m ): array {
			$prefix = isset( $m['database']['prefix'] ) ? (string) $m['database']['prefix'] : '';
			$out    = array();
			foreach ( isset( $m['database']['tables'] ) ? (array) $m['database']['tables'] : array() as $table ) {
				$name         = isset( $table['name'] ) ? (string) $table['name'] : '';
				$short        = '' !== $prefix && 0 === strpos( $name, $prefix ) ? substr( $name, strlen( $prefix ) ) : $name;
				$out[ $short ] = isset( $table['rows'] ) ? (int) $table['rows'] : 0;
			}
			return $out;
		};
		$a       = $rows( $om );
		$b       = $rows( $nm );
		$changed = array();
		$same    = 0;
		foreach ( array_intersect_key( $b, $a ) as $name => $count ) {
			if ( $a[ $name ] !== $count ) {
				$changed[] = array(
					'name'  => (string) $name,
					'older' => $a[ $name ],
					'newer' => $count,
				);
			} else {
				++$same;
			}
		}
		usort(
			$changed,
			static function ( array $x, array $y ): int {
				return abs( $y['newer'] - $y['older'] ) - abs( $x['newer'] - $x['older'] );
			}
		);
		$added   = array_map( 'strval', array_keys( array_diff_key( $b, $a ) ) );
		$removed = array_map( 'strval', array_keys( array_diff_key( $a, $b ) ) );
		sort( $added );
		sort( $removed );
		return array(
			'added'   => $added,
			'removed' => $removed,
			'changed' => $changed,
			'same'    => $same,
			'size'    => array(
				'older' => isset( $om['database']['size'] ) ? (int) $om['database']['size'] : 0,
				'newer' => isset( $nm['database']['size'] ) ? (int) $nm['database']['size'] : 0,
			),
		);
	}

	/**
	 * The files a backup holds: count, bytes, what was left out (null when not recorded), and for a
	 * changes-only backup how many of them changed.
	 *
	 * @param array $m Manifest.
	 */
	private static function files_of( array $m ): array {
		$holds = Backups::holds( $m );
		return array(
			'files'    => isset( $m['files']['files'] ) ? (int) $m['files']['files'] : 0,
			'bytes'    => isset( $m['files']['bytes'] ) ? (int) $m['files']['bytes'] : 0,
			'left_out' => null === $holds ? null : $holds['left_out'],
			'changed'  => isset( $m['changes']['files'] ) ? (int) $m['changes']['files'] : null,
		);
	}

	/**
	 * A nested manifest value as a string, '' when missing.
	 *
	 * @param array  $m    Manifest.
	 * @param string ...$keys Path.
	 */
	private static function get( array $m, string ...$keys ): string {
		foreach ( $keys as $key ) {
			if ( ! is_array( $m ) || ! isset( $m[ $key ] ) ) {
				return '';
			}
			$m = $m[ $key ];
		}
		return is_scalar( $m ) ? (string) $m : '';
	}

	/**
	 * A version from [name, version].
	 *
	 * @param mixed $item [name, version].
	 */
	private static function version( $item ): string {
		return is_array( $item ) && isset( $item[1] ) ? (string) $item[1] : '';
	}

	/**
	 * The name to show: from the first list that has one, else the id.
	 *
	 * @param string $id    Plugin file or theme folder.
	 * @param array  ...$lists id => [name, version].
	 */
	private static function name_of( string $id, array ...$lists ): string {
		foreach ( $lists as $list ) {
			if ( isset( $list[ $id ][0] ) && '' !== trim( (string) $list[ $id ][0] ) ) {
				return (string) $list[ $id ][0];
			}
		}
		return $id;
	}
}
