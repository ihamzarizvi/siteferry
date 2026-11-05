<?php
/**
 * Multisite networks, the part that needs no WordPress: where every subsite of a backed-up network
 * goes in the network it is restored into (subfolders and subdomains, a new domain and path, a
 * subsite on a domain of its own).
 *
 * Run: php tests/test-network.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Import\Inspect_Step;

echo "Network tests\n";

/**
 * The domain, path and URL of each mapped subsite.
 *
 * @param array $map Network map.
 */
function sf_places( array $map ): array {
	$out = array();
	foreach ( $map['blogs'] as $blog ) {
		$out[ $blog['id'] ] = array( $blog['domain'], $blog['path'], $blog['url'], $blog['old_url'] );
	}
	return $out;
}

Sf_Test::run(
	'subfolders: the same subsites under the new domain and path',
	static function () {
		$from = array(
			'id'                => 1,
			'domain'            => 'old.example',
			'path'              => '/',
			'subdomain_install' => false,
			'main_site'         => 1,
			'blogs'             => array(
				array( 'id' => 1, 'domain' => 'old.example', 'path' => '/' ),
				array( 'id' => 2, 'domain' => 'old.example', 'path' => '/alpha/' ),
				array( 'id' => 5, 'domain' => 'old.example', 'path' => '/beta/' ),
			),
		);
		$to   = array( 'id' => 1, 'domain' => 'new.example:8080', 'path' => '/net/', 'subdomain_install' => false );
		$map  = Inspect_Step::network_map( $from, $to, 'https' );
		Sf_Test::same( array( 'new.example:8080', '/net/', 'https://new.example:8080/net/' ), array( $map['domain'], $map['path'], $map['url'] ) );
		Sf_Test::same(
			array(
				1 => array( 'new.example:8080', '/net/', 'https://new.example:8080/net', 'https://old.example' ),
				2 => array( 'new.example:8080', '/net/alpha/', 'https://new.example:8080/net/alpha', 'https://old.example/alpha' ),
				5 => array( 'new.example:8080', '/net/beta/', 'https://new.example:8080/net/beta', 'https://old.example/beta' ),
			),
			sf_places( $map )
		);
	}
);

Sf_Test::run(
	'subdomains: each keeps its first label; a subsite on its own domain keeps that domain',
	static function () {
		$from = array(
			'id'                => 3,
			'domain'            => 'old.example',
			'path'              => '/',
			'subdomain_install' => true,
			'main_site'         => 1,
			'blogs'             => array(
				array( 'id' => 1, 'domain' => 'old.example', 'path' => '/' ),
				array( 'id' => 2, 'domain' => 'shop.old.example', 'path' => '/' ),
				array( 'id' => 3, 'domain' => 'brand.test', 'path' => '/' ),
				array( 'id' => 4, 'domain' => 'notold.example', 'path' => '/' ),
			),
		);
		$map  = Inspect_Step::network_map( $from, array( 'id' => 1, 'domain' => 'new.test', 'path' => '/', 'subdomain_install' => true ), 'http' );
		Sf_Test::same( 3, $map['id'], 'the backup\'s network id (its rows are updated):' );
		Sf_Test::same(
			array(
				1 => array( 'new.test', '/', 'http://new.test', 'http://old.example' ),
				2 => array( 'shop.new.test', '/', 'http://shop.new.test', 'http://shop.old.example' ),
				3 => array( 'brand.test', '/', 'http://brand.test', 'http://brand.test' ),
				4 => array( 'notold.example', '/', 'http://notold.example', 'http://notold.example' ),
			),
			sf_places( $map ),
			'"notold.example" is not a subdomain of "old.example":'
		);
	}
);

exit( Sf_Test::finish( 'test-network' ) );
