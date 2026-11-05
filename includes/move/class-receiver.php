<?php
/**
 * Site-to-site move, the receiving site: a one-time key, and an upload that only that key opens.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Move;

use SiteFerry\Archive\Crypto;
use SiteFerry\Backups;
use SiteFerry\Import\Upload;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Labels;
use SiteFerry\Security\Secrets;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * An administrator of the new site makes a key ("Receive a site"); pasted on the old site, it lets
 * that site send one backup straight here. The key holds this site's address, a key id and a
 * 256-bit secret. The sending site encrypts the backup with the secret as its password, so it
 * crosses the network unreadable (also over plain http), and sends it in checked chunks to routes
 * that answer only to the key. The key works once and for 24 hours; wrong keys are counted and
 * the key is dropped after too many. The backup then waits in the list: restoring it is still
 * the administrator's decision, and it opens with the kept secret, without asking for a password.
 */
final class Receiver {

	/** The key and what arrived with it: id, secret (encrypted), expires, upload, received. */
	const OPTION = 'siteferry_receive';

	/** Backups that arrived with a key: name => { password (encrypted), from, time }. */
	const RECEIVED = 'siteferry_received';

	/** Hours a key is valid. */
	const HOURS = 24;

	/** Wrong keys before the key is dropped. */
	const MAX_FAILURES = 20;

	/** Key prefix: format version 1. */
	const PREFIX = 'SFMOVE1.';

	/**
	 * Makes a new key (any earlier one stops working).
	 *
	 * @param int $hours Hours it is valid.
	 * @return array{key:string,expires:int}
	 */
	public static function create( int $hours = self::HOURS ): array {
		self::cancel();
		$id     = Storage::random_hex( 8 );
		$secret = self::base64url( random_bytes( 32 ) );
		$state  = array(
			'id'       => $id,
			'secret'   => Secrets::site()->encrypt( $secret ),
			'created'  => time(),
			'expires'  => time() + max( 1, min( 168, $hours ) ) * HOUR_IN_SECONDS,
			'upload'   => '',
			'received' => null,
			'failures' => 0,
		);
		update_option( self::OPTION, $state, false );
		return array(
			'key'     => self::PREFIX . self::base64url(
				(string) wp_json_encode(
					array(
						'u' => home_url(),
						'i' => $id,
						's' => $secret,
					)
				)
			),
			'expires' => $state['expires'],
		);
	}

	/**
	 * What the administrator sees: whether a key waits, how far a backup has arrived, what arrived.
	 */
	public static function status(): array {
		$state = self::state();
		if ( null === $state ) {
			return array( 'state' => 'none' );
		}
		$out = array(
			'state'    => 'waiting',
			'expires'  => (int) $state['expires'],
			'received' => null,
			'progress' => null,
		);
		if ( is_array( $state['received'] ) ) {
			$out['state']    = 'received';
			$out['received'] = array_intersect_key( $state['received'], array_flip( array( 'name', 'from', 'time', 'size' ) ) );
			return $out;
		}
		if ( time() > (int) $state['expires'] ) {
			$out['state'] = 'expired';
			return $out;
		}
		$upload = self::upload( $state );
		if ( null !== $upload ) {
			$out['state']    = 'receiving';
			$out['progress'] = $upload->to_array();
		}
		return $out;
	}

	/**
	 * Drops the key and anything half-received.
	 */
	public static function cancel(): void {
		$state = self::state();
		if ( null !== $state ) {
			$upload = self::upload( $state );
			if ( null !== $upload ) {
				$upload->delete();
			}
		}
		delete_option( self::OPTION );
	}

	/**
	 * Whether a request carries the valid key (header "X-SiteFerry-Move: <id>.<secret>").
	 * Counts wrong secrets for the current key and drops it after too many.
	 *
	 * @param string $header Header value.
	 */
	public static function accepts( string $header ): bool {
		$state = self::state();
		if ( null === $state || is_array( $state['received'] ) || time() > (int) $state['expires'] ) {
			return false;
		}
		$parts = explode( '.', $header, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( (string) $state['id'], $parts[0] ) ) {
			return false;
		}
		try {
			$secret = Secrets::site()->decrypt( (string) $state['secret'] );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( hash_equals( $secret, $parts[1] ) ) {
			return true;
		}
		++$state['failures'];
		if ( $state['failures'] >= self::MAX_FAILURES ) {
			self::cancel(); // Someone is guessing: the key is gone.
		} else {
			update_option( self::OPTION, $state, false );
		}
		return false;
	}

	/**
	 * About this site, for the sending site to show before it starts.
	 */
	public static function hello(): array {
		$free = function_exists( 'disk_free_space' ) ? \SiteFerry\Archive\Io::quiet(
			static function () {
				return disk_free_space( Storage::root() );
			}
		) : false;
		return array(
			'site'      => home_url(),
			'name'      => (string) get_option( 'blogname' ),
			'wordpress' => (string) get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'siteferry' => SITEFERRY_VERSION,
			'free'      => false === $free ? null : (int) $free,
			'chunk'     => \SiteFerry\Rest\Rest::chunk_size(),
			'expires'   => (int) self::state()['expires'],
		);
	}

	/**
	 * Starts receiving (or tells where an interrupted transfer stopped).
	 *
	 * @param string $name Backup name (an encrypted SiteFerry backup).
	 * @param int    $size Bytes.
	 * @param string $from The sending site's address.
	 * @throws Job_Exception If the name is not an encrypted backup's.
	 */
	public static function start( string $name, int $size, string $from ): array {
		if ( ! preg_match( Backups::NAME_PATTERN, $name ) || ! Backups::encrypted_name( $name ) ) {
			throw new Job_Exception( 'Only an encrypted SiteFerry backup can be received this way.', Job_Exception::STEP );
		}
		$state  = self::state();
		$upload = self::upload( $state );
		if ( null !== $upload ) {
			$current = $upload->to_array();
			if ( $current['name'] === $name && $current['size'] === $size ) {
				return array( 'upload' => $current ) + self::hello();
			}
			$upload->delete();
		}
		$upload          = Upload::create( self::uploads_dir(), $name, $size );
		$state['upload'] = $upload->to_array()['id'];
		$state['from']   = esc_url_raw( $from );
		update_option( self::OPTION, $state, false );
		return array( 'upload' => $upload->to_array() ) + self::hello();
	}

	/**
	 * Receives one chunk.
	 *
	 * @param int    $offset Where it starts.
	 * @param string $bytes  Data.
	 * @param string $sha256 Its SHA-256.
	 * @throws Job_Exception If no transfer was started.
	 */
	public static function chunk( int $offset, string $bytes, string $sha256 ): array {
		$upload = self::upload( self::state() );
		if ( null === $upload ) {
			throw new Job_Exception( 'No transfer was started with this key.', Job_Exception::NOT_FOUND );
		}
		ignore_user_abort( true );
		$upload->append( $offset, $bytes, $sha256 );
		return $upload->to_array();
	}

	/**
	 * Takes the complete backup into the list; checks that the key's secret opens it.
	 *
	 * @throws Job_Exception If nothing complete arrived or it does not open with the key.
	 */
	public static function finish(): array {
		$state  = self::state();
		$upload = self::upload( $state );
		if ( null === $upload ) {
			throw new Job_Exception( 'No transfer was started with this key.', Job_Exception::NOT_FOUND );
		}
		$name   = $upload->finish( Storage::backups_dir() );
		$secret = Secrets::site()->decrypt( (string) $state['secret'] );
		$path   = Storage::backups_dir() . '/' . $name;
		try {
			Crypto::unlock( $path, $secret );
		} catch ( \Throwable $e ) {
			\SiteFerry\Archive\Io::quiet(
				static function () use ( $path ) {
					return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
			throw new Job_Exception( 'The backup that arrived does not open with this key.', Job_Exception::STEP );
		}
		$from              = isset( $state['from'] ) ? (string) $state['from'] : '';
		$state['upload']   = '';
		$state['received'] = array(
			'name' => $name,
			'from' => $from,
			'time' => time(),
			'size' => (int) filesize( $path ),
		);
		update_option( self::OPTION, $state, false );
		// Its password (the key's secret) stays until the backup is deleted, also when a new key is made.
		$received          = (array) get_option( self::RECEIVED, array() );
		$received[ $name ] = array(
			'password' => (string) $state['secret'],
			'from'     => $from,
			'time'     => time(),
		);
		update_option( self::RECEIVED, $received, false );
		$host = (string) wp_parse_url( $from, PHP_URL_HOST );
		Labels::set( $name, 'Moved from ' . ( '' === $host ? 'another site' : $host ), '', array( 'moved' ) );
		return array( 'backup' => $name );
	}

	/**
	 * The password of a backup that arrived with the key (the key's secret), or null.
	 *
	 * @param string $name Backup name.
	 */
	public static function password_for( string $name ): ?string {
		$received = (array) get_option( self::RECEIVED, array() );
		if ( ! isset( $received[ $name ]['password'] ) ) {
			return null;
		}
		try {
			return Secrets::site()->decrypt( (string) $received[ $name ]['password'] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Forgets a backup's kept password (and the key that brought it) once it is deleted.
	 *
	 * @param string $name Deleted backup.
	 */
	public static function forget_backup( string $name ): void {
		$received = (array) get_option( self::RECEIVED, array() );
		if ( isset( $received[ $name ] ) ) {
			unset( $received[ $name ] );
			update_option( self::RECEIVED, $received, false );
		}
		$state = self::state();
		if ( null !== $state && is_array( $state['received'] ) && $state['received']['name'] === $name ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * The saved key, or null.
	 */
	private static function state(): ?array {
		$state = get_option( self::OPTION );
		return is_array( $state ) && isset( $state['id'], $state['secret'], $state['expires'] ) ? $state : null;
	}

	/**
	 * The transfer in progress, or null.
	 *
	 * @param array|null $state Key state.
	 */
	private static function upload( ?array $state ): ?Upload {
		if ( null === $state || '' === (string) $state['upload'] ) {
			return null;
		}
		try {
			return Upload::load( self::uploads_dir(), (string) $state['upload'] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Where transfers are stored while they arrive (the upload folder).
	 */
	private static function uploads_dir(): string {
		return Storage::ensure( Storage::root() . '/uploads' );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
