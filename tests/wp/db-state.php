<?php
/**
 * Read-only snapshot of a site's database state, as JSON (for the import checks).
 *
 *     wp eval-file tests/wp/db-state.php [needle]
 *
 * Prints home/siteurl, CHECKSUM TABLE of every base table, SiteFerry's storage option, leftover
 * import tables, invalid serialized values in the meta/options tables, and (with a needle) which
 * text columns still contain it (posts.guid excluded: GUIDs never change).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;

$needle = isset( $args[0] ) ? (string) $args[0] : '';
$state  = array(
	'home'               => (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'home'" ),
	'siteurl'            => (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" ),
	'storage'            => (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteferry_storage'" ),
	'checksums'          => array(),
	'import_tables'      => array(),
	'invalid_serialized' => 0,
	'hits'               => array(),
);

foreach ( $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
	if ( 'BASE TABLE' !== $row[1] ) {
		continue;
	}
	$table = (string) $row[0];
	if ( preg_match( '/^sf[to][a-f0-9]{6}_/', $table ) ) {
		$state['import_tables'][] = $table;
	}
	$sum                         = $wpdb->get_row( 'CHECKSUM TABLE `' . str_replace( '`', '``', $table ) . '`', ARRAY_N );
	$state['checksums'][ $table ] = (string) $sum[1];

	if ( '' === $needle || 0 !== strpos( $table, $wpdb->prefix ) ) {
		continue;
	}
	foreach ( $wpdb->get_results( 'SHOW FULL COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`', ARRAY_A ) as $column ) {
		if ( ! preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/i', $column['Type'] ) || ( $wpdb->posts === $table && 'guid' === $column['Field'] ) ) {
			continue;
		}
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', $table ) . '` WHERE `' . str_replace( '`', '``', $column['Field'] ) . '` LIKE %s', '%' . $wpdb->esc_like( $needle ) . '%' )
		);
		if ( $count > 0 ) {
			$state['hits'][ $table . '.' . $column['Field'] ] = $count;
		}
	}
}

foreach ( array( array( $wpdb->options, 'option_value' ), array( $wpdb->postmeta, 'meta_value' ), array( $wpdb->usermeta, 'meta_value' ) ) as list( $table, $column ) ) {
	foreach ( $wpdb->get_col( "SELECT `$column` FROM `$table` WHERE `$column` REGEXP '^[aOs]:[0-9]+:'" ) as $value ) {
		if ( 'b:0;' !== $value && false === @unserialize( $value, array( 'allowed_classes' => false ) ) ) { // phpcs:ignore
			++$state['invalid_serialized'];
		}
	}
}

// Always objects, so an empty result is {} rather than [].
$state['hits']      = (object) $state['hits'];
$state['checksums'] = (object) $state['checksums'];
echo wp_json_encode( $state, JSON_UNESCAPED_SLASHES ), "\n";
