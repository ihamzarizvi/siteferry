<?php
/**
 * Encryption of stored secrets.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts storage keys and tokens before they are saved in the database, so a copy of the
 * database alone does not reveal them.
 *
 * The key is derived (HKDF-SHA256) from the site's WordPress salts, or from the constant
 * SITEFERRY_ENCRYPTION_KEY if wp-config.php defines one (at least 32 characters; it survives
 * changed salts). libsodium's secretbox (XSalsa20-Poly1305) is used, which WordPress provides even
 * without the PHP extension; OpenSSL AES-256-GCM is the fallback. Both detect tampering.
 *
 * Stored form: "sf1s:" or "sf1o:" followed by base64 of nonce, (tag,) ciphertext.
 */
final class Secrets {

	const SODIUM  = 'sf1s:';
	const OPENSSL = 'sf1o:';
	const INFO    = 'siteferry-secrets-v1';

	/**
	 * 32-byte key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * "sodium", "openssl" or null when neither is available.
	 *
	 * @var string|null
	 */
	private $backend;

	/**
	 * Constructor.
	 *
	 * @param string      $material Key material (high entropy).
	 * @param string|null $backend  Force "sodium" or "openssl" (tests); default: the best available.
	 */
	public function __construct(
		#[\SensitiveParameter]
		string $material,
		?string $backend = null
	) {
		$this->key     = hash_hkdf( 'sha256', $material, 32, self::INFO );
		$this->backend = null === $backend ? self::best_backend() : $backend;
	}

	/**
	 * The instance for this site.
	 */
	public static function site(): self {
		if ( defined( 'SITEFERRY_ENCRYPTION_KEY' ) && is_string( SITEFERRY_ENCRYPTION_KEY ) && strlen( SITEFERRY_ENCRYPTION_KEY ) >= 32 ) {
			return new self( SITEFERRY_ENCRYPTION_KEY );
		}
		return new self( wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}

	/**
	 * The best available backend.
	 */
	public static function best_backend(): ?string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'sodium';
		}
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return 'openssl';
		}
		return null;
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plain Secret.
	 * @throws Secret_Exception If no backend is available.
	 */
	public function encrypt(
		#[\SensitiveParameter]
		string $plain
	): string {
		if ( 'sodium' === $this->backend ) {
			$nonce = random_bytes( 24 );
			return self::SODIUM . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		if ( 'openssl' === $this->backend ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, self::INFO, 16 );
			if ( false === $cipher ) {
				throw new Secret_Exception( 'The secret could not be encrypted.' );
			}
			return self::OPENSSL . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		throw new Secret_Exception( 'Saving secrets needs the PHP sodium or OpenSSL extension.' );
	}

	/**
	 * Decrypts a secret saved by encrypt().
	 *
	 * @param string $stored Stored form.
	 * @throws Secret_Exception If it was changed, made with another key or cannot be read here.
	 */
	public function decrypt( string $stored ): string {
		$prefix = substr( $stored, 0, 5 );
		$data   = base64_decode( substr( $stored, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$plain  = false;

		// Sodium: 24-byte nonce + 16-byte MAC + ciphertext. OpenSSL: 12-byte IV + 16-byte tag + ciphertext.
		if ( false !== $data && self::SODIUM === $prefix && strlen( $data ) >= 40 ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				throw new Secret_Exception( 'This saved secret needs the PHP sodium extension.' );
			}
			try {
				$plain = sodium_crypto_secretbox_open( substr( $data, 24 ), substr( $data, 0, 24 ), $this->key );
			} catch ( \Exception $e ) { // SodiumException from malformed input.
				$plain = false;
			}
		} elseif ( false !== $data && self::OPENSSL === $prefix && strlen( $data ) >= 28 ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				throw new Secret_Exception( 'This saved secret needs the PHP OpenSSL extension.' );
			}
			$plain = openssl_decrypt( substr( $data, 28 ), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr( $data, 0, 12 ), substr( $data, 12, 16 ), self::INFO );
		}

		if ( ! is_string( $plain ) ) {
			throw new Secret_Exception( 'A saved secret cannot be read (the WordPress salts may have changed). Enter it again on the Storage tab.' );
		}
		return $plain;
	}
}
