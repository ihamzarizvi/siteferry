<?php
/**
 * The after-move checklist: what to look at once a site has been moved here.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Table_Replacer;
use SiteFerry\Import\Replacements;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Caps;
use SiteFerry\Security\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a site after a move and offers safe fixes:
 *
 * - address: WordPress's address against the address the page was opened at;
 * - old_address: content still holding the old site's addresses or paths (after a move that
 *   changed the address), fixed with the same serialized-safe search and replace as an import;
 * - mixed_content: on an https site, content still linking to its own address over http;
 * - permalinks: the rewrite rules and, on Apache, the WordPress block in .htaccess;
 * - cron: whether WP-Cron is turned off, and whether the site can reach its own wp-cron.php;
 * - email: a test email to the site's admin address, on request only;
 * - caches: an object cache, a page cache drop-in or a known caching plugin to clear;
 * - search: whether search engines are asked to stay away;
 * - files: whether the uploads folder can be written.
 *
 * Reads only, except for the fixes, each of which the admin asks for and confirms.
 */
final class Checklist {

	/** Every item, in the order shown. */
	const ITEMS = array( 'address', 'old_address', 'mixed_content', 'permalinks', 'cron', 'email', 'caches', 'search', 'files' );

	/** The fixes and who may run them: content changes need restore, settings need settings. */
	const FIX_CAPS = array(
		'old_address'   => Caps::RESTORE,
		'mixed_content' => Caps::RESTORE,
		'permalinks'    => Caps::SETTINGS,
		'caches'        => Caps::SETTINGS,
		'email'         => Caps::SETTINGS,
		'search'        => Caps::SETTINGS,
	);

	/** The last move here, in SiteFerry's storage folder (the options table is rewritten by moves). */
	const MOVE_FILE = 'last-move.json';

	/** Days the "Check the moved site" card shows after a move. */
	const CARD_DAYS = 14;

	/** User meta: the move time a user dismissed the card for. */
	const DISMISSED = 'siteferry_checklist_dismissed';

	/** The job that rewrites addresses in the site's own tables. */
	const STEP = 'checklist.replace';

	/** Known caching plugins, by plugin folder: names only, to tell the admin what to clear. */
	const CACHE_PLUGINS = array(
		'wp-rocket'        => 'WP Rocket',
		'w3-total-cache'   => 'W3 Total Cache',
		'wp-super-cache'   => 'WP Super Cache',
		'litespeed-cache'  => 'LiteSpeed Cache',
		'wp-fastest-cache' => 'WP Fastest Cache',
		'sg-cachepress'    => 'SiteGround Speed Optimizer',
		'breeze'           => 'Breeze',
		'autoptimize'      => 'Autoptimize',
	);

	/** Where old addresses are looked for: table => [columns, extra condition]. */
	const CONTENT = array(
		'posts'    => array( array( 'post_content', 'post_excerpt' ), '' ),
		'postmeta' => array( array( 'meta_value' ), '' ),
		'options'  => array( array( 'option_value' ), "option_name NOT LIKE 'siteferry\\_%'" ),
		'comments' => array( array( 'comment_content', 'comment_author_url' ), '' ),
		'usermeta' => array( array( 'meta_value' ), '' ),
	);

	// ------------------------------------------------------------ The last move

	/**
	 * Remembers a move that changed the site's address (from the import's finish step).
	 *
	 * @param array $source The old site's addresses and paths (home, siteurl, wp_content_url, uploads_url, abspath, wp_content_dir, uploads_dir).
	 * @param array $target This site's, same keys.
	 */
	public static function record_move( array $source, array $target ): void {
		if ( self::same_site( (string) ( $source['home'] ?? '' ), (string) ( $target['home'] ?? '' ) ) ) {
			return;
		}
		$keep = array_merge( Replacements::URL_KEYS, Replacements::PATH_KEYS );
		$record = array(
			'from' => array_intersect_key( array_map( 'strval', $source ), array_flip( $keep ) ),
			'to'   => array_intersect_key( array_map( 'strval', $target ), array_flip( $keep ) ),
			'time' => time(),
		);
		$file = Storage::ensure( Storage::root() ) . '/' . self::MOVE_FILE;
		file_put_contents( $file, (string) wp_json_encode( $record ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a small record in SiteFerry's protected storage folder.
	}

	/**
	 * The last move that changed the address, or null.
	 *
	 * @return array{from:array,to:array,time:int}|null
	 */
	public static function last_move(): ?array {
		$root = Storage::root();
		$file = $root . '/' . self::MOVE_FILE;
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- SiteFerry's own record.
		return self::valid_move( $data );
	}

	/**
	 * A stored move record, checked; null if it is not one.
	 *
	 * @param mixed $data Decoded record.
	 */
	public static function valid_move( $data ): ?array {
		if ( ! is_array( $data ) || ! isset( $data['from']['home'], $data['to']['home'], $data['time'] ) || ! is_array( $data['from'] ) || ! is_array( $data['to'] ) ) {
			return null;
		}
		foreach ( array( $data['from']['home'], $data['to']['home'] ) as $home ) {
			if ( ! is_string( $home ) || ! preg_match( '#^https?://[^\s/]+#i', $home ) ) {
				return null;
			}
		}
		return array(
			'from' => array_map( 'strval', $data['from'] ),
			'to'   => array_map( 'strval', $data['to'] ),
			'time' => (int) $data['time'],
		);
	}

	/**
	 * Whether a user should see the "Check the moved site" card now.
	 *
	 * @param array|null $move      Last move.
	 * @param int        $dismissed The move time the user dismissed (0 for none).
	 * @param int        $now       Current time.
	 */
	public static function card_due( ?array $move, int $dismissed, int $now ): bool {
		return null !== $move && $move['time'] > $now - self::CARD_DAYS * DAY_IN_SECONDS && $dismissed !== $move['time'];
	}

	/**
	 * What the admin page needs for the card: the last move and whether to show it.
	 */
	public static function card(): array {
		$move = self::last_move();
		return array(
			'move' => null === $move ? null : array(
				'from' => $move['from']['home'],
				'to'   => $move['to']['home'],
				'time' => $move['time'],
			),
			'show' => self::card_due( $move, (int) get_user_meta( get_current_user_id(), self::DISMISSED, true ), time() ),
		);
	}

	/**
	 * Hides the card for this user until the next move.
	 */
	public static function dismiss(): void {
		$move = self::last_move();
		if ( null !== $move ) {
			update_user_meta( get_current_user_id(), self::DISMISSED, $move['time'] );
		}
	}

	// ------------------------------------------------------------ Decisions (no WordPress needed)

	/**
	 * Whether two addresses are the same site (scheme, "www." and a trailing slash ignored).
	 *
	 * @param string $a An address.
	 * @param string $b Another.
	 */
	public static function same_site( string $a, string $b ): bool {
		$key = static function ( string $url ): string {
			$url = strtolower( rtrim( $url, '/' ) );
			$url = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $url );
			return (string) preg_replace( '#^www\.#', '', $url );
		};
		return '' !== $a && $key( $a ) === $key( $b );
	}

	/**
	 * The address check: what WordPress calls itself against where the page was opened.
	 *
	 * @param string      $home    The home option (or WP_HOME).
	 * @param string|null $host    Host the request came in on (with port), null from WP-CLI.
	 * @param bool        $https   Whether the request used https.
	 * @return array{0:string,1:string} [state, reason]: ok, info (no request), warn host, warn scheme.
	 */
	public static function address_state( string $home, ?string $host, bool $https ): array {
		if ( null === $host || '' === $host ) {
			return array( 'info', 'no-request' );
		}
		$parts = wp_parse_url( $home );
		$home_host = strtolower( (string) ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
		if ( strtolower( $host ) !== $home_host ) {
			return array( 'warn', 'host' );
		}
		if ( $https && 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return array( 'warn', 'scheme' );
		}
		return array( 'ok', '' );
	}

	/**
	 * Search-replace pairs for content still holding the old site's addresses and paths.
	 *
	 * @param array|null $move Last move.
	 * @return array<string,string>
	 */
	public static function old_pairs( ?array $move ): array {
		return null === $move ? array() : Replacements::build( $move['from'], $move['to'] );
	}

	/**
	 * Pairs that turn http links to the site's own address into https on an https site.
	 *
	 * @param string $home Site address.
	 * @return array<string,string>
	 */
	public static function mixed_pairs( string $home ): array {
		$parts = wp_parse_url( $home );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return array();
		}
		$authority = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return array(
			'http://' . $authority     => 'https://' . $authority,
			'http:\\/\\/' . $authority => 'https:\\/\\/' . $authority,
		);
	}

	/**
	 * The shortest search strings that still find every pair.
	 *
	 * @param array<string,string> $pairs Pairs.
	 * @return string[]
	 */
	public static function needles( array $pairs ): array {
		return Table_Replacer::needles( array_map( 'strval', array_keys( $pairs ) ) );
	}

	/**
	 * Whether an item name is a known item; and whether it has a fix.
	 *
	 * @param string $item Item.
	 */
	public static function fixable( string $item ): bool {
		return isset( self::FIX_CAPS[ $item ] );
	}

	/**
	 * The search-engine state.
	 *
	 * @param string $blog_public The blog_public option.
	 * @param bool   $staging     Whether this is a SiteFerry staging copy.
	 */
	public static function search_state( string $blog_public, bool $staging ): string {
		if ( '0' !== $blog_public ) {
			return 'ok';
		}
		return $staging ? 'ok' : 'warn';
	}

	/**
	 * The permalink state.
	 *
	 * @param string $structure  permalink_structure.
	 * @param bool   $has_rules  Whether rewrite_rules holds rules.
	 * @param string $server     apache, nginx or other.
	 * @param bool   $has_block  Whether .htaccess holds WordPress's block (Apache).
	 * @return array{0:string,1:string} [state, reason]
	 */
	public static function permalink_state( string $structure, bool $has_rules, string $server, bool $has_block ): array {
		if ( '' === $structure ) {
			return array( 'ok', 'plain' );
		}
		if ( ! $has_rules ) {
			return array( 'warn', 'rules' );
		}
		if ( 'apache' === $server && ! $has_block ) {
			return array( 'warn', 'htaccess' );
		}
		if ( 'nginx' === $server ) {
			return array( 'info', 'nginx' );
		}
		return array( 'ok', '' );
	}

	// ------------------------------------------------------------ The report

	/**
	 * Runs every check.
	 *
	 * @param string|null $host  Host the request came in on, null from WP-CLI.
	 * @param bool        $https Whether the request used https.
	 * @return array{items:array,context:?array}
	 */
	public static function report( ?string $host, bool $https ): array {
		$move  = self::last_move();
		$items = array(
			self::address( $host, $https ),
			self::old_address( $move ),
			self::mixed_content(),
			self::permalinks(),
			self::cron(),
			self::email(),
			self::caches(),
			self::search(),
			self::files(),
		);
		$extra = Hooks::filter( 'siteferry_checklist_items', array(), $move );
		foreach ( is_array( $extra ) ? $extra : array() as $item ) {
			$clean = self::clean_item( $item );
			if ( null !== $clean ) {
				$items[] = $clean;
			}
		}
		return array(
			'items'   => $items,
			'context' => null === $move ? null : array(
				'from' => $move['from']['home'],
				'to'   => $move['to']['home'],
				'time' => $move['time'],
			),
		);
	}

	/**
	 * An item added by a developer, reduced to what the page shows (never a fix: only SiteFerry's
	 * own fixes can run).
	 *
	 * @param mixed $item Item from the filter.
	 */
	public static function clean_item( $item ): ?array {
		if ( ! is_array( $item ) || ! isset( $item['id'], $item['title'], $item['state'] ) || ! in_array( $item['state'], array( 'ok', 'info', 'warn', 'fail' ), true ) ) {
			return null;
		}
		$id = (string) $item['id'];
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,40}$/', $id ) || in_array( $id, self::ITEMS, true ) ) {
			return null;
		}
		return array(
			'id'     => $id,
			'title'  => substr( wp_strip_all_tags( (string) $item['title'] ), 0, 120 ),
			'state'  => (string) $item['state'],
			'detail' => substr( wp_strip_all_tags( (string) ( $item['detail'] ?? '' ) ), 0, 600 ),
			'fix'    => null,
		);
	}

	/**
	 * One item.
	 *
	 * @param string     $id     Item.
	 * @param string     $title  Title.
	 * @param string     $state  ok, info, warn or fail.
	 * @param string     $detail One or two plain sentences.
	 * @param array|null $fix    label and confirm, or null.
	 */
	private static function item( string $id, string $title, string $state, string $detail, ?array $fix = null ): array {
		return array(
			'id'     => $id,
			'title'  => $title,
			'state'  => $state,
			'detail' => $detail,
			'fix'    => null === $fix ? null : array(
				'id'      => $id,
				'label'   => $fix[0],
				'confirm' => $fix[1],
			),
		);
	}

	/**
	 * The site address against where the page was opened.
	 *
	 * @param string|null $host  Request host.
	 * @param bool        $https Request scheme.
	 */
	private static function address( ?string $host, bool $https ): array {
		$home    = untrailingslashit( (string) get_option( 'home' ) );
		$siteurl = untrailingslashit( (string) get_option( 'siteurl' ) );
		$title   = __( 'Site address', 'siteferry' );
		$where   = defined( 'WP_HOME' ) || defined( 'WP_SITEURL' )
			? __( 'It is set in wp-config.php (WP_HOME and WP_SITEURL).', 'siteferry' )
			: __( 'Change it under Settings, General.', 'siteferry' );
		list( $state, $reason ) = self::address_state( $home, $host, $https );
		if ( 'host' === $reason ) {
			/* translators: 1: address the page was opened at, 2: the site's address */
			return self::item( 'address', $title, 'warn', sprintf( __( 'You opened this page at %1$s, but WordPress calls this site %2$s.', 'siteferry' ), (string) $host, $home ) . ' ' . $where );
		}
		if ( 'scheme' === $reason ) {
			/* translators: %s: the site's address */
			return self::item( 'address', $title, 'warn', sprintf( __( 'This page uses https, but the site address is %s (http). Switch it to https so visitors get the secure address.', 'siteferry' ), $home ) . ' ' . $where );
		}
		if ( ! self::same_site( $home, $siteurl ) ) {
			/* translators: 1: the site's address, 2: the WordPress address */
			return self::item( 'address', $title, 'info', sprintf( __( 'The site address is %1$s and WordPress itself is at %2$s. That is fine when it is on purpose.', 'siteferry' ), $home, $siteurl ) );
		}
		if ( 'no-request' === $reason ) {
			/* translators: %s: the site's address */
			return self::item( 'address', $title, 'info', sprintf( __( 'The site address is %s. Open the checklist on the SiteFerry page to compare it with the address in your browser.', 'siteferry' ), $home ) );
		}
		/* translators: %s: the site's address */
		return self::item( 'address', $title, 'ok', sprintf( __( 'WordPress uses the address you opened it at: %s.', 'siteferry' ), $home ) );
	}

	/**
	 * Content still holding the old site's addresses or paths.
	 *
	 * @param array|null $move Last move.
	 */
	private static function old_address( ?array $move ): array {
		$title = __( 'Old addresses in the content', 'siteferry' );
		if ( null === $move ) {
			return self::item( 'old_address', $title, 'ok', __( 'No move to this site with a different address is recorded, so there is nothing to look for.', 'siteferry' ) );
		}
		$counts = self::count_matches( self::needles( self::old_pairs( $move ) ) );
		$total  = array_sum( $counts );
		$from   = $move['from']['home'];
		if ( 0 === $total ) {
			/* translators: %s: the old site's address */
			return self::item( 'old_address', $title, 'ok', sprintf( __( 'No content still points to %s.', 'siteferry' ), $from ) );
		}
		if ( is_multisite() ) {
			/* translators: 1: a number, 2: the old site's address */
			return self::item( 'old_address', $title, 'warn', sprintf( _n( '%1$d entry still points to %2$s. On a network, fix it with WP-CLI (wp search-replace).', '%1$d entries still point to %2$s. On a network, fix it with WP-CLI (wp search-replace).', $total, 'siteferry' ), $total, $from ) );
		}
		return self::item(
			'old_address',
			$title,
			'warn',
			/* translators: 1: a number, 2: the old site's address, 3: where, such as posts 4, options 2 */
			sprintf( _n( '%1$d entry still points to %2$s (%3$s), for example a link or an image added after the backup was made.', '%1$d entries still point to %2$s (%3$s), for example links or images added after the backup was made.', $total, 'siteferry' ), $total, $from, self::where( $counts ) ),
			array(
				__( 'Change them to this site’s address', 'siteferry' ),
				/* translators: 1: the old site's address, 2: this site's address */
				sprintf( __( 'Change every %1$s in this site’s database to %2$s? This rewrites content (serialized data stays valid). A backup first is a good idea; it cannot be undone otherwise.', 'siteferry' ), $from, $move['to']['home'] ),
			)
		);
	}

	/**
	 * Http links to the site's own address on an https site.
	 */
	private static function mixed_content(): array {
		$title = __( 'Mixed content (http on an https site)', 'siteferry' );
		$home  = untrailingslashit( (string) get_option( 'home' ) );
		$pairs = self::mixed_pairs( $home );
		if ( array() === $pairs ) {
			return self::item( 'mixed_content', $title, 'info', __( 'The site does not use https, so there is nothing to check.', 'siteferry' ) );
		}
		$counts = self::count_matches( self::needles( $pairs ) );
		$total  = array_sum( $counts );
		if ( 0 === $total ) {
			return self::item( 'mixed_content', $title, 'ok', __( 'No content links to this site over http.', 'siteferry' ) );
		}
		if ( is_multisite() ) {
			/* translators: %d: a number */
			return self::item( 'mixed_content', $title, 'warn', sprintf( _n( '%d entry links to this site over http. On a network, fix it with WP-CLI (wp search-replace).', '%d entries link to this site over http. On a network, fix it with WP-CLI (wp search-replace).', $total, 'siteferry' ), $total ) );
		}
		$http = (string) array_keys( $pairs )[0];
		return self::item(
			'mixed_content',
			$title,
			'warn',
			/* translators: 1: a number, 2: where, such as posts 4, options 2 */
			sprintf( _n( '%1$d entry links to this site over http (%2$s); browsers may block those images or scripts.', '%1$d entries link to this site over http (%2$s); browsers may block those images or scripts.', $total, 'siteferry' ), $total, self::where( $counts ) ),
			array(
				__( 'Change them to https', 'siteferry' ),
				/* translators: 1: an http address, 2: the same address with https */
				sprintf( __( 'Change every %1$s in this site’s database to %2$s? This rewrites content (serialized data stays valid). A backup first is a good idea.', 'siteferry' ), $http, (string) $pairs[ $http ] ),
			)
		);
	}

	/**
	 * Permalinks.
	 */
	private static function permalinks(): array {
		$title     = __( 'Permalinks', 'siteferry' );
		$structure = (string) get_option( 'permalink_structure' );
		$rules     = get_option( 'rewrite_rules' );
		$server    = self::server();
		$has_block = true;
		if ( 'apache' === $server ) {
			$htaccess  = self::htaccess();
			$has_block = is_file( $htaccess ) && false !== strpos( (string) file_get_contents( $htaccess ), '# BEGIN WordPress' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the site's .htaccess.
		}
		list( $state, $reason ) = self::permalink_state( $structure, is_array( $rules ) && array() !== $rules, $server, $has_block );
		$fix = array(
			__( 'Refresh the permalinks', 'siteferry' ),
			__( 'Refresh the permalink rules? This is the same as saving Settings, Permalinks.', 'siteferry' ),
		);
		switch ( $reason ) {
			case 'plain':
				return self::item( 'permalinks', $title, 'ok', __( 'The site uses plain links (?p=123), which need no server rules.', 'siteferry' ) );
			case 'rules':
				return self::item( 'permalinks', $title, 'warn', __( 'The permalink rules are missing, so pretty links may show "Not found".', 'siteferry' ), $fix );
			case 'htaccess':
				return self::item( 'permalinks', $title, 'warn', __( 'The .htaccess file has no WordPress rules, so pretty links may show "Not found".', 'siteferry' ), $fix );
			case 'nginx':
				return self::item( 'permalinks', $title, 'info', __( 'This server runs nginx: pretty links work when its configuration sends unknown addresses to index.php (try_files $uri $uri/ /index.php?$args). Open a post to check.', 'siteferry' ), $fix );
		}
		return self::item( 'permalinks', $title, 'ok', __( 'The permalink rules are in place.', 'siteferry' ), $fix );
	}

	/**
	 * WP-Cron.
	 */
	private static function cron(): array {
		$title = __( 'Scheduled tasks (WP-Cron)', 'siteferry' );
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return self::item( 'cron', $title, 'info', __( 'WP-Cron is turned off in wp-config.php. Make sure the new server runs it on a schedule (a cron job for wp-cron.php or "wp cron event run --due-now"), or scheduled backups and posts will not run.', 'siteferry' ) );
		}
		// A POST with a body makes wp-cron.php stop at once, so this only proves the site can reach itself.
		$response = wp_remote_post(
			site_url( 'wp-cron.php' ),
			array(
				'timeout'   => 5,
				'body'      => array( 'siteferry_probe' => '1' ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as core uses it for this request.
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message */
			return self::item( 'cron', $title, 'warn', sprintf( __( 'The site could not reach itself to run scheduled tasks: %s. Ask the host to allow it, or run WP-Cron from a server cron job.', 'siteferry' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			/* translators: %d: an HTTP status code */
			return self::item( 'cron', $title, 'warn', sprintf( __( 'The site reached itself, but wp-cron.php answered with HTTP %d. Scheduled tasks may not run.', 'siteferry' ), $code ) );
		}
		return self::item( 'cron', $title, 'ok', __( 'The site can reach its own wp-cron.php, so scheduled tasks can run.', 'siteferry' ) );
	}

	/**
	 * Email.
	 */
	private static function email(): array {
		$to = (string) get_option( 'admin_email' );
		return self::item(
			'email',
			__( 'Email', 'siteferry' ),
			'info',
			/* translators: %s: an email address */
			sprintf( __( 'A new server often sends email differently. Send a test to %s and check it arrives (also in spam).', 'siteferry' ), $to ),
			array(
				__( 'Send a test email', 'siteferry' ),
				/* translators: %s: an email address */
				sprintf( __( 'Send a test email to %s?', 'siteferry' ), $to ),
			)
		);
	}

	/**
	 * Caches.
	 */
	private static function caches(): array {
		$title  = __( 'Caches', 'siteferry' );
		$found  = array();
		$object = wp_using_ext_object_cache() || is_file( WP_CONTENT_DIR . '/object-cache.php' );
		if ( $object ) {
			$found[] = __( 'an object cache', 'siteferry' );
		}
		if ( is_file( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$found[] = __( 'a page cache (advanced-cache.php)', 'siteferry' );
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );
			if ( isset( self::CACHE_PLUGINS[ $folder ] ) ) {
				$found[] = self::CACHE_PLUGINS[ $folder ];
			}
		}
		$found = array_values( array_unique( $found ) );
		if ( array() === $found ) {
			return self::item( 'caches', $title, 'ok', __( 'No cache found that could still serve the old site.', 'siteferry' ) );
		}
		$detail = sprintf(
			/* translators: %s: list of caches, such as an object cache, WP Rocket */
			__( 'Found: %s. Clear them so visitors see the moved site, not saved copies of the old one.', 'siteferry' ),
			implode( ', ', $found )
		);
		return self::item(
			'caches',
			$title,
			'warn',
			$detail,
			$object ? array( __( 'Clear the object cache', 'siteferry' ), __( 'Clear the object cache now? Plugins with their own page cache have their own button for that.', 'siteferry' ) ) : null
		);
	}

	/**
	 * Search engines.
	 */
	private static function search(): array {
		$title   = __( 'Search engines', 'siteferry' );
		$staging = defined( 'SITEFERRY_STAGING' ) && SITEFERRY_STAGING;
		$public  = (string) get_option( 'blog_public' );
		if ( 'ok' === self::search_state( $public, $staging ) ) {
			return self::item( 'search', $title, 'ok', '0' === $public ? __( 'This is a staging copy, so search engines are kept away on purpose.', 'siteferry' ) : __( 'Search engines may index the site.', 'siteferry' ) );
		}
		return self::item(
			'search',
			$title,
			'warn',
			__( 'Search engines are asked not to index this site (Settings, Reading). Leave it if the site is not public yet.', 'siteferry' ),
			array( __( 'Let search engines index it', 'siteferry' ), __( 'Let search engines index this site? Only do this if it is the live site.', 'siteferry' ) )
		);
	}

	/**
	 * The uploads folder.
	 */
	private static function files(): array {
		$title   = __( 'Uploads folder', 'siteferry' );
		$uploads = wp_upload_dir( null, false );
		$dir     = (string) $uploads['basedir'];
		if ( is_dir( $dir ) && wp_is_writable( $dir ) ) {
			return self::item( 'files', $title, 'ok', __( 'New media can be uploaded.', 'siteferry' ) );
		}
		/* translators: %s: a folder */
		return self::item( 'files', $title, 'fail', sprintf( __( 'WordPress cannot write to %s, so media uploads will fail. Ask the host to fix the folder\'s permissions.', 'siteferry' ), $dir ) );
	}

	// ------------------------------------------------------------ Fixes

	/**
	 * Runs a fix the admin confirmed. Search-and-replace fixes start a job; the others finish at once.
	 *
	 * @param string $item Item.
	 * @return array{done?:string,job?:Job,token?:string}
	 * @throws Job_Exception If the item has no fix or there is nothing to fix.
	 */
	public static function fix( string $item ): array {
		switch ( $item ) {
			case 'old_address':
				$move = self::last_move();
				if ( null === $move ) {
					throw new Job_Exception( __( 'No move with a different address is recorded.', 'siteferry' ), Job_Exception::STEP );
				}
				return self::start_replace( self::old_pairs( $move ), $item );
			case 'mixed_content':
				return self::start_replace( self::mixed_pairs( untrailingslashit( (string) get_option( 'home' ) ) ), $item );
			case 'permalinks':
				$hard = 'apache' === self::server() && wp_is_writable( self::htaccess() );
				flush_rewrite_rules( $hard );
				return array( 'done' => __( 'The permalink rules were refreshed.', 'siteferry' ) );
			case 'caches':
				wp_cache_flush();
				return array( 'done' => __( 'The object cache was cleared.', 'siteferry' ) );
			case 'search':
				update_option( 'blog_public', '1' );
				return array( 'done' => __( 'Search engines may now index the site.', 'siteferry' ) );
			case 'email':
				return array( 'done' => self::send_test_email() );
		}
		throw new Job_Exception( __( 'This item has no fix.', 'siteferry' ), Job_Exception::STEP );
	}

	/**
	 * Sends the test email to the site's admin address.
	 *
	 * @throws Job_Exception If it could not be sent.
	 */
	public static function send_test_email(): string {
		$to    = (string) get_option( 'admin_email' );
		$error = '';
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail(
			$to,
			/* translators: %s: the site's name */
			sprintf( __( '[%s] Test email from SiteFerry', 'siteferry' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
			/* translators: %s: the site's address */
			sprintf( __( "This is a test email sent from the SiteFerry checklist on %s.\n\nIf you received it, this server can send email.", 'siteferry' ), home_url() )
		);
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $sent ) {
			/* translators: 1: an email address, 2: the reason */
			throw new Job_Exception( sprintf( __( 'The test email to %1$s could not be sent: %2$s', 'siteferry' ), $to, '' === $error ? __( 'no reason given', 'siteferry' ) : $error ), Job_Exception::STEP );
		}
		/* translators: %s: an email address */
		return sprintf( __( 'The test email was sent to %s. Check that it arrived, also in spam.', 'siteferry' ), $to );
	}

	/**
	 * Starts the job that rewrites addresses in this site's tables.
	 *
	 * @param array<string,string> $pairs Search => replace.
	 * @param string               $item  The item it fixes.
	 * @return array{job:Job,token:string}
	 * @throws Job_Exception If there is nothing to change or the site is a network.
	 */
	private static function start_replace( array $pairs, string $item ): array {
		global $wpdb;
		if ( is_multisite() ) {
			throw new Job_Exception( __( 'On a network, use WP-CLI (wp search-replace) for this.', 'siteferry' ), Job_Exception::STEP );
		}
		if ( array() === $pairs ) {
			throw new Job_Exception( __( 'There is nothing to change.', 'siteferry' ), Job_Exception::STEP );
		}
		$active = Job::active( Storage::jobs_dir() );
		if ( null !== $active ) {
			throw new Job_Exception( sprintf( /* translators: 1: a kind of job, such as "export" or "import", 2: the job's ID */ __( 'Another %1$s is still in progress (job %2$s). Finish or cancel it first.', 'siteferry' ), $active->type(), $active->id() ), Job_Exception::BUSY );
		}
		$tables = Raw_Query::column( $wpdb, 'SHOW TABLES LIKE ' . Raw_Query::quote( $wpdb, $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		sort( $tables );
		return array_combine(
			array( 'job', 'token' ),
			Job::create(
				Storage::jobs_dir(),
				'checklist',
				array( self::STEP ),
				array(
					'item'   => $item,
					'pairs'  => $pairs,
					'tables' => array_values( $tables ),
					'skip'   => array( $wpdb->posts => array( 'guid' ) ),
				)
			)
		);
	}

	// ------------------------------------------------------------ Helpers

	/**
	 * How many rows of the content tables hold any of the needles: short table name => rows.
	 *
	 * @param string[] $needles Search strings.
	 * @return array<string,int>
	 */
	private static function count_matches( array $needles ): array {
		global $wpdb;
		$counts = array();
		if ( array() === $needles ) {
			return $counts;
		}
		foreach ( self::CONTENT as $table => list( $columns, $extra ) ) {
			$name = $wpdb->$table;
			$any  = array();
			foreach ( $columns as $column ) {
				foreach ( $needles as $needle ) {
					$any[] = '`' . $column . '` LIKE ' . Raw_Query::quote( $wpdb, '%' . $wpdb->esc_like( $needle ) . '%' );
				}
			}
			$where = '(' . implode( ' OR ', $any ) . ')' . ( '' === $extra ? '' : ' AND ' . $extra );
			$rows  = Raw_Query::column( $wpdb, 'SELECT COUNT(*) FROM `' . str_replace( '`', '``', (string) $name ) . '` WHERE ' . $where );
			$count = isset( $rows[0] ) ? (int) $rows[0] : 0;
			if ( $count > 0 ) {
				$counts[ $table ] = $count;
			}
		}
		return $counts;
	}

	/**
	 * Where matches were found, in words ("posts 4, options 2").
	 *
	 * @param array<string,int> $counts Table => rows.
	 */
	private static function where( array $counts ): string {
		$names = array(
			'posts'    => __( 'posts', 'siteferry' ),
			'postmeta' => __( 'post details', 'siteferry' ),
			'options'  => __( 'settings', 'siteferry' ),
			'comments' => __( 'comments', 'siteferry' ),
			'usermeta' => __( 'user details', 'siteferry' ),
		);
		$parts = array();
		foreach ( $counts as $table => $count ) {
			$parts[] = ( $names[ $table ] ?? $table ) . ' ' . $count;
		}
		return implode( ', ', $parts );
	}

	/**
	 * The web server, as far as WordPress knows: apache (also LiteSpeed), nginx or other.
	 */
	private static function server(): string {
		global $is_apache, $is_nginx;
		if ( ! empty( $is_apache ) ) {
			return 'apache';
		}
		return empty( $is_nginx ) ? 'other' : 'nginx';
	}

	/**
	 * The site's .htaccess file.
	 */
	private static function htaccess(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return get_home_path() . '.htaccess';
	}
}
