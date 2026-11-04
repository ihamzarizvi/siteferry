<?php
/**
 * Errors while encrypting or decrypting secrets.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Security;

defined( 'ABSPATH' ) || exit;

/**
 * A secret could not be encrypted or decrypted. Messages never contain the secret.
 */
final class Secret_Exception extends \RuntimeException {
}
