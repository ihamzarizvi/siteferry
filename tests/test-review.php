<?php
/**
 * Asking for a review: when the question appears and how each answer ends or delays it.
 *
 * Run: php tests/test-review.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Review;

echo "Review tests\n";

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
$GLOBALS['sf_options'] = array();

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

Sf_Test::run(
	'asks after five backups or one restore, not before',
	static function () {
		$GLOBALS['sf_options'] = array();
		Sf_Test::same( false, Review::due(), 'a new site:' );
		for ( $i = 0; $i < 4; $i++ ) {
			Review::count( 'backups' );
		}
		Sf_Test::same( false, Review::due(), 'four backups:' );
		Review::count( 'backups' );
		Sf_Test::same( true, Review::due(), 'five backups:' );
		Sf_Test::same( true, Review::due( array( 'restores' => 1 ) ), 'one restore:' );
		Review::count( 'nonsense' );
		Sf_Test::same( 5, Review::state()['backups'], 'unknown kinds are not counted:' );
	}
);

Sf_Test::run(
	'"Rate SiteFerry" and "Don\'t ask again" end it; "Maybe later" waits 30 days',
	static function () {
		$GLOBALS['sf_options'] = array( Review::OPTION => array( 'backups' => 9 ) );
		Sf_Test::same( true, Review::answer( 'later' ) );
		Sf_Test::same( false, Review::due(), 'right after "later":' );
		Sf_Test::same( true, Review::due( null, time() + 31 * DAY_IN_SECONDS ), 'a month later:' );
		Sf_Test::same( true, Review::answer( 'rated' ) );
		Sf_Test::same( false, Review::due( null, time() + 400 * DAY_IN_SECONDS ), 'after rating, never again:' );
		Review::count( 'backups' );
		Sf_Test::same( 9, Review::state()['backups'], 'answered: nothing more is counted:' );
		$GLOBALS['sf_options'] = array( Review::OPTION => array( 'restores' => 2 ) );
		Review::answer( 'never' );
		Sf_Test::same( false, Review::due( null, time() + 400 * DAY_IN_SECONDS ), '"never" is final:' );
		Sf_Test::same( false, Review::answer( 'maybe' ), 'an unknown answer is refused' );
		Sf_Test::same( true, Review::due( array( 'answer' => 'bogus', 'backups' => 10, 'later' => 0 ) ), 'a bad stored answer counts as none:' );
	}
);

exit( Sf_Test::finish( 'test-review' ) );
