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
		$hook = add_menu_page(
			__( 'SiteFerry', 'siteferry' ),
			__( 'SiteFerry', 'siteferry' ),
			'manage_options',
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
		wp_enqueue_script( 'siteferry-admin', $url . 'admin.js', array(), $version( 'admin.js' ), true );
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
						<div class="sf-home-actions">
							<button type="submit" form="siteferry-export-form" class="button button-primary button-large" id="siteferry-backup-now"><?php esc_html_e( 'Back up now', 'siteferry' ); ?></button>
							<button type="button" class="button button-large" id="siteferry-options-toggle" aria-expanded="false" aria-controls="siteferry-export-form"><?php esc_html_e( 'Options', 'siteferry' ); ?></button>
							<button type="button" class="button button-large" id="siteferry-upload-toggle" aria-expanded="false" aria-controls="siteferry-upload"><?php esc_html_e( 'Upload a backup', 'siteferry' ); ?></button>
						</div>
					</div>

					<form id="siteferry-export-form" class="sf-drawer" hidden>
						<p class="sf-drawer-note"><?php esc_html_e( '"Back up now" uses these choices. Without changes it backs up the whole site to this server.', 'siteferry' ); ?></p>
						<h3 class="sf-sub"><?php esc_html_e( 'Name, note and tags', 'siteferry' ); ?></h3>
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
							<?php esc_html_e( 'To send backups to S3, an FTP server or Google Drive, set one up first.', 'siteferry' ); ?>
							<a href="#storage" data-goto="storage"><?php esc_html_e( 'Set up storage', 'siteferry' ); ?></a>
						</p>
						<p class="sf-download-ready"><label><input type="checkbox" id="siteferry-download-ready"> <?php esc_html_e( 'Also download it to my computer when it is ready', 'siteferry' ); ?></label></p>
					</form>

					<div id="siteferry-upload" class="sf-drawer" hidden>
						<p class="sf-drawer-note"><?php esc_html_e( 'Upload a backup to restore it here, or to move another site here. It is added to the list below; nothing changes until you choose to restore it.', 'siteferry' ); ?></p>
						<input type="file" id="siteferry-file" class="sf-file-input" accept=".tar,.gz,.tgz,.wpress">
						<label for="siteferry-file" class="sf-drop" id="siteferry-drop">
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<span class="sf-drop-main"><?php esc_html_e( 'Drop a backup file here, or choose one', 'siteferry' ); ?></span>
							<span class="sf-drop-note"><?php esc_html_e( 'A .tar or .tar.gz made by SiteFerry, or a .wpress made by All-in-One WP Migration (without a password). Large files upload in pieces; an interrupted upload continues when you choose the same file again.', 'siteferry' ); ?></span>
						</label>
						<div class="sf-progress" id="siteferry-upload-progress" hidden></div>
						<p id="siteferry-unfinished-uploads" class="description" hidden></p>
					</div>
				</div>

				<div class="sf-progress" id="siteferry-job-progress" hidden></div>

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
					<details class="sf-more" id="siteferry-restore-advanced">
						<summary><?php esc_html_e( 'Advanced', 'siteferry' ); ?></summary>
						<p class="sf-field">
							<label for="siteferry-replace-url"><?php esc_html_e( 'Site address after restoring', 'siteferry' ); ?></label>
							<input type="url" class="regular-text" id="siteferry-replace-url" placeholder="<?php echo esc_attr( home_url() ); ?>">
							<span class="description"><?php esc_html_e( 'Leave empty to keep this site\'s address. Links inside the content are changed to match.', 'siteferry' ); ?></span>
						</p>
						<p><label><input type="checkbox" id="siteferry-include-mu"> <?php esc_html_e( 'Also copy must-use plugins', 'siteferry' ); ?></label></p>
						<p><label><input type="checkbox" id="siteferry-include-dropins"> <?php esc_html_e( 'Also copy server-specific drop-ins (object-cache.php, advanced-cache.php, db.php)', 'siteferry' ); ?></label></p>
					</details>
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
					<div class="sf-filter" id="siteferry-filter" hidden>
						<label class="screen-reader-text" for="siteferry-search"><?php esc_html_e( 'Search backups', 'siteferry' ); ?></label>
						<input type="search" id="siteferry-search" placeholder="<?php esc_attr_e( 'Search names, notes, tags and file names', 'siteferry' ); ?>">
						<div class="sf-tag-filter" id="siteferry-tag-filter" role="group" aria-label="<?php esc_attr_e( 'Show only backups with this tag', 'siteferry' ); ?>"></div>
					</div>
					<div class="sf-backup sf-columns" id="siteferry-columns" aria-hidden="true" hidden>
						<span></span>
						<span><?php esc_html_e( 'Backup', 'siteferry' ); ?></span>
						<span><?php esc_html_e( 'Stored on', 'siteferry' ); ?></span>
						<span class="sf-backup-size"><?php esc_html_e( 'Size', 'siteferry' ); ?></span>
						<span></span>
					</div>
					<ul class="sf-list" id="siteferry-backups"></ul>
				</div>

				<details class="sf-help">
					<summary><?php esc_html_e( 'Moving a site here?', 'siteferry' ); ?></summary>
					<ol>
						<li><?php esc_html_e( 'On the old site, make a backup with SiteFerry (or with All-in-One WP Migration) and download it, or send it to a storage this site can reach too.', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Here, click "Upload a backup", or find it in the list if it is on a storage set up on this site.', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Click "Move here". SiteFerry checks the backup, shows what will change, and only then replaces this site\'s content. The addresses are changed to this site\'s.', 'siteferry' ); ?></li>
					</ol>
				</details>
			</section>

			<section class="siteferry-panel" data-panel="storage" hidden>
				<p class="sf-lead"><?php esc_html_e( 'Places SiteFerry can send backups to and fetch them back from. Keys are stored encrypted, are never shown again and are not part of any backup. SiteFerry connects only to the services set up here.', 'siteferry' ); ?></p>
				<div class="sf-dests">
					<?php
					self::render_destination( 's3', 'dashicons-cloud', __( 'S3-compatible storage', 'siteferry' ), __( 'Amazon S3, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, MinIO', 'siteferry' ), array( self::class, 'render_s3' ) );
					self::render_destination( 'ftp', 'dashicons-networking', __( 'FTP server', 'siteferry' ), __( 'FTPS (encrypted) or plain FTP', 'siteferry' ), array( self::class, 'render_ftp' ) );
					self::render_destination( 'gdrive', 'dashicons-google', __( 'Google Drive', 'siteferry' ), __( 'Sign in with your Google account', 'siteferry' ), array( self::class, 'render_gdrive' ) );
					?>
					<div class="sf-dest is-soon">
						<div class="sf-dest-head">
							<span class="sf-dest-icon dashicons dashicons-cloud-saved" aria-hidden="true"></span>
							<span class="sf-dest-text"><span class="sf-dest-name"><?php esc_html_e( 'Dropbox', 'siteferry' ); ?></span><span class="sf-dest-state"><?php esc_html_e( 'Comes in a later version.', 'siteferry' ); ?></span></span>
						</div>
					</div>
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
					<tr>
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
				<tr>
					<th scope="row"><?php esc_html_e( 'Addressing', 'siteferry' ); ?></th>
					<td><label><input type="checkbox" name="path_style"> <?php esc_html_e( 'Path-style requests (endpoint/bucket/file instead of bucket.endpoint/file)', 'siteferry' ); ?></label></td>
				</tr>
			</table>
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
		<form id="siteferry-storage-gdrive" class="siteferry-storage-form" data-destination="gdrive" method="post" action="#" autocomplete="off">
			<p>
				<label for="siteferry-gdrive-mode"><?php esc_html_e( 'Sign-in:', 'siteferry' ); ?></label>
				<select id="siteferry-gdrive-mode" name="mode" data-default="relay">
					<option value="relay"><?php esc_html_e( 'Sign in with Google (recommended)', 'siteferry' ); ?></option>
					<option value="own"><?php esc_html_e( 'Use my own OAuth client (advanced)', 'siteferry' ); ?></option>
				</select>
			</p>
			<p class="description" data-mode="relay">
				<?php
				printf(
					/* translators: %s: host name of the sign-in service */
					esc_html__( 'Sign in with your Google account; no keys needed. Signing in, and renewing the access while SiteFerry works with your Drive, go through SiteFerry\'s sign-in service at %s, which holds SiteFerry\'s Google app secret and stores nothing. Backups go straight between this site and your Google Drive.', 'siteferry' ),
					'<strong>' . esc_html( $relay_host ) . '</strong>'
				);
				?>
			</p>
			<details class="siteferry-howto" data-mode="own">
				<summary><?php esc_html_e( 'How to create your own OAuth client (once)', 'siteferry' ); ?></summary>
				<ol>
					<li><?php esc_html_e( 'In the Google Cloud console, create a project and enable the Google Drive API for it.', 'siteferry' ); ?></li>
					<li><?php esc_html_e( 'Set up the OAuth consent screen (External; add yourself as a test user). Apps left in "Testing" lose their authorization after 7 days; publish the app to keep it. SiteFerry asks only for the drive.file scope.', 'siteferry' ); ?></li>
					<li><?php esc_html_e( 'Create an OAuth client ID of type "Web application" and add the redirect URI below as an authorized redirect URI.', 'siteferry' ); ?></li>
					<li><?php esc_html_e( 'Enter the client ID and secret here, save, then click "Connect to Google Drive".', 'siteferry' ); ?></li>
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
					<td><input type="text" class="large-text" id="siteferry-gdrive-client_id" name="client_id" placeholder="1234-abc.apps.googleusercontent.com" spellcheck="false" autocomplete="off"></td>
				</tr>
				<tr data-mode="own">
					<th scope="row"><label for="siteferry-gdrive-client_secret"><?php esc_html_e( 'Client secret', 'siteferry' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="siteferry-gdrive-client_secret" name="client_secret" spellcheck="false" autocomplete="new-password">
						<p class="description" data-role="secret-note"><?php esc_html_e( 'Stored encrypted. It is never shown again.', 'siteferry' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="siteferry-gdrive-folder"><?php esc_html_e( 'Folder in My Drive', 'siteferry' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="siteferry-gdrive-folder" name="folder" placeholder="SiteFerry" spellcheck="false" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Created at the top of My Drive. SiteFerry can only see the files and folders it created itself.', 'siteferry' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="sf-form-actions">
				<button type="button" class="button button-primary" data-role="connect"><?php esc_html_e( 'Sign in with Google', 'siteferry' ); ?></button>
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
