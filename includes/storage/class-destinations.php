<?php
/**
 * Saved storage destinations.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Secret_Exception;
use SiteFerry\Security\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Settings of each storage destination, in the option siteferry_destinations (not autoloaded):
 * id => {driver, settings, secrets (encrypted), auth, updated}.
 *
 * "auth" exists for OAuth storages once they are connected: the driver's token fields encrypted,
 * the rest (account, folder id, expiry) as they are, and a hash of the client ID it belongs to.
 * Saving the settings with another client ID drops it.
 *
 * Like every siteferry_ option it is left out of exports and kept on the destination site during
 * an import, and uninstall.php removes it. Secrets and tokens are never sent back to the browser.
 */
final class Destinations {

	const OPTION = 'siteferry_destinations';

	/** Destination id => driver class. */
	const DRIVERS = array(
		's3'      => S3_Driver::class,
		'ftp'     => Ftp_Driver::class,
		'gdrive'  => Gdrive_Driver::class,
		'dropbox' => Dropbox_Driver::class,
	);

	/** Destination id => [name, short name]. */
	const LABELS = array(
		's3'      => array( 'S3-compatible storage', 'S3' ),
		'ftp'     => array( 'FTP server', 'FTP' ),
		'gdrive'  => array( 'Google Drive', 'Google Drive' ),
		'dropbox' => array( 'Dropbox', 'Dropbox' ),
	);

	/**
	 * Every destination for the admin screen: settings without secrets, which secrets are set,
	 * whether it is ready, and driver information.
	 *
	 * @return array<int,array>
	 */
	public static function overview(): array {
		$stored = self::stored();
		$list   = array();
		foreach ( self::DRIVERS as $id => $class ) {
			$saved = isset( $stored[ $id ] ) ? $stored[ $id ] : null;
			$set   = array();
			foreach ( array_keys( $class::secrets() ) as $name ) {
				$set[ $name ] = null !== $saved && isset( $saved['secrets'][ $name ] );
			}
			$oauth     = self::is_oauth( $class );
			$connected = $oauth && null !== $saved && ! empty( $saved['auth'] );
			$entry     = array(
				'id'          => $id,
				'label'       => self::LABELS[ $id ][0],
				'short'       => self::LABELS[ $id ][1],
				'configured'  => null !== $saved,
				'ready'       => null !== $saved && ( ! $oauth || $connected ),
				'settings'    => null === $saved ? null : $saved['settings'],
				'secrets_set' => $set,
				'description' => null === $saved ? null : self::driver( $id, new Null_Transport() )->describe(),
				'updated'     => null === $saved ? null : (int) $saved['updated'],
				// The last connection test with these settings: ok, time and message; null if none.
				'tested'      => null !== $saved && isset( $saved['tested'] ) ? $saved['tested'] : null,
				'info'        => $class::info(),
			);
			if ( $oauth ) {
				$entry['oauth'] = array(
					'connected'    => $connected,
					'account'      => $connected && isset( $saved['auth']['account'] ) ? (string) $saved['auth']['account'] : '',
					'redirect_uri' => self::redirect_uri( $id ),
				);
			}
			$list[] = $entry;
		}
		return $list;
	}

	/**
	 * Whether a destination has saved settings.
	 *
	 * @param string $id Destination id.
	 */
	public static function configured( string $id ): bool {
		return isset( self::stored()[ $id ] );
	}

	/**
	 * Whether a destination can take backups (set up and, for OAuth storages, connected).
	 *
	 * @param string $id Destination id.
	 */
	public static function ready( string $id ): bool {
		$stored = self::stored();
		if ( ! isset( $stored[ $id ], self::DRIVERS[ $id ] ) ) {
			return false;
		}
		return ! self::is_oauth( self::DRIVERS[ $id ] ) || ! empty( $stored[ $id ]['auth'] );
	}

	/**
	 * Throws unless a destination is ready.
	 *
	 * @param string $id Destination id.
	 * @throws Job_Exception If it is not set up or not connected.
	 */
	public static function require_ready( string $id ): void {
		self::driver_class( $id );
		if ( ! self::configured( $id ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a storage, such as Dropbox */ __( '%s is not set up yet (Storage tab).', 'siteferry' ), self::LABELS[ $id ][0] ), Job_Exception::NOT_FOUND );
		}
		if ( ! self::ready( $id ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a storage, such as Dropbox */ __( '%s is not connected yet: click "Connect" on the Storage tab.', 'siteferry' ), self::LABELS[ $id ][0] ), Job_Exception::STEP );
		}
	}

	/**
	 * Validates and saves a destination's settings. Secrets left empty keep their saved value.
	 * An OAuth connection is kept unless the client ID changes.
	 *
	 * @param string $id    Destination id.
	 * @param array  $input Raw input.
	 * @throws Storage_Exception On invalid input.
	 * @throws Job_Exception For unknown ids.
	 */
	public static function save( string $id, array $input ): void {
		$class    = self::driver_class( $id );
		$stored   = self::stored();
		$old      = isset( $stored[ $id ] ) ? $stored[ $id ] : array();
		$previous = isset( $old['secrets'] ) ? (array) $old['secrets'] : array();
		$normal   = $class::normalize( $input );
		// Backups larger than this are stored in numbered parts (0: whole files).
		$normal['settings']['split_mb'] = Parts::normalize_mb( isset( $input['split_mb'] ) ? $input['split_mb'] : 0 );

		$secrets = array();
		foreach ( $class::secrets() as $name => $label ) {
			if ( ! array_key_exists( $name, $normal['secrets'] ) ) {
				continue; // Not used with these settings (Google Drive through the sign-in service needs no secret).
			}
			$value = $normal['secrets'][ $name ];
			if ( null !== $value ) {
				$secrets[ $name ] = self::encrypt( $value );
			} elseif ( isset( $previous[ $name ] ) ) {
				$secrets[ $name ] = $previous[ $name ];
			} else {
				throw new Storage_Exception( sprintf( /* translators: %s: a field name, such as "host name" */ __( 'Enter the %s.', 'siteferry' ), $label ), Storage_Exception::CONFIG );
			}
		}

		$entry = array(
			'driver'   => $id,
			'settings' => $normal['settings'],
			'secrets'  => $secrets,
			'updated'  => time(),
		);
		if ( ! empty( $old['auth'] ) && hash_equals( (string) $old['auth']['client'], self::client_key( $class, $normal['settings'] ) ) ) {
			$entry['auth'] = $old['auth'];
		}
		$stored[ $id ] = $entry;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Largest part a backup is stored in on a destination, in bytes (0: whole files).
	 *
	 * @param string $id Destination id.
	 */
	public static function split( string $id ): int {
		$stored = self::stored();
		$mb     = isset( $stored[ $id ]['settings']['split_mb'] ) ? (int) $stored[ $id ]['settings']['split_mb'] : 0;
		return $mb > 0 ? $mb * 1048576 : 0;
	}

	/**
	 * Removes a destination's settings, secrets and tokens (an OAuth connection is revoked first,
	 * as far as the provider can be reached).
	 *
	 * @param string $id Destination id.
	 */
	public static function remove( string $id ): void {
		self::driver_class( $id );
		if ( self::ready( $id ) && self::is_oauth( self::DRIVERS[ $id ] ) ) {
			try {
				self::disconnect( $id );
			} catch ( \Throwable $e ) {
				unset( $e ); // The settings go regardless.
			}
		}
		$stored = self::stored();
		unset( $stored[ $id ] );
		if ( array() === $stored ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $stored, false );
		}
	}

	/**
	 * Starts connecting an OAuth storage: a one-time state, and the provider's consent URL.
	 *
	 * @param string $id Destination id.
	 * @return array{url:string,redirect_uri:string}
	 * @throws Job_Exception If the storage is not set up or does not use OAuth.
	 */
	public static function authorize( string $id ): array {
		$driver = self::oauth_driver( $id );
		$start  = Oauth_State::start( $id );
		return array(
			'url'          => $driver->authorization_url( self::redirect_uri( $id ), $start['state'], $start['challenge'] ),
			'redirect_uri' => self::redirect_uri( $id ),
		);
	}

	/**
	 * Finishes connecting: checks the state, exchanges the code and saves the tokens.
	 *
	 * @param string $id    Destination id.
	 * @param string $code  Authorization code.
	 * @param string $state State from the provider's answer.
	 * @throws Job_Exception If the state is not valid.
	 * @throws Storage_Exception If the provider refuses.
	 */
	public static function connect( string $id, string $code, string $state ): void {
		$verifier = Oauth_State::finish( $id, $state );
		if ( '' === $code || strlen( $code ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $code ) ) {
			throw new Job_Exception( __( 'The answer from the provider has no valid authorization code.', 'siteferry' ), Job_Exception::STEP );
		}
		$before = self::stored();
		$was    = isset( $before[ $id ]['auth']['account'] ) ? (string) $before[ $id ]['auth']['account'] : '';
		$driver = self::oauth_driver( $id );
		$auth   = $driver->connect( $code, $verifier, self::redirect_uri( $id ) );
		self::save_auth( $id, $auth );
		self::record_test( $id, null ); // A new connection has not been tested yet.

		// Another account cannot see a folder chosen in the old one: back to the top of its Drive.
		$now = isset( $auth['account'] ) ? (string) $auth['account'] : '';
		if ( '' !== $was && '' !== $now && strtolower( $was ) !== strtolower( $now ) ) {
			$stored = self::stored();
			if ( ! empty( $stored[ $id ]['settings']['parent_id'] ) ) {
				$stored[ $id ]['settings']['parent_id']   = '';
				$stored[ $id ]['settings']['parent_path'] = '';
				update_option( self::OPTION, $stored, false );
			}
		}
	}

	/**
	 * Revokes an OAuth connection and forgets its tokens; the settings stay.
	 *
	 * @param string $id Destination id.
	 */
	public static function disconnect( string $id ): void {
		$driver = self::oauth_driver( $id );
		$driver->disconnect();
		$stored = self::stored();
		unset( $stored[ $id ]['auth'], $stored[ $id ]['tested'] );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Remembers how the last connection test of a destination went (null forgets it). Saving new
	 * settings replaces the whole entry, so a result never outlives the settings it was made with.
	 *
	 * @param string      $id      Destination id.
	 * @param bool|null   $ok      Outcome, or null to forget it.
	 * @param string      $message The error when it failed.
	 */
	public static function record_test( string $id, ?bool $ok, string $message = '' ): void {
		$stored = self::stored();
		if ( ! isset( $stored[ $id ] ) ) {
			return;
		}
		if ( null === $ok ) {
			unset( $stored[ $id ]['tested'] );
		} else {
			$stored[ $id ]['tested'] = array(
				'ok'      => $ok,
				'time'    => time(),
				'message' => $ok ? '' : substr( $message, 0, 500 ),
			);
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Saves a changed auth record (tokens encrypted).
	 *
	 * @param string $id   Destination id.
	 * @param array  $auth Auth record (plain).
	 */
	public static function save_auth( string $id, array $auth ): void {
		$class  = self::driver_class( $id );
		$stored = self::stored();
		if ( ! isset( $stored[ $id ] ) || ! self::is_oauth( $class ) ) {
			return;
		}
		$saved = array();
		foreach ( $auth as $key => $value ) {
			$saved[ $key ] = in_array( $key, $class::token_fields(), true ) && '' !== (string) $value ? self::encrypt( (string) $value ) : $value;
		}
		$saved['client']      = self::client_key( $class, (array) $stored[ $id ]['settings'] );
		$stored[ $id ]['auth'] = $saved;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * A ready driver for a destination.
	 *
	 * @param string         $id        Destination id.
	 * @param Transport|null $transport HTTP (default: WordPress).
	 * @throws Job_Exception If the destination is not set up.
	 * @throws Storage_Exception If a saved secret cannot be decrypted.
	 */
	public static function driver( string $id, ?Transport $transport = null ): Driver {
		$class  = self::driver_class( $id );
		$stored = self::stored();
		if ( ! isset( $stored[ $id ] ) ) {
			throw new Job_Exception( sprintf( /* translators: %s: a storage, such as Dropbox */ __( '%s is not set up yet (Storage tab).', 'siteferry' ), self::LABELS[ $id ][0] ), Job_Exception::NOT_FOUND );
		}
		$describe_only = $transport instanceof Null_Transport;
		$secrets       = array();
		if ( ! $describe_only ) {
			foreach ( (array) $stored[ $id ]['secrets'] as $name => $value ) {
				$secrets[ $name ] = self::decrypt( (string) $value );
			}
		}
		$driver = $class::create( $stored[ $id ]['settings'], $secrets, null === $transport ? new Wp_Transport() : $transport );
		if ( $driver instanceof Oauth_Driver ) {
			$auth = isset( $stored[ $id ]['auth'] ) ? (array) $stored[ $id ]['auth'] : array();
			unset( $auth['client'] );
			foreach ( $class::token_fields() as $field ) {
				if ( isset( $auth[ $field ] ) && '' !== (string) $auth[ $field ] ) {
					$auth[ $field ] = $describe_only ? 'x' : self::decrypt( (string) $auth[ $field ] );
				}
			}
			$driver->use_auth(
				$auth,
				$describe_only ? null : static function ( array $changed ) use ( $id ) {
					self::save_auth( $id, $changed );
				}
			);
		}
		return $driver;
	}

	/**
	 * Where the provider sends the administrator back: the SiteFerry page, which hands the answer
	 * to the REST API with the administrator's nonce (nothing is saved from the GET itself).
	 *
	 * @param string $id Destination id.
	 */
	public static function redirect_uri( string $id ): string {
		return admin_url( 'admin.php?page=siteferry&oauth=' . rawurlencode( $id ) );
	}

	/**
	 * Short name of a destination ("S3").
	 *
	 * @param string $id Destination id.
	 */
	public static function short_label( string $id ): string {
		return isset( self::LABELS[ $id ] ) ? self::LABELS[ $id ][1] : $id;
	}

	/**
	 * The driver of an OAuth destination.
	 *
	 * @param string $id Destination id.
	 * @throws Job_Exception If it is not set up or does not use OAuth.
	 */
	private static function oauth_driver( string $id ): Oauth_Driver {
		$driver = self::driver( $id );
		if ( ! $driver instanceof Oauth_Driver ) {
			throw new Job_Exception( __( 'This storage does not use a sign-in.', 'siteferry' ), Job_Exception::STEP );
		}
		return $driver;
	}

	/**
	 * Hash naming the OAuth client a connection belongs to; saving settings for another client
	 * drops the connection.
	 *
	 * @param string $class    Driver class.
	 * @param array  $settings Settings.
	 */
	private static function client_key( string $class, array $settings ): string {
		$key = method_exists( $class, 'client_key' ) ? $class::client_key( $settings ) : ( isset( $settings['client_id'] ) ? (string) $settings['client_id'] : '' );
		return hash( 'sha256', $key );
	}

	/**
	 * Whether a driver class signs in with OAuth.
	 *
	 * @param string $class Driver class.
	 */
	private static function is_oauth( string $class ): bool {
		return is_subclass_of( $class, Oauth_Driver::class );
	}

	/**
	 * Driver class for an id.
	 *
	 * @param string $id Destination id.
	 * @throws Job_Exception For unknown ids.
	 */
	private static function driver_class( string $id ): string {
		if ( ! isset( self::DRIVERS[ $id ] ) ) {
			throw new Job_Exception( __( 'Unknown storage.', 'siteferry' ), Job_Exception::NOT_FOUND );
		}
		return self::DRIVERS[ $id ];
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $value Secret.
	 * @throws Storage_Exception If it cannot be encrypted.
	 */
	private static function encrypt(
		#[\SensitiveParameter]
		string $value
	): string {
		try {
			return Secrets::site()->encrypt( $value );
		} catch ( Secret_Exception $e ) {
			throw new Storage_Exception( $e->getMessage(), Storage_Exception::CONFIG );
		}
	}

	/**
	 * Decrypts a secret.
	 *
	 * @param string $value Stored form.
	 * @throws Storage_Exception If it cannot be decrypted.
	 */
	private static function decrypt( string $value ): string {
		try {
			return Secrets::site()->decrypt( $value );
		} catch ( Secret_Exception $e ) {
			throw new Storage_Exception( $e->getMessage(), Storage_Exception::CONFIG );
		}
	}

	/**
	 * The saved option.
	 *
	 * @return array<string,array>
	 */
	private static function stored(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}
}
