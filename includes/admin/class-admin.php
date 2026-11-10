<?php
/**
 * The SiteFerry admin page.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Admin;

use SiteFerry\Requirements;
use SiteFerry\Rest\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * One menu page with the tabs Export, Import, Backups, Storage and Log.
 *
 * The page is static markup; assets/admin.js drives it through the REST API (siteferry/v1).
 * Only administrators (manage_options) see it.
 */
final class Admin {

	const SLUG = 'siteferry';

	/** The maker's website (a plain link; nothing is loaded from it). */
	const MAKER_URL = 'https://xsofty.com/';

	/**
	 * Hooks the menu.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	/**
	 * Adds the menu page and loads assets only there.
	 */
	public static function menu(): void {
		if ( is_multisite() && ! is_main_site() ) {
			return; // A network is backed up whole, from its main site.
		}
		$hook = add_menu_page(
			__( 'SiteFerry', 'siteferry' ),
			__( 'SiteFerry', 'siteferry' ),
			is_multisite() ? 'manage_network_options' : 'manage_options',
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-migrate',
			80
		);
		add_action( 'load-' . $hook, array( self::class, 'load' ) );
	}

	/**
	 * Enqueues the page's script and styles.
	 */
	public static function load(): void {
		$url = plugins_url( 'assets/', SITEFERRY_FILE );
		// The file time in the version makes browsers fetch changed files even within one release.
		$version = static function ( string $file ): string {
			$time = @filemtime( SITEFERRY_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return SITEFERRY_VERSION . ( false === $time ? '' : '.' . $time );
		};
		// This page only (load-{page} hook): credit the maker in the admin footer.
		add_filter( 'admin_footer_text', array( self::class, 'footer_text' ) );
		wp_enqueue_style( 'siteferry-admin', $url . 'admin.css', array(), $version( 'admin.css' ) );
		wp_enqueue_script( 'siteferry-admin', $url . 'admin.js', array( 'wp-i18n' ), $version( 'admin.js' ), true );
		wp_set_script_translations( 'siteferry-admin', 'siteferry' ); // The page's texts, in the site's language.
		// WordPress removes "error" from the address bar before the script runs, so a refusal from
		// the OAuth provider is passed along here. Display only: nothing is saved from a GET.
		$oauth_error = isset( $_GET['oauth'], $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_add_inline_script(
			'siteferry-admin',
			'window.SiteFerryConfig = ' . wp_json_encode(
				array(
					'root'       => esc_url_raw( rest_url( Rest::NS . '/' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'home'       => home_url(),
					'compress'   => Requirements::can_compress(),
					'chunk'      => Rest::chunk_size(),
					'loginUrl'   => wp_login_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
					'oauthError' => $oauth_error,
					'network'    => is_multisite(), // A network is restored whole: no partial restore, no new address.
					'siteName'   => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), // Default name of a backup folder in Google Drive.
					'choices'    => Choices::get( get_current_user_id() ),
					'stagingOf'  => self::staging_of(), // In a staging copy: the live site it copies.
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Footer text on the SiteFerry page.
	 */
	public static function footer_text(): string {
		return sprintf(
			/* translators: %s: link to the maker's website */
			esc_html__( 'SiteFerry is a product of %s.', 'siteferry' ),
			'<a href="' . esc_url( self::MAKER_URL ) . '" target="_blank" rel="noopener noreferrer">xsofty</a>'
		);
	}

	/**
	 * Prints the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to use SiteFerry.', 'siteferry' ) );
		}
		$tabs = array(
			'backups'  => __( 'Backups', 'siteferry' ),
			'storage'  => __( 'Storage', 'siteferry' ),
			'activity' => __( 'Activity', 'siteferry' ),
		);
		$whole_parts = array(
			'exclude_media'   => __( 'Media library (uploads)', 'siteferry' ),
			'exclude_plugins' => __( 'Plugins', 'siteferry' ),
			'exclude_themes'  => __( 'Themes', 'siteferry' ),
		);
		$clutter     = array(
			'exclude_inactive_plugins' => array( __( 'Inactive plugins', 'siteferry' ), false ),
			'exclude_inactive_themes'  => array( __( 'Inactive themes', 'siteferry' ), false ),
			'exclude_cache'            => array( __( 'Cache folders', 'siteferry' ), true ),
			'exclude_spam_comments'    => array( __( 'Spam comments', 'siteferry' ), false ),
			'exclude_revisions'        => array( __( 'Post revisions', 'siteferry' ), false ),
		);
		$host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$port        = wp_parse_url( home_url(), PHP_URL_PORT );
		$host       .= $port ? ':' . $port : '';
		?>
		<div class="wrap siteferry" id="siteferry">
			<header class="sf-head">
				<h1 class="sf-mark">
					<svg class="sf-mark-icon" viewBox="0 0 32 20" aria-hidden="true" focusable="false"><path d="M9 3h9l3 5H6z"/><path d="M1 9h30l-4 8H5z"/></svg>
					<?php esc_html_e( 'SiteFerry', 'siteferry' ); ?>
				</h1>
				<a class="sf-by" href="<?php echo esc_url( self::MAKER_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'by xsofty', 'siteferry' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'siteferry' ); ?></span></a>
				<p class="sf-facts" id="siteferry-facts">
					<span class="sf-fact sf-fact-host"><?php echo esc_html( $host ); ?></span>
					<span class="sf-fact" id="siteferry-facts-backups" hidden></span>
					<span class="sf-fact" id="siteferry-facts-storage" hidden></span>
				</p>
			</header>
			<hr class="wp-header-end">
			<div id="siteferry-notice" class="sf-notice" role="status" hidden></div>
			<div id="siteferry-active" class="sf-active" hidden>
				<p class="sf-active-text" id="siteferry-active-text"></p>
				<p class="sf-active-actions">
					<button type="button" class="button button-primary" id="siteferry-active-resume" hidden><?php esc_html_e( 'Resume', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-active-cancel"><?php esc_html_e( 'Cancel it', 'siteferry' ); ?></button>
				</p>
			</div>

			<nav class="nav-tab-wrapper sf-tabs" aria-label="<?php esc_attr_e( 'SiteFerry sections', 'siteferry' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?><?php if ( 'backups' === $id ) : ?> <span class="sf-tab-count" id="siteferry-tab-count" hidden></span><?php endif; ?></a>
				<?php endforeach; ?>
			</nav>

			<section class="siteferry-panel" data-panel="backups" hidden>
				<div class="sf-card sf-home">
					<div class="sf-home-head">
						<div class="sf-home-title">
							<h2 class="sf-card-title"><?php esc_html_e( 'Backups', 'siteferry' ); ?></h2>
							<p class="sf-home-summary" id="siteferry-summary"><?php esc_html_e( 'Make a backup of this whole site, or restore one from the list below.', 'siteferry' ); ?></p>
						</div>
						<div class="sf-tools">
							<button type="button" class="button sf-btn" id="siteferry-upload-toggle" aria-expanded="false" aria-controls="siteferry-upload"><span class="dashicons dashicons-upload" aria-hidden="true"></span><?php esc_html_e( 'Upload a backup', 'siteferry' ); ?></button>
							<button type="button" class="button sf-btn" id="siteferry-schedule-toggle" aria-expanded="false" aria-controls="siteferry-schedule-form"><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e( 'Schedule', 'siteferry' ); ?><span class="sf-btn-state" id="siteferry-schedule-state" hidden></span></button>
							<button type="button" class="button sf-btn" id="siteferry-move-toggle" aria-expanded="false" aria-controls="siteferry-move"><span class="dashicons dashicons-migrate" aria-hidden="true"></span><?php esc_html_e( 'Move a site', 'siteferry' ); ?></button>
							<button type="button" class="button sf-btn" id="siteferry-kit-toggle" aria-expanded="false" aria-controls="siteferry-kit"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Emergency kit', 'siteferry' ); ?></button>
							<button type="button" class="button sf-btn" id="siteferry-staging-toggle" aria-expanded="false" aria-controls="siteferry-staging"><span class="dashicons dashicons-admin-multisite" aria-hidden="true"></span><?php esc_html_e( 'Staging', 'siteferry' ); ?></button>
						</div>
					</div>
					<div class="sf-now">
						<button type="submit" form="siteferry-export-form" class="button button-primary sf-btn sf-btn-now" id="siteferry-backup-now"><span class="dashicons dashicons-backup" aria-hidden="true"></span><span class="sf-btn-label"><?php esc_html_e( 'Back up now', 'siteferry' ); ?></span></button>
						<p class="sf-now-line" id="siteferry-now-line" aria-live="polite"></p>
						<button type="button" class="button sf-btn" id="siteferry-options-toggle" aria-expanded="false" aria-controls="siteferry-export-form"><span class="dashicons dashicons-edit" aria-hidden="true"></span><?php esc_html_e( 'Customize', 'siteferry' ); ?></button>
					</div>
					<p class="sf-schedule-line" id="siteferry-schedule-line" hidden></p>

					<form id="siteferry-export-form" class="sf-drawer" hidden>
						<div class="sf-drawer-head">
							<h3 class="sf-drawer-title"><?php esc_html_e( 'Customize “Back up now”', 'siteferry' ); ?></h3>
							<p class="sf-drawer-note"><?php esc_html_e( 'Changes here apply to your next backup. Click “Save as my settings” to keep them for every backup you make with “Back up now”.', 'siteferry' ); ?></p>
						</div>
						<h3 class="sf-sub"><?php esc_html_e( 'What goes into the backup', 'siteferry' ); ?></h3>
						<p class="sf-lead"><?php esc_html_e( 'The database and everything in wp-content, as one file. Other backup plugins\' folders, upgrade folders, .git and node_modules are always left out.', 'siteferry' ); ?></p>
						<div class="sf-groups">
							<fieldset class="sf-checks">
								<legend><?php esc_html_e( 'Leave out whole parts', 'siteferry' ); ?></legend>
								<?php foreach ( $whole_parts as $name => $label ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>"> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<fieldset class="sf-checks">
								<legend><?php esc_html_e( 'Leave out clutter', 'siteferry' ); ?></legend>
								<?php foreach ( $clutter as $name => list( $label, $checked ) ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" <?php checked( $checked ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</fieldset>
						</div>
						<details class="sf-more">
							<summary><?php esc_html_e( 'Leave out single database tables', 'siteferry' ); ?> <span class="sf-more-count" id="siteferry-tables-count" hidden></span></summary>
							<div id="siteferry-tables" class="sf-tables"><?php esc_html_e( 'Loading...', 'siteferry' ); ?></div>
						</details>
						<div class="sf-space" id="siteferry-space" aria-live="polite">
							<p class="sf-space-line" id="siteferry-space-line"><?php esc_html_e( 'Measuring the site...', 'siteferry' ); ?></p>
							<details class="sf-more" id="siteferry-space-details" hidden>
								<summary><?php esc_html_e( 'What takes the room', 'siteferry' ); ?></summary>
								<div class="sf-space-body" id="siteferry-space-body"></div>
							</details>
						</div>
						<fieldset class="sf-format">
							<legend><?php esc_html_e( 'File', 'siteferry' ); ?></legend>
							<div class="sf-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Backup file format', 'siteferry' ); ?>">
								<label class="sf-choice">
									<input type="radio" name="format" value="tar" checked>
									<span class="sf-choice-name"><?php esc_html_e( 'SiteFerry backup (.tar)', 'siteferry' ); ?></span>
									<span class="sf-choice-note"><?php esc_html_e( 'Every file is checked when it is imported.', 'siteferry' ); ?></span>
								</label>
								<label class="sf-choice">
									<input type="radio" name="format" value="wpress">
									<span class="sf-choice-name"><?php esc_html_e( '.wpress file', 'siteferry' ); ?></span>
									<span class="sf-choice-note"><?php esc_html_e( 'For importing with the All-in-One WP Migration plugin.', 'siteferry' ); ?></span>
								</label>
							</div>
							<?php if ( Requirements::can_compress() ) : ?>
								<p class="sf-compress" id="siteferry-compress-row"><label><input type="checkbox" name="compress"> <?php esc_html_e( 'Compress the file (.tar.gz): smaller, but takes longer to make', 'siteferry' ); ?></label></p>
							<?php endif; ?>
							<div class="sf-changes-only" id="siteferry-changes-row">
								<p><label><input type="checkbox" name="changes_only" id="siteferry-changes-only"> <?php esc_html_e( 'Only changes since the last full backup', 'siteferry' ); ?></label></p>
								<p class="description" id="siteferry-changes-note"><?php esc_html_e( 'Much smaller and quicker: the whole database, and only the files added or changed since then. Restoring it needs that full backup too, so it stays on this server.', 'siteferry' ); ?></p>
							</div>
							<?php if ( \SiteFerry\Archive\Crypto::available() ) : ?>
								<div class="sf-encrypt" id="siteferry-encrypt-row">
									<p><label><input type="checkbox" id="siteferry-encrypt"> <?php esc_html_e( 'Protect the backup with a password', 'siteferry' ); ?></label></p>
									<div class="sf-encrypt-fields" id="siteferry-encrypt-fields" hidden>
										<p class="sf-field">
											<label for="siteferry-password"><?php esc_html_e( 'Password', 'siteferry' ); ?></label>
											<input type="password" id="siteferry-password" autocomplete="new-password" minlength="8">
										</p>
										<p class="sf-field">
											<label for="siteferry-password-again"><?php esc_html_e( 'The same password again', 'siteferry' ); ?></label>
											<input type="password" id="siteferry-password-again" autocomplete="new-password" minlength="8">
										</p>
										<p class="description sf-field-wide"><?php esc_html_e( 'The backup is encrypted with AES-256 (the file name ends in .enc). SiteFerry does not keep this password: without it, nobody can restore the backup, not even you.', 'siteferry' ); ?></p>
									</div>
								</div>
							<?php endif; ?>
						</fieldset>

						<h3 class="sf-sub"><?php esc_html_e( 'Where it goes', 'siteferry' ); ?></h3>
						<div class="sf-choices" id="siteferry-send-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Where the backup goes', 'siteferry' ); ?>">
							<label class="sf-choice">
								<input type="radio" name="send_to" value="" checked>
								<span class="sf-choice-name"><?php esc_html_e( 'This server', 'siteferry' ); ?></span>
								<span class="sf-choice-note"><?php esc_html_e( 'Kept in SiteFerry\'s protected folder; download it from the list whenever you like.', 'siteferry' ); ?></span>
							</label>
						</div>
						<p class="sf-delete-after" id="siteferry-delete-after" hidden><label><input type="checkbox" name="delete_after_send"> <?php esc_html_e( 'Then delete the copy on this server', 'siteferry' ); ?></label></p>
						<p class="description" id="siteferry-no-storage" hidden>
							<?php esc_html_e( 'To send backups to S3, an FTP server, Google Drive or Dropbox, set one up first.', 'siteferry' ); ?>
							<a href="#storage" data-goto="storage"><?php esc_html_e( 'Set up storage', 'siteferry' ); ?></a>
						</p>
						<p class="sf-download-ready"><label><input type="checkbox" id="siteferry-download-ready"> <?php esc_html_e( 'Also download it to my computer when it is ready', 'siteferry' ); ?></label></p>

						<h3 class="sf-sub"><?php esc_html_e( 'This backup only: name, note and tags', 'siteferry' ); ?></h3>
						<div class="sf-label-fields">
							<p class="sf-field">
								<label for="siteferry-title"><?php esc_html_e( 'Name (optional)', 'siteferry' ); ?></label>
								<input type="text" id="siteferry-title" name="title" maxlength="100" placeholder="<?php esc_attr_e( 'Before the theme update', 'siteferry' ); ?>">
							</p>
							<p class="sf-field">
								<label for="siteferry-tags"><?php esc_html_e( 'Tags (optional)', 'siteferry' ); ?></label>
								<input type="text" id="siteferry-tags" name="tags" maxlength="200" placeholder="<?php esc_attr_e( 'before-update, monthly', 'siteferry' ); ?>">
								<span class="description"><?php esc_html_e( 'Separate tags with commas. Click a tag in the list to show only backups with it.', 'siteferry' ); ?></span>
							</p>
							<p class="sf-field sf-field-wide">
								<label for="siteferry-note"><?php esc_html_e( 'Note (optional)', 'siteferry' ); ?></label>
								<textarea id="siteferry-note" name="note" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'What changed, or why you made this backup', 'siteferry' ); ?>"></textarea>
							</p>
						</div>
						<div class="sf-drawer-actions">
							<button type="submit" class="button button-primary sf-btn"><span class="dashicons dashicons-backup" aria-hidden="true"></span><?php esc_html_e( 'Back up now', 'siteferry' ); ?></button>
							<button type="button" class="button sf-btn" id="siteferry-options-save" disabled><?php esc_html_e( 'Save as my settings', 'siteferry' ); ?></button>
							<button type="button" class="button sf-quiet" id="siteferry-options-defaults"><?php esc_html_e( 'Reset to defaults', 'siteferry' ); ?></button>
							<button type="button" class="button sf-quiet sf-push" id="siteferry-options-close"><?php esc_html_e( 'Close', 'siteferry' ); ?></button>
							<span class="description sf-drawer-foot" id="siteferry-options-state"><?php esc_html_e( 'The name, note, tags and password are only ever used for one backup.', 'siteferry' ); ?></span>
						</div>
					</form>

					<div id="siteferry-upload" class="sf-drawer" hidden>
						<p class="sf-drawer-note"><?php esc_html_e( 'Upload a backup to restore it here, or to move another site here. It is added to the list below; nothing changes until you choose to restore it.', 'siteferry' ); ?></p>
						<input type="file" id="siteferry-file" class="sf-file-input" multiple>
						<label for="siteferry-file" class="sf-drop" id="siteferry-drop">
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<span class="sf-drop-main"><?php esc_html_e( 'Drop a backup file here, or choose one', 'siteferry' ); ?></span>
							<span class="sf-drop-note"><?php esc_html_e( 'A .tar or .tar.gz made by SiteFerry, or a .wpress made by All-in-One WP Migration (without a password). Large files upload in pieces; an interrupted upload continues when you choose the same file again.', 'siteferry' ); ?></span>
						</label>
						<div class="sf-progress" id="siteferry-upload-progress" hidden></div>
						<p id="siteferry-unfinished-uploads" class="description" hidden></p>
					</div>

					<?php self::render_schedule( $whole_parts, $clutter ); ?>

					<div id="siteferry-move" class="sf-drawer sf-move" hidden>
						<p class="sf-drawer-note"><?php esc_html_e( 'Move a site straight from one server to another, with SiteFerry on both. Start on the new site.', 'siteferry' ); ?></p>
						<div class="sf-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Which site is this?', 'siteferry' ); ?>">
							<label class="sf-choice">
								<input type="radio" name="siteferry_move_mode" value="receive">
								<span class="sf-choice-name"><?php esc_html_e( 'Receive a site here', 'siteferry' ); ?></span>
								<span class="sf-choice-note"><?php esc_html_e( 'This is the new site: make a key for the old one.', 'siteferry' ); ?></span>
							</label>
							<label class="sf-choice">
								<input type="radio" name="siteferry_move_mode" value="send" checked>
								<span class="sf-choice-name"><?php esc_html_e( 'Send this site to another server', 'siteferry' ); ?></span>
								<span class="sf-choice-note"><?php esc_html_e( 'This is the old site: paste the key from the new one.', 'siteferry' ); ?></span>
							</label>
						</div>
						<div class="sf-move-half" id="siteferry-move-send">
							<ol class="sf-steps">
								<li><?php esc_html_e( 'On the new site, install SiteFerry, open "Move a site", choose "Receive a site here" and click "Make a key".', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'Paste that key below and click "Check the key".', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'Click "Move this site there". This site is backed up, encrypted with the key and sent straight to the new site; nothing passes through your computer, and nothing changes here.', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'On the new site, click "Move it here" to finish.', 'siteferry' ); ?></li>
							</ol>
							<p class="sf-field">
								<label for="siteferry-move-key"><?php esc_html_e( 'Key from the new site', 'siteferry' ); ?></label>
								<textarea id="siteferry-move-key" rows="3" spellcheck="false" autocomplete="off"></textarea>
							</p>
							<p class="sf-actions"><button type="button" class="button" id="siteferry-move-check"><?php esc_html_e( 'Check the key', 'siteferry' ); ?></button></p>
							<div id="siteferry-move-target" class="sf-move-target" hidden></div>
						</div>
						<div class="sf-move-half" id="siteferry-move-receive" hidden>
							<ol class="sf-steps">
								<li><?php esc_html_e( 'Click "Make a key" below and copy it. It works once, for 24 hours.', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'On the old site, open SiteFerry, "Move a site", choose "Send this site to another server" and paste the key there.', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'When the site has arrived, click "Move it here". Until then nothing on this site changes.', 'siteferry' ); ?></li>
							</ol>
							<div id="siteferry-receive" class="sf-receive"></div>
							<p class="description"><?php esc_html_e( 'The old site has no SiteFerry, or cannot reach this server? Make a backup there, download it, and use "Upload a backup" here instead.', 'siteferry' ); ?></p>
						</div>
					</div>

					<div id="siteferry-kit" class="sf-drawer sf-kit" hidden>
						<p class="sf-drawer-note"><?php esc_html_e( 'For the day this site shows a white screen and you cannot log in: a small page that works without WordPress. Make it now, while the site works, and keep the file somewhere safe.', 'siteferry' ); ?></p>
						<ol class="sf-steps">
							<li><?php esc_html_e( 'Choose a password and download the kit. Keep the file private: with its password it can change this site.', 'siteferry' ); ?></li>
							<li><?php esc_html_e( 'If the site breaks: upload the file by FTP or your hosting file manager into WordPress\'s main folder, the one with wp-config.php (or wp-load.php) and wp-admin.', 'siteferry' ); ?></li>
							<li><?php esc_html_e( 'Open it in your browser at your site\'s address followed by the file name, and sign in with the password.', 'siteferry' ); ?></li>
							<li><?php esc_html_e( 'Turn off all plugins except SiteFerry, or switch to a default theme. Both can be undone. It can also download SiteFerry\'s backups.', 'siteferry' ); ?></li>
							<li><?php esc_html_e( 'When the site works again, delete the kit (it has a button for that), and restore a backup with SiteFerry if needed.', 'siteferry' ); ?></li>
						</ol>
						<form id="siteferry-kit-form" class="sf-kit-form" novalidate>
							<p class="sf-field">
								<label for="siteferry-kit-password"><?php esc_html_e( 'Password for the kit', 'siteferry' ); ?></label>
								<input type="password" id="siteferry-kit-password" name="password" minlength="12" autocomplete="new-password" required>
								<span class="description"><?php esc_html_e( 'At least 12 characters. It is not stored anywhere; the kit holds only a hash of it. Write it down with the file.', 'siteferry' ); ?></span>
							</p>
							<p class="sf-field">
								<label for="siteferry-kit-again"><?php esc_html_e( 'The password again', 'siteferry' ); ?></label>
								<input type="password" id="siteferry-kit-again" name="again" minlength="12" autocomplete="new-password" required>
							</p>
							<p class="sf-field">
								<label for="siteferry-kit-days"><?php esc_html_e( 'Works for', 'siteferry' ); ?></label>
								<select id="siteferry-kit-days" name="days">
									<option value="7"><?php esc_html_e( '7 days', 'siteferry' ); ?></option>
									<option value="30" selected><?php esc_html_e( '30 days', 'siteferry' ); ?></option>
									<option value="90"><?php esc_html_e( '90 days', 'siteferry' ); ?></option>
								</select>
							</p>
							<p class="sf-actions"><button type="submit" class="button button-primary" id="siteferry-kit-make"><?php esc_html_e( 'Download the emergency kit', 'siteferry' ); ?></button></p>
						</form>
						<div class="sf-result" id="siteferry-kit-result" role="status" hidden></div>
						<h3 class="sf-sub"><?php esc_html_e( 'Kits made', 'siteferry' ); ?></h3>
						<ul class="sf-kit-list" id="siteferry-kit-list"></ul>
						<p class="description"><?php esc_html_e( 'Revoke a kit you no longer trust (for example, a lost file): it then stops working as soon as it reads this site\'s database. A kit also stops working when it expires.', 'siteferry' ); ?></p>
					</div>

					<div id="siteferry-staging" class="sf-drawer sf-staging" hidden>
						<p class="sf-drawer-note"><?php esc_html_e( 'A private copy of this site in a subfolder, to try updates, plugins or design changes without touching the live site. It has its own database tables, is hidden from search engines and holds back outgoing email.', 'siteferry' ); ?></p>
						<div id="siteferry-staging-current" class="sf-staging-current" hidden></div>
						<form id="siteferry-staging-form" class="sf-staging-form" novalidate hidden>
							<ol class="sf-steps">
								<li><?php esc_html_e( 'SiteFerry copies WordPress\'s files and this site\'s database tables into the subfolder below (the same things a backup leaves out stay out).', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'The copy gets its own address, a STAGING badge, and its own copy of your users: sign in there with the same name and password.', 'siteferry' ); ?></li>
								<li><?php esc_html_e( 'Delete it here when you are done. Copying changes back to the live site comes later.', 'siteferry' ); ?></li>
							</ol>
							<p class="sf-field">
								<label for="siteferry-staging-folder"><?php esc_html_e( 'Subfolder', 'siteferry' ); ?></label>
								<span class="sf-staging-address"><span id="siteferry-staging-base"></span><input type="text" id="siteferry-staging-folder" name="folder" value="staging" maxlength="30" pattern="[a-z0-9][a-z0-9\-]{0,29}" spellcheck="false" autocomplete="off"></span>
							</p>
							<p><label><input type="checkbox" id="siteferry-staging-no-media" name="no_media"> <?php esc_html_e( 'Leave the media library out (faster and smaller; images will be missing in the copy)', 'siteferry' ); ?></label></p>
							<p class="description" id="siteferry-staging-estimate"></p>
							<p class="sf-actions"><button type="submit" class="button button-primary" id="siteferry-staging-create"><?php esc_html_e( 'Create the staging copy', 'siteferry' ); ?></button></p>
						</form>
						<div id="siteferry-staging-blocked" class="sf-result is-failed" hidden></div>
						<div id="siteferry-staging-progress" class="sf-progress" hidden></div>
						<div class="sf-result" id="siteferry-staging-result" role="status" hidden></div>
					</div>				</div>

				<dialog class="sf-dialog" id="siteferry-parts-dialog" aria-labelledby="siteferry-parts-dialog-title">
					<form method="dialog" class="sf-dialog-form">
						<h2 class="sf-card-title" id="siteferry-parts-dialog-title"><?php esc_html_e( 'Download in parts', 'siteferry' ); ?></h2>
						<p><?php esc_html_e( 'For disks and services with a size limit. Upload all parts together to put the backup back on a site; or join them in order (copy /b on Windows, cat elsewhere).', 'siteferry' ); ?></p>
						<p class="sf-field">
							<label for="siteferry-parts-size"><?php esc_html_e( 'Largest part', 'siteferry' ); ?></label>
							<select id="siteferry-parts-size">
								<option value="100">100 MB</option>
								<option value="500">500 MB</option>
								<option value="1024">1 GB</option>
								<option value="2048" selected>2 GB</option>
								<option value="4095">4 GB (FAT32)</option>
							</select>
						</p>
						<ol class="sf-parts-list" id="siteferry-parts-list"></ol>
						<p class="sf-actions"><button type="submit" class="button" value="close"><?php esc_html_e( 'Close', 'siteferry' ); ?></button></p>
					</form>
				</dialog>
				<dialog class="sf-dialog" id="siteferry-password-dialog" aria-labelledby="siteferry-password-dialog-title">
					<form method="dialog" class="sf-dialog-form">
						<h2 class="sf-card-title" id="siteferry-password-dialog-title"></h2>
						<p id="siteferry-password-dialog-text"></p>
						<p class="sf-field">
							<label for="siteferry-password-dialog-input"><?php esc_html_e( 'Password', 'siteferry' ); ?></label>
							<input type="password" id="siteferry-password-dialog-input" autocomplete="current-password">
						</p>
						<p class="sf-row-error" id="siteferry-password-dialog-error" role="alert" hidden></p>
						<p class="sf-actions">
							<button type="submit" class="button button-primary" value="ok"><?php esc_html_e( 'Continue', 'siteferry' ); ?></button>
							<button type="submit" class="button sf-quiet" value="cancel" formnovalidate><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
						</p>
					</form>
				</dialog>

				<div class="sf-undo" id="siteferry-undo" role="status" hidden></div>

				<div class="sf-progress" id="siteferry-job-progress" hidden></div>

				<div class="sf-card sf-contents" id="siteferry-contents" hidden tabindex="-1" aria-labelledby="siteferry-contents-title">
					<div class="sf-restore-head">
						<h2 class="sf-card-title" id="siteferry-contents-title"></h2>
						<button type="button" class="sf-notice-close" id="siteferry-contents-close" aria-label="<?php esc_attr_e( 'Close', 'siteferry' ); ?>">&times;</button>
					</div>
					<?php if ( is_multisite() ) : ?>
						<p class="sf-lead"><?php esc_html_e( 'Browse the backup, download single files, or restore one file or one post or page of the main site on its own. Larger parts cannot be restored on their own here: a network is restored whole.', 'siteferry' ); ?></p>
					<?php else : ?>
						<p class="sf-lead"><?php esc_html_e( 'Browse the backup and download single files from it, restore one file or one post or page straight away (with an undo), or tick parts of it to restore only those: the database or single tables, all files or single folders.', 'siteferry' ); ?></p>
					<?php endif; ?>
					<nav class="sf-crumbs" id="siteferry-contents-crumbs" aria-label="<?php esc_attr_e( 'Folder in the backup', 'siteferry' ); ?>"></nav>
					<div class="sf-result" id="siteferry-contents-result" role="status" hidden></div>
					<div class="sf-contents-body" id="siteferry-contents-body" aria-live="polite"></div>
					<div class="sf-contents-foot" id="siteferry-contents-foot" hidden>
						<p class="sf-contents-picked" id="siteferry-contents-picked"></p>
						<p class="sf-actions">
							<button type="button" class="button sf-quiet" id="siteferry-contents-clear"><?php esc_html_e( 'Clear', 'siteferry' ); ?></button>
							<button type="button" class="button button-primary" id="siteferry-contents-restore"><?php esc_html_e( 'Restore only these', 'siteferry' ); ?></button>
						</p>
					</div>
				</div>

				<div class="sf-card sf-compare" id="siteferry-compare" hidden tabindex="-1" aria-labelledby="siteferry-compare-title">
					<div class="sf-restore-head">
						<h2 class="sf-card-title" id="siteferry-compare-title"><?php esc_html_e( 'Compare two backups', 'siteferry' ); ?></h2>
						<button type="button" class="sf-notice-close" id="siteferry-compare-close" aria-label="<?php esc_attr_e( 'Close', 'siteferry' ); ?>">&times;</button>
					</div>
					<div class="sf-compare-body" id="siteferry-compare-body" aria-live="polite"></div>
				</div>

				<div class="sf-card sf-restore" id="siteferry-restore" hidden tabindex="-1" aria-labelledby="siteferry-restore-title">
					<div class="sf-restore-head">
						<h2 class="sf-card-title" id="siteferry-restore-title"></h2>
						<button type="button" class="sf-notice-close" id="siteferry-restore-close" aria-label="<?php esc_attr_e( 'Close', 'siteferry' ); ?>">&times;</button>
					</div>
					<p class="sf-lead" id="siteferry-restore-lead"></p>
					<div class="sf-crossing" id="siteferry-crossing" hidden></div>
					<div class="sf-progress" id="siteferry-import-progress" hidden></div>
					<table class="sf-facts-table" id="siteferry-inspection" hidden><tbody id="siteferry-inspection-rows"></tbody></table>
					<ul id="siteferry-inspection-warnings" class="sf-warnings"></ul>
					<section class="sf-preview" id="siteferry-preview" aria-labelledby="siteferry-preview-title" hidden></section>
					<details class="sf-more" id="siteferry-restore-advanced">
						<summary><?php esc_html_e( 'Advanced', 'siteferry' ); ?></summary>
						<fieldset class="sf-checks sf-restore-what" id="siteferry-restore-what">
							<legend><?php esc_html_e( 'What to restore', 'siteferry' ); ?></legend>
							<label><input type="radio" name="siteferry_restore_what" value="all" checked> <?php esc_html_e( 'Everything', 'siteferry' ); ?></label>
							<label><input type="radio" name="siteferry_restore_what" value="database"> <?php esc_html_e( 'Only the database (posts, pages, settings, users)', 'siteferry' ); ?></label>
							<label><input type="radio" name="siteferry_restore_what" value="files"> <?php esc_html_e( 'Only the files (plugins, themes, media and the rest of wp-content)', 'siteferry' ); ?></label>
							<label id="siteferry-restore-what-chosen" hidden><input type="radio" name="siteferry_restore_what" value="chosen"> <span id="siteferry-restore-what-label"></span></label>
							<span class="description"><?php esc_html_e( 'To restore single plugins, themes, folders or tables, use "Look inside" in the backup\'s menu.', 'siteferry' ); ?></span>
						</fieldset>
						<p class="sf-field" id="siteferry-replace-url-row">
							<label for="siteferry-replace-url"><?php esc_html_e( 'Site address after restoring', 'siteferry' ); ?></label>
							<input type="url" class="regular-text" id="siteferry-replace-url" placeholder="<?php echo esc_attr( home_url() ); ?>">
							<span class="description"><?php esc_html_e( 'Leave empty to keep this site\'s address. Links inside the content are changed to match.', 'siteferry' ); ?></span>
						</p>
						<p id="siteferry-include-mu-row"><label><input type="checkbox" id="siteferry-include-mu"> <?php esc_html_e( 'Also copy must-use plugins', 'siteferry' ); ?></label></p>
						<p id="siteferry-include-dropins-row"><label><input type="checkbox" id="siteferry-include-dropins"> <?php esc_html_e( 'Also copy server-specific drop-ins (object-cache.php, advanced-cache.php, db.php)', 'siteferry' ); ?></label></p>
						<p class="description" id="siteferry-restore-whole-note" hidden><?php esc_html_e( 'A network is restored whole, and it keeps the address set in wp-config.php.', 'siteferry' ); ?></p>
					</details>
					<p class="sf-safety" id="siteferry-safety-row" hidden>
						<label><input type="checkbox" id="siteferry-safety" checked> <strong><?php esc_html_e( 'Make a safety backup of this site first (recommended)', 'siteferry' ); ?></strong></label>
						<span class="description"><?php esc_html_e( 'A whole-site backup of this site as it is now, so you can undo the restore. It takes about as long as a normal backup and needs room for one.', 'siteferry' ); ?></span>
					</p>
					<p class="sf-caution" id="siteferry-confirm-row" hidden><label><input type="checkbox" id="siteferry-confirm-check"> <span id="siteferry-confirm-text"></span></label></p>
					<p class="sf-actions sf-restore-actions">
						<button type="button" class="button button-large" id="siteferry-import-cancel"><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
						<button type="button" class="button button-primary button-large sf-danger" id="siteferry-confirm" disabled><?php esc_html_e( 'Restore now', 'siteferry' ); ?></button>
					</p>
				</div>

				<div class="sf-card sf-card-flush">
					<div class="sf-list-head">
						<h2 class="sf-card-title"><?php esc_html_e( 'All backups', 'siteferry' ); ?></h2>
						<span class="sf-list-tools">
							<span class="sf-list-summary" id="siteferry-backups-summary"></span>
							<button type="button" class="button sf-quiet sf-quiet-small" id="siteferry-backups-refresh"><?php esc_html_e( 'Refresh', 'siteferry' ); ?></button>
						</span>
					</div>
					<div class="sf-timeline-wrap" id="siteferry-timeline-wrap" hidden>
						<button type="button" class="button-link sf-timeline-toggle" id="siteferry-timeline-toggle" aria-expanded="false" aria-controls="siteferry-timeline"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span> <?php esc_html_e( 'Show timeline', 'siteferry' ); ?></button>
						<div class="sf-timeline" id="siteferry-timeline" hidden>
							<div class="sf-timeline-scroll" id="siteferry-timeline-scroll"></div>
							<p class="sf-timeline-legend" id="siteferry-timeline-legend"></p>
						</div>
					</div>
					<div class="sf-filter" id="siteferry-filter" hidden>
						<label class="screen-reader-text" for="siteferry-search"><?php esc_html_e( 'Search backups', 'siteferry' ); ?></label>
						<input type="search" id="siteferry-search" placeholder="<?php esc_attr_e( 'Search names, notes, tags and file names', 'siteferry' ); ?>">
						<div class="sf-tag-filter" id="siteferry-tag-filter" role="group" aria-label="<?php esc_attr_e( 'Show only backups with this tag', 'siteferry' ); ?>"></div>
					</div>
					<div class="sf-backup sf-columns" id="siteferry-columns" aria-hidden="true" hidden>
						<span></span>
						<span><?php esc_html_e( 'Backup', 'siteferry' ); ?></span>
						<span><?php esc_html_e( 'Stored on', 'siteferry' ); ?></span>
						<span><?php esc_html_e( 'Format', 'siteferry' ); ?></span>
						<span class="sf-backup-size"><?php esc_html_e( 'Size', 'siteferry' ); ?></span>
						<span></span>
					</div>
					<ul class="sf-list" id="siteferry-backups"></ul>
				</div>

				<details class="sf-help">
					<summary><?php esc_html_e( 'Moving a site here? Three ways', 'siteferry' ); ?></summary>
					<ul>
						<li><?php esc_html_e( 'Straight from the old server, with SiteFerry on both sites: click "Move a site" above and choose "Receive a site here".', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'With a backup file: make a backup on the old site with SiteFerry (or a .wpress with All-in-One WP Migration), download it, and click "Upload a backup" here.', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Through a storage: send the backup from the old site to a storage this site has set up too; it shows up in the list above.', 'siteferry' ); ?></li>
					</ul>
					<p><?php esc_html_e( 'Then click "Move here" on the backup. SiteFerry checks it, shows what will change, and only then replaces this site\'s content; the addresses are changed to this site\'s.', 'siteferry' ); ?></p>
				</details>
			</section>

			<section class="siteferry-panel" data-panel="storage" hidden>
				<p class="sf-lead"><?php esc_html_e( 'Places SiteFerry can send backups to and fetch them back from. Keys are stored encrypted, are never shown again and are not part of any backup. SiteFerry connects only to the services set up here.', 'siteferry' ); ?></p>
				<div class="sf-dests">
					<?php
					self::render_destination( 's3', 'dashicons-cloud', __( 'S3-compatible storage', 'siteferry' ), __( 'Amazon S3, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, MinIO', 'siteferry' ), array( self::class, 'render_s3' ) );
					self::render_destination( 'ftp', 'dashicons-networking', __( 'FTP server', 'siteferry' ), __( 'FTPS (encrypted) or plain FTP', 'siteferry' ), array( self::class, 'render_ftp' ) );
					self::render_destination( 'gdrive', 'dashicons-google', __( 'Google Drive', 'siteferry' ), __( 'Sign in with your Google account', 'siteferry' ), array( self::class, 'render_gdrive' ) );
					self::render_destination( 'dropbox', 'dashicons-cloud-saved', __( 'Dropbox', 'siteferry' ), __( 'Sign in with your Dropbox account', 'siteferry' ), array( self::class, 'render_dropbox' ) );
					?>
				</div>
			</section>

			<section class="siteferry-panel" data-panel="activity" hidden>
				<div class="sf-card sf-card-flush">
					<div class="sf-list-head">
						<h2 class="sf-card-title"><?php esc_html_e( 'Recent activity', 'siteferry' ); ?></h2>
						<button type="button" class="button sf-quiet sf-quiet-small" id="siteferry-jobs-refresh"><?php esc_html_e( 'Refresh', 'siteferry' ); ?></button>
					</div>
					<ul class="sf-list" id="siteferry-jobs"></ul>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Prints the schedule drawer: when, what, where, how many to keep, and email. The script fills
	 * it from GET /schedule and saves it with POST /schedule.
	 *
	 * @param array $whole_parts Checkbox name => label: whole parts to leave out.
	 * @param array $clutter     Checkbox name => [label, checked]: clutter to leave out.
	 */
	private static function render_schedule( array $whole_parts, array $clutter ): void {
		$days = array(
			1 => __( 'Monday', 'siteferry' ),
			2 => __( 'Tuesday', 'siteferry' ),
			3 => __( 'Wednesday', 'siteferry' ),
			4 => __( 'Thursday', 'siteferry' ),
			5 => __( 'Friday', 'siteferry' ),
			6 => __( 'Saturday', 'siteferry' ),
			0 => __( 'Sunday', 'siteferry' ),
		);
		?>
		<form id="siteferry-schedule-form" class="sf-drawer sf-schedule" hidden novalidate>
			<p class="sf-drawer-note"><?php esc_html_e( 'Back up this site by itself, at a set time. Nobody needs to be logged in: the backup runs on the server, step by step.', 'siteferry' ); ?></p>
			<p class="sf-switch"><label><input type="checkbox" name="enabled"> <strong><?php esc_html_e( 'Back up automatically', 'siteferry' ); ?></strong></label></p>

			<div class="sf-schedule-body">
				<h3 class="sf-sub"><?php esc_html_e( 'When', 'siteferry' ); ?></h3>
				<div class="sf-when">
					<p class="sf-field">
						<label for="siteferry-schedule-frequency"><?php esc_html_e( 'How often', 'siteferry' ); ?></label>
						<select id="siteferry-schedule-frequency" name="frequency">
							<option value="daily"><?php esc_html_e( 'Every day', 'siteferry' ); ?></option>
							<option value="weekly"><?php esc_html_e( 'Every week', 'siteferry' ); ?></option>
							<option value="monthly"><?php esc_html_e( 'Every month', 'siteferry' ); ?></option>
						</select>
					</p>
					<p class="sf-field" data-when="weekly">
						<label for="siteferry-schedule-weekday"><?php esc_html_e( 'On', 'siteferry' ); ?></label>
						<select id="siteferry-schedule-weekday" name="weekday">
							<?php foreach ( $days as $number => $day ) : ?>
								<option value="<?php echo esc_attr( (string) $number ); ?>"><?php echo esc_html( $day ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="sf-field" data-when="monthly">
						<label for="siteferry-schedule-monthday"><?php esc_html_e( 'On day', 'siteferry' ); ?></label>
						<select id="siteferry-schedule-monthday" name="monthday">
							<?php for ( $day = 1; $day <= 28; $day++ ) : ?>
								<option value="<?php echo esc_attr( (string) $day ); ?>"><?php echo esc_html( (string) $day ); ?></option>
							<?php endfor; ?>
						</select>
					</p>
					<p class="sf-field">
						<label for="siteferry-schedule-time"><?php esc_html_e( 'At', 'siteferry' ); ?></label>
						<input type="time" id="siteferry-schedule-time" name="time" step="60" value="03:00">
					</p>
				</div>
				<p class="description" id="siteferry-schedule-zone"></p>
				<p class="sf-caution" id="siteferry-schedule-cron-off" hidden><?php esc_html_e( 'WP-Cron is turned off on this site (DISABLE_WP_CRON). Scheduled backups only start if a server cron job runs wp-cron.php, or "wp cron event run --due-now", every few minutes.', 'siteferry' ); ?></p>

				<h3 class="sf-sub"><?php esc_html_e( 'What goes into the backup', 'siteferry' ); ?></h3>
				<details class="sf-more sf-schedule-what">
					<summary id="siteferry-schedule-what-summary"><?php esc_html_e( 'The whole site', 'siteferry' ); ?></summary>
					<div class="sf-groups">
						<fieldset class="sf-checks">
							<legend><?php esc_html_e( 'Leave out whole parts', 'siteferry' ); ?></legend>
							<?php foreach ( $whole_parts as $name => $label ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>"> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<fieldset class="sf-checks">
							<legend><?php esc_html_e( 'Leave out clutter', 'siteferry' ); ?></legend>
							<?php foreach ( $clutter as $name => list( $label, $checked ) ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" <?php checked( $checked ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
					</div>
				</details>
				<fieldset class="sf-format">
					<legend><?php esc_html_e( 'File', 'siteferry' ); ?></legend>
					<div class="sf-choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Backup file format', 'siteferry' ); ?>">
						<label class="sf-choice">
							<input type="radio" name="format" value="tar" checked>
							<span class="sf-choice-name"><?php esc_html_e( 'SiteFerry backup (.tar)', 'siteferry' ); ?></span>
							<span class="sf-choice-note"><?php esc_html_e( 'Every file is checked when it is imported.', 'siteferry' ); ?></span>
						</label>
						<label class="sf-choice">
							<input type="radio" name="format" value="wpress">
							<span class="sf-choice-name"><?php esc_html_e( '.wpress file', 'siteferry' ); ?></span>
							<span class="sf-choice-note"><?php esc_html_e( 'For importing with the All-in-One WP Migration plugin.', 'siteferry' ); ?></span>
						</label>
					</div>
					<?php if ( Requirements::can_compress() ) : ?>
						<p class="sf-compress" data-role="compress"><label><input type="checkbox" name="compress"> <?php esc_html_e( 'Compress the file (.tar.gz): smaller, but takes longer to make', 'siteferry' ); ?></label></p>
					<?php endif; ?>
					<?php if ( \SiteFerry\Archive\Crypto::available() ) : ?>
						<div class="sf-encrypt" data-role="encrypt">
							<p><label><input type="checkbox" name="encrypt"> <?php esc_html_e( 'Protect automatic backups with a password (also those made before updates)', 'siteferry' ); ?></label></p>
							<p class="sf-field" data-role="encrypt-password" hidden>
								<label for="siteferry-schedule-password"><?php esc_html_e( 'Password', 'siteferry' ); ?></label>
								<input type="password" id="siteferry-schedule-password" name="password" autocomplete="new-password" minlength="8">
								<span class="description"><?php esc_html_e( 'Kept on this site, encrypted, so backups can run by themselves. Write it down somewhere safe: a backup cannot be restored without it, for example on a new server.', 'siteferry' ); ?></span>
							</p>
						</div>
					<?php endif; ?>
				</fieldset>

				<h3 class="sf-sub"><?php esc_html_e( 'Where it goes', 'siteferry' ); ?></h3>
				<div class="sf-choices" id="siteferry-schedule-where" role="radiogroup" aria-label="<?php esc_attr_e( 'Where scheduled backups go', 'siteferry' ); ?>">
					<label class="sf-choice">
						<input type="radio" name="send_to" value="" checked>
						<span class="sf-choice-name"><?php esc_html_e( 'This server', 'siteferry' ); ?></span>
						<span class="sf-choice-note"><?php esc_html_e( 'Kept in SiteFerry\'s protected folder.', 'siteferry' ); ?></span>
					</label>
				</div>
				<p class="sf-delete-after" id="siteferry-schedule-keep-local" hidden><label><input type="checkbox" name="keep_local"> <?php esc_html_e( 'Also keep a copy on this server', 'siteferry' ); ?></label></p>
				<p class="description" id="siteferry-schedule-no-storage" hidden>
					<?php esc_html_e( 'To send scheduled backups to S3, an FTP server, Google Drive or Dropbox, set one up first.', 'siteferry' ); ?>
					<a href="#storage" data-goto="storage"><?php esc_html_e( 'Set up storage', 'siteferry' ); ?></a>
				</p>

				<h3 class="sf-sub"><?php esc_html_e( 'How many to keep', 'siteferry' ); ?></h3>
				<p class="sf-field sf-inline-field">
					<label for="siteferry-schedule-retain"><?php esc_html_e( 'Keep the newest', 'siteferry' ); ?></label>
					<input type="number" class="small-text" id="siteferry-schedule-retain" name="retain" min="0" max="100" step="1" value="7">
					<span><?php esc_html_e( 'scheduled backups', 'siteferry' ); ?></span>
				</p>
				<p class="description"><?php esc_html_e( 'After a new scheduled backup succeeds, older scheduled backups beyond this number are deleted wherever they are stored. 0 keeps them all. Backups you make yourself are never deleted.', 'siteferry' ); ?></p>
				<p class="sf-field sf-inline-field">
					<label for="siteferry-schedule-full-every"><?php esc_html_e( 'Make a full backup every', 'siteferry' ); ?></label>
					<input type="number" class="small-text" id="siteferry-schedule-full-every" name="full_every" min="1" max="30" step="1" value="1">
					<span><?php esc_html_e( 'runs, only the changes in between', 'siteferry' ); ?></span>
				</p>
				<p class="description"><?php esc_html_e( '1 makes a full backup every time. With a higher number the runs in between hold only the files changed since the last full backup (and the whole database), so they are much smaller. They need SiteFerry\'s format, no password, and a copy kept on this server; a full backup is kept as long as changes built on it are.', 'siteferry' ); ?></p>

				<h3 class="sf-sub"><?php esc_html_e( 'Email', 'siteferry' ); ?></h3>
				<div class="sf-when">
					<p class="sf-field">
						<label for="siteferry-schedule-notify"><?php esc_html_e( 'Send an email', 'siteferry' ); ?></label>
						<select id="siteferry-schedule-notify" name="notify">
							<option value="failure"><?php esc_html_e( 'When a backup fails', 'siteferry' ); ?></option>
							<option value="always"><?php esc_html_e( 'After every backup', 'siteferry' ); ?></option>
							<option value="never"><?php esc_html_e( 'Never', 'siteferry' ); ?></option>
						</select>
					</p>
					<p class="sf-field sf-field-grow">
						<label for="siteferry-schedule-email"><?php esc_html_e( 'To', 'siteferry' ); ?></label>
						<input type="email" id="siteferry-schedule-email" name="email" maxlength="200" autocomplete="email">
					</p>
				</div>
			</div>

			<div class="sf-before-updates">
				<h3 class="sf-sub"><?php esc_html_e( 'A backup before updates and risky changes', 'siteferry' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Separate from the automatic backups above: it works whether they are on or off. It is saved with the same button.', 'siteferry' ); ?></p>
				<p class="sf-switch"><label><input type="checkbox" name="before_updates"> <strong><?php esc_html_e( 'Back up before WordPress, a plugin or a theme updates', 'siteferry' ); ?></strong></label></p>
				<p class="description"><?php esc_html_e( 'Just before an update starts: the database and the plugin or theme being updated (the database only for WordPress itself), on this server, tagged "before-update". Restoring it puts those back as they were and leaves the rest of the site alone. If it cannot be made within about two minutes, the update goes ahead without it.', 'siteferry' ); ?></p>
				<p class="sf-field sf-inline-field">
					<label for="siteferry-update-retain"><?php esc_html_e( 'Keep the newest', 'siteferry' ); ?></label>
					<input type="number" class="small-text" id="siteferry-update-retain" name="update_retain" min="1" max="50" step="1" value="3">
					<span><?php esc_html_e( 'backups made before updates and risky changes', 'siteferry' ); ?></span>
				</p>
				<fieldset class="sf-checks sf-before-risky">
					<legend><?php esc_html_e( 'Before risky changes', 'siteferry' ); ?></legend>
					<label><input type="checkbox" name="before_plugin_delete"> <?php esc_html_e( 'Deleting a plugin (the database and that plugin)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="before_theme_delete"> <?php esc_html_e( 'Deleting a theme (the database and that theme)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="before_theme_switch"> <?php esc_html_e( 'Switching the theme (the database; the theme files stay)', 'siteferry' ); ?></label>
				</fieldset>
				<p class="description"><?php esc_html_e( 'The same quick backup, on this server, made just before the change and tagged with what happened. If it takes more than about two minutes, the change goes ahead without it.', 'siteferry' ); ?></p>
				<p class="description" id="siteferry-update-last" hidden></p>
			</div>

			<p class="sf-schedule-status" id="siteferry-schedule-status"></p>
			<div class="sf-drawer-actions">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save these settings', 'siteferry' ); ?></button>
				<button type="button" class="button" id="siteferry-schedule-run"><?php esc_html_e( 'Run it now', 'siteferry' ); ?></button>
				<button type="button" class="button sf-quiet" id="siteferry-schedule-close"><?php esc_html_e( 'Close', 'siteferry' ); ?></button>
			</div>
			<div class="sf-result" id="siteferry-schedule-result" role="status" hidden></div>
		</form>
		<?php
	}

	/**
	 * In a staging copy: the address of the live site it copies; '' elsewhere.
	 */
	private static function staging_of(): string {
		$copy = get_option( \SiteFerry\Staging\Staging::COPY_OPTION );
		return is_array( $copy ) && isset( $copy['of'] ) ? esc_url_raw( (string) $copy['of'] ) : '';
	}

	/**
	 * The size limit of a storage: larger backups are stored there in numbered parts.
	 */
	private static function render_split(): void {
		$choices = array(
			0    => __( 'No limit: whole backups', 'siteferry' ),
			100  => '100 MB',
			500  => '500 MB',
			1024 => '1 GB',
			2048 => '2 GB',
			4095 => __( '4 GB (FAT32 and many FTP servers)', 'siteferry' ),
		);
		?>
		<p class="sf-field sf-split">
			<label><?php esc_html_e( 'Largest file on this storage', 'siteferry' ); ?>
				<select name="split_mb" data-default="0">
					<?php foreach ( $choices as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<span class="description"><?php esc_html_e( 'Larger backups are stored in numbered parts (.001, .002, ...). SiteFerry joins them again when it downloads, checks or restores one.', 'siteferry' ); ?></span>
		</p>
		<?php
	}
	/**
	 * Prints one storage as a card that opens its settings form.
	 *
	 * @param string   $id    Storage id.
	 * @param string   $icon  Dashicons class.
	 * @param string   $name  Name.
	 * @param string   $about What it covers, shown until it is set up.
	 * @param callable $form  Prints the form.
	 */
	private static function render_destination( string $id, string $icon, string $name, string $about, callable $form ): void {
		?>
		<details class="sf-dest" data-dest="<?php echo esc_attr( $id ); ?>">
			<summary class="sf-dest-head">
				<span class="sf-dest-icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<span class="sf-dest-text">
					<span class="sf-dest-name"><?php echo esc_html( $name ); ?></span>
					<span class="sf-dest-state" data-role="state" data-about="<?php echo esc_attr( $about ); ?>"><?php echo esc_html( $about ); ?></span>
				</span>
				<span class="sf-chip" data-role="chip"><?php esc_html_e( 'Not set up', 'siteferry' ); ?></span>
			</summary>
			<div class="sf-dest-body">
				<?php call_user_func( $form ); ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Prints the S3 settings form. It is submitted by script as JSON with the REST nonce; method
	 * "post" only keeps a browser without script from putting the key into a URL.
	 */
	private static function render_s3(): void {
		$fields = array(
			'endpoint'   => array( __( 'Endpoint', 'siteferry' ), __( 'Host name of the service. Leave empty to use the provider\'s endpoint for the region.', 'siteferry' ) ),
			'region'     => array( __( 'Region', 'siteferry' ), '' ),
			'bucket'     => array( __( 'Bucket', 'siteferry' ), __( 'Create the bucket at the provider first; keep it private.', 'siteferry' ) ),
			'folder'     => array( __( 'Folder in the bucket', 'siteferry' ), __( 'Backups are stored as folder/file name. Default: siteferry.', 'siteferry' ) ),
			'access_key' => array( __( 'Access key ID', 'siteferry' ), '' ),
		);
		?>
		<form id="siteferry-storage-s3" class="siteferry-storage-form" data-destination="s3" method="post" action="#" autocomplete="off">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="siteferry-s3-preset"><?php esc_html_e( 'Provider', 'siteferry' ); ?></label></th>
					<td>
						<select id="siteferry-s3-preset" name="preset">
							<?php foreach ( \SiteFerry\Storage\S3_Driver::PRESETS as $id => $preset ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" data-endpoint="<?php echo esc_attr( $preset['endpoint'] ); ?>" data-region="<?php echo esc_attr( $preset['region'] ); ?>" data-path-style="<?php echo $preset['path_style'] ? '1' : '0'; ?>" data-help="<?php echo esc_attr( $preset['help'] ); ?>"><?php echo esc_html( $preset['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description" data-role="help"></p>
					</td>
				</tr>
				<?php foreach ( $fields as $name => list( $label, $help ) ) : ?>
					<tr<?php echo 'endpoint' === $name ? ' data-advanced="endpoint"' : ''; ?>>
						<th scope="row"><label for="siteferry-s3-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="siteferry-s3-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" spellcheck="false" autocomplete="off">
							<?php if ( '' !== $help ) : ?>
								<p class="description"><?php echo esc_html( $help ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><label for="siteferry-s3-secret_key"><?php esc_html_e( 'Secret access key', 'siteferry' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="siteferry-s3-secret_key" name="secret_key" spellcheck="false" autocomplete="new-password">
						<p class="description" data-role="secret-note"><?php esc_html_e( 'Stored encrypted. It is never shown again.', 'siteferry' ); ?></p>
					</td>
				</tr>
				<tr data-advanced="addressing">
					<th scope="row"><?php esc_html_e( 'Addressing', 'siteferry' ); ?></th>
					<td><label><input type="checkbox" name="path_style"> <?php esc_html_e( 'Path-style requests (endpoint/bucket/file instead of bucket.endpoint/file)', 'siteferry' ); ?></label></td>
				</tr>
			</table>
			<p><button type="button" class="button-link" data-role="advanced"><?php esc_html_e( 'Advanced settings: endpoint and addressing', 'siteferry' ); ?></button></p>
			<?php self::render_split(); ?>
			<p class="sf-form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'siteferry' ); ?></button>
				<button type="button" class="button" data-role="test"><?php esc_html_e( 'Test connection', 'siteferry' ); ?></button>
				<button type="button" class="button-link button-link-delete" data-role="remove"><?php esc_html_e( 'Remove these settings', 'siteferry' ); ?></button>
			</p>
			<div class="sf-result" data-role="result" role="status" hidden></div>
		</form>
		<?php
	}

	/**
	 * Prints the Google Drive settings form: one-click sign-in through SiteFerry's sign-in service,
	 * or (advanced) the administrator's own OAuth client with the redirect URI to register.
	 */
	private static function render_gdrive(): void {
		$redirect   = \SiteFerry\Storage\Destinations::redirect_uri( 'gdrive' );
		$relay_host = (string) wp_parse_url( \SiteFerry\Storage\Gdrive_Driver::relay_url(), PHP_URL_HOST );
		?>
		<form id="siteferry-storage-gdrive" class="siteferry-storage-form sf-gd" data-destination="gdrive" method="post" action="#" autocomplete="off">
			<section class="sf-gd-block sf-gd-account" aria-labelledby="siteferry-gdrive-who">
				<span class="sf-gd-avatar" data-role="avatar" aria-hidden="true">G</span>
				<div class="sf-gd-who">
					<strong id="siteferry-gdrive-who" data-role="who"><?php esc_html_e( 'Not connected yet', 'siteferry' ); ?></strong>
					<span data-role="who-note"><?php esc_html_e( 'Sign in with the Google account whose Drive should keep the backups.', 'siteferry' ); ?></span>
				</div>
				<div class="sf-gd-account-actions">
					<button type="button" class="button button-primary" data-role="connect" data-label-relay="<?php esc_attr_e( 'Sign in with Google', 'siteferry' ); ?>" data-label-own="<?php esc_attr_e( 'Connect to Google Drive', 'siteferry' ); ?>"><?php esc_html_e( 'Sign in with Google', 'siteferry' ); ?></button>
					<button type="button" class="button-link" data-role="disconnect" hidden><?php esc_html_e( 'Disconnect', 'siteferry' ); ?></button>
				</div>
				<p class="sf-gd-fine" data-mode="relay">
					<?php
					printf(
						/* translators: %s: host name of the sign-in service */
						esc_html__( 'Signing in goes through SiteFerry\'s sign-in service at %s, which stores nothing. SiteFerry sees only the files and folders it creates, and backups go straight between this site and your Drive.', 'siteferry' ),
						'<strong>' . esc_html( $relay_host ) . '</strong>'
					);
					?>
				</p>
			</section>

			<section class="sf-gd-block" aria-labelledby="siteferry-gdrive-where">
				<h4 class="sf-gd-title" id="siteferry-gdrive-where"><?php esc_html_e( 'Backups go to', 'siteferry' ); ?></h4>
				<input type="hidden" name="parent_id">
				<input type="hidden" name="parent_path">
				<div class="sf-gd-path-row">
					<p class="sf-gd-path" data-role="place" aria-live="polite"><?php esc_html_e( 'My Drive', 'siteferry' ); ?></p>
					<p class="sf-drive-actions">
						<button type="button" class="button" data-role="pick"><span class="dashicons dashicons-category" aria-hidden="true"></span> <?php esc_html_e( 'Choose a folder in Drive', 'siteferry' ); ?></button>
						<button type="button" class="button-link" data-role="unpick" hidden><?php esc_html_e( 'Use the top of My Drive', 'siteferry' ); ?></button>
					</p>
				</div>
				<p class="description" data-role="pick-note"><?php esc_html_e( 'After signing in you can choose any folder of yours; SiteFerry gets access to that folder only.', 'siteferry' ); ?></p>
				<p class="sf-gd-name">
					<label for="siteferry-gdrive-folder"><?php esc_html_e( 'Backup folder', 'siteferry' ); ?></label>
					<input type="text" id="siteferry-gdrive-folder" name="folder" placeholder="SiteFerry" spellcheck="false" autocomplete="off">
				</p>
				<p class="description"><?php esc_html_e( 'SiteFerry creates it when it is missing. Put / between names for folders inside each other, for example Backups/My site.', 'siteferry' ); ?></p>
			</section>

			<details class="sf-gd-advanced" data-role="gd-advanced">
				<summary><?php esc_html_e( 'Advanced', 'siteferry' ); ?> <span class="sf-gd-advanced-sum" data-role="advanced-sum"></span></summary>
				<p class="sf-field">
					<label for="siteferry-gdrive-mode"><?php esc_html_e( 'Sign-in method', 'siteferry' ); ?></label>
					<select id="siteferry-gdrive-mode" name="mode" data-default="relay">
						<option value="relay"><?php esc_html_e( 'Sign in with Google (recommended)', 'siteferry' ); ?></option>
						<option value="own"><?php esc_html_e( 'Use my own OAuth client', 'siteferry' ); ?></option>
					</select>
				</p>
				<details class="siteferry-howto" data-mode="own">
					<summary><?php esc_html_e( 'How to create your own OAuth client (once)', 'siteferry' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'In the Google Cloud console, create a project and enable the Google Drive API for it.', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Set up the OAuth consent screen (External; add yourself as a test user). Apps left in "Testing" lose their authorization after 7 days; publish the app to keep it. SiteFerry asks only for the drive.file scope.', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Create an OAuth client ID of type "Web application" and add the redirect URI below as an authorized redirect URI.', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Enter the client ID and secret here, then click "Connect to Google Drive" (this also saves them).', 'siteferry' ); ?></li>
					</ol>
				</details>
				<table class="form-table" role="presentation">
					<tr data-mode="own">
						<th scope="row"><?php esc_html_e( 'Redirect URI', 'siteferry' ); ?></th>
						<td>
							<code class="siteferry-redirect" data-role="redirect"><?php echo esc_html( $redirect ); ?></code>
							<button type="button" class="button button-small" data-role="copy" data-copy="<?php echo esc_attr( $redirect ); ?>"><?php esc_html_e( 'Copy', 'siteferry' ); ?></button>
							<p class="description"><?php esc_html_e( 'Register exactly this address with your OAuth client.', 'siteferry' ); ?></p>
						</td>
					</tr>
					<tr data-mode="own">
						<th scope="row"><label for="siteferry-gdrive-client_id"><?php esc_html_e( 'Client ID', 'siteferry' ); ?></label></th>
						<td><input type="text" class="large-text" id="siteferry-gdrive-client_id" name="client_id" placeholder="1234-abc.apps.googleusercontent.com" spellcheck="false" autocomplete="off"></td><?php // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- an example client ID in a placeholder; nothing is loaded. ?>
					</tr>
					<tr data-mode="own">
						<th scope="row"><label for="siteferry-gdrive-client_secret"><?php esc_html_e( 'Client secret', 'siteferry' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="siteferry-gdrive-client_secret" name="client_secret" spellcheck="false" autocomplete="new-password">
							<p class="description" data-role="secret-note"><?php esc_html_e( 'Stored encrypted. It is never shown again.', 'siteferry' ); ?></p>
						</td>
					</tr>
					<tr data-mode="own">
						<th scope="row"><label for="siteferry-gdrive-picker_key"><?php esc_html_e( 'Picker API key', 'siteferry' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="siteferry-gdrive-picker_key" name="picker_key" spellcheck="false" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Optional, to choose a folder in Google Drive: an API key restricted to the Google Picker API (APIs & Services, Credentials).', 'siteferry' ); ?></p>
						</td>
					</tr>
					<tr data-mode="own">
						<th scope="row"><label for="siteferry-gdrive-app_id"><?php esc_html_e( 'Project number', 'siteferry' ); ?></label></th>
						<td><input type="text" class="regular-text" id="siteferry-gdrive-app_id" name="app_id" inputmode="numeric" spellcheck="false" autocomplete="off"></td>
					</tr>
				</table>
				<?php self::render_split(); ?>
			</details>

			<p class="sf-form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'siteferry' ); ?></button>
				<button type="button" class="button" data-role="test"><?php esc_html_e( 'Test connection', 'siteferry' ); ?></button>
				<button type="button" class="button-link button-link-delete" data-role="remove"><?php esc_html_e( 'Remove these settings', 'siteferry' ); ?></button>
			</p>
			<div class="sf-result" data-role="result" role="status" hidden></div>
		</form>
		<?php
	}

	/**
	 * Prints the Dropbox settings form: one-click sign-in through SiteFerry's sign-in service, or
	 * (advanced) the administrator's own Dropbox app with the redirect URI to register. Neither
	 * needs a secret.
	 */
	private static function render_dropbox(): void {
		$redirect   = \SiteFerry\Storage\Destinations::redirect_uri( 'dropbox' );
		$relay_host = (string) wp_parse_url( \SiteFerry\Storage\Gdrive_Driver::relay_url(), PHP_URL_HOST );
		?>
		<form id="siteferry-storage-dropbox" class="siteferry-storage-form" data-destination="dropbox" method="post" action="#" autocomplete="off">
			<p>
				<label for="siteferry-dropbox-mode"><?php esc_html_e( 'Sign-in:', 'siteferry' ); ?></label>
				<select id="siteferry-dropbox-mode" name="mode" data-default="relay">
					<option value="relay"><?php esc_html_e( 'Sign in with Dropbox (recommended)', 'siteferry' ); ?></option>
					<option value="own"><?php esc_html_e( 'Use my own Dropbox app (advanced)', 'siteferry' ); ?></option>
				</select>
			</p>
			<p class="description" data-mode="relay">
				<?php
				printf(
					/* translators: %s: host name of the sign-in service */
					esc_html__( 'Sign in with your Dropbox account; no keys needed. The sign-in page opens through SiteFerry\'s sign-in service at %s, which only passes your browser on to Dropbox and back: the access itself goes straight to this site and never through the service. Backups go straight between this site and SiteFerry\'s own folder in your Dropbox (Apps, SiteFerry); SiteFerry cannot see anything else there.', 'siteferry' ),
					'<strong>' . esc_html( $relay_host ) . '</strong>'
				);
				?>
			</p>
			<details class="siteferry-howto" data-mode="own">
				<summary><?php esc_html_e( 'How to create your own Dropbox app (once)', 'siteferry' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'In the Dropbox App Console (dropbox.com/developers/apps), click "Create app": choose "Scoped access" and "App folder", and give it a name. Its folder appears in your Dropbox under Apps.', 'siteferry' ); ?></li>
					<li><?php esc_html_e( 'On the Permissions tab, tick files.metadata.read, files.content.read and files.content.write, then click Submit.', 'siteferry' ); ?></li>
					<li><?php esc_html_e( 'On the Settings tab, add the redirect URI below under OAuth 2, Redirect URIs (Dropbox accepts http only for localhost).', 'siteferry' ); ?></li>
					<li><?php esc_html_e( 'Enter the app key here (no app secret is needed), then click "Connect to Dropbox" (this also saves it).', 'siteferry' ); ?></li>
				</ol>
			</details>
			<table class="form-table" role="presentation">
				<tr data-mode="own">
					<th scope="row"><?php esc_html_e( 'Redirect URI', 'siteferry' ); ?></th>
					<td>
						<code class="siteferry-redirect" data-role="redirect"><?php echo esc_html( $redirect ); ?></code>
						<button type="button" class="button button-small" data-role="copy" data-copy="<?php echo esc_attr( $redirect ); ?>"><?php esc_html_e( 'Copy', 'siteferry' ); ?></button>
						<p class="description"><?php esc_html_e( 'Register exactly this address with your Dropbox app.', 'siteferry' ); ?></p>
					</td>
				</tr>
				<tr data-mode="own">
					<th scope="row"><label for="siteferry-dropbox-app_key"><?php esc_html_e( 'App key', 'siteferry' ); ?></label></th>
					<td><input type="text" class="regular-text" id="siteferry-dropbox-app_key" name="app_key" placeholder="a1b2c3d4e5f6g7h" spellcheck="false" autocomplete="off"></td>
				</tr>
				<tr>
					<th scope="row"><label for="siteferry-dropbox-folder"><?php esc_html_e( 'Folder in the app folder', 'siteferry' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="siteferry-dropbox-folder" name="folder" placeholder="<?php esc_attr_e( '(the app folder itself)', 'siteferry' ); ?>" spellcheck="false" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Optional, for example the site\'s name when several sites share one Dropbox. Dropbox creates it with the first backup.', 'siteferry' ); ?></p>
					</td>
				</tr>
			</table>
			<?php self::render_split(); ?>
			<p class="sf-form-actions">
				<button type="button" class="button button-primary" data-role="connect" data-label-relay="<?php esc_attr_e( 'Sign in with Dropbox', 'siteferry' ); ?>" data-label-own="<?php esc_attr_e( 'Connect to Dropbox', 'siteferry' ); ?>"><?php esc_html_e( 'Sign in with Dropbox', 'siteferry' ); ?></button>
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'siteferry' ); ?></button>
				<button type="button" class="button" data-role="test"><?php esc_html_e( 'Test connection', 'siteferry' ); ?></button>
				<button type="button" class="button" data-role="disconnect" hidden><?php esc_html_e( 'Disconnect', 'siteferry' ); ?></button>
				<button type="button" class="button-link button-link-delete" data-role="remove"><?php esc_html_e( 'Remove these settings', 'siteferry' ); ?></button>
			</p>
			<div class="sf-result" data-role="result" role="status" hidden></div>
		</form>
		<?php
	}

	/**
	 * Prints the FTP settings form (submitted by script, like the S3 form).
	 */
	private static function render_ftp(): void {
		$info = \SiteFerry\Storage\Ftp_Driver::info();
		?>
		<form id="siteferry-storage-ftp" class="siteferry-storage-form" data-destination="ftp" method="post" action="#" autocomplete="off">
			<?php if ( ! $info['available'] ) : ?>
				<p class="sf-caution"><?php esc_html_e( 'This server\'s PHP has no ftp extension, so FTP cannot be used here. Ask the host to enable it.', 'siteferry' ); ?></p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="siteferry-ftp-host"><?php esc_html_e( 'Server', 'siteferry' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="siteferry-ftp-host" name="host" placeholder="ftp.example.com" spellcheck="false" autocomplete="off">
						<label for="siteferry-ftp-port"><?php esc_html_e( 'Port', 'siteferry' ); ?></label>
						<input type="number" class="small-text" id="siteferry-ftp-port" name="port" min="1" max="65535" placeholder="21">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="siteferry-ftp-encryption"><?php esc_html_e( 'Encryption', 'siteferry' ); ?></label></th>
					<td>
						<select id="siteferry-ftp-encryption" name="encryption" data-default="ftps">
							<option value="ftps"><?php esc_html_e( 'Explicit FTPS (TLS) - recommended', 'siteferry' ); ?></option>
							<option value="ftp"><?php esc_html_e( 'None: plain FTP (password and backups unencrypted)', 'siteferry' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'FTPS on port 990 ("implicit") and SFTP are not supported. PHP\'s FTP functions encrypt the connection but cannot check the server\'s certificate.', 'siteferry' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="siteferry-ftp-username"><?php esc_html_e( 'User name', 'siteferry' ); ?></label></th>
					<td><input type="text" class="regular-text" id="siteferry-ftp-username" name="username" spellcheck="false" autocomplete="off"></td>
				</tr>
				<tr>
					<th scope="row"><label for="siteferry-ftp-password"><?php esc_html_e( 'Password', 'siteferry' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="siteferry-ftp-password" name="password" spellcheck="false" autocomplete="new-password">
						<p class="description" data-role="secret-note"><?php esc_html_e( 'Stored encrypted. It is never shown again.', 'siteferry' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="siteferry-ftp-folder"><?php esc_html_e( 'Folder', 'siteferry' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="siteferry-ftp-folder" name="folder" placeholder="siteferry" spellcheck="false" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Relative to the folder you land in after logging in, or starting with / from the server\'s top. Created if missing. Keep it outside the public web folder.', 'siteferry' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'siteferry' ); ?></th>
					<td><label><input type="checkbox" name="passive" data-default="1" checked> <?php esc_html_e( 'Passive mode (works through most firewalls)', 'siteferry' ); ?></label></td>
				</tr>
			</table>
			<?php self::render_split(); ?>
			<p class="sf-form-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'siteferry' ); ?></button>
				<button type="button" class="button" data-role="test"><?php esc_html_e( 'Test connection', 'siteferry' ); ?></button>
				<button type="button" class="button-link button-link-delete" data-role="remove"><?php esc_html_e( 'Remove these settings', 'siteferry' ); ?></button>
			</p>
			<div class="sf-result" data-role="result" role="status" hidden></div>
		</form>
		<?php
	}
}
