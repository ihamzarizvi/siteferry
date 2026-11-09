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
	// /google/callback, exactly as registered in the Google Cloud console; Dropbox's is this
	// plus /dropbox/callback, as registered in the Dropbox App Console.
	'base_url'             => 'https://auth.xsofty.com/siteferry',

	// At least 32 random characters, for signing the sign-in state. Make one with:
	// php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
	'signing_key'          => '',

	// From the Google Cloud console: the OAuth client of type "Web application". Leave both
	// empty to offer only Dropbox.
	'google_client_id'     => '',
	'google_client_secret' => '',
	// Optional, for choosing a folder in Google Drive from the Storage tab: an API key restricted to
	// the Google Picker API (APIs & Services, Credentials), and the project number (12 digits, from
	// the project's settings). Both are public by design. Leave empty to keep only the folder name.
	'google_picker_key'    => '',
	'google_app_id'        => '',

	// From the Dropbox App Console: SiteFerry's app (Scoped access, App folder), Settings, App key.
	// Its OAuth 2 redirect URI is base_url plus /dropbox/callback. No app secret: sign-ins use
	// PKCE, and the tokens go straight between each site and Dropbox. Leave it empty to offer
	// only Google Drive.
	'dropbox_app_key'      => '',
);
