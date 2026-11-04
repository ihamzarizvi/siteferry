<?php
/**
 * Import building blocks: serialization-safe replacement, replacement pairs, dump statement
 * reading and statement rewriting.
 *
 * Run: php tests/test-import-sql.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Database\Replacer;
use SiteFerry\Database\Statement_Reader;
use SiteFerry\Database\Statement_Rewriter;
use SiteFerry\Import\Replacements;

echo "Import SQL tests\n";

/**
 * Test class with private and protected properties.
 */
final class Sf_Widget {
	/**
	 * Private URL.
	 *
	 * @var string
	 */
	private $link = 'http://old.test/private';

	/**
	 * Protected list.
	 *
	 * @var array
	 */
	protected $items = array( 'http://old.test/a', 'unchanged' );

	/**
	 * Public property.
	 *
	 * @var string
	 */
	public $title = 'Café ☕ http://old.test';

	/**
	 * Values for assertions.
	 *
	 * @return array
	 */
	public function values(): array {
		return array( $this->link, $this->items, $this->title );
	}
}

$pairs = array(
	'http://old.test'   => 'https://new.example',
	'https://old.test'  => 'https://new.example',
	'//old.test'        => '//new.example',
	'http:\/\/old.test' => 'https:\/\/new.example',
);

Sf_Test::run(
	'plain values: one pass, longest match first, no re-replacement',
	static function () use ( $pairs ) {
		$r = new Replacer( $pairs );
		Sf_Test::same( 'see https://new.example/x and https://new.example and //new.example/y', $r->replace( 'see http://old.test/x and https://old.test and //old.test/y' ) );
		Sf_Test::same( '{"u":"https:\/\/new.example\/p"}', $r->replace( '{"u":"http:\/\/old.test\/p"}' ) );
		Sf_Test::same( 'nothing here', $r->replace( 'nothing here' ) );
		$cascade = new Replacer( array( 'a' => 'b', 'b' => 'c' ) );
		Sf_Test::same( 'bc', $cascade->replace( 'ab' ), 'replaced text is not searched again:' );
	}
);

Sf_Test::run(
	'serialized arrays: lengths recomputed, multibyte safe, keys untouched',
	static function () use ( $pairs ) {
		$r      = new Replacer( $pairs );
		$data   = array(
			'http://old.test/key' => 'http://old.test/value',
			'nested'              => array( 'x' => 'Ünïcödé 😀 //old.test/img.png', 'n' => 42, 'f' => 1.5, 'b' => true, 'null' => null ),
		);
		$result = unserialize( $r->replace( serialize( $data ) ) );
		Sf_Test::same(
			array(
				'http://old.test/key' => 'https://new.example/value',
				'nested'              => array( 'x' => 'Ünïcödé 😀 //new.example/img.png', 'n' => 42, 'f' => 1.5, 'b' => true, 'null' => null ),
			),
			$result
		);
		Sf_Test::same( 1, $r->stats()['serialized'] );
	}
);

Sf_Test::run(
	'serialized inside serialized, and surrounding whitespace',
	static function () use ( $pairs ) {
		$r      = new Replacer( $pairs );
		$inner  = serialize( array( 'url' => 'http://old.test/inner' ) );
		$outer  = serialize( array( 'blob' => $inner, 'url' => 'http://old.test/outer' ) );
		$result = unserialize( trim( $r->replace( "  $outer\n" ) ) );
		Sf_Test::same( 'https://new.example/outer', $result['url'] );
		Sf_Test::same( array( 'url' => 'https://new.example/inner' ), unserialize( $result['blob'] ) );
		Sf_Test::same( "\n", substr( $r->replace( "$outer\n" ), -1 ), 'whitespace kept:' );
	}
);

Sf_Test::run(
	'serialized objects: private, protected and public properties, no class loaded while rewriting',
	static function () use ( $pairs ) {
		$r      = new Replacer( $pairs );
		$value  = serialize( array( 'widget' => new Sf_Widget(), 'std' => (object) array( 'u' => 'https://old.test/s' ) ) );
		$result = unserialize( $r->replace( $value ) );
		Sf_Test::same( array( 'https://new.example/private', array( 'https://new.example/a', 'unchanged' ), 'Café ☕ https://new.example' ), $result['widget']->values() );
		Sf_Test::same( 'https://new.example/s', $result['std']->u );
	}
);

Sf_Test::run(
	'references, floats, booleans and null survive',
	static function () use ( $pairs ) {
		$r       = new Replacer( $pairs );
		$shared  = 'http://old.test/shared';
		$data    = array( 'a' => $shared, 'f' => -0.000125, 't' => false, 'n' => null );
		$data['r'] = &$data['a'];
		$out     = $r->replace( serialize( $data ) );
		Sf_Test::ok( false !== strpos( $out, 'R:' ), 'reference token kept' );
		$result = unserialize( $out );
		Sf_Test::same( 'https://new.example/shared', $result['a'] );
		Sf_Test::same( 'https://new.example/shared', $result['r'] );
		Sf_Test::same( -0.000125, $result['f'] );
	}
);

Sf_Test::run(
	'broken serialized data gets a plain replacement; custom-serialized objects are left alone',
	static function () use ( $pairs ) {
		$r      = new Replacer( $pairs );
		$broken = 'a:1:{s:3:"url";s:99:"http://old.test";}';
		Sf_Test::same( 'a:1:{s:3:"url";s:99:"https://new.example";}', $r->replace( $broken ) );
		Sf_Test::same( 1, $r->stats()['broken'] );

		$payload = 'x:i:0;a:1:{i:0;s:15:"http://old.test";};m:a:0:{}';
		$custom  = 'a:1:{s:1:"c";C:11:"ArrayObject":' . strlen( $payload ) . ':{' . $payload . '}}';
		Sf_Test::ok( Replacer::is_valid_serialized( $custom ), 'test data must be valid' );
		Sf_Test::same( $custom, $r->replace( $custom ), 'custom payload unchanged:' );
		Sf_Test::ok( $r->stats()['opaque'] >= 1, 'counted as opaque' );
	}
);

Sf_Test::run(
	'replacement pairs: schemes, www, protocol-relative, JSON, URL-encoded and paths',
	static function () {
		$pairs = Replacements::build(
			array(
				'home'           => 'http://example.com/',
				'siteurl'        => 'http://example.com',
				'abspath'        => 'C:/Users/me/Local Sites/old/app/public',
				'wp_content_dir' => 'C:/Users/me/Local Sites/old/app/public/wp-content',
			),
			array(
				'home'           => 'https://new.test',
				'siteurl'        => 'https://new.test',
				'abspath'        => '/var/www/html',
				'wp_content_dir' => '/var/www/html/wp-content',
			),
			array( array( 'old-cdn.example.com/files', 'cdn.new.test/files' ) )
		);
		$expect = array(
			'http://example.com'                         => 'https://new.test',
			'https://example.com'                        => 'https://new.test',
			'http://www.example.com'                     => 'https://new.test',
			'https://www.example.com'                    => 'https://new.test',
			'//example.com'                              => '//new.test',
			'//www.example.com'                          => '//new.test',
			'http:\/\/example.com'                       => 'https:\/\/new.test',
			'\/\/www.example.com'                        => '\/\/new.test',
			'http%3A%2F%2Fexample.com'                   => 'https%3A%2F%2Fnew.test',
			'C:/Users/me/Local Sites/old/app/public'     => '/var/www/html',
			'C:\Users\me\Local Sites\old\app\public'     => '\var\www\html',
			'C:\\\\Users\\\\me\\\\Local Sites\\\\old\\\\app\\\\public' => '\\\\var\\\\www\\\\html',
			'C:\/Users\/me\/Local Sites\/old\/app\/public' => '\/var\/www\/html',
			'old-cdn.example.com/files'                  => 'cdn.new.test/files',
			'old-cdn.example.com\/files'                 => 'cdn.new.test\/files',
		);
		foreach ( $expect as $search => $replace ) {
			Sf_Test::ok( isset( $pairs[ $search ] ), "missing pair for $search" );
			Sf_Test::same( $replace, $pairs[ $search ], $search . ':' );
		}
		$r = new Replacer( $pairs );
		Sf_Test::same( 'https://new.test/wp-content/x.jpg and /var/www/html/wp-content/up', $r->replace( 'http://www.example.com/wp-content/x.jpg and C:/Users/me/Local Sites/old/app/public/wp-content/up' ) );
		Sf_Test::same( array(), Replacements::build( array( 'home' => 'http://same.test' ), array( 'home' => 'http://same.test/' ) ), 'same address: nothing to do:' );
		$local = Replacements::build( array( 'home' => 'http://localhost:10022' ), array( 'home' => 'http://localhost:10016' ) );
		Sf_Test::ok( ! isset( $local['http://www.localhost:10022'] ), 'no www variant for localhost' );
		Sf_Test::same( 'http://localhost:10016', $local['http://localhost:10022'] );
	}
);

$dir = Sf_Test::temp_dir( 'import-sql' );

Sf_Test::run(
	'statement reader: quotes, escapes, semicolons in strings, comments, several statements per line',
	static function () use ( $dir ) {
		$file = $dir . '/dump.sql';
		$sql  = "-- comment line\n# hash comment\n/* block\ncomment */\n"
			. "/*!40101 SET NAMES utf8mb4 */;\n"
			. "CREATE TABLE `wp_a` (\n  `id` int NOT NULL,\n  `v` text COMMENT 'it''s; fine'\n);\n"
			. "INSERT INTO `wp_a` (`id`, `v`) VALUES (1,'a;b'),(2,'quote \\' and \\\\'),(3,\"dq; \\\" x\");\n"
			. "INSERT INTO `w``eird` (`id`) VALUES (4); INSERT INTO `wp_a` (`id`) VALUES (5);\n"
			. "  \n";
		file_put_contents( $file, $sql );
		$reader     = new Statement_Reader( $file );
		$statements = array();
		$offsets    = array();
		while ( null !== ( $statement = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$statements[] = $statement;
			$offsets[]    = $reader->offset();
		}
		$reader->close();
		Sf_Test::same( 5, count( $statements ) );
		Sf_Test::same( '/*!40101 SET NAMES utf8mb4 */', $statements[0] );
		Sf_Test::same( "INSERT INTO `wp_a` (`id`, `v`) VALUES (1,'a;b'),(2,'quote \\' and \\\\'),(3,\"dq; \\\" x\")", $statements[2] );
		Sf_Test::same( 'INSERT INTO `w``eird` (`id`) VALUES (4)', $statements[3] );

		// Resuming at any saved offset gives the remaining statements.
		foreach ( $offsets as $index => $offset ) {
			$reader = new Statement_Reader( $file, $offset );
			$rest   = array();
			while ( null !== ( $statement = $reader->next() ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				$rest[] = $statement;
			}
			$reader->close();
			Sf_Test::same( array_slice( $statements, $index + 1 ), $rest, "resume after statement $index:" );
		}

		file_put_contents( $file, "INSERT INTO `wp_a` VALUES ('unterminated;\n" );
		Sf_Test::throws(
			Archive_Exception::TRUNCATED,
			static function () use ( $file ) {
				$reader = new Statement_Reader( $file );
				while ( null !== $reader->next() ) {
					continue;
				}
			}
		);
	}
);

Sf_Test::run(
	'rewriter: tables move to the temporary prefix, keys and collations follow',
	static function () {
		$rewriter = new Statement_Rewriter( 'wp_', 'sft1a2b3c_', array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_bin', 'utf8_general_ci' ), array( 'utf8mb4', 'utf8', 'latin1' ) );
		Sf_Test::same( 'skip', $rewriter->rewrite( '/*!40101 SET NAMES utf8mb4 */' )['type'] );
		Sf_Test::same( 'skip', $rewriter->rewrite( "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO,ANSI_QUOTES'" )['type'] );
		Sf_Test::same( 'skip', $rewriter->rewrite( "SET TIME_ZONE = '+00:00'" )['type'] );
		Sf_Test::same( 'DROP TABLE IF EXISTS `sft1a2b3c_posts`', $rewriter->rewrite( 'DROP TABLE IF EXISTS `wp_posts`' )['sql'] );

		$create = $rewriter->rewrite( "CREATE TABLE `wp_log` (\n  `id` int,\n  `p` bigint,\n  `t` text CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci,\n  CONSTRAINT `fk_log` FOREIGN KEY (`p`) REFERENCES `wp_perm` (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci" );
		Sf_Test::same( 'create', $create['type'] );
		Sf_Test::same( 'sft1a2b3c_log', $create['table'] );
		Sf_Test::ok( 0 === strpos( $create['sql'], 'CREATE TABLE `sft1a2b3c_log` (' ), $create['sql'] );
		Sf_Test::ok( false !== strpos( $create['sql'], 'REFERENCES `sft1a2b3c_perm`' ), 'reference renamed' );
		Sf_Test::ok( false !== strpos( $create['sql'], 'CONSTRAINT `sft1a2b3c_fk_log`' ), 'constraint renamed' );
		Sf_Test::ok( false !== strpos( $create['sql'], 'COLLATE=utf8mb4_unicode_520_ci' ), 'MySQL 8 collation mapped' );
		Sf_Test::ok( false !== strpos( $create['sql'], 'CHARACTER SET utf8 COLLATE utf8_general_ci' ), 'utf8mb3 mapped to utf8' );
		Sf_Test::same( array( 'utf8mb3' => 'utf8', 'utf8mb3_general_ci' => 'utf8_general_ci', 'utf8mb4_0900_ai_ci' => 'utf8mb4_unicode_520_ci' ), $rewriter->mapped() );

		$insert = $rewriter->rewrite( "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES (1,'INSERT INTO `wp_users` SELECT;'),(2,NULL),(-3,0x1f),(4,b'01'),(5,1.5e-10)" );
		Sf_Test::same( "INSERT INTO `sft1a2b3c_posts` (`ID`, `post_title`) VALUES (1,'INSERT INTO `wp_users` SELECT;'),(2,NULL),(-3,0x1f),(4,b'01'),(5,1.5e-10)", $insert['sql'] );
	}
);

Sf_Test::run(
	'rewriter refuses statements a SiteFerry dump never contains',
	static function () {
		$rewriter = new Statement_Rewriter( 'wp_', 'sft1a2b3c_' );
		$bad      = array(
			'DROP DATABASE wordpress',
			'DROP TABLE `wp_users`',
			'GRANT ALL ON *.* TO `x`@`%`',
			"CREATE USER 'x'@'%'",
			'INSERT INTO `other_users` (`id`) VALUES (1)',
			'INSERT INTO `mysql`.`user` (`id`) VALUES (1)',
			'INSERT INTO `wp_posts` (`id`) SELECT `id` FROM `wp_users`',
			'INSERT INTO `wp_posts` (`id`) VALUES ((SELECT `user_pass` FROM `wp_users` LIMIT 1))',
			'INSERT INTO `wp_posts` (`id`) VALUES (UNHEX(\'41\'))',
			'INSERT INTO `wp_posts` (`id`) VALUES (@x := 1)',
			'UPDATE `wp_users` SET `user_pass` = 1',
			'CREATE TABLE `wp_x` (`id` int) DATA DIRECTORY = \'/tmp\'',
			'CREATE TABLE `wp_x` (`id` int) ENGINE=FEDERATED CONNECTION=\'mysql://u@h/db/t\'',
			'CREATE TABLE `wp_x` (`id` int) AS SELECT * FROM `wp_users`',
			'CREATE TABLE `wp_x` (`p` int, FOREIGN KEY (`p`) REFERENCES `other` (`id`))',
			"SET GLOBAL general_log = 'ON'",
			"SET @@global.read_only = 1",
			'SET PASSWORD = \'x\'',
			'LOAD DATA INFILE \'/etc/passwd\' INTO TABLE `wp_x`',
		);
		foreach ( $bad as $statement ) {
			Sf_Test::throws(
				Archive_Exception::UNSUPPORTED,
				static function () use ( $rewriter, $statement ) {
					$rewriter->rewrite( $statement );
				},
				$statement
			);
		}
	}
);

exit( Sf_Test::finish( 'test-import-sql' ) );
