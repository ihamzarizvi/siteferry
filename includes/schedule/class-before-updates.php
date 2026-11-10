<?php
/**
 * A quick backup before WordPress, a plugin or a theme updates.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Schedule;

use SiteFerry\Archive\Io;
use SiteFerry\Export\Export;
use SiteFerry\Export\Site_Info;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * When "Back up before updates" is on: just before WordPress downloads an update (the filter
 * upgrader_pre_download, before anything changes), backs up the database and the plugin or theme
 * being updated (only the database for WordPress itself), on this server, tagged "before-update".
 * Restoring such a backup puts back the database and that plugin or theme as they were; a restore
 * replaces only what a backup contains, so nothing else on the site is touched.
 *
 * It runs in the update's own request, so it has a time allowance (two minutes when the time limit
 * can be raised, else half of it): when the backup cannot be finished in time, it is stopped and
 * the update goes ahead without it. An update is never blocked. Only the newest few such backups
 * are kept.
 *
 * The same quick backup is made before risky changes, each with its own switch (on unless turned
 * off), sharing the number to keep:
 * - deleting a plugin: the database and that plugin's folder, tagged "before-plugin-delete". It
 *   hooks pre_uninstall_plugin (WordPress runs a plugin's uninstall code, which may delete its
 *   data, BEFORE the delete_plugin action) and delete_plugin (plugins without uninstall code);
 * - deleting a theme: the database and that theme's folder ("before-theme-delete"), on the
 *   delete_theme action, which runs before the files are removed;
 * - switching the theme: the database only ("before-theme-switch"; theme files stay). The
 *   switch_theme action runs after the switch, so it hooks pre_update_option_template and
 *   pre_update_option_stylesheet instead: switch_theme() updates the template option first, and
 *   these filters run before the new value is written, in every way of switching (Themes screen,
 *   Customizer, WP-CLI). Not while WordPress installs, nor inside switch_to_blog().
 */
final class Before_Updates {

	const TAG = 'before-update';

	/** Tags of the backups before risky changes, by kind of change. */
	const TAGS = array(
		'plugin-delete' => 'before-plugin-delete',
		'theme-delete'  => 'before-theme-delete',
		'theme-switch'  => 'before-theme-switch',
	);

	/** The setting that turns each kind of change's backup on. */
	const SETTINGS = array(
		'update'        => 'before_updates',
		'plugin-delete' => 'before_plugin_delete',
		'theme-delete'  => 'before_theme_delete',
		'theme-switch'  => 'before_theme_switch',
	);

	/** Seconds the backup may take when the time limit could be raised. */
	const SECONDS = 120.0;

	/** Backups made in this request: "plugin:<file>", "theme:<slug>", "core". */
	private static $done = array();

	/**
	 * Hooks the update downloads.
	 */
	public static function register(): void {
		add_filter( 'upgrader_pre_download', array( self::class, 'on_pre_download' ), 10, 4 );
		add_action( 'pre_uninstall_plugin', array( self::class, 'on_plugin_delete' ), 10, 1 );
		add_action( 'delete_plugin', array( self::class, 'on_plugin_delete' ), 10, 1 );
		add_action( 'delete_theme', array( self::class, 'on_theme_delete' ), 10, 1 );
		add_filter( 'pre_update_option_template', array( self::class, 'on_theme_switch' ), 10, 2 );
		add_filter( 'pre_update_option_stylesheet', array( self::class, 'on_theme_switch' ), 10, 2 );
	}

	/**
	 * Whether the backup before a kind of change is turned on.
	 *
	 * @param string $change   update, plugin-delete, theme-delete or theme-switch.
	 * @param array  $settings Schedule settings.
	 */
	public static function wanted( string $change, array $settings ): bool {
		return isset( self::SETTINGS[ $change ] ) && ! empty( $settings[ self::SETTINGS[ $change ] ] );
	}

	/**
	 * Action pre_uninstall_plugin / delete_plugin: a backup before a plugin is deleted.
	 *
	 * @param mixed $file Plugin file, such as akismet/akismet.php.
	 */
	public static function on_plugin_delete( $file ): void {
		if ( ! is_string( $file ) || '' === $file || ! self::wanted( 'plugin-delete', Schedule::settings() ) ) {
			return;
		}
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		self::run(
			array(
				'key'     => 'plugin-delete:' . $file,
				'type'    => 'plugin',
				'change'  => 'plugin-delete',
				'folder'  => false === strpos( $file, '/' ) ? $file : dirname( $file ),
				'name'    => isset( $plugins[ $file ]['Name'] ) && '' !== $plugins[ $file ]['Name'] ? (string) $plugins[ $file ]['Name'] : $file,
				'version' => isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '',
			)
		);
	}

	/**
	 * Action delete_theme: a backup before a theme is deleted.
	 *
	 * @param mixed $slug Theme folder.
	 */
	public static function on_theme_delete( $slug ): void {
		if ( ! is_string( $slug ) || '' === $slug || ! self::wanted( 'theme-delete', Schedule::settings() ) ) {
			return;
		}
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme( $slug ) : null;
		self::run(
			array(
				'key'     => 'theme-delete:' . $slug,
				'type'    => 'theme',
				'change'  => 'theme-delete',
				'folder'  => $slug,
				'name'    => null !== $theme && $theme->exists() ? (string) $theme->get( 'Name' ) : $slug,
				'version' => null !== $theme && $theme->exists() ? (string) $theme->get( 'Version' ) : '',
			)
		);
	}

	/**
	 * Filters pre_update_option_template / pre_update_option_stylesheet: a backup of the database
	 * before the theme in use changes. Returns the new value unchanged.
	 *
	 * @param mixed $value New value.
	 * @param mixed $old   Current value.
	 * @return mixed
	 */
	public static function on_theme_switch( $value, $old = null ) {
		if ( ! is_string( $value ) || $value === $old || '' === $value ) {
			return $value;
		}
		if ( ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) || ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) ) {
			return $value;
		}
		if ( ! self::wanted( 'theme-switch', Schedule::settings() ) ) {
			return $value;
		}
		$current = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		self::run(
			array(
				'key'     => 'theme-switch',
				'type'    => 'database',
				'change'  => 'theme-switch',
				'folder'  => '',
				'name'    => null !== $current && $current->exists() ? (string) $current->get( 'Name' ) : (string) $old,
				'version' => '',
			)
		);
		return $value;
	}

	/**
	 * Makes one backup per change and request; never lets a failure stop the change.
	 *
	 * @param array $item What changes.
	 */
	private static function run( array $item ): void {
		if ( isset( self::$done[ $item['key'] ] ) ) {
			return;
		}
		self::$done[ $item['key'] ] = true;
		try {
			self::backup( $item );
		} catch ( \Throwable $e ) {
			self::record( $item, 'failed', $e->getMessage(), array() );
		}
	}

	/**
	 * Filter upgrader_pre_download: makes the backup, then lets the download go ahead unchanged.
	 *
	 * @param mixed  $reply      False to download as usual (anything else: another plugin handled it).
	 * @param string $package    Package URL.
	 * @param mixed  $upgrader   The upgrader.
	 * @param mixed  $hook_extra What is being updated.
	 * @return mixed $reply, unchanged.
	 */
	public static function on_pre_download( $reply, $package = '', $upgrader = null, $hook_extra = array() ) {
		unset( $package );
		if ( false !== $reply || ! self::wanted( 'update', Schedule::settings() ) ) {
			return $reply;
		}
		$item = self::item( $upgrader, is_array( $hook_extra ) ? $hook_extra : array() );
		if ( null !== $item ) {
			self::run( $item );
		}
		return $reply;
	}

	/**
	 * What an update is about to change, or null for anything else (new installs, translations).
	 *
	 * @param mixed $upgrader   The upgrader.
	 * @param array $hook_extra Its details.
	 * @return array{key:string,type:string,folder:string,name:string,version:string}|null
	 */
	public static function item( $upgrader, array $hook_extra ): ?array {
		if ( $upgrader instanceof \Plugin_Upgrader && isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) && '' !== $hook_extra['plugin'] ) {
			$file    = $hook_extra['plugin'];
			$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
			return array(
				'key'     => 'plugin:' . $file,
				'type'    => 'plugin',
				'folder'  => false === strpos( $file, '/' ) ? $file : dirname( $file ),
				'name'    => isset( $plugins[ $file ]['Name'] ) && '' !== $plugins[ $file ]['Name'] ? (string) $plugins[ $file ]['Name'] : $file,
				'version' => isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '',
			);
		}
		if ( $upgrader instanceof \Theme_Upgrader && isset( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) && '' !== $hook_extra['theme'] ) {
			$slug  = $hook_extra['theme'];
			$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme( $slug ) : null;
			return array(
				'key'     => 'theme:' . $slug,
				'type'    => 'theme',
				'folder'  => $slug,
				'name'    => null !== $theme && $theme->exists() ? (string) $theme->get( 'Name' ) : $slug,
				'version' => null !== $theme && $theme->exists() ? (string) $theme->get( 'Version' ) : '',
			);
		}
		if ( $upgrader instanceof \Core_Upgrader ) {
			return array(
				'key'     => 'core',
				'type'    => 'core',
				'folder'  => '',
				'name'    => 'WordPress',
				'version' => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			);
		}
		return null;
	}

	/**
	 * Export options for such a backup: the database, and only the plugin or theme being updated.
	 *
	 * @param array    $item    What is updated (item()).
	 * @param string[] $content Names at the top of wp-content.
	 * @param string[] $plugins Names in the plugins folder.
	 * @param string[] $themes  Names in the themes folder.
	 * @param string   $plugins_rel Plugins folder relative to wp-content ("plugins").
	 * @param string   $themes_rel  Themes folder relative to wp-content ("themes").
	 */
	public static function export_options( array $item, array $content, array $plugins, array $themes, string $plugins_rel = 'plugins', string $themes_rel = 'themes' ): array {
		$keep  = 'plugin' === $item['type'] ? $plugins_rel : ( 'theme' === $item['type'] ? $themes_rel : '' );
		$paths = array();
		foreach ( $content as $name ) {
			if ( $name !== $keep && $name !== $plugins_rel && $name !== $themes_rel ) {
				$paths[] = $name; // Everything else in wp-content (languages, mu-plugins, other folders).
			}
		}
		$inside = 'plugin' === $item['type'] ? array( $plugins_rel, $plugins ) : ( 'theme' === $item['type'] ? array( $themes_rel, $themes ) : null );
		if ( null !== $inside ) {
			foreach ( $inside[1] as $name ) {
				if ( $name !== $item['folder'] ) {
					$paths[] = $inside[0] . '/' . $name;
				}
			}
		}
		$change = isset( $item['change'] ) ? (string) $item['change'] : 'update';
		return array(
			'title'           => self::title( $item ),
			'tags'            => array( isset( self::TAGS[ $change ] ) ? self::TAGS[ $change ] : self::TAG ),
			'exclude_media'   => true,
			'exclude_plugins' => 'plugin' !== $item['type'],
			'exclude_themes'  => 'theme' !== $item['type'],
			'exclude_paths'   => $paths,
		);
	}

	/**
	 * The backup's title: what was about to change.
	 *
	 * @param array $item What changes.
	 */
	public static function title( array $item ): string {
		$change = isset( $item['change'] ) ? (string) $item['change'] : 'update';
		if ( 'plugin-delete' === $change ) {
			/* translators: 1: name of a plugin, 2: its version */
			return trim( sprintf( __( 'Before deleting the plugin %1$s %2$s', 'siteferry' ), $item['name'], $item['version'] ) );
		}
		if ( 'theme-delete' === $change ) {
			/* translators: 1: name of a theme, 2: its version */
			return trim( sprintf( __( 'Before deleting the theme %1$s %2$s', 'siteferry' ), $item['name'], $item['version'] ) );
		}
		if ( 'theme-switch' === $change ) {
			/* translators: %s: name of the theme in use before the switch */
			return sprintf( __( 'Before switching away from the theme %s', 'siteferry' ), $item['name'] );
		}
		/* translators: 1: name of the plugin, theme or WordPress, 2: its version */
		return trim( sprintf( __( 'Before updating %1$s %2$s', 'siteferry' ), $item['name'], $item['version'] ) );
	}

	/**
	 * Makes the backup within the time allowance, keeps the newest few, records the outcome.
	 *
	 * @param array $item What is updated.
	 */
	private static function backup( array $item ): void {
		if ( null !== Job::active( Storage::jobs_dir() ) ) {
			self::record( $item, 'skipped', 'Another backup, restore or transfer was running.', array() );
			return;
		}
		$site    = Site_Info::collect();
		$content = (string) $site['wp_content_dir'];
		$options = self::export_options(
			$item,
			self::names( $content ),
			self::names( (string) $site['plugins_dir'] ),
			self::names( (string) $site['themes_dir'] ),
			self::relative( (string) $site['plugins_dir'], $content, 'plugins' ),
			self::relative( (string) $site['themes_dir'], $content, 'themes' )
		);
		list( $job ) = Export::start( Schedule::with_password( $options ), null, 'update' );
		$job->log( 'info', 'Started: ' . self::title( $item ) . '.' );

		$raised  = (bool) Io::quiet(
			static function () {
				return set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- a backup or restore slice may need longer than the default limit.
			}
		);
		$limit   = (int) ini_get( 'max_execution_time' );
		$seconds = $raised || $limit <= 0 ? self::SECONDS : max( 5.0, $limit / 2 );
		$seconds = (float) apply_filters( 'siteferry_update_backup_seconds', $seconds, $item );
		$until   = microtime( true ) + $seconds;
		while ( ! $job->finished() && microtime( true ) < $until ) {
			Runner::run_slice( $job, new Slice_Budget( max( 0.0, $until - microtime( true ) ) ) );
		}
		if ( ! $job->finished() ) {
			Runner::cancel( $job, 'Stopped: the quick backup took too long; the change went ahead without it.' );
			self::record( $item, 'skipped', 'It took too long, so the change went ahead without it.', array() );
			return;
		}
		if ( Job::STATUS_DONE !== $job->status() ) {
			self::record( $item, 'failed', (string) $job->error(), array() );
			return;
		}
		$result = $job->result();
		self::record( $item, 'done', '', array( 'name' => (string) $result['name'], 'size' => (int) $result['size'] ) );
	}

	/**
	 * Remembers the outcome and, after a backup, deletes the oldest backups made before updates
	 * beyond the number to keep.
	 *
	 * @param array  $item    What was updated.
	 * @param string $status  done, skipped or failed.
	 * @param string $message Why, for skipped and failed.
	 * @param array  $backup  name, size.
	 */
	private static function record( array $item, string $status, string $message, array $backup ): void {
		$state = Schedule::state();
		if ( 'done' === $status ) {
			$state['update_runs'][] = array(
				'name'        => $backup['name'],
				'time'        => time(),
				'local'       => true,
				'destination' => '',
			);
			$keep = max( 1, (int) Schedule::settings()['update_retain'] );
			foreach ( Schedule::expired( $state['update_runs'], $keep ) as $run ) {
				if ( null === Schedule::delete_run( $run ) ) {
					$state['update_runs'] = array_values(
						array_filter(
							$state['update_runs'],
							static function ( array $kept ) use ( $run ): bool {
								return $kept['name'] !== $run['name'];
							}
						)
					);
				}
			}
		}
		$state['last_update'] = array(
			'status'  => $status,
			'time'    => time(),
			'what'    => trim( $item['name'] . ' ' . $item['version'] ),
			'title'   => self::title( $item ),
			'change'  => isset( $item['change'] ) ? (string) $item['change'] : 'update',
			'message' => $message,
			'name'    => isset( $backup['name'] ) ? (string) $backup['name'] : '',
			'size'    => isset( $backup['size'] ) ? (int) $backup['size'] : 0,
		);
		Schedule::save_state( $state );
	}

	/**
	 * Names in a folder, without "." and "..", hidden entries or anything unreadable.
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	private static function names( string $dir ): array {
		$names = is_dir( $dir ) ? scandir( $dir ) : false;
		return array_values(
			array_filter(
				false === $names ? array() : $names,
				static function ( string $name ): bool {
					return '' !== $name && '.' !== $name[0];
				}
			)
		);
	}

	/**
	 * A folder relative to wp-content, or $default when it is elsewhere.
	 *
	 * @param string $path    Folder.
	 * @param string $content wp-content.
	 * @param string $default Fallback.
	 */
	private static function relative( string $path, string $content, string $default ): string {
		$prefix = rtrim( $content, '/' ) . '/';
		return 0 === strpos( $path, $prefix ) ? substr( $path, strlen( $prefix ) ) : $default;
	}
}
