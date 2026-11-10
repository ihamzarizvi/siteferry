<?php
/**
 * Backups before updates, inside WordPress: WordPress's own filter upgrader_pre_download is applied
 * with real upgrader objects, as an update would (nothing is downloaded or updated). Checks what
 * each backup holds (the database and only that plugin or theme; the database only for
 * WordPress), that the download goes ahead unchanged, retention, the time allowance, a busy site,
 * translations and new installs, and the setting being off. Removes its backups and settings.
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-before-updates.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Archive\Tar_Reader;
use SiteFerry\Export\Export;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Runner;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Schedule\Before_Updates' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

if ( false !== get_option( Schedule::OPTION ) || false !== get_option( Schedule::STATE ) ) {
	echo "Backup settings (schedule) already exist on this site; this test would replace them. Remove them first.\n";
	exit( 1 );
}
if ( null !== Job::active( Storage::jobs_dir() ) ) {
	echo "Another SiteFerry job is active on this site; finish or cancel it first.\n";
	exit( 1 );
}

echo 'Before-update backup tests on ' . home_url() . "\n";

// A plugin to "update": the first one in a folder that is not SiteFerry.
$sf_plugin = '';
foreach ( array_keys( get_plugins() ) as $sf_file ) {
	if ( false !== strpos( $sf_file, '/' ) && 0 !== strpos( $sf_file, 'siteferry/' ) ) {
		$sf_plugin = $sf_file;
		break;
	}
}
$sf_theme = get_stylesheet();
$sf_made  = array();

/**
 * Applies the filter WordPress applies right before downloading an update.
 *
 * @param WP_Upgrader $upgrader   Upgrader.
 * @param array       $hook_extra What is updated.
 * @return mixed The filter's answer.
 */
function sf_pre_download( $upgrader, array $hook_extra ) {
	return apply_filters( 'upgrader_pre_download', false, 'https://downloads.example.invalid/package.zip', $upgrader, $hook_extra );
}

/**
 * The outcome of the last backup before an update (read fresh).
 */
function sf_last_update(): ?array {
	wp_cache_delete( Schedule::STATE, 'options' );
	return Schedule::state()['last_update'];
}

/**
 * Paths inside a backup, without the manifest, the dump and the checksum list.
 *
 * @param string $name Backup file name.
 * @return string[]
 */
function sf_paths( string $name ): array {
	$reader = Tar_Reader::open( Storage::backups_dir() . '/' . $name );
	$paths  = array();
	while ( null !== ( $entry = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		$paths[] = $entry->name;
	}
	$reader->close();
	return $paths;
}

/**
 * Paths in wp-content that are not the given folder (or its parents).
 *
 * @param string[] $paths  Paths in the backup.
 * @param string   $folder Folder that may be there, such as "wp-content/plugins/akismet" ('' for none).
 * @return string[]
 */
function sf_outside( array $paths, string $folder ): array {
	$bad = array();
	foreach ( $paths as $path ) {
		$path = rtrim( $path, '/' );
		if ( 0 !== strpos( $path, 'wp-content' ) ) {
			continue; // The manifest, the dump, the checksum list.
		}
		$parent = '' !== $folder && 0 === strpos( $folder . '/', $path . '/' );
		$inside = '' !== $folder && ( $path === $folder || 0 === strpos( $path, $folder . '/' ) );
		if ( ! $parent && ! $inside && ! ( '' === $folder && 'wp-content' === $path ) ) {
			$bad[] = $path;
		}
	}
	return $bad;
}

Sf_Test::run(
	'off by default: an update makes no backup',
	static function () use ( $sf_plugin ) {
		$reply = sf_pre_download( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), array( 'plugin' => $sf_plugin, 'type' => 'plugin', 'action' => 'update' ) );
		Sf_Test::same( false, $reply, 'the download goes ahead:' );
		Sf_Test::same( null, sf_last_update() );
	}
);

Schedule::save(
	array(
		'before_updates' => true,
		'update_retain'  => 2,
	)
);

Sf_Test::run(
	'a plugin update: the database and only that plugin, tagged "before-update"; the download goes ahead',
	static function () use ( $sf_plugin, &$sf_made ) {
		Sf_Test::ok( '' !== $sf_plugin, 'a plugin to try' );
		$reply = sf_pre_download( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), array( 'plugin' => $sf_plugin, 'type' => 'plugin', 'action' => 'update' ) );
		Sf_Test::same( false, $reply, 'the download goes ahead unchanged:' );
		$last = sf_last_update();
		Sf_Test::same( 'done', $last['status'], wp_json_encode( $last ) );
		$sf_made[] = $last['name'];
		$folder    = dirname( $sf_plugin );
		$paths     = sf_paths( $last['name'] );
		Sf_Test::ok( in_array( 'database.sql', $paths, true ), 'has the database' );
		$content = array_filter(
			$paths,
			static function ( string $path ): bool {
				return 0 === strpos( $path, 'wp-content/' );
			}
		);
		Sf_Test::ok( count( $content ) > 2, 'has the plugin\'s files' );
		Sf_Test::same( array(), sf_outside( $paths, 'wp-content/plugins/' . $folder ), 'only that plugin:' );
		$data  = get_plugins()[ $sf_plugin ];
		$label = \SiteFerry\Labels::get( $last['name'] );
		Sf_Test::same( trim( 'Before updating ' . $data['Name'] . ' ' . $data['Version'] ), $label['title'] );
		Sf_Test::same( array( 'before-update' ), $label['tags'] );

		sf_pre_download( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), array( 'plugin' => $sf_plugin, 'type' => 'plugin', 'action' => 'update' ) );
		Sf_Test::same( $last['time'], sf_last_update()['time'], 'once per item per request (a retried download makes no second backup):' );
	}
);

Sf_Test::run(
	'a theme update holds only that theme; a WordPress update only the database; retention keeps the newest two',
	static function () use ( $sf_theme, &$sf_made ) {
		sf_pre_download( new Theme_Upgrader( new Automatic_Upgrader_Skin() ), array( 'theme' => $sf_theme, 'type' => 'theme', 'action' => 'update' ) );
		$theme     = sf_last_update();
		$sf_made[] = $theme['name'];
		Sf_Test::same( 'done', $theme['status'], wp_json_encode( $theme ) );
		$theme_paths = sf_paths( $theme['name'] );
		Sf_Test::ok( in_array( 'wp-content/themes/' . $sf_theme . '/style.css', $theme_paths, true ), 'has the theme' );
		Sf_Test::same( array(), sf_outside( $theme_paths, 'wp-content/themes/' . $sf_theme ), 'only that theme:' );

		sf_pre_download( new Core_Upgrader( new Automatic_Upgrader_Skin() ), array() );
		$core      = sf_last_update();
		$sf_made[] = $core['name'];
		Sf_Test::same( 'done', $core['status'], wp_json_encode( $core ) );
		Sf_Test::same( array(), sf_outside( sf_paths( $core['name'] ), '' ), 'no files, only the database:' );
		Sf_Test::same( 'WordPress ' . get_bloginfo( 'version' ), $core['what'] );

		Sf_Test::ok( ! is_file( Storage::backups_dir() . '/' . $sf_made[0] ), 'the oldest of three was deleted (keep 2)' );
		Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $theme['name'] ) && is_file( Storage::backups_dir() . '/' . $core['name'] ), 'the newest two kept' );
		Sf_Test::same( 2, Schedule::overview()['update_kept'] );
	}
);

Sf_Test::run(
	'never blocks an update: too slow, busy, a translation or a new install',
	static function () use ( $sf_plugin ) {
		$others = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( $file !== $sf_plugin && false !== strpos( $file, '/' ) && 0 !== strpos( $file, 'siteferry/' ) ) {
				$others[] = $file;
			}
		}
		$slow = static function () {
			return 0.0001;
		};
		add_filter( 'siteferry_update_backup_seconds', $slow );
		$before = count( glob( Storage::backups_dir() . '/*' ) );
		$reply  = sf_pre_download( new Theme_Upgrader( new Automatic_Upgrader_Skin() ), array( 'theme' => 'some-other-theme', 'type' => 'theme', 'action' => 'update' ) );
		remove_filter( 'siteferry_update_backup_seconds', $slow );
		Sf_Test::same( false, $reply, 'the update goes ahead:' );
		$last = sf_last_update();
		Sf_Test::same( 'skipped', $last['status'], wp_json_encode( $last ) );
		Sf_Test::same( $before, count( glob( Storage::backups_dir() . '/*' ) ), 'no partial backup left' );

		list( $busy ) = Export::start( array( 'exclude_media' => true ) );
		$reply        = sf_pre_download( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), array( 'plugin' => isset( $others[0] ) ? $others[0] : 'x/x.php', 'type' => 'plugin', 'action' => 'update' ) );
		Sf_Test::same( false, $reply );
		Sf_Test::same( 'skipped', sf_last_update()['status'], 'skipped while another job runs:' );
		Runner::cancel( $busy );

		$time = sf_last_update()['time'];
		sleep( 1 );
		sf_pre_download( new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() ), array( 'language_update_type' => 'core' ) );
		sf_pre_download( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), array( 'type' => 'plugin', 'action' => 'install' ) );
		Sf_Test::same( $time, sf_last_update()['time'], 'translations and new installs make no backup:' );
		Sf_Test::same( 'a reply', apply_filters( 'upgrader_pre_download', 'a reply', 'x', new Core_Upgrader( new Automatic_Upgrader_Skin() ), array() ), 'another plugin\'s answer passes through untouched:' );
	}
);

Schedule::save(
	array(
		'before_updates' => true,
		'update_retain'  => 10,
	)
);

Sf_Test::run(
	'before risky changes: deleting a plugin or a theme, switching the theme (WordPress\'s own functions)',
	static function () use ( &$sf_made ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		// A tiny plugin and theme made here (our own files), then deleted the way WordPress deletes them.
		$plugin_dir = WP_PLUGIN_DIR . '/sf-risky-test';
		wp_mkdir_p( $plugin_dir );
		file_put_contents( $plugin_dir . '/sf-risky-test.php', "<?php\n/**\n * Plugin Name: SiteFerry risky test\n * Version: 1.0.1\n */\n" );
		file_put_contents( $plugin_dir . '/readme.txt', 'SiteFerry test plugin.' );
		wp_clean_plugins_cache( false );
		$deleted = delete_plugins( array( 'sf-risky-test/sf-risky-test.php' ) );
		Sf_Test::same( true, $deleted, 'the plugin is deleted:' );
		Sf_Test::ok( ! is_dir( $plugin_dir ), 'its folder is gone' );
		$last      = sf_last_update();
		$sf_made[] = $last['name'];
		Sf_Test::same( array( 'done', 'plugin-delete' ), array( $last['status'], $last['change'] ), wp_json_encode( $last ) );
		Sf_Test::same( 'Before deleting the plugin SiteFerry risky test 1.0.1', $last['title'] );
		$paths = sf_paths( $last['name'] );
		Sf_Test::ok( in_array( 'wp-content/plugins/sf-risky-test/sf-risky-test.php', $paths, true ) && in_array( 'database.sql', $paths, true ), 'the backup holds the database and the plugin' );
		Sf_Test::same( array(), sf_outside( $paths, 'wp-content/plugins/sf-risky-test' ), 'only that plugin:' );
		Sf_Test::same( array( 'before-plugin-delete' ), \SiteFerry\Labels::get( $last['name'] )['tags'] );

		$theme_dir = get_theme_root() . '/sf-risky-theme';
		wp_mkdir_p( $theme_dir );
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: SiteFerry risky theme\nVersion: 2.0\n*/\n" );
		file_put_contents( $theme_dir . '/index.php', "<?php\n" );
		wp_clean_themes_cache();
		Sf_Test::ok( true === delete_theme( 'sf-risky-theme' ), 'the theme is deleted' );
		$last      = sf_last_update();
		$sf_made[] = $last['name'];
		Sf_Test::same( array( 'done', 'theme-delete' ), array( $last['status'], $last['change'] ), wp_json_encode( $last ) );
		$paths = sf_paths( $last['name'] );
		Sf_Test::ok( in_array( 'wp-content/themes/sf-risky-theme/style.css', $paths, true ), 'the backup holds the theme' );
		Sf_Test::same( array(), sf_outside( $paths, 'wp-content/themes/sf-risky-theme' ), 'only that theme:' );

		// Switching: to another installed theme and back; the backup is taken before the switch.
		$current = get_stylesheet();
		$other = '';
		$slugs = array_keys( wp_get_themes() );
		usort(
			$slugs,
			static function ( string $a, string $b ): int {
				return (int) ( 0 !== strpos( $a, 'twenty' ) ) - (int) ( 0 !== strpos( $b, 'twenty' ) ); // WordPress's default themes first.
			}
		);
		foreach ( $slugs as $slug ) {
			if ( $slug !== $current && wp_get_theme( $slug )->get_template() === $slug ) {
				$other = $slug;
				break;
			}
		}
		Sf_Test::ok( '' !== $other, 'another theme to switch to' );
		$in_backup = null;
		$watch     = static function ( $value ) use ( &$in_backup ) {
			// Read in the middle of the switch: the backup before it must already exist.
			if ( null === $in_backup ) {
				$in_backup = sf_last_update();
			}
			return $value;
		};
		add_filter( 'pre_update_option_stylesheet', $watch, 20 );
		switch_theme( $other );
		remove_filter( 'pre_update_option_stylesheet', $watch, 20 );
		$last      = sf_last_update();
		$sf_made[] = $last['name'];
		Sf_Test::same( array( 'done', 'theme-switch' ), array( $last['status'], $last['change'] ), wp_json_encode( $last ) );
		Sf_Test::same( $last['name'], $in_backup['name'], 'made before the switch finished:' );
		Sf_Test::same( array(), sf_outside( sf_paths( $last['name'] ), '' ), 'the database only:' );
		Sf_Test::same( $other, get_stylesheet(), 'the switch went ahead:' );
		switch_theme( $current );
		$back      = sf_last_update();
		$sf_made[] = $back['name'];
		Sf_Test::same( $current, get_stylesheet() );

		// Turned off: no backup.
		Schedule::save(
			array(
				'before_updates'      => true,
				'update_retain'       => 10,
				'before_theme_switch' => false,
			)
		);
		$time = sf_last_update()['time'];
		sleep( 1 );
		switch_theme( $other );
		switch_theme( $current );
		Sf_Test::same( $time, sf_last_update()['time'], 'no backup when turned off:' );
	}
);

// Clean up: the backups made here, and the settings.
foreach ( Schedule::state()['update_runs'] as $sf_run ) {
	$sf_made[] = $sf_run['name'];
}
foreach ( array_unique( $sf_made ) as $sf_name ) {
	$sf_path = Storage::backups_dir() . '/' . $sf_name;
	if ( '' !== $sf_name && is_file( $sf_path ) ) {
		unlink( $sf_path );
	}
	if ( '' !== $sf_name ) {
		\SiteFerry\Labels::set( $sf_name, '', '', array() );
	}
}
delete_option( Schedule::OPTION );
delete_option( Schedule::STATE );
Schedule::unschedule_all();

exit( Sf_Test::finish( 'wp/test-before-updates' ) );
