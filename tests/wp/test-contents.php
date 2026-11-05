<?php
/**
 * "Look inside a backup" inside WordPress (no import is run). On the test site siteferry-dst:
 *
 *     powershell -File tests\wp-cli.ps1 siteferry-dst --skip-themes eval-file tests/wp/test-contents.php
 *
 * Makes a small backup (the database and the smallest plugin), lists it through the REST API,
 * downloads single files from it over HTTP as a logged-in administrator (the way the browser
 * does), checks what is refused, and deletes the backup and its index again.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

use SiteFerry\Backups;
use SiteFerry\Export\Export;
use SiteFerry\Security\Storage;

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'SiteFerry\Contents' ) ) {
	echo "Run with wp eval-file on a site where SiteFerry is active.\n";
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/class-sf-test.php';

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( false !== strpos( str_replace( '\\', '/', $file ), '/siteferry/' ) && ( error_reporting() & $severity ) ) {
			throw new ErrorException( $message, 0, $severity, $file, $line );
		}
		return false;
	}
);

echo "Look inside tests\n";

$admins = get_users(
	array(
		'role'   => 'administrator',
		'fields' => 'ID',
		'number' => 1,
	)
);
$admin  = (int) $admins[0];

require dirname( __DIR__ ) . '/lib/wp-rest.php';

/**
 * Total size of the files below a folder.
 *
 * @param string $dir Folder.
 */
function sf_folder_bytes( string $dir ): int {
	$bytes = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		$bytes += $file->isFile() ? (int) $file->getSize() : 0;
	}
	return $bytes;
}

// The smallest plugin folder other than SiteFerry goes into the backup; every other plugin, the
// themes, the media and the other folders of wp-content are left out.
$plugins = array_values( array_diff( (array) scandir( WP_PLUGIN_DIR ), array( '.', '..' ) ) );
$keep    = '';
foreach ( $plugins as $name ) {
	if ( 'siteferry' !== $name && is_dir( WP_PLUGIN_DIR . '/' . $name ) && ( '' === $keep || sf_folder_bytes( WP_PLUGIN_DIR . '/' . $name ) < sf_folder_bytes( WP_PLUGIN_DIR . '/' . $keep ) ) ) {
		$keep = $name;
	}
}
if ( '' === $keep ) {
	echo "This test needs one plugin besides SiteFerry.\n";
	exit( 1 );
}
$exclude = array();
foreach ( array_diff( (array) scandir( WP_CONTENT_DIR ), array( '.', '..', 'plugins' ) ) as $name ) {
	if ( 0 !== strpos( $name, 'siteferry-' ) ) {
		$exclude[] = $name;
	}
}
foreach ( $plugins as $name ) {
	if ( $name !== $keep ) {
		$exclude[] = 'plugins/' . $name;
	}
}

$backup = '';

Sf_Test::run(
	'a small backup to look into',
	static function () use ( $exclude, &$backup ) {
		list( $job, $token ) = Export::start(
			array(
				'title'          => 'SiteFerry test: look inside',
				'exclude_media'  => true,
				'exclude_themes' => true,
				'exclude_paths'  => $exclude,
			)
		);
		$summary = sf_rest_drive( $job->id(), $token );
		Sf_Test::same( 'done', $summary['status'], 'export: ' . wp_json_encode( $summary ) );
		$backup = (string) $summary['result']['name'];
		Sf_Test::ok( is_file( Storage::backups_dir() . '/' . $backup ), 'the backup is there' );
	}
);

if ( '' === $backup ) {
	exit( Sf_Test::finish( 'wp/test-contents' ) );
}

Sf_Test::run(
	'the contents routes are for administrators only',
	static function () use ( $backup ) {
		list( $status ) = sf_rest( 'GET', '/backups/' . $backup . '/contents', 0 );
		Sf_Test::same( 401, $status, 'contents, anonymous:' );
		list( $status ) = sf_rest( 'GET', '/backups/' . $backup . '/file', 0 );
		Sf_Test::same( 401, $status, 'file, anonymous:' );
		$subscriber = get_users(
			array(
				'role__not_in' => array( 'administrator' ),
				'fields'       => 'ID',
				'number'       => 1,
			)
		);
		if ( $subscriber ) {
			list( $status ) = sf_rest( 'GET', '/backups/' . $backup . '/contents', (int) $subscriber[0] );
			Sf_Test::same( 403, $status, 'contents, a user who is not an administrator:' );
		}
		list( $status ) = sf_rest( 'GET', '/backups/..%2Fwp-config.php/contents', 0 );
		Sf_Test::ok( 404 === $status || 401 === $status, 'a path as the backup name is no route (' . $status . ')' );
	}
);

Sf_Test::run(
	'the top of the backup: the files, the tables from the manifest and its table prefix',
	static function () use ( $backup, $admin ) {
		$calls = 0;
		do {
			list( $status, $data ) = sf_rest( 'GET', '/backups/' . $backup . '/contents', $admin );
			Sf_Test::same( 200, $status, 'contents: ' . wp_json_encode( $data ) );
			++$calls;
		} while ( ! $data['complete'] && $calls < 50 );
		Sf_Test::same( true, $data['complete'] );
		Sf_Test::same( array( 'wp-content', 'database.sql', 'siteferry.json', 'siteferry.sha256' ), array_column( $data['entries'], 'path' ) );

		$manifest = Backups::manifest( Storage::backups_dir() . '/' . $backup );
		Sf_Test::same( array_column( $manifest['database']['tables'], 'name' ), array_column( $data['tables'], 'name' ) );
		Sf_Test::same( true, $manifest['database']['rows_exact'], 'rows per table are counted, not estimated:' );
		Sf_Test::same( (int) $manifest['database']['rows'], array_sum( array_column( $data['tables'], 'rows' ) ) );
		foreach ( array_slice( $data['tables'], 0, 5 ) as $table ) {
			Sf_Test::same( (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table['name'] ) . '`' ), $table['rows'], $table['name'] . ':' ); // phpcs:ignore WordPress.DB
		}
		Sf_Test::same( $manifest['database']['prefix'], $data['prefix'] );
		Sf_Test::same( (int) $manifest['files']['files'], (int) $data['entries'][0]['files'], 'files below wp-content, as in the manifest:' );
	}
);

Sf_Test::run(
	'a folder lists its children; only the kept plugin is in the backup',
	static function () use ( $backup, $admin, $keep ) {
		$request = new WP_REST_Request( 'GET', '/siteferry/v1/backups/' . $backup . '/contents' );
		$request->set_param( 'dir', 'wp-content/plugins' );
		wp_set_current_user( $admin );
		$data = rest_do_request( $request )->get_data();
		Sf_Test::same( array( 'wp-content/plugins/' . $keep ), array_column( $data['entries'], 'path' ) );
		Sf_Test::same( 'dir', $data['entries'][0]['type'] );
		Sf_Test::same( sf_folder_bytes( WP_PLUGIN_DIR . '/' . $keep ), (int) $data['entries'][0]['size'], 'the plugin\'s size:' );
		Sf_Test::same( null, $data['tables'], 'tables only at the top:' );
	}
);

// Downloads go through the web server, signed in like the browser: a session for the
// administrator, its login cookie, and a REST nonce made for that session.
$session = WP_Session_Tokens::get_instance( $admin );
$expires = time() + 600;
$token   = $session->create( $expires );
$cookie  = wp_generate_auth_cookie( $admin, $expires, 'logged_in', $token );
$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- the nonce belongs to this session.
wp_set_current_user( $admin );
$GLOBALS['sf_cookie'] = $cookie;
$GLOBALS['sf_nonce']  = wp_create_nonce( 'wp_rest' );

/**
 * GET a backup's file over HTTP.
 *
 * @param string $backup Backup name.
 * @param string $path   Path in the backup.
 * @param bool   $signed Whether to send the login cookie and nonce.
 * @return array|WP_Error
 */
function sf_fetch_file( string $backup, string $path, bool $signed = true ) {
	$query = array( 'path' => $path );
	if ( $signed ) {
		$query['_wpnonce'] = $GLOBALS['sf_nonce'];
	}
	return wp_remote_get(
		add_query_arg( array_map( 'rawurlencode', $query ), rest_url( 'siteferry/v1/backups/' . $backup . '/file' ) ),
		array(
			'timeout'   => 60,
			'sslverify' => false,
			'cookies'   => $signed ? array( LOGGED_IN_COOKIE => $GLOBALS['sf_cookie'] ) : array(),
		)
	);
}

Sf_Test::run(
	'single files download exactly as they were backed up',
	static function () use ( $backup, $keep ) {
		$files = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( WP_PLUGIN_DIR . '/' . $keep, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) {
				$files[] = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( str_replace( '\\', '/', WP_CONTENT_DIR ) ) + 1 );
			}
		}
		sort( $files );
		foreach ( array_slice( $files, 0, 3 ) as $relative ) {
			$response = sf_fetch_file( $backup, 'wp-content/' . $relative );
			Sf_Test::ok( ! is_wp_error( $response ), 'request: ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'ok' ) );
			Sf_Test::same( 200, wp_remote_retrieve_response_code( $response ), $relative . ': ' . substr( (string) wp_remote_retrieve_body( $response ), 0, 200 ) );
			Sf_Test::same( hash_file( 'sha256', WP_CONTENT_DIR . '/' . $relative ), hash( 'sha256', (string) wp_remote_retrieve_body( $response ) ), $relative . ' unchanged:' );
			Sf_Test::same( 'nosniff', wp_remote_retrieve_header( $response, 'x-content-type-options' ) );
			Sf_Test::ok( 0 === strpos( (string) wp_remote_retrieve_header( $response, 'content-disposition' ), 'attachment; filename="' . basename( $relative ) . '"' ), 'sent as a download: ' . wp_remote_retrieve_header( $response, 'content-disposition' ) );
		}

		$dump = sf_fetch_file( $backup, 'database.sql' );
		Sf_Test::same( 200, wp_remote_retrieve_response_code( $dump ) );
		Sf_Test::ok( false !== strpos( (string) wp_remote_retrieve_body( $dump ), 'CREATE TABLE' ), 'the database dump downloads too' );
	}
);

Sf_Test::run(
	'what is refused: no login, a folder, a missing file, a path outside the backup',
	static function () use ( $backup, $keep ) {
		$anonymous = sf_fetch_file( $backup, 'database.sql', false );
		Sf_Test::same( 401, wp_remote_retrieve_response_code( $anonymous ), 'without the login:' );
		Sf_Test::ok( false === strpos( (string) wp_remote_retrieve_body( $anonymous ), 'CREATE TABLE' ), 'nothing of the dump without the login' );
		foreach ( array( 'wp-content/plugins/' . $keep, 'wp-content/plugins/' . $keep . '/no-such-file.php', '../wp-config.php', 'wp-content/../../wp-config.php', '' ) as $path ) {
			$response = sf_fetch_file( $backup, $path );
			Sf_Test::same( 404, wp_remote_retrieve_response_code( $response ), '"' . $path . '":' );
		}
	}
);

$session->destroy( $token );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

Sf_Test::run(
	'deleting the backup removes its index',
	static function () use ( $backup, $admin ) {
		$pattern = Storage::root() . '/index/idx-' . substr( sha1( $backup ), 0, 16 ) . '-*.json';
		Sf_Test::same( 1, count( (array) glob( $pattern ) ), 'an index was kept while the backup exists:' );
		list( $status ) = sf_rest( 'DELETE', '/backups/' . $backup, $admin );
		Sf_Test::same( 200, $status );
		Sf_Test::same( array(), (array) glob( $pattern ) );
		sf_rest( 'POST', '/labels/' . $backup, $admin, array(), array() );
	}
);

exit( Sf_Test::finish( 'wp/test-contents' ) );
