# SiteFerry sign-in relay

A small PHP service that makes "Sign in with Google" and "Sign in with Dropbox" work in SiteFerry
without each site owner creating a Google Cloud project or a Dropbox app. It runs on the
publisher's server (planned: `https://auth.xsofty.com/siteferry`).

Google and Dropbox send the browser back only to the redirect address registered for an app, and
SiteFerry is installed on many sites with different addresses; the relay is that one registered
address, and passes the browser on to the right site. For Google it also holds the secret of
SiteFerry's OAuth client, which a plugin cannot keep secret. Dropbox needs no secret (PKCE alone),
so for Dropbox the relay only passes the browser through: the tokens go straight between each site
and Dropbox.

It is not part of the plugin package (`.distignore` leaves `relay/` out).

## What it does

| Request | From | What happens |
| --- | --- | --- |
| `GET /siteferry/` | anyone | Health check: `200 SiteFerry relay` (`503` while config.php is missing or has no `base_url` and `signing_key`). |
| `GET /siteferry/google/start?return=…&state=…&challenge=…` | the administrator's browser | Checks that `return` is a SiteFerry admin page (`…/wp-admin/admin.php?page=siteferry&oauth=gdrive`), signs `return` and the site's `state` into its own state (valid 10 minutes), redirects to Google's consent page with the PKCE challenge. |
| `GET /siteferry/google/callback?code=…&state=…` | Google, through the browser | Checks the signature and the time, redirects the browser to the site's page with the code and the site's own state (or Google's error). |
| `POST /siteferry/google/token` | the WordPress site | Adds the client ID, secret and redirect URI to the site's code + PKCE verifier (first sign-in) or refresh token (renewals, about once an hour while a site works with its Drive), forwards it to Google and returns Google's answer unchanged. |
| `GET /siteferry/dropbox/start?return=…&state=…&challenge=…` | the administrator's browser | As for Google, with `oauth=dropbox`, to Dropbox's authorization page (offline access, the permissions `account_info.read files.metadata.read files.content.read files.content.write`, PKCE). Refuses site addresses so long that the signed state would pass Dropbox's 500-byte limit. |
| `GET /siteferry/dropbox/callback?code=…&state=…` | Dropbox, through the browser | As for Google. |
| `GET /siteferry/dropbox/app` | the WordPress site, once per sign-in | `{"app_key": "…", "redirect_uri": "…/dropbox/callback"}`: the public details the site needs to exchange the code with Dropbox itself and to renew its access later. |

Everything else is `404`. A provider the config does not set up answers `503` (a relay may offer
only Google Drive or only Dropbox). It stores nothing, writes no log of its own, sets no cookies and
answers with `Cache-Control: no-store`. A stolen code is useless without the PKCE verifier, which
only the site that started the sign-in has, and the site accepts the code only with its one-time
state from the administrator's own session. A state signed for Google is refused at the Dropbox
callback and the other way round.

Backups never pass through the relay: sites upload to and download from Google or Dropbox directly.

## Requirements

* PHP 7.4 or later with the curl extension (for Google's token requests; Dropbox alone needs no
  curl), and OpenSSL for HTTPS, as usual.
* HTTPS with a valid certificate. Google and Dropbox refuse plain-HTTP redirect URIs.
* Its own (sub)domain is recommended, for example `auth.xsofty.com`.

## Install

1. Upload the `relay` folder outside any existing web root, for example to `/srv/siteferry-relay/`:
   ```
   /srv/siteferry-relay/
       config.php        <- you create this (step 3); never inside public/
       config.sample.php
       public/           <- the web root of auth.xsofty.com
           index.php
           .htaccess
       src/
           class-relay.php
   ```
2. Point the domain's web root at `public/` (examples below). Requests to `/siteferry/...` must reach
   `public/index.php`.
3. Copy `config.sample.php` to `config.php`, fill it in **on the server** and make it readable only by
   the web server's user (`chmod 600`, owned by that user):
   * `base_url`: `https://auth.xsofty.com/siteferry` (no trailing slash).
   * `signing_key`: at least 32 random characters, e.g. from
     `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
   * Google Drive (leave both empty to offer only Dropbox): `google_client_id`,
     `google_client_secret` of SiteFerry's OAuth client of type "Web application" in the Google
     Cloud console, with the authorized redirect URI
     `https://auth.xsofty.com/siteferry/google/callback` (exactly `base_url` + `/google/callback`).
   * Dropbox (leave empty to offer only Google Drive): `dropbox_app_key` of SiteFerry's Dropbox app
     (see below).
   Never commit config.php, email it or paste it into a chat. If it has to live elsewhere, set the
   environment variable `SITEFERRY_RELAY_CONFIG` to its path.
4. Open `https://auth.xsofty.com/siteferry/`: it must answer `SiteFerry relay`.
5. On a test site, choose "Sign in with Google" or "Sign in with Dropbox" on SiteFerry's Storage tab.

### SiteFerry's Dropbox app

1. In the [Dropbox App Console](https://www.dropbox.com/developers/apps), click **Create app**:
   **Scoped access**, **App folder**, name **SiteFerry** (the folder every user gets is
   Dropbox → Apps → SiteFerry; the plugin's texts say so).
2. **Permissions** tab: tick `files.metadata.read`, `files.content.read` and `files.content.write`
   (`account_info.read` is always on), then **Submit**.
3. **Settings** tab, OAuth 2, **Redirect URIs**: add `https://auth.xsofty.com/siteferry/dropbox/callback`
   (exactly `base_url` + `/dropbox/callback`). "Allow public clients (Implicit Grant & PKCE)" must
   stay allowed.
4. Copy the **App key** into `dropbox_app_key`. The app secret is not used anywhere; don't put it
   on the server.
5. A new app is in "Development" status and works for up to 500 users; apply for production on the
   Settings tab before that.

### Apache

`public/.htaccess` already sends every request to `index.php` (needs `mod_rewrite`; `AllowOverride
All` or at least `FileInfo Options` for that folder) and sets HSTS if `mod_headers` is on.

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name auth.xsofty.com;
    root /srv/siteferry-relay/public;

    # Every request goes to the one front controller; nothing else is served.
    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php-fpm.sock;   # your PHP-FPM socket
    }

    add_header Strict-Transport-Security "max-age=31536000" always;
    access_log off;   # optional: the relay itself keeps no logs
}
```

## Logs and privacy

The relay writes nothing. The web server's access log, if enabled, records request lines; for the
`start` and `callback` routes those contain the site's admin address, the one-time state and code
(useless without the site's PKCE verifier and expired within minutes). Google tokens travel only in
POST bodies and answers, which access logs do not record; Dropbox tokens never reach the relay.
Turn the access log off, or keep it short, to match the privacy policy.

## Changing the secret

Create a new secret for the Google OAuth client in the Google Cloud console, put it in config.php,
then delete the old one there. Connected sites keep working: their refresh tokens belong to the
client, not to the secret. Dropbox has no secret to change. Changing `signing_key` only cancels
sign-ins started in the last 10 minutes.

## Running somewhere else

A site can use a different relay by defining `SITEFERRY_RELAY_URL` (https) in wp-config.php, or with
the `siteferry_relay_url` filter. Site owners who want no relay at all choose "Use my own OAuth
client" or "Use my own Dropbox app" on the Storage tab.

## Tests

`tests/test-relay.php` (unit tests with a fake Google transport), `tests/test-dropbox.php` (the
Dropbox driver through this relay, in process) and `tests/wp-storage-check.ps1 -Driver gdrive-relay`
or `-Driver dropbox-relay` (a WordPress site, this relay under `php -S` and a fake Google or
Dropbox, end to end).
