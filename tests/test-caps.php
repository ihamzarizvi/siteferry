<?php
/**
 * Role-based access: grants are checked and completed, and every REST route is guarded by one of
 * SiteFerry's capabilities (no route left on the old administrators-only check).
 *
 * Run: php tests/test-caps.php
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Security\Caps;

if ( ! function_exists( 'is_multisite' ) ) {
	/** A single site, as on most installs (WordPress's own function in real use). */
	function is_multisite(): bool {
		return false;
	}
}

echo "Capability tests\n";

Sf_Test::run(
	'grants: known roles only, never administrator, and anything implies view',
	static function () {
		$known = array( 'administrator', 'editor', 'shop_manager' );
		$out   = Caps::normalize(
			array(
				'editor'       => array(
					'backup' => true,
					'junk'   => true,
				),
				'shop_manager' => array( 'view' => '1' ),
			),
			$known
		);
		Sf_Test::same(
			array(
				'view'     => true,
				'backup'   => true,
				'restore'  => false,
				'settings' => false,
			),
			$out['editor'],
			'backup brings view; unknown keys dropped:'
		);
		Sf_Test::same( array( true, false, false, false ), array_values( $out['shop_manager'] ) );
		Sf_Test::same( array( false, false, false, false ), array_values( Caps::normalize( array( 'editor' => 'yes' ), $known )['editor'] ), 'not a list: nothing:' );
		foreach ( array( 'administrator', 'ghost' ) as $bad ) {
			try {
				Caps::normalize( array( $bad => array( 'view' => true ) ), $known );
			} catch ( InvalidArgumentException $e ) {
				continue;
			}
			throw new RuntimeException( 'accepted role: ' . $bad );
		}
		Sf_Test::same( array( 'siteferry_view', 'siteferry_backup', 'siteferry_restore', 'siteferry_settings' ), Caps::ALL );
	}
);

Sf_Test::run(
	'administrators always hold every SiteFerry capability; others only what their role grants',
	static function () {
		$admin = Caps::admins_have_all( array( 'manage_options' => true ), array(), array(), null );
		foreach ( Caps::ALL as $cap ) {
			Sf_Test::ok( ! empty( $admin[ $cap ] ), 'admin has ' . $cap );
		}
		$editor = Caps::admins_have_all( array( 'edit_posts' => true, 'siteferry_view' => true ), array(), array(), null );
		Sf_Test::same( array( true, false ), array( ! empty( $editor['siteferry_view'] ), ! empty( $editor['siteferry_restore'] ) ), 'an editor keeps only its grant:' );
	}
);

Sf_Test::run(
	'every REST route is guarded by a SiteFerry capability, a job token or a move key',
	static function () {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/rest/class-rest.php' );
		preg_match_all( "/array\\( '(\\/[^']*)'[^;]*?WP_REST_Server::[A-Z]+, '([a-z_]+)', \\$(\\w+) \\)/", $source, $routes, PREG_SET_ORDER );
		Sf_Test::ok( count( $routes ) > 60, 'routes found: ' . count( $routes ) );
		$allowed = array( 'view', 'backup', 'restore', 'settings', 'job', 'stop', 'confirm', 'key' );
		$expect  = array(
			'start_import'   => 'restore',
			'delete_backup'  => 'restore',
			'restore_file'   => 'restore',
			'restore_post'   => 'restore',
			'backup_posts'   => 'restore',
			'delete_staging' => 'restore',
			'download'       => 'backup',
			'backup_file'    => 'backup',
			'start_export'   => 'backup',
			'save_storage'   => 'settings',
			'save_schedule'  => 'settings',
			'make_emergency_kit' => 'settings',
			'save_access'    => 'settings',
			'move_send'      => 'settings',
			'start_staging'  => 'settings',
			'backups'        => 'view',
			'activity_csv'   => 'view',
			'cancel'         => 'stop',
			'confirm'        => 'confirm',
			'move_chunk'     => 'key',
		);
		foreach ( $routes as list( , $path, $callback, $guard ) ) {
			Sf_Test::ok( in_array( $guard, $allowed, true ), $path . ' (' . $callback . ') is guarded by $' . $guard );
			if ( isset( $expect[ $callback ] ) ) {
				Sf_Test::same( $expect[ $callback ], $guard, $callback . ':' );
			}
		}
		Sf_Test::ok( false === strpos( $source, '$admin' ), 'no route on the old administrators-only check' );
	}
);

exit( Sf_Test::finish( 'test-caps' ) );
