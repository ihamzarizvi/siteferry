<?php
/**
 * SiteFerry relay settings. Copy this file to config.php next to it (outside the web root),
 * fill it in on the server, and make it readable only by the web server's user.
 *
 * Never commit config.php, email it or paste it into a chat.
 *
 * @package SiteFerry_Relay
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

return array(
	// The relay's own address, without a trailing slash. Google's redirect URI is this plus
	// /google/callback, exactly as registered in the Google Cloud console.
	'base_url'             => 'https://auth.xsofty.com/siteferry',

	// At least 32 random characters, for signing the sign-in state. Make one with:
	// php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
	'signing_key'          => '',

	// From the Google Cloud console: the OAuth client of type "Web application".
	'google_client_id'     => '',
	'google_client_secret' => '',
);
