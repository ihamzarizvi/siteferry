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
		wp_enqueue_style( 'siteferry-admin', $url . 'admin.css', array(), SITEFERRY_VERSION );
		wp_enqueue_script( 'siteferry-admin', $url . 'admin.js', array(), SITEFERRY_VERSION, true );
		wp_add_inline_script(
			'siteferry-admin',
			'window.SiteFerryConfig = ' . wp_json_encode(
				array(
					'root'     => esc_url_raw( rest_url( Rest::NS . '/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'home'     => home_url(),
					'compress' => Requirements::can_compress(),
					'chunk'    => Rest::chunk_size(),
					'loginUrl' => wp_login_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
				)
			) . ';',
			'before'
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
			'export'  => __( 'Export', 'siteferry' ),
			'import'  => __( 'Import', 'siteferry' ),
			'backups' => __( 'Backups', 'siteferry' ),
			'storage' => __( 'Storage', 'siteferry' ),
			'log'     => __( 'Log', 'siteferry' ),
		);
		$leave_out = array(
			'exclude_media'            => array( __( 'Media library (uploads)', 'siteferry' ), false ),
			'exclude_plugins'          => array( __( 'Plugins', 'siteferry' ), false ),
			'exclude_themes'           => array( __( 'Themes', 'siteferry' ), false ),
			'exclude_inactive_plugins' => array( __( 'Inactive plugins', 'siteferry' ), false ),
			'exclude_inactive_themes'  => array( __( 'Inactive themes', 'siteferry' ), false ),
			'exclude_cache'            => array( __( 'Cache folders', 'siteferry' ), true ),
			'exclude_spam_comments'    => array( __( 'Spam comments', 'siteferry' ), false ),
			'exclude_revisions'        => array( __( 'Post revisions', 'siteferry' ), false ),
		);
		?>
		<div class="wrap siteferry" id="siteferry">
			<h1><?php esc_html_e( 'SiteFerry', 'siteferry' ); ?></h1>
			<div id="siteferry-notice" class="siteferry-notice" hidden></div>
			<div id="siteferry-active" class="notice notice-warning siteferry-active" hidden>
				<p><span id="siteferry-active-text"></span>
					<button type="button" class="button" id="siteferry-active-resume" hidden><?php esc_html_e( 'Resume', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-active-cancel"><?php esc_html_e( 'Cancel it', 'siteferry' ); ?></button>
				</p>
			</div>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'SiteFerry sections', 'siteferry' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="nav-tab" data-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<section class="siteferry-panel" data-panel="export" hidden>
				<form id="siteferry-export-form">
					<h2><?php esc_html_e( 'Export this site', 'siteferry' ); ?></h2>
					<p><?php esc_html_e( 'Saves the database and wp-content into one backup file. Other backup plugins\' folders, upgrade folders, .git and node_modules are always left out.', 'siteferry' ); ?></p>
					<fieldset class="siteferry-options">
						<legend><?php esc_html_e( 'Leave out', 'siteferry' ); ?></legend>
						<?php foreach ( $leave_out as $name => list( $label, $checked ) ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" <?php checked( $checked ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<details class="siteferry-tables">
						<summary><?php esc_html_e( 'Leave out database tables', 'siteferry' ); ?></summary>
						<div id="siteferry-tables"><?php esc_html_e( 'Loading...', 'siteferry' ); ?></div>
					</details>
					<?php if ( Requirements::can_compress() ) : ?>
						<p><label><input type="checkbox" name="compress"> <?php esc_html_e( 'Compress (.tar.gz): smaller, slower', 'siteferry' ); ?></label></p>
					<?php endif; ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Export', 'siteferry' ); ?></button></p>
				</form>
				<div class="siteferry-progress" id="siteferry-export-progress" hidden></div>
			</section>

			<section class="siteferry-panel" data-panel="import" hidden>
				<h2><?php esc_html_e( 'Import a backup into this site', 'siteferry' ); ?></h2>
				<p class="siteferry-warning"><?php esc_html_e( 'An import replaces this site\'s database and files. SiteFerry and its settings stay. You will need to log in with the imported site\'s users afterwards.', 'siteferry' ); ?></p>
				<h3><?php esc_html_e( '1. Choose a backup', 'siteferry' ); ?></h3>
				<p>
					<label for="siteferry-file"><?php esc_html_e( 'Upload a backup file:', 'siteferry' ); ?></label>
					<input type="file" id="siteferry-file" accept=".tar,.gz,.tgz">
				</p>
				<div class="siteferry-progress" id="siteferry-upload-progress" hidden></div>
				<p id="siteferry-unfinished-uploads" class="description" hidden></p>
				<p><?php esc_html_e( 'Or pick a stored backup on the Backups tab.', 'siteferry' ); ?></p>
				<p><?php esc_html_e( 'Selected backup:', 'siteferry' ); ?> <strong id="siteferry-import-selected"><?php esc_html_e( 'none', 'siteferry' ); ?></strong></p>

				<h3><?php esc_html_e( '2. Options', 'siteferry' ); ?></h3>
				<p>
					<label for="siteferry-replace-url"><?php esc_html_e( 'Site address after the import:', 'siteferry' ); ?></label>
					<input type="url" class="regular-text" id="siteferry-replace-url" placeholder="<?php echo esc_attr( home_url() ); ?>">
					<span class="description"><?php esc_html_e( 'Leave empty to keep this site\'s address.', 'siteferry' ); ?></span>
				</p>
				<p><label><input type="checkbox" id="siteferry-include-mu"> <?php esc_html_e( 'Also copy must-use plugins', 'siteferry' ); ?></label></p>
				<p><label><input type="checkbox" id="siteferry-include-dropins"> <?php esc_html_e( 'Also copy server-specific drop-ins (object-cache.php, advanced-cache.php, db.php)', 'siteferry' ); ?></label></p>
				<p><button type="button" class="button button-primary" id="siteferry-import-start" disabled><?php esc_html_e( 'Check backup', 'siteferry' ); ?></button></p>

				<div id="siteferry-inspection" hidden>
					<h3><?php esc_html_e( '3. Confirm', 'siteferry' ); ?></h3>
					<table class="widefat striped siteferry-summary"><tbody id="siteferry-inspection-rows"></tbody></table>
					<ul id="siteferry-inspection-warnings" class="siteferry-warnings"></ul>
					<p><label><input type="checkbox" id="siteferry-confirm-check"> <?php esc_html_e( 'I understand that this replaces this site\'s database and files.', 'siteferry' ); ?></label></p>
					<p>
						<button type="button" class="button button-primary" id="siteferry-confirm" disabled><?php esc_html_e( 'Import now', 'siteferry' ); ?></button>
						<button type="button" class="button" id="siteferry-import-cancel"><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
					</p>
				</div>
				<div class="siteferry-progress" id="siteferry-import-progress" hidden></div>
			</section>

			<section class="siteferry-panel" data-panel="backups" hidden>
				<h2><?php esc_html_e( 'Backups', 'siteferry' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'File', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'Size', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'Made', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'From', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'siteferry' ); ?></th>
					</tr></thead>
					<tbody id="siteferry-backups"></tbody>
				</table>
			</section>

			<section class="siteferry-panel" data-panel="storage" hidden>
				<h2><?php esc_html_e( 'Storage', 'siteferry' ); ?></h2>
				<p><?php esc_html_e( 'Sending backups to S3-compatible storage, FTP/FTPS, Google Drive and Dropbox comes in the next versions. Backups are kept on this server for now.', 'siteferry' ); ?></p>
			</section>

			<section class="siteferry-panel" data-panel="log" hidden>
				<h2><?php esc_html_e( 'Recent jobs', 'siteferry' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Started', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'Type', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'Status', 'siteferry' ); ?></th>
						<th><?php esc_html_e( 'Details', 'siteferry' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody id="siteferry-jobs"></tbody>
				</table>
				<pre id="siteferry-log-view" class="siteferry-log" hidden></pre>
			</section>
		</div>
		<?php
	}
}
