<?php
/**
 * A hash whose progress can be saved between requests.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Incremental hash that survives the end of a request.
 *
 * PHP 8 can serialize a HashContext, so the state is saved directly. PHP 7.4 cannot; there the
 * caller supplies a replay callback that feeds the already-hashed bytes again (for example by
 * re-reading the start of a file). If replay is impossible, the hash is marked invalid and
 * hex() returns null, so a wrong checksum is never reported as a right one.
 */
final class Resumable_Hash {

	/**
	 * Hash algorithm.
	 *
	 * @var string
	 */
	private $algo;

	/**
	 * Running hash context.
	 *
	 * @var \HashContext
	 */
	private $context;

	/**
	 * Bytes hashed so far.
	 *
	 * @var int
	 */
	private $length = 0;

	/**
	 * False when the state could not be restored.
	 *
	 * @var bool
	 */
	private $valid = true;

	/**
	 * Final digest once computed.
	 *
	 * @var string|null
	 */
	private $digest = null;

	/**
	 * Constructor.
	 *
	 * @param string $algo Algorithm name for hash_init().
	 */
	public function __construct( string $algo = 'sha256' ) {
		$this->algo    = $algo;
		$this->context = hash_init( $algo );
	}

	/**
	 * Adds bytes to the hash.
	 *
	 * @param string $data Bytes.
	 * @throws Archive_Exception If called after hex().
	 */
	public function update( string $data ): void {
		if ( null !== $this->digest ) {
			throw new Archive_Exception( __( 'Hash already finalized.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		if ( '' === $data ) {
			return;
		}
		hash_update( $this->context, $data );
		$this->length += strlen( $data );
	}

	/**
	 * Bytes hashed so far.
	 */
	public function length(): int {
		return $this->length;
	}

	/**
	 * Whether the digest will be trustworthy.
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * Final lowercase hex digest, or null if the state was lost.
	 */
	public function hex(): ?string {
		if ( null === $this->digest ) {
			$this->digest = hash_final( $this->context );
		}
		return $this->valid ? $this->digest : null;
	}

	/**
	 * Saves the state as JSON-safe data.
	 *
	 * @param bool $allow_serialize False to force the replay path (used by tests).
	 * @return array
	 */
	public function export( bool $allow_serialize = true ): array {
		$state = array(
			'algo'    => $this->algo,
			'length'  => $this->length,
			'valid'   => $this->valid,
			'context' => null,
		);
		if ( $this->valid && $allow_serialize && self::can_serialize() ) {
			$state['context'] = base64_encode( serialize( $this->context ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return $state;
	}

	/**
	 * Restores a saved state.
	 *
	 * @param array         $state  Data from export().
	 * @param callable|null $replay function( int $length, callable $feed ): bool. Must pass the first
	 *                              $length bytes to $feed again and return true, or return false.
	 * @return self
	 */
	public static function import( array $state, ?callable $replay = null ): self {
		$algo   = isset( $state['algo'] ) ? (string) $state['algo'] : 'sha256';
		$length = isset( $state['length'] ) ? (int) $state['length'] : 0;
		$hash   = new self( $algo );

		if ( empty( $state['valid'] ) ) {
			return self::invalid( $algo, $length );
		}

		if ( isset( $state['context'] ) && is_string( $state['context'] ) ) {
			$context = self::unserialize_context( $state['context'] );
			if ( null !== $context ) {
				$hash->context = $context;
				$hash->length  = $length;
				return $hash;
			}
		}

		if ( 0 === $length ) {
			return $hash;
		}
		if ( null !== $replay && true === $replay( $length, array( $hash, 'update' ) ) && $hash->length === $length ) {
			return $hash;
		}
		return self::invalid( $algo, $length );
	}

	/**
	 * Whether this PHP version can serialize hash contexts (PHP 8.0+).
	 */
	public static function can_serialize(): bool {
		static $can = null;
		if ( null === $can ) {
			try {
				$can = self::unserialize_context( base64_encode( serialize( hash_init( 'sha256' ) ) ) ) instanceof \HashContext; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			} catch ( \Throwable $e ) {
				$can = false;
			}
		}
		return $can;
	}

	/**
	 * A hash that reports null because its state was lost.
	 *
	 * @param string $algo   Algorithm.
	 * @param int    $length Bytes already counted.
	 */
	private static function invalid( string $algo, int $length ): self {
		$hash         = new self( $algo );
		$hash->valid  = false;
		$hash->length = $length;
		return $hash;
	}

	/**
	 * Decodes a serialized context, allowing no class other than HashContext.
	 *
	 * @param string $encoded Base64 of serialize( HashContext ).
	 * @return \HashContext|null
	 */
	private static function unserialize_context( string $encoded ) {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw ) {
			return null;
		}
		$context = Io::quiet(
			static function () use ( $raw ) {
				return unserialize( $raw, array( 'allowed_classes' => array( 'HashContext' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		);
		return $context instanceof \HashContext ? $context : null;
	}
}
