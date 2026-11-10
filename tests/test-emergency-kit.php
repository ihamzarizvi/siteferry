<?php
/**
 * Emergency kit: the generated file is valid PHP with its settings intact, wp-config.php is read
 * without running it (only plain literals), and the session and form tokens hold.
 *
 * Run: php tests/test-emergency-kit.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Emergency_Kit;

echo "Emergency kit tests\n";

$sfk_test_config = array(
	'id'         => 'ab12cd34',
	'hash'       => password_hash( 'correct horse battery', PASSWORD_DEFAULT ),
	'secret'     => str_repeat( 'f0', 32 ),
	'expires'    => 2000000000,
	'made'       => 1700000000,
	'host'       => "example.com:8080",
	'backups'    => "wp-content/siteferry-0123456789ab/backups",
	'themes'     => "wp-content/themes",
	'own_plugin' => "siteferry/siteferry.php",
	'version'    => '0.31.0',
	// Values with quotes, backslashes and code-like text must stay data.
	'note'       => "it's \\ a \"test\" '); echo 'x'; //",
);
$sfk_test_code = Emergency_Kit::build( $sfk_test_config );
$sfk_test_dir  = Sf_Test::temp_dir( 'kit' );
$sfk_test_file = $sfk_test_dir . '/' . Emergency_Kit::file_name( 'ab12cd34' );
file_put_contents( $sfk_test_file, $sfk_test_code );

// Load the kit's functions without running it.
define( 'SFK_TEST', true );
require $sfk_test_file;

Sf_Test::run(
	'the kit is valid PHP, keeps its settings exactly, and has no leftover placeholder',
	static function () use ( $sfk_test_code, $sfk_test_config ) {
		$parsed = true;
		try {
			token_get_all( $sfk_test_code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			$parsed = $e->getMessage();
		}
		Sf_Test::same( true, $parsed, 'parses:' );
		Sf_Test::ok( false === strpos( $sfk_test_code, Emergency_Kit::PLACEHOLDER ), 'placeholder replaced' );
		Sf_Test::same( 1, substr_count( $sfk_test_code, '$sfk_config = ' ), 'one settings line:' );
		Sf_Test::same( $sfk_test_config, $GLOBALS['sfk_config'], 'settings round-trip:' );
		Sf_Test::ok( 0 === strpos( $sfk_test_code, "<?php\n" ), 'starts as PHP' );
		Sf_Test::ok( false === strpos( $sfk_test_code, 'correct horse battery' ), 'the password itself is not in the file' );
		Sf_Test::same( 'siteferry-emergency-ab12cd34.php', Emergency_Kit::file_name( 'ab12cd34' ) );
	}
);

Sf_Test::run(
	'wp-config.php: plain literals are read; getenv(), constants, calculations and conflicting values are refused',
	static function () {
		$typical = <<<'PHP'
<?php
/** The name of the database */
define( 'DB_NAME', 'local' );
define( 'DB_USER', "root" ); // a comment, define( 'DB_USER', 'wrong' );
# define( 'DB_PASSWORD', 'commented out' );
/* define( 'DB_HOST', 'in a block comment' ); */
define('DB_PASSWORD','pa\'ss\\word');
define( "DB_HOST", "localhost:3306" );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'WP_DEBUG', false );
define( 'MULTISITE', true );
define( 'BLOG_ID_CURRENT_SITE', 1 );
$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP;
		$out = sfk_parse_config( $typical );
		Sf_Test::same( 'local', $out['defines']['DB_NAME'] );
		Sf_Test::same( 'root', $out['defines']['DB_USER'], 'double quotes; the commented-out value is ignored:' );
		Sf_Test::same( "pa'ss\\word", $out['defines']['DB_PASSWORD'], 'escapes in single quotes:' );
		Sf_Test::same( 'localhost:3306', $out['defines']['DB_HOST'] );
		Sf_Test::same( false, $out['defines']['WP_DEBUG'] );
		Sf_Test::same( true, $out['defines']['MULTISITE'] );
		Sf_Test::same( 1, $out['defines']['BLOG_ID_CURRENT_SITE'] );
		Sf_Test::same( 'wp_', $out['prefix'] );
		Sf_Test::same( array( 'ABSPATH' ), array_keys( $out['problems'] ), 'ABSPATH is a calculation:' );

		$tricky = <<<'PHP'
<?php
define( 'DB_NAME', getenv( 'DB_NAME' ) );
define( 'DB_USER', DB_NAME_USER );
define( 'DB_PASSWORD', "pass$word" );
define( 'DB_HOST', 'a' . 'b' );
\define( 'DB_CHARSET', 'utf8' );
if ( $live ) { define( 'WP_HOME', 'https://a.example' ); } else { define( 'WP_HOME', 'https://b.example' ); }
define( 'WP_SITEURL', 'https://same.example' );
define( 'WP_SITEURL', 'https://same.example' );
$table_prefix = getenv( 'PREFIX' );
PHP;
		$out = sfk_parse_config( $tricky );
		foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'WP_HOME' ) as $key ) {
			Sf_Test::ok( isset( $out['problems'][ $key ] ) && ! array_key_exists( $key, $out['defines'] ), $key . ' refused' );
		}
		Sf_Test::same( 'utf8', $out['defines']['DB_CHARSET'], 'a fully qualified \\define() is read:' );
		Sf_Test::same( 'https://same.example', $out['defines']['WP_SITEURL'], 'the same value twice is fine:' );
		Sf_Test::same( null, $out['prefix'], 'a prefix from getenv() is refused:' );
		Sf_Test::ok( isset( $out['problems']['table_prefix'] ), 'and listed' );

		$two = sfk_parse_config( "<?php\n\$table_prefix = 'a_';\n\$table_prefix = 'b_';\n" );
		Sf_Test::same( null, $two['prefix'], 'two different prefixes are refused:' );

		Sf_Test::same( "it's", sfk_literal( "'it\\'s'" ) );
		Sf_Test::same( "a\tb", sfk_literal( '"a\\tb"' ) );
		Sf_Test::same( null, sfk_literal( '"$x"' ) );
	}
);

Sf_Test::run(
	'sessions and form tokens: signed, expiring, bound to the session and the action',
	static function () use ( $sfk_test_config ) {
		$c   = $sfk_test_config;
		$now = 1800000000;
		$tok = sfk_session_token( $c, $now + 3600 );
		Sf_Test::ok( sfk_session_valid( $c, $tok, $now ), 'a fresh session is valid' );
		Sf_Test::ok( ! sfk_session_valid( $c, $tok, $now + 3601 ), 'it expires' );
		Sf_Test::ok( ! sfk_session_valid( $c, ( $now + 99999 ) . substr( $tok, strpos( $tok, '.' ) ), $now ), 'a changed expiry is refused' );
		Sf_Test::ok( ! sfk_session_valid( $c, substr( $tok, 0, -1 ) . ( '0' === substr( $tok, -1 ) ? '1' : '0' ), $now ), 'a changed signature is refused' );
		Sf_Test::ok( ! sfk_session_valid( $c, array( $tok ), $now ) && ! sfk_session_valid( $c, '', $now ), 'other shapes are refused' );
		$other = array( 'secret' => str_repeat( '11', 32 ) ) + $c;
		Sf_Test::ok( ! sfk_session_valid( $other, $tok, $now ), 'another kit\'s secret does not accept it' );

		$csrf = sfk_csrf( $c, $tok, 'plugins' );
		Sf_Test::ok( sfk_csrf_valid( $c, $tok, 'plugins', $csrf ), 'the token for its action is valid' );
		Sf_Test::ok( ! sfk_csrf_valid( $c, $tok, 'theme', $csrf ), 'not for another action' );
		Sf_Test::ok( ! sfk_csrf_valid( $c, sfk_session_token( $c, $now + 60 ), 'plugins', $csrf ), 'not for another session' );
		Sf_Test::ok( ! sfk_csrf_valid( $c, $tok, 'plugins', null ), 'missing is refused' );
	}
);

Sf_Test::run(
	'backup names, paths and the default theme',
	static function () use ( $sfk_test_dir ) {
		foreach ( array( 'site-2026-01-01-abc.tar', 'a.tar.gz', 'a.tar.gz.enc', 'b.wpress' ) as $name ) {
			Sf_Test::ok( sfk_backup_name( $name ), $name . ' accepted' );
		}
		foreach ( array( '../x.tar', '.htaccess', 'index.php', 'a/b.tar', 'a..tar', "a.tar\n", '-x.tar', 'x.tar.php', array( 'a.tar' ) ) as $name ) {
			Sf_Test::ok( ! sfk_backup_name( $name ), json_encode( $name ) . ' refused' );
		}
		mkdir( $sfk_test_dir . '/themes/twentytwentyfour', 0777, true );
		mkdir( $sfk_test_dir . '/themes/twentytwentyfive', 0777, true );
		touch( $sfk_test_dir . '/themes/twentytwentyfour/style.css' );
		Sf_Test::same( 'twentytwentyfour', sfk_default_theme( $sfk_test_dir . '/themes' ), 'a folder without style.css is not a theme:' );
		touch( $sfk_test_dir . '/themes/twentytwentyfive/style.css' );
		Sf_Test::same( 'twentytwentyfive', sfk_default_theme( $sfk_test_dir . '/themes' ), 'the newest one:' );
		Sf_Test::same( null, sfk_default_theme( $sfk_test_dir . '/none' ) );
		Sf_Test::same( '1.5 MB', sfk_size( 1572864 ) );
		Sf_Test::same( 'C:/x/backups', sfk_path( 'C:/x/backups' ) );
		Sf_Test::same( '/srv/b', sfk_path( '/srv/b' ) );
	}
);

Sf_Test::run(
	'the session key needs the server\'s salts: a copy of the file alone cannot sign in',
	static function () use ( $sfk_test_config ) {
		$config = "<?php\ndefine( 'AUTH_KEY', 'k-1-a long random phrase here' );\ndefine( 'NONCE_SALT', 's-8-another long random phrase' );\n\$table_prefix = 'wp_';\n";
		$server = sfk_server_secret( sfk_parse_config( $config ) );
		Sf_Test::ok( 64 === strlen( $server ), 'salts give a server secret' );
		$c      = $sfk_test_config;
		$onsite = array( 'server' => $server ) + $c;
		$now    = 1800000000;
		$forged = sfk_session_token( $c, $now + 3600 ); // What a copy of the file alone can compute.
		Sf_Test::ok( ! sfk_session_valid( $onsite, $forged, $now ), 'a session made without the salts is refused' );
		Sf_Test::ok( sfk_session_valid( $onsite, sfk_session_token( $onsite, $now + 3600 ), $now ), 'one made with them is accepted' );
		Sf_Test::ok( ! sfk_csrf_valid( $onsite, $forged, 'download', sfk_csrf( $c, $forged, 'download' ) ), 'a form token made without them is refused' );

		$other = sfk_server_secret( sfk_parse_config( str_replace( 'k-1-', 'k-2-', $config ) ) );
		Sf_Test::ok( $other !== $server, 'other salts, another secret' );
		Sf_Test::same( '', sfk_server_secret( sfk_parse_config( "<?php\ndefine( 'AUTH_KEY', 'put your unique phrase here' );\ndefine( 'NONCE_KEY', getenv( 'K' ) );\n" ) ), 'default phrases and getenv() give none:' );
		Sf_Test::same( '', sfk_server_secret( null ), 'no wp-config.php, none:' );
		Sf_Test::same( sfk_key( $c ), sfk_key( array( 'server' => '' ) + $c ), 'without salts the key is the file-only key, as before:' );
	}
);

Sf_Test::run(
	'revocation: checked before any action (a download included); an unknown kit counts as revoked',
	static function () use ( $sfk_test_code ) {
		Sf_Test::same( 'ok', sfk_revocation( array( array( 'id' => 'ab12cd34', 'revoked' => false ) ), 'ab12cd34' ) );
		Sf_Test::same( 'revoked', sfk_revocation( array( array( 'id' => 'ffffffff' ), array( 'id' => 'ab12cd34', 'revoked' => true ) ), 'ab12cd34' ) );
		Sf_Test::same( 'unknown', sfk_revocation( array( array( 'id' => 'ffffffff' ) ), 'ab12cd34' ), 'no record:' );
		Sf_Test::same( 'unknown', sfk_revocation( null, 'ab12cd34' ), 'no option at all:' );
		Sf_Test::same( 'unknown', sfk_revocation( 'junk', 'ab12cd34' ) );

		// In sfk_main the check comes before signing in, before the session, and before the download.
		$main  = substr( $sfk_test_code, (int) strpos( $sfk_test_code, 'function sfk_main(' ) );
		$check = strpos( $main, 'sfk_revocation(' );
		Sf_Test::ok( false !== $check, 'sfk_main checks the record' );
		foreach ( array( '$session = isset( $_COOKIE', "'login' === \$action", "'download' === \$action", "'plugins' === \$action" ) as $later ) {
			$at = strpos( $main, $later );
			Sf_Test::ok( false !== $at && $check < $at, 'the check comes before ' . $later );
		}
		Sf_Test::ok( strpos( $main, 'sfk_server_secret(' ) < strpos( $main, '$session = isset( $_COOKIE' ), 'the server secret is set before the session is checked' );
		Sf_Test::same( 1, substr_count( $main, 'sfk_revocation(' ), 'one check:' );
	}
);

Sf_Test::run(
	'kit records: expired ones go, revoked ones stay until they expire, at most 20 working kits',
	static function () {
		$now  = 1800000000;
		$kit  = static function ( $n, $revoked = false, $expires = 1900000000 ) {
			return array(
				'id'          => sprintf( '%08x', $n ),
				'made'        => 1700000000 + $n,
				'expires'     => $expires,
				'fingerprint' => '',
				'revoked'     => $revoked,
			);
		};
		$kits = array();
		for ( $n = 30; $n >= 1; $n-- ) { // Newest first: 30 working kits made after...
			$kits[] = $kit( 100 + $n );
		}
		$kits[] = $kit( 1, true );                 // ...an older revoked kit, still valid,
		$kits[] = $kit( 2, true, $now - 1 );       // a revoked kit that has expired,
		$kits[] = $kit( 3, false, $now - 1 );      // and an expired working kit.
		$kept   = Emergency_Kit::retain( $kits, $now );
		$ids    = array_column( $kept, 'id' );
		Sf_Test::ok( in_array( '00000001', $ids, true ), 'the revoked, unexpired kit is kept' );
		Sf_Test::ok( ! in_array( '00000002', $ids, true ) && ! in_array( '00000003', $ids, true ), 'expired kits are dropped' );
		Sf_Test::same( 21, count( $kept ), '20 working kits plus the revoked one:' );
		Sf_Test::same( sprintf( '%08x', 130 ), $kept[0]['id'], 'newest first:' );
		Sf_Test::same( 'revoked', sfk_revocation( $kept, '00000001' ), 'so the revoked kit still stops:' );
		Sf_Test::same( 'unknown', sfk_revocation( $kept, sprintf( '%08x', 101 ) ), 'and the 21st working kit stops too (no record):' );
	}
);

exit( Sf_Test::finish( 'test-emergency-kit' ) );
