<?php
/**
 * The must-use plugin written into a staging copy.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Staging;

defined( 'ABSPATH' ) || exit;

/**
 * Source of wp-content/mu-plugins/siteferry-staging.php in the copy. It marks the copy as staging
 * (admin bar badge, and a bar on the site for signed-in users when the admin bar is hidden), keeps
 * search engines away (noindex header and robots meta, "discourage search engines" forced on) and
 * holds back outgoing email unless an administrator turns it on from the badge's menu.
 */
final class Mu_Plugin {

	/** File name in mu-plugins. */
	const FILE = 'siteferry-staging.php';

	/**
	 * The plugin's PHP source.
	 *
	 * @param string $live The live site's address.
	 */
	public static function source( string $live ): string {
		$host = (string) wp_parse_url( $live, PHP_URL_HOST );
		$port = wp_parse_url( $live, PHP_URL_PORT );
		$name = $host . ( $port ? ':' . $port : '' ) . (string) wp_parse_url( $live, PHP_URL_PATH );
		// One pass (strtr): text put in is never searched again, so an address that contains a
		// placeholder name cannot be rewritten inside its own string literal.
		return strtr(
			self::TEMPLATE,
			array(
				'__SF_LIVE__' => var_export( $live, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
				'__SF_NAME__' => var_export( '' === $name ? $live : $name, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- writes a PHP string literal.
			)
		);
	}

	const TEMPLATE = <<<'PHP'
<?php
/**
 * Plugin Name: SiteFerry staging copy
 * Description: Marks this site as a staging copy made by SiteFerry, keeps search engines away and holds back outgoing email. Delete the staging copy from SiteFerry on the live site.
 * License: GPL-2.0-or-later
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SITEFERRY_STAGING_OF' ) ) {
	define( 'SITEFERRY_STAGING_OF', __SF_LIVE__ );
}

// Search engines stay away.
add_filter( 'pre_option_blog_public', static function () {
	return '0';
} );
add_action( 'send_headers', static function () {
	header( 'X-Robots-Tag: noindex, nofollow', true );
} );
add_filter( 'wp_robots', static function ( $robots ) {
	$robots['noindex']  = true;
	$robots['nofollow'] = true;
	return $robots;
} );

// Outgoing email is held back unless turned on.
add_filter( 'pre_wp_mail', static function ( $result ) {
	return get_option( 'siteferry_staging_mail' ) ? $result : false;
} );

add_action( 'admin_post_siteferry_staging_mail', static function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'default' ), 403 );
	}
	check_admin_referer( 'siteferry_staging_mail' );
	update_option( 'siteferry_staging_mail', empty( $_GET['on'] ) ? 0 : 1, false );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
} );

add_action( 'admin_bar_menu', static function ( $bar ) {
	$bar->add_node( array(
		'id'    => 'siteferry-staging',
		'title' => esc_html( 'STAGING · copy of ' . __SF_NAME__ ),
		'href'  => esc_url( SITEFERRY_STAGING_OF ),
		'meta'  => array( 'title' => 'This is a staging copy. Changes here do not reach the live site.' ),
	) );
	if ( current_user_can( 'manage_options' ) ) {
		$on = (bool) get_option( 'siteferry_staging_mail' );
		$bar->add_node( array(
			'parent' => 'siteferry-staging',
			'id'     => 'siteferry-staging-mail',
			'title'  => esc_html( $on ? 'Email is on — hold it back' : 'Email is held back — turn it on' ),
			'href'   => esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=siteferry_staging_mail&on=' . ( $on ? '0' : '1' ) ), 'siteferry_staging_mail' ) ),
		) );
		$bar->add_node( array(
			'parent' => 'siteferry-staging',
			'id'     => 'siteferry-staging-live',
			'title'  => esc_html( 'Open the live site' ),
			'href'   => esc_url( SITEFERRY_STAGING_OF ),
		) );
	}
}, 5 );

$siteferry_staging_style = static function () {
	echo '<style>#wpadminbar #wp-admin-bar-siteferry-staging > .ab-item{background:#c2352d;color:#fff;font-weight:600}@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-siteferry-staging{display:block;float:left}#wpadminbar #wp-admin-bar-siteferry-staging > .ab-item{width:auto;max-width:34vw;padding:0 8px;overflow:hidden;font-size:12px;line-height:46px;white-space:nowrap;text-overflow:ellipsis}}.siteferry-staging-bar{position:fixed;top:0;left:0;right:0;z-index:99999;padding:4px 12px;background:#c2352d;color:#fff;font:600 13px/1.6 system-ui,sans-serif;text-align:center}</style>';
};
add_action( 'admin_head', $siteferry_staging_style );
add_action( 'wp_head', $siteferry_staging_style );

add_action( 'wp_footer', static function () {
	if ( is_user_logged_in() && ! is_admin_bar_showing() ) {
		echo '<div class="siteferry-staging-bar">' . esc_html( 'STAGING · copy of ' . __SF_NAME__ ) . '</div>';
	}
} );
PHP;
}
