<?php
/**
 * Emergency kit: a stand-alone page for when WordPress no longer opens.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Makes emergency kits and keeps a short record of them.
 *
 * A kit is one PHP file the administrator downloads now and keeps private. If the site later
 * shows a white screen or a fatal error, they upload it into WordPress's main folder (the one
 * with wp-load.php) and open it in a browser. Without loading WordPress, and after a password,
 * it can turn off every plugin except SiteFerry, switch to a default theme, undo both, and
 * download SiteFerry's backups. See template() for the kit itself and its security notes.
 *
 * Nothing is written to the server when a kit is made except a record in an option (id, when it
 * was made, when it expires, a short fingerprint of its password hash), so kits can be listed and
 * revoked. The password itself is never stored or sent back; the kit holds only its hash.
 */
final class Emergency_Kit {

	/** Option with the record of kits made. */
	const OPTION = 'siteferry_emergency_kits';

	/** Option the kit uses to remember what it changed (read by the kit; removed on uninstall). */
	const BEFORE_OPTION = 'siteferry_emergency_before';

	/** Days a kit may work. */
	const DAYS = array( 7, 30, 90 );

	/** Shortest password accepted. */
	const MIN_PASSWORD = 12;

	/** Working (not revoked) kits kept; revoked kits are kept until they expire. */
	const KEEP = 20;

	/** Placeholder in the template for the kit's settings. */
	const PLACEHOLDER = '/*SFK_CONFIG*/';

	/**
	 * Makes a kit: checks the password, records the kit and returns its file name and contents.
	 *
	 * @param string $password Password.
	 * @param string $again    The password typed again.
	 * @param int    $days     Days it works.
	 * @return array{name:string,code:string,id:string,expires:int}
	 * @throws Job_Exception On a password that is too short or does not match, or other days.
	 */
	public static function make(
		#[\SensitiveParameter]
		string $password,
		#[\SensitiveParameter]
		string $again,
		int $days
	): array {
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $password, 'UTF-8' ) < self::MIN_PASSWORD : strlen( $password ) < self::MIN_PASSWORD ) {
			/* translators: %d: a number of characters */
			throw new Job_Exception( sprintf( __( 'Choose a password of at least %d characters.', 'siteferry' ), self::MIN_PASSWORD ), Job_Exception::STEP );
		}
		if ( ! hash_equals( $password, $again ) ) {
			throw new Job_Exception( __( 'The two passwords are not the same.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( strlen( $password ) > 200 ) {
			throw new Job_Exception( __( 'The password is too long.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! in_array( $days, self::DAYS, true ) ) {
			throw new Job_Exception( __( 'Choose how long the kit works: 7, 30 or 90 days.', 'siteferry' ), Job_Exception::STEP );
		}
		$id      = Storage::random_hex( 4 );
		$hash    = password_hash( $password, PASSWORD_DEFAULT );
		$expires = time() + $days * DAY_IN_SECONDS;
		$config  = self::config( $id, $hash, $expires );

		$kits = self::records();
		array_unshift(
			$kits,
			array(
				'id'          => $id,
				'made'        => time(),
				'expires'     => $expires,
				'fingerprint' => substr( hash( 'sha256', $hash ), 0, 12 ),
				'revoked'     => false,
			)
		);
		update_option( self::OPTION, self::retain( $kits, time() ), false );

		return array(
			'name'    => self::file_name( $id ),
			'code'    => self::build( $config ),
			'id'      => $id,
			'expires' => $expires,
		);
	}

	/**
	 * The records to keep, newest first: expired kits are dropped, every revoked kit is kept until
	 * it expires (a kit treats a missing record as revoked, but keeping it shows the admin that it
	 * was revoked), and at most KEEP kits that are not revoked. A working kit beyond KEEP loses its
	 * record and so stops working.
	 *
	 * @param array $kits Records, newest first.
	 * @param int   $now  Current time.
	 * @return array
	 */
	public static function retain( array $kits, int $now ): array {
		$kept  = array();
		$plain = 0;
		foreach ( $kits as $kit ) {
			if ( (int) $kit['expires'] < $now ) {
				continue;
			}
			if ( ! empty( $kit['revoked'] ) ) {
				$kept[] = $kit;
			} elseif ( $plain < self::KEEP ) {
				$kept[] = $kit;
				++$plain;
			}
		}
		return $kept;
	}

	/**
	 * The kits made, newest first, with their state: active, expired or revoked.
	 *
	 * @return array<int,array{id:string,name:string,made:int,expires:int,state:string}>
	 */
	public static function kits(): array {
		$now = time();
		return array_map(
			static function ( array $kit ) use ( $now ): array {
				$state = ! empty( $kit['revoked'] ) ? 'revoked' : ( (int) $kit['expires'] < $now ? 'expired' : 'active' );
				return array(
					'id'      => (string) $kit['id'],
					'name'    => self::file_name( (string) $kit['id'] ),
					'made'    => (int) $kit['made'],
					'expires' => (int) $kit['expires'],
					'state'   => $state,
				);
			},
			self::records()
		);
	}

	/**
	 * Revokes a kit: it stops working as soon as it can read the database.
	 *
	 * @param string $id Kit id.
	 * @throws Job_Exception If there is no such kit.
	 */
	public static function revoke( string $id ): void {
		$kits  = self::records();
		$found = false;
		foreach ( $kits as &$kit ) {
			if ( $kit['id'] === $id ) {
				$kit['revoked'] = true;
				$found          = true;
			}
		}
		unset( $kit );
		if ( ! $found ) {
			throw new Job_Exception( __( 'There is no such emergency kit.', 'siteferry' ), Job_Exception::NOT_FOUND );
		}
		update_option( self::OPTION, $kits, false );
	}

	/**
	 * The kit's settings: what it needs to find its way without WordPress.
	 *
	 * @param string $id      Kit id (8 hex).
	 * @param string $hash    password_hash() of its password.
	 * @param int    $expires Expiry time.
	 */
	public static function config( string $id, string $hash, int $expires ): array {
		$home = (string) get_option( 'home' );
		$host = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$port = wp_parse_url( $home, PHP_URL_PORT );
		$base = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		return array(
			'id'         => $id,
			'hash'       => $hash,
			'secret'     => Storage::random_hex( 32 ),
			'expires'    => $expires,
			'made'       => time(),
			'host'       => $host . ( $port ? ':' . (int) $port : '' ),
			'backups'    => self::relative( WP_CONTENT_DIR . '/' . Storage::name() . '/backups', $base ),
			'themes'     => self::relative( get_theme_root(), $base ),
			'own_plugin' => Plugin::basename(),
			'version'    => SITEFERRY_VERSION,
		);
	}

	/**
	 * The kit's PHP code with its settings filled in (with var_export, so no setting can become
	 * code).
	 *
	 * @param array $config Settings.
	 */
	public static function build( array $config ): string {
		$template = self::template();
		$at       = strpos( $template, self::PLACEHOLDER );
		return substr( $template, 0, (int) $at ) . '$sfk_config = ' . var_export( $config, true ) . ';' . substr( $template, (int) $at + strlen( self::PLACEHOLDER ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes the kit's settings as PHP literals.
	}

	/**
	 * A kit's file name.
	 *
	 * @param string $id Kit id.
	 */
	public static function file_name( string $id ): string {
		return 'siteferry-emergency-' . $id . '.php';
	}

	/**
	 * The stored records, cleaned.
	 *
	 * @return array<int,array{id:string,made:int,expires:int,fingerprint:string,revoked:bool}>
	 */
	private static function records(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $kit ) {
			if ( is_array( $kit ) && isset( $kit['id'], $kit['expires'] ) && preg_match( '/^[a-f0-9]{8}\z/', (string) $kit['id'] ) ) {
				$out[] = array(
					'id'          => (string) $kit['id'],
					'made'        => isset( $kit['made'] ) ? (int) $kit['made'] : 0,
					'expires'     => (int) $kit['expires'],
					'fingerprint' => isset( $kit['fingerprint'] ) ? (string) $kit['fingerprint'] : '',
					'revoked'     => ! empty( $kit['revoked'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * A path relative to WordPress's main folder when it is inside it, else the absolute path.
	 *
	 * @param string $path Path.
	 * @param string $base WordPress's main folder, forward slashes, no trailing slash.
	 */
	private static function relative( string $path, string $base ): string {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		return 0 === strpos( $path, $base . '/' ) ? substr( $path, strlen( $base ) + 1 ) : $path;
	}

	/**
	 * The kit's code. It is a string here (the kit is written into a downloaded file, never run
	 * inside WordPress); its settings replace PLACEHOLDER.
	 */
	public static function template(): string {
		return <<<'KIT'
<?php
/**
 * SiteFerry emergency kit.
 *
 * For a WordPress site that no longer opens (a white screen, or an error after an update).
 * Upload this file into WordPress's main folder (the one with wp-load.php and wp-admin), open it
 * in a browser and sign in with the password chosen when the kit was made. Without loading
 * WordPress it can turn off every plugin except SiteFerry, switch to a default theme, undo both,
 * and download SiteFerry's backups. Delete it when you are done (it has a button for that).
 *
 * Made by SiteFerry, https://xsofty.com/products/siteferry
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Security notes:
 * - It works only until it expires, under its own file name, on the address it was made for,
 *   and while the site's database has a record of it that is not revoked. That is checked first,
 *   before signing in and before any action (a download included). When the database cannot be
 *   reached, the kit still opens, so that backups can be downloaded.
 * - The password is kept only as a password_hash(). Wrong passwords are slowed down (1 to 2
 *   seconds) and locked out for 15 minutes after 5 in a row (a small file in the system's temp
 *   folder). A signed-in session is an HMAC-signed cookie (HttpOnly, SameSite=Strict, Secure over
 *   HTTPS) that lasts one hour. Its key comes from a random secret in this file and from the
 *   WordPress keys and salts in the server's wp-config.php, so a copy of this file alone cannot
 *   sign in without the password. Only when wp-config.php gives no salts as plain text (for
 *   example from getenv()) does the key come from this file alone.
 * - Every action is a POST carrying a token bound to the session and the action (no CSRF), and
 *   nothing happens on a GET.
 * - wp-config.php is read, never run: only plain literal values are accepted.
 * - SQL goes through prepared statements; table names come from a prefix that is checked.
 * - A download is a file directly inside SiteFerry's backups folder, by a name that is checked
 *   against a strict pattern; that name is the only request value that reaches a header. There
 *   are no redirects; everything shown is escaped; there are no scripts; a strict content
 *   security policy, no framing, no caching and no indexing.
 */

/*SFK_CONFIG*/

/**
 * Text for HTML.
 *
 * @param mixed $text Text.
 */
function sfk_h( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * The key for this kit's signatures.
 *
 * @param array $c Settings.
 */
function sfk_key( array $c ) {
	$server = isset( $c['server'] ) && is_string( $c['server'] ) ? $c['server'] : '';
	return hash_hmac( 'sha256', 'siteferry-emergency|' . $c['id'], $c['secret'] . '|' . $c['hash'] . ( '' === $server ? '' : '|' . $server ) );
}

/**
 * A secret only the server has: a hash of the WordPress keys and salts in wp-config.php (plain
 * literals only). It goes into the session key, so a copy of this file alone cannot sign in.
 * Empty when none can be read (for example when wp-config.php takes them from getenv()); the key
 * then comes from this file only.
 *
 * @param array|null $parsed sfk_parse_config() result.
 */
function sfk_server_secret( $parsed ) {
	if ( ! is_array( $parsed ) || ! isset( $parsed['defines'] ) || ! is_array( $parsed['defines'] ) ) {
		return '';
	}
	$parts = array();
	foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
		$value = isset( $parsed['defines'][ $name ] ) && is_string( $parsed['defines'][ $name ] ) ? $parsed['defines'][ $name ] : '';
		if ( strlen( $value ) >= 16 && 'put your unique phrase here' !== $value ) {
			$parts[] = $name . '=' . $value;
		}
	}
	return array() === $parts ? '' : hash( 'sha256', implode( "\n", $parts ) );
}

/**
 * What the site's record says about this kit: "ok", "revoked", or "unknown" (no record, which
 * the kit treats like revoked).
 *
 * @param mixed  $kits The siteferry_emergency_kits option, unserialized.
 * @param string $id   This kit's id.
 */
function sfk_revocation( $kits, $id ) {
	foreach ( is_array( $kits ) ? $kits : array() as $kit ) {
		if ( is_array( $kit ) && isset( $kit['id'] ) && $kit['id'] === $id ) {
			return empty( $kit['revoked'] ) ? 'ok' : 'revoked';
		}
	}
	return 'unknown';
}

/**
 * A session token that is valid until $until.
 *
 * @param array $c     Settings.
 * @param int   $until Expiry time.
 */
function sfk_session_token( array $c, $until ) {
	return (int) $until . '.' . hash_hmac( 'sha256', 'session|' . $c['id'] . '|' . (int) $until, sfk_key( $c ) );
}

/**
 * Whether a session token is genuine and current.
 *
 * @param array $c     Settings.
 * @param mixed $token Token from the cookie.
 * @param int   $now   Current time.
 */
function sfk_session_valid( array $c, $token, $now ) {
	if ( ! is_string( $token ) || ! preg_match( '/^([0-9]{1,12})\.[a-f0-9]{64}\z/', $token, $m ) ) {
		return false;
	}
	if ( (int) $m[1] < $now ) {
		return false;
	}
	return hash_equals( sfk_session_token( $c, (int) $m[1] ), $token );
}

/**
 * The token a form for $action carries in this session.
 *
 * @param array  $c       Settings.
 * @param string $session Session token.
 * @param string $action  Action.
 */
function sfk_csrf( array $c, $session, $action ) {
	return hash_hmac( 'sha256', 'csrf|' . $session . '|' . $action, sfk_key( $c ) );
}

/**
 * Whether a form's token is right for this session and action.
 *
 * @param array  $c       Settings.
 * @param string $session Session token.
 * @param string $action  Action.
 * @param mixed  $token   Token sent.
 */
function sfk_csrf_valid( array $c, $session, $action, $token ) {
	return is_string( $token ) && hash_equals( sfk_csrf( $c, $session, $action ), $token );
}

/**
 * A PHP string literal's value, or null when it is not plain text (a double-quoted string with
 * a variable in it).
 *
 * @param string $raw Literal as written, with its quotes.
 */
function sfk_literal( $raw ) {
	$quote = substr( $raw, 0, 1 );
	$inner = substr( $raw, 1, -1 );
	if ( "'" === $quote ) {
		return preg_replace( '/\\\\([\\\\\'])/', '$1', $inner );
	}
	if ( '"' === $quote && false === strpos( $inner, '$' ) ) {
		return stripcslashes( $inner );
	}
	return null;
}

/**
 * The value of a define()'s second argument when it is one plain literal.
 *
 * @param array $tokens Tokens of the argument.
 * @return array{0:bool,1:mixed} Whether it is plain, and its value.
 */
function sfk_plain( array $tokens ) {
	if ( 1 !== count( $tokens ) || ! is_array( $tokens[0] ) ) {
		return array( false, null );
	}
	list( $type, $text ) = $tokens[0];
	if ( T_CONSTANT_ENCAPSED_STRING === $type ) {
		$value = sfk_literal( $text );
		return array( null !== $value, $value );
	}
	if ( T_LNUMBER === $type ) {
		return array( true, (int) $text );
	}
	if ( T_STRING === $type && in_array( strtolower( $text ), array( 'true', 'false' ), true ) ) {
		return array( true, 'true' === strtolower( $text ) );
	}
	return array( false, null );
}

/**
 * Reads wp-config.php without running it: the define()s and $table_prefix whose values are
 * plain literals. A setting given any other way (getenv(), a constant, a calculation), or set
 * twice to different values, is listed under "problems" and has no value.
 *
 * @param string $code The file's PHP code.
 * @return array{defines:array,prefix:?string,problems:array}
 */
function sfk_parse_config( $code ) {
	$skip   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML );
	$tokens = array();
	foreach ( token_get_all( $code ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], $skip, true ) ) {
			continue;
		}
		$tokens[] = $token;
	}
	$out     = array(
		'defines'  => array(),
		'prefix'   => null,
		'problems' => array(),
	);
	$set     = static function ( $key, $value ) use ( &$out ) {
		if ( array_key_exists( $key, $out['defines'] ) && $out['defines'][ $key ] !== $value ) {
			$out['problems'][ $key ] = 'set more than once, to different values';
			return;
		}
		$out['defines'][ $key ] = $value;
	};
	$names   = array( T_STRING );
	if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
		$names[] = constant( 'T_NAME_FULLY_QUALIFIED' );
	}
	$count = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) ) {
			continue;
		}
		if ( T_VARIABLE === $token[0] && '$table_prefix' === $token[1] ) {
			$next = isset( $tokens[ $i + 1 ], $tokens[ $i + 2 ], $tokens[ $i + 3 ] ) ? array( $tokens[ $i + 1 ], $tokens[ $i + 2 ], $tokens[ $i + 3 ] ) : null;
			if ( null !== $next && '=' === $next[0] && ';' === $next[2] && is_array( $next[1] ) && T_CONSTANT_ENCAPSED_STRING === $next[1][0] && null !== sfk_literal( $next[1][1] ) ) {
				$prefix = sfk_literal( $next[1][1] );
				if ( null !== $out['prefix'] && $out['prefix'] !== $prefix ) {
					$out['problems']['table_prefix'] = 'set more than once, to different values';
				}
				$out['prefix'] = $prefix;
			} else {
				$out['problems']['table_prefix'] = 'not a plain text value';
			}
			continue;
		}
		if ( ! in_array( $token[0], $names, true ) || 'define' !== strtolower( ltrim( $token[1], '\\' ) ) || ! isset( $tokens[ $i + 1 ] ) || '(' !== $tokens[ $i + 1 ] ) {
			continue;
		}
		$args  = array( array() );
		$depth = 0;
		for ( $j = $i + 2; $j < $count; $j++ ) {
			$part = $tokens[ $j ];
			if ( '(' === $part || '[' === $part ) {
				++$depth;
			} elseif ( ')' === $part || ']' === $part ) {
				if ( 0 === $depth ) {
					break;
				}
				--$depth;
			} elseif ( ',' === $part && 0 === $depth ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $part;
		}
		$i = $j;
		if ( count( $args ) < 2 || 1 !== count( $args[0] ) || ! is_array( $args[0][0] ) || T_CONSTANT_ENCAPSED_STRING !== $args[0][0][0] ) {
			continue;
		}
		$key = sfk_literal( $args[0][0][1] );
		if ( null === $key ) {
			continue;
		}
		list( $plain, $value ) = sfk_plain( $args[1] );
		if ( ! $plain ) {
			$out['problems'][ $key ] = 'not a plain value';
			continue;
		}
		$set( $key, $value );
	}
	foreach ( array_keys( $out['problems'] ) as $key ) {
		unset( $out['defines'][ $key ] );
	}
	if ( isset( $out['problems']['table_prefix'] ) ) {
		$out['prefix'] = null;
	}
	return $out;
}

/**
 * wp-config.php for WordPress in $dir: there, or one folder up when that folder is not another
 * WordPress (the same rule WordPress follows).
 *
 * @param string $dir WordPress's main folder.
 */
function sfk_config_file( $dir ) {
	if ( is_file( $dir . '/wp-config.php' ) ) {
		return $dir . '/wp-config.php';
	}
	$up = dirname( $dir );
	if ( is_file( $up . '/wp-config.php' ) && ! is_file( $up . '/wp-settings.php' ) ) {
		return $up . '/wp-config.php';
	}
	return null;
}

/**
 * A path from the settings: relative to WordPress's main folder unless absolute.
 *
 * @param string $path Path.
 */
function sfk_path( $path ) {
	return ( '/' === substr( $path, 0, 1 ) || preg_match( '#^[A-Za-z]:/#', $path ) ) ? $path : __DIR__ . '/' . $path;
}

/**
 * Connects to the database from wp-config.php's values.
 *
 * @param array  $defines Values from wp-config.php.
 * @param string $error   Set to what went wrong.
 * @return mysqli|null
 */
function sfk_connect( array $defines, &$error ) {
	if ( ! class_exists( 'mysqli' ) ) {
		$error = 'This server\'s PHP has no MySQL support (mysqli), so the kit cannot reach the database.';
		return null;
	}
	foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $key ) {
		if ( ! isset( $defines[ $key ] ) || ! is_string( $defines[ $key ] ) ) {
			$error = 'wp-config.php does not set ' . $key . ' as plain text, so the kit cannot read it.';
			return null;
		}
	}
	$host   = $defines['DB_HOST'];
	$port   = 0;
	$socket = null;
	if ( preg_match( '/^\[(.+)\](?::([0-9]+))?$/', $host, $m ) ) {
		$host = $m[1];
		$port = isset( $m[2] ) ? (int) $m[2] : 0;
	} elseif ( false !== strpos( $host, ':' ) && 1 === substr_count( $host, ':' ) ) {
		list( $host, $rest ) = explode( ':', $host, 2 );
		if ( ctype_digit( $rest ) ) {
			$port = (int) $rest;
		} elseif ( '' !== $rest ) {
			$socket = $rest;
		}
	}
	if ( '' === $host ) {
		$host = 'localhost';
	}
	mysqli_report( MYSQLI_REPORT_OFF );
	$db = mysqli_init();
	$db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
	if ( ! @$db->real_connect( $host, $defines['DB_USER'], $defines['DB_PASSWORD'], $defines['DB_NAME'], $port, $socket ) ) {
		$error = 'The kit cannot connect to the database: ' . $db->connect_error;
		return null;
	}
	if ( ! $db->set_charset( 'utf8mb4' ) ) {
		$db->set_charset( 'utf8' );
	}
	return $db;
}

/**
 * Runs a prepared statement with string parameters.
 *
 * @param mysqli $db     Connection.
 * @param string $sql    SQL with ? placeholders.
 * @param array  $params String values.
 * @return array|bool Rows (each a list of values) for a SELECT, true otherwise; false on failure.
 */
function sfk_query( $db, $sql, array $params = array() ) {
	$stmt = $db->prepare( $sql );
	if ( false === $stmt ) {
		return false;
	}
	if ( array() !== $params ) {
		$types = str_repeat( 's', count( $params ) );
		$refs  = array( $types );
		foreach ( $params as $k => $value ) {
			$params[ $k ] = (string) $value;
			$refs[]       = &$params[ $k ];
		}
		call_user_func_array( array( $stmt, 'bind_param' ), $refs );
	}
	if ( ! $stmt->execute() ) {
		$stmt->close();
		return false;
	}
	$meta = $stmt->result_metadata();
	if ( false === $meta ) {
		$stmt->close();
		return true;
	}
	$fields = $meta->field_count;
	$row    = array_fill( 0, $fields, null );
	$bind   = array();
	foreach ( array_keys( $row ) as $k ) {
		$bind[] = &$row[ $k ];
	}
	call_user_func_array( array( $stmt, 'bind_result' ), $bind );
	$rows = array();
	while ( $stmt->fetch() ) {
		$rows[] = array_map(
			static function ( $value ) {
				return $value;
			},
			$row
		);
	}
	$stmt->close();
	return $rows;
}

/**
 * An option's stored value (as text), or null.
 *
 * @param mysqli $db   Connection.
 * @param string $p    Table prefix.
 * @param string $name Option name.
 */
function sfk_get( $db, $p, $name ) {
	$rows = sfk_query( $db, 'SELECT option_value FROM `' . $p . 'options` WHERE option_name = ? LIMIT 1', array( $name ) );
	return is_array( $rows ) && isset( $rows[0][0] ) ? (string) $rows[0][0] : null;
}

/**
 * Stores an option (text as WordPress stores it: arrays serialized by the caller).
 *
 * @param mysqli $db    Connection.
 * @param string $p     Table prefix.
 * @param string $name  Option name.
 * @param string $value Stored text.
 */
function sfk_set( $db, $p, $name, $value ) {
	if ( null === sfk_get( $db, $p, $name ) ) {
		return false !== sfk_query( $db, 'INSERT INTO `' . $p . 'options` (option_name, option_value, autoload) VALUES (?, ?, ?)', array( $name, $value, 'off' ) );
	}
	return false !== sfk_query( $db, 'UPDATE `' . $p . 'options` SET option_value = ? WHERE option_name = ?', array( $value, $name ) );
}

/**
 * A serialized value, or null (objects are never created).
 *
 * @param mixed $raw Stored text.
 */
function sfk_unserialize( $raw ) {
	if ( ! is_string( $raw ) || ! preg_match( '/^[aibsdN][:;]/', $raw ) ) {
		return null;
	}
	$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
	return false === $value && 'b:0;' !== $raw ? null : $value;
}

/**
 * The network's active plugins row: [meta_id, plugins], or null on a single site.
 *
 * @param mysqli $db Connection.
 * @param string $p  Table prefix.
 */
function sfk_network_plugins( $db, $p ) {
	$rows = sfk_query( $db, 'SELECT meta_id, meta_value FROM `' . $p . 'sitemeta` WHERE meta_key = ? ORDER BY site_id, meta_id LIMIT 1', array( 'active_sitewide_plugins' ) );
	if ( ! is_array( $rows ) || ! isset( $rows[0] ) ) {
		return null;
	}
	$plugins = sfk_unserialize( $rows[0][1] );
	return array( (string) $rows[0][0], is_array( $plugins ) ? $plugins : array() );
}

/**
 * The newest default theme installed (its folder name), or null.
 *
 * @param string $themes Themes folder.
 */
function sfk_default_theme( $themes ) {
	$known = array( 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty', 'twentynineteen', 'twentyseventeen', 'twentysixteen' );
	foreach ( $known as $slug ) {
		if ( is_file( $themes . '/' . $slug . '/style.css' ) ) {
			return $slug;
		}
	}
	return null;
}

/**
 * SiteFerry's backups: name, size, time; newest first.
 *
 * @param string $dir Backups folder.
 */
function sfk_backups( $dir ) {
	$list = array();
	foreach ( is_dir( $dir ) ? (array) scandir( $dir ) : array() as $name ) {
		if ( sfk_backup_name( $name ) && is_file( $dir . '/' . $name ) && ! is_link( $dir . '/' . $name ) ) {
			$list[] = array( $name, (int) filesize( $dir . '/' . $name ), (int) filemtime( $dir . '/' . $name ) );
		}
	}
	usort(
		$list,
		static function ( $a, $b ) {
			return $b[2] - $a[2];
		}
	);
	return $list;
}

/**
 * Whether a name is a SiteFerry backup's file name.
 *
 * @param mixed $name Name.
 */
function sfk_backup_name( $name ) {
	return is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.(tar(\.gz)?(\.enc)?|wpress)\z/', $name ) && false === strpos( $name, '..' );
}

/**
 * A size for people.
 *
 * @param int $bytes Bytes.
 */
function sfk_size( $bytes ) {
	$units = array( 'bytes', 'KB', 'MB', 'GB', 'TB' );
	$i     = 0;
	$size  = (float) $bytes;
	while ( $size >= 1024 && $i < 4 ) {
		$size /= 1024;
		++$i;
	}
	return ( 0 === $i ? (string) (int) $size : number_format( $size, 1 ) ) . ' ' . $units[ $i ];
}

/**
 * Sends the kit's headers: nothing cached, framed or indexed; no scripts.
 */
function sfk_headers() {
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-store, max-age=0' );
	header( 'X-Frame-Options: DENY' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Referrer-Policy: no-referrer' );
	header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
}

/**
 * Prints a page.
 *
 * @param string $title Title.
 * @param string $body  HTML (already escaped).
 */
function sfk_page( $title, $body ) {
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . sfk_h( $title ) . '</title><style>'
		. ':root{--ink:#12355b;--text:#1d2327;--soft:#50575e;--line:#c9d5e3;--fog:#e7f0fa;--accent:#1e5fa8;--bad:#c2352d;--badw:#fbe9e7;--good:#15803d;--goodw:#e6f4ea;--warn:#8a5300;--warnw:#fcf4e3}'
		. '*{box-sizing:border-box}body{margin:0;background:#f4f6f9;color:var(--text);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
		. 'main{max-width:760px;margin:0 auto;padding:24px 16px 48px}h1{margin:0 0 4px;font-size:22px;color:var(--ink)}h2{margin:0 0 8px;font-size:16px;color:var(--ink)}'
		. '.sub{margin:0 0 18px;color:var(--soft)}.card{margin:0 0 14px;padding:16px;border:1px solid var(--line);border-radius:8px;background:#fff}'
		. '.msg{margin:0 0 14px;padding:10px 14px;border-left:4px solid var(--accent);border-radius:0 6px 6px 0;background:var(--fog)}.msg.good{border-color:var(--good);background:var(--goodw)}.msg.bad{border-color:var(--bad);background:var(--badw)}.msg.warn{border-color:var(--warn);background:var(--warnw)}'
		. 'button{min-height:40px;padding:8px 14px;border:1px solid var(--accent);border-radius:6px;background:#fff;color:var(--accent);font:inherit;font-weight:600;cursor:pointer}button.main{background:var(--accent);color:#fff}button.danger{border-color:var(--bad);color:var(--bad)}button:focus-visible,input:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}'
		. 'form{margin:0}.row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}label{display:block;margin:0 0 4px;font-weight:600}input[type=password]{width:100%;max-width:360px;min-height:40px;padding:8px 10px;border:1px solid var(--soft);border-radius:6px;font:inherit}'
		. 'ul{margin:6px 0 10px;padding-left:20px}code{font-size:13px;overflow-wrap:anywhere}table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:8px 6px;border-top:1px solid var(--fog);text-align:left;vertical-align:middle}td.n{white-space:nowrap}'
		. '.soft{color:var(--soft);font-size:13px}a{color:var(--accent)}@media(max-width:560px){table,tbody,tr,td{display:block}tr{padding:6px 0;border-top:1px solid var(--fog)}td{border:0;padding:2px 0}th{display:none}}'
		. '</style></head><body><main>' . $body . '</main></body></html>';
}

/**
 * Prints a page that only says why the kit stops, and ends.
 *
 * @param string $message Message.
 */
function sfk_stop( $message ) {
	sfk_page( 'SiteFerry emergency kit', '<h1>SiteFerry emergency kit</h1><p class="msg bad">' . sfk_h( $message ) . '</p>' );
	exit;
}

/**
 * A POST form with one button.
 *
 * @param array  $c       Settings.
 * @param string $session Session token.
 * @param string $action  Action.
 * @param string $label   Button text.
 * @param string $class   Button class.
 * @param array  $fields  Extra hidden fields.
 */
function sfk_button( array $c, $session, $action, $label, $class = '', array $fields = array() ) {
	$html = '<form method="post"><input type="hidden" name="action" value="' . sfk_h( $action ) . '"><input type="hidden" name="token" value="' . sfk_h( sfk_csrf( $c, $session, $action ) ) . '">';
	foreach ( $fields as $name => $value ) {
		$html .= '<input type="hidden" name="' . sfk_h( $name ) . '" value="' . sfk_h( $value ) . '">';
	}
	return $html . '<button type="submit"' . ( '' === $class ? '' : ' class="' . sfk_h( $class ) . '"' ) . '>' . sfk_h( $label ) . '</button></form>';
}

/**
 * The lock file for wrong passwords.
 *
 * @param array $c Settings.
 */
function sfk_lock_file( array $c ) {
	return rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/sfk-' . $c['id'] . '.json';
}

/**
 * Wrong passwords so far: fails, until.
 *
 * @param array $c Settings.
 */
function sfk_lock( array $c ) {
	$file = sfk_lock_file( $c );
	$data = is_file( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
	return array(
		'fails' => is_array( $data ) && isset( $data['fails'] ) ? (int) $data['fails'] : 0,
		'until' => is_array( $data ) && isset( $data['until'] ) ? (int) $data['until'] : 0,
	);
}

/**
 * Saves the wrong-password count.
 *
 * @param array $c    Settings.
 * @param array $lock fails, until.
 */
function sfk_save_lock( array $c, array $lock ) {
	@file_put_contents( sfk_lock_file( $c ), json_encode( $lock ), LOCK_EX );
}

/**
 * The page.
 *
 * @param array $c Settings.
 */
function sfk_main( array $c ) {
	sfk_headers();
	$now = time();
	if ( basename( __FILE__ ) !== 'siteferry-emergency-' . $c['id'] . '.php' ) {
		sfk_stop( 'This file was renamed. Use it under the name it had when you downloaded it.' );
	}
	if ( $now > (int) $c['expires'] ) {
		sfk_stop( 'This emergency kit has expired. Delete it, and make a new one in SiteFerry (or ask your host for help).' );
	}
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	if ( ! hash_equals( (string) $c['host'], $host ) ) {
		sfk_stop( 'This kit was made for another site address. Open it at the address of the site it was made on.' );
	}
	if ( ! is_file( __DIR__ . '/wp-load.php' ) ) {
		sfk_stop( 'Upload this file into WordPress\'s main folder: the one with wp-load.php and the wp-admin folder.' );
	}

	$https   = ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] );
	$cookie  = 'sfk_' . $c['id'];
	$cookies = array(
		'path'     => '',
		'secure'   => $https,
		'httponly' => true,
		'samesite' => 'Strict',
	);

	// wp-config.php and the database come first: the session key needs the site's salts, and a
	// revoked kit (or one the site no longer knows) stops here, before any action, a download
	// included. When the database cannot be reached, the kit still opens, so backups can be saved.
	$db       = null;
	$p        = null;
	$db_error = '';
	$config   = sfk_config_file( __DIR__ );
	$parsed   = null;
	if ( null === $config ) {
		$db_error = 'There is no wp-config.php in this folder or the one above it.';
	} else {
		$parsed = sfk_parse_config( (string) file_get_contents( $config ) );
		$p      = $parsed['prefix'];
		if ( null === $p || ! preg_match( '/^[A-Za-z0-9_]{1,64}\z/', $p ) ) {
			$db_error = 'wp-config.php does not set $table_prefix as plain text, so the kit cannot find the tables.';
		} else {
			$db = sfk_connect( $parsed['defines'], $db_error );
		}
	}
	$network     = is_array( $parsed ) && ! empty( $parsed['defines']['MULTISITE'] );
	$c['server'] = sfk_server_secret( $parsed );
	if ( null !== $db ) {
		$state = sfk_revocation( sfk_unserialize( sfk_get( $db, $p, 'siteferry_emergency_kits' ) ), (string) $c['id'] );
		if ( 'ok' !== $state ) {
			setcookie( $cookie, '', array( 'expires' => 1 ) + $cookies );
			sfk_stop( 'revoked' === $state ? 'This kit was revoked in SiteFerry. Delete it from the server.' : 'This site no longer has a record of this kit (it was removed, or SiteFerry was reinstalled), so it does not work. Delete it from the server and make a new one in SiteFerry.' );
		}
	}

	$session = isset( $_COOKIE[ $cookie ] ) && sfk_session_valid( $c, $_COOKIE[ $cookie ], $now ) ? (string) $_COOKIE[ $cookie ] : '';
	$post    = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
	$action  = $post && isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? $_POST['action'] : '';
	$expires = 'This kit works until ' . gmdate( 'j M Y, H:i', (int) $c['expires'] ) . ' UTC.';

	// Signing in.
	if ( '' === $session ) {
		$notice = '';
		if ( 'login' === $action ) {
			$lock = sfk_lock( $c );
			if ( $lock['until'] > $now ) {
				$notice = '<p class="msg bad">Too many wrong passwords. Try again in ' . (int) ceil( ( $lock['until'] - $now ) / 60 ) . ' minutes.</p>';
			} elseif ( isset( $_POST['password'] ) && is_string( $_POST['password'] ) && password_verify( $_POST['password'], (string) $c['hash'] ) ) {
				sfk_save_lock( $c, array( 'fails' => 0, 'until' => 0 ) );
				$session = sfk_session_token( $c, $now + 3600 );
				setcookie( $cookie, $session, array( 'expires' => $now + 3600 ) + $cookies );
				$action = '';
			} else {
				usleep( random_int( 1000000, 2000000 ) );
				$lock['fails'] = $lock['fails'] + 1;
				if ( $lock['fails'] >= 5 ) {
					$lock = array( 'fails' => 0, 'until' => $now + 900 );
				}
				sfk_save_lock( $c, $lock );
				$notice = '<p class="msg bad">' . ( $lock['until'] > $now ? 'Too many wrong passwords. Try again in 15 minutes.' : 'That password is not right.' ) . '</p>';
			}
		}
		if ( '' === $session ) {
			sfk_page(
				'Sign in: SiteFerry emergency kit',
				'<h1>SiteFerry emergency kit</h1><p class="sub">For when this WordPress site does not open. ' . sfk_h( $expires ) . '</p>' . $notice
				. '<div class="card"><form method="post"><input type="hidden" name="action" value="login"><label for="sfk-password">Password of this kit</label>'
				. '<p class="row"><input type="password" id="sfk-password" name="password" autocomplete="current-password" required><button type="submit" class="main">Sign in</button></p></form>'
				. '<p class="soft">The password was chosen in SiteFerry when the kit was made.</p></div>'
			);
			return;
		}
	}

	// Every action needs this session's token for it.
	$notices = array();
	if ( $post && '' !== $action ) {
		if ( ! sfk_csrf_valid( $c, $session, $action, isset( $_POST['token'] ) ? $_POST['token'] : null ) ) {
			$notices[] = array( 'bad', 'This page was out of date, so nothing was done. Try again.' );
			$action    = '';
		}
	}
	if ( 'logout' === $action ) {
		setcookie( $cookie, '', array( 'expires' => 1 ) + $cookies );
		sfk_page( 'SiteFerry emergency kit', '<h1>SiteFerry emergency kit</h1><p class="msg">You are signed out. Delete this file from the server when you no longer need it.</p>' );
		return;
	}
	if ( 'delete' === $action ) {
		setcookie( $cookie, '', array( 'expires' => 1 ) + $cookies );
		@unlink( sfk_lock_file( $c ) );
		$gone = @unlink( __FILE__ );
		sfk_page(
			'SiteFerry emergency kit',
			'<h1>SiteFerry emergency kit</h1>' . ( $gone ? '<p class="msg good">This kit is deleted from the server.</p>' : '<p class="msg bad">This file could not delete itself. Delete it by FTP or in your hosting file manager.</p>' )
		);
		return;
	}

	$backups_dir = sfk_path( (string) $c['backups'] );
	if ( 'download' === $action ) {
		$name = isset( $_POST['name'] ) ? $_POST['name'] : null;
		$path = sfk_backup_name( $name ) ? $backups_dir . '/' . $name : '';
		$real = '' === $path ? false : realpath( $path );
		$base = realpath( $backups_dir );
		if ( false !== $real && false !== $base && dirname( $real ) === $base && is_file( $real ) && ! is_link( $path ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			@set_time_limit( 0 );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="' . $name . '"' );
			header( 'Content-Length: ' . (string) filesize( $real ) );
			$in = fopen( $real, 'rb' );
			while ( false !== $in && ! feof( $in ) && ! connection_aborted() ) {
				echo fread( $in, 1048576 );
				flush();
			}
			if ( false !== $in ) {
				fclose( $in );
			}
			exit;
		}
		$notices[] = array( 'bad', 'That backup is not there.' );
	}

	if ( null !== $db && in_array( $action, array( 'plugins', 'theme', 'undo' ), true ) ) {
		$before = sfk_unserialize( sfk_get( $db, $p, 'siteferry_emergency_before' ) );
		$before = is_array( $before ) ? $before : array();
		$own    = (string) $c['own_plugin'];
		if ( 'plugins' === $action ) {
			$active = sfk_unserialize( sfk_get( $db, $p, 'active_plugins' ) );
			$active = is_array( $active ) ? array_values( $active ) : array();
			if ( ! array_key_exists( 'active_plugins', $before ) ) {
				$before['active_plugins'] = $active;
			}
			$ok = sfk_set( $db, $p, 'active_plugins', serialize( in_array( $own, $active, true ) ? array( $own ) : array() ) );
			if ( $network ) {
				$row = sfk_network_plugins( $db, $p );
				if ( null !== $row ) {
					if ( ! array_key_exists( 'active_sitewide_plugins', $before ) ) {
						$before['active_sitewide_plugins'] = $row[1];
					}
					$keep = array_intersect_key( $row[1], array( $own => true ) );
					$ok   = $ok && false !== sfk_query( $db, 'UPDATE `' . $p . 'sitemeta` SET meta_value = ? WHERE meta_id = ?', array( serialize( $keep ), $row[0] ) );
				}
			}
			$before['time'] = $now;
			sfk_set( $db, $p, 'siteferry_emergency_before', serialize( $before ) );
			$notices[] = $ok ? array( 'good', 'All plugins except SiteFerry are turned off.' ) : array( 'bad', 'The plugins could not be changed: ' . $db->error );
		} elseif ( 'theme' === $action ) {
			$slug = sfk_default_theme( sfk_path( (string) $c['themes'] ) );
			if ( null === $slug ) {
				$notices[] = array( 'bad', 'No default WordPress theme (Twenty Twenty-Five or another "Twenty" theme) is installed.' );
			} else {
				if ( ! array_key_exists( 'template', $before ) ) {
					$before['template']   = sfk_get( $db, $p, 'template' );
					$before['stylesheet'] = sfk_get( $db, $p, 'stylesheet' );
				}
				$ok             = sfk_set( $db, $p, 'template', $slug ) && sfk_set( $db, $p, 'stylesheet', $slug );
				$before['time'] = $now;
				sfk_set( $db, $p, 'siteferry_emergency_before', serialize( $before ) );
				$notices[] = $ok ? array( 'good', 'The site now uses the theme ' . $slug . '.' ) : array( 'bad', 'The theme could not be changed: ' . $db->error );
			}
		} elseif ( array() === $before ) {
			$notices[] = array( 'warn', 'There is nothing to undo.' );
		} else {
			$ok = true;
			if ( array_key_exists( 'active_plugins', $before ) ) {
				$ok = sfk_set( $db, $p, 'active_plugins', serialize( (array) $before['active_plugins'] ) ) && $ok;
			}
			if ( array_key_exists( 'active_sitewide_plugins', $before ) && $network ) {
				$row = sfk_network_plugins( $db, $p );
				if ( null !== $row ) {
					$ok = false !== sfk_query( $db, 'UPDATE `' . $p . 'sitemeta` SET meta_value = ? WHERE meta_id = ?', array( serialize( (array) $before['active_sitewide_plugins'] ), $row[0] ) ) && $ok;
				}
			}
			foreach ( array( 'template', 'stylesheet' ) as $key ) {
				if ( isset( $before[ $key ] ) && is_string( $before[ $key ] ) ) {
					$ok = sfk_set( $db, $p, $key, $before[ $key ] ) && $ok;
				}
			}
			if ( $ok ) {
				sfk_query( $db, 'DELETE FROM `' . $p . 'options` WHERE option_name = ?', array( 'siteferry_emergency_before' ) );
				$notices[] = array( 'good', 'Undone: the plugins and the theme are as they were before.' );
			} else {
				$notices[] = array( 'bad', 'Undo did not finish: ' . $db->error );
			}
		}
	}

	// The page.
	$html  = '<h1>SiteFerry emergency kit</h1>';
	$html .= '<div class="row sub"><span>' . sfk_h( $expires ) . '</span>' . sfk_button( $c, $session, 'logout', 'Sign out' ) . '</div>';
	foreach ( $notices as $notice ) {
		$html .= '<p class="msg ' . sfk_h( $notice[0] ) . '">' . sfk_h( $notice[1] ) . '</p>';
	}
	if ( in_array( $action, array( 'plugins', 'theme' ), true ) ) {
		$html .= '<p class="msg">Now open <a href="wp-admin/">your site\'s dashboard</a>. If it works, open SiteFerry, choose a backup from before the problem and click Restore: it shows what will change first. Then come back here and delete this kit.</p>';
	}

	if ( null === $db ) {
		$html .= '<div class="card"><h2>The database cannot be reached</h2><p>' . sfk_h( $db_error ) . '</p>'
			. '<p>Check that the database server is running. If the database itself is damaged, restore it in your hosting control panel, or contact your host. You can still download SiteFerry\'s backups below.</p></div>';
	} else {
		$home   = sfk_get( $db, $p, 'home' );
		$active = sfk_unserialize( sfk_get( $db, $p, 'active_plugins' ) );
		$active = is_array( $active ) ? array_values( $active ) : array();
		$theme  = sfk_get( $db, $p, 'stylesheet' );
		$before = sfk_unserialize( sfk_get( $db, $p, 'siteferry_emergency_before' ) );

		$html .= '<div class="card"><h2>Site</h2><p>' . sfk_h( null === $home ? '(address unknown)' : $home ) . ( $network ? ' (a multisite network: the kit changes its main site and network-wide plugins)' : '' ) . '</p></div>';

		$html .= '<div class="card"><h2>Plugins</h2><p>' . count( $active ) . ' plugin' . ( 1 === count( $active ) ? ' is' : 's are' ) . ' turned on' . ( $network ? ' on the main site' : '' ) . '.</p>';
		if ( array() !== $active ) {
			$html .= '<ul>';
			foreach ( array_slice( $active, 0, 50 ) as $plugin ) {
				$html .= '<li><code>' . sfk_h( is_string( $plugin ) ? $plugin : '?' ) . '</code></li>';
			}
			$html .= '</ul>';
		}
		$html .= '<p class="soft">A broken plugin is the most common cause of a white screen. Turning them off keeps their settings; you can undo it here.</p>';
		$html .= sfk_button( $c, $session, 'plugins', 'Turn off all plugins except SiteFerry', 'main' ) . '</div>';

		$default = sfk_default_theme( sfk_path( (string) $c['themes'] ) );
		$html   .= '<div class="card"><h2>Theme</h2><p>Theme in use: <code>' . sfk_h( null === $theme ? '?' : $theme ) . '</code></p>';
		if ( null !== $default && $default !== $theme ) {
			$html .= sfk_button( $c, $session, 'theme', 'Switch to ' . $default );
		} elseif ( null === $default ) {
			$html .= '<p class="soft">No default WordPress theme is installed, so the kit cannot switch to one.</p>';
		}
		$html .= '</div>';

		if ( is_array( $before ) && array() !== $before ) {
			$html .= '<div class="card"><h2>Undo</h2><p>This kit changed ' . ( isset( $before['active_plugins'] ) && isset( $before['template'] ) ? 'the plugins and the theme' : ( isset( $before['template'] ) ? 'the theme' : 'the plugins' ) ) . ( isset( $before['time'] ) ? ' on ' . sfk_h( gmdate( 'j M Y, H:i', (int) $before['time'] ) ) . ' UTC' : '' ) . '.</p>'
				. sfk_button( $c, $session, 'undo', 'Undo: put them back as they were' ) . '</div>';
		}
		$html .= '<p class="soft">If the site uses a persistent object cache (Redis, Memcached), clear it in your hosting panel after a change, or the site may not see it yet.</p>';
	}

	$backups = sfk_backups( $backups_dir );
	$html   .= '<div class="card"><h2>SiteFerry backups on this server</h2>';
	if ( array() === $backups ) {
		$html .= '<p>None found' . ( is_dir( $backups_dir ) ? '.' : ' (SiteFerry\'s backups folder is not there).' ) . '</p>';
	} else {
		$html .= '<table><thead><tr><th scope="col">Backup</th><th scope="col">Size</th><th scope="col">Made (UTC)</th><th scope="col"><span class="soft">Download</span></th></tr></thead><tbody>';
		foreach ( $backups as $backup ) {
			$html .= '<tr><td><code>' . sfk_h( $backup[0] ) . '</code></td><td class="n">' . sfk_h( sfk_size( $backup[1] ) ) . '</td><td class="n">' . sfk_h( gmdate( 'j M Y, H:i', $backup[2] ) ) . '</td><td>' . sfk_button( $c, $session, 'download', 'Download', '', array( 'name' => $backup[0] ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
	}
	$html .= '</div>';

	$html .= '<div class="card"><h2>When this is not enough</h2><p>If WordPress itself is damaged (its files are missing or changed) or the database is broken, this kit cannot fix it. Download a backup here, then restore through your hosting control panel, or ask your host for help. Once WordPress opens again, restore with SiteFerry.</p></div>';
	$html .= '<div class="card"><h2>Done?</h2><p>Delete this kit from the server, so nobody else can use it.</p>' . sfk_button( $c, $session, 'delete', 'Delete this kit', 'danger' ) . '</div>';

	sfk_page( 'SiteFerry emergency kit', $html );
}

if ( defined( 'SFK_TEST' ) ) {
	return;
}
sfk_main( $sfk_config );

KIT;
	}
}
