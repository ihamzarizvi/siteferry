<?php
/**
 * The choices of "Back up now", remembered for each administrator.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * What "Back up now" makes: the parts left out, the file, where it goes. Kept in the user's meta,
 * so the choices are still there after the page is reloaded.
 *
 * A password is never kept, only whether to ask for one. The name, note and tags belong to one
 * backup and are not kept either.
 */
final class Choices {

	/** User meta key. */
	const META = 'siteferry_backup_choices';

	/** Yes/no choices with their defaults (the same as an export's). */
	const FLAGS = array(
		'exclude_media'            => false,
		'exclude_plugins'          => false,
		'exclude_themes'           => false,
		'exclude_inactive_plugins' => false,
		'exclude_inactive_themes'  => false,
		'exclude_cache'            => true,
		'exclude_spam_comments'    => false,
		'exclude_revisions'        => false,
		'compress'                 => false,
		'delete_after_send'        => false,
		'encrypt'                  => false, // Ask for a password; the password itself is never kept.
		'download'                 => false, // Also download the backup to the computer when it is ready.
		'changes_only'             => false, // Only the changes since the newest full backup on this server, when there is one.
	);

	/** At most this many left-out tables are remembered. */
	const MAX_TABLES = 1000;

	/**
	 * The choices when nothing was changed: the whole site, as a .tar file, on this server.
	 */
	public static function defaults(): array {
		return self::FLAGS + array(
			'format'         => 'tar',
			'send_to'        => '',
			'exclude_tables' => array(),
		);
	}

	/**
	 * A user's choices (the defaults when none were saved).
	 *
	 * @param int $user User id.
	 */
	public static function get( int $user ): array {
		$saved = $user > 0 ? get_user_meta( $user, self::META, true ) : array();
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Remembers a user's choices; the defaults are not stored at all.
	 *
	 * @param int   $user    User id.
	 * @param array $choices Choices as sent by the admin page (unknown keys are dropped).
	 * @return array The choices as kept.
	 */
	public static function save( int $user, array $choices ): array {
		$clean = self::clean( $choices );
		if ( $user > 0 ) {
			if ( self::defaults() === $clean ) {
				delete_user_meta( $user, self::META );
			} else {
				update_user_meta( $user, self::META, $clean );
			}
		}
		return $clean;
	}

	/**
	 * Valid choices from anything: known keys only, the right types, and no combination an export
	 * would refuse or ignore (a .wpress file is neither compressed nor protected by SiteFerry;
	 * deleting the copy here only applies when the backup is sent somewhere).
	 *
	 * @param array $raw Choices.
	 */
	public static function clean( array $raw ): array {
		$out = self::defaults();
		foreach ( array_keys( self::FLAGS ) as $key ) {
			if ( array_key_exists( $key, $raw ) ) {
				$out[ $key ] = self::yes( $raw[ $key ] );
			}
		}
		if ( isset( $raw['format'] ) && in_array( $raw['format'], array( 'tar', 'wpress' ), true ) ) {
			$out['format'] = $raw['format'];
		}
		if ( isset( $raw['send_to'] ) && is_string( $raw['send_to'] ) && preg_match( '/^[a-z0-9]{1,20}$/', $raw['send_to'] ) ) {
			$out['send_to'] = $raw['send_to'];
		}
		if ( isset( $raw['exclude_tables'] ) && is_array( $raw['exclude_tables'] ) ) {
			$seen = array();
			foreach ( $raw['exclude_tables'] as $table ) {
				// A table name: 1 to 64 characters, none of them control characters.
				if ( is_string( $table ) && preg_match( '/^[^\x00-\x1f\x7f]{1,64}$/u', $table ) && ! isset( $seen[ 't' . $table ] ) ) {
					$seen[ 't' . $table ]    = true;
					$out['exclude_tables'][] = $table;
				}
			}
			$out['exclude_tables'] = array_slice( $out['exclude_tables'], 0, self::MAX_TABLES );
		}
		if ( 'wpress' === $out['format'] ) {
			$out['compress']     = false;
			$out['encrypt']      = false;
			$out['changes_only'] = false;
		}
		if ( $out['encrypt'] ) {
			$out['changes_only'] = false; // Changes-only backups are not protected with a password (yet).
		}
		if ( '' === $out['send_to'] ) {
			$out['delete_after_send'] = false;
		}
		return $out;
	}

	/**
	 * Whether a value means yes (JSON true, or 1 from a form).
	 *
	 * @param mixed $value Value.
	 */
	private static function yes( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
