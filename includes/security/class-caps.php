<?php
/**
 * Who may do what in SiteFerry.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Security;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * SiteFerry's own capabilities, so a site can let other roles use parts of it:
 *
 * - siteferry_view: see backups, their contents and the activity;
 * - siteferry_backup: make, download, check and send backups;
 * - siteferry_restore: restore (whole, one file or one post), delete backups, delete the staging
 *   copy — this can replace the whole site;
 * - siteferry_settings: storages, schedule, emergency kits, staging copies, moving the site, and
 *   who may use SiteFerry.
 *
 * Administrators (manage_options) always have all four, whatever is set on roles. On a network,
 * only network administrators on the main site use SiteFerry, as before: roles are not used there.
 * WP-CLI runs with server access and is not limited.
 */
final class Caps {

	const VIEW     = 'siteferry_view';
	const BACKUP   = 'siteferry_backup';
	const RESTORE  = 'siteferry_restore';
	const SETTINGS = 'siteferry_settings';

	const ALL = array( self::VIEW, self::BACKUP, self::RESTORE, self::SETTINGS );

	/** Option holding the version that last granted the capabilities to administrators. */
	const VERSION_OPTION = 'siteferry_caps_version';

	/**
	 * Hooks the administrator fallback and the one-time grant.
	 */
	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'admins_have_all' ), 10, 4 );
		add_action( 'admin_init', array( self::class, 'upgrade' ) );
	}

	/**
	 * Administrators always hold every SiteFerry capability, even if roles were edited.
	 *
	 * @param array $allcaps Capabilities the user has.
	 * @param array $caps    Capabilities asked for (after mapping).
	 * @param array $args    Arguments of the check.
	 * @param mixed $user    The user.
	 */
	public static function admins_have_all( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args, $user );
		$admin = is_multisite() ? ! empty( $allcaps['manage_network_options'] ) : ! empty( $allcaps['manage_options'] );
		if ( $admin ) {
			foreach ( self::ALL as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Gives the administrator role the capabilities once per version (so they show in role
	 * editors too).
	 */
	public static function upgrade(): void {
		if ( SITEFERRY_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::ALL as $cap ) {
				$role->add_cap( $cap );
			}
		}
		update_option( self::VERSION_OPTION, SITEFERRY_VERSION, false );
	}

	/**
	 * Whether the current user may do something. On a network: network administrators on the main
	 * site only.
	 *
	 * @param string $cap One of the constants.
	 */
	public static function can( string $cap ): bool {
		if ( is_multisite() ) {
			return is_main_site() && current_user_can( 'manage_network_options' );
		}
		return in_array( $cap, self::ALL, true ) && current_user_can( $cap );
	}

	/**
	 * Whether the current user may use any part of SiteFerry.
	 */
	public static function any(): bool {
		foreach ( self::ALL as $cap ) {
			if ( self::can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the current user may do, for the admin page.
	 *
	 * @return array<string,bool> view, backup, restore, settings.
	 */
	public static function mine(): array {
		return array(
			'view'     => self::can( self::VIEW ),
			'backup'   => self::can( self::BACKUP ),
			'restore'  => self::can( self::RESTORE ),
			'settings' => self::can( self::SETTINGS ),
		);
	}

	/**
	 * Roles other than administrator and what SiteFerry lets each do.
	 *
	 * @return array<int,array{role:string,name:string,caps:array<string,bool>}>
	 */
	public static function roles(): array {
		$out = array();
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$caps = array();
			foreach ( self::ALL as $cap ) {
				$caps[ self::short( $cap ) ] = $role->has_cap( $cap );
			}
			$out[] = array(
				'role' => (string) $slug,
				'name' => translate_user_role( (string) wp_roles()->role_names[ $slug ] ),
				'caps' => $caps,
			);
		}
		return $out;
	}

	/**
	 * Sets what roles may do. Unknown roles and administrator are refused; a capability that needs
	 * another (restore and settings need view) brings it along.
	 *
	 * @param array $grants role => [view, backup, restore, settings] booleans.
	 * @throws \InvalidArgumentException On an unknown role.
	 */
	public static function set( array $grants ): void {
		$normalized = self::normalize( $grants, array_keys( wp_roles()->role_objects ) );
		foreach ( $normalized as $slug => $caps ) {
			$role = get_role( $slug );
			foreach ( $caps as $short => $on ) {
				if ( $on ) {
					$role->add_cap( self::long( $short ) );
				} else {
					$role->remove_cap( self::long( $short ) );
				}
			}
		}
	}

	/**
	 * Checks and completes grants: known roles only (never administrator), the four keys, and
	 * view whenever anything else is given.
	 *
	 * @param array    $grants role => caps.
	 * @param string[] $known  Role slugs that exist.
	 * @return array<string,array<string,bool>>
	 * @throws \InvalidArgumentException On an unknown role or administrator.
	 */
	public static function normalize( array $grants, array $known ): array {
		$out = array();
		foreach ( $grants as $slug => $caps ) {
			$slug = (string) $slug;
			if ( 'administrator' === $slug || ! in_array( $slug, $known, true ) ) {
				throw new \InvalidArgumentException( 'Unknown role: ' . $slug );
			}
			$set = array();
			foreach ( array( 'view', 'backup', 'restore', 'settings' ) as $short ) {
				$set[ $short ] = is_array( $caps ) && ! empty( $caps[ $short ] );
			}
			if ( $set['backup'] || $set['restore'] || $set['settings'] ) {
				$set['view'] = true; // Every action happens on the SiteFerry page, which needs view.
			}
			$out[ $slug ] = $set;
		}
		return $out;
	}

	/**
	 * Removes SiteFerry's capabilities from every role (on uninstall).
	 */
	public static function remove_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::ALL as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * "siteferry_view" → "view".
	 *
	 * @param string $cap Capability.
	 */
	private static function short( string $cap ): string {
		return substr( $cap, strlen( 'siteferry_' ) );
	}

	/**
	 * "view" → "siteferry_view".
	 *
	 * @param string $short Short name.
	 */
	private static function long( string $short ): string {
		return 'siteferry_' . $short;
	}
}
