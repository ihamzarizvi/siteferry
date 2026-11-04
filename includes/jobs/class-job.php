<?php
/**
 * A long-running export or import, stored in files.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Jobs;

use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Job state lives in <jobs folder>/<id>/job.json, never in the database, because an import
 * replaces the database while the job runs. Each job has its own random token, so an import
 * can be finished after the users table has been replaced.
 *
 * Files in the job folder: job.json (state), job.log (log), lock (slice lock) and whatever
 * temporary files the steps create.
 */
final class Job {

	const STATUS_PENDING = 'pending';
	const STATUS_RUNNING = 'running';
	const STATUS_WAITING = 'waiting';
	const STATUS_DONE    = 'done';
	const STATUS_FAILED  = 'failed';

	/** Warnings kept in job.json for display; all of them are in job.log. */
	const MAX_KEPT_WARNINGS = 200;

	/**
	 * Job folder.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Saved state.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Lock file handle while a slice runs.
	 *
	 * @var resource|null
	 */
	private $lock = null;

	/**
	 * Constructor.
	 *
	 * @param string $dir  Job folder.
	 * @param array  $data State.
	 */
	private function __construct( string $dir, array $data ) {
		$this->dir  = $dir;
		$this->data = $data;
	}

	/**
	 * Creates a job folder and its state file.
	 *
	 * @param string   $base    Folder that holds job folders.
	 * @param string   $type    "export" or "import".
	 * @param string[] $steps   Step names, run in order.
	 * @param array    $options Job options (JSON-safe).
	 * @return array{0:Job,1:string} The job and its token (shown once; only a hash is stored).
	 * @throws Job_Exception If the folder cannot be created.
	 */
	public static function create( string $base, string $type, array $steps, array $options ): array {
		$id    = bin2hex( random_bytes( 8 ) );
		$token = bin2hex( random_bytes( 32 ) );
		$dir   = rtrim( $base, '/\\' ) . '/' . $id;

		$made = Io::quiet(
			static function () use ( $dir ) {
				return mkdir( $dir, 0755, true );
			}
		);
		if ( ! $made ) {
			throw new Job_Exception( 'Cannot create the job folder.', Job_Exception::INVALID );
		}

		$now = time();
		$job = new self(
			$dir,
			array(
				'version'       => 1,
				'id'            => $id,
				'type'          => $type,
				'status'        => self::STATUS_PENDING,
				'created'       => $now,
				'updated'       => $now,
				'token_hash'    => hash( 'sha256', $token ),
				'steps'         => array_values( $steps ),
				'step'          => 0,
				'options'       => $options,
				'state'         => array(),
				'progress'      => array(
					'message' => '',
					'done'    => 0,
					'total'   => 0,
				),
				'warnings'      => array(),
				'warning_count' => 0,
				'error'         => null,
				'result'        => array(),
			)
		);
		$job->save();
		$job->log( 'info', sprintf( '%s job created.', ucfirst( $type ) ) );
		return array( $job, $token );
	}

	/**
	 * Loads a job.
	 *
	 * @param string $base Folder that holds job folders.
	 * @param string $id   Job id.
	 * @throws Job_Exception If the id is malformed or the job is missing or damaged.
	 */
	public static function load( string $base, string $id ): self {
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
			throw new Job_Exception( 'Unknown job.', Job_Exception::NOT_FOUND );
		}
		$dir  = rtrim( $base, '/\\' ) . '/' . $id;
		$json = is_file( $dir . '/job.json' ) ? file_get_contents( $dir . '/job.json' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $json ) {
			throw new Job_Exception( 'Unknown job.', Job_Exception::NOT_FOUND );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || 1 !== ( isset( $data['version'] ) ? $data['version'] : 0 ) || $id !== $data['id'] ) {
			throw new Job_Exception( 'The job state file is damaged.', Job_Exception::INVALID );
		}
		return new self( $dir, $data );
	}

	/**
	 * Job id.
	 */
	public function id(): string {
		return $this->data['id'];
	}

	/**
	 * "export" or "import".
	 */
	public function type(): string {
		return $this->data['type'];
	}

	/**
	 * Job folder.
	 */
	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Path of a file in the job folder.
	 *
	 * @param string $name File name.
	 */
	public function path( string $name ): string {
		return $this->dir . '/' . $name;
	}

	/**
	 * Creation time.
	 */
	public function created(): int {
		return (int) $this->data['created'];
	}

	/**
	 * Time of the last save.
	 */
	public function updated(): int {
		return (int) $this->data['updated'];
	}

	/**
	 * Public view of the job for the admin screen (no token hash, no step internals).
	 *
	 * @return array
	 */
	public function summary(): array {
		$steps = $this->data['steps'];
		$index = min( (int) $this->data['step'], count( $steps ) );
		return array(
			'id'       => $this->data['id'],
			'type'     => $this->data['type'],
			'status'   => $this->data['status'],
			'step'     => array(
				'index' => $index,
				'count' => count( $steps ),
				'name'  => isset( $steps[ $index ] ) ? $steps[ $index ] : null,
			),
			'progress' => $this->data['progress'],
			'warnings' => array(
				'count' => (int) $this->data['warning_count'],
				'first' => array_slice( $this->data['warnings'], 0, 20 ),
			),
			'error'    => $this->data['error'],
			'result'   => $this->data['result'],
			'created'  => (int) $this->data['created'],
			'updated'  => (int) $this->data['updated'],
		);
	}

	/**
	 * All jobs in a folder, newest first. Unreadable job folders are skipped.
	 *
	 * @param string $base Folder that holds job folders.
	 * @return Job[]
	 */
	public static function all( string $base ): array {
		$names = is_dir( $base ) ? scandir( $base ) : false;
		$jobs  = array();
		foreach ( false === $names ? array() : $names as $name ) {
			if ( preg_match( '/^[a-f0-9]{16}$/', $name ) ) {
				try {
					$jobs[] = self::load( $base, $name );
				} catch ( Job_Exception $e ) {
					continue;
				}
			}
		}
		usort(
			$jobs,
			static function ( Job $a, Job $b ): int {
				return $b->created() - $a->created();
			}
		);
		return $jobs;
	}

	/**
	 * The unfinished job that was active recently, if any (only one job may run at a time).
	 *
	 * @param string $base      Folder that holds job folders.
	 * @param int    $stale_age Seconds without progress after which an unfinished job no longer blocks.
	 */
	public static function active( string $base, int $stale_age = 3600 ): ?self {
		foreach ( self::all( $base ) as $job ) {
			if ( ! $job->finished() && time() - $job->updated() < $stale_age ) {
				return $job;
			}
		}
		return null;
	}

	/**
	 * One option, or $default.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function option( string $key, $default = null ) {
		return array_key_exists( $key, $this->data['options'] ) ? $this->data['options'][ $key ] : $default;
	}

	/**
	 * All options.
	 */
	public function options(): array {
		return $this->data['options'];
	}

	/**
	 * Saved state of one step (empty before its first run).
	 *
	 * @param string $step Step name.
	 */
	public function state( string $step ): array {
		return isset( $this->data['state'][ $step ] ) ? $this->data['state'][ $step ] : array();
	}

	/**
	 * Replaces the saved state of one step (written on the next save()).
	 *
	 * @param string $step  Step name.
	 * @param array  $state JSON-safe state.
	 */
	public function set_state( string $step, array $state ): void {
		$this->data['state'][ $step ] = $state;
	}

	/**
	 * Current status.
	 */
	public function status(): string {
		return $this->data['status'];
	}

	/**
	 * Whether the job has ended (done or failed).
	 */
	public function finished(): bool {
		return in_array( $this->data['status'], array( self::STATUS_DONE, self::STATUS_FAILED ), true );
	}

	/**
	 * Step names.
	 *
	 * @return string[]
	 */
	public function steps(): array {
		return $this->data['steps'];
	}

	/**
	 * Name of the step to run next, or null when all are done.
	 */
	public function current_step(): ?string {
		$index = (int) $this->data['step'];
		return isset( $this->data['steps'][ $index ] ) ? $this->data['steps'][ $index ] : null;
	}

	/**
	 * Index of the current step (0-based).
	 */
	public function step_index(): int {
		return (int) $this->data['step'];
	}

	/**
	 * Moves on to the next step.
	 */
	public function advance(): void {
		++$this->data['step'];
	}

	/**
	 * Sets the status.
	 *
	 * @param string $status One of the STATUS_ constants.
	 */
	public function set_status( string $status ): void {
		$this->data['status'] = $status;
	}

	/**
	 * Marks the job failed.
	 *
	 * @param string $message Plain-text reason.
	 */
	public function fail( string $message ): void {
		$this->data['status'] = self::STATUS_FAILED;
		$this->data['error']  = $message;
		$this->log( 'error', $message );
	}

	/**
	 * Failure reason, if any.
	 */
	public function error(): ?string {
		return $this->data['error'];
	}

	/**
	 * Updates the progress shown to the user.
	 *
	 * @param string $message What is happening.
	 * @param int    $done    Units done.
	 * @param int    $total   Units in total (0 if unknown).
	 */
	public function set_progress( string $message, int $done = 0, int $total = 0 ): void {
		$this->data['progress'] = array(
			'message' => $message,
			'done'    => $done,
			'total'   => $total,
		);
	}

	/**
	 * Progress: ['message', 'done', 'total'].
	 */
	public function progress(): array {
		return $this->data['progress'];
	}

	/**
	 * Result data of a finished job.
	 */
	public function result(): array {
		return $this->data['result'];
	}

	/**
	 * Sets result data.
	 *
	 * @param array $result JSON-safe result.
	 */
	public function set_result( array $result ): void {
		$this->data['result'] = $result;
	}

	/**
	 * Whether a token belongs to this job (constant-time comparison).
	 *
	 * @param string $token Token from the client.
	 */
	public function check_token( string $token ): bool {
		return hash_equals( $this->data['token_hash'], hash( 'sha256', $token ) );
	}

	/**
	 * Records a warning: kept for display (up to a limit) and written to the log.
	 *
	 * @param string $message Plain text; never include secrets.
	 */
	public function warn( string $message ): void {
		++$this->data['warning_count'];
		if ( count( $this->data['warnings'] ) < self::MAX_KEPT_WARNINGS ) {
			$this->data['warnings'][] = $message;
		}
		$this->log( 'warning', $message );
	}

	/**
	 * Number of warnings.
	 */
	public function warning_count(): int {
		return (int) $this->data['warning_count'];
	}

	/**
	 * The first warnings (up to MAX_KEPT_WARNINGS).
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->data['warnings'];
	}

	/**
	 * Appends a line to job.log.
	 *
	 * @param string $level   info, warning or error.
	 * @param string $message Plain text; never include secrets.
	 */
	public function log( string $level, string $message ): void {
		$line = sprintf( "%s [%s] %s\n", gmdate( 'Y-m-d H:i:s' ), $level, preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $message ) );
		$file = $this->path( 'job.log' );
		Io::quiet(
			static function () use ( $file, $line ) {
				return file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		);
	}

	/**
	 * Writes job.json atomically (temporary file, then rename).
	 *
	 * @throws Job_Exception If the state cannot be encoded or written.
	 */
	public function save(): void {
		$this->data['updated'] = time();
		$json                  = json_encode( $this->data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			throw new Job_Exception( 'Cannot encode the job state: ' . json_last_error_msg(), Job_Exception::INVALID );
		}
		$final = $this->path( 'job.json' );
		$temp  = $this->path( 'job.json.' . bin2hex( random_bytes( 4 ) ) . '.tmp' );
		if ( false === Io::quiet(
			static function () use ( $temp, $json ) {
				return file_put_contents( $temp, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		) || ! Io::quiet(
			static function () use ( $temp, $final ) {
				return rename( $temp, $final ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			}
		) ) {
			Io::quiet(
				static function () use ( $temp ) {
					return unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
			throw new Job_Exception( 'Cannot save the job state. The disk may be full.', Job_Exception::INVALID );
		}
	}

	/**
	 * Takes the slice lock so only one request works on the job at a time.
	 *
	 * @return bool False if another request holds it.
	 */
	public function lock(): bool {
		if ( null !== $this->lock ) {
			return true;
		}
		$handle = Io::open( $this->path( 'lock' ), 'c' );
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		$this->lock = $handle;
		return true;
	}

	/**
	 * Releases the slice lock.
	 */
	public function unlock(): void {
		if ( null !== $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->lock = null;
		}
	}

	/**
	 * Deletes a temporary file in the job folder, if it exists.
	 *
	 * @param string $name File name.
	 */
	public function delete_file( string $name ): void {
		$path = $this->path( $name );
		if ( is_file( $path ) ) {
			Io::quiet(
				static function () use ( $path ) {
					return unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
		}
	}
}
