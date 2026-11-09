<?php
/**
 * Password-protected backup files: the format, key derivation and chunk encryption.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

defined( 'ABSPATH' ) || exit;

/**
 * An encrypted backup is the ordinary backup (.tar or .tar.gz) cut into chunks, each sealed with
 * AES-256-GCM. The file name gets ".enc" added.
 *
 * Header (44 bytes, plain):
 *     "SFENC" 0x01 | cipher (1 = AES-256-GCM) | KDF (1 = PBKDF2-HMAC-SHA256)
 *     | iterations (uint32, big-endian) | salt (16) | password check (16)
 *
 * The password and salt give a 32-byte master key (PBKDF2); HKDF-SHA256 turns it into the
 * encryption key and a check key. The password check is the first 16 bytes of HMAC-SHA256 of the
 * first 28 header bytes under the check key, so a wrong password is reported before any data is
 * read, and the header cannot be changed.
 *
 * Chunks, one after the other until the end of the file:
 *     plaintext length (uint32) | flags (bit 0: final) | nonce (12, random) | ciphertext | tag (16)
 *
 * The additional data of each chunk is the header, the chunk's number (uint64) and its length and
 * flags, so chunks cannot be reordered, moved to another file or changed. The last chunk carries
 * the final flag (it may be empty): a file without it was cut short. Every chunk gets a fresh
 * random nonce, also when an interrupted write rewrites a chunk with the same number.
 */
final class Crypto {

	const MAGIC        = "SFENC\x01";
	const CIPHER       = 1;
	const KDF          = 1;
	const ITERATIONS   = 600000;
	const MIN_ROUNDS   = 10000;
	const MAX_ROUNDS   = 10000000;
	const SALT_BYTES   = 16;
	const CHECK_BYTES  = 16;
	const HEADER_BYTES = 44;

	/** Plaintext bytes per chunk at most (a write that is checkpointed seals a shorter one). */
	const CHUNK = 2097152;

	/** Bytes before a chunk's ciphertext: length, flags, nonce. */
	const CHUNK_HEAD = 17;

	const TAG   = 16;
	const FINAL = 1;

	/** Suffix of an encrypted backup's file name. */
	const SUFFIX = '.enc';

	/** Shortest password accepted for new backups. */
	const MIN_PASSWORD = 8;

	/**
	 * Whether this server can make and read encrypted backups.
	 */
	public static function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'hash_pbkdf2' ) && function_exists( 'hash_hkdf' )
			&& in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	/**
	 * Whether a file starts like an encrypted backup.
	 *
	 * @param string $path File.
	 */
	public static function is_encrypted( string $path ): bool {
		$handle = is_file( $path ) ? Io::open( $path, 'rb' ) : false;
		if ( false === $handle ) {
			return false;
		}
		$magic = fread( $handle, strlen( self::MAGIC ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return self::MAGIC === $magic;
	}

	/**
	 * A new header and its key, for a new encrypted file.
	 *
	 * @param string $password   Password.
	 * @param int    $iterations PBKDF2 rounds (tests use fewer).
	 * @return array{0:string,1:string} Header and 32-byte key.
	 * @throws Archive_Exception If encryption is not available.
	 */
	public static function create(
		#[\SensitiveParameter]
		string $password,
		int $iterations = self::ITERATIONS
	): array {
		if ( ! self::available() ) {
			throw new Archive_Exception( __( 'Encrypted backups need the PHP OpenSSL extension with AES-256-GCM.', 'siteferry' ), Archive_Exception::UNSUPPORTED );
		}
		$salt   = random_bytes( self::SALT_BYTES );
		$prefix = self::MAGIC . chr( self::CIPHER ) . chr( self::KDF ) . pack( 'N', $iterations ) . $salt;
		list( $key, $check ) = self::derive( $password, $salt, $iterations );
		return array( $prefix . substr( hash_hmac( 'sha256', $prefix, $check, true ), 0, self::CHECK_BYTES ), $key );
	}

	/**
	 * The key of an encrypted file for a password.
	 *
	 * @param string $header   The file's 44-byte header.
	 * @param string $password Password.
	 * @return string 32-byte key.
	 * @throws Archive_Exception If the password is wrong or the header is not one SiteFerry can read.
	 */
	public static function key(
		string $header,
		#[\SensitiveParameter]
		string $password
	): string {
		if ( strlen( $header ) !== self::HEADER_BYTES || 0 !== strpos( $header, self::MAGIC ) ) {
			throw new Archive_Exception( __( 'This is not an encrypted SiteFerry backup.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		if ( self::CIPHER !== ord( $header[6] ) || self::KDF !== ord( $header[7] ) ) {
			throw new Archive_Exception( __( 'This backup is encrypted in a way this version of SiteFerry does not know.', 'siteferry' ), Archive_Exception::UNSUPPORTED );
		}
		$iterations = (int) unpack( 'N', substr( $header, 8, 4 ) )[1];
		if ( $iterations < self::MIN_ROUNDS || $iterations > self::MAX_ROUNDS ) {
			throw new Archive_Exception( __( 'The header of this encrypted backup is damaged.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		if ( ! self::available() ) {
			throw new Archive_Exception( __( 'Encrypted backups need the PHP OpenSSL extension with AES-256-GCM.', 'siteferry' ), Archive_Exception::UNSUPPORTED );
		}
		$prefix = substr( $header, 0, 28 );
		list( $key, $check ) = self::derive( $password, substr( $header, 12, self::SALT_BYTES ), $iterations );
		if ( ! hash_equals( substr( $header, 28 ), substr( hash_hmac( 'sha256', $prefix, $check, true ), 0, self::CHECK_BYTES ) ) ) {
			throw new Archive_Exception( __( 'The password is wrong.', 'siteferry' ), Archive_Exception::LOCKED );
		}
		return $key;
	}

	/**
	 * The key of an encrypted file on disk for a password.
	 *
	 * @param string $path     Encrypted file.
	 * @param string $password Password.
	 * @throws Archive_Exception If the password is wrong or the file is not an encrypted backup.
	 */
	public static function unlock(
		string $path,
		#[\SensitiveParameter]
		string $password
	): string {
		return self::key( self::header( $path ), $password );
	}

	/**
	 * The header of an encrypted file.
	 *
	 * @param string $path Encrypted file.
	 * @throws Archive_Exception If it cannot be read.
	 */
	public static function header( string $path ): string {
		$handle = Io::open_or_fail( $path, 'rb' );
		$header = (string) fread( $handle, self::HEADER_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( strlen( $header ) !== self::HEADER_BYTES || 0 !== strpos( $header, self::MAGIC ) ) {
			throw new Archive_Exception( __( 'This is not an encrypted SiteFerry backup.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		return $header;
	}

	/**
	 * Path that reads (or writes) the plain contents of an encrypted file through the stream
	 * wrapper, with the key registered for this request.
	 *
	 * @param string      $path   Encrypted file.
	 * @param string      $key    Key.
	 * @param string|null $header Header of a file that is about to be created.
	 */
	public static function url(
		string $path,
		#[\SensitiveParameter]
		string $key,
		?string $header = null
	): string {
		return Crypto_Stream::url( $path, $key, $header );
	}

	/**
	 * Seals one chunk.
	 *
	 * @param string $key    Key.
	 * @param string $header File header.
	 * @param int    $index  Chunk number, from 0.
	 * @param string $plain  Plaintext (at most CHUNK bytes).
	 * @param bool   $final  Whether this is the last chunk.
	 * @throws Archive_Exception If OpenSSL fails.
	 */
	public static function seal_chunk(
		#[\SensitiveParameter]
		string $key,
		string $header,
		int $index,
		string $plain,
		bool $final
	): string {
		$head   = pack( 'N', strlen( $plain ) ) . chr( $final ? self::FINAL : 0 );
		$nonce  = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header . pack( 'J', $index ) . $head, self::TAG );
		if ( false === $cipher ) {
			throw new Archive_Exception( __( 'Encryption failed.', 'siteferry' ), Archive_Exception::IO );
		}
		return $head . $nonce . $cipher . $tag;
	}

	/**
	 * Opens one chunk.
	 *
	 * @param string $key    Key.
	 * @param string $header File header.
	 * @param int    $index  Chunk number.
	 * @param string $head   The chunk's first 17 bytes.
	 * @param string $body   Ciphertext and tag.
	 * @throws Archive_Exception If the chunk was changed (or the key is wrong).
	 */
	public static function open_chunk(
		#[\SensitiveParameter]
		string $key,
		string $header,
		int $index,
		string $head,
		string $body
	): string {
		$plain = strlen( $body ) < self::TAG ? false : openssl_decrypt(
			substr( $body, 0, -self::TAG ),
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			substr( $head, 5, 12 ),
			substr( $body, -self::TAG ),
			$header . pack( 'J', $index ) . substr( $head, 0, 5 )
		);
		if ( false === $plain ) {
			throw new Archive_Exception( sprintf( /* translators: %d: index + 1 */ __( 'Part %d of the encrypted backup fails its check: the file is damaged or was changed.', 'siteferry' ), $index + 1 ), Archive_Exception::CORRUPT );
		}
		return $plain;
	}

	/**
	 * Lists the chunks of an encrypted file by reading their heads.
	 *
	 * @param resource $handle Open file.
	 * @return array{chunks:array<int,array{0:int,1:int,2:int,3:bool}>,size:int,end:int,sealed:bool,torn:bool}
	 *               Chunks as [file offset, plaintext offset, length, final]; the plaintext size;
	 *               where the last whole chunk ends; whether the last chunk is final; whether
	 *               anything follows the last whole chunk.
	 */
	public static function scan( $handle ): array {
		$file   = Io::size( $handle );
		$offset = self::HEADER_BYTES;
		$plain  = 0;
		$chunks = array();
		$sealed = false;
		while ( $offset + self::CHUNK_HEAD <= $file && ! $sealed ) {
			fseek( $handle, $offset );
			$head   = (string) fread( $handle, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$length = (int) unpack( 'N', substr( $head, 0, 4 ) )[1];
			$final  = 0 !== ( ord( $head[4] ) & self::FINAL );
			if ( $length > self::CHUNK || $offset + self::CHUNK_HEAD + $length + self::TAG > $file ) {
				break; // Cut off while it was written.
			}
			$chunks[] = array( $offset, $plain, $length, $final );
			$offset  += self::CHUNK_HEAD + $length + self::TAG;
			$plain   += $length;
			$sealed   = $final;
		}
		return array(
			'chunks' => $chunks,
			'size'   => $plain,
			'end'    => $offset,
			'sealed' => $sealed,
			'torn'   => $offset < $file,
		);
	}

	/**
	 * Ends an encrypted file with its final chunk (once; sealing a sealed file does nothing).
	 *
	 * @param string $path Encrypted file.
	 * @param string $key  Key.
	 * @throws Archive_Exception If the file cannot be written.
	 */
	public static function seal(
		string $path,
		#[\SensitiveParameter]
		string $key
	): void {
		$handle = Io::open_or_fail( $path, 'r+b' );
		try {
			$header = (string) fread( $handle, self::HEADER_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$scan   = self::scan( $handle );
			if ( $scan['sealed'] ) {
				return;
			}
			if ( ! ftruncate( $handle, $scan['end'] ) || 0 !== fseek( $handle, $scan['end'] ) ) {
				throw new Archive_Exception( __( 'Cannot finish the encrypted backup.', 'siteferry' ), Archive_Exception::IO );
			}
			Io::write_all( $handle, self::seal_chunk( $key, $header, count( $scan['chunks'] ), '', true ) );
			fflush( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * The master key, split into the encryption key and the check key.
	 *
	 * @param string $password   Password.
	 * @param string $salt       Salt.
	 * @param int    $iterations PBKDF2 rounds.
	 * @return array{0:string,1:string}
	 */
	private static function derive(
		#[\SensitiveParameter]
		string $password,
		string $salt,
		int $iterations
	): array {
		$master = hash_pbkdf2( 'sha256', $password, $salt, $iterations, 32, true );
		return array(
			hash_hkdf( 'sha256', $master, 32, 'siteferry-backup-key-v1', $salt ),
			hash_hkdf( 'sha256', $master, 32, 'siteferry-backup-check-v1', $salt ),
		);
	}
}
