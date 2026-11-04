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
 * id => {driver, settings, secrets (encrypted), updated}.
 *
 * Like every siteferry_ option it is left out of exports and kept on the destination site during
 * an import, and uninstall.php removes it. Secrets are never sent back to the browser.
 */
final class Destinations {

	const OPTION = 'siteferry_destinations';

	/** Destination id => driver class. */
	const DRIVERS = array(
		's3'  => S3_Driver::class,
		'ftp' => Ftp_Driver::class,
	);

	/** Destination id => [name, short name]. */
	const LABELS = array(
		's3'  => array( 'S3-compatible storage', 'S3' ),
		'ftp' => array( 'FTP server', 'FTP' ),
	);

	/**
	 * Every destination for the admin screen: settings without secrets, which secrets are set,
	 * and driver information.
	 *
	 * @return array<int,array>
	 */
	public static function overview(): array {
		$stored = self::stored();
		$list   = array();
		foreach ( self::DRIVERS as $id => $class ) {
			$saved  = isset( $stored[ $id ] ) ? $stored[ $id ] : null;
			$set    = array();
			foreach ( array_keys( $class::secrets() ) as $name ) {
				$set[ $name ] = null !== $saved && isset( $saved['secrets'][ $name ] );
			}
			$list[] = array(
				'id'          => $id,
				'label'       => self::LABELS[ $id ][0],
				'short'       => self::LABELS[ $id ][1],
				'configured'  => null !== $saved,
				'settings'    => null === $saved ? null : $saved['settings'],
				'secrets_set' => $set,
				'description' => null === $saved ? null : self::driver( $id, new Null_Transport() )->describe(),
				'updated'     => null === $saved ? null : (int) $saved['updated'],
				'info'        => $class::info(),
			);
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
	 * Validates and saves a destination's settings. Secrets left empty keep their saved value.
	 *
	 * @param string $id    Destination id.
	 * @param array  $input Raw input.
	 * @throws Storage_Exception On invalid input.
	 * @throws Job_Exception For unknown ids.
	 */
	public static function save( string $id, array $input ): void {
		$class    = self::driver_class( $id );
		$stored   = self::stored();
		$previous = isset( $stored[ $id ]['secrets'] ) ? (array) $stored[ $id ]['secrets'] : array();
		$normal   = $class::normalize( $input );

		$secrets = array();
		foreach ( $class::secrets() as $name => $label ) {
			$value = isset( $normal['secrets'][ $name ] ) ? $normal['secrets'][ $name ] : null;
			if ( null !== $value ) {
				try {
					$secrets[ $name ] = Secrets::site()->encrypt( $value );
				} catch ( Secret_Exception $e ) {
					throw new Storage_Exception( $e->getMessage(), Storage_Exception::CONFIG );
				}
			} elseif ( isset( $previous[ $name ] ) ) {
				$secrets[ $name ] = $previous[ $name ];
			} else {
				throw new Storage_Exception( sprintf( 'Enter the %s.', $label ), Storage_Exception::CONFIG );
			}
		}

		$stored[ $id ] = array(
			'driver'   => $id,
			'settings' => $normal['settings'],
			'secrets'  => $secrets,
			'updated'  => time(),
		);
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Removes a destination's settings and secrets.
	 *
	 * @param string $id Destination id.
	 */
	public static function remove( string $id ): void {
		self::driver_class( $id );
		$stored = self::stored();
		unset( $stored[ $id ] );
		if ( array() === $stored ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $stored, false );
		}
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
			throw new Job_Exception( sprintf( '%s is not set up yet (Storage tab).', self::LABELS[ $id ][0] ), Job_Exception::NOT_FOUND );
		}
		$secrets = array();
		if ( ! $transport instanceof Null_Transport ) {
			foreach ( (array) $stored[ $id ]['secrets'] as $name => $value ) {
				try {
					$secrets[ $name ] = Secrets::site()->decrypt( (string) $value );
				} catch ( Secret_Exception $e ) {
					throw new Storage_Exception( $e->getMessage(), Storage_Exception::CONFIG );
				}
			}
		}
		return $class::create( $stored[ $id ]['settings'], $secrets, null === $transport ? new Wp_Transport() : $transport );
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
	 * Driver class for an id.
	 *
	 * @param string $id Destination id.
	 * @throws Job_Exception For unknown ids.
	 */
	private static function driver_class( string $id ): string {
		if ( ! isset( self::DRIVERS[ $id ] ) ) {
			throw new Job_Exception( 'Unknown storage.', Job_Exception::NOT_FOUND );
		}
		return self::DRIVERS[ $id ];
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
