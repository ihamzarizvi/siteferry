<?php
/**
 * FTP and FTPS storage.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

use SiteFerry\Backups;

defined( 'ABSPATH' ) || exit;

/**
 * An FTP server, through PHP's ftp extension: explicit FTPS (AUTH TLS, with encrypted data
 * connections) or plain FTP, passive mode by default.
 *
 * Uploads go to "<name>.part" and are renamed when complete. Each part is written at its offset
 * (REST + STOR, which resumes an interrupted part), so repeating a part is harmless. Downloads
 * read a range by starting the transfer at the offset (REST + RETR) and dropping the connection
 * once enough bytes have arrived.
 *
 * Passive data connections always go to the address of the control connection, never to an
 * address the server names in its PASV answer.
 *
 * Note: PHP's ftp extension does not check the server's TLS certificate. FTPS keeps passwords and
 * backups away from eavesdroppers, but does not prove the server's identity.
 */
final class Ftp_Driver implements Driver {

	/** Bytes per upload part. */
	const PART_SIZE = 8388608;

	/** Seconds to connect. */
	const CONNECT_TIMEOUT = 30;

	/** Seconds a transfer may stall. */
	const TRANSFER_TIMEOUT = 120;

	/** Suffix of unfinished uploads. */
	const TEMP_SUFFIX = '.part';

	/**
	 * Normalized settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Waits between retries (null: really sleep).
	 *
	 * @var callable|null
	 */
	private $sleep;

	/**
	 * Seconds to connect.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Open connection (FTP\Connection on PHP 8.1+, a resource before), or null.
	 *
	 * @var mixed
	 */
	private $conn = null;

	/**
	 * Folder after login, to which every folder change starts.
	 *
	 * @var string
	 */
	private $home = '/';

	/**
	 * Whether the connection is in the backup folder.
	 *
	 * @var bool
	 */
	private $in_folder = false;

	/**
	 * Whether temporary failures are retried (not during test()).
	 *
	 * @var bool
	 */
	private $retry = true;

	/**
	 * Constructor.
	 *
	 * @param array         $settings Normalized settings.
	 * @param string        $password Password.
	 * @param callable|null $sleep    Retry wait (tests).
	 * @param int           $timeout  Seconds to connect.
	 */
	public function __construct(
		array $settings,
		#[\SensitiveParameter]
		string $password,
		?callable $sleep = null,
		int $timeout = self::CONNECT_TIMEOUT
	) {
		$this->settings = $settings;
		$this->password = $password;
		$this->sleep    = $sleep;
		$this->timeout  = $timeout;
	}

	/**
	 * Closes the connection.
	 */
	public function __destruct() {
		$this->disconnect( true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array                $settings  Settings.
	 * @param array<string,string> $secrets   Secrets.
	 * @param Transport            $transport Unused: FTP does not go through HTTP.
	 */
	public static function create( array $settings, array $secrets, Transport $transport ): Driver {
		return new self( $settings, isset( $secrets['password'] ) ? $secrets['password'] : '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function secrets(): array {
		return array( 'password' => 'password' );
	}

	/**
	 * {@inheritDoc}
	 */
	public static function info(): array {
		return array(
			'available' => function_exists( 'ftp_connect' ),
			'tls'       => function_exists( 'ftp_ssl_connect' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $input Raw input: host, port, encryption (ftps or ftp), username, password,
	 *                     folder, passive.
	 * @throws Storage_Exception On invalid input.
	 */
	public static function normalize( array $input ): array {
		$host = strtolower( trim( isset( $input['host'] ) ? (string) $input['host'] : '' ) );
		$host = (string) preg_replace( '#^(ftps?|sftp)://#', '', $host );
		$host = rtrim( $host, '/' );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host ) ) {
			throw new Storage_Exception( 'Enter the server\'s host name, such as ftp.example.com (no path, no port).', Storage_Exception::CONFIG );
		}

		$port = isset( $input['port'] ) && '' !== trim( (string) $input['port'] ) ? (string) $input['port'] : '21';
		if ( ! preg_match( '/^\d{1,5}$/', trim( $port ) ) || (int) $port < 1 || (int) $port > 65535 ) {
			throw new Storage_Exception( 'The port must be a number from 1 to 65535 (usually 21).', Storage_Exception::CONFIG );
		}

		$encryption = isset( $input['encryption'] ) ? (string) $input['encryption'] : 'ftps';
		if ( ! in_array( $encryption, array( 'ftps', 'ftp' ), true ) ) {
			throw new Storage_Exception( 'Choose FTPS or plain FTP.', Storage_Exception::CONFIG );
		}

		// No control characters anywhere: a line break would end the FTP command early.
		$username = trim( isset( $input['username'] ) ? (string) $input['username'] : '' );
		if ( ! preg_match( '/^[^\x00-\x1f\x7f]{1,128}$/u', $username ) ) {
			throw new Storage_Exception( 'Enter the user name.', Storage_Exception::CONFIG );
		}
		$password = isset( $input['password'] ) ? (string) $input['password'] : '';
		if ( '' !== $password && ! preg_match( '/^[^\x00-\x1f\x7f]{1,256}$/u', $password ) ) {
			throw new Storage_Exception( 'The password contains characters that are not allowed.', Storage_Exception::CONFIG );
		}

		$raw_folder = trim( str_replace( '\\', '/', isset( $input['folder'] ) ? (string) $input['folder'] : 'siteferry' ) );
		$absolute   = '' !== $raw_folder && '/' === $raw_folder[0];
		$folder     = trim( $raw_folder, '/' );
		foreach ( '' === $folder ? array() : explode( '/', $folder ) as $segment ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,99}$/', $segment ) ) {
				throw new Storage_Exception( 'The folder may contain only letters, digits, ".", "-", "_" and "/" between folder names.', Storage_Exception::CONFIG );
			}
		}

		return array(
			'settings' => array(
				'host'       => $host,
				'port'       => (int) $port,
				'encryption' => $encryption,
				'username'   => $username,
				'folder'     => ( $absolute ? '/' : '' ) . $folder,
				'passive'    => array_key_exists( 'passive', $input ) ? (bool) $input['passive'] : true,
			),
			'secrets'  => array( 'password' => '' === $password ? null : $password ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe(): string {
		$s = $this->settings;
		return sprintf(
			'%s server %s%s, folder "%s"',
			'ftps' === $s['encryption'] ? 'FTPS' : 'FTP',
			$s['host'],
			21 === $s['port'] ? '' : ':' . $s['port'],
			'' === $s['folder'] ? '(login folder)' : $s['folder']
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws Storage_Exception Naming the check that failed.
	 */
	public function test(): array {
		$this->retry = false;
		try {
			return $this->run_test();
		} finally {
			$this->retry = true;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $size File size.
	 */
	public function part_size( int $size ): int {
		return self::PART_SIZE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name File name.
	 * @param int    $size File size.
	 */
	public function begin( string $name, int $size ): array {
		return $this->attempt(
			function () use ( $name, $size ): array {
				$this->enter_folder( true );
				$temp = $name . self::TEMP_SUFFIX;
				// A leftover of an earlier, abandoned attempt.
				self::ftp(
					function () use ( $temp ) {
						return ftp_delete( $this->conn, $temp );
					}
				);
				return array(
					'name'      => $name,
					'temp'      => $temp,
					'size'      => $size,
					'part_size' => $this->part_size( $size ),
				);
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array  $state Upload state.
	 * @param int    $index Part number from 0.
	 * @param string $bytes Data.
	 */
	public function put_part( array $state, int $index, string $bytes ): array {
		return $this->attempt(
			function () use ( $state, $index, $bytes ): array {
				$this->enter_folder( true );
				$offset = $index * (int) $state['part_size'];
				$remote = $this->remote_size( $state['temp'] );
				if ( $remote >= 0 && $remote < $offset ) {
					throw new Storage_Exception( sprintf( 'The partly uploaded file on the server has %d bytes instead of at least %d; start the transfer again.', $remote, $offset ), Storage_Exception::REMOTE );
				}
				$this->upload( $state['temp'], $bytes, $offset, sprintf( 'Uploading part %d', $index + 1 ) );
				$after = $this->remote_size( $state['temp'] );
				if ( $after >= 0 && $after < $offset + strlen( $bytes ) ) {
					throw new Storage_Exception( sprintf( 'The server stored %d bytes instead of %d.', $after, $offset + strlen( $bytes ) ), Storage_Exception::REMOTE );
				}
				return $state;
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $state Upload state.
	 */
	public function finish( array $state ): void {
		$this->attempt(
			function () use ( $state ) {
				$this->enter_folder( true );
				$size   = (int) $state['size'];
				$remote = $this->remote_size( $state['temp'] );
				if ( $remote >= 0 && $remote !== $size ) {
					throw new Storage_Exception( sprintf( 'The uploaded file on the server has %d bytes instead of %d.', $remote, $size ), Storage_Exception::REMOTE );
				}
				if ( $this->remote_size( $state['name'] ) >= 0 ) {
					self::ftp(
						function () use ( $state ) {
							return ftp_delete( $this->conn, $state['name'] );
						}
					);
				}
				list( $renamed, $message ) = self::ftp(
					function () use ( $state ) {
						return ftp_rename( $this->conn, $state['temp'], $state['name'] );
					}
				);
				// A rename whose answer was lost is complete if the file is there.
				if ( ! $renamed && $this->remote_size( $state['name'] ) !== $size ) {
					throw $this->failure( 'Renaming the finished upload', $message );
				}
				$stored = $this->remote_size( $state['name'] );
				if ( $stored >= 0 && $stored !== $size ) {
					throw new Storage_Exception( sprintf( 'The stored file has %d bytes instead of %d.', $stored, $size ), Storage_Exception::REMOTE );
				}
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $state Upload state.
	 */
	public function abort( array $state ): void {
		$this->attempt(
			function () use ( $state ) {
				if ( $this->enter_folder( false ) ) {
					self::ftp(
						function () use ( $state ) {
							return ftp_delete( $this->conn, $state['temp'] );
						}
					);
				}
			}
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function list(): array {
		return $this->attempt(
			function (): array {
				return $this->enter_folder( false ) ? $this->list_here() : array();
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name   File name.
	 * @param int    $offset First byte.
	 * @param int    $length Number of bytes.
	 */
	public function download_range( string $name, int $offset, int $length ): string {
		return $this->attempt(
			function () use ( $name, $offset, $length ): string {
				if ( ! $this->enter_folder( false ) ) {
					throw new Storage_Exception( 'The backup folder does not exist on the server.', Storage_Exception::NOT_FOUND );
				}
				return $this->download( $name, $offset, $length );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $name File name.
	 */
	public function delete( string $name ): void {
		$this->attempt(
			function () use ( $name ) {
				if ( ! $this->enter_folder( false ) ) {
					return;
				}
				list( $deleted, $message ) = self::ftp(
					function () use ( $name ) {
						return ftp_delete( $this->conn, $name );
					}
				);
				if ( ! $deleted && $this->remote_size( $name ) >= 0 ) {
					throw $this->failure( 'Deleting ' . $name, $message );
				}
			}
		);
	}

	/**
	 * The checks of test(): connect and log in, use the folder, write a test file in two pieces
	 * (the second resumed at an offset, as uploads do), read it back, list, delete.
	 *
	 * @throws Storage_Exception Naming the check that failed.
	 */
	private function run_test(): array {
		$lines = array();
		$this->check(
			'Connecting and logging in',
			function () {
				$this->connection();
			}
		);
		$s       = $this->settings;
		$lines[] = 'ftps' === $s['encryption']
			? sprintf( 'Connected to %s:%d with explicit FTPS (TLS) and logged in as %s.', $s['host'], $s['port'], $s['username'] )
			: sprintf( 'Connected to %s:%d and logged in as %s WITHOUT encryption: the password and the backups travel in plain text.', $s['host'], $s['port'], $s['username'] );

		$this->check(
			'Opening the folder',
			function () {
				$this->enter_folder( true );
			}
		);
		list( $pwd ) = self::ftp(
			function () {
				return ftp_pwd( $this->conn );
			}
		);
		$lines[] = sprintf( 'Using the folder %s on the server.', is_string( $pwd ) ? $pwd : $s['folder'] );

		$name = '.siteferry-test-' . bin2hex( random_bytes( 6 ) ) . '.txt';
		$body = 'SiteFerry connection test, ' . gmdate( 'Y-m-d H:i:s' ) . " UTC.\n" . str_repeat( 'x', 100 ) . "\n";
		$half = (int) ( strlen( $body ) / 2 );
		$this->check(
			'Writing a test file',
			function () use ( $name, $body, $half ) {
				$this->upload( $name, substr( $body, 0, $half ), 0, 'Writing' );
			}
		);
		$this->check(
			'Resuming the test file at an offset (needed for uploads in parts)',
			function () use ( $name, $body, $half ) {
				$this->upload( $name, substr( $body, $half ), $half, 'Resuming' );
			}
		);
		$lines[] = 'Wrote a test file in two pieces (resuming works).';

		$this->check(
			'Reading the test file back',
			function () use ( $name, $body ) {
				if ( $this->download( $name, 0, strlen( $body ) ) !== $body ) {
					throw new Storage_Exception( 'The file read back differs from what was written.', Storage_Exception::REMOTE );
				}
				// The download may have dropped the connection; this reconnects if so.
				$this->enter_folder( false );
			}
		);
		$lines[] = 'Read it back unchanged.';

		$count   = $this->check(
			'Listing the folder',
			function (): int {
				return count( $this->list_here() );
			}
		);
		$lines[] = sprintf( 'Listed the folder: %d SiteFerry backup(s).', $count );

		$this->check(
			'Deleting the test file',
			function () use ( $name ) {
				list( $deleted, $message ) = self::ftp(
					function () use ( $name ) {
						return ftp_delete( $this->conn, $name );
					}
				);
				if ( ! $deleted ) {
					throw $this->failure( 'Deleting', $message );
				}
			}
		);
		$lines[] = 'Deleted the test file.';
		return $lines;
	}

	/**
	 * Backups in the current folder (MLSD, or NLST with SIZE and MDTM where MLSD is missing).
	 *
	 * @throws Storage_Exception If the folder cannot be listed.
	 */
	private function list_here(): array {
		$items             = array();
		list( $entries )   = self::ftp(
			function () {
				return function_exists( 'ftp_mlsd' ) ? ftp_mlsd( $this->conn, '.' ) : false;
			}
		);
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				$name = isset( $entry['name'] ) ? (string) $entry['name'] : '';
				if ( isset( $entry['type'] ) && 'file' === strtolower( (string) $entry['type'] ) && self::is_backup_name( $name ) ) {
					$items[] = array(
						'name'     => $name,
						'size'     => isset( $entry['size'] ) ? (int) $entry['size'] : $this->remote_size( $name ),
						'modified' => isset( $entry['modify'] ) ? self::ftp_time( (string) $entry['modify'] ) : 0,
					);
				}
			}
		} else {
			list( $names, $message ) = self::ftp(
				function () {
					return ftp_nlist( $this->conn, '.' );
				}
			);
			if ( ! is_array( $names ) ) {
				throw $this->failure( 'Listing the folder', $message );
			}
			foreach ( $names as $path ) {
				$name = basename( str_replace( '\\', '/', (string) $path ) );
				if ( self::is_backup_name( $name ) ) {
					list( $time ) = self::ftp(
						function () use ( $name ) {
							return ftp_mdtm( $this->conn, $name );
						}
					);
					$items[]      = array(
						'name'     => $name,
						'size'     => $this->remote_size( $name ),
						'modified' => is_int( $time ) && $time > 0 ? $time : 0,
					);
				}
			}
		}
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return $b['modified'] - $a['modified'];
			}
		);
		return $items;
	}

	/**
	 * Writes bytes to a file at an offset (0: the file is replaced).
	 *
	 * @param string $file   Remote file in the current folder.
	 * @param string $bytes  Data.
	 * @param int    $offset Position.
	 * @param string $what   For messages.
	 * @throws Storage_Exception If the server refuses or the connection breaks.
	 */
	private function upload( string $file, string $bytes, int $offset, string $what ): void {
		$stream = fopen( 'php://temp/maxmemory:' . ( self::PART_SIZE + 65536 ), 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $stream, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );
		list( $ok, $message ) = self::ftp(
			function () use ( $file, $stream, $offset ) {
				return ftp_fput( $this->conn, $file, $stream, FTP_BINARY, $offset );
			}
		);
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $ok ) {
			throw $this->failure( $what, $message );
		}
	}

	/**
	 * Reads bytes from a file in the current folder.
	 *
	 * @param string $name   File.
	 * @param int    $offset First byte.
	 * @param int    $length Bytes wanted.
	 * @throws Storage_Exception If the file is missing or the connection breaks.
	 */
	private function download( string $name, int $offset, int $length ): string {
		$stream                = fopen( 'php://temp/maxmemory:' . ( self::PART_SIZE * 2 ), 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		list( $state, $error ) = self::ftp(
			function () use ( $stream, $name, $offset ) {
				return ftp_nb_fget( $this->conn, $stream, $name, FTP_BINARY, $offset );
			}
		);
		while ( FTP_MOREDATA === $state && (int) fstat( $stream )['size'] < $length ) {
			list( $state, $error ) = self::ftp(
				function () {
					return ftp_nb_continue( $this->conn );
				}
			);
		}
		if ( FTP_FAILED === $state ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$failure = $this->failure( 'Downloading ' . $name, $error );
			if ( ! $failure->retryable() && $this->remote_size( $name ) < 0 ) {
				throw new Storage_Exception( sprintf( '%s is not on the server.', $name ), Storage_Exception::NOT_FOUND );
			}
			throw $failure;
		}
		if ( FTP_MOREDATA === $state ) {
			// Enough bytes: give up the rest of the transfer together with the connection.
			$this->disconnect( false );
		}
		rewind( $stream );
		$bytes = (string) stream_get_contents( $stream, $length );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $bytes;
	}

	/**
	 * The open connection, or a new one: connect (with AUTH TLS for FTPS), log in, passive mode.
	 *
	 * @return mixed The connection.
	 * @throws Storage_Exception If the server cannot be reached or refuses the login.
	 */
	private function connection() {
		if ( null !== $this->conn ) {
			return $this->conn;
		}
		$s   = $this->settings;
		$tls = 'ftps' === $s['encryption'];
		if ( ! function_exists( 'ftp_connect' ) ) {
			throw new Storage_Exception( 'This server\'s PHP has no ftp extension; ask the host to enable it.', Storage_Exception::CONFIG );
		}
		if ( $tls && ! function_exists( 'ftp_ssl_connect' ) ) {
			throw new Storage_Exception( 'This server\'s PHP cannot use FTPS (its ftp extension was built without TLS).', Storage_Exception::CONFIG );
		}

		list( $conn, $message ) = self::ftp(
			function () use ( $s, $tls ) {
				return $tls ? ftp_ssl_connect( $s['host'], $s['port'], $this->timeout ) : ftp_connect( $s['host'], $s['port'], $this->timeout );
			}
		);
		if ( false === $conn ) {
			throw new Storage_Exception( sprintf( 'Cannot connect to %s:%d%s', $s['host'], $s['port'], '' === $message ? '.' : ': ' . $message ), Storage_Exception::TRANSIENT );
		}

		list( $logged_in, $message ) = self::ftp(
			function () use ( $conn, $s ) {
				return ftp_login( $conn, $s['username'], $this->password );
			}
		);
		if ( ! $logged_in ) {
			self::ftp(
				static function () use ( $conn ) {
					return ftp_close( $conn );
				}
			);
			if ( '' === $message ) {
				throw new Storage_Exception( 'The connection broke during the login.', Storage_Exception::TRANSIENT );
			}
			if ( $tls && preg_match( '/\b(AUTH|TLS|SSL)\b/i', $message ) ) {
				throw new Storage_Exception( sprintf( 'The server did not accept an encrypted (FTPS) connection: %s Ask the host whether it supports explicit FTPS (AUTH TLS).', $message ), Storage_Exception::CONFIG );
			}
			throw new Storage_Exception( sprintf( 'The server refused the login for "%s": %s Check the user name and password.', $s['username'], $message ), Storage_Exception::AUTH );
		}

		self::ftp(
			static function () use ( $conn ) {
				// Data connections go to the server we logged in to, whatever address PASV names.
				ftp_set_option( $conn, FTP_USEPASVADDRESS, false );
				// Offsets apply to the remote file only (REST); the local streams hold just one part.
				ftp_set_option( $conn, FTP_AUTOSEEK, false );
				return ftp_set_option( $conn, FTP_TIMEOUT_SEC, self::TRANSFER_TIMEOUT );
			}
		);
		list( $passive, $message ) = self::ftp(
			static function () use ( $conn, $s ) {
				return ftp_pasv( $conn, $s['passive'] );
			}
		);
		if ( ! $passive ) {
			self::ftp(
				static function () use ( $conn ) {
					return ftp_close( $conn );
				}
			);
			throw new Storage_Exception( 'The server refused passive mode: ' . $message, Storage_Exception::REMOTE );
		}
		list( $home ) = self::ftp(
			static function () use ( $conn ) {
				return ftp_pwd( $conn );
			}
		);

		$this->conn      = $conn;
		$this->home      = is_string( $home ) && '' !== $home ? $home : '/';
		$this->in_folder = false;
		return $conn;
	}

	/**
	 * Changes into the backup folder.
	 *
	 * @param bool $create Create missing folders.
	 * @return bool False if the folder does not exist and $create is false.
	 * @throws Storage_Exception If a folder cannot be created or entered.
	 */
	private function enter_folder( bool $create ): bool {
		$this->connection();
		if ( $this->in_folder ) {
			return true;
		}
		$folder = $this->settings['folder'];
		$start  = '' !== $folder && '/' === $folder[0] ? '/' : $this->home;
		list( $ok, $message ) = self::ftp(
			function () use ( $start ) {
				return ftp_chdir( $this->conn, $start );
			}
		);
		if ( ! $ok ) {
			throw $this->failure( 'Opening the folder ' . $start, $message );
		}
		foreach ( '' === trim( $folder, '/' ) ? array() : explode( '/', trim( $folder, '/' ) ) as $segment ) {
			list( $ok ) = self::ftp(
				function () use ( $segment ) {
					return ftp_chdir( $this->conn, $segment );
				}
			);
			if ( $ok ) {
				continue;
			}
			if ( ! $create ) {
				return false;
			}
			list( $made, $message ) = self::ftp(
				function () use ( $segment ) {
					return ftp_mkdir( $this->conn, $segment );
				}
			);
			list( $ok ) = self::ftp(
				function () use ( $segment ) {
					return ftp_chdir( $this->conn, $segment );
				}
			);
			if ( ! $ok ) {
				throw $this->failure( sprintf( 'Creating the folder "%s"', $segment ), false === $made ? $message : '' );
			}
		}
		$this->in_folder = true;
		return true;
	}

	/**
	 * Size of a file in the current folder, or -1 if it is missing (or SIZE is not supported).
	 *
	 * @param string $file File name.
	 */
	private function remote_size( string $file ): int {
		if ( null === $this->conn ) {
			return -1;
		}
		list( $size ) = self::ftp(
			function () use ( $file ) {
				return ftp_size( $this->conn, $file );
			}
		);
		return is_int( $size ) ? $size : -1;
	}

	/**
	 * An exception for a failed command. PHP's ftp functions do not tell the reply code, so the
	 * connection is probed: if it still answers, the server refused (permanent); if not, the
	 * connection was lost (temporary; the next attempt reconnects).
	 *
	 * @param string $what    What failed.
	 * @param string $message The server's text, if any.
	 */
	private function failure( string $what, string $message ): Storage_Exception {
		$alive = false;
		if ( null !== $this->conn ) {
			list( $pwd ) = self::ftp(
				function () {
					return ftp_pwd( $this->conn );
				}
			);
			$alive = is_string( $pwd );
		}
		if ( ! $alive ) {
			$this->disconnect( false );
			return new Storage_Exception( sprintf( '%s failed: the connection to the server was lost%s.', $what, '' === $message ? '' : ' (' . $message . ')' ), Storage_Exception::TRANSIENT );
		}
		$hint = preg_match( '/data connection|425|PASV|PORT/i', $message ) ? ' If this keeps happening, check the passive mode setting and the server\'s firewall.' : '';
		return new Storage_Exception( sprintf( '%s failed: %s%s', $what, '' === $message ? 'the server refused.' : $message, $hint ), Storage_Exception::REMOTE );
	}

	/**
	 * Runs one operation, retrying temporary failures with a fresh connection.
	 *
	 * @param callable $operation Operation.
	 * @return mixed What it returns.
	 */
	private function attempt( callable $operation ) {
		$run = function () use ( $operation ) {
			try {
				return $operation();
			} catch ( Storage_Exception $e ) {
				if ( $e->retryable() ) {
					$this->disconnect( false );
				}
				throw $e;
			}
		};
		return $this->retry ? Retry::run( $run, $this->sleep ) : $run();
	}

	/**
	 * Runs one check of test() and names it in any error.
	 *
	 * @param string   $label    What is being checked.
	 * @param callable $callback The check.
	 * @return mixed What the check returns.
	 * @throws Storage_Exception Prefixed with the label.
	 */
	private function check( string $label, callable $callback ) {
		try {
			return $callback();
		} catch ( Storage_Exception $e ) {
			throw new Storage_Exception( $label . ' failed. ' . $e->getMessage(), $e->getCode(), $e );
		}
	}

	/**
	 * Closes the connection.
	 *
	 * @param bool $polite Send QUIT first. Not while a transfer is running: the answer would only
	 *                     come after it, so the connection is simply dropped then.
	 */
	private function disconnect( bool $polite ): void {
		if ( null === $this->conn ) {
			return;
		}
		$conn            = $this->conn;
		$this->conn      = null;
		$this->in_folder = false;
		if ( $polite ) {
			self::ftp(
				static function () use ( $conn ) {
					return ftp_close( $conn );
				}
			);
		}
		// Otherwise the last reference goes away here, which closes the sockets without QUIT.
	}

	/**
	 * Calls an ftp_* function and captures its warning (the server's answer) instead of
	 * letting PHP print it.
	 *
	 * @param callable $call The call.
	 * @return array{0:mixed,1:string} Result and warning text.
	 */
	private static function ftp( callable $call ): array {
		$message = '';
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function ( int $severity, string $text ) use ( &$message ): bool {
				$message = trim( (string) preg_replace( '/^ftp_\w+\(\):\s*/', '', $text ) );
				return true;
			}
		);
		try {
			$result = $call();
		} finally {
			restore_error_handler();
		}
		return array( $result, (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $message ) );
	}

	/**
	 * Whether a listed file is a SiteFerry backup name.
	 *
	 * @param string $name File name.
	 */
	private static function is_backup_name( string $name ): bool {
		return Parts::stored_name( $name );
	}

	/**
	 * Unix time of an MLSD "modify" fact (YYYYMMDDHHMMSS[.sss], UTC).
	 *
	 * @param string $value Fact value.
	 */
	private static function ftp_time( string $value ): int {
		$time = \DateTime::createFromFormat( 'YmdHis', substr( $value, 0, 14 ), new \DateTimeZone( 'UTC' ) );
		return false === $time ? 0 : $time->getTimestamp();
	}
}
