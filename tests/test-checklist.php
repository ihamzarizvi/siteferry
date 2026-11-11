<?php
/**
 * After-move checklist: the decisions that need no WordPress (addresses, replacement pairs,
 * states, the card's timing and items added by the filter).
 *
 * Run: php tests/test-checklist.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Checklist;
use SiteFerry\Database\Replacer;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/** WordPress's own function in real use. */
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}

echo "Checklist tests\n";

Sf_Test::run(
	'same_site ignores scheme, www and a trailing slash',
	static function () {
		Sf_Test::ok( Checklist::same_site( 'http://www.example.com/', 'https://example.com' ), 'www and scheme' );
		Sf_Test::ok( ! Checklist::same_site( 'https://example.com', 'https://example.com/blog' ), 'subfolder differs' );
		Sf_Test::ok( ! Checklist::same_site( 'http://localhost:10016', 'http://localhost:10017' ), 'port differs' );
		Sf_Test::ok( ! Checklist::same_site( '', '' ), 'empty is never the same site' );
	}
);

Sf_Test::run(
	'address_state compares the request with the home address',
	static function () {
		Sf_Test::same( array( 'ok', '' ), Checklist::address_state( 'https://example.com', 'example.com', true ) );
		Sf_Test::same( array( 'ok', '' ), Checklist::address_state( 'http://localhost:10016', 'LOCALHOST:10016', false ), 'case and port:' );
		Sf_Test::same( array( 'warn', 'host' ), Checklist::address_state( 'https://old.example', 'new.example', true ) );
		Sf_Test::same( array( 'warn', 'scheme' ), Checklist::address_state( 'http://example.com', 'example.com', true ) );
		Sf_Test::same( array( 'ok', '' ), Checklist::address_state( 'https://example.com', 'example.com', false ), 'https home opened on http is fine (redirects):' );
		Sf_Test::same( array( 'info', 'no-request' ), Checklist::address_state( 'https://example.com', null, false ) );
	}
);

Sf_Test::run(
	'old_pairs replace the old addresses and paths, and the replacement keeps serialized data whole',
	static function () {
		Sf_Test::same( array(), Checklist::old_pairs( null ) );
		$move  = array(
			'from' => array(
				'home'    => 'http://old-site.test',
				'siteurl' => 'http://old-site.test',
				'abspath' => '/var/www/old/',
			),
			'to'   => array(
				'home'    => 'http://localhost:10016',
				'siteurl' => 'http://localhost:10016',
				'abspath' => 'C:/sites/dst/app/public/',
			),
			'time' => 1,
		);
		$pairs = Checklist::old_pairs( $move );
		Sf_Test::same( 'http://localhost:10016', $pairs['http://old-site.test'] ?? null, 'plain address:' );
		Sf_Test::ok( isset( $pairs['http:\\/\\/old-site.test'] ), 'JSON-slashed address too' );
		Sf_Test::ok( isset( $pairs['/var/www/old'] ) || isset( $pairs['/var/www/old/'] ), 'path pair' );
		$needles = Checklist::needles( $pairs );
		Sf_Test::ok( count( $needles ) >= 1 && count( $needles ) <= count( $pairs ), 'needles: ' . implode( ', ', $needles ) );
		foreach ( array_keys( $pairs ) as $search ) {
			$found = false;
			foreach ( $needles as $needle ) {
				$found = $found || false !== strpos( (string) $search, $needle );
			}
			Sf_Test::ok( $found, 'a needle finds ' . $search );
		}
		$replacer = new Replacer( $pairs );
		$value    = serialize( array( 'img' => 'http://old-site.test/wp-content/uploads/x.jpg' ) );
		$out      = $replacer->replace( $value );
		Sf_Test::same( array( 'img' => 'http://localhost:10016/wp-content/uploads/x.jpg' ), unserialize( $out ), 'serialized:' );
	}
);

Sf_Test::run(
	'mixed_pairs: only on an https site, for its own host, plain and JSON-slashed',
	static function () {
		Sf_Test::same( array(), Checklist::mixed_pairs( 'http://example.com' ) );
		Sf_Test::same( array(), Checklist::mixed_pairs( 'not a url' ) );
		Sf_Test::same(
			array(
				'http://example.com:8443'     => 'https://example.com:8443',
				'http:\\/\\/example.com:8443' => 'https:\\/\\/example.com:8443',
			),
			Checklist::mixed_pairs( 'https://example.com:8443/blog' )
		);
	}
);

Sf_Test::run(
	'fixable, search and permalink states',
	static function () {
		foreach ( array( 'old_address', 'mixed_content', 'permalinks', 'caches', 'email', 'search' ) as $item ) {
			Sf_Test::ok( Checklist::fixable( $item ), $item . ' has a fix' );
		}
		foreach ( array( 'address', 'cron', 'files', 'nope', '' ) as $item ) {
			Sf_Test::ok( ! Checklist::fixable( $item ), $item . ' has no fix' );
		}
		Sf_Test::same( 'ok', Checklist::search_state( '1', false ) );
		Sf_Test::same( 'warn', Checklist::search_state( '0', false ) );
		Sf_Test::same( 'ok', Checklist::search_state( '0', true ), 'a staging copy stays hidden:' );
		Sf_Test::same( array( 'ok', 'plain' ), Checklist::permalink_state( '', false, 'apache', false ) );
		Sf_Test::same( array( 'warn', 'rules' ), Checklist::permalink_state( '/%postname%/', false, 'other', false ) );
		Sf_Test::same( array( 'warn', 'htaccess' ), Checklist::permalink_state( '/%postname%/', true, 'apache', false ) );
		Sf_Test::same( array( 'ok', '' ), Checklist::permalink_state( '/%postname%/', true, 'apache', true ) );
		Sf_Test::same( array( 'info', 'nginx' ), Checklist::permalink_state( '/%postname%/', true, 'nginx', false ) );
	}
);

Sf_Test::run(
	'valid_move and card_due',
	static function () {
		Sf_Test::same( null, Checklist::valid_move( 'x' ) );
		Sf_Test::same( null, Checklist::valid_move( array( 'from' => array( 'home' => 'javascript:x' ), 'to' => array( 'home' => 'http://a.test' ), 'time' => 1 ) ) );
		$move = Checklist::valid_move( array( 'from' => array( 'home' => 'http://a.test' ), 'to' => array( 'home' => 'https://b.test' ), 'time' => '100' ) );
		Sf_Test::same( 100, $move['time'] ?? null, 'time as int:' );
		$now = 100 + 3 * DAY_IN_SECONDS;
		Sf_Test::ok( Checklist::card_due( $move, 0, $now ), 'shown three days after' );
		Sf_Test::ok( ! Checklist::card_due( $move, 100, $now ), 'dismissed for this move' );
		Sf_Test::ok( Checklist::card_due( $move, 50, $now ), 'dismissing an earlier move does not hide a new one' );
		Sf_Test::ok( ! Checklist::card_due( $move, 0, 100 + 15 * DAY_IN_SECONDS ), 'gone after 14 days' );
		Sf_Test::ok( ! Checklist::card_due( null, 0, $now ), 'no move, no card' );
	}
);

Sf_Test::run(
	'clean_item keeps valid extra items, without markup, never with a fix',
	static function () {
		$item = Checklist::clean_item(
			array(
				'id'     => 'my-cdn',
				'title'  => '<b>CDN</b>',
				'state'  => 'warn',
				'detail' => 'Point it <script>x</script>at the new address.',
				'fix'    => array( 'label' => 'Do it' ),
			)
		);
		Sf_Test::same( 'CDN', $item['title'] ?? null );
		Sf_Test::ok( array_key_exists( 'fix', $item ) && null === $item['fix'], 'fix is null' );
		Sf_Test::ok( false === strpos( (string) $item['detail'], '<' ), 'no markup in detail' );
		Sf_Test::same( null, Checklist::clean_item( array( 'id' => 'search', 'title' => 'x', 'state' => 'ok' ) ), 'a built-in id:' );
		Sf_Test::same( null, Checklist::clean_item( array( 'id' => 'Bad Id', 'title' => 'x', 'state' => 'ok' ) ), 'bad id:' );
		Sf_Test::same( null, Checklist::clean_item( array( 'id' => 'x', 'title' => 'x', 'state' => 'great' ) ), 'bad state:' );
		Sf_Test::same( null, Checklist::clean_item( 'x' ), 'not an array:' );
	}
);

exit( Sf_Test::finish( 'test-checklist' ) );
