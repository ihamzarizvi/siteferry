<?php
/**
 * Staging copy, the parts that need no WordPress: rewriting wp-config.php (and refusing what cannot
 * be copied safely), folder names, what the copy leaves out, the checks before deleting, and the
 * must-use plugin's source.
 *
 * Run: php tests/test-staging.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Staging\Config;
use SiteFerry\Staging\Mu_Plugin;
use SiteFerry\Staging\Staging;

echo "Staging tests\n";

/** A typical wp-config.php. */
function sf_st_config( string $extra = '' ): string {
	return "<?php\n/** The base configuration for WordPress */\ndefine( 'DB_NAME', 'local' );\ndefine( 'DB_USER', 'root' );\ndefine('DB_PASSWORD', 'p@ss;word\\'s');\ndefine( 'DB_HOST', 'localhost' );\n"
		. "define( 'AUTH_KEY', 'a;b)c(d' );\n\$table_prefix = 'wp_';\n" . $extra
		. "define( 'WP_DEBUG', false );\n/* That's all, stop editing! */\nif ( ! defined( 'ABSPATH' ) ) {\n\tdefine( 'ABSPATH', __DIR__ . '/' );\n}\nrequire_once ABSPATH . 'wp-settings.php';\n";
}

/**
 * The constants and prefix a config sets, read with the tokenizer (as PHP would see them).
 *
 * @param string $php Config.
 */
function sf_st_values( string $php ): array {
	$out    = array( 'prefix' => Config::prefix( $php ) );
	$tokens = token_get_all( $php );
	foreach ( $tokens as $i => $t ) {
		if ( is_array( $t ) && T_STRING === $t[0] && 'define' === strtolower( $t[1] ) ) {
			$name = null;
			$val  = null;
			for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) {
					if ( null === $name ) {
						$name = stripcslashes( substr( $tokens[ $j ][1], 1, -1 ) );
						continue;
					}
					$val = stripcslashes( substr( $tokens[ $j ][1], 1, -1 ) );
					break;
				}
				if ( ';' === $tokens[ $j ] ) {
					break;
				}
			}
			if ( null !== $name ) {
				$out[ $name ][] = $val;
			}
		}
	}
	return $out;
}

Sf_Test::run(
	'wp-config.php: only the prefix changes, the staging address and markers are set once, the rest stays',
	static function () {
		$out = Config::rewrite( sf_st_config( "define( 'WP_HOME', 'http://live.test' );\ndefine('WP_CACHE', true);\n" ), 'stgab12_', 'http://live.test/staging', 'http://live.test' );
		$v   = sf_st_values( $out );
		Sf_Test::same( 'stgab12_', $v['prefix'] );
		Sf_Test::same( array( 'http://live.test/staging' ), $v['WP_HOME'], 'WP_HOME once, the staging address:' );
		Sf_Test::same( array( 'http://live.test/staging' ), $v['WP_SITEURL'] );
		Sf_Test::same( array( 'staging' ), $v['WP_ENVIRONMENT_TYPE'] );
		Sf_Test::same( 1, count( $v['WP_CACHE'] ), 'WP_CACHE set once:' );
		Sf_Test::same( 1, count( $v['SITEFERRY_STAGING'] ) );
		Sf_Test::same( array( 'p@ss;word\'s' ), $v['DB_PASSWORD'], 'the database login stays exactly:' );
		Sf_Test::same( array( 'a;b)c(d' ), $v['AUTH_KEY'] );
		Sf_Test::ok( false !== strpos( $out, "require_once ABSPATH . 'wp-settings.php';" ), 'wp-settings.php is still loaded' );
		Sf_Test::ok( 0 === strpos( $out, "<?php\n// Added by SiteFerry" ), 'the staging block comes first' );
		// It is valid PHP.
		$file = Sf_Test::temp_dir( 'staging' ) . '/wp-config.php';
		file_put_contents( $file, $out );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $file ), $lines, $code );
		Sf_Test::same( 0, $code, 'php -l: ' . implode( ' ', $lines ) );

		// dirname( __FILE__ ), double quotes, comments between tokens, require with brackets.
		$alt = str_replace(
			array( "define( 'ABSPATH', __DIR__ . '/' );", "require_once ABSPATH . 'wp-settings.php';", "\$table_prefix = 'wp_';" ),
			array( 'define( "ABSPATH", dirname( __FILE__ ) . "/" );', "require_once( ABSPATH . 'wp-settings.php' );", "\$table_prefix /* site */ = \"shop_\";" ),
			sf_st_config()
		);
		Sf_Test::same( 'stgab12_', Config::prefix( Config::rewrite( $alt, 'stgab12_', 'http://x/s', 'http://x' ) ) );
		Sf_Test::same( 'shop_', Config::prefix( $alt ), 'reading the prefix:' );
	}
);

Sf_Test::run(
	'wp-config.php that cannot be copied safely is refused with the reason',
	static function () {
		$cases = array(
			'prefix from getenv'        => array( str_replace( "\$table_prefix = 'wp_';", "\$table_prefix = getenv( 'PREFIX' );", sf_st_config() ), 'plain text value' ),
			'prefix set twice'          => array( sf_st_config( "\$table_prefix = 'x_';\n" ), 'more than once' ),
			'no prefix'                 => array( str_replace( "\$table_prefix = 'wp_';", '', sf_st_config() ), '$table_prefix' ),
			'content folder moved'      => array( sf_st_config( "define( 'WP_CONTENT_DIR', '/srv/content' );\n" ), 'WP_CONTENT_DIR' ),
			'a network'                 => array( sf_st_config( "define( 'MULTISITE', true );\n" ), 'MULTISITE' ),
			'absolute ABSPATH'          => array( str_replace( "define( 'ABSPATH', __DIR__ . '/' );", "define( 'ABSPATH', '/var/www/html/' );", sf_st_config() ), 'ABSPATH' ),
			'relative include'          => array( sf_st_config( "require __DIR__ . '/wp-config-local.php';\n" ), 'another file' ),
			'relative include, literal' => array( sf_st_config( "include 'secrets.php';\n" ), 'another file' ),
			'no open tag'               => array( "define( 'X', 1 );", 'opening PHP tag' ),
		);
		foreach ( $cases as $label => list( $php, $says ) ) {
			try {
				Config::rewrite( $php, 'stgab12_', 'http://x/s', 'http://x' );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( false !== strpos( $e->getMessage(), $says ), $label . ': ' . $e->getMessage() );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . $label );
		}
		// An absolute include is fine (it is found from the copy too).
		Sf_Test::same( 'stgab12_', Config::prefix( Config::rewrite( sf_st_config( "require_once '/home/site/salts.php';\n" ), 'stgab12_', 'http://x/s', 'http://x' ) ) );
		try {
			Config::rewrite( sf_st_config(), "x'; evil();", 'http://x/s', 'http://x' );
			throw new RuntimeException( 'a bad prefix was accepted' );
		} catch ( Job_Exception $e ) {
			Sf_Test::ok( true, 'a bad prefix is refused' );
		}
	}
);

Sf_Test::run(
	'folder names, and what the copy leaves out',
	static function () {
		foreach ( array( 'staging', 'test-1', 'a', str_repeat( 'b', 30 ) ) as $ok ) {
			Staging::check_folder_name( $ok );
		}
		foreach ( array( '', 'Staging', 'wp-admin', 'wp-content', '-x', 'a b', '../x', 'a/b', str_repeat( 'b', 31 ) ) as $bad ) {
			try {
				Staging::check_folder_name( $bad );
			} catch ( Job_Exception $e ) {
				continue;
			}
			throw new RuntimeException( 'accepted folder: ' . $bad );
		}
		$root = Sf_Test::temp_dir( 'staging-root' );
		foreach ( array( 'wp-admin', 'wp-content', 'other-site', 'old-copy', 'just-files' ) as $dir ) {
			mkdir( $root . '/' . $dir );
		}
		file_put_contents( $root . '/other-site/wp-config.php', '<?php' );
		file_put_contents( $root . '/old-copy/' . Staging::MARKER, 'abc' );
		$ex = Staging::exclusions( 'staging', false, $root );
		foreach ( array( 'staging', 'wp-config.php', '.htaccess', 'other-site', 'old-copy', 'wp-content/siteferry-abc123', 'wp-content/cache', 'wp-content/ai1wm-backups', 'wp-content/upgrade', 'wp-content/object-cache.php', 'wp-content/plugins/x/node_modules' ) as $out ) {
			Sf_Test::ok( $ex->excludes( $out ), 'left out: ' . $out );
		}
		foreach ( array( 'wp-admin', 'wp-includes/version.php', 'wp-content/plugins/siteferry', 'wp-content/uploads', 'just-files', 'index.php' ) as $in ) {
			Sf_Test::ok( ! $ex->excludes( $in ), 'copied: ' . $in );
		}
		Sf_Test::ok( Staging::exclusions( 'staging', true, $root )->excludes( 'wp-content/uploads' ), 'media left out when asked' );
	}
);

Sf_Test::run(
	'before deleting: only a recorded staging folder with its marker and staging tables, never the live site',
	static function () {
		$root = Sf_Test::temp_dir( 'staging-del' );
		mkdir( $root . '/staging' );
		file_put_contents( $root . '/staging/' . Staging::MARKER, 'abcdef0123456789' );
		$good = array(
			'id'     => 'abcdef0123456789',
			'folder' => 'staging',
			'path'   => $root . '/staging',
			'prefix' => 'stgab12_',
		);
		Staging::check_target( $good, $root, 'wp_' );
		Sf_Test::ok( true, 'a matching record passes' );
		$bad = array(
			'live prefix'         => array( 'prefix' => 'wp_' ),
			'not a staging prefix' => array( 'prefix' => 'wpx_' ),
			'live starts with it' => array( 'prefix' => 'stgab12_', 'live' => 'stgab12_shop_' ),
			'path elsewhere'      => array( 'path' => dirname( $root ) . '/staging' ),
			'path above'          => array( 'path' => $root . '/staging/..' ),
			'folder mismatch'     => array( 'folder' => 'other' ),
			'reserved folder'     => array( 'folder' => 'wp-content', 'path' => $root . '/wp-content' ),
			'marker id differs'   => array( 'id' => '0000000000000000' ),
			'bad id'              => array( 'id' => '../x' ),
		);
		foreach ( $bad as $label => $change ) {
			$live   = isset( $change['live'] ) ? $change['live'] : 'wp_';
			unset( $change['live'] );
			if ( 'reserved folder' === $label ) {
				mkdir( $root . '/wp-content' );
			}
			try {
				Staging::check_target( $change + $good, $root, $live );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( true, 'refused: ' . $label );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . $label );
		}
		// No marker in the folder: refused.
		unlink( $root . '/staging/' . Staging::MARKER );
		try {
			Staging::check_target( $good, $root, 'wp_' );
			throw new RuntimeException( 'accepted a folder without the marker' );
		} catch ( Job_Exception $e ) {
			Sf_Test::ok( true, 'refused: no marker' );
		}
		// Folder already gone (an earlier slice removed it): the tables can still be dropped.
		rmdir( $root . '/staging' );
		Staging::check_target( $good, $root, 'wp_' );
		Sf_Test::ok( true, 'a folder already removed passes' );
	}
);

Sf_Test::run(
	'the must-use plugin: valid PHP, the live address as data, rules for nginx and Apache',
	static function () {
		$src  = Mu_Plugin::source( "http://live.test/it's" );
		$file = Sf_Test::temp_dir( 'staging-mu' ) . '/mu.php';
		file_put_contents( $file, $src );
		exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $file ), $lines, $code );
		Sf_Test::same( 0, $code, 'php -l: ' . implode( ' ', $lines ) );
		Sf_Test::ok( false !== strpos( $src, "'http://live.test/it\\'s'" ), 'the address is a quoted string' );
		Sf_Test::ok( false !== strpos( $src, 'pre_wp_mail' ) && false !== strpos( $src, 'X-Robots-Tag' ), 'email held back, noindex sent' );
		Sf_Test::same( "location /staging/ {\n\ttry_files \$uri \$uri/ /staging/index.php?\$args;\n}", Staging::nginx_rule( 'http://live.test/staging' ) );
		Sf_Test::ok( false !== strpos( Staging::htaccess( 'http://live.test/blog/staging' ), 'RewriteBase /blog/staging/' ), 'RewriteBase of the copy' );
	}
);

exit( Sf_Test::finish( 'test-staging' ) );
