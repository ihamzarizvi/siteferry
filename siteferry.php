<?php
/**
 * Plugin Name:       SiteFerry – Backup, Restore & Migration
 * Description:       Back up, restore and migrate WordPress sites: scheduled backups to Google Drive, Dropbox, Amazon S3 and FTP, staging copies and one-click moves to a new host.
 * Version:           0.47.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Plugin URI:        https://xsofty.com/products/siteferry
 * Author:            xsofty
 * Author URI:        https://xsofty.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siteferry
 * Domain Path:       /languages
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/bootstrap.php';
