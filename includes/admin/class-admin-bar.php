<?php
/**
 * The running job's progress in the admin bar, on every admin page.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Admin;

use SiteFerry\Jobs\Job;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * While a SiteFerry job runs (a backup, restore, upload, download, check or staging copy), the
 * admin bar shows "SiteFerry 42%" linking to the SiteFerry page; when it ends, "Backup done" (or
 * a red "Backup failed") for a little while.
 *
 * Cheap by design: the page decides on the server whether anything runs (the job files only, no
 * database queries); the small script is only added then, and polls the /bar route every three
 * seconds while the tab is visible, until the job has ended. The weekly re-check's background
 * checks are not shown.
 */
final class Admin_Bar {

	/** Seconds a finished job stays in the bar. */
	const DONE_FOR = 30;

	/** Seconds a failed job stays in the bar (longer: it needs a look). */
	const FAILED_FOR = 600;

	const NODE = 'siteferry-job';

	/**
	 * Hooks the bar item and its script.
	 */
	public static function register(): void {
		add_action( 'admin_bar_menu', array( self::class, 'node' ), 90 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * What the bar shows for a job summary (Job::summary()), or null when nothing is shown.
	 *
	 * @param array|null $job Job summary, or null.
	 * @param int        $now Current time.
	 * @return array{state:string,percent:int,label:string,type:string,id:string}|null
	 */
	public static function summarize( ?array $job, int $now ): ?array {
		if ( null === $job || ! isset( $job['status'], $job['type'] ) ) {
			return null;
		}
		$status = (string) $job['status'];
		$age    = $now - (int) ( isset( $job['updated'] ) ? $job['updated'] : 0 );
		$what   = self::what( (string) $job['type'] );
		$base   = array(
			'type' => (string) $job['type'],
			'id'   => isset( $job['id'] ) ? (string) $job['id'] : '',
		);
		if ( in_array( $status, array( Job::STATUS_PENDING, Job::STATUS_RUNNING ), true ) ) {
			$percent = self::percent( $job );
			return $base + array(
				'state'   => 'running',
				'percent' => $percent,
				/* translators: %d: how far the job is, in percent */
				'label'   => sprintf( __( 'SiteFerry %d%%', 'siteferry' ), $percent ),
			);
		}
		if ( Job::STATUS_WAITING === $status ) {
			return $base + array(
				'state'   => 'waiting',
				'percent' => self::percent( $job ),
				'label'   => __( 'SiteFerry: waiting for you', 'siteferry' ),
			);
		}
		if ( Job::STATUS_DONE === $status && $age <= self::DONE_FOR ) {
			return $base + array(
				'state'   => 'done',
				'percent' => 100,
				/* translators: %s: what was done, such as Backup */
				'label'   => sprintf( __( '%s done ✓', 'siteferry' ), $what ),
			);
		}
		if ( Job::STATUS_FAILED === $status && $age <= self::FAILED_FOR ) {
			return $base + array(
				'state'   => 'failed',
				'percent' => self::percent( $job ),
				/* translators: %s: what failed, such as Backup */
				'label'   => sprintf( __( '%s failed', 'siteferry' ), $what ),
			);
		}
		return null;
	}

	/**
	 * How far a job is, 0–99 while it runs: finished steps plus the part of the current one.
	 *
	 * @param array $job Job summary.
	 */
	public static function percent( array $job ): int {
		$count = isset( $job['step']['count'] ) ? max( 1, (int) $job['step']['count'] ) : 1;
		$index = isset( $job['step']['index'] ) ? min( $count, max( 0, (int) $job['step']['index'] ) ) : 0;
		$total = isset( $job['progress']['total'] ) ? (int) $job['progress']['total'] : 0;
		$done  = isset( $job['progress']['done'] ) ? (int) $job['progress']['done'] : 0;
		$part  = $index < $count && $total > 0 ? min( 1, max( 0, $done / $total ) ) : 0;
		return (int) min( 99, floor( 100 * ( $index + $part ) / $count ) );
	}

	/**
	 * A job type in words.
	 *
	 * @param string $type Job type.
	 */
	public static function what( string $type ): string {
		switch ( $type ) {
			case 'export':
				return __( 'Backup', 'siteferry' );
			case 'import':
				return __( 'Restore', 'siteferry' );
			case 'send':
				return __( 'Upload', 'siteferry' );
			case 'fetch':
				return __( 'Download', 'siteferry' );
			case 'check':
				return __( 'Check', 'siteferry' );
			case 'staging':
				return __( 'Staging copy', 'siteferry' );
			case 'staging-delete':
				return __( 'Deleting the staging copy', 'siteferry' );
			case 'checklist':
				return __( 'Updating addresses', 'siteferry' );
			default:
				return __( 'SiteFerry job', 'siteferry' );
		}
	}

	/**
	 * The job to show now: the running one, else the newest that ended a moment ago. Background
	 * checks and jobs that run inside another job are skipped.
	 */
	public static function current(): ?array {
		$dir = Storage::existing_jobs_dir();
		if ( null === $dir ) {
			return null;
		}
		$active = Job::active( $dir );
		if ( null !== $active ) {
			return self::summarize( $active->summary(), time() );
		}
		foreach ( Job::all( $dir ) as $job ) {
			if ( '' !== (string) $job->option( 'parent', '' ) || $job->option( 'background', false ) ) {
				continue;
			}
			return self::summarize( $job->summary(), time() ); // The newest one only.
		}
		return null;
	}

	/**
	 * Whether the current user may see SiteFerry's jobs.
	 */
	private static function allowed(): bool {
		return is_user_logged_in() && \SiteFerry\Rest\Rest::can_manage();
	}

	/**
	 * Adds the bar item when a job runs or has just ended.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public static function node( $bar ): void {
		if ( ! self::allowed() ) {
			return;
		}
		$shown = self::current();
		if ( null === $shown ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => '<span class="sf-ab-glyph" aria-hidden="true"></span><span class="sf-ab-label">' . esc_html( $shown['label'] ) . '</span>',
				'href'  => esc_url( self::page_url() ),
				'meta'  => array(
					'class' => 'sf-ab is-' . sanitize_html_class( $shown['state'] ),
					'title' => esc_attr( self::what( $shown['type'] ) ),
				),
			)
		);
	}

	/**
	 * The SiteFerry page.
	 */
	private static function page_url(): string {
		// On a network the page lives in the main site's dashboard.
		return is_multisite() ? get_admin_url( get_main_site_id(), 'admin.php?page=' . Admin::SLUG ) : admin_url( 'admin.php?page=' . Admin::SLUG );
	}

	/**
	 * The script (only while something runs) and the bar item's style.
	 */
	public static function enqueue(): void {
		if ( ! is_admin_bar_showing() || ! self::allowed() ) {
			return;
		}
		$shown = self::current();
		if ( null === $shown ) {
			return;
		}
		wp_register_style( 'siteferry-admin-bar', false, array(), SITEFERRY_VERSION );
		wp_enqueue_style( 'siteferry-admin-bar' );
		wp_add_inline_style( 'siteferry-admin-bar', self::css() );
		if ( in_array( $shown['state'], array( 'running', 'waiting' ), true ) ) {
			wp_enqueue_script( 'siteferry-admin-bar', plugins_url( 'assets/admin-bar.js', SITEFERRY_FILE ), array(), SITEFERRY_VERSION, true );
			wp_add_inline_script(
				'siteferry-admin-bar',
				'window.SiteFerryBar = ' . wp_json_encode(
					array(
						'url'   => esc_url_raw( rest_url( 'siteferry/v1/bar' ) ),
						'nonce' => wp_create_nonce( 'wp_rest' ),
						'node'  => 'wp-admin-bar-' . self::NODE,
					)
				) . ';',
				'before'
			);
		}
	}

	/**
	 * The bar item's look, also on phones (WordPress hides added items below 782px).
	 */
	private static function css(): string {
		return '#wpadminbar #wp-admin-bar-siteferry-job > .ab-item{display:flex;align-items:center;gap:6px;font-weight:600}'
			. '#wpadminbar #wp-admin-bar-siteferry-job .sf-ab-glyph{display:inline-block;width:14px;height:8px;border-radius:0 0 4px 4px;background:currentColor;opacity:.85}'
			. '#wpadminbar #wp-admin-bar-siteferry-job.is-running .sf-ab-glyph{animation:sf-ab-bob 1.6s ease-in-out infinite}'
			. '#wpadminbar #wp-admin-bar-siteferry-job.is-done > .ab-item{color:#7ad03a}'
			. '#wpadminbar #wp-admin-bar-siteferry-job.is-failed > .ab-item{background:#c2352d;color:#fff}'
			. '@keyframes sf-ab-bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2px)}}'
			. '@media (prefers-reduced-motion:reduce){#wpadminbar #wp-admin-bar-siteferry-job .sf-ab-glyph{animation:none}}'
			. '@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-siteferry-job{display:block;float:left}#wpadminbar #wp-admin-bar-siteferry-job > .ab-item{width:auto;max-width:40vw;padding:0 8px;overflow:hidden;font-size:12px;line-height:46px;white-space:nowrap;text-overflow:ellipsis}}';
	}
}
