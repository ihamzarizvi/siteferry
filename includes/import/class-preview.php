<?php
/**
 * What a restore would change on this site, worked out before it starts.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a backup's manifest with this site's facts (Site_Info), for what the chosen restore
 * puts back:
 *
 * - plugins and themes: put back whole from the backup when the files are restored (and the
 *   item is chosen); ones added since the backup stay. Their versions are known for backups made
 *   by SiteFerry 0.29.0 or later; older backups and .wpress files only say which were active;
 * - which plugins are active and which theme is used: from the backup's options table, when it is
 *   restored;
 * - how many posts, pages, media items, users and approved comments there are: from their
 *   tables, when they are restored (backups made by 0.29.0 or later).
 *
 * WordPress itself is not restored (only wp-content and the database), and SiteFerry stays as it
 * is, so neither is compared. Reads only; the result is shown before the restore is confirmed.
 */
final class Preview {

	/** Content counted in a backup and on the site, and the table each lives in. */
	const CONTENT = array(
		'posts'    => 'posts',
		'pages'    => 'posts',
		'media'    => 'posts',
		'users'    => 'users',
		'comments' => 'comments',
	);

	/**
	 * The changes.
	 *
	 * @param array $manifest Backup manifest.
	 * @param array $site     This site's facts (Site_Info::collect()).
	 * @param array $scope    What is restored: database (bool), tables (full names, [] = all),
	 *                        files (bool), paths (wp-content-relative, [] = all), own_plugin
	 *                        (SiteFerry's plugin file) and own_folder (its folder).
	 * @return array{known:bool,plugins:array,plugins_same:int,themes:array,themes_same:int,theme:?array,content:?array,network:bool}
	 */
	public static function compare( array $manifest, array $site, array $scope ): array {
		$scope += array(
			'database'   => true,
			'tables'     => array(),
			'files'      => true,
			'paths'      => array(),
			'own_plugin' => '',
			'own_folder' => '',
		);
		$prefix  = isset( $manifest['database']['prefix'] ) ? (string) $manifest['database']['prefix'] : '';
		$options = self::table_restored( $scope, $prefix, 'options' );

		$then_plugins = isset( $manifest['plugins']['installed'] ) && is_array( $manifest['plugins']['installed'] ) ? $manifest['plugins']['installed'] : null;
		$then_themes  = isset( $manifest['themes']['installed'] ) && is_array( $manifest['themes']['installed'] ) ? $manifest['themes']['installed'] : null;
		$now_plugins  = isset( $site['plugins_installed'] ) && is_array( $site['plugins_installed'] ) ? $site['plugins_installed'] : array();
		$now_themes   = isset( $site['themes_installed'] ) && is_array( $site['themes_installed'] ) ? $site['themes_installed'] : array();

		$active_then = isset( $manifest['plugins']['active'] ) && is_array( $manifest['plugins']['active'] ) ? array_map( 'strval', $manifest['plugins']['active'] ) : null;
		$active_now  = isset( $site['active_plugins'] ) ? array_map( 'strval', (array) $site['active_plugins'] ) : array();

		list( $plugins, $plugins_same ) = self::items(
			$now_plugins,
			$then_plugins,
			$active_now,
			$options && null !== $active_then ? $active_then : null,
			static function ( string $file ) use ( $scope ): bool {
				$folder = false === strpos( $file, '/' ) ? $file : (string) strstr( $file, '/', true );
				return self::path_restored( $scope, 'plugins', $folder );
			},
			static function ( string $file ) use ( $scope ): bool {
				$folder = false === strpos( $file, '/' ) ? $file : (string) strstr( $file, '/', true );
				return $file === $scope['own_plugin'] || ( '' !== $scope['own_folder'] && $folder === $scope['own_folder'] );
			}
		);

		// Themes: their versions; which one is used comes from the options table.
		$used_now  = isset( $site['stylesheet'] ) ? (string) $site['stylesheet'] : '';
		$used_then = isset( $manifest['theme']['stylesheet'] ) ? (string) $manifest['theme']['stylesheet'] : '';
		list( $themes, $themes_same ) = self::items(
			$now_themes,
			$then_themes,
			array(),
			null,
			static function ( string $slug ) use ( $scope ): bool {
				return self::path_restored( $scope, 'themes', $slug );
			},
			static function (): bool {
				return false;
			}
		);
		$theme = null;
		if ( $options && '' !== $used_then && $used_then !== $used_now ) {
			$theme = array(
				'now'  => self::name_of( $used_now, $now_themes, $then_themes ),
				'then' => self::name_of( $used_then, (array) $then_themes, $now_themes ),
			);
		}

		// Content, for the tables that are restored.
		$content = null;
		if ( isset( $manifest['content'] ) && is_array( $manifest['content'] ) && isset( $site['content'] ) && is_array( $site['content'] ) ) {
			$content = array();
			foreach ( self::CONTENT as $what => $table ) {
				if ( isset( $manifest['content'][ $what ], $site['content'][ $what ] ) && self::table_restored( $scope, $prefix, $table ) ) {
					$content[ $what ] = array(
						'now'  => (int) $site['content'][ $what ],
						'then' => (int) $manifest['content'][ $what ],
					);
				}
			}
			$content = array() === $content ? null : $content;
		}

		return array(
			'known'        => null !== $then_plugins,
			'plugins'      => $plugins,
			'plugins_same' => $plugins_same,
			'themes'       => $themes,
			'themes_same'  => $themes_same,
			'theme'        => $theme,
			'content'      => $content,
			'network'      => ! empty( $manifest['site']['network'] ),
		);
	}

	/**
	 * Plugins or themes that change, and how many stay as they are.
	 *
	 * Change: "back" (not here now, the backup puts it back), "older" or "newer" (the backup's
	 * version replaces this one), "stays" (not in the backup: left as it is; listed only when it
	 * would be turned off). Activation: "on" or "off" when the restored options change it.
	 *
	 * @param array      $now         Installed here: id => [name, version].
	 * @param array|null $then        In the backup: id => [name, version]; null when not recorded.
	 * @param string[]   $active_now  Active here (plugins).
	 * @param array|null $active_then Active after the restore (null: activation does not change).
	 * @param callable   $restored    Whether an id's files are restored.
	 * @param callable   $own         Whether an id is SiteFerry itself.
	 * @return array{0:array,1:int}
	 */
	private static function items( array $now, ?array $then, array $active_now, ?array $active_then, callable $restored, callable $own ): array {
		$ids = array_keys( $now + ( null === $then ? array() : $then ) );
		if ( null !== $active_then ) {
			$ids = array_merge( $ids, $active_then, $active_now );
		}
		$ids = array_values( array_unique( array_map( 'strval', $ids ) ) );
		sort( $ids );

		$list = array();
		$same = 0;
		foreach ( $ids as $id ) {
			if ( $own( $id ) ) {
				continue;
			}
			$here     = isset( $now[ $id ] ) ? self::version( $now[ $id ] ) : null;
			$backup   = null !== $then && isset( $then[ $id ] ) ? self::version( $then[ $id ] ) : null;
			$puts     = null !== $backup && $restored( $id );
			$change   = '';
			$after    = $here;
			if ( $puts && null === $here ) {
				$change = 'back';
				$after  = $backup;
			} elseif ( $puts && $backup !== $here ) {
				$change = version_compare( $backup, $here, '<' ) ? 'older' : 'newer';
				$after  = $backup;
			} elseif ( null !== $then && null !== $here && null === $backup ) {
				$change = 'stays';
			}
			$installed = null !== $after || ( null === $then && null !== $active_then && in_array( $id, $active_then, true ) );
			$activity  = '';
			if ( null !== $active_then ) {
				$on_now   = in_array( $id, $active_now, true );
				$on_after = $installed && in_array( $id, $active_then, true );
				if ( $on_now !== $on_after ) {
					$activity = $on_after ? 'on' : 'off';
				}
			}
			if ( ( '' === $change || 'stays' === $change ) && '' === $activity ) {
				++$same;
				continue;
			}
			$list[] = array(
				'id'       => $id,
				'name'     => self::name_of( $id, $now, null === $then ? array() : $then ),
				'now'      => $here,
				'then'     => $backup,
				'change'   => $change,
				'activity' => $activity,
			);
		}
		return array( $list, $same );
	}

	/**
	 * Whether a table (by its name without prefix) is restored.
	 *
	 * @param array  $scope  Scope.
	 * @param string $prefix The backup's table prefix.
	 * @param string $table  Table name without prefix.
	 */
	private static function table_restored( array $scope, string $prefix, string $table ): bool {
		return $scope['database'] && ( array() === $scope['tables'] || in_array( $prefix . $table, $scope['tables'], true ) );
	}

	/**
	 * Whether a plugin's or theme's files are restored.
	 *
	 * @param array  $scope Scope.
	 * @param string $kind  "plugins" or "themes".
	 * @param string $name  Folder (or single file) name.
	 */
	private static function path_restored( array $scope, string $kind, string $name ): bool {
		if ( ! $scope['files'] ) {
			return false;
		}
		$paths = (array) $scope['paths'];
		return array() === $paths || in_array( $kind, $paths, true ) || in_array( $kind . '/' . $name, $paths, true );
	}

	/**
	 * A version from [name, version], or '' when it has none.
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
	 * @param array  $first id => [name, version].
	 * @param array  $other id => [name, version].
	 */
	private static function name_of( string $id, array $first, ?array $other ): string {
		foreach ( array( $first, (array) $other ) as $list ) {
			if ( isset( $list[ $id ][0] ) && '' !== trim( (string) $list[ $id ][0] ) ) {
				return (string) $list[ $id ][0];
			}
		}
		return $id;
	}
}
