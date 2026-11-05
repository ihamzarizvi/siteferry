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

		$routes = array(
			array( '/status', WP_REST_Server::READABLE, 'status', $admin ),
			array( '/tables', WP_REST_Server::READABLE, 'tables', $admin ),
			array( '/backups', WP_REST_Server::READABLE, 'backups', $admin ),
			array( '/labels', WP_REST_Server::READABLE, 'labels', $admin ),
			array( '/labels/' . self::NAME, WP_REST_Server::CREATABLE, 'save_label', $admin ),
			array( '/backups/' . self::NAME, WP_REST_Server::DELETABLE, 'delete_backup', $admin ),
			array( '/backups/' . self::NAME . '/download', WP_REST_Server::READABLE, 'download', $admin ),
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
		return array( 'deleted' => (string) $request['name'] );
	}

	/**
	 * Streams a backup (supports Range) and ends the request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function download( WP_REST_Request $request ): void {
		$path = Backups::path( Storage::backups_dir(), (string) $request['name'] );
		Backups::stream( $path, $request->get_header( 'range' ) );
	}

	/**
	 * Starts an export.
	 *
	 * @param WP_REST_Request $request Request with export options as JSON.
	 */
	public static function start_export( WP_REST_Request $request ): array {
		self::assert_idle();
		$params  = (array) $request->get_json_params();
		$options = array_intersect_key( $params, array_flip( array( 'title', 'note', 'tags', 'format', 'compress', 'exclude_media', 'exclude_plugins', 'exclude_themes', 'exclude_inactive_plugins', 'exclude_inactive_themes', 'exclude_cache', 'exclude_spam_comments', 'exclude_revisions', 'exclude_tables', 'send_to', 'delete_after_send' ) ) );
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
		$options = array_intersect_key( $params, array_flip( array( 'replace_url', 'include_mu_plugins', 'include_drop_ins', 'force' ) ) );
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
		Schedule::save( array_intersect_key( (array) $request->get_json_params(), Schedule::DEFAULTS ) );
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
	 * Confirms an import that is waiting after its inspection.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function confirm( WP_REST_Request $request ): array {
		$job = self::load_job( $request );
		Import::confirm( $job );
		return $job->summary();
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
			'backups' => $driver->list(),
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
		Destinations::driver( (string) $request['dest'] )->delete( $name );
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
				return new WP_Error( 'siteferry_error', $e->getMessage(), array( 'status' => 400 ) );
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
