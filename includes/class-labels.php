<?php
/**
 * Names, notes and tags of backups.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

defined( 'ABSPATH' ) || exit;

/**
 * A display name, a note and tags for each backup, keyed by file name, so they apply to every
 * copy of a backup (this server and each storage). The file names themselves never change.
 *
 * Kept in one option, not autoloaded. Like all "siteferry_" options it is left out of exports,
 * kept when a backup is imported into this site and removed on uninstall. Labels given when a
 * backup is made are also in its manifest, so they travel with the file.
 */
final class Labels {

	const OPTION = 'siteferry_backup_labels';

	const MAX_TITLE = 100;
	const MAX_NOTE  = 1000;
	const MAX_TAGS  = 10;
	const MAX_TAG   = 30;

	/**
	 * Every label: file name => [title, note, tags].
	 *
	 * @return array<string,array{title:string,note:string,tags:string[]}>
	 */
	public static function all(): array {
		$labels = get_option( self::OPTION, array() );
		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * The label of one backup, or null.
	 *
	 * @param string $name File name.
	 */
	public static function get( string $name ): ?array {
		$all = self::all();
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	/**
	 * Sets (or, when everything is empty, removes) the label of a backup.
	 *
	 * @param string $name  File name.
	 * @param mixed  $title Display name.
	 * @param mixed  $note  Note.
	 * @param mixed  $tags  Tags: a list, or one comma-separated string.
	 * @return array|null The stored label, or null when removed.
	 * @throws Jobs\Job_Exception On a name that is not a backup file name.
	 */
	public static function set( string $name, $title, $note, $tags ): ?array {
		if ( strlen( $name ) > 200 || ! preg_match( Backups::SAFE_NAME, $name ) ) {
			throw new Jobs\Job_Exception( 'Unknown backup.', Jobs\Job_Exception::NOT_FOUND );
		}
		$label = self::normalize( $title, $note, $tags );
		// Read again: a long-running process (WP-CLI, a job) may hold a copy older than another request's change.
		wp_cache_delete( self::OPTION, 'options' );
		$all = self::all();
		if ( '' === $label['title'] && '' === $label['note'] && array() === $label['tags'] ) {
			unset( $all[ $name ] );
			$label = null;
		} else {
			$all[ $name ] = $label;
		}
		update_option( self::OPTION, $all, false );
		return $label;
	}

	/**
	 * Moves a label to a new file name (when an export turns its TAR into a .wpress file).
	 *
	 * @param string $from Old name.
	 * @param string $to   New name.
	 */
	public static function rename( string $from, string $to ): void {
		$all = self::all();
		if ( isset( $all[ $from ] ) ) {
			$all[ $to ] = $all[ $from ];
			unset( $all[ $from ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Cleans up a label: trimmed, single-line name, limited lengths, tags without duplicates.
	 *
	 * @param mixed $title Display name.
	 * @param mixed $note  Note.
	 * @param mixed $tags  Tags: a list, or one comma-separated string.
	 * @return array{title:string,note:string,tags:string[]}
	 */
	public static function normalize( $title, $note, $tags ): array {
		$title = trim( (string) preg_replace( '/\s+/u', ' ', self::text( $title ) ) );
		$note  = trim( str_replace( "\r\n", "\n", self::text( $note ) ) );
		return array(
			'title' => self::cut( $title, self::MAX_TITLE ),
			'note'  => self::cut( $note, self::MAX_NOTE ),
			'tags'  => self::tags( $tags ),
		);
	}

	/**
	 * Tags: letters, digits, spaces, "-" and "_"; at most MAX_TAGS, each at most MAX_TAG long;
	 * duplicates (ignoring case) dropped.
	 *
	 * @param mixed $tags A list, or one comma-separated string.
	 * @return string[]
	 */
	public static function tags( $tags ): array {
		$list = is_array( $tags ) ? $tags : explode( ',', self::text( $tags ) );
		$out  = array();
		$seen = array();
		foreach ( $list as $tag ) {
			$tag = trim( (string) preg_replace( '/[^\p{L}\p{N} _-]+/u', '', (string) preg_replace( '/\s+/u', ' ', self::text( $tag ) ) ) );
			$tag = self::cut( $tag, self::MAX_TAG );
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tag, 'UTF-8' ) : strtolower( $tag );
			if ( '' === $tag || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tag;
			if ( count( $out ) >= self::MAX_TAGS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A scalar as valid UTF-8 text without control characters (newlines and tabs kept).
	 *
	 * @param mixed $value Value.
	 */
	private static function text( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$text = wp_check_invalid_utf8( $text, true );
		}
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	}

	/**
	 * At most $length characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters.
	 */
	private static function cut( string $text, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length, 'UTF-8' ) : substr( $text, 0, $length );
	}
}
