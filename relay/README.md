# SiteFerry sign-in relay

A small PHP service that makes "Sign in with Google" work in SiteFerry without each site owner
creating a Google Cloud project. It runs on the publisher's server (planned:
`https://auth.xsofty.com/siteferry`) and holds the secret of SiteFerry's Google OAuth client, which a
plugin installed on thousands of sites cannot keep secret.

It is not part of the plugin package (`.distignore` leaves `relay/` out).

## What it does

| Request | From | What happens |
| --- | --- | --- |
| `GET /siteferry/` | anyone | Health check: `200 SiteFerry relay` (`503` while config.php is missing or incomplete). |
| `GET /siteferry/google/start?return=…&state=…&challenge=…` | the administrator's browser | Checks that `return` is a SiteFerry admin page (`…/wp-admin/admin.php?page=siteferry&oauth=<id>`), signs `return` and the site's `state` into its own state (valid 10 minutes), redirects to Google's consent page with the PKCE challenge. |
| `GET /siteferry/google/callback?code=…&state=…` | Google, through the browser | Checks the signature and the time, redirects the browser to the site's page with the code and the site's own state (or Google's error). |
| `POST /siteferry/google/token` | the WordPress site | Adds the client ID, secret and redirect URI to the site's code + PKCE verifier (first sign-in) or refresh token (renewals, about once an hour while a site works with its Drive), forwards it to Google and returns Google's answer unchanged. |

Everything else is `404`. It stores nothing, writes no log of its own, sets no cookies and answers
with `Cache-Control: no-store`. A stolen code is useless without the PKCE verifier, which only the
site that started the sign-in has, and the site accepts the code only with its one-time state from
the administrator's own session.

Backups never pass through the relay: sites upload to and download from Google directly.

## Requirements

* PHP 7.4 or later with the curl extension (and OpenSSL for HTTPS, as usual).
* HTTPS with a valid certificate. Google refuses plain-HTTP redirect URIs.
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
   * `google_client_id`, `google_client_secret`: SiteFerry's OAuth client of type "Web application"
     in the Google Cloud console, with the authorized redirect URI
     `https://auth.xsofty.com/siteferry/google/callback` (exactly `base_url` + `/google/callback`).
   Never commit config.php, email it or paste it into a chat. If it has to live elsewhere, set the
   environment variable `SITEFERRY_RELAY_CONFIG` to its path.
4. Open `https://auth.xsofty.com/siteferry/`: it must answer `SiteFerry relay`.
5. On a test site, choose "Sign in with Google" on SiteFerry's Storage tab.

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

The relay writes nothing. The web server's access log, if enabled, records request lines; for
`/google/start` and `/google/callback` those contain the site's admin address, the one-time state and
code (useless without the site's PKCE verifier and expired within minutes). Tokens travel only in POST
bodies and answers, which access logs do not record. Turn the access log off, or keep it short, to
match the privacy policy.

## Changing the secret

Create a new secret for the OAuth client in the Google Cloud console, put it in config.php, then
delete the old one there. Connected sites keep working: their refresh tokens belong to the client, not
to the secret. Changing `signing_key` only cancels sign-ins started in the last 10 minutes.

## Running somewhere else

A site can use a different relay by defining `SITEFERRY_RELAY_URL` (https) in wp-config.php, or with
the `siteferry_relay_url` filter. Site owners who want no relay at all choose "Use my own OAuth
client" on the Storage tab.

## Tests

`tests/test-relay.php` (unit tests with a fake Google transport) and
`tests/wp-storage-check.ps1 -Driver gdrive-relay` (a WordPress site, this relay under `php -S` and a
fake Google, end to end).
