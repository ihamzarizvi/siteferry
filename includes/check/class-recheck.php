<?php
/**
 * The weekly re-check of stored copies.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Check;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Schedule\Schedule;
use SiteFerry\Security\Storage;
use SiteFerry\Storage\Destinations;
use SiteFerry\Storage\Parts;

defined( 'ABSPATH' ) || exit;

/**
 * Once a week, checks a few copies on each storage that is set up, with the same check as "Check
 * for damage" (download, then every file's SHA-256 against the backup's own list), one copy at a
 * time, in short slices carried by WP-Cron. Which copies: on each storage the ones never checked
 * first, newest first, then the ones checked longest ago, so over the weeks every copy gets its
 * turn; at most PER_STORAGE per storage and at most the size cap per week (larger ones are
 * skipped and named). Scheduled backups that should be on a storage but are not are reported as
 * missing. A damaged or missing copy is emailed (the schedule's email settings) and shows in
 * red in the backups list; the check itself is a job on the Activity tab.
 *
 * The check jobs run in the background ("background" option): they only read the backups and
 * download into SiteFerry's job folder, so they do not stop a backup or restore from starting.
 */
final class Recheck {

	const HOOK_WEEKLY = 'siteferry_recheck';
	const HOOK_SLICE  = 'siteferry_recheck_slice';
	const STATE       = 'siteferry_recheck_state';

	/** Copies checked per storage each week. */
	const PER_STORAGE = 2;

	/** Seconds one slice may work. */
	const SLICE_SECONDS = 15.0;

	/** Seconds between slices (WP-Cron runs them on the next visit after this). */
	const SLICE_GAP = 30;

	/** When another job is running, try again after this many seconds. */
	const BUSY_GAP = 300;

	/**
	 * Hooks the cron events.
	 */
	public static function register(): void {
		add_action( self::HOOK_WEEKLY, array( self::class, 'on_weekly' ) );
		add_action( self::HOOK_SLICE, array( self::class, 'on_slice' ) );
		add_action( 'admin_init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Whether the weekly re-check is on: turned on in the schedule settings, with at least one
	 * storage that can be read.
	 */
	public static function enabled(): bool {
		$settings = Schedule::settings();
		return ! empty( $settings['recheck'] ) && array() !== self::storages();
	}

	/**
	 * The storages that are ready.
	 *
	 * @return string[]
	 */
	public static function storages(): array {
		$ready = array();
		foreach ( Destinations::overview() as $entry ) {
			if ( ! empty( $entry['ready'] ) ) {
				$ready[] = (string) $entry['id'];
			}
		}
		return $ready;
	}

	/**
	 * Plans the weekly event (or removes it when the re-check is off).
	 */
	public static function ensure_scheduled(): void {
		$next = wp_next_scheduled( self::HOOK_WEEKLY );
		if ( self::enabled() ) {
			if ( false === $next ) {
				wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::HOOK_WEEKLY );
			}
		} elseif ( false !== $next ) {
			wp_clear_scheduled_hook( self::HOOK_WEEKLY );
		}
	}

	/**
	 * Which copies this week's re-check looks at. Pure: from the listings, the earlier outcomes and
	 * the scheduled runs.
	 *
	 * @param array<string,array> $listings place => grouped listing (name, size, modified, complete).
	 * @param array               $outcomes Check::all(): name => place => {time, ok, size}.
	 * @param array               $runs     Scheduled runs (name, local, destination(s)).
	 * @param int                 $cap      Most bytes to download this week (0: no limit).
	 * @param int                 $per      Copies per storage.
	 * @param bool                $unlock   Whether password-protected copies can be opened (the schedule's password is stored).
	 * @return array{queue:array<int,array{name:string,place:string,size:int}>,skipped:array<int,array{name:string,place:string,size:int}>,missing:array<int,array{name:string,place:string}>}
	 */
	public static function plan( array $listings, array $outcomes, array $runs, int $cap, int $per = self::PER_STORAGE, bool $unlock = false ): array {
		$queue   = array();
		$skipped = array();
		$missing = array();
		$left    = $cap;
		foreach ( $listings as $place => $items ) {
			$candidates = array();
			foreach ( $items as $item ) {
				if ( empty( $item['complete'] ) || ( ! $unlock && \SiteFerry\Backups::encrypted_name( (string) $item['name'] ) ) ) {
					continue; // Parts missing (shown as such already), or a password nobody stored.
				}
				$seen         = isset( $outcomes[ $item['name'] ][ $place ] ) && (int) $outcomes[ $item['name'] ][ $place ]['size'] === (int) $item['size']
					? (int) $outcomes[ $item['name'] ][ $place ]['time'] : 0;
				$candidates[] = array(
					'name'  => (string) $item['name'],
					'place' => (string) $place,
					'size'  => (int) $item['size'],
					'seen'  => $seen,
					'time'  => (int) $item['modified'],
				);
			}
			// Never checked first (newest first), then checked longest ago.
			usort(
				$candidates,
				static function ( array $a, array $b ): int {
					if ( ( 0 === $a['seen'] ) !== ( 0 === $b['seen'] ) ) {
						return 0 === $a['seen'] ? -1 : 1;
					}
					if ( 0 === $a['seen'] ) {
						return $b['time'] - $a['time'];
					}
					return $a['seen'] !== $b['seen'] ? $a['seen'] - $b['seen'] : $b['time'] - $a['time'];
				}
			);
			$taken = 0;
			foreach ( $candidates as $candidate ) {
				if ( $taken >= $per ) {
					break;
				}
				$one = array_intersect_key( $candidate, array_flip( array( 'name', 'place', 'size' ) ) );
				if ( $cap > 0 && $candidate['size'] > $left ) {
					$skipped[] = $one;
					continue;
				}
				$queue[] = $one;
				$left   -= $cap > 0 ? $candidate['size'] : 0;
				++$taken;
			}
		}
		// Scheduled backups sent to a storage that does not have them (any more).
		foreach ( $runs as $run ) {
			foreach ( Schedule::places_of( $run ) as $place ) {
				if ( ! isset( $listings[ $place ] ) ) {
					continue; // That storage was not listed this week.
				}
				if ( ! in_array( (string) $run['name'], array_column( $listings[ $place ], 'name' ), true ) ) {
					$missing[] = array(
						'name'  => (string) $run['name'],
						'place' => $place,
					);
				}
			}
		}
		return array(
			'queue'   => $queue,
			'skipped' => $skipped,
			'missing' => $missing,
		);
	}

	/**
	 * The weekly event: lists the storages, plans and starts the first check.
	 */
	public static function on_weekly(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$state = self::state();
		if ( ! empty( $state['queue'] ) || ! empty( $state['current'] ) ) {
			return; // Last week's is still going.
		}
		self::start_round();
	}

	/**
	 * Plans and starts a round now (the weekly event, and WP-CLI).
	 *
	 * @return array The round's state.
	 */
	public static function start_round(): array {
		$listings = array();
		$errors   = array();
		foreach ( self::storages() as $place ) {
			try {
				$listings[ $place ] = Parts::group( Destinations::driver( $place )->list() );
			} catch ( \Throwable $e ) {
				$errors[] = sprintf( '%s: %s', Destinations::short_label( $place ), $e->getMessage() );
			}
		}
		$settings = Schedule::settings();
		$cap      = max( 0, (int) $settings['recheck_cap_mb'] ) * 1048576;
		$plan     = self::plan( $listings, Check::all(), Schedule::state()['runs'], $cap, self::PER_STORAGE, null !== Schedule::password() );
		foreach ( $plan['missing'] as $gone ) {
			Check::record( $gone['name'], $gone['place'], false, 0, 'Not on the storage any more.', Check::MISSING );
		}
		$state = array(
			'started' => time(),
			'queue'   => $plan['queue'],
			'skipped' => $plan['skipped'],
			'missing' => $plan['missing'],
			'errors'  => $errors,
			'done'    => array(),
			'current' => null,
		);
		self::save_state( $state );
		self::next_slice( 0 );
		return $state;
	}

	/**
	 * One slice: carries the current check on, or starts the next one, or finishes the round.
	 */
	public static function on_slice(): void {
		$state = self::state();
		if ( ! empty( $state['current'] ) ) {
			try {
				$job = Job::load( Storage::jobs_dir(), (string) $state['current'] );
			} catch ( Job_Exception $e ) {
				$state['current'] = null; // Gone (deleted with the Activity list): go on with the next one.
				self::save_state( $state );
				self::next_slice( 0 );
				return;
			}
			if ( ! $job->finished() ) {
				if ( null !== Job::active( Storage::jobs_dir() ) ) {
					self::next_slice( self::BUSY_GAP ); // Low priority: let the other job work.
					return;
				}
				try {
					Runner::run_slice( $job, new Slice_Budget( self::SLICE_SECONDS ) );
				} catch ( Job_Exception $e ) {
					unset( $e ); // Busy in another request; the next slice goes on.
				}
				if ( ! $job->finished() ) {
					self::next_slice( self::SLICE_GAP );
					return;
				}
			}
			$state['done'][]  = self::outcome( $job );
			$state['current'] = null;
			self::save_state( $state );
		}
		if ( array() === $state['queue'] ) {
			self::finish_round( $state );
			return;
		}
		if ( null !== Job::active( Storage::jobs_dir() ) ) {
			self::next_slice( self::BUSY_GAP );
			return;
		}
		$next = array_shift( $state['queue'] );
		try {
			list( $job ) = Check::start( $next['name'], $next['place'], (string) Schedule::password(), array( 'background' => true ) );
			$job->log( 'info', 'Started by the weekly re-check of stored copies.' );
			$job->save();
			$state['current'] = $job->id();
		} catch ( \Throwable $e ) {
			$gone            = $e instanceof Job_Exception && Job_Exception::NOT_FOUND === $e->getCode();
			$state['done'][] = array(
				'name'    => $next['name'],
				'place'   => $next['place'],
				'state'   => $gone ? Check::MISSING : 'error',
				'summary' => $e->getMessage(),
			);
			if ( $gone ) {
				Check::record( $next['name'], $next['place'], false, 0, 'Not on the storage any more.', Check::MISSING );
			}
		}
		self::save_state( $state );
		self::next_slice( 0 );
	}

	/**
	 * What a finished check found.
	 *
	 * @param Job $job The check job.
	 */
	private static function outcome( Job $job ): array {
		$result = $job->result();
		$name   = (string) $job->option( 'name' );
		$place  = (string) $job->option( 'place' );
		// An intact copy finishes the job; a damaged one fails it, with what was found in "checked".
		if ( isset( $result['checked']['ok'] ) ) {
			return array(
				'name'    => $name,
				'place'   => $place,
				'state'   => $result['checked']['ok'] ? Check::OK : Check::DAMAGED,
				'summary' => isset( $result['checked']['summary'] ) ? (string) $result['checked']['summary'] : '',
			);
		}
		return array(
			'name'    => $name,
			'place'   => $place,
			'state'   => 'error',
			'summary' => (string) $job->error(),
		);
	}

	/**
	 * Ends a round: remembers what it found and emails damaged or missing copies.
	 *
	 * @param array $state The round.
	 */
	private static function finish_round( array $state ): void {
		$bad = array();
		foreach ( $state['done'] as $one ) {
			if ( Check::DAMAGED === $one['state'] ) {
				/* translators: 1: a backup name, 2: a storage, such as Dropbox, 3: what was found */
				$bad[] = sprintf( __( '%1$s on %2$s is damaged: %3$s', 'siteferry' ), $one['name'], Check::where( $one['place'] ), $one['summary'] );
			}
		}
		foreach ( $state['missing'] as $gone ) {
			/* translators: 1: a backup name, 2: a storage, such as Dropbox */
			$bad[] = sprintf( __( '%1$s is missing on %2$s.', 'siteferry' ), $gone['name'], Check::where( $gone['place'] ) );
		}
		$last = array(
			'time'    => time(),
			'checked' => count( $state['done'] ),
			'ok'      => count(
				array_filter(
					$state['done'],
					static function ( array $one ): bool {
						return Check::OK === $one['state'];
					}
				)
			),
			'bad'     => $bad,
			'skipped' => array_column( $state['skipped'], 'name' ),
			'errors'  => array_merge(
				(array) $state['errors'],
				array_values(
					array_map(
						static function ( array $one ): string {
							return $one['name'] . ' (' . Check::where( $one['place'] ) . '): ' . $one['summary'];
						},
						array_filter(
							$state['done'],
							static function ( array $one ): bool {
								return 'error' === $one['state'];
							}
						)
					)
				)
			),
		);
		self::save_state(
			array(
				'queue'   => array(),
				'current' => null,
				'last'    => $last,
			)
		);
		if ( array() !== $bad ) {
			self::notify( $bad, $last['skipped'] );
		}
	}

	/**
	 * Emails damaged or missing copies to the schedule's address (unless emails are off).
	 *
	 * @param string[] $bad     What is wrong.
	 * @param string[] $skipped Backups skipped for their size.
	 * @return bool Whether an email was handed to wp_mail().
	 */
	public static function notify( array $bad, array $skipped ): bool {
		$settings = Schedule::settings();
		if ( 'never' === $settings['notify'] ) {
			return false;
		}
		$to = '' !== $settings['email'] ? $settings['email'] : (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return false;
		}
		$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		/* translators: %s: site address */
		$subject = sprintf( __( '[%s] A stored backup is damaged or missing', 'siteferry' ), $site );
		$lines   = array( sprintf( /* translators: %s: the site's address */ __( 'The weekly check of the stored backups of %s found a problem:', 'siteferry' ), home_url() ), '' );
		foreach ( $bad as $line ) {
			$lines[] = '- ' . $line;
		}
		if ( array() !== $skipped ) {
			$lines[] = '';
			/* translators: %s: backup names */
			$lines[] = sprintf( __( 'Not checked this week because of their size: %s', 'siteferry' ), implode( ', ', $skipped ) );
		}
		$lines[] = '';
		$lines[] = __( 'Make a new backup and send it to the storage, or restore from another copy if you need one.', 'siteferry' );
		$lines[] = 'SiteFerry: ' . admin_url( 'admin.php?page=siteferry' );
		return (bool) wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/**
	 * Asks WP-Cron for the next slice.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function next_slice( int $delay ): void {
		if ( false === wp_next_scheduled( self::HOOK_SLICE ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK_SLICE );
		}
	}

	/**
	 * The re-check's state: queue, current job, skipped, missing, done, last (the last round's summary).
	 */
	public static function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		return $state + array(
			'queue'   => array(),
			'current' => null,
			'skipped' => array(),
			'missing' => array(),
			'errors'  => array(),
			'done'    => array(),
			'last'    => null,
		);
	}

	/**
	 * Saves the state, keeping the last round's summary.
	 *
	 * @param array $state State.
	 */
	private static function save_state( array $state ): void {
		$old = self::state();
		if ( ! array_key_exists( 'last', $state ) ) {
			$state['last'] = $old['last'];
		}
		update_option( self::STATE, $state, false );
	}

	/**
	 * For the admin screen: whether it is on, when it runs next, whether a round is going, and
	 * what the last round found.
	 */
	public static function overview(): array {
		$state = self::state();
		$next  = wp_next_scheduled( self::HOOK_WEEKLY );
		return array(
			'enabled'  => self::enabled(),
			'storages' => count( self::storages() ),
			'next'     => false === $next ? null : (int) $next,
			'running'  => array() !== $state['queue'] || ! empty( $state['current'] ),
			'last'     => $state['last'],
		);
	}

	/**
	 * Stops a round and removes the events (the re-check turned off, or SiteFerry uninstalled).
	 */
	public static function stop(): void {
		wp_clear_scheduled_hook( self::HOOK_WEEKLY );
		wp_clear_scheduled_hook( self::HOOK_SLICE );
		$state = self::state();
		update_option(
			self::STATE,
			array(
				'queue'   => array(),
				'current' => null,
				'last'    => $state['last'],
			),
			false
		);
	}
}
