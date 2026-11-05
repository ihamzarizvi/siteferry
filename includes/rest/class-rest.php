<?php
/**
 * REST API: siteferry/v1.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Rest;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Backups;
use SiteFerry\Check\Check;
use SiteFerry\Contents;
use SiteFerry\Database\Dumper;
use SiteFerry\Export\Export;
use SiteFerry\Import\Import;
use SiteFerry\Import\Upload;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Requirements;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;
use SiteFerry\Storage\Storage_Exception;
use SiteFerry\Storage\Transfer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes for the admin screen.
 *
 * Every route has a permission callback. Most require manage_options (with WordPress's REST
 * nonce, checked by the cookie authentication). The job routes also accept the job's own token
 * in the X-SiteFerry-Job header, compared with hash_equals(): an import replaces the users table,
 * after which the admin's login no longer exists, yet the job must be able to finish. The same
 * header lets these requests through SiteFerry's maintenance mode. Confirming an import needs
 * both the admin and the token.
 */
final class Rest {

	const NS = 'siteferry/v1';

	/** A job token only works while the job has made progress within this many seconds. */
	const TOKEN_MAX_AGE = 86400;

	const ID   = '(?P<id>[a-f0-9]{16})';
	const UP   = '(?P<id>[a-f0-9]{24})';
	const NAME = '(?P<name>[A-Za-z0-9._-]+)';
	const DEST = '(?P<dest>[a-z0-9]{1,20})';

	/**
	 * Hooks route registration.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function routes(): void {
		$admin   = array( self::class, 'can_manage' );
		$job     = array( self::class, 'can_drive_job' );
		$confirm = array( self::class, 'can_confirm' );
		$key     = array( self::class, 'can_receive' );

		$routes = array(
			array( '/status', WP_REST_Server::READABLE, 'status', $admin ),
			array( '/tables', WP_REST_Server::READABLE, 'tables', $admin ),
			array( '/backups', WP_REST_Server::READABLE, 'backups', $admin ),
			array( '/labels', WP_REST_Server::READABLE, 'labels', $admin ),
			array( '/labels/' . self::NAME, WP_REST_Server::CREATABLE, 'save_label', $admin ),
			array( '/backups/' . self::NAME, WP_REST_Server::DELETABLE, 'delete_backup', $admin ),
			array( '/backups/' . self::NAME . '/download', WP_REST_Server::READABLE, 'download', $admin ),
			array( '/backups/' . self::NAME . '/contents', WP_REST_Server::READABLE, 'contents_of', $admin ),
			array( '/checks', WP_REST_Server::READABLE, 'checks', $admin ),
			array( '/space', WP_REST_Server::READABLE, 'space', $admin ),
			array( '/backups/' . self::NAME . '/unlock', WP_REST_Server::CREATABLE, 'unlock_backup', $admin ),
			array( '/checks', WP_REST_Server::CREATABLE, 'start_check', $admin ),
			array( '/backups/' . self::NAME . '/file', WP_REST_Server::READABLE, 'backup_file', $admin ),
			array( '/exports', WP_REST_Server::CREATABLE, 'start_export', $admin ),
			array( '/imports', WP_REST_Server::CREATABLE, 'start_import', $admin ),
			array( '/jobs', WP_REST_Server::READABLE, 'jobs', $admin ),
			array( '/jobs/' . self::ID, WP_REST_Server::READABLE, 'job', $job ),
			array( '/jobs/' . self::ID . '/run', WP_REST_Server::CREATABLE, 'run', $job ),
			array( '/jobs/' . self::ID . '/confirm', WP_REST_Server::CREATABLE, 'confirm', $confirm ),
			array( '/jobs/' . self::ID . '/cancel', WP_REST_Server::CREATABLE, 'cancel', $job ),
			array( '/jobs/' . self::ID . '/log', WP_REST_Server::READABLE, 'log', $admin ),
			array( '/uploads', WP_REST_Server::READABLE, 'uploads', $admin ),
			array( '/uploads/new', WP_REST_Server::CREATABLE, 'start_upload', $admin ),
			array( '/uploads/' . self::UP, WP_REST_Server::READABLE, 'upload', $admin ),
			array( '/uploads/' . self::UP . '/chunk', WP_REST_Server::CREATABLE, 'chunk', $admin ),
			array( '/uploads/' . self::UP . '/finish', WP_REST_Server::CREATABLE, 'finish_upload', $admin ),
			array( '/uploads/' . self::UP . '/cancel', WP_REST_Server::CREATABLE, 'cancel_upload', $admin ),
			array( '/storage', WP_REST_Server::READABLE, 'storage', $admin ),
			array( '/storage/' . self::DEST, WP_REST_Server::CREATABLE, 'save_storage', $admin ),
			array( '/storage/' . self::DEST, WP_REST_Server::DELETABLE, 'remove_storage', $admin ),
			array( '/storage/' . self::DEST . '/test', WP_REST_Server::CREATABLE, 'test_storage', $admin ),
			array( '/storage/' . self::DEST . '/backups', WP_REST_Server::READABLE, 'remote_backups', $admin ),
			array( '/storage/' . self::DEST . '/backups/' . self::NAME, WP_REST_Server::DELETABLE, 'delete_remote', $admin ),
			array( '/storage/' . self::DEST . '/send', WP_REST_Server::CREATABLE, 'start_send', $admin ),
			array( '/storage/' . self::DEST . '/fetch', WP_REST_Server::CREATABLE, 'start_fetch', $admin ),
			array( '/storage/' . self::DEST . '/authorize', WP_REST_Server::CREATABLE, 'authorize_storage', $admin ),
			array( '/storage/' . self::DEST . '/connect', WP_REST_Server::CREATABLE, 'connect_storage', $admin ),
			array( '/storage/' . self::DEST . '/disconnect', WP_REST_Server::CREATABLE, 'disconnect_storage', $admin ),
			array( '/schedule', WP_REST_Server::READABLE, 'schedule', $admin ),
			array( '/schedule', WP_REST_Server::CREATABLE, 'save_schedule', $admin ),
			array( '/schedule/run', WP_REST_Server::CREATABLE, 'run_schedule', $admin ),
			array( '/undo/dismiss', WP_REST_Server::CREATABLE, 'dismiss_undo', $admin ),
			array( '/receive', WP_REST_Server::READABLE, 'receive_status', $admin ),
			array( '/receive', WP_REST_Server::CREATABLE, 'receive_key', $admin ),
			array( '/receive', WP_REST_Server::DELETABLE, 'receive_cancel', $admin ),
			array( '/move/check', WP_REST_Server::CREATABLE, 'move_check', $admin ),
			array( '/move/send', WP_REST_Server::CREATABLE, 'move_send', $admin ),
			// The site being moved here, with the key only (no login on this site).
			array( '/move/hello', WP_REST_Server::READABLE, 'move_hello', $key ),
			array( '/move/start', WP_REST_Server::CREATABLE, 'move_start', $key ),
			array( '/move/chunk', WP_REST_Server::CREATABLE, 'move_chunk', $key ),
			array( '/move/finish', WP_REST_Server::CREATABLE, 'move_finish', $key ),
		);

		// Several methods on one path become several endpoints of one route.
		$endpoints = array();
		foreach ( $routes as list( $path, $methods, $callback, $permission ) ) {
			$endpoints[ $path ][] = array(
				'methods'             => $methods,
				'callback'            => self::guard( $callback ),
				'permission_callback' => $permission,
			);
		}
		foreach ( $endpoints as $path => $list ) {
			register_rest_route( self::NS, $path, $list );
		}
	}

	/**
	 * Receiving a site: whether a key waits, how far a backup has arrived, what arrived.
	 */
	public static function receive_status(): array {
		return \SiteFerry\Move\Receiver::status();
	}

	/**
	 * Makes a new key for receiving a site (any earlier one stops working).
	 *
	 * @param WP_REST_Request $request Request with "hours" (optional).
	 */
	public static function receive_key( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		return \SiteFerry\Move\Receiver::create( isset( $params['hours'] ) ? (int) $params['hours'] : \SiteFerry\Move\Receiver::HOURS ) + \SiteFerry\Move\Receiver::status();
	}

	/**
	 * Drops the key and anything half-received.
	 */
	public static function receive_cancel(): array {
		\SiteFerry\Move\Receiver::cancel();
		return \SiteFerry\Move\Receiver::status();
	}

	/**
	 * Moving this site: what the key's site says about itself.
	 *
	 * @param WP_REST_Request $request Request with "key".
	 */
	public static function move_check( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		return \SiteFerry\Move\Sender::hello( \SiteFerry\Move\Sender::parse( isset( $params['key'] ) ? (string) $params['key'] : '' ) );
	}

	/**
	 * Moving this site: starts the backup that is then sent to the key's site.
	 *
	 * @param WP_REST_Request $request Request with "key" and "keep_local".
	 */
	public static function move_send( WP_REST_Request $request ): array {
		self::assert_idle();
		$params = (array) $request->get_json_params();
		list( $job, $token ) = \SiteFerry\Move\Sender::start( isset( $params['key'] ) ? (string) $params['key'] : '', ! empty( $params['keep_local'] ) );
		return array(
			'job'   => $job->summary(),
			'token' => $token,
		);
	}

	/**
	 * For the sending site (with the key): about this site.
	 */
	public static function move_hello(): array {
		return \SiteFerry\Move\Receiver::hello();
	}

	/**
	 * For the sending site: starts (or resumes) receiving its backup.
	 *
	 * @param WP_REST_Request $request Request with "name", "size" and "from".
	 */
	public static function move_start( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		return \SiteFerry\Move\Receiver::start( isset( $params['name'] ) ? (string) $params['name'] : '', isset( $params['size'] ) ? (int) $params['size'] : 0, isset( $params['from'] ) ? (string) $params['from'] : '' );
	}

	/**
	 * For the sending site: one chunk (raw body; X-SiteFerry-Offset and X-SiteFerry-Sha256).
	 *
	 * @param WP_REST_Request $request Request.
	 * @throws Job_Exception On a missing offset.
	 */
	public static function move_chunk( WP_REST_Request $request ): array {
		$offset = (string) $request->get_header( 'x_siteferry_offset' );
		if ( ! preg_match( '/^[0-9]{1,18}$/', $offset ) ) {
			throw new Job_Exception( 'Missing or invalid chunk offset.', Job_Exception::STEP );
		}
		return \SiteFerry\Move\Receiver::chunk( (int) $offset, $request->get_body(), (string) $request->get_header( 'x_siteferry_sha256' ) );
	}

	/**
	 * For the sending site: the backup is complete; it joins the list.
	 */
	public static function move_finish(): array {
		return \SiteFerry\Move\Receiver::finish();
	}

	/**
	 * Permission for the move routes: the key that "Receive a site" made, and nothing else.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function can_receive( WP_REST_Request $request ): bool {
		return \SiteFerry\Move\Receiver::accepts( (string) $request->get_header( 'x_siteferry_move' ) );
	}

	/**
	 * The password given, or the one kept for a backup that arrived with a move key.
	 *
	 * @param array  $params Request parameters.
	 * @param string $name   Backup name.
	 */
	private static function password( array $params, string $name ): string {
		if ( isset( $params['password'] ) && is_string( $params['password'] ) && '' !== $params['password'] ) {
			return $params['password'];
		}
		return (string) \SiteFerry\Move\Receiver::password_for( $name );
	}
	/**
	 * Permission: administrators.
	 */
	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission: administrators, or a request carrying this job's token.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function can_drive_job( WP_REST_Request $request ): bool {
		return self::can_manage() || null !== self::job_from_token( $request );
	}

	/**
	 * Permission: an administrator who also holds this job's token.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function can_confirm( WP_REST_Request $request ): bool {
		return self::can_manage() && null !== self::job_from_token( $request );
	}

	/**
	 * Limits and the active job, for the admin screen.
	 */
	public static function status(): array {
		global $wpdb;
		$active = Job::active( Storage::jobs_dir() );
		return array(
			'version'  => SITEFERRY_VERSION,
			'compress' => Requirements::can_compress(),
			'chunk'    => self::chunk_size(),
			'prefix'   => $wpdb->prefix,
			'home'     => home_url(),
			'active'   => null === $active ? null : $active->summary(),
			'undo'     => Import::undo_point(),
			'uploads'  => array_map(
				static function ( Upload $upload ): array {
					return $upload->to_array();
				},
				Upload::all( self::uploads_dir() )
			),
		);
	}

	/**
	 * Tables an export would include.
	 */
	public static function tables(): array {
		global $wpdb;
		return ( new Dumper( $wpdb ) )->tables( $wpdb->prefix );
	}

	/**
	 * Backups, newest first.
	 */
	public static function backups(): array {
		$list   = array();
		$labels = \SiteFerry\Labels::all();
		foreach ( Backups::in( Storage::backups_dir() ) as $backup ) {
			$manifest = $backup['manifest'];
			// The label set on this site; otherwise the one the backup was made with.
			$given    = isset( $manifest['options'] ) && is_array( $manifest['options'] ) ? $manifest['options'] : array();
			$label    = isset( $labels[ $backup['name'] ] ) ? $labels[ $backup['name'] ] : \SiteFerry\Labels::normalize( isset( $given['title'] ) ? $given['title'] : '', isset( $given['note'] ) ? $given['note'] : '', isset( $given['tags'] ) ? $given['tags'] : array() );
			$list[]   = array(
				'title'     => $label['title'],
				'note'      => $label['note'],
				'tags'      => $label['tags'],
				'name'      => $backup['name'],
				'size'      => $backup['size'],
				'modified'  => $backup['modified'],
				'created'   => null === $manifest ? null : $manifest['created'],
				'source'    => null === $manifest ? null : $manifest['site']['home'],
				'wordpress' => null === $manifest ? null : $manifest['versions']['wordpress'],
				'format'    => \SiteFerry\Wpress\Wpress::is_name( $backup['name'] ) ? 'wpress' : 'siteferry',
				'contents'  => null === $manifest ? null : self::contents( $manifest ),
				'error'     => $backup['error'],
				'encrypted' => $backup['encrypted'],
				// A backup moved here with a key opens without asking for a password.
				'unlocked'  => $backup['encrypted'] && null !== \SiteFerry\Move\Receiver::password_for( $backup['name'] ),
			);
		}
		return $list;
	}

	/**
	 * Every backup label (file name => title, note, tags), for backups on storage too.
	 */
	public static function labels(): array {
		return \SiteFerry\Labels::all();
	}

	/**
	 * Sets a backup's name, note and tags; all empty removes the label.
	 *
	 * @param WP_REST_Request $request Request with title, note and tags.
	 */
	public static function save_label( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		$label  = \SiteFerry\Labels::set(
			(string) $request['name'],
			isset( $params['title'] ) ? $params['title'] : '',
			isset( $params['note'] ) ? $params['note'] : '',
			isset( $params['tags'] ) ? $params['tags'] : array()
		);
		return array(
			'name'  => (string) $request['name'],
			'label' => $label,
		);
	}

	/**
	 * What a backup holds, in a few words ("Whole site", "Without media and plugins"), from the
	 * export options in its manifest; null when the manifest does not say (.wpress backups).
	 *
	 * @param array $manifest Manifest.
	 */
	private static function contents( array $manifest ): ?string {
		if ( ! isset( $manifest['options'] ) || ! is_array( $manifest['options'] ) || array() === $manifest['options'] ) {
			return null;
		}
		$options = $manifest['options'];
		$missing = array();
		foreach (
			array(
				'exclude_media'   => __( 'media', 'siteferry' ),
				'exclude_plugins' => __( 'plugins', 'siteferry' ),
				'exclude_themes'  => __( 'themes', 'siteferry' ),
			) as $key => $label
		) {
			if ( ! empty( $options[ $key ] ) ) {
				$missing[] = $label;
			}
		}
		if ( ! empty( $options['exclude_tables'] ) ) {
			$missing[] = __( 'some tables', 'siteferry' );
		}
		if ( array() === $missing ) {
			return __( 'Whole site', 'siteferry' );
		}
		/* translators: %s: list of left-out parts, such as "media and plugins" */
		return sprintf( __( 'Without %s', 'siteferry' ), wp_sprintf_l( '%l', $missing ) );
	}

	/**
	 * Deletes a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function delete_backup( WP_REST_Request $request ): array {
		$path = Backups::path( Storage::backups_dir(), (string) $request['name'] );
		if ( ! Io::quiet(
			static function () use ( $path ) {
				return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		) ) {
			throw new Job_Exception( 'The backup could not be deleted.', Job_Exception::STEP );
		}
		Contents::forget( (string) $request['name'] );
		Check::forget( (string) $request['name'], Check::LOCAL );
		\SiteFerry\Move\Receiver::forget_backup( (string) $request['name'] );
		return array( 'deleted' => (string) $request['name'] );
	}

	/**
	 * Streams a backup (supports Range) and ends the request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function download( WP_REST_Request $request ): void {
		$path = Backups::path( Storage::backups_dir(), (string) $request['name'] );
		$part = (int) $request->get_param( 'part' );
		if ( $part > 0 ) {
			// One part of the backup ("<name>.001" and so on), for disks and uploads with a size limit.
			$pieces = \SiteFerry\Storage\Parts::pieces( basename( $path ), (int) filesize( $path ), \SiteFerry\Storage\Parts::normalize_mb( $request->get_param( 'part_mb' ) ) * 1048576 );
			if ( ! isset( $pieces[ $part - 1 ] ) ) {
				throw new Job_Exception( 'There is no such part.', Job_Exception::NOT_FOUND );
			}
			list( $file_name, $offset, $length ) = $pieces[ $part - 1 ];
			Backups::stream( $path, $request->get_header( 'range' ), $offset, $length, $file_name );
		}
		Backups::stream( $path, $request->get_header( 'range' ) );
	}

	/**
	 * What is inside a backup on this server: one folder of it ("dir", '' for the top), and at the
	 * top the database's tables. The first calls read the backup's headers (a .tar.gz is
	 * decompressed); until that is done, "complete" is false and the page asks again.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function contents_of( WP_REST_Request $request ): array {
		$path  = Backups::path( Storage::backups_dir(), (string) $request['name'] );
		$key   = self::unlocked( $request );
		$index = Contents::index( $path, min( Contents::SECONDS, self::slice_seconds() ), $key );
		$out   = array(
			'name'     => (string) $request['name'],
			'complete' => $index['complete'],
			'read'     => $index['read'],
			'size'     => $index['size'],
			'count'    => count( $index['entries'] ),
			'dir'      => '',
			'entries'  => array(),
			'tables'   => null,
		);
		if ( ! $index['complete'] ) {
			return $out;
		}
		$out['dir']     = trim( (string) $request->get_param( 'dir' ), '/' );
		$out['entries'] = Contents::listing( $index['entries'], $out['dir'] );
		if ( '' === $out['dir'] ) {
			try {
				$manifest      = Backups::manifest( $path, $key );
				$out['tables'] = array_map(
					static function ( array $table ): array {
						return array(
							'name' => (string) $table['name'],
							'rows' => (int) $table['rows'],
						);
					},
					(array) $manifest['database']['tables']
				);
				$out['prefix'] = (string) $manifest['database']['prefix'];
			} catch ( \Throwable $e ) {
				$out['tables'] = null; // A .wpress backup lists its tables only once it is imported.
			}
		}
		return $out;
	}

	/**
	 * Opens a password-protected backup on this server for "Look inside": checks the password and
	 * returns a token that stands for the key for 15 minutes (the key is kept encrypted with the
	 * site's key; the password is not kept).
	 *
	 * @param WP_REST_Request $request Request with "password".
	 */
	public static function unlock_backup( WP_REST_Request $request ): array {
		$name   = (string) $request['name'];
		$path   = Backups::path( Storage::backups_dir(), $name );
		$params = (array) $request->get_json_params();
		$key    = \SiteFerry\Archive\Crypto::unlock( $path, self::password( $params, $name ) );
		$token  = Storage::random_hex( 16 );
		set_transient( 'siteferry_unlock_' . $token, \SiteFerry\Security\Secrets::site()->encrypt( $name . "\n" . base64_encode( $key ) ), 15 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return array(
			'unlock'  => $token,
			'minutes' => 15,
		);
	}

	/**
	 * The key an "unlock" token stands for, if it was made for the backup in the route.
	 *
	 * @param WP_REST_Request $request Request with "unlock".
	 */
	private static function unlocked( WP_REST_Request $request ): ?string {
		$token = (string) $request->get_param( 'unlock' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$sealed = get_transient( 'siteferry_unlock_' . $token );
		if ( ! is_string( $sealed ) ) {
			return null;
		}
		try {
			list( $name, $key ) = array_pad( explode( "\n", \SiteFerry\Security\Secrets::site()->decrypt( $sealed ), 2 ), 2, '' );
		} catch ( \Throwable $e ) {
			return null;
		}
		$key = base64_decode( $key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return $name === (string) $request['name'] && false !== $key ? $key : null;
	}

	/**
	 * The space report ("fresh" measures again). Until the site is measured, "complete" is false
	 * and the page asks again.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function space( WP_REST_Request $request ): array {
		return \SiteFerry\Space::report( min( 15.0, self::slice_seconds() ), (bool) $request->get_param( 'fresh' ) );
	}

	/**
	 * Outcomes of the checks of backup copies: name => place => { time, ok, size, summary }.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 */
	public static function checks( WP_REST_Request $request ): array {
		unset( $request );
		return Check::all();
	}

	/**
	 * Starts checking one copy of a backup: "name", and "place" ("local" or a storage id).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function start_check( WP_REST_Request $request ): array {
		self::assert_idle();
		$params = (array) $request->get_json_params();
		list( $job, $token ) = Check::start(
			isset( $params['name'] ) ? (string) $params['name'] : '',
			isset( $params['place'] ) ? (string) $params['place'] : Check::LOCAL,
			self::password( $params, isset( $params['name'] ) ? (string) $params['name'] : '' )
		);
		return array(
			'job'   => $job->summary(),
			'token' => $token,
		);
	}

	/**
	 * Sends one file from inside a backup on this server ("path" as listed) and ends the request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @throws Job_Exception If there is no such file.
	 */
	public static function backup_file( WP_REST_Request $request ): void {
		$path  = Backups::path( Storage::backups_dir(), (string) $request['name'] );
		$inner = trim( (string) $request->get_param( 'path' ), '/' );
		if ( '' === $inner || strlen( $inner ) > 4096 ) {
			throw new Job_Exception( 'That file is not in the backup.', Job_Exception::NOT_FOUND );
		}
		Contents::stream( $path, $inner, self::unlocked( $request ) );
	}

	/**
	 * Starts an export.
	 *
	 * @param WP_REST_Request $request Request with export options as JSON.
	 */
	public static function start_export( WP_REST_Request $request ): array {
		self::assert_idle();
		$params  = (array) $request->get_json_params();
		$options = array_intersect_key( $params, array_flip( array( 'title', 'note', 'tags', 'format', 'compress', 'exclude_media', 'exclude_plugins', 'exclude_themes', 'exclude_inactive_plugins', 'exclude_inactive_themes', 'exclude_cache', 'exclude_spam_comments', 'exclude_revisions', 'exclude_tables', 'send_to', 'delete_after_send', 'password' ) ) );
		list( $job, $token ) = Export::start( $options );
		return array(
			'job'   => $job->summary(),
			'token' => $token,
		);
	}

	/**
	 * Starts an import of a stored backup.
	 *
	 * @param WP_REST_Request $request Request with "backup" and import options as JSON.
	 */
	public static function start_import( WP_REST_Request $request ): array {
		self::assert_idle();
		$params  = (array) $request->get_json_params();
		$path    = Backups::path( Storage::backups_dir(), isset( $params['backup'] ) ? (string) $params['backup'] : '' );
		$options = array_intersect_key( $params, array_flip( array( 'replace_url', 'include_mu_plugins', 'include_drop_ins', 'force', 'safety', 'restore_database', 'restore_tables', 'restore_files', 'restore_paths', 'password' ) ) );
		$options['password'] = self::password( $params, basename( $path ) );
		list( $job, $token ) = Import::start( $path, $options );
		return array(
			'job'   => $job->summary(),
			'token' => $token,
		);
	}

	/**
	 * Recent jobs.
	 */
	public static function jobs(): array {
		return array_map(
			static function ( Job $job ): array {
				return $job->summary();
			},
			array_slice( Job::all( Storage::jobs_dir() ), 0, 30 )
		);
	}

	/**
	 * One job.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function job( WP_REST_Request $request ): array {
		return self::load_job( $request )->summary();
	}

	/**
	 * Runs one slice of a job (up to 15 seconds of work) and reports progress.
	 *
	 * An optional "seconds" parameter asks for a shorter slice (for proxies with short timeouts);
	 * it can never raise the server's own limit.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function run( WP_REST_Request $request ): array {
		$job     = self::load_job( $request );
		$seconds = self::slice_seconds();
		$asked   = $request->get_param( 'seconds' );
		if ( is_numeric( $asked ) ) {
			$seconds = max( 0.0, min( $seconds, (float) $asked ) );
		}
		ignore_user_abort( true );
		Runner::run_slice( $job, new Slice_Budget( $seconds ) );
		Schedule::after_slice( $job ); // A scheduled backup asks for its next slice itself.
		return $job->summary();
	}

	/**
	 * The backup schedule: settings, next and last run, the running scheduled backup.
	 */
	public static function schedule(): array {
		return Schedule::overview();
	}

	/**
	 * Saves the backup schedule and plans its next run.
	 *
	 * @param WP_REST_Request $request Request with the settings as JSON.
	 */
	public static function save_schedule( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		Schedule::save( array_intersect_key( $params, Schedule::DEFAULTS ), isset( $params['password'] ) && is_string( $params['password'] ) ? $params['password'] : null );
		return Schedule::overview();
	}

	/**
	 * Starts the scheduled backup now, in the background (the page only watches it).
	 */
	public static function run_schedule(): array {
		$job = Schedule::start_job();
		Schedule::after_slice( $job ); // Hands it to the site's own next request.
		return Schedule::overview() + array( 'job' => $job->summary() );
	}

	/**
	 * Confirms an import that is waiting after its inspection; "safety" (JSON, optional) says
	 * whether to make a safety backup of the site first.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function confirm( WP_REST_Request $request ): array {
		$job    = self::load_job( $request );
		$params = (array) $request->get_json_params();
		Import::confirm( $job, array_key_exists( 'safety', $params ) ? (bool) $params['safety'] : null );
		return $job->summary();
	}

	/**
	 * Forgets the offer to undo the last restore (the safety backup stays in the list).
	 */
	public static function dismiss_undo(): array {
		delete_option( Import::UNDO_OPTION );
		return array( 'undo' => null );
	}

	/**
	 * Cancels an unfinished job (its work is undone).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function cancel( WP_REST_Request $request ): array {
		$job = self::load_job( $request );
		Runner::cancel( $job );
		return $job->summary();
	}

	/**
	 * The end of a job's log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function log( WP_REST_Request $request ): array {
		$job  = self::load_job( $request );
		$file = $job->path( 'job.log' );
		$size = is_file( $file ) ? (int) filesize( $file ) : 0;
		$text = '';
		if ( $size > 0 ) {
			$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fseek( $handle, max( 0, $size - 262144 ) );
			$text = (string) stream_get_contents( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return array(
			'id'        => $job->id(),
			'log'       => $text,
			'truncated' => $size > 262144,
		);
	}

	/**
	 * Unfinished uploads (to resume after a page reload).
	 */
	public static function uploads(): array {
		return array_map(
			static function ( Upload $upload ): array {
				return $upload->to_array();
			},
			Upload::all( self::uploads_dir() )
		);
	}

	/**
	 * Starts an upload.
	 *
	 * @param WP_REST_Request $request Request with "name" and "size".
	 */
	public static function start_upload( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		return Upload::create( self::uploads_dir(), isset( $params['name'] ) ? (string) $params['name'] : '', isset( $params['size'] ) ? (int) $params['size'] : 0 )->to_array();
	}

	/**
	 * One upload.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function upload( WP_REST_Request $request ): array {
		return Upload::load( self::uploads_dir(), (string) $request['id'] )->to_array();
	}

	/**
	 * Receives one chunk as the raw request body.
	 *
	 * Headers: X-SiteFerry-Offset (byte offset) and X-SiteFerry-Sha256 (hex SHA-256 of the body).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function chunk( WP_REST_Request $request ): array {
		$upload = Upload::load( self::uploads_dir(), (string) $request['id'] );
		$offset = (string) $request->get_header( 'x_siteferry_offset' );
		if ( ! preg_match( '/^[0-9]{1,18}$/', $offset ) ) {
			throw new Job_Exception( 'Missing or invalid chunk offset.', Job_Exception::STEP );
		}
		ignore_user_abort( true );
		$upload->append( (int) $offset, $request->get_body(), (string) $request->get_header( 'x_siteferry_sha256' ) );
		return $upload->to_array();
	}

	/**
	 * Checks a complete upload and turns it into a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function finish_upload( WP_REST_Request $request ): array {
		$upload = Upload::load( self::uploads_dir(), (string) $request['id'] );
		return array( 'backup' => $upload->finish( Storage::backups_dir() ) );
	}

	/**
	 * Abandons an upload.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function cancel_upload( WP_REST_Request $request ): array {
		Upload::load( self::uploads_dir(), (string) $request['id'] )->delete();
		return array( 'cancelled' => (string) $request['id'] );
	}

	/**
	 * Storage destinations: settings without secrets, and which secrets are saved.
	 */
	public static function storage(): array {
		return Destinations::overview();
	}

	/**
	 * Saves a destination's settings (JSON body). Empty secrets keep the saved ones.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function save_storage( WP_REST_Request $request ): array {
		Destinations::save( (string) $request['dest'], (array) $request->get_json_params() );
		return self::destination( (string) $request['dest'] );
	}

	/**
	 * Removes a destination's settings and secrets.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function remove_storage( WP_REST_Request $request ): array {
		Destinations::remove( (string) $request['dest'] );
		return self::destination( (string) $request['dest'] );
	}

	/**
	 * Tests the saved settings of a destination.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function test_storage( WP_REST_Request $request ): array {
		return array( 'lines' => Destinations::driver( (string) $request['dest'] )->test() );
	}

	/**
	 * Backups stored at a destination.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function remote_backups( WP_REST_Request $request ): array {
		$driver = Destinations::driver( (string) $request['dest'] );
		return array(
			'where'   => $driver->describe(),
			'backups' => \SiteFerry\Storage\Parts::group( $driver->list() ), // A set of parts is one backup.
		);
	}

	/**
	 * Deletes a backup at a destination.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function delete_remote( WP_REST_Request $request ): array {
		$name = (string) $request['name'];
		if ( ! preg_match( Backups::SAFE_NAME, $name ) ) {
			throw new Job_Exception( 'Unknown backup.', Job_Exception::NOT_FOUND );
		}
		\SiteFerry\Storage\Parts::delete( Destinations::driver( (string) $request['dest'] ), $name );
		Check::forget( $name, (string) $request['dest'] );
		return array( 'deleted' => $name );
	}

	/**
	 * Starts sending a stored backup to a destination.
	 *
	 * @param WP_REST_Request $request Request with "backup".
	 */
	public static function start_send( WP_REST_Request $request ): array {
		self::assert_idle();
		$params = (array) $request->get_json_params();
		$path   = Backups::path( Storage::backups_dir(), isset( $params['backup'] ) ? (string) $params['backup'] : '' );
		list( $job, $token ) = Transfer::send( (string) $request['dest'], $path );
		return array(
			'job'   => $job->summary(),
			'token' => $token,
		);
	}

	/**
	 * Starts downloading a backup from a destination.
	 *
	 * @param WP_REST_Request $request Request with "name".
	 */
	public static function start_fetch( WP_REST_Request $request ): array {
		self::assert_idle();
		$params = (array) $request->get_json_params();
		list( $job, $token ) = Transfer::fetch( (string) $request['dest'], isset( $params['name'] ) ? (string) $params['name'] : '' );
		return array(
			'job'   => $job->summary(),
			'token' => $token,
		);
	}

	/**
	 * Starts connecting an OAuth storage; the browser then goes to the returned consent URL.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function authorize_storage( WP_REST_Request $request ): array {
		return Destinations::authorize( (string) $request['dest'] );
	}

	/**
	 * Finishes connecting an OAuth storage with the code and state the provider sent back to the
	 * SiteFerry page. Only this authenticated POST (administrator, REST nonce, matching one-time
	 * state) saves tokens; the provider's GET redirect itself changes nothing.
	 *
	 * @param WP_REST_Request $request Request with "code" and "state".
	 */
	public static function connect_storage( WP_REST_Request $request ): array {
		$params = (array) $request->get_json_params();
		Destinations::connect(
			(string) $request['dest'],
			isset( $params['code'] ) && is_string( $params['code'] ) ? $params['code'] : '',
			isset( $params['state'] ) && is_string( $params['state'] ) ? $params['state'] : ''
		);
		return self::destination( (string) $request['dest'] );
	}

	/**
	 * Revokes an OAuth connection; the settings stay.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function disconnect_storage( WP_REST_Request $request ): array {
		Destinations::disconnect( (string) $request['dest'] );
		return self::destination( (string) $request['dest'] );
	}

	/**
	 * Chunk size for uploads: about 5 MB, but always below post_max_size.
	 */
	public static function chunk_size(): int {
		$post  = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$chunk = 5242880;
		if ( $post > 0 ) {
			$chunk = min( $chunk, $post - 262144 );
		}
		return max( 262144, min( $chunk, Upload::MAX_CHUNK ) );
	}

	/**
	 * Wraps a handler: maps SiteFerry exceptions to REST errors with fitting status codes.
	 *
	 * @param string $method Handler name.
	 * @return callable
	 */
	private static function guard( string $method ): callable {
		return static function ( WP_REST_Request $request ) use ( $method ) {
			try {
				$result = call_user_func( array( self::class, $method ), $request );
				return $result instanceof WP_REST_Response ? $result : new WP_REST_Response( $result );
			} catch ( Job_Exception $e ) {
				$status = array(
					Job_Exception::NOT_FOUND => 404,
					Job_Exception::BUSY      => 409,
				);
				return new WP_Error( 'siteferry_error', $e->getMessage(), array( 'status' => isset( $status[ $e->getCode() ] ) ? $status[ $e->getCode() ] : 400 ) );
			} catch ( Archive_Exception $e ) {
				// A password is needed (or was wrong): the admin screen asks for it.
				return new WP_Error( Archive_Exception::LOCKED === $e->getCode() ? 'siteferry_locked' : 'siteferry_error', $e->getMessage(), array( 'status' => 400 ) );
			} catch ( Storage_Exception $e ) {
				// Never 401/403 here: those would read as "your login expired" to the admin screen.
				$status = Storage_Exception::NOT_FOUND === $e->getCode() ? 404 : ( Storage_Exception::TRANSIENT === $e->getCode() ? 502 : 400 );
				return new WP_Error( 'siteferry_storage_error', $e->getMessage(), array( 'status' => $status ) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'siteferry_error', $e->getMessage(), array( 'status' => 500 ) );
			}
		};
	}

	/**
	 * One destination's overview entry.
	 *
	 * @param string $id Destination id.
	 */
	private static function destination( string $id ): array {
		foreach ( Destinations::overview() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		throw new Job_Exception( 'Unknown storage.', Job_Exception::NOT_FOUND );
	}

	/**
	 * The job named in the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @throws Job_Exception If it does not exist.
	 */
	private static function load_job( WP_REST_Request $request ): Job {
		return Job::load( Storage::jobs_dir(), (string) $request['id'] );
	}

	/**
	 * The job named in the route if the request carries its valid, recently used token.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function job_from_token( WP_REST_Request $request ): ?Job {
		$token = (string) $request->get_header( 'x_siteferry_job' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		try {
			$job = self::load_job( $request );
		} catch ( Job_Exception $e ) {
			return null;
		}
		if ( ! $job->check_token( $token ) || time() - $job->updated() > self::TOKEN_MAX_AGE ) {
			return null;
		}
		return $job;
	}

	/**
	 * Refuses to start a job while another one is active.
	 *
	 * @throws Job_Exception If a job is active.
	 */
	private static function assert_idle(): void {
		$active = Job::active( Storage::jobs_dir() );
		if ( null !== $active ) {
			throw new Job_Exception( sprintf( 'Another %s is still in progress (job %s). Finish or cancel it first.', $active->type(), $active->id() ), Job_Exception::BUSY );
		}
	}

	/**
	 * Seconds of work per slice: 15, or half of a short max_execution_time.
	 */
	private static function slice_seconds(): float {
		Io::quiet(
			static function () {
				return set_time_limit( 120 );
			}
		);
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return 15.0;
		}
		return (float) max( 3, min( 15, intdiv( $limit, 2 ) ) );
	}

	/**
	 * Folder for upload sessions.
	 */
	private static function uploads_dir(): string {
		return Storage::ensure( Storage::root() . '/uploads' );
	}
}
