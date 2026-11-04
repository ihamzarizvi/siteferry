<?php
/**
 * .wpress backups: headers, reading, writing, CRC-32, SQL translation both ways, package.json.
 *
 * With tests/tmp/wpress/aiowm-plain.wpress present (a backup made by All-in-One WP Migration), it
 * is also read in full and its database converted.
 *
 * Run: php tests/test-wpress.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Tar_Extractor;
use SiteFerry\Database\Statement_Reader;
use SiteFerry\Database\Statement_Rewriter;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Wpress\Crc32;
use SiteFerry\Wpress\Header;
use SiteFerry\Wpress\Package;
use SiteFerry\Wpress\Reader;
use SiteFerry\Wpress\Sql_In;
use SiteFerry\Wpress\Sql_Out;
use SiteFerry\Wpress\Sql_Values;
use SiteFerry\Wpress\Wpress;
use SiteFerry\Wpress\Writer;

// The few WordPress pieces the code under test touches.
define( 'SITEFERRY_VERSION', 'test' );
define( 'SITEFERRY_DIR', dirname( __DIR__ ) );
define( 'SITEFERRY_FILE', SITEFERRY_DIR . '/siteferry.php' );
if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Without trailing slashes.
	 *
	 * @param string $text Text.
	 */
	function untrailingslashit( $text ) {
		return rtrim( (string) $text, '/\\' );
	}
}

echo ".wpress tests\n";

$dir = Sf_Test::temp_dir( 'wpress' );

/**
 * Writes a .wpress file with the given entries: [folder, name, data].
 *
 * @param string $file    Target.
 * @param array  $entries Entries.
 */
function sf_wpress( string $file, array $entries ): void {
	$writer = Writer::create( $file );
	foreach ( $entries as list( $folder, $name, $data ) ) {
		$writer->add_string( $name, $folder, $data, 1700000000 );
	}
	$writer->seal();
	$writer->close();
}

/**
 * Every statement of a dump file.
 *
 * @param string $file Dump.
 * @return string[]
 */
function sf_statements( string $file ): array {
	$reader = new Statement_Reader( $file );
	$all    = array();
	while ( null !== ( $statement = $reader->next() ) ) {
		$all[] = $statement;
	}
	$reader->close();
	return $all;
}

Sf_Test::run(
	'headers: layout, closing header, older layout without CRCs, damaged and impossible fields',
	static function () {
		$header = Header::build( 'index.php', 28, 1326042071, 'themes/twentytwentyfive', Crc32::of( 'x' ) );
		Sf_Test::same( Header::SIZE, strlen( $header ) );
		Sf_Test::same( 'index.php', rtrim( substr( $header, 0, 255 ), "\0" ) );
		Sf_Test::same( '28', rtrim( substr( $header, 255, 14 ), "\0" ) );
		Sf_Test::same( '1326042071', rtrim( substr( $header, 269, 12 ), "\0" ) );
		Sf_Test::same( 'themes/twentytwentyfive', rtrim( substr( $header, 281, 4088 ), "\0" ) );
		Sf_Test::same( hash( 'crc32b', 'x' ), substr( $header, 4369, 8 ) );
		$parsed = Header::parse( $header );
		Sf_Test::same( array( false, 'index.php', 28, 1326042071, 'themes/twentytwentyfive', Crc32::of( 'x' ) ), array( $parsed['end'], $parsed['name'], $parsed['size'], $parsed['mtime'], $parsed['path'], $parsed['crc'] ) );

		$end = Header::parse( Header::end( 519328535, 0x7710a88f ) );
		Sf_Test::same( array( true, 519328535, 0x7710a88f ), array( $end['end'], $end['size'], $end['crc'] ), 'closing header' );
		Sf_Test::ok( Header::parse( str_repeat( "\0", Header::SIZE ) )['end'], 'an all-zero header also ends the archive' );

		$old    = str_pad( 'a.txt', 255, "\0" ) . str_pad( '3', 14, "\0" ) . str_pad( '1', 12, "\0" ) . str_pad( 'uploads', 4096, "\0" );
		$parsed = Header::parse( $old );
		Sf_Test::same( array( 'uploads', null ), array( $parsed['path'], $parsed['crc'] ), 'older layout: no CRC' );

		Sf_Test::throws( Archive_Exception::CORRUPT, static function () use ( $header ) { Header::parse( substr_replace( $header, 'x', 256, 1 ) ); }, 'non-digit size' );
		Sf_Test::throws( Archive_Exception::TRUNCATED, static function () use ( $header ) { Header::parse( substr( $header, 0, 100 ) ); }, 'short header' );
		Sf_Test::throws( Archive_Exception::UNSUPPORTED, static function () { Header::build( 'a/b', 1, 0, '.', null ); }, 'slash in a name' );
		Sf_Test::throws( Archive_Exception::UNSUPPORTED, static function () { Header::build( str_repeat( 'n', 256 ), 1, 0, '.', null ); }, 'name too long' );
	}
);

Sf_Test::run(
	'writing and reading: CRCs per file and for the whole archive, prefix and skip, slices, damage detected',
	static function () use ( $dir ) {
		$big     = random_bytes( 3 * Reader::CHUNK + 12345 );
		$entries = array(
			array( '.', 'package.json', '{"SiteURL":"http://a","HomeURL":"http://a"}' ),
			array( 'uploads/2026/11', 'photo.jpg', $big ),
			array( '.', 'index.php', '' ),
			array( 'themes/t', 'style.css', 'body{}' ),
			array( 'siteferry-0123456789ab/backups', 'x.tar', 'skip me' ),
		);
		$file = $dir . '/rw.wpress';
		sf_wpress( $file, $entries );

		// Whole-archive CRC in the closing header, as the format's own backups have.
		$bytes  = (string) file_get_contents( $file );
		$length = strlen( $bytes ) - Header::SIZE;
		$end    = Header::parse( substr( $bytes, $length ) );
		Sf_Test::same( array( true, $length, Crc32::of( substr( $bytes, 0, $length ) ) ), array( $end['end'], $end['size'], $end['crc'] ), 'closing header' );
		Sf_Test::ok( Reader::is_wpress( $file ), 'recognized' );

		// Read in tiny slices, saving and restoring the reader each time.
		$out   = $dir . '/rw-out';
		$state = null;
		$seen  = array();
		$sink  = fopen( $out, 'w+b' );
		while ( true ) {
			$reader = Reader::open( $file, $state, array( 'prefix' => 'wp-content/', 'skip' => array( Wpress::class, 'skipped' ) ) );
			if ( null === $reader->entry() || 0 === $reader->remaining() ) {
				$entry = $reader->next();
				if ( null === $entry ) {
					$reader->close();
					break;
				}
				ftruncate( $sink, 0 );
				rewind( $sink );
				$seen[ $entry->name ] = null;
			}
			$done  = $reader->copy_to( $sink, new Slice_Budget( null, 1 ) );
			$state = $reader->state();
			$name  = $reader->entry()->name;
			$reader->close();
			if ( $done ) {
				rewind( $sink );
				$seen[ $name ] = hash( 'sha256', (string) stream_get_contents( $sink ) );
			}
		}
		fclose( $sink );
		Sf_Test::same(
			array(
				'wp-content/uploads/2026/11/photo.jpg' => hash( 'sha256', $big ),
				'wp-content/index.php'                 => hash( 'sha256', '' ),
				'wp-content/themes/t/style.css'        => hash( 'sha256', 'body{}' ),
			),
			$seen,
			'entries, data and skipped ones'
		);

		// One flipped byte in the big file's data.
		$damaged = $dir . '/damaged.wpress';
		$at      = strpos( $bytes, $big ) + 2 * Reader::CHUNK + 7;
		file_put_contents( $damaged, substr_replace( $bytes, chr( ord( $bytes[ $at ] ) ^ 1 ), $at, 1 ) );
		$reader = Reader::open( $damaged );
		$reader->next();
		$reader->next();
		$sink = fopen( 'php://temp', 'w+b' );
		Sf_Test::throws( Archive_Exception::CORRUPT, static function () use ( $reader, $sink ) { $reader->copy_to( $sink ); }, 'CRC mismatch' );
		$reader->close();

		// Cut short.
		file_put_contents( $damaged, substr( $bytes, 0, 9000 ) );
		$reader = Reader::open( $damaged );
		$reader->next();
		Sf_Test::throws( Archive_Exception::TRUNCATED, static function () use ( $reader ) { $reader->next(); }, 'archive ends inside an entry' );
		$reader->close();
	}
);

Sf_Test::run(
	'writer: a request that dies mid-file is cut back to the checkpoint and continued',
	static function () use ( $dir ) {
		$data = random_bytes( 2500000 );
		$one  = $dir . '/one.wpress';
		$two  = $dir . '/two.wpress';
		sf_wpress( $one, array( array( '.', 'package.json', '{}' ), array( 'uploads', 'a.bin', $data ) ) );

		$writer = Writer::create( $two );
		$writer->add_string( 'package.json', '.', '{}', 1700000000 );
		$writer->begin( 'a.bin', 'uploads', strlen( $data ), 1700000000 );
		$writer->write( substr( $data, 0, 1000000 ) );
		$checkpoint = $writer->checkpoint();
		$writer->write( substr( $data, 1000000, 500000 ) ); // Lost: the request "died" here.
		$writer->close();

		$writer = Writer::resume( $two, $checkpoint );
		$writer->write( substr( $data, 1000000 ) );
		$sealed = false;
		while ( ! $sealed ) {
			$sealed     = $writer->seal( new Slice_Budget( null, 1 ) );
			$checkpoint = $writer->checkpoint();
			$writer->close();
			$writer = Writer::resume( $two, $checkpoint );
		}
		$writer->close();
		Sf_Test::same( hash_file( 'sha256', $one ), hash_file( 'sha256', $two ), 'same bytes as one pass (sealed in slices too)' );
	}
);

Sf_Test::run(
	'unpacking with Tar_Extractor: paths outside wp-content and ".." are refused',
	static function () use ( $dir ) {
		$evil = $dir . '/evil.wpress';
		sf_wpress( $evil, array( array( '../../..', 'evil.php', '<?php' ) ) );
		$staging = $dir . '/staging-evil';
		mkdir( $staging );
		$reader = Reader::open( $evil, null, array( 'prefix' => 'wp-content/' ) );
		$e      = Sf_Test::throws(
			Archive_Exception::UNSAFE_PATH,
			static function () use ( $reader, $staging ) {
				( new Tar_Extractor( $reader, $staging ) )->run();
			},
			'traversal'
		);
		$reader->close();
		Sf_Test::ok( ! file_exists( $dir . '/evil.php' ), 'nothing written outside: ' . $e->getMessage() );

		$good = $dir . '/good.wpress';
		sf_wpress( $good, array( array( 'plugins/p', 'p.php', '<?php // p' ) ) );
		$staging = $dir . '/staging-good';
		mkdir( $staging );
		$reader = Reader::open( $good, null, array( 'prefix' => 'wp-content/' ) );
		( new Tar_Extractor( $reader, $staging ) )->run();
		$reader->close();
		Sf_Test::same( '<?php // p', file_get_contents( $staging . '/wp-content/plugins/p/p.php' ) );
	}
);

$sf_aiowm_dump = "-- All-in-One WP Migration SQL Dump\n--\n\n"
	. "DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\n"
	. "CREATE TABLE `SERVMASK_PREFIX_options` (\n  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `option_name` varchar(191) NOT NULL DEFAULT '',\n  `option_value` longtext NOT NULL,\n  `autoload` varchar(20) NOT NULL DEFAULT 'yes',\n  PRIMARY KEY (`option_id`),\n  UNIQUE KEY `option_name` (`option_name`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n\n"
	. "START TRANSACTION;\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'siteurl','http://old.example','on');\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'db_version','60717','on');\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (3,'SERVMASK_PREFIX_user_roles','a:1:{s:5:\\\"admin\\\";s:3:\\\"x;y\\\";}','on'),(4,'blogdescription','It\\'s (a) test; really','on');\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (5,'template','','on');\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (6,'stylesheet','kept-child','on');\n"
	. "INSERT INTO `SERVMASK_PREFIX_options` VALUES (7,'active_plugins','a:0:{}','on');\n"
	. "COMMIT;\n\n"
	. "DROP TABLE IF EXISTS `SERVMASK_PREFIX_usermeta`;\n"
	. "CREATE TABLE `SERVMASK_PREFIX_usermeta` (\n  `umeta_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `user_id` bigint unsigned NOT NULL DEFAULT '0',\n  `meta_key` varchar(255) DEFAULT NULL,\n  `meta_value` longtext,\n  PRIMARY KEY (`umeta_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;\n\n"
	. "START TRANSACTION;\n"
	. "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (1,1,'SERVMASK_PREFIX_capabilities','a:1:{s:13:\\\"administrator\\\";b:1;}');\n"
	. "INSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (2,1,'nickname',NULL);\n"
	. "COMMIT;\n";

Sf_Test::run(
	'database in: placeholder prefix restored, column lists added, rows and db_version recorded, accepted by the importer',
	static function () use ( $dir, $sf_aiowm_dump ) {
		file_put_contents( $dir . '/in.sql', $sf_aiowm_dump );
		$state = null;
		$runs  = 0;
		$fill  = Package::options( array( 'Template' => 'parent-theme', 'Stylesheet' => 'child', 'Plugins' => array( "a/it's.php" ) ) );
		do {
			$sql   = new Sql_In( $dir . '/in.sql', $dir . '/in-out.sql', 'wp_', $state, $fill );
			$done  = $sql->run( new Slice_Budget( null, 1 ) ); // One statement per run.
			$state = $sql->state();
			++$runs;
		} while ( ! $done );
		Sf_Test::ok( $runs > 5, 'resumed between statements (' . $runs . ' runs)' );
		Sf_Test::same( array( 'wp_options', 'wp_usermeta' ), array_keys( $state['tables'] ) );
		Sf_Test::same( array( 7, 2 ), array( $state['tables']['wp_options']['rows'], $state['tables']['wp_usermeta']['rows'] ), 'rows' );
		Sf_Test::same( array( '60717', 'utf8mb4', 'utf8mb4_unicode_520_ci', 0 ), array( $state['db_version'], $state['charset'], $state['collate'], $state['stray'] ) );

		$out = (string) file_get_contents( $dir . '/in-out.sql' );
		Sf_Test::ok( false === strpos( $out, 'SERVMASK_PREFIX_' ) && false === strpos( $out, 'TRANSACTION' ) && false === strpos( $out, 'COMMIT' ), 'no placeholders or transactions left' );
		Sf_Test::ok( false !== strpos( $out, "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (3,'wp_user_roles'," ), 'column list and restored option name' );
		Sf_Test::ok( false !== strpos( $out, "'wp_capabilities'" ), 'restored meta key' );
		Sf_Test::ok( false !== strpos( $out, "VALUES (5,'template','parent-theme','on');" ), 'empty theme filled in from package.json' );
		Sf_Test::ok( false !== strpos( $out, "VALUES (6,'stylesheet','kept-child','on');" ), 'a value the dump has is kept' );
		Sf_Test::ok( false !== strpos( $out, "VALUES (7,'active_plugins','a:1:{i:0;s:10:\"a/it\\'s.php\";}','on');" ), 'active plugins filled in, serialized and escaped' );
		Sf_Test::ok( "-- SiteFerry dump end\n" === substr( $out, -strlen( "-- SiteFerry dump end\n" ) ), 'end marker' );

		$rewriter = new Statement_Rewriter( 'wp_', 'sft123_' );
		$types    = array();
		foreach ( sf_statements( $dir . '/in-out.sql' ) as $statement ) {
			$types[] = $rewriter->rewrite( $statement )['type'];
		}
		Sf_Test::same( array( 'skip', 'skip', 'drop', 'create', 'insert', 'insert', 'insert', 'insert', 'insert', 'insert', 'drop', 'create', 'insert', 'insert', 'skip' ), $types, 'every statement accepted by the importer' );
	}
);

Sf_Test::run(
	'database out: placeholder prefix in names and option/meta keys, one row per INSERT, transactions; back again gives the same rows',
	static function () use ( $dir ) {
		$dump = "-- SiteFerry database dump\nSET FOREIGN_KEY_CHECKS = 0;\n"
			. "DROP TABLE IF EXISTS `wp_options`;\n"
			. "CREATE TABLE `wp_options` (\n  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `option_name` varchar(191) NOT NULL DEFAULT '',\n  `option_value` longtext NOT NULL,\n  `autoload` varchar(20) NOT NULL DEFAULT 'yes',\n  PRIMARY KEY (`option_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
			. "INSERT INTO `wp_options` (`option_id`, `option_name`, `option_value`, `autoload`) VALUES (1,'wp_user_roles','a:0:{}','on'),\n(2,'home','wp_not_a_key','on'),(3,'blogname','A (b), c','on'),(4,'wp_page_for_privacy_policy','3','on');\n"
			. "DROP TABLE IF EXISTS `wp_usermeta`;\n"
			. "CREATE TABLE `wp_usermeta` (\n  `umeta_id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `user_id` bigint unsigned NOT NULL DEFAULT '0',\n  `meta_key` varchar(255) DEFAULT NULL,\n  `meta_value` longtext,\n  PRIMARY KEY (`umeta_id`)\n) ENGINE=InnoDB;\n"
			. "INSERT INTO `wp_usermeta` (`umeta_id`, `user_id`, `meta_key`, `meta_value`) VALUES (1,1,'wp_capabilities','a:0:{}'),(2,1,'nickname','wp_x');\n"
			. "DROP TABLE IF EXISTS `wp_posts`;\n"
			. "CREATE TABLE `wp_posts` (\n  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text NOT NULL,\n  `slug` varchar(20) GENERATED ALWAYS AS (lower(`post_title`)) VIRTUAL,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB;\n"
			. "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES (1,'Hello; world');\n"
			. "SET FOREIGN_KEY_CHECKS = 1;\n-- SiteFerry dump end\n";
		file_put_contents( $dir . '/out.sql', $dump );
		$state = null;
		do {
			$sql   = new Sql_Out( $dir . '/out.sql', $dir . '/out-wpress.sql', 'wp_', $state );
			$done  = $sql->run( new Slice_Budget( null, 1 ) );
			$state = $sql->state();
		} while ( ! $done );
		$out = (string) file_get_contents( $dir . '/out-wpress.sql' );

		Sf_Test::ok( false !== strpos( $out, "DROP TABLE IF EXISTS `SERVMASK_PREFIX_options`;\nCREATE TABLE `SERVMASK_PREFIX_options` (" ), 'table names' );
		Sf_Test::ok( false !== strpos( $out, "START TRANSACTION;\nINSERT INTO `SERVMASK_PREFIX_options` VALUES (1,'SERVMASK_PREFIX_user_roles','a:0:{}','on');\nINSERT INTO `SERVMASK_PREFIX_options` VALUES (2,'home','wp_not_a_key','on');\n" ), 'one row each, option name keyed, other values untouched' );
		Sf_Test::ok( false !== strpos( $out, "VALUES (4,'wp_page_for_privacy_policy','3','on');" ), 'options whose names start with wp_ whatever the prefix stay as they are' );
		Sf_Test::ok( false !== strpos( $out, "VALUES (1,1,'SERVMASK_PREFIX_capabilities','a:0:{}');\nINSERT INTO `SERVMASK_PREFIX_usermeta` VALUES (2,1,'nickname','wp_x');" ), 'user meta keys get the placeholder, values do not' );
		Sf_Test::ok( false !== strpos( $out, "INSERT INTO `SERVMASK_PREFIX_posts` (`ID`, `post_title`) VALUES (1,'Hello; world');\nCOMMIT;" ), 'column list kept when a generated column is left out' );
		Sf_Test::same( 3, substr_count( $out, 'START TRANSACTION;' ), 'one transaction per table' );
		Sf_Test::ok( false === strpos( $out, 'SET ' ), 'no session statements' );

		$back  = null;
		$state = null;
		do {
			$sql   = new Sql_In( $dir . '/out-wpress.sql', $dir . '/back.sql', 'wp_', $state );
			$done  = $sql->run();
			$state = $sql->state();
		} while ( ! $done );
		Sf_Test::same( array( 4, 2, 1 ), array( $state['tables']['wp_options']['rows'], $state['tables']['wp_usermeta']['rows'], $state['tables']['wp_posts']['rows'] ), 'rows survive the round trip' );
		$rows = array();
		foreach ( sf_statements( $dir . '/back.sql' ) as $statement ) {
			if ( preg_match( '/^INSERT INTO `wp_options` \([^)]*\) VALUES (.*)$/s', $statement, $match ) ) {
				$rows = array_merge( $rows, Sql_Values::rows( $match[1] ) );
			}
		}
		Sf_Test::same( array( "1,'wp_user_roles','a:0:{}','on'", "2,'home','wp_not_a_key','on'", "3,'blogname','A (b), c','on'", "4,'wp_page_for_privacy_policy','3','on'" ), $rows, 'values unchanged' );
	}
);

Sf_Test::run(
	'package.json: manifest from a package with Windows paths, and package from a manifest',
	static function () {
		$package  = Package::parse( '{"SiteURL":"http:\/\/localhost:10009","HomeURL":"http:\/\/localhost:10009\/","Plugin":{"Version":"7.113"},"WordPress":{"Version":"7.1.4","Absolute":"C:\\\\Sites\\\\a\\\\public\/","Content":"C:\\\\Sites\\\\a\\\\public\/wp-content","Plugins":"C:\\\\Sites\\\\a\\\\public\/wp-content\/plugins","Themes":["C:\\\\Sites\\\\a\\\\public\/wp-content\/themes"],"Uploads":"C:\\\\Sites\\\\a\\\\public\/wp-content\/uploads","UploadsURL":"http:\/\/localhost:10009\/wp-content\/uploads\/"},"Database":{"Version":"8.4.0","Charset":"utf8","Collate":"","Prefix":"wp_"},"PHP":{"Version":"8.2.29","System":"WINNT","Integer":8},"Plugins":["a\/a.php"],"Template":"tt5","Stylesheet":"tt5"}' );
		$manifest = Package::to_manifest(
			$package,
			array(
				'created'    => 1793804642,
				'files'      => 10,
				'bytes'      => 2048,
				'size'       => 999,
				'tables'     => array( 'wp_options' => 4 ),
				'db_version' => '60717',
				'charset'    => 'utf8mb4',
				'collate'    => '',
			)
		);
		Sf_Test::same( 'http://localhost:10009', $manifest['site']['home'] );
		Sf_Test::same( 'C:/Sites/a/public', $manifest['paths']['abspath'] );
		Sf_Test::same( 'C:/Sites/a/public/wp-content/uploads', $manifest['paths']['uploads_dir'] );
		Sf_Test::same( 'http://localhost:10009/wp-content', $manifest['urls']['wp_content_url'] );
		Sf_Test::same( 'http://localhost:10009/wp-content/uploads', $manifest['urls']['uploads_url'] );
		Sf_Test::same( array( 'utf8mb4', 'wp_', 4, '60717' ), array( $manifest['database']['charset'], $manifest['database']['prefix'], $manifest['database']['rows'], $manifest['versions']['db_version'] ) );
		Sf_Test::same( 'All-in-One WP Migration 7.113', $manifest['generator']['from'] );

		$back = Package::from_manifest( $manifest );
		Sf_Test::same( array( 'http://localhost:10009', 'C:/Sites/a/public/', 'wp_', array( 'a/a.php' ) ), array( $back['HomeURL'], $back['WordPress']['Absolute'], $back['Database']['Prefix'], $back['Plugins'] ) );
		Sf_Test::ok( ! isset( $back['Encrypted'] ), 'never marked as encrypted' );
		Sf_Test::throws( Archive_Exception::CORRUPT, static function () { Package::parse( '{"SiteURL":1}' ); }, 'incomplete package' );
	}
);

Sf_Test::run(
	'entries an import leaves out',
	static function () {
		foreach ( array( 'package.json', 'database.sql', 'multisite.json', 'siteferry-0123456789ab/backups/x.tar', 'ai1wm-backups/a.wpress', 'plugins/' . basename( SITEFERRY_DIR ) . '/siteferry.php' ) as $path ) {
			Sf_Test::ok( Wpress::skipped( $path ), 'skipped: ' . $path );
		}
		foreach ( array( 'index.php', 'uploads/package.json', 'plugins/akismet/akismet.php', 'siteferry-notes/a.txt', 'themes/t/database.sql' ) as $path ) {
			Sf_Test::ok( ! Wpress::skipped( $path ), 'kept: ' . $path );
		}
	}
);

$sf_real = __DIR__ . '/tmp/wpress/aiowm-plain.wpress';
Sf_Test::run(
	'a real backup made by All-in-One WP Migration: every CRC checks out, the database converts and is accepted',
	static function () use ( $sf_real, $dir ) {
		if ( ! is_file( $sf_real ) ) {
			Sf_Test::skip( 'tests/tmp/wpress/aiowm-plain.wpress is not there' );
		}
		$described = Wpress::describe( $sf_real );
		Sf_Test::same( array( 'wpress', false ), array( $described['format'], $described['encrypted'] ) );

		$reader = Reader::open( $sf_real );
		$sink   = fopen( 'php://temp', 'w+b' );
		$count  = 0;
		$dump   = null;
		while ( null !== ( $entry = $reader->next() ) ) {
			++$count;
			if ( 'database.sql' === $entry->name ) {
				$dump = fopen( $dir . '/real.sql', 'wb' );
				$reader->copy_to( $dump );
				fclose( $dump );
				continue;
			}
			ftruncate( $sink, 0 );
			rewind( $sink );
			$reader->copy_to( $sink );
		}
		$reader->close();
		Sf_Test::ok( $count > 100 && null !== $dump, $count . ' entries read with their CRCs checked' );

		$sql = new Sql_In( $dir . '/real.sql', $dir . '/real-out.sql', 'wp_' );
		$sql->run();
		$state = $sql->state();
		Sf_Test::ok( isset( $state['tables']['wp_options'] ) && '' !== $state['db_version'], 'tables and db_version found' );
		$rewriter = new Statement_Rewriter( 'wp_', 'sft123_' );
		foreach ( sf_statements( $dir . '/real-out.sql' ) as $statement ) {
			$rewriter->rewrite( $statement );
		}
		Sf_Test::ok( true, 'every converted statement accepted' );
	}
);

$encrypted = __DIR__ . '/tmp/wpress/aiowm-encrypted.wpress';
Sf_Test::run(
	'a password-protected .wpress is recognized as such',
	static function () use ( $encrypted ) {
		if ( ! is_file( $encrypted ) ) {
			Sf_Test::skip( 'tests/tmp/wpress/aiowm-encrypted.wpress is not there' );
		}
		Sf_Test::ok( Wpress::describe( $encrypted )['encrypted'], 'encrypted' );
	}
);

exit( Sf_Test::finish( 'test-wpress' ) );
