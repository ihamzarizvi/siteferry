<?php
/**
 * Plugin Name:       SiteFerry
 * Description:       Back up a whole WordPress site into one TAR file and restore or migrate it to another site.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            The SiteFerry authors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siteferry
 *
 * @package SiteFerry
 *
 * Copyright (C) 2026 The SiteFerry authors
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not,
 * see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'SITEFERRY_VERSION', '0.1.0' );
define( 'SITEFERRY_FILE', __FILE__ );
define( 'SITEFERRY_DIR', __DIR__ );

require_once SITEFERRY_DIR . '/includes/class-autoloader.php';

\SiteFerry\Autoloader::register();

register_activation_hook( __FILE__, array( '\SiteFerry\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\SiteFerry\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\SiteFerry\Plugin', 'boot' ) );
