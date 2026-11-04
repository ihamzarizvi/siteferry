=== SiteFerry ===
Tags: backup, migration, restore, tar
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up a whole WordPress site into one TAR file and restore or migrate it to another site.

== Description ==

SiteFerry exports the database and wp-content into a single standard TAR archive (optionally
gzip-compressed) and imports it on another site, replacing the site address where needed.

Work in progress. This version exports and imports sites from the admin screen and from WP-CLI,
and sends backups to S3-compatible storage, FTP/FTPS servers and Google Drive. Dropbox follows.

= Admin screen =

The SiteFerry menu (administrators only) has these tabs:

* Export - choose what to leave out (media, plugins, themes, inactive plugins and themes, spam
  comments, revisions, single tables) and whether to compress.
* Import - upload a backup or pick a stored one, set the new site address, check the backup, then
  confirm. Uploads are sent in chunks with a SHA-256 checksum each; an interrupted upload continues
  where it stopped when you choose the same file again.
* Backups - download (through an authenticated PHP stream with Range support), import, send to
  storage or delete; below, the backups on each storage, which can be downloaded to this server or
  deleted there.
* Storage - settings for remote storage, with a connection test.
* Log - status and log of the last jobs.

Exports and imports run as a series of short requests from the open page. Closing the page pauses the
job; opening SiteFerry again offers to resume or cancel it. Only one job runs at a time.

= WP-CLI =

* `wp siteferry export [--compress] [--exclude=media,plugins,themes,inactive-plugins,inactive-themes,spam-comments,revisions] [--include-cache] [--exclude-tables=...] [--exclude-paths=...] [--dir=...] [--porcelain]`
* `wp siteferry import <file> [--replace-url=<url>] [--replace="old=>new;..."] [--include-mu-plugins] [--include-drop-ins] [--force] [--yes]`
* `wp siteferry backups list [--dir=...] [--format=table|csv|json|yaml]`
* `wp siteferry export --send=<id> [--delete-after-send]`
* `wp siteferry storage test|list <id>`, `wp siteferry storage send <id> <backup>`,
  `wp siteferry storage fetch <id> <name>`, `wp siteferry storage delete <id> <name> [--yes]`
  (storage id: s3, ftp or gdrive; settings come from the Storage tab, where Google Drive is also
  connected)

= S3-compatible storage =

Presets for Amazon S3, Backblaze B2, Wasabi, Cloudflare R2 and DigitalOcean Spaces; any other
service that speaks the S3 API (MinIO, ...) works with its endpoint. Requests are signed with AWS
Signature Version 4 (no SDK) and carry the SHA-256 of their content, which the service checks.
Backups are stored as `<folder>/<backup name>` in the bucket.

* Uploads use multipart upload in parts of 8 MB (larger for files over 78 GB), one or a few parts
  per request, so the PHP time limit does not matter. Temporary errors (network, throttling, server
  errors) are retried with growing pauses. Cancelling or a failure removes the unfinished upload.
  A job that is simply abandoned can leave one behind: a bucket lifecycle rule that aborts
  incomplete multipart uploads after a few days cleans those up.
* Downloads to this server use range requests of 8 MB and are checked to be SiteFerry backups.
* "Test connection" writes, reads, lists and deletes a small file in the folder.
* On Amazon S3, a key limited to the bucket and folder needs only this (replace BUCKET; the folder
  here is the default "siteferry"):
  `{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":"s3:ListBucket","Resource":"arn:aws:s3:::BUCKET","Condition":{"StringLike":{"s3:prefix":"siteferry/*"}}},{"Effect":"Allow","Action":["s3:PutObject","s3:GetObject","s3:DeleteObject","s3:AbortMultipartUpload"],"Resource":"arn:aws:s3:::BUCKET/siteferry/*"}]}`

= FTP and FTPS =

Uses PHP's ftp extension. Explicit FTPS (AUTH TLS, with the data connections encrypted as well) is
the default; plain FTP can be chosen for servers without TLS, and the connection test then warns
that the password and backups travel unencrypted. Implicit FTPS (port 990) and SFTP are not
supported.

* PHP's ftp extension does not check the server's TLS certificate: FTPS protects against
  eavesdropping, not against someone who can redirect the connection.
* Passive mode by default. Data connections always go to the server SiteFerry logged in to, never
  to another address the server names in its passive-mode answer.
* Uploads are written to `<name>.part` in parts of 8 MB, each at its offset (REST + STOR, so an
  interrupted part is simply written again), and renamed when complete. The server must support
  resuming uploads, which common servers do; "Test connection" checks it. Cancelling or a failure
  deletes the `.part` file.
* Downloads to this server read 8 MB ranges (REST + RETR).
* Temporary failures (a dropped connection) are retried with growing pauses and a new connection;
  refusals by the server (permissions, quota) are reported at once.
* The folder is created if missing. Keep it outside the server's public web folder.

= Google Drive =

Two ways to sign in, chosen on the Storage tab:

* "Sign in with Google" (the default): click the button and allow access on Google's page. No
  Google Cloud project or keys are needed; SiteFerry's own Google app is used, through SiteFerry's
  sign-in service (see "Sign-in service" below).
* "Use my own OAuth client" (advanced, no sign-in service involved): in the Google Cloud console,
  enable the Google Drive API, set up the consent screen, create an OAuth client of type "Web
  application" and register the redirect URI that the Storage tab shows; enter its client ID and
  secret. If that consent screen is left in "Testing", Google ends the authorization after 7 days;
  publish the app to keep it (with only the drive.file scope Google usually asks for no review).

SiteFerry asks only for the `drive.file` scope: it can see and change only the files and folders it
created itself, nothing else in the Drive. Backups go into one folder at the top of My Drive
("SiteFerry" by default).

* Connecting: the sign-in button (a POST with the administrator's nonce) creates a one-time
  `state`, tied to that administrator, and a PKCE code verifier, both kept for 10 minutes. Google
  (through the sign-in service, in the default way) sends the browser back to the SiteFerry page
  with a code; that page then hands code and state to the REST API with the nonce. Only that
  request, after the state matches, exchanges the code and saves the tokens. Nothing is saved from
  the GET request itself, and the code is removed from the address bar at once.
* The refresh token and access token are stored encrypted; the access token is refreshed when it
  is about to expire (or when Google refuses it) and the new one saved. "Disconnect" revokes the
  tokens at Google. Switching between the two ways of signing in, or changing the client ID, drops
  the connection.
* Uploads use resumable uploads in parts of 8 MB; after an interruption Drive is asked how much it
  has and the upload continues from there. Sending a backup again replaces the earlier copy.
  Deleting a backup moves it to the Drive trash. Downloads use range requests.
* The access token is only ever sent to Google's API hosts.

= Sign-in service (auth.xsofty.com) =

"Sign in with Google" for Google Drive uses SiteFerry's sign-in service at
`https://auth.xsofty.com/siteferry`, run by the publisher of SiteFerry. It holds the secret of
SiteFerry's Google app, which a plugin cannot keep secret. SiteFerry contacts it only for that
storage, only in that mode, and only for these steps:

* Signing in: your browser goes to the service with this site's SiteFerry page address, a random
  state and a PKCE challenge, and on to Google; Google sends the browser back through the service,
  which passes the one-time code to this site's page.
* Getting and renewing access: this site sends the service the one-time code with the PKCE verifier
  (once), and later the refresh token whenever the access token needs renewing (about once an hour
  while SiteFerry works with the Drive). The service adds the app's client ID and secret, sends the
  request to Google and returns Google's answer.

The service stores nothing and keeps no logs of its own. Backups never go through it: they travel
directly between this site and Google. If the service cannot be reached, signing in and renewing
access fail with a message saying so; the rest of SiteFerry is not affected. To avoid the service
completely, choose "Use my own OAuth client". To use a sign-in service of your own instead, define
`SITEFERRY_RELAY_URL` (an https address) in wp-config.php.

= Stored keys =

Secret keys, passwords and OAuth tokens are encrypted before they are saved (libsodium secretbox, or OpenSSL
AES-256-GCM if libsodium is missing) with a key derived from the site's WordPress salts. They are
never shown again or sent to the browser, never logged, left out of every export, kept when a
backup is imported into this site, and removed when SiteFerry is deleted. If the salts change,
enter them again. To use a key that survives changed salts, define `SITEFERRY_ENCRYPTION_KEY` (at
least 32 random characters) in wp-config.php before saving the settings.

= How an import works =

1. The backup's manifest, WordPress and PHP versions and free disk space are checked, and you confirm.
2. Files are unpacked into a staging folder and checked against the backup's SHA-256 list.
3. The database is loaded into temporary tables while the site keeps running. Only the statements a
   SiteFerry dump contains are accepted. Collations the server lacks are mapped to close equivalents.
4. The old address and folder paths are replaced (http/https, www, `//`, JSON and URL-encoded forms;
   serialized data stays valid). GUIDs are not changed.
5. Under maintenance mode, the tables are swapped with one RENAME TABLE and the files are moved into
   place. SiteFerry itself, its settings and its storage folder stay as they are. Must-use plugins
   and server-specific drop-ins (object-cache.php, advanced-cache.php, db.php) are only copied on request.
6. Caches and rewrite rules are flushed; the old tables and files are removed.

If anything fails after step 5 starts, the previous tables and files are put back. After an import,
log in with the imported site's users.

Left out of every export: cache folders (unless `--include-cache`), WordPress's upgrade folders,
other backup plugins' folders, `.git`, `node_modules`, SiteFerry itself, its storage folder and its
settings (including storage credentials).

= Where backups are stored =

In `wp-content/siteferry-<random>/backups/`, with `.htaccess`, `web.config` and `index.php` deny
files. Web servers that ignore those files (nginx, including LocalWP) will serve a backup to anyone
who knows its exact URL; the random folder and file names make that URL unguessable. To block it on
nginx as well, add a rule such as `location ~ /wp-content/siteferry-[a-f0-9]+/ { deny all; }`.

= Backup format =

A SiteFerry backup is a plain POSIX TAR file (ustar with pax extended headers) that any TAR tool can
open. It contains:

* `siteferry.json` - the manifest (site address, versions, export options).
* `database.sql` - the database dump.
* `wp-content/...` - the site files.
* `siteferry.sha256` - SHA-256 checksums of every file above except the manifest, in `sha256sum` format.

Compressed backups (`.tar.gz`) are a series of independent gzip members of about 8 MB each, so they
are valid gzip files and an interrupted job can resume at a member boundary.

== Changelog ==

= 0.7.0 =
* One-click "Sign in with Google" for Google Drive through SiteFerry's sign-in service (auth.xsofty.com); no Google Cloud project needed. Your own OAuth client stays available.
* Google Drive storage with your own OAuth client: drive.file scope only, one-time state and PKCE, tokens saved only from an authenticated POST, encrypted tokens with refresh, revoke on disconnect, resumable uploads, ranged downloads.
* Only storages that are ready (and, for Google Drive, connected) are offered for sending.
* A refusal on the provider's consent page is shown even though WordPress removes "error" from the address bar.

= 0.6.0 =
* FTP/FTPS storage: explicit FTPS by default, passive mode, uploads in parts with resume, ranged downloads, connection test that also checks resume support.
* The Storage tab forms work the same way for every storage.

= 0.5.0 =
* S3-compatible storage: settings with provider presets, connection test, send after an export or later, list, download to this server, delete.
* Multipart uploads in slices with retries and backoff; cancelled uploads are aborted on the storage.
* Storage keys encrypted at rest (libsodium, OpenSSL fallback) and never sent back to the browser.
* WP-CLI: `wp siteferry storage ...`, `wp siteferry export --send=<id>`.
* A second copy of a backup keeps its site and date in the file name.

= 0.4.0 =
* Admin screen: export, import with inspection and confirmation, backups list with download and delete, job log.
* REST API (`siteferry/v1`) for the admin screen: administrator nonce for site actions, a per-job token for driving a job.
* Chunked, resumable uploads with per-chunk SHA-256; downloads with Range support.
* Jobs can be cancelled; their cleanups run in reverse order.

= 0.3.0 =
* Import: staging with checksum verification, temporary tables, serialization-safe search-replace, atomic table swap, journalled file moves, automatic rollback.
* WP-CLI: `wp siteferry import`.

= 0.2.0 =
* Export: resumable wp-content scan with exclusions, batched database dump, archive with manifest and checksums.
* Job runner with file-based state, per-job tokens and slice lock.
* WP-CLI: `wp siteferry export`, `wp siteferry backups list`; works with `--require` on sites without SiteFerry.

= 0.1.0 =
* Plugin skeleton and streaming TAR reader/writer.
