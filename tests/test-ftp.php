<?php
/**
 * FTP driver against the fake FTP/FTPS server (tests/tools/fake-ftp-server.php), started in a
 * separate process on 127.0.0.1, through PHP's real ftp extension.
 *
 * Run: php -d extension=ftp -d extension=openssl tests/test-ftp.php [--keep]
 * (run-tests.ps1 loads the extensions when the PHP build has them).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/fake-ftp.php';

use SiteFerry\Storage\Ftp_Driver;
use SiteFerry\Storage\Storage_Exception;

echo "FTP driver tests\n";

/**
 * A driver for a fake server.
 *
 * @param Fake_Ftp   $server   Server.
 * @param array      $settings Settings overrides (before normalize()).
 * @param array|null $delays   Receives retry delays.
 */
function sf_ftp( Fake_Ftp $server, array $settings = array(), ?array &$delays = null ): Ftp_Driver {
	$normal = Ftp_Driver::normalize(
		$settings + array(
			'host'       => '127.0.0.1',
			'port'       => (string) $server->port,
			'encryption' => 'ftps',
			'username'   => $server->user,
			'password'   => $server->pass,
			'folder'     => 'backups/site',
		)
	);
	$delays = array();
	$sleep  = static function ( float $seconds ) use ( &$delays ) {
		$delays[] = $seconds;
	};
	return new Ftp_Driver( $normal['settings'], isset( $settings['password'] ) ? $settings['password'] : $server->pass, $sleep, 5 );
}

/**
 * Asserts a Storage_Exception.
 *
 * @param int      $code     Expected code.
 * @param callable $callback Code that must throw.
 * @param string   $contains Text the message must contain.
 */
function sf_expect_storage_error( int $code, callable $callback, string $contains = '' ): Storage_Exception {
	try {
		$callback();
	} catch ( Storage_Exception $e ) {
		Sf_Test::same( $code, $e->getCode(), 'error code for "' . $e->getMessage() . '":' );
		Sf_Test::ok( '' === $contains || false !== strpos( $e->getMessage(), $contains ), 'message should contain "' . $contains . '": ' . $e->getMessage() );
		return $e;
	}
	throw new RuntimeException( 'expected a Storage_Exception' );
}

/**
 * Random data.
 *
 * @param int $size Bytes.
 */
function sf_bytes( int $size ): string {
	$data = '';
	while ( strlen( $data ) < $size ) {
		$data .= random_bytes( 65536 );
	}
	return substr( $data, 0, $size );
}

if ( ! function_exists( 'ftp_connect' ) || ! extension_loaded( 'openssl' ) ) {
	Sf_Test::run(
		'FTP driver',
		static function () {
			Sf_Test::skip( 'this PHP has no ftp or openssl extension' );
		}
	);
	exit( Sf_Test::finish( 'test-ftp' ) );
}

Sf_Test::run(
	'settings: host, port, encryption, folder; control characters are refused',
	static function () {
		$ok = Ftp_Driver::normalize( array( 'host' => 'FTPS://Ftp.Example.com/', 'username' => 'me@example.com', 'password' => ' spaced pass ' ) );
		Sf_Test::same( 'ftp.example.com', $ok['settings']['host'] );
		Sf_Test::same( 21, $ok['settings']['port'] );
		Sf_Test::same( 'ftps', $ok['settings']['encryption'], 'FTPS by default:' );
		Sf_Test::same( true, $ok['settings']['passive'], 'passive by default:' );
		Sf_Test::same( 'siteferry', $ok['settings']['folder'] );
		Sf_Test::same( ' spaced pass ', $ok['secrets']['password'], 'password kept as typed:' );
		Sf_Test::ok( ! isset( $ok['settings']['password'] ), 'the password is not among the settings' );
		Sf_Test::same( '/home/me/backups', Ftp_Driver::normalize( array( 'host' => 'h', 'username' => 'u', 'folder' => '/home/me/backups/' ) )['settings']['folder'] );
		Sf_Test::same( '', Ftp_Driver::normalize( array( 'host' => 'h', 'username' => 'u', 'folder' => '' ) )['settings']['folder'] );
		Sf_Test::same( null, Ftp_Driver::normalize( array( 'host' => 'h', 'username' => 'u' ) )['secrets']['password'], 'empty password means "keep":' );

		$bad = array(
			'no host'               => array( 'username' => 'u' ),
			'host with a path'      => array( 'host' => 'ftp.example.com/dir', 'username' => 'u' ),
			'host with a port'      => array( 'host' => 'ftp.example.com:21', 'username' => 'u' ),
			'port 0'                => array( 'host' => 'h', 'username' => 'u', 'port' => '0' ),
			'port 70000'            => array( 'host' => 'h', 'username' => 'u', 'port' => '70000' ),
			'port text'             => array( 'host' => 'h', 'username' => 'u', 'port' => '21a' ),
			'sftp'                  => array( 'host' => 'h', 'username' => 'u', 'encryption' => 'sftp' ),
			'no user'               => array( 'host' => 'h' ),
			'line break in user'    => array( 'host' => 'h', 'username' => "u\r\nDELE x" ),
			'line break in password' => array( 'host' => 'h', 'username' => 'u', 'password' => "p\r\nDELE x" ),
			'NUL in password'       => array( 'host' => 'h', 'username' => 'u', 'password' => "p\0" ),
			'folder with ..'        => array( 'host' => 'h', 'username' => 'u', 'folder' => 'a/../b' ),
			'folder with a newline' => array( 'host' => 'h', 'username' => 'u', 'folder' => "a\nb" ),
		);
		foreach ( $bad as $label => $input ) {
			try {
				Ftp_Driver::normalize( $input );
			} catch ( Storage_Exception $e ) {
				Sf_Test::same( Storage_Exception::CONFIG, $e->getCode(), $label );
				continue;
			}
			throw new RuntimeException( 'accepted: ' . $label );
		}
	}
);

Sf_Test::run(
	'connection test over explicit FTPS: TLS on control and data, resume, folders created',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ) );
		$driver = sf_ftp( $server );
		$lines  = $driver->test();
		Sf_Test::same( 6, count( $lines ), implode( ' | ', $lines ) );
		Sf_Test::ok( false !== strpos( $lines[0], 'explicit FTPS (TLS)' ), $lines[0] );
		Sf_Test::ok( false !== strpos( $lines[1], '/backups/site' ), $lines[1] );
		$log = $server->commands();
		foreach ( array( 'AUTH TLS', 'PBSZ 0', 'PROT P', 'MKD backups', 'MKD site', 'REST ', 'MLSD', 'PASS ***' ) as $command ) {
			Sf_Test::ok( false !== strpos( $log, $command ), "command $command sent" );
		}
		Sf_Test::ok( false === strpos( $log, $server->pass ), 'the password is not in the server log' );
		Sf_Test::same( array( '.', '..' ), scandir( $server->root . '/backups/site' ), 'test file removed:' );
	}
);

Sf_Test::run(
	'plain FTP works and says that it is unencrypted; FTPS against a server without TLS fails clearly',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ), array( 'tls' => false ) );
		$lines  = sf_ftp( $server, array( 'encryption' => 'ftp' ) )->test();
		Sf_Test::ok( false !== strpos( $lines[0], 'WITHOUT encryption' ), $lines[0] );
		Sf_Test::ok( false === strpos( $server->commands(), 'AUTH' ), 'no AUTH command for plain FTP' );

		$driver = sf_ftp( $server );
		sf_expect_storage_error( Storage_Exception::CONFIG, static function () use ( $driver ) { $driver->test(); }, 'did not accept an encrypted (FTPS) connection' );
	}
);

Sf_Test::run(
	'wrong password and unreachable server',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ) );
		$wrong  = sf_ftp( $server, array( 'password' => 'not-the-password' ) );
		$e      = sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $wrong ) { $wrong->list(); }, 'refused the login' );
		Sf_Test::ok( false === strpos( $e->getMessage(), 'not-the-password' ), 'the password is not in the message' );

		$closed = sf_ftp( $server, array( 'port' => '1' ) );
		sf_expect_storage_error( Storage_Exception::TRANSIENT, static function () use ( $closed ) { $closed->test(); }, 'Cannot connect to 127.0.0.1:1' );
	}
);

Sf_Test::run(
	'upload in parts (one repeated), rename when complete, list, ranged download, delete',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ) );
		$driver = sf_ftp( $server );
		$name   = 'mysite-2026-11-04-0123456789ab.tar';
		$data   = sf_bytes( 3 * 1048576 + 4321 );

		// A leftover from an earlier attempt is removed when an upload begins.
		mkdir( $server->root . '/backups/site', 0755, true );
		file_put_contents( $server->root . '/backups/site/' . $name . '.part', 'stale' );

		$state              = $driver->begin( $name, strlen( $data ) );
		$state['part_size'] = 1048576;
		$state              = json_decode( json_encode( $state ), true );
		Sf_Test::ok( ! is_file( $server->root . '/backups/site/' . $name . '.part' ), 'stale part removed' );
		foreach ( array( 0, 1, 1, 2, 3 ) as $index ) {
			$state = $driver->put_part( $state, $index, substr( $data, $index * 1048576, 1048576 ) );
		}
		Sf_Test::ok( ! is_file( $server->root . '/backups/site/' . $name ), 'not visible under its name before finish()' );
		Sf_Test::same( array(), $driver->list(), 'unfinished uploads are not listed:' );
		$driver->finish( $state );
		Sf_Test::same( hash( 'sha256', $data ), hash_file( 'sha256', $server->root . '/backups/site/' . $name ), 'stored bytes:' );
		Sf_Test::ok( ! is_file( $server->root . '/backups/site/' . $name . '.part' ), 'temporary name gone' );

		file_put_contents( $server->root . '/backups/site/notes.txt', 'x' );
		mkdir( $server->root . '/backups/site/inner-2026-01-01-000000000000.tar' );
		$listed = $driver->list();
		Sf_Test::same( array( $name ), array_column( $listed, 'name' ), 'only backup files are listed:' );
		Sf_Test::same( strlen( $data ), $listed[0]['size'] );
		Sf_Test::ok( abs( $listed[0]['modified'] - time() ) < 120, 'modified time parsed' );

		$back = '';
		for ( $offset = 0; $offset < strlen( $data ); $offset += 700000 ) {
			$back .= $driver->download_range( $name, $offset, (int) min( 700000, strlen( $data ) - $offset ) );
		}
		Sf_Test::same( hash( 'sha256', $data ), hash( 'sha256', $back ), 'downloaded in ranges:' );
		// Ranges in a row continue one transfer; a range on its own starts one at its offset.
		Sf_Test::same( 0, substr_count( $server->commands(), 'REST 700000' ), 'ranges in a row share one transfer:' );
		Sf_Test::same( substr( $data, 700000, 10 ), $driver->download_range( $name, 700000, 10 ), 'a range on its own:' );
		Sf_Test::ok( substr_count( $server->commands(), 'REST 700000' ) >= 1, 'a range on its own starts at its offset' );

		$driver->delete( $name );
		Sf_Test::same( array(), $driver->list() );
		$driver->delete( $name ); // Deleting a missing file is not an error.
		sf_expect_storage_error( Storage_Exception::NOT_FOUND, static function () use ( $driver, $name ) { $driver->download_range( $name, 0, 10 ); }, 'is not on the server' );
	}
);

Sf_Test::run(
	'a dropped connection during a part is retried and the part is written again at its offset',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ) );
		$driver = sf_ftp( $server, array(), $delays );
		$name   = 'retry-2026-11-04-0123456789ab.tar';
		$data   = sf_bytes( 2 * 1048576 + 99 );

		$state              = $driver->begin( $name, strlen( $data ) );
		$state['part_size'] = 1048576;
		$state              = $driver->put_part( $state, 0, substr( $data, 0, 1048576 ) );
		$server->inject( array( array( 'cmd' => 'STOR', 'action' => 'drop' ) ) );
		$state = $driver->put_part( $state, 1, substr( $data, 1048576, 1048576 ) );
		Sf_Test::same( 1, count( $delays ), 'one retry:' );
		$state = $driver->put_part( $state, 2, substr( $data, 2 * 1048576 ) );
		$driver->finish( $state );
		Sf_Test::same( hash( 'sha256', $data ), hash_file( 'sha256', $server->root . '/backups/site/' . $name ) );

		// A refusal is not retried.
		$server->inject( array( array( 'cmd' => 'STOR', 'reply' => '553 Permission denied.' ) ) );
		$before = count( $delays );
		$state2 = $driver->begin( 'denied-2026-11-04-0123456789ab.tar', 10 );
		sf_expect_storage_error( Storage_Exception::REMOTE, static function () use ( $driver, $state2 ) { $driver->put_part( $state2, 0, '0123456789' ); }, 'Permission denied' );
		Sf_Test::same( $before, count( $delays ), 'no retry for a refusal:' );

		$driver->abort( $state2 );
		Sf_Test::ok( ! is_file( $server->root . '/backups/site/denied-2026-11-04-0123456789ab.tar.part' ), 'abort removes the partial file' );
	}
);

Sf_Test::run(
	'passive mode ignores the address the server names; plain-folder and absolute-folder setups',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ), array( 'pasv-ip' => '10.255.255.1' ) );
		$driver = sf_ftp( $server, array( 'folder' => '/abs/olute' ) );
		$started = microtime( true );
		Sf_Test::same( 6, count( $driver->test() ) );
		Sf_Test::ok( microtime( true ) - $started < 20, 'no attempt to reach 10.255.255.1' );
		Sf_Test::ok( false !== strpos( $server->commands(), '227 Entering Passive Mode (10,255,255,1,' ), 'the server did name the other address' );
		Sf_Test::ok( is_dir( $server->root . '/abs/olute' ), 'absolute folder created' );
		$driver = null; // The fake serves one client at a time.

		$login = sf_ftp( $server, array( 'folder' => '' ) );
		$state = $login->begin( 'root-2026-11-04-0123456789ab.tar', 3 );
		$login->finish( $login->put_part( $state, 0, 'abc' ) );
		$login = null;
		Sf_Test::same( 'abc', file_get_contents( $server->root . '/root-2026-11-04-0123456789ab.tar' ), 'stored in the login folder:' );
		Sf_Test::same( array(), sf_ftp( $server, array( 'folder' => 'does/not/exist' ) )->list(), 'a missing folder lists nothing:' );
		Sf_Test::ok( ! is_dir( $server->root . '/does' ), 'listing does not create folders' );
	}
);

Sf_Test::run(
	'TLS is required by the server: plain FTP is refused with its message',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ), array( 'require-tls' => true ) );
		sf_expect_storage_error( Storage_Exception::AUTH, static function () use ( $server ) { sf_ftp( $server, array( 'encryption' => 'ftp' ) )->test(); }, 'requires AUTH TLS' );
		Sf_Test::same( 6, count( sf_ftp( $server )->test() ), 'FTPS works:' );
	}
);

Sf_Test::run(
	'ranges read in a row share one transfer; another command in between ends it cleanly',
	static function () {
		$server = new Fake_Ftp( Sf_Test::temp_dir( 'ftp' ) );
		mkdir( $server->root . '/backups/site', 0777, true );
		$name = 'example-com-2026-01-01-0123456789ab.tar';
		$data = sf_bytes( 20 * 1048576 + 123 );
		file_put_contents( $server->root . '/backups/site/' . $name, $data );
		$driver = sf_ftp( $server );
		$chunk  = 3 * 1048576;
		$got    = '';
		for ( $at = 0; $at < 9 * 1048576; $at += $chunk ) {
			$got .= $driver->download_range( $name, $at, $chunk );
		}
		Sf_Test::same( 1, substr_count( $server->commands(), 'USER ' ), 'one login for ranges in a row:' );
		// Another command while the transfer is still sending.
		Sf_Test::same( array( $name ), array_column( $driver->list(), 'name' ), 'listing in between:' );
		for ( $at = 9 * 1048576; $at < strlen( $data ); $at += $chunk ) {
			$got .= $driver->download_range( $name, $at, min( $chunk, strlen( $data ) - $at ) );
		}
		Sf_Test::same( md5( $data ), md5( $got ), 'every byte, in order:' );
		Sf_Test::same( substr( $data, 5, 100 ), $driver->download_range( $name, 5, 100 ), 'an earlier range:' );
		Sf_Test::same( substr( $data, -50 ), $driver->download_range( $name, strlen( $data ) - 50, 50 ), 'the last bytes:' );
		Sf_Test::same( substr( $data, 1048576, 10 ), $driver->download_range( $name, 1048576, 10 ), 'then a range in the middle:' );
		$started = microtime( true );
		unset( $driver ); // Closing while a transfer may still be sending must not wait for it.
		Sf_Test::ok( microtime( true ) - $started < 3, 'closing does not hang' );
	}
);

exit( Sf_Test::finish( 'test-ftp' ) );
