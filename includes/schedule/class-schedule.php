<?php
/**
 * Scheduled backups.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Schedule;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Backups;
use SiteFerry\Export\Export;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Security\Secret_Exception;
use SiteFerry\Security\Secrets;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;

defined( 'ABSPATH' ) || exit;

/**
 * One backup schedule: daily, weekly or monthly at a time of day in the site's time zone.
 *
 * WP-Cron starts the backup (the event "siteferry_scheduled_backup", one at a time, each run
 * scheduling the next). Nobody has the admin page open then, so the job keeps itself going: the
 * cron request runs the first slice, and every slice ends with a non-blocking request from the
 * site to its own REST route for the next one, authenticated with the job's token (kept
 * encrypted). Where the host blocks such requests, a check every five minutes runs the job
 * instead, and stops one that has made no progress for an hour.
 *
 * The last step of a scheduled backup (Finish_Step) records it, deletes scheduled backups beyond
 * the number to keep (only ones this schedule made, and only after a newer one succeeded) and
 * sends the email. A failure is recorded and mailed by that step's cleanup.
 *
 * Settings live in the option siteferry_schedule, the state in siteferry_schedule_state (neither
 * autoloaded; both kept on the site during an import, and removed on uninstall).
 */
final class Schedule {

	const OPTION = 'siteferry_schedule';
	const STATE  = 'siteferry_schedule_state';

	/** Cron event that starts a backup; the argument counts retries while another job runs. */
	const HOOK_START = 'siteferry_scheduled_backup';

	/** Cron event that keeps a scheduled backup going when the site's own requests do not. */
	const HOOK_WATCH = 'siteferry_schedule_watchdog';

	/** Cron interval of the watchdog. */
	const WATCH_INTERVAL = 'siteferry_five_minutes';

	/** While another job runs: try again after this many seconds, this many times. */
	const RETRY_DELAY = 900;
	const MAX_RETRIES = 8;

	/** A scheduled backup without progress for this long is stopped. */
	const STALL_LIMIT = 3600;

	/** Seconds of work a cron request does itself. */
	const SLICE_SECONDS = 20.0;

	/** The watchdog leaves a job alone that made progress this recently (the chain is working). */
	const QUIET_AFTER = 120;

	/** Scheduled backups remembered for retention (the newest). */
	const MAX_RUNS = 200;

	const FREQUENCIES = array( 'daily', 'weekly', 'monthly' );
	const NOTIFY      = array( 'failure', 'always', 'never' );

	/** Tag every scheduled backup gets. */
	const TAG = 'scheduled';

	/** Settings and their defaults. */
	/** The password of the automatic backups, encrypted with the site's key. Never sent to the page. */
	const PASSWORD = 'siteferry_schedule_password';

	const DEFAULTS = array(
		'enabled'                  => false,
		'frequency'                => 'daily',
		'time'                     => '03:00',
		'weekday'                  => 1,
		'monthday'                 => 1,
		'exclude_media'            => false,
		'exclude_plugins'          => false,
		'exclude_themes'           => false,
		'exclude_inactive_plugins' => false,
		'exclude_inactive_themes'  => false,
		'exclude_cache'            => true,
		'exclude_spam_comments'    => false,
		'exclude_revisions'        => false,
		'format'                   => 'tar',
		'compress'                 => false,
		'encrypt'                  => false,
		'send_to'                  => '',
		// More storages each run also goes to, after send_to (since 0.38.0).
		'send_also'                => array(),
		'keep_local'               => false,
		'retain'                   => 7,
		// "newest": keep the newest "retain"; "periods": the newest of each of the last days, weeks, months.
		'retain_mode'              => 'newest',
		'keep_daily'               => 7,
		'keep_weekly'              => 4,
		'keep_monthly'             => 6,
		// A full backup every this many runs, changes-only backups in between (1: always full).
		'full_every'               => 1,
		'notify'                   => 'failure',
		'email'                    => '',
		// Independent of the schedule: a quick backup before updates (see Before_Updates).
		'before_updates'           => false,
		'update_retain'            => 3,
		// Quick backups before risky changes (see Before_Updates); kept with the ones made before updates.
		'before_theme_switch'      => true,
		'before_plugin_delete'     => true,
		'before_theme_delete'      => true,
		// The weekly re-check of stored copies (see Check\Recheck); runs only when a storage is set up.
		'recheck'                  => true,
		// Most MB the re-check downloads in a week (0: no limit); larger copies are skipped and named.
		'recheck_cap_mb'           => 2048,
	);

	/**
	 * Hooks the cron events.
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK_START, array( self::class, 'on_start' ) );
		add_action( self::HOOK_WATCH, array( self::class, 'on_watchdog' ) );
		// An import replaces the cron table (it is part of the database); put the next run back.
		add_action( 'admin_init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Adds the watchdog's five-minute interval.
	 *
	 * @param array $schedules Cron intervals.
	 */
	public static function intervals( $schedules ): array {
		$schedules                         = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::WATCH_INTERVAL ] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (SiteFerry)',
		);
		return $schedules;
	}

	// ------------------------------------------------------------------ settings

	/**
	 * Validates settings.
	 *
	 * @param array    $input Raw input.
	 * @param string[] $ready Storages that can take backups now.
	 * @return array Normalized settings.
	 * @throws Job_Exception On invalid input.
	 */
	public static function normalize( array $input, array $ready ): array {
		// Where it goes: "send_to" may be a list of storages (the page sends one); the first stays
		// send_to, the others become send_also. Saved settings from before 0.38.0 have one storage.
		$targets = self::targets_of( $input );
		foreach ( $targets as $id ) {
			if ( ! in_array( $id, $ready, true ) ) {
				throw new Job_Exception( __( 'That storage is not set up (or not connected) yet. Set it up on the Storage tab, or keep the backups on this server.', 'siteferry' ), Job_Exception::STEP );
			}
		}
		$input['send_to']   = isset( $targets[0] ) ? $targets[0] : '';
		$input['send_also'] = array_slice( $targets, 1 );

		$out = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$value = array_key_exists( $key, $input ) ? $input[ $key ] : $default;
			if ( is_array( $default ) ) {
				$value = array_values( (array) $value );
			} elseif ( is_bool( $default ) ) {
				$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
			} elseif ( is_int( $default ) ) {
				if ( ! is_numeric( $value ) ) {
					throw new Job_Exception( sprintf( /* translators: %s: a setting name */ __( 'The schedule setting "%s" must be a number.', 'siteferry' ), $key ), Job_Exception::STEP );
				}
				$value = (int) $value;
			} else {
				$value = trim( is_scalar( $value ) ? (string) $value : '' );
			}
			$out[ $key ] = $value;
		}
		if ( ! in_array( $out['frequency'], self::FREQUENCIES, true ) ) {
			throw new Job_Exception( __( 'Choose how often to back up: every day, week or month.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $out['time'] ) ) {
			throw new Job_Exception( __( 'Enter the time as hours and minutes, for example 03:00.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['weekday'] < 0 || $out['weekday'] > 6 ) {
			throw new Job_Exception( __( 'Choose a day of the week.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['monthday'] < 1 || $out['monthday'] > 28 ) {
			throw new Job_Exception( __( 'Choose a day of the month from 1 to 28 (every month has those).', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! in_array( $out['format'], array( 'tar', 'wpress' ), true ) ) {
			throw new Job_Exception( __( 'Unknown backup format; use "tar" or "wpress".', 'siteferry' ), Job_Exception::STEP );
		}
		if ( 'wpress' === $out['format'] ) {
			$out['compress'] = false;
			$out['encrypt']  = false; // SiteFerry encrypts only its own format.
		}
		if ( '' === $out['send_to'] ) {
			$out['keep_local'] = true;
		}
		if ( $out['retain'] < 0 || $out['retain'] > 100 ) {
			throw new Job_Exception( __( 'Keep between 1 and 100 scheduled backups, or 0 to keep them all.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! in_array( $out['retain_mode'], array( Retention::NEWEST, Retention::PERIODS ), true ) ) {
			throw new Job_Exception( __( 'Choose which scheduled backups to keep: the newest few, or one a day, week and month.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['keep_daily'] < 0 || $out['keep_daily'] > 60 || $out['keep_weekly'] < 0 || $out['keep_weekly'] > 52 || $out['keep_monthly'] < 0 || $out['keep_monthly'] > 120 ) {
			throw new Job_Exception( __( 'Keep a backup a day for 0 to 60 days, a week for 0 to 52 weeks and a month for 0 to 120 months.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( Retention::PERIODS === $out['retain_mode'] && 0 === $out['keep_daily'] + $out['keep_weekly'] + $out['keep_monthly'] ) {
			throw new Job_Exception( __( 'Keep at least one day, week or month of backups.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( ! in_array( $out['notify'], self::NOTIFY, true ) ) {
			throw new Job_Exception( __( 'Choose when to send an email.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( '' !== $out['email'] && ( strlen( $out['email'] ) > 200 || false === filter_var( $out['email'], FILTER_VALIDATE_EMAIL ) ) ) {
			throw new Job_Exception( __( 'Enter one email address, or leave it empty for the site\'s admin email.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['update_retain'] < 1 || $out['update_retain'] > 50 ) {
			throw new Job_Exception( __( 'Keep between 1 and 50 backups made before updates.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['recheck_cap_mb'] < 0 || $out['recheck_cap_mb'] > 1048576 ) {
			throw new Job_Exception( __( 'Download at most 0 (no limit) to 1048576 MB a week for the check of stored copies.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['full_every'] < 1 || $out['full_every'] > 30 ) {
			throw new Job_Exception( __( 'Make a full backup every 1 to 30 runs.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $out['full_every'] > 1 ) {
			// Changes-only backups are built on a full backup on this server, in SiteFerry's format.
			if ( 'tar' !== $out['format'] ) {
				throw new Job_Exception( __( 'Changes-only backups are always SiteFerry backups; choose that format, or make a full backup every run.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( $out['encrypt'] ) {
				throw new Job_Exception( __( 'Changes-only backups cannot be protected with a password yet; turn the password off, or make a full backup every run.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( ! $out['keep_local'] ) {
				throw new Job_Exception( __( 'Changes-only backups need their full backup on this server; keep a copy on this server, or make a full backup every run.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( Retention::NEWEST === $out['retain_mode'] && 0 !== $out['retain'] && $out['retain'] < $out['full_every'] ) {
				throw new Job_Exception( __( 'Keep at least as many scheduled backups as the runs between full backups.', 'siteferry' ), Job_Exception::STEP );
			}
		}
		return $out;
	}

	/**
	 * The storages a run goes to, in order, from send_to (one id, or a list) and send_also; ids are
	 * checked and duplicates dropped.
	 *
	 * @param array $input Settings or raw input.
	 * @return string[]
	 * @throws Job_Exception On an invalid id or too many storages.
	 */
	public static function targets_of( array $input ): array {
		$raw = array();
		foreach ( array( 'send_to', 'send_also' ) as $key ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}
			$value = $input[ $key ];
			if ( is_string( $value ) ) {
				$value = '' === trim( $value ) ? array() : explode( ',', $value );
			}
			foreach ( (array) $value as $id ) {
				$raw[] = trim( is_scalar( $id ) ? (string) $id : '' );
			}
		}
		$targets = array();
		foreach ( $raw as $id ) {
			if ( '' === $id ) {
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9]{1,20}$/', $id ) ) {
				throw new Job_Exception( __( 'That storage is not set up (or not connected) yet. Set it up on the Storage tab, or keep the backups on this server.', 'siteferry' ), Job_Exception::STEP );
			}
			if ( ! in_array( $id, $targets, true ) ) {
				$targets[] = $id;
			}
		}
		if ( count( $targets ) > 10 ) {
			throw new Job_Exception( __( 'Send scheduled backups to at most 10 storages.', 'siteferry' ), Job_Exception::STEP );
		}
		return $targets;
	}

	/**
	 * The saved settings (defaults when there are none).
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return ( is_array( $saved ) ? array_intersect_key( $saved, self::DEFAULTS ) : array() ) + self::DEFAULTS;
	}

	/**
	 * Validates and saves the settings, and schedules the next run (or removes it).
	 *
	 * @param array $input Raw input.
	 * @throws Job_Exception On invalid input.
	 */
	public static function save(
		array $input,
		#[\SensitiveParameter]
		?string $password = null
	): array {
		if ( null !== $password && '' !== $password ) {
			if ( strlen( $password ) < \SiteFerry\Archive\Crypto::MIN_PASSWORD ) {
				throw new Job_Exception( sprintf( /* translators: %d: a number */ __( 'The password needs at least %d characters.', 'siteferry' ), \SiteFerry\Archive\Crypto::MIN_PASSWORD ), Job_Exception::STEP );
			}
			update_option( self::PASSWORD, Secrets::site()->encrypt( $password ), false );
		}
		$ready = array();
		foreach ( Destinations::overview() as $entry ) {
			if ( $entry['ready'] ) {
				$ready[] = $entry['id'];
			}
		}
		$saved = self::settings();
		if ( array_key_exists( 'send_to', $input ) ) {
			unset( $saved['send_also'] ); // A new "where it goes" replaces the whole list.
		}
		$settings = self::normalize( $input + $saved, $ready );
		if ( $settings['encrypt'] && null === self::password() ) {
			throw new Job_Exception( __( 'Enter a password for the automatic backups, or untick the encryption.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( $settings['encrypt'] && ! \SiteFerry\Archive\Crypto::available() ) {
			throw new Job_Exception( __( 'Encrypted backups need the PHP OpenSSL extension with AES-256-GCM, which this server does not have.', 'siteferry' ), Job_Exception::STEP );
		}
		update_option( self::OPTION, $settings, false );
		self::reschedule( $settings );
		if ( empty( $settings['recheck'] ) ) {
			\SiteFerry\Check\Recheck::stop();
		} else {
			\SiteFerry\Check\Recheck::ensure_scheduled();
		}
		return $settings;
	}

	/**
	 * When the next run is due after $now: a Unix time.
	 *
	 * @param array         $settings Normalized settings.
	 * @param int           $now      Unix time.
	 * @param \DateTimeZone $zone     The site's time zone.
	 */
	public static function next_run( array $settings, int $now, \DateTimeZone $zone ): int {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $settings['time'] ) );
		$today                 = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
		if ( 'monthly' === $settings['frequency'] ) {
			$at = $today->setDate( (int) $today->format( 'Y' ), (int) $today->format( 'n' ), (int) $settings['monthday'] )->setTime( $hour, $minute );
			if ( $at->getTimestamp() <= $now ) {
				$next = $today->modify( 'first day of next month' );
				$at   = $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), (int) $settings['monthday'] )->setTime( $hour, $minute );
			}
			return $at->getTimestamp();
		}
		$at = $today->setTime( $hour, $minute );
		if ( 'weekly' === $settings['frequency'] ) {
			$days = ( (int) $settings['weekday'] - (int) $at->format( 'w' ) + 7 ) % 7;
			$at   = $at->modify( '+' . $days . ' days' )->setTime( $hour, $minute );
			return $at->getTimestamp() <= $now ? $at->modify( '+7 days' )->setTime( $hour, $minute )->getTimestamp() : $at->getTimestamp();
		}
		return $at->getTimestamp() <= $now ? $at->modify( '+1 day' )->setTime( $hour, $minute )->getTimestamp() : $at->getTimestamp();
	}

	/**
	 * Replaces the scheduled start with the next one for these settings (none when disabled).
	 *
	 * @param array $settings Normalized settings.
	 */
	public static function reschedule( array $settings ): void {
		wp_unschedule_hook( self::HOOK_START ); // Retries too.
		if ( $settings['enabled'] ) {
			wp_schedule_single_event( self::next_run( $settings, time(), wp_timezone() ), self::HOOK_START );
		}
	}

	/**
	 * Puts the next run back when it has gone missing (after an import, or a cron table reset).
	 */
	public static function ensure_scheduled(): void {
		$settings = self::settings();
		if ( $settings['enabled'] && false === wp_next_scheduled( self::HOOK_START ) ) {
			wp_schedule_single_event( self::next_run( $settings, time(), wp_timezone() ), self::HOOK_START );
		}
	}

	/**
	 * Removes every SiteFerry cron event (deactivation).
	 */
	public static function unschedule_all(): void {
		wp_unschedule_hook( self::HOOK_START );
		wp_unschedule_hook( self::HOOK_WATCH );
	}

	// ------------------------------------------------------------------ running

	/**
	 * Cron: a scheduled backup is due (or a retry after another job was running).
	 *
	 * @param int $retry Retries so far.
	 */
	public static function on_start( $retry = 0 ): void {
		$retry    = (int) $retry;
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			wp_unschedule_hook( self::HOOK_START );
			return;
		}
		$state = self::state();
		if ( 0 === $retry ) {
			// The next regular run first, so one failure never ends the schedule.
			wp_schedule_single_event( self::next_run( $settings, time() + 60, wp_timezone() ), self::HOOK_START );
			// A copy of this event (from an imported cron table, say) arriving soon after a start is ignored.
			if ( time() - (int) $state['last_start'] < 600 ) {
				return;
			}
		}
		$active = Job::active( Storage::jobs_dir() );
		if ( null !== $active ) {
			if ( $retry < self::MAX_RETRIES ) {
				wp_schedule_single_event( time() + self::RETRY_DELAY, self::HOOK_START, array( $retry + 1 ) );
				return;
			}
			self::record_outcome( '', 'skipped', 'Skipped: another backup, restore or transfer was running for two hours.', array() );
			self::notify( 'failed', array( 'error' => 'The scheduled backup was skipped because another backup, restore or transfer was running for two hours.' ) );
			return;
		}
		try {
			$job = self::start_job();
		} catch ( \Throwable $e ) {
			self::record_outcome( '', 'failed', $e->getMessage(), array() );
			self::notify( 'failed', array( 'error' => $e->getMessage() ) );
			return;
		}
		self::work( $job );
	}

	/**
	 * Starts the scheduled backup now ("Run it now", WP-CLI and cron).
	 *
	 * @return Job The job (running in the background).
	 * @throws Job_Exception If another job is active or the backup cannot start.
	 */
	public static function start_job(): Job {
		$active = Job::active( Storage::jobs_dir() );
		if ( null !== $active ) {
			throw new Job_Exception( sprintf( /* translators: 1: a kind of job, such as "export" or "import", 2: the job's ID */ __( 'Another %1$s is still in progress (job %2$s). Finish or cancel it first.', 'siteferry' ), $active->type(), $active->id() ), Job_Exception::BUSY );
		}
		list( $job, $token ) = Export::start( self::with_password( self::export_options( self::settings() ) ), null, 'schedule' );
		$job->log( 'info', 'Started by the backup schedule.' );

		$state                = self::state();
		$state['last_start']  = time();
		$state['current']     = array(
			'job'   => $job->id(),
			'token' => Secrets::site()->encrypt( $token ),
			'seen'  => array(
				'mark' => '',
				'at'   => time(),
			),
		);
		self::save_state( $state );
		if ( false === wp_next_scheduled( self::HOOK_WATCH ) ) {
			wp_schedule_event( time() + 300, self::WATCH_INTERVAL, self::HOOK_WATCH );
		}
		return $job;
	}

	/**
	 * Export options for the settings.
	 *
	 * @param array $settings Normalized settings.
	 */
	public static function export_options( array $settings ): array {
		$options = array_intersect_key( $settings, array_flip( array( 'exclude_media', 'exclude_plugins', 'exclude_themes', 'exclude_inactive_plugins', 'exclude_inactive_themes', 'exclude_cache', 'exclude_spam_comments', 'exclude_revisions', 'format', 'compress', 'send_to' ) ) );
		$options['send_also']         = isset( $settings['send_also'] ) ? array_values( (array) $settings['send_also'] ) : array();
		$options['delete_after_send'] = '' !== $settings['send_to'] && ! $settings['keep_local'];
		$options['tags']              = array( self::TAG );
		if ( isset( $settings['full_every'] ) && (int) $settings['full_every'] > 1 ) {
			$base = self::changes_base( $settings, self::state()['runs'] );
			if ( null !== $base ) {
				$options['changes_since'] = $base;
			}
		}
		return $options;
	}

	/**
	 * The full backup this run builds a changes-only backup on, or null for a full backup: with
	 * "a full backup every N runs", the newest scheduled full backup still on this server, as long
	 * as fewer than N - 1 changes-only runs followed it.
	 *
	 * @param array $settings Settings.
	 * @param array $runs     Recorded runs: name, time, local, base.
	 */
	public static function changes_base( array $settings, array $runs ): ?string {
		$every = (int) $settings['full_every'];
		if ( $every <= 1 || 'tar' !== $settings['format'] || ! empty( $settings['encrypt'] ) ) {
			return null;
		}
		usort(
			$runs,
			static function ( array $a, array $b ): int {
				return (int) $b['time'] - (int) $a['time'];
			}
		);
		$since = 0;
		foreach ( $runs as $run ) {
			if ( ! empty( $run['base'] ) ) {
				++$since;
				continue;
			}
			if ( $since >= $every - 1 ) {
				return null; // Time for a full one.
			}
			$dir = Storage::existing_backups_dir();
			if ( null === $dir || ! is_file( $dir . '/' . $run['name'] ) || null !== \SiteFerry\Incremental::unsuitable_base( $dir . '/' . $run['name'] ) ) {
				return null;
			}
			return (string) $run['name'];
		}
		return null;
	}

	/**
	 * Runs a scheduled backup for a while in this request, then hands it to the next request.
	 *
	 * @param Job $job The job.
	 */
	public static function work( Job $job ): void {
		ignore_user_abort( true );
		try {
			Runner::run_slice( $job, new Slice_Budget( self::SLICE_SECONDS ) );
		} catch ( Job_Exception $e ) {
			return; // Busy: another request is running it, and will hand it on.
		}
		self::after_slice( $job );
	}

	/**
	 * After a slice of any job: a scheduled backup that is not finished asks for its next slice.
	 *
	 * @param Job $job The job.
	 */
	public static function after_slice( Job $job ): void {
		if ( 'schedule' !== $job->option( 'origin', '' ) ) {
			return;
		}
		$current = self::state()['current'];
		if ( ! is_array( $current ) || $current['job'] !== $job->id() ) {
			return;
		}
		if ( $job->finished() ) {
			self::forget_current( $job->id() );
			return;
		}
		self::kick( $job->id(), (string) $current['token'] );
	}

	/**
	 * Asks this site for the next slice of a job, without waiting for the answer.
	 *
	 * @param string $id    Job id.
	 * @param string $token The job token, encrypted.
	 */
	private static function kick( string $id, string $token ): void {
		try {
			$token = Secrets::site()->decrypt( $token );
		} catch ( Secret_Exception $e ) {
			return; // The watchdog carries on.
		}
		wp_remote_post(
			rest_url( 'siteferry/v1/jobs/' . $id . '/run' ),
			array(
				'blocking'  => false,
				'timeout'   => 1,
				'headers'   => array( 'X-SiteFerry-Job' => $token ),
				'cookies'   => array(),
				// As WordPress does for its own cron requests to the site.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			)
		);
	}

	/**
	 * Cron, every five minutes while a scheduled backup runs: carries on a job whose own requests
	 * have stopped, and stops one that has made no progress for an hour.
	 */
	public static function on_watchdog(): void {
		$current = self::state()['current'];
		if ( ! is_array( $current ) ) {
			wp_unschedule_hook( self::HOOK_WATCH );
			return;
		}
		try {
			$job = Job::load( Storage::jobs_dir(), (string) $current['job'] );
		} catch ( Job_Exception $e ) {
			self::forget_current( (string) $current['job'] );
			return;
		}
		if ( $job->finished() ) {
			self::forget_current( $job->id() );
			return;
		}
		$progress = $job->progress();
		$mark     = $job->step_index() . ':' . (int) $progress['done'] . ':' . (string) $progress['message'];
		if ( $mark !== $current['seen']['mark'] ) {
			$state                    = self::state();
			$state['current']['seen'] = array(
				'mark' => $mark,
				'at'   => time(),
			);
			self::save_state( $state );
		} elseif ( time() - (int) $current['seen']['at'] > self::STALL_LIMIT ) {
			try {
				Runner::cancel( $job, 'Stopped: the scheduled backup made no progress for an hour.' );
			} catch ( Job_Exception $e ) {
				return; // A slice is running right now after all.
			}
			self::forget_current( $job->id() );
			return;
		}
		if ( time() - $job->updated() < self::QUIET_AFTER ) {
			return; // Its own requests keep it going.
		}
		self::work( $job );
	}

	/**
	 * Clears the running job from the state (and stops the watchdog).
	 *
	 * @param string $id Job id.
	 */
	private static function forget_current( string $id ): void {
		$state = self::state();
		if ( is_array( $state['current'] ) && $state['current']['job'] === $id ) {
			$state['current'] = null;
			self::save_state( $state );
		}
		wp_unschedule_hook( self::HOOK_WATCH );
	}

	// ------------------------------------------------------------------ results

	/**
	 * Records a finished scheduled backup and deletes the scheduled backups beyond the number to
	 * keep (only after this one succeeded).
	 *
	 * @param Job   $job    The job.
	 * @param array $backup name, size, local (whether a copy stays on this server), destination.
	 * @return array{deleted:string[],problems:string[]} What retention removed, and what failed.
	 */
	public static function record_success( Job $job, array $backup ): array {
		$state           = self::state();
		$state['runs'][] = array(
			'name'        => $backup['name'],
			'time'        => time(),
			'local'       => (bool) $backup['local'],
			'destination' => (string) $backup['destination'],
			// Every storage it reached (since 0.38.0; older runs have only "destination").
			'destinations' => isset( $backup['destinations'] ) ? array_values( (array) $backup['destinations'] ) : ( '' === (string) $backup['destination'] ? array() : array( (string) $backup['destination'] ) ),
			'base'        => isset( $backup['base'] ) ? (string) $backup['base'] : '',
		);
		$settings        = self::settings();
		$deleted         = array();
		$problems        = array();
		$left            = array();
		if ( Retention::PERIODS === $settings['retain_mode'] ) {
			$chosen  = Retention::select( $state['runs'], $settings, time(), wp_timezone() );
			$expired = $chosen['expired'];
			$held    = $chosen['held'];
		} else {
			list( $expired, $held ) = \SiteFerry\Incremental::expired( $state['runs'], (int) $settings['retain'] );
		}
		// Changes-only backups first, so the full backups they were built on are free to go after them.
		usort(
			$expired,
			static function ( array $a, array $b ): int {
				return (int) empty( $a['base'] ) - (int) empty( $b['base'] );
			}
		);
		foreach ( $expired as $run ) {
			$failed = self::delete_run( $run );
			if ( null === $failed ) {
				$deleted[] = $run['name'];
			} else {
				$problems[] = $failed;
				$left[]     = $run; // Tried again after the next backup.
			}
		}
		$state['runs'] = array_values(
			array_filter(
				$state['runs'],
				static function ( array $run ) use ( $deleted ): bool {
					return ! in_array( $run['name'], $deleted, true );
				}
			)
		);
		$state['runs'] = array_slice( $state['runs'], -self::MAX_RUNS );
		self::save_state( $state );
		self::record_outcome( $job->id(), 'done', '', $backup + array( 'deleted' => $deleted ) );
		return array(
			'deleted'  => $deleted,
			'problems' => $problems,
			'held'     => $held,
		);
	}

	/**
	 * The recorded runs beyond the newest $keep (none when $keep is 0), oldest first.
	 *
	 * @param array $runs Runs: name, time, local, destination.
	 * @param int   $keep How many to keep.
	 * @return array
	 */
	public static function expired( array $runs, int $keep ): array {
		if ( $keep <= 0 ) {
			return array();
		}
		return array_reverse( array_slice( self::newest_first( $runs ), $keep ) );
	}

	/**
	 * What keeping would do after the next scheduled backup, for settings that may not be saved
	 * yet: each recorded scheduled backup (newest first) with why it is kept, or that it would be
	 * deleted, and the next backup itself. Changes nothing.
	 *
	 * @param array $input retain_mode, retain, keep_daily, keep_weekly, keep_monthly (missing: the saved ones).
	 * @return array{next:int,items:array<int,array{name:string,time:int,keep:bool,reasons:string[],next:bool}>,deleted:int}
	 * @throws Job_Exception On invalid values.
	 */
	public static function retention_preview( array $input ): array {
		$saved    = self::settings();
		$settings = self::normalize( array_intersect_key( $input, array_flip( array( 'retain_mode', 'retain', 'keep_daily', 'keep_weekly', 'keep_monthly' ) ) ) + $saved, self::targets_of( $saved ) ); // Only the keep values are checked here.
		$state    = self::state();
		$next     = wp_next_scheduled( self::HOOK_START );
		$when     = false === $next ? time() : (int) $next;
		$runs     = $state['runs'];
		$runs[]   = array(
			'name' => '',
			'time' => $when,
			'base' => '',
		);
		if ( Retention::PERIODS === $settings['retain_mode'] ) {
			$chosen = Retention::select( $runs, $settings, $when, wp_timezone() );
		} else {
			list( $expired, $held ) = \SiteFerry\Incremental::expired( $runs, (int) $settings['retain'] );
			$keep                   = array();
			foreach ( $runs as $run ) {
				$keep[ (string) $run['name'] ] = array( 'newest' );
			}
			foreach ( $expired as $run ) {
				unset( $keep[ (string) $run['name'] ] );
			}
			foreach ( $held as $name ) {
				$keep[ $name ] = array( 'base' );
			}
			$chosen = array( 'keep' => $keep );
		}
		$items   = array();
		$deleted = 0;
		foreach ( self::newest_first( $runs ) as $run ) {
			$name    = (string) $run['name'];
			$kept    = isset( $chosen['keep'][ $name ] );
			$deleted += $kept ? 0 : 1;
			$items[] = array(
				'name'    => $name,
				'time'    => (int) $run['time'],
				'keep'    => $kept,
				'reasons' => $kept ? array_values( array_unique( $chosen['keep'][ $name ] ) ) : array(),
				'next'    => '' === $name,
			);
		}
		return array(
			'next'    => $when,
			'items'   => $items,
			'deleted' => $deleted,
		);
	}

	/**
	 * Runs newest first. Runs are recorded in the order they finish, so of two with the same time
	 * (quick backups can finish within the same second) the one recorded later is the newer.
	 *
	 * @param array $runs Runs with a time.
	 * @return array
	 */
	public static function newest_first( array $runs ): array {
		$order = array();
		foreach ( array_values( $runs ) as $i => $run ) {
			$order[] = array( (int) $run['time'], $i, $run );
		}
		usort(
			$order,
			static function ( array $a, array $b ): int {
				return $b[0] !== $a[0] ? $b[0] - $a[0] : $b[1] - $a[1];
			}
		);
		return array_column( $order, 2 );
	}

	/**
	 * Deletes every copy of one scheduled backup (or of one made before an update).
	 *
	 * @param array $run Run: name, local, destination.
	 * @return string|null What went wrong, or null.
	 */
	public static function delete_run( array $run ): ?string {
		$name       = (string) $run['name'];
		$dependents = \SiteFerry\Incremental::dependents( $name );
		if ( array() !== $dependents ) {
			// Kept (here and on the storage) while changes-only backups are built on it; tried again later.
			return sprintf( '%s was kept: changes-only backups are built on it (%s).', $name, implode( ', ', $dependents ) );
		}
		try {
			$path = Backups::path( Storage::backups_dir(), $name );
			if ( is_file( $path ) && ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return sprintf( '%s could not be deleted on this server.', $name );
			}
			\SiteFerry\Contents::forget( $name );
			\SiteFerry\Check\Check::forget( $name, \SiteFerry\Check\Check::LOCAL );
		} catch ( \Throwable $e ) {
			unset( $e ); // Not a backup name any more, or gone: nothing to delete here.
		}
		$failed = array();
		foreach ( self::places_of( $run ) as $destination ) {
			try {
				\SiteFerry\Storage\Parts::delete( Destinations::driver( $destination ), $name );
				\SiteFerry\Check\Check::forget( $name, $destination );
			} catch ( \Throwable $e ) {
				$failed[] = sprintf( '%s could not be deleted on %s: %s', $name, Destinations::short_label( $destination ), $e->getMessage() );
			}
		}
		if ( array() !== $failed ) {
			return implode( ' ', $failed );
		}
		try {
			\SiteFerry\Labels::set( $name, '', '', array() );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return null;
	}

	/**
	 * The storages a recorded run was sent to.
	 *
	 * @param array $run Run: destination, and since 0.38.0 destinations.
	 * @return string[]
	 */
	public static function places_of( array $run ): array {
		$places = isset( $run['destinations'] ) && is_array( $run['destinations'] ) ? array_map( 'strval', $run['destinations'] ) : array();
		if ( isset( $run['destination'] ) && '' !== (string) $run['destination'] ) {
			$places[] = (string) $run['destination'];
		}
		return array_values( array_unique( array_filter( $places, 'strlen' ) ) );
	}

	/**
	 * Remembers the outcome of the last run.
	 *
	 * @param string $job     Job id ('' when no job started).
	 * @param string $status  done, failed or skipped.
	 * @param string $message Error, or ''.
	 * @param array  $backup  name, size, where, deleted.
	 */
	public static function record_outcome( string $job, string $status, string $message, array $backup ): void {
		$state         = self::state();
		$state['last'] = array(
			'job'     => $job,
			'status'  => $status,
			'time'    => time(),
			'message' => $message,
			'name'    => isset( $backup['name'] ) ? (string) $backup['name'] : '',
			'size'    => isset( $backup['size'] ) ? (int) $backup['size'] : 0,
			'where'   => isset( $backup['where'] ) ? (string) $backup['where'] : '',
			'deleted' => isset( $backup['deleted'] ) ? count( $backup['deleted'] ) : 0,
		);
		self::save_state( $state );
	}

	/**
	 * Emails the outcome of a run, as the settings ask.
	 *
	 * @param string $outcome "done" or "failed".
	 * @param array  $details name, size, where, deleted (names), problems, warnings, seconds, error.
	 * @return bool Whether an email was handed to wp_mail().
	 */
	public static function notify( string $outcome, array $details ): bool {
		$settings = self::settings();
		if ( 'never' === $settings['notify'] || ( 'done' === $outcome && 'always' !== $settings['notify'] ) ) {
			return false;
		}
		$to   = '' !== $settings['email'] ? $settings['email'] : (string) get_option( 'admin_email' );
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $to ) {
			return false;
		}
		$lines = array();
		if ( 'done' === $outcome ) {
			/* translators: %s: site address */
			$subject = sprintf( __( '[%s] Scheduled backup done', 'siteferry' ), $site );
			$lines[] = sprintf( /* translators: %s: the site's address */ __( 'SiteFerry made the scheduled backup of %s.', 'siteferry' ), home_url() );
			$lines[] = '';
			/* translators: %s: backup name (and size) */
			$lines[] = sprintf( __( 'Backup: %s', 'siteferry' ), $details['name'] . ( empty( $details['size'] ) ? '' : ' (' . size_format( (int) $details['size'], 1 ) . ')' ) );
			/* translators: %s: where it is stored */
			$lines[] = sprintf( __( 'Stored: %s', 'siteferry' ), $details['where'] );
			if ( ! empty( $details['seconds'] ) ) {
				/* translators: %s: how long, such as 5 mins */
				$lines[] = sprintf( __( 'Took: %s', 'siteferry' ), human_time_diff( 0, (int) $details['seconds'] ) );
			}
			if ( ! empty( $details['warnings'] ) ) {
				$lines[] = sprintf( /* translators: %d: a number */ __( 'Warnings: %d (see the job log on the Activity tab)', 'siteferry' ), (int) $details['warnings'] );
			}
			if ( ! empty( $details['deleted'] ) ) {
				/* translators: %s: backup names */
				$lines[] = sprintf( __( 'Older scheduled backups deleted: %s', 'siteferry' ), implode( ', ', $details['deleted'] ) );
			}
			foreach ( isset( $details['problems'] ) ? (array) $details['problems'] : array() as $problem ) {
				/* translators: %s: what went wrong */
				$lines[] = sprintf( __( 'Problem: %s', 'siteferry' ), $problem );
			}
		} else {
			/* translators: %s: site address */
			$subject = sprintf( __( '[%s] Scheduled backup FAILED', 'siteferry' ), $site );
			$lines[] = sprintf( /* translators: %s: the site's address */ __( 'SiteFerry could not make the scheduled backup of %s.', 'siteferry' ), home_url() );
			$lines[] = '';
			/* translators: %s: what went wrong */
			$lines[] = sprintf( __( 'Reason: %s', 'siteferry' ), isset( $details['error'] ) ? $details['error'] : __( 'unknown', 'siteferry' ) );
			$lines[] = __( 'Earlier backups were not touched.', 'siteferry' );
		}
		$lines[] = '';
		$lines[] = 'SiteFerry: ' . admin_url( 'admin.php?page=siteferry' );
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	// ------------------------------------------------------------------ overview

	/**
	 * Settings and state for the admin screen.
	 */
	public static function overview(): array {
		$settings = self::settings();
		$state    = self::state();
		$next     = wp_next_scheduled( self::HOOK_START );
		$running  = null;
		if ( is_array( $state['current'] ) ) {
			try {
				$job     = Job::load( Storage::jobs_dir(), (string) $state['current']['job'] );
				$running = $job->finished() ? null : $job->summary();
			} catch ( Job_Exception $e ) {
				$running = null;
			}
		}
		return array(
			'settings'       => $settings,
			'next'           => false === $next ? null : (int) $next,
			// In the site's time zone, with its offset, so the page can tell when the viewer's differs.
			'next_site'      => false === $next ? null : wp_date( 'D, M j, H:i', (int) $next ),
			'next_offset'    => false === $next ? null : wp_timezone()->getOffset( new \DateTimeImmutable( '@' . (int) $next ) ),
			'retry'          => self::next_retry(),
			'last'           => $state['last'],
			'running'        => $running,
			'kept'           => count( $state['runs'] ),
			'timezone'       => wp_timezone_string(),
			'admin_email'    => (string) get_option( 'admin_email' ),
			'cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'scheduled_runs' => array_values( array_column( $state['runs'], 'name' ) ),
			'last_update'    => $state['last_update'],
			'update_kept'    => count( $state['update_runs'] ),
			'has_password'   => null !== self::password(),
			// 3-2-1: the newest scheduled backup's copies, and what these settings would make.
			'copies'         => self::copies_overview( $settings, $state['runs'] ),
			// The weekly re-check of stored copies: on (with a storage), next, running, last round.
			'recheck'        => \SiteFerry\Check\Recheck::overview(),
		);
	}

	/**
	 * The 3-2-1 meter for the admin screen: of the newest scheduled backup (still in the places it
	 * was sent to, as recorded), and of what the saved settings would make.
	 *
	 * @param array $settings Settings.
	 * @param array $runs     Recorded runs.
	 */
	private static function copies_overview( array $settings, array $runs ): array {
		$newest  = Copies::newest( $runs );
		$planned = Copies::planned( $settings );
		return array(
			'newest'  => null === $newest ? null : Copies::meter( $newest ) + array( 'places' => $newest ),
			'planned' => Copies::meter( $planned ) + array( 'places' => $planned ),
		);
	}

	/**
	 * The password of the automatic backups, or null.
	 */
	public static function password(): ?string {
		$stored = get_option( self::PASSWORD );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		try {
			return Secrets::site()->decrypt( $stored );
		} catch ( \Throwable $e ) {
			return null; // Saved with other salts: it has to be entered again.
		}
	}

	/**
	 * Export options of an automatic backup, with the password when they are encrypted.
	 *
	 * @param array $options Export options.
	 * @throws Job_Exception If they should be encrypted and the password is gone.
	 */
	public static function with_password( array $options ): array {
		if ( ! self::settings()['encrypt'] || ( isset( $options['format'] ) && 'tar' !== $options['format'] ) ) {
			return $options;
		}
		$password = self::password();
		if ( null === $password ) {
			throw new Job_Exception( __( 'Automatic backups are set to be encrypted, but their password cannot be read (were the WordPress salts changed?). Enter it again in the Schedule panel.', 'siteferry' ), Job_Exception::STEP );
		}
		return $options + array( 'password' => $password );
	}

	/**
	 * Time of the next retry (while another job was running), or null.
	 */
	private static function next_retry(): ?int {
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( is_array( $crons ) ? $crons : array() as $time => $hooks ) {
			if ( isset( $hooks[ self::HOOK_START ] ) ) {
				foreach ( $hooks[ self::HOOK_START ] as $event ) {
					if ( array() !== $event['args'] ) {
						return (int) $time;
					}
				}
			}
		}
		return null;
	}

	/**
	 * The saved state.
	 */
	public static function state(): array {
		$saved = get_option( self::STATE, array() );
		$state = ( is_array( $saved ) ? $saved : array() ) + array(
			'last'        => null,
			'current'     => null,
			'runs'        => array(),
			'last_start'  => 0,
			'update_runs' => array(),
			'last_update' => null,
		);
		$state['runs']        = is_array( $state['runs'] ) ? $state['runs'] : array();
		$state['update_runs'] = is_array( $state['update_runs'] ) ? $state['update_runs'] : array();
		return $state;
	}

	/**
	 * Saves the state.
	 *
	 * @param array $state State.
	 */
	public static function save_state( array $state ): void {
		update_option( self::STATE, $state, false );
	}
}
