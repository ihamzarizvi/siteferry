<?php
/**
 * Looking inside a backup and choosing parts to restore, the parts that need no WordPress: the
 * index of a .tar, .tar.gz and .wpress (built in pieces when time runs out, cached, forgotten),
 * folder listings, and the checks on the partial-restore options.
 *
 * Run: php tests/test-contents.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Tar_Writer;
use SiteFerry\Contents;
use SiteFerry\Import\Import;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Wpress\Writer;

echo "Contents tests\n";

$content = Sf_Test::temp_dir( 'contents' );

// The few WordPress pieces the code under test touches: SiteFerry's storage folder lives in a
// temporary wp-content.
define( 'WP_CONTENT_DIR', $content );
$GLOBALS['sf_options'] = array( 'siteferry_storage' => 'siteferry-0123456789ab' );

/**
 * Stand-in for get_option().
 *
 * @param string $name    Option.
 * @param mixed  $default Default.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['sf_options'] ) ? $GLOBALS['sf_options'][ $name ] : $default;
}

/**
 * Stand-in for update_option().
 *
 * @param string $name  Option.
 * @param mixed  $value Value.
 */
function update_option( $name, $value ) {
	$GLOBALS['sf_options'][ $name ] = $value;
	return true;
}


/**
 * Stand-in for untrailingslashit().
 *
 * @param string $text Text.
 */
function untrailingslashit( $text ) {
	return rtrim( (string) $text, '/\\' );
}

$backups = $content . '/siteferry-0123456789ab/backups';
mkdir( $backups, 0755, true );

/**
 * Writes a backup laid out like a SiteFerry one.
 *
 * @param string $file    Target.
 * @param array  $options Tar writer options.
 */
function sf_backup( string $file, array $options = array() ): void {
	$writer = Tar_Writer::create( $file, $options );
	$writer->add_string( 'siteferry.json', '{"format":"siteferry"}', 1700000000 );
	$writer->add_string( 'database.sql', "-- dump\n", 1700000000 );
	$writer->add_directory( 'wp-content/', 1700000000 );
	$writer->add_directory( 'wp-content/plugins/', 1700000000 );
	$writer->add_directory( 'wp-content/plugins/akismet/', 1700000000 );
	$writer->add_string( 'wp-content/plugins/akismet/akismet.php', str_repeat( 'a', 300 ), 1700000001 );
	$writer->add_string( 'wp-content/plugins/akismet/readme.txt', str_repeat( 'r', 50 ), 1700000002 );
	$writer->add_string( 'wp-content/plugins/hello.php', 'hello', 1700000003 );
	$writer->add_directory( 'wp-content/plugins/plugin10/', 1700000000 );
	$writer->add_string( 'wp-content/plugins/plugin10/a.php', 'x', 1700000000 );
	$writer->add_directory( 'wp-content/plugins/plugin9/', 1700000000 );
	$writer->add_string( 'wp-content/plugins/plugin9/a.php', 'xy', 1700000000 );
	$writer->add_string( 'wp-content/uploads/2026/11/photo.jpg', str_repeat( 'p', 1000 ), 1700000004 );
	$writer->add_string( 'wp-content/index.php', '<?php', 1700000005 );
	$writer->add_directory( 'wp-content/empty/', 1700000000 );
	$writer->add_string( 'siteferry.sha256', "...\n", 1700000000 );
	$writer->finish();
	$writer->close();
}

/**
 * Names in a listing.
 *
 * @param array $listing Listing.
 * @return string[]
 */
function sf_names( array $listing ): array {
	return array_column( $listing, 'name' );
}

$tar = $backups . '/site-2026-11-05-aaaaaaaaaaaa.tar';
sf_backup( $tar );
$gz = $backups . '/site-2026-11-05-bbbbbbbbbbbb.tar.gz';
sf_backup( $gz, array( 'compress' => true, 'block_size' => 256 ) );

Sf_Test::run(
	'a .tar is indexed in one go: every entry, with type, size and time',
	static function () use ( $tar ) {
		$index = Contents::index( $tar );
		Sf_Test::same( true, $index['complete'] );
		Sf_Test::same( filesize( $tar ), $index['size'] );
		$byPath = array();
		foreach ( $index['entries'] as $entry ) {
			$byPath[ $entry[0] ] = $entry;
		}
		Sf_Test::same( array( 'wp-content/plugins/akismet/akismet.php', 'f', 300, 1700000001 ), $byPath['wp-content/plugins/akismet/akismet.php'] );
		Sf_Test::same( 'd', $byPath['wp-content/plugins/akismet'][1], 'folders without the trailing slash:' );
		Sf_Test::same( 16, count( $index['entries'] ) );
	}
);

Sf_Test::run(
	'listings: folders first in natural order, with their file counts and sizes; then files',
	static function () use ( $tar ) {
		$entries = Contents::index( $tar )['entries'];
		$top     = Contents::listing( $entries, '' );
		Sf_Test::same( array( 'wp-content', 'database.sql', 'siteferry.json', 'siteferry.sha256' ), sf_names( $top ) );
		Sf_Test::same( 'dir', $top[0]['type'] );
		Sf_Test::same( 7, $top[0]['files'], 'files below wp-content:' );
		Sf_Test::same( 300 + 50 + 5 + 1 + 2 + 1000 + 5, $top[0]['size'] );
		Sf_Test::same( 'file', $top[1]['type'] );
		Sf_Test::same( 'database.sql', $top[1]['path'] );

		$content = Contents::listing( $entries, 'wp-content' );
		Sf_Test::same( array( 'empty', 'plugins', 'uploads', 'index.php' ), sf_names( $content ) );
		Sf_Test::same( 0, $content[0]['files'], 'an empty folder is still listed:' );
		Sf_Test::same( 'dir', $content[2]['type'], 'a folder known only from the paths below it:' );

		$plugins = Contents::listing( $entries, '/wp-content/plugins/' );
		Sf_Test::same( array( 'akismet', 'plugin9', 'plugin10', 'hello.php' ), sf_names( $plugins ) );
		Sf_Test::same( 'wp-content/plugins/akismet', $plugins[0]['path'] );
		Sf_Test::same( 2, $plugins[0]['files'] );
		Sf_Test::same( 350, $plugins[0]['size'] );
		Sf_Test::same( array( 'name' => 'hello.php', 'path' => 'wp-content/plugins/hello.php', 'type' => 'file', 'size' => 5, 'files' => 0, 'mtime' => 1700000003 ), $plugins[3] );

		Sf_Test::same( array(), Contents::listing( $entries, 'wp-content/nothing' ) );
		Sf_Test::same( array(), Contents::listing( $entries, 'wp-cont' ), 'a prefix of a name is not a folder:' );
	}
);

Sf_Test::run(
	'a .tar.gz is indexed in pieces when time runs out, and each call continues the last',
	static function () use ( $gz, $tar ) {
		$calls = 0;
		$last  = 0;
		do {
			$index = Contents::index( $gz, 0.0 );
			++$calls;
			Sf_Test::ok( $index['read'] >= $last && $index['read'] <= $index['size'], 'progress in bytes of the file only grows (' . $index['read'] . ' of ' . $index['size'] . ')' );
			$last = $index['read'];
		} while ( ! $index['complete'] && $calls < 100 );
		Sf_Test::ok( $calls > 3, 'it took several calls (' . $calls . ')' );
		Sf_Test::same( Contents::index( $tar )['entries'], $index['entries'], 'the same entries as the .tar:' );
	}
);

Sf_Test::run(
	'the index is cached under the backup\'s name, size and time; a changed file is read again; deleting forgets it',
	static function () use ( $tar, $content ) {
		$dir   = $content . '/siteferry-0123456789ab/index';
		$files = glob( $dir . '/idx-' . substr( sha1( basename( $tar ) ), 0, 16 ) . '-*.json' );
		Sf_Test::same( 1, count( $files ) );
		Sf_Test::ok( is_file( $dir . '/.htaccess' ), 'the index folder is protected' );

		clearstatcache();
		touch( $tar, 1700001000 );
		clearstatcache();
		Sf_Test::same( true, Contents::index( $tar )['complete'] );
		$again = glob( $dir . '/idx-' . substr( sha1( basename( $tar ) ), 0, 16 ) . '-*.json' );
		Sf_Test::same( 1, count( $again ), 'the old index is replaced:' );
		Sf_Test::ok( $again !== $files, 'with a new name' );

		Contents::forget( '/' . basename( $tar ) );
		Sf_Test::same( array(), glob( $dir . '/idx-' . substr( sha1( basename( $tar ) ), 0, 16 ) . '-*.json' ) );
	}
);

Sf_Test::run(
	'a .wpress lists its package and dump at the top and everything else under wp-content',
	static function () use ( $backups ) {
		$file   = $backups . '/site-2026-11-05-cccccccccccc.wpress';
		$writer = Writer::create( $file );
		$writer->add_string( 'package.json', '.', '{"SiteURL":"http://x"}', 1700000000 );
		$writer->add_string( 'database.sql', '.', "-- dump\n", 1700000000 );
		$writer->add_string( 'akismet.php', 'plugins/akismet', 'aaa', 1700000000 );
		$writer->add_string( 'photo.jpg', 'uploads/2026', 'pppp', 1700000000 );
		$writer->seal();
		$writer->close();

		$entries = Contents::index( $file )['entries'];
		Sf_Test::same( array( 'wp-content', 'database.sql', 'package.json' ), sf_names( Contents::listing( $entries, '' ) ) );
		Sf_Test::same( array( 'akismet' ), sf_names( Contents::listing( $entries, 'wp-content/plugins' ) ) );
		Sf_Test::same( 3, Contents::listing( $entries, 'wp-content/plugins' )[0]['size'] );
	}
);

Sf_Test::run(
	'partial restore options: tables and paths are checked, cleaned and deduplicated',
	static function () {
		$options = Import::normalize(
			array(
				'restore_tables' => array( 'wp_posts', ' wp_postmeta ', 'wp_posts' ),
				'restore_paths'  => array( 'wp-content/plugins/akismet', 'uploads', 'themes\\twentytwentyfive', 'plugins/./hello.php', 'uploads/' ),
			)
		);
		Sf_Test::same( array( 'wp_posts', 'wp_postmeta' ), $options['restore_tables'] );
		Sf_Test::same( array( 'plugins/akismet', 'uploads', 'themes/twentytwentyfive', 'plugins/hello.php' ), $options['restore_paths'] );
		Sf_Test::same( true, $options['restore_database'] );
		Sf_Test::same( true, $options['restore_files'] );

		$all = Import::normalize( array( 'restore_paths' => array( 'plugins/akismet', 'wp-content' ) ) );
		Sf_Test::same( array(), $all['restore_paths'], '"wp-content" means all files:' );

		$defaults = Import::normalize( array() );
		Sf_Test::same( array( true, array(), true, array() ), array( $defaults['restore_database'], $defaults['restore_tables'], $defaults['restore_files'], $defaults['restore_paths'] ) );

		foreach ( array( 'wp_posts; DROP TABLE x', 'wp`posts', str_repeat( 'a', 65 ), '' ) as $bad ) {
			try {
				Import::normalize( array( 'restore_tables' => array( $bad ) ) );
				Sf_Test::ok( false, 'refused: table "' . $bad . '"' );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( false !== strpos( $e->getMessage(), 'Invalid table name' ), 'refused: table "' . substr( $bad, 0, 20 ) . '"' );
			}
		}
		foreach ( array( '../wp-config.php', 'plugins/../../x', 'C:/x', '', 'uploads/2026', 'plugins/akismet/akismet.php', 'languages/plugins' ) as $bad ) {
			try {
				Import::normalize( array( 'restore_paths' => array( $bad ) ) );
				Sf_Test::ok( false, 'refused: path "' . $bad . '"' );
			} catch ( Job_Exception $e ) {
				Sf_Test::ok( 1 === preg_match( '/^(Invalid path|Only a whole folder)/', $e->getMessage() ), 'refused: path "' . $bad . '" (' . $e->getMessage() . ')' );
			}
		}
		try {
			Import::normalize( array( 'restore_paths' => array_fill( 0, 501, 'uploads' ) ) );
			Sf_Test::ok( true, 'duplicates are counted once' );
		} catch ( Job_Exception $e ) {
			Sf_Test::ok( false, 'duplicates are counted once' );
		}
	}
);

Sf_Test::run(
	'which paths and tables a partial restore takes from the backup',
	static function () use ( $content ) {
		$base = $content . '/jobs';
		mkdir( $base, 0755, true );
		$make = static function ( array $options ) use ( $base ): Job {
			list( $job ) = Job::create( $base, 'import', array( 'test.count' ), Import::normalize( $options ) );
			$job->set_state( 'inspect', array( 'prefix' => 'wp_' ) );
			return $job;
		};

		$all = $make( array() );
		Sf_Test::same( false, Import::partial( $all ) );
		Sf_Test::same( null, Import::table_suffixes( $all ) );
		Sf_Test::same( true, Import::wanted_path( $all, 'wp-content/uploads/2026/x.jpg' ) );

		$some = $make(
			array(
				'restore_tables' => array( 'wp_posts', 'wp_postmeta' ),
				'restore_paths'  => array( 'plugins/akismet', 'uploads' ),
			)
		);
		Sf_Test::same( true, Import::partial( $some ) );
		Sf_Test::same( array( 'posts', 'postmeta' ), Import::table_suffixes( $some ) );
		$wanted = array(
			'siteferry.json'                         => true,
			'database.sql'                           => true,
			'wp-content'                             => true,
			'wp-content/plugins'                     => true,
			'wp-content/plugins/akismet'             => true,
			'wp-content/plugins/akismet/akismet.php' => true,
			'wp-content/plugins/akismet-pro'         => false,
			'wp-content/plugins/hello.php'           => false,
			'wp-content/uploads/2026/11/photo.jpg'   => true,
			'wp-content/uploadsx'                    => false,
			'wp-content/themes/twentytwentyfive'     => false,
			'wp-content/index.php'                   => false,
		);
		foreach ( $wanted as $path => $expected ) {
			Sf_Test::same( $expected, Import::wanted_path( $some, $path ), $path . ':' );
		}

		$db_only = $make( array( 'restore_files' => false ) );
		Sf_Test::same( true, Import::partial( $db_only ) );
		Sf_Test::same( false, Import::wanted_path( $db_only, 'wp-content' ) );
		Sf_Test::same( true, Import::wanted_path( $db_only, 'database.sql' ) );
		Sf_Test::same( null, Import::table_suffixes( $db_only ) );

		$files_only = $make( array( 'restore_database' => false ) );
		Sf_Test::same( array(), Import::table_suffixes( $files_only ), 'no tables at all:' );
		Sf_Test::same( true, Import::wanted_path( $files_only, 'wp-content/themes/x/style.css' ) );
	}
);

exit( Sf_Test::finish( 'test-contents' ) );
