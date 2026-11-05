<?php
/**
 * Bootstrap for the stand-alone CLI tests (no WordPress needed).
 *
 * Every PHP notice, warning or deprecation fails the test that caused it.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

set_error_handler(
	static function ( int $severity, string $message, string $file, int $line ): bool {
		if ( 0 === ( error_reporting() & $severity ) ) {
			return false;
		}
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

// Plugin files only check that ABSPATH is defined; the archive code does not use WordPress.
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/class-autoloader.php';
require __DIR__ . '/lib/i18n-stub.php';
SiteFerry\Autoloader::register();

require __DIR__ . '/lib/class-sf-test.php';
require __DIR__ . '/lib/fixtures.php';
