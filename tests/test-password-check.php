<?php
/**
 * The automatic backups' password: when to advise one, when to remind, the rate limit on tests
 * and the constant-time comparison.
 *
 * Run: php tests/test-password-check.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Schedule\Password_Check;

echo "Password check tests\n";

Sf_Test::run(
	'advice: only for scheduled backups sent to a storage without a password, until dismissed',
	static function () {
		Sf_Test::same( true, Password_Check::advise( true, true, false, false ) );
		Sf_Test::same( false, Password_Check::advise( false, true, false, false ), 'schedule off:' );
		Sf_Test::same( false, Password_Check::advise( true, false, false, false ), 'kept on this server only:' );
		Sf_Test::same( false, Password_Check::advise( true, true, true, false ), 'already protected:' );
		Sf_Test::same( false, Password_Check::advise( true, true, false, true ), 'dismissed:' );
	}
);

Sf_Test::run(
	'reminder: once a month while backups are protected',
	static function () {
		$month = Password_Check::MONTH;
		Sf_Test::same( false, Password_Check::remind( true, 1000, 1000 + $month - 1 ), 'not yet:' );
		Sf_Test::same( true, Password_Check::remind( true, 1000, 1000 + $month ), 'a month later:' );
		Sf_Test::same( false, Password_Check::remind( false, 1000, 1000 + 2 * $month ), 'not protected:' );
		Sf_Test::same( false, Password_Check::remind( true, 0, 1000 + 2 * $month ), 'never seen yet (the month starts then):' );
	}
);

Sf_Test::run(
	'tests: five tries in fifteen minutes, then wait; comparison is exact',
	static function () {
		Sf_Test::same( true, Password_Check::allowed( 0, 1000, 1000 ) );
		Sf_Test::same( true, Password_Check::allowed( 4, 1000, 1100 ), 'the fifth try:' );
		Sf_Test::same( false, Password_Check::allowed( 5, 1000, 1100 ), 'the sixth within the window:' );
		Sf_Test::same( true, Password_Check::allowed( 5, 1000, 1000 + Password_Check::WINDOW ), 'after fifteen minutes:' );
		Sf_Test::same( true, Password_Check::same( 'correct horse battery', 'correct horse battery' ) );
		Sf_Test::same( false, Password_Check::same( 'correct horse battery', 'correct horse batter' ), 'one character short:' );
		Sf_Test::same( false, Password_Check::same( 'correct horse battery', 'Correct horse battery' ), 'case matters:' );
		Sf_Test::same( false, Password_Check::same( 'secret', '' ) );
	}
);

exit( Sf_Test::finish( 'test-password-check' ) );
