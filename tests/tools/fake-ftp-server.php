<?php
/**
 * A small FTP/FTPS server for tests (one client at a time, passive mode only):
 *
 *     php -n -d extension=openssl tests/tools/fake-ftp-server.php --port=18767 --root=DIR
 *         --user=NAME --pass=SECRET [--cert=FILE] [--require-tls] [--pasv-ip=IP]
 *         [--faults=FILE] [--log=FILE] [--idle=SECONDS]
 *
 * Listens on 127.0.0.1 only and prints "READY <port>" once it accepts connections (--port=0
 * picks a free port). With --cert it offers explicit FTPS (AUTH TLS, PBSZ, PROT P; the
 * certificate is created, self-signed, if the file does not exist). --pasv-ip puts another
 * address into PASV answers, to check that clients ignore it. --faults names a JSON file with a
 * list of {"cmd": "STOR", "action": "drop"} (close the connection) or {"cmd": "STOR",
 * "reply": "451 ..."} entries, each used once. --log records the commands (never the password).
 * The server stops after --idle seconds without a client (default 300).
 *
 * Commands: USER PASS AUTH PBSZ PROT FEAT SYST OPTS TYPE MODE STRU NOOP PWD CWD CDUP MKD RMD
 * DELE RNFR RNTO SIZE MDTM REST PASV EPSV STOR APPE RETR MLSD NLST LIST ABOR QUIT.
 * Test data only.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$sf_opt = getopt( '', array( 'port:', 'root:', 'user:', 'pass:', 'cert::', 'require-tls', 'pasv-ip::', 'faults::', 'log::', 'idle::' ) );
foreach ( array( 'port', 'root', 'user', 'pass' ) as $sf_required ) {
	if ( ! isset( $sf_opt[ $sf_required ] ) ) {
		fwrite( STDERR, "Missing --$sf_required\n" );
		exit( 2 );
	}
}

/**
 * Creates a self-signed certificate and key (PEM) for 127.0.0.1.
 *
 * @param string $file Where to write it.
 */
function sf_ftp_make_cert( string $file ): void {
	$cnf = dirname( PHP_BINARY ) . '/extras/ssl/openssl.cnf';
	if ( ! is_file( $cnf ) ) {
		$cnf = $file . '.cnf';
		file_put_contents( $cnf, "[ req ]\ndistinguished_name = dn\n[ dn ]\n" );
	}
	$args = array(
		'config'           => $cnf,
		'private_key_bits' => 2048,
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
		'digest_alg'       => 'sha256',
	);
	$key  = openssl_pkey_new( $args );
	$csr  = openssl_csr_new( array( 'commonName' => '127.0.0.1' ), $key, $args );
	$cert = openssl_csr_sign( $csr, null, $key, 30, $args );
	openssl_x509_export( $cert, $cert_pem );
	openssl_pkey_export( $key, $key_pem, null, $args );
	file_put_contents( $file, $cert_pem . $key_pem );
}

/**
 * One client session.
 */
final class Fake_Ftp_Session {

	/**
	 * Control connection.
	 *
	 * @var resource
	 */
	private $ctl;

	/**
	 * Server options.
	 *
	 * @var array
	 */
	private $opt;

	/**
	 * Stream context with the certificate (null: no TLS).
	 *
	 * @var resource|null
	 */
	private $context;

	/**
	 * Real root folder.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Virtual current folder.
	 *
	 * @var string
	 */
	private $cwd = '/';

	/**
	 * State.
	 *
	 * @var bool
	 */
	private $user_ok = false, $logged_in = false, $tls = false, $data_tls = false;

	/**
	 * Passive listener.
	 *
	 * @var resource|null
	 */
	private $pasv = null;

	/**
	 * REST offset for the next transfer.
	 *
	 * @var int
	 */
	private $rest = 0;

	/**
	 * RNFR source.
	 *
	 * @var string|null
	 */
	private $rename_from = null;

	/**
	 * Constructor.
	 *
	 * @param resource      $ctl     Control connection.
	 * @param array         $opt     Options.
	 * @param resource|null $context TLS context.
	 */
	public function __construct( $ctl, array $opt, $context ) {
		$this->ctl     = $ctl;
		$this->opt     = $opt;
		$this->context = $context;
		$this->root    = rtrim( str_replace( '\\', '/', realpath( $opt['root'] ) ), '/' );
	}

	/**
	 * Runs the session until QUIT or disconnect.
	 */
	public function run(): void {
		stream_set_timeout( $this->ctl, 120 );
		$this->reply( '220 SiteFerry test FTP server ready.' );
		while ( true ) {
			$line = fgets( $this->ctl, 4096 );
			if ( false === $line || '' === $line ) {
				return;
			}
			$line = rtrim( $line, "\r\n" );
			$cmd  = strtoupper( (string) strtok( $line, ' ' ) );
			$arg  = (string) substr( $line, strlen( $cmd ) + 1 );
			$this->log( 'PASS' === $cmd ? 'PASS ***' : $line );

			$fault = $this->fault( $cmd );
			if ( null !== $fault ) {
				if ( isset( $fault['action'] ) && 'drop' === $fault['action'] ) {
					$this->log( '(dropping the connection)' );
					return;
				}
				$this->reply( $fault['reply'] );
				$this->close_pasv();
				continue;
			}
			if ( ! $this->handle( $cmd, $arg ) ) {
				return;
			}
		}
	}

	/**
	 * Handles one command.
	 *
	 * @param string $cmd Command.
	 * @param string $arg Argument.
	 * @return bool False to end the session.
	 */
	private function handle( string $cmd, string $arg ): bool {
		$open = array( 'USER', 'PASS', 'AUTH', 'FEAT', 'SYST', 'QUIT', 'NOOP', 'OPTS', 'PBSZ', 'PROT' );
		if ( ! $this->logged_in && ! in_array( $cmd, $open, true ) ) {
			$this->reply( '530 Please log in with USER and PASS.' );
			return true;
		}
		switch ( $cmd ) {
			case 'AUTH':
				if ( null === $this->context || ! in_array( strtoupper( $arg ), array( 'TLS', 'SSL', 'TLS-C' ), true ) ) {
					$this->reply( '502 AUTH not supported here.' );
					return true;
				}
				$this->reply( '234 Proceed with TLS negotiation.' );
				stream_set_blocking( $this->ctl, true );
				if ( ! @stream_socket_enable_crypto( $this->ctl, true, STREAM_CRYPTO_METHOD_TLS_SERVER ) ) {
					$this->log( '(TLS handshake failed)' );
					return false;
				}
				$this->tls = true;
				return true;
			case 'USER':
				if ( isset( $this->opt['require-tls'] ) && ! $this->tls ) {
					$this->reply( '530 This server requires AUTH TLS first.' );
					return true;
				}
				$this->user_ok = $arg === $this->opt['user'];
				$this->reply( '331 Password required.' );
				return true;
			case 'PASS':
				if ( $this->user_ok && hash_equals( (string) $this->opt['pass'], $arg ) ) {
					$this->logged_in = true;
					$this->reply( '230 Logged in.' );
				} else {
					$this->reply( '530 Login incorrect.' );
				}
				return true;
			case 'PBSZ':
				$this->reply( '200 PBSZ=0' );
				return true;
			case 'PROT':
				$this->data_tls = 'P' === strtoupper( $arg );
				$this->reply( '200 Protection level set.' );
				return true;
			case 'FEAT':
				$this->reply( "211-Features:\r\n MLSD\r\n SIZE\r\n MDTM\r\n REST STREAM\r\n UTF8\r\n" . ( null === $this->context ? '' : " AUTH TLS\r\n PBSZ\r\n PROT\r\n" ) . '211 End' );
				return true;
			case 'SYST':
				$this->reply( '215 UNIX Type: L8' );
				return true;
			case 'OPTS':
			case 'TYPE':
			case 'MODE':
			case 'STRU':
			case 'NOOP':
				$this->reply( '200 OK.' );
				return true;
			case 'QUIT':
				$this->reply( '221 Goodbye.' );
				return false;
			case 'ABOR':
				$this->reply( '226 No transfer to abort.' );
				return true;
			case 'PWD':
				$this->reply( '257 "' . $this->cwd . '" is the current directory.' );
				return true;
			case 'CWD':
			case 'CDUP':
				$target = $this->resolve( 'CDUP' === $cmd ? '..' : $arg );
				if ( null !== $target && is_dir( $this->real( $target ) ) ) {
					$this->cwd = $target;
					$this->reply( '250 Directory changed.' );
				} else {
					$this->reply( '550 No such directory.' );
				}
				return true;
			case 'MKD':
				$target = $this->resolve( $arg );
				if ( null !== $target && ! file_exists( $this->real( $target ) ) && @mkdir( $this->real( $target ) ) ) {
					$this->reply( '257 "' . $target . '" created.' );
				} else {
					$this->reply( '550 Cannot create the directory.' );
				}
				return true;
			case 'RMD':
				$target = $this->resolve( $arg );
				$this->reply( null !== $target && @rmdir( $this->real( $target ) ) ? '250 Removed.' : '550 Cannot remove.' );
				return true;
			case 'DELE':
				$target = $this->resolve( $arg );
				$this->reply( null !== $target && is_file( $this->real( $target ) ) && @unlink( $this->real( $target ) ) ? '250 Deleted.' : '550 No such file.' );
				return true;
			case 'RNFR':
				$target            = $this->resolve( $arg );
				$this->rename_from = null !== $target && file_exists( $this->real( $target ) ) ? $target : null;
				$this->reply( null === $this->rename_from ? '550 No such file.' : '350 Ready for RNTO.' );
				return true;
			case 'RNTO':
				$target = $this->resolve( $arg );
				$ok     = null !== $this->rename_from && null !== $target && ! file_exists( $this->real( $target ) ) && @rename( $this->real( $this->rename_from ), $this->real( $target ) );
				$this->rename_from = null;
				$this->reply( $ok ? '250 Renamed.' : '553 Cannot rename.' );
				return true;
			case 'SIZE':
				$target = $this->resolve( $arg );
				$this->reply( null !== $target && is_file( $this->real( $target ) ) ? '213 ' . filesize( $this->real( $target ) ) : '550 No such file.' );
				return true;
			case 'MDTM':
				$target = $this->resolve( $arg );
				$this->reply( null !== $target && is_file( $this->real( $target ) ) ? '213 ' . gmdate( 'YmdHis', (int) filemtime( $this->real( $target ) ) ) : '550 No such file.' );
				return true;
			case 'REST':
				$this->rest = max( 0, (int) $arg );
				$this->reply( '350 Restarting at ' . $this->rest . '.' );
				return true;
			case 'PASV':
			case 'EPSV':
				$this->close_pasv();
				$this->pasv = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, null === $this->context ? stream_context_create() : $this->context );
				$port       = (int) substr( strrchr( (string) stream_socket_get_name( $this->pasv, false ), ':' ), 1 );
				if ( 'EPSV' === $cmd ) {
					$this->reply( '229 Entering Extended Passive Mode (|||' . $port . '|)' );
				} else {
					$ip = isset( $this->opt['pasv-ip'] ) ? (string) $this->opt['pasv-ip'] : '127.0.0.1';
					$this->reply( sprintf( '227 Entering Passive Mode (%s,%d,%d).', str_replace( '.', ',', $ip ), intdiv( $port, 256 ), $port % 256 ) );
				}
				return true;
			case 'STOR':
			case 'APPE':
				return $this->store( $arg, 'APPE' === $cmd );
			case 'RETR':
				return $this->retrieve( $arg );
			case 'MLSD':
			case 'NLST':
			case 'LIST':
				return $this->listing( $cmd, $arg );
		}
		$this->reply( '502 Command not implemented.' );
		return true;
	}

	/**
	 * STOR / APPE (with REST: write from that offset, without truncating).
	 *
	 * @param string $arg    File.
	 * @param bool   $append APPE.
	 */
	private function store( string $arg, bool $append ): bool {
		$target = $this->resolve( $arg );
		if ( null === $target || is_dir( $this->real( $target ) ) || ! is_dir( dirname( $this->real( $target ) ) ) ) {
			$this->reply( '553 Cannot store there.' );
			$this->close_pasv();
			return true;
		}
		$data = $this->open_data();
		if ( null === $data ) {
			return true;
		}
		$file = $this->real( $target );
		if ( $append ) {
			$out = fopen( $file, 'ab' );
		} elseif ( $this->rest > 0 ) {
			$out = fopen( $file, 'c+b' );
			fseek( $out, $this->rest );
		} else {
			$out = fopen( $file, 'wb' );
		}
		$this->rest = 0;
		while ( ! feof( $data ) ) {
			$chunk = @fread( $data, 65536 );
			if ( false === $chunk ) {
				break;
			}
			if ( '' !== $chunk ) {
				fwrite( $out, $chunk );
			}
		}
		fclose( $out );
		@fclose( $data );
		$this->reply( '226 Transfer complete.' );
		return true;
	}

	/**
	 * RETR from the REST offset.
	 *
	 * @param string $arg File.
	 */
	private function retrieve( string $arg ): bool {
		$target = $this->resolve( $arg );
		if ( null === $target || ! is_file( $this->real( $target ) ) ) {
			$this->reply( '550 No such file.' );
			$this->close_pasv();
			$this->rest = 0;
			return true;
		}
		$data = $this->open_data();
		if ( null === $data ) {
			return true;
		}
		$in = fopen( $this->real( $target ), 'rb' );
		fseek( $in, $this->rest );
		$this->rest = 0;
		$aborted    = false;
		while ( ! feof( $in ) ) {
			$chunk   = (string) fread( $in, 65536 );
			$written = @fwrite( $data, $chunk );
			if ( false === $written || ( 0 === $written && '' !== $chunk ) ) {
				$aborted = true;
				break;
			}
		}
		fclose( $in );
		@fclose( $data );
		if ( $aborted ) {
			$this->log( '(client stopped reading)' );
			return false;
		}
		$this->reply( '226 Transfer complete.' );
		return true;
	}

	/**
	 * MLSD, NLST and LIST.
	 *
	 * @param string $cmd Command.
	 * @param string $arg Folder (or options for LIST, ignored).
	 */
	private function listing( string $cmd, string $arg ): bool {
		$target = $this->resolve( '' === $arg || '-' === substr( $arg, 0, 1 ) ? '.' : $arg );
		if ( null === $target || ! is_dir( $this->real( $target ) ) ) {
			$this->reply( '550 No such directory.' );
			$this->close_pasv();
			return true;
		}
		$data = $this->open_data();
		if ( null === $data ) {
			return true;
		}
		$dir = $this->real( $target );
		foreach ( scandir( $dir ) as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path  = $dir . '/' . $name;
			$isdir = is_dir( $path );
			if ( 'MLSD' === $cmd ) {
				$line = sprintf( 'type=%s;size=%d;modify=%s; %s', $isdir ? 'dir' : 'file', $isdir ? 0 : filesize( $path ), gmdate( 'YmdHis', (int) filemtime( $path ) ), $name );
			} elseif ( 'NLST' === $cmd ) {
				$line = $name;
			} else {
				$line = sprintf( '%s 1 ftp ftp %12d %s %s', $isdir ? 'drwxr-xr-x' : '-rw-r--r--', $isdir ? 0 : filesize( $path ), gmdate( 'M d H:i', (int) filemtime( $path ) ), $name );
			}
			@fwrite( $data, $line . "\r\n" );
		}
		@fclose( $data );
		$this->reply( '226 Listing complete.' );
		return true;
	}

	/**
	 * Accepts the passive data connection (after a 150 reply), with TLS when PROT P is set.
	 *
	 * @return resource|null The data connection, or null after an error reply.
	 */
	private function open_data() {
		if ( null === $this->pasv ) {
			$this->reply( '425 Use PASV first.' );
			return null;
		}
		$this->reply( '150 Opening data connection.' );
		$data = @stream_socket_accept( $this->pasv, 15 );
		$this->close_pasv();
		if ( false === $data ) {
			$this->reply( '425 Cannot open the data connection.' );
			return null;
		}
		stream_set_timeout( $data, 30 );
		if ( $this->data_tls ) {
			stream_set_blocking( $data, true );
			if ( ! @stream_socket_enable_crypto( $data, true, STREAM_CRYPTO_METHOD_TLS_SERVER ) ) {
				@fclose( $data );
				$this->reply( '425 TLS negotiation on the data connection failed.' );
				return null;
			}
		}
		return $data;
	}

	/**
	 * Closes the passive listener.
	 */
	private function close_pasv(): void {
		if ( null !== $this->pasv ) {
			@fclose( $this->pasv );
			$this->pasv = null;
		}
	}

	/**
	 * Virtual path for an argument, or null if it leaves the root.
	 *
	 * @param string $path Path from the client.
	 */
	private function resolve( string $path ): ?string {
		$path  = str_replace( '\\', '/', $path );
		$parts = '/' === substr( $path, 0, 1 ) ? array() : explode( '/', trim( $this->cwd, '/' ) );
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( array() === array_filter( $parts, 'strlen' ) ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		return '/' . implode( '/', array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Real path of a virtual path.
	 *
	 * @param string $virtual Virtual path.
	 */
	private function real( string $virtual ): string {
		return $this->root . ( '/' === $virtual ? '' : $virtual );
	}

	/**
	 * Sends a reply line (or a multi-line reply).
	 *
	 * @param string $text Reply.
	 */
	private function reply( string $text ): void {
		@fwrite( $this->ctl, $text . "\r\n" );
		$this->log( '> ' . strtok( $text, "\r" ) );
	}

	/**
	 * The next injected fault for a command, if any.
	 *
	 * @param string $cmd Command.
	 */
	private function fault( string $cmd ): ?array {
		$file = isset( $this->opt['faults'] ) ? (string) $this->opt['faults'] : '';
		if ( '' === $file || ! is_file( $file ) ) {
			return null;
		}
		$faults = json_decode( (string) file_get_contents( $file ), true );
		foreach ( is_array( $faults ) ? $faults : array() as $i => $fault ) {
			if ( isset( $fault['cmd'] ) && strtoupper( $fault['cmd'] ) === $cmd ) {
				array_splice( $faults, $i, 1 );
				file_put_contents( $file, json_encode( $faults ) );
				return $fault;
			}
		}
		return null;
	}

	/**
	 * Appends to the command log.
	 *
	 * @param string $line Line.
	 */
	private function log( string $line ): void {
		if ( isset( $this->opt['log'] ) && '' !== $this->opt['log'] ) {
			file_put_contents( (string) $this->opt['log'], $line . "\n", FILE_APPEND );
		}
	}
}

$sf_context = null;
if ( isset( $sf_opt['cert'] ) && '' !== $sf_opt['cert'] ) {
	if ( ! is_file( $sf_opt['cert'] ) ) {
		sf_ftp_make_cert( $sf_opt['cert'] );
	}
	$sf_context = stream_context_create(
		array(
			'ssl' => array(
				'local_cert'        => $sf_opt['cert'],
				'verify_peer'       => false,
				'allow_self_signed' => true,
			),
		)
	);
}

$sf_server = stream_socket_server( 'tcp://127.0.0.1:' . (int) $sf_opt['port'], $sf_errno, $sf_errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, null === $sf_context ? stream_context_create() : $sf_context );
if ( false === $sf_server ) {
	fwrite( STDERR, "Cannot listen: $sf_errstr\n" );
	exit( 3 );
}
echo 'READY ', substr( strrchr( (string) stream_socket_get_name( $sf_server, false ), ':' ), 1 ), "\n";
fflush( STDOUT );

$sf_idle = isset( $sf_opt['idle'] ) ? max( 5, (int) $sf_opt['idle'] ) : 300;
while ( true ) {
	$sf_ctl = @stream_socket_accept( $sf_server, $sf_idle );
	if ( false === $sf_ctl ) {
		break;
	}
	try {
		( new Fake_Ftp_Session( $sf_ctl, $sf_opt, $sf_context ) )->run();
	} catch ( Throwable $sf_e ) {
		fwrite( STDERR, $sf_e->getMessage() . "\n" );
	}
	@fclose( $sf_ctl );
}
