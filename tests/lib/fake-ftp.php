<?php
/**
 * Starts tests/tools/fake-ftp-server.php in a separate process for a test.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/**
 * A running fake FTP server.
 */
final class Fake_Ftp {

	/**
	 * Port.
	 *
	 * @var int
	 */
	public $port;

	/**
	 * Root folder of the server's files.
	 *
	 * @var string
	 */
	public $root;

	/**
	 * Login.
	 *
	 * @var string
	 */
	public $user, $pass;

	/**
	 * Fault list file and command log.
	 *
	 * @var string
	 */
	public $faults, $log;

	/**
	 * Process.
	 *
	 * @var resource
	 */
	private $process;

	/**
	 * Starts a server.
	 *
	 * @param string $dir   Empty work folder.
	 * @param array  $extra More options (tls => bool, require-tls => bool, pasv-ip => string).
	 * @throws RuntimeException If it does not start.
	 */
	public function __construct( string $dir, array $extra = array() ) {
		$this->root   = $dir . '/root';
		$this->faults = $dir . '/faults.json';
		$this->log    = $dir . '/commands.log';
		$this->user   = 'sftest-' . bin2hex( random_bytes( 3 ) );
		$this->pass   = 'pw ' . bin2hex( random_bytes( 8 ) ) . ' !@#';
		mkdir( $this->root );

		$command = array( PHP_BINARY, '-n' );
		if ( extension_loaded( 'openssl' ) ) {
			$command = array_merge( $command, array( '-d', 'extension_dir=' . ini_get( 'extension_dir' ), '-d', 'extension=openssl' ) );
		}
		$command = array_merge(
			$command,
			array(
				dirname( __DIR__ ) . '/tools/fake-ftp-server.php',
				'--port=0',
				'--root=' . $this->root,
				'--user=' . $this->user,
				'--pass=' . $this->pass,
				'--faults=' . $this->faults,
				'--log=' . $this->log,
				'--idle=120',
			)
		);
		if ( ! isset( $extra['tls'] ) || $extra['tls'] ) {
			$command[] = '--cert=' . $dir . '/cert.pem';
		}
		if ( ! empty( $extra['require-tls'] ) ) {
			$command[] = '--require-tls';
		}
		if ( isset( $extra['pasv-ip'] ) ) {
			$command[] = '--pasv-ip=' . $extra['pasv-ip'];
		}

		$this->process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', $dir . '/server.err', 'a' ) ), $pipes );
		if ( ! is_resource( $this->process ) ) {
			throw new RuntimeException( 'Cannot start the fake FTP server.' );
		}
		$ready = (string) fgets( $pipes[1] );
		fclose( $pipes[1] );
		if ( ! preg_match( '/^READY (\d+)/', $ready, $match ) ) {
			throw new RuntimeException( 'The fake FTP server did not start: ' . $ready . ' ' . (string) @file_get_contents( $dir . '/server.err' ) );
		}
		$this->port = (int) $match[1];
	}

	/**
	 * Stops the server.
	 */
	public function __destruct() {
		if ( is_resource( $this->process ) ) {
			proc_terminate( $this->process );
			proc_close( $this->process );
		}
	}

	/**
	 * Injects faults for the next matching commands.
	 *
	 * @param array $faults List of ["cmd" => ..., "action" => "drop"] or ["cmd" => ..., "reply" => ...].
	 */
	public function inject( array $faults ): void {
		file_put_contents( $this->faults, json_encode( $faults ) );
	}

	/**
	 * Commands received so far.
	 */
	public function commands(): string {
		return is_file( $this->log ) ? (string) file_get_contents( $this->log ) : '';
	}
}
