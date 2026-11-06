=== SiteFerry ===
Contributors: hamzarizvi
Tags: backup, migration, restore, multisite, cloud backup
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.23.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up a whole WordPress site into one TAR file and restore or migrate it to another site.

== Description ==

SiteFerry exports the database and wp-content into a single standard TAR archive (optionally
gzip-compressed) and imports it on another site, replacing the site address where needed. It also
imports and writes .wpress files, the backup format of the All-in-One WP Migration plugin (see
".wpress backups" below).

Work in progress. This version exports and imports sites from the admin screen and from WP-CLI,
makes backups on a schedule, and sends backups to S3-compatible storage, FTP/FTPS servers, Google
Drive and Dropbox.

= Admin screen =

The SiteFerry menu (administrators only) has these tabs:

* Backups - "Back up now" backs up the whole site to this server in one click. "Options" holds the
  rest: a name, note and tags for the backup, what to leave out (media, plugins, themes, inactive
  plugins and themes, spam comments, revisions, single tables), the file (a SiteFerry .tar,
  optionally compressed, or a .wpress file), where it goes (this server or a storage that is set
  up), and downloading it to your computer when it is ready. "Upload a backup" adds a SiteFerry
  .tar/.tar.gz or a .wpress file to the list; uploads are sent in chunks with a SHA-256 checksum
  each, and an interrupted upload continues when you choose the same file again. "Schedule" sets
  up automatic backups (see "Scheduled backups" below); a line under the buttons shows the
  schedule, the next run and how the last one went.
  Below, one list of every backup on this server and on each storage, newest first, with where
  each copy is, its name, note and tags, a search box and a tag filter. Each backup's menu
  downloads it (through an authenticated PHP stream with Range support), sends it to a storage,
  edits its name, note and tags (the file name stays), checks a copy (see "Checking a copy"
  below), or deletes a copy.
  "Restore" (a backup of this site) or "Move here" (a backup of another site) opens a panel that
  checks the backup first (versions, disk space, files) without changing anything, says what will
  happen, and only then offers to go ahead. A backup that is only on a storage is downloaded to
  this server first. The site address, must-use plugins and drop-ins are under "Advanced".
  "Make a safety backup of this site first" (ticked by default) backs up the whole site as it is
  before anything changes; after the restore, the Backups tab offers "Undo this restore", which
  restores that safety backup (see "Safety backup and Undo" below).
  "Look inside" in a backup's menu lists its folders, files and database tables, downloads single
  files from it, and restores only the parts you tick: the database or single tables, all files, a
  folder of wp-content, or one plugin or theme. "Advanced" in the restore panel also offers "Only
  the database" and "Only the files".
* Storage - one card per storage with its state; open it for the settings and a connection test.
* Activity - status and log of the last jobs.

Names, notes and tags are kept by file name, so they apply to every copy of a backup. Those given
when a backup is made are also stored in its manifest and travel with it.

The header shows the site, its backups and the storages set up. While a job runs, its steps are
drawn as the legs of a route, with the current step and how far it is.

Exports and imports run as a series of short requests from the open page. Closing the page pauses the
job; opening SiteFerry again offers to resume or cancel it. Only one job runs at a time.

= Scheduled backups =

"Schedule" on the Backups tab: every day, every week (on a chosen day) or every month (on a day
from 1 to 28), at a time in the site's time zone (Settings, General). The same choices as a backup
made by hand: what to leave out, the file, and where it goes (this server, or a storage that is
set up, optionally keeping a copy on this server too). Then how many scheduled backups to keep,
and when to send an email.

* WP-Cron starts the backup. WordPress runs its scheduled tasks when the site gets a visit, so on a
  quiet site a backup can start a little late; with `DISABLE_WP_CRON`, a server cron job must run
  wp-cron.php (or `wp cron event run --due-now`), and the Schedule panel says so.
* Nobody has to be logged in or keep a page open. The backup runs in short steps: each step ends
  by asking the site itself (a non-blocking request to its own REST API, authenticated with that
  job's one-time token) for the next one. Where a host blocks such requests, a check every five
  minutes carries the job on, and stops one that has made no progress for an hour.
* While another backup, restore or transfer runs, a scheduled backup waits and tries again every
  15 minutes; after two hours it is skipped (and reported).
* Scheduled backups get the tag "scheduled". After a new one succeeds, the scheduled backups
  beyond the number to keep are deleted everywhere they are stored (this server and the storage).
  Only backups the schedule made are ever deleted, never ones you made yourself; 0 keeps them all.
* Email (with wp_mail): when a backup fails (the default), after every backup, or never; to the
  site's admin email unless another address is given. A backup that you stop yourself sends none.
* "Run it now" starts the scheduled backup at once, in the background; the page shows its progress
  and can stop it, and leaving the page does not stop it.
* An import replaces the cron table along with the database; the next scheduled run is put back
  the next time an administrator opens the dashboard. Deactivating SiteFerry removes its scheduled
  events (the settings stay); deleting it removes the settings too.

= Backups before updates =

"Back up before WordPress, a plugin or a theme updates" (in the Schedule panel, independent of the
schedule itself; off by default). Just before WordPress downloads an update (the filter
upgrader_pre_download, when nothing has changed yet), SiteFerry backs up the database and the plugin
or theme being updated; for an update of WordPress itself, the database only. Media, other plugins
and themes are left out, so it is quick. It stays on this server, named "Before updating <name>
<version>" with the tag "before-update".

* Restoring it puts back the database and that plugin or theme as they were. A restore replaces
  only what a backup contains, so everything else on the site stays as it is. (The database goes
  back as a whole, including anything changed since.)
* It runs in the update's own request: when the time limit can be raised it may take two minutes,
  otherwise half of the limit. If it cannot be finished in time, or another backup, restore or
  transfer is running, it is skipped and the update goes ahead. An update is never blocked.
* One backup per plugin, theme or WordPress update; translations and new installs get none. Only
  the newest few (3 by default) are kept; older ones are deleted after a new one is made.
* The Schedule panel shows how the last one went.

= WP-CLI =

* `wp siteferry export [--format=tar|wpress] [--compress] [--exclude=media,plugins,themes,inactive-plugins,inactive-themes,spam-comments,revisions] [--include-cache] [--exclude-tables=...] [--exclude-paths=...] [--dir=...] [--porcelain]`
* `wp siteferry import <file> [--replace-url=<url>] [--replace="old=>new;..."] [--include-mu-plugins] [--include-drop-ins] [--force] [--skip-safety-backup] [--only-database|--only-files] [--tables=...] [--paths=...] [--yes]`
* `wp siteferry backups list [--dir=...] [--format=table|csv|json|yaml]`
* `wp siteferry backups check <name> [--storage=<id>] [--password]`
* `wp siteferry space [--fresh] [--format=table|json]`
* `wp siteferry receive [--hours=<n>] [--status] [--cancel]`, `wp siteferry move <key> [--keep-local]`
* `wp siteferry export --password`, `wp siteferry import <file>.tar.enc --password`
* `wp siteferry export --send=<id> [--delete-after-send]`
* `wp siteferry storage test|list <id>`, `wp siteferry storage send <id> <backup>`,
  `wp siteferry storage fetch <id> <name>`, `wp siteferry storage delete <id> <name> [--yes]`
  (storage id: s3, ftp, gdrive or dropbox; settings come from the Storage tab, where Google Drive
  and Dropbox are also connected)
* `wp siteferry schedule show`, `wp siteferry schedule run [--quiet]` (the scheduled backup now,
  in this process, with its retention and email; the schedule itself is set on the Backups tab)

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

= Dropbox =

Two ways to sign in, chosen on the Storage tab; neither needs a secret, because Dropbox lets an app
sign in with PKCE and its public app key alone:

* "Sign in with Dropbox" (the default): click the button and allow access on Dropbox's page.
  SiteFerry's own Dropbox app is used; your browser passes through SiteFerry's sign-in service on
  the way (see "Sign-in service" below), but the access itself never does.
* "Use my own Dropbox app" (advanced, no sign-in service involved): in the Dropbox App Console,
  create an app with "Scoped access" and "App folder" access; on its Permissions tab tick
  files.metadata.read, files.content.read and files.content.write; on its Settings tab register the
  redirect URI that the Storage tab shows (Dropbox accepts http only for localhost). Enter its app
  key; no app secret is needed.

With "App folder" access SiteFerry sees only its own folder in the Dropbox (Apps, then the app's
name), nothing else. Backups go into that folder, or into a folder inside it named on the Storage
tab (for example one per site, when several sites share a Dropbox).

* Connecting works as for Google Drive: a one-time `state` tied to the administrator and a PKCE
  code verifier, kept for 10 minutes; the code that comes back is exchanged only by an
  authenticated POST with the nonce, after the state matches. SiteFerry asks for the permissions
  account_info.read (the account's email address, shown on the Storage tab), files.metadata.read,
  files.content.read and files.content.write, and checks that they were granted.
* The refresh token and access token are stored encrypted; the access token (valid for four hours)
  is renewed when it is about to expire, or when Dropbox refuses it, and the new one saved.
  "Disconnect" revokes the access at Dropbox. Switching between the two ways of signing in, or
  changing the app key, drops the connection.
* Uploads use upload sessions in parts of 8 MB; after an interruption Dropbox says how much it has
  and the upload continues from there. Sending a backup again replaces the earlier copy. Cancelling
  leaves nothing in the folder (an unfinished upload session never becomes a file, and Dropbox
  discards it). Downloads use range requests. Deleted backups can be restored from Dropbox's deleted
  files for a while, depending on the Dropbox plan.
* The access token is only ever sent to Dropbox's API hosts.

= Sign-in service (auth.xsofty.com) =

"Sign in with Google" and "Sign in with Dropbox" use SiteFerry's sign-in service at
`https://auth.xsofty.com/siteferry`, run by the publisher of SiteFerry. Google and Dropbox only send
the browser back to the one address registered for SiteFerry's apps, which is the service's; for
Google, the service also holds the secret of SiteFerry's Google app, which a plugin cannot keep
secret. SiteFerry contacts it only for those two storages, only in those modes, and only for these
steps:

* Signing in: your browser goes to the service with this site's SiteFerry page address, a random
  state and a PKCE challenge, and on to Google or Dropbox, which sends the browser back through the
  service; the service passes the one-time code to this site's page. The code is useless without
  the PKCE verifier, which stays on this site.
* Google Drive, getting and renewing access: this site sends the service the one-time code with the
  PKCE verifier (once), and later the refresh token whenever the access token needs renewing (about
  once an hour while SiteFerry works with the Drive). The service adds the app's client ID and
  secret, sends the request to Google and returns Google's answer.
* Dropbox, connecting: this site asks the service once for the app key and redirect address of
  SiteFerry's Dropbox app (both public). The code exchange and every renewal then go straight from
  this site to Dropbox; no token ever passes through the service.

The service stores nothing and keeps no logs of its own. Backups never go through it: they travel
directly between this site and Google or Dropbox. If the service cannot be reached, signing in (and,
for Google Drive, renewing access) fails with a message saying so; the rest of SiteFerry is not
affected. To avoid the service completely, use your own OAuth client or Dropbox app. To use a
sign-in service of your own instead, define `SITEFERRY_RELAY_URL` (an https address) in
wp-config.php.

= Stored keys =

Secret keys, passwords and OAuth tokens are encrypted before they are saved (libsodium secretbox, or OpenSSL
AES-256-GCM if libsodium is missing) with a key derived from the site's WordPress salts. They are
never shown again or sent to the browser, never logged, left out of every export, kept when a
backup is imported into this site, and removed when SiteFerry is deleted. If the salts change,
enter them again. To use a key that survives changed salts, define `SITEFERRY_ENCRYPTION_KEY` (at
least 32 random characters) in wp-config.php before saving the settings.

= How an import works =

1. The backup's manifest, WordPress and PHP versions and free disk space are checked, and you confirm.
   Then, unless you untick it, a safety backup of the whole site as it is now is made.
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

= Password-protected backups =

"Protect the backup with a password" in Options (and, for automatic backups, in the Schedule
panel) encrypts the whole backup with AES-256-GCM as it is written; the file name ends in .enc. The
key comes from the password (PBKDF2-HMAC-SHA256, 600,000 rounds, and HKDF); every 2 MB chunk is
authenticated, so a changed, reordered or cut-off file is refused. Restoring, checking or looking
inside asks for the password; a wrong one is refused before anything changes. The password of a
backup made by hand is not kept anywhere. The password of automatic backups is kept on the site,
encrypted with a key from the WordPress salts. Safety backups made before a restore are not
encrypted (they stay on this server). Needs the PHP OpenSSL extension.

= Multisite networks =

On a network, SiteFerry is for super admins, on the main site, and backs up the whole network:
every site's tables, the network tables and all of wp-content. Such a backup restores into a
network of the same kind (subfolders or subdomains); every subsite is put under the new network's
domain and path, and subsites made after the backup are removed with their tables. A single site
cannot be restored into a network (or the other way round) yet, nor a part of a network.

= Moving a site straight to another server =

"Move a site" on the new site makes a one-time key (24 hours). Pasted under "Move a site" on the old site, it
shows the new site and then moves the old one there: a fresh backup, encrypted with the key's
256-bit secret (AES-256-GCM), sent straight from server to server in chunks with their SHA-256;
an interrupted transfer continues where it stopped. The routes that receive it answer only to the
key, which works once and is dropped after 20 wrong tries. On the new site the backup waits in the
list; "Move it here" restores it after the usual check and confirmation, with the key's secret as
its password (kept, encrypted, until the backup is deleted). Only the address in the key is
contacted.

= Checking a copy =

"Check the copy on ..." in a backup's menu reads that copy from start to end without unpacking
anything. Every file is compared with the backup's own SHA-256 checksum list; the manifest must be
there, the database dump complete and of the size the manifest gives, and entries an import would
refuse count as damage. A copy on a storage is downloaded into SiteFerry's protected folder first
and deleted afterwards. The outcome shows on the copy's place badge (a tick, or a warning) and
stays until the copy is deleted. A .wpress file has no checksums: its check only makes sure it
reads to its end.

= Safety backup and Undo =

Rolling back on failure protects against a restore that breaks halfway; the safety backup protects
against one that works but was the wrong choice.

* After you confirm a restore (or a move), SiteFerry first backs up this whole site as it is, as
  an ordinary backup named "Before restoring ..." with the tag "before-restore". It takes about as
  long as a normal backup and needs room for one. Untick "Make a safety backup of this site first"
  to skip it (WP-CLI: `--skip-safety-backup`).
* If the safety backup fails, the restore stops before anything changes.
* If the restore then fails and the site is left as it was, or is put back completely, the safety
  backup is not needed and is removed. If putting the site back does not fully succeed, it is kept.
* After a restore, the Backups tab offers "Undo this restore": it opens the restore panel for the
  safety backup, checked like any other restore. "Keep it as it is" hides the offer; the safety
  backup stays in the list until you delete it. Safety backups are never deleted automatically.

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

= .wpress backups =

.wpress is the backup format of the All-in-One WP Migration plugin. SiteFerry reads and writes it
from the format's layout, for moving sites between the two plugins; it is not affiliated with that
plugin or its makers. All-in-One WP Migration is a trademark of its owner.

Importing a .wpress backup (Import tab, or `wp siteferry import site.wpress`) runs the usual import
with one more step first:

* Every file's CRC-32, stored in the archive, is checked as it is unpacked; a damaged backup stops
  the import before the site is touched. (.wpress files have no SHA-256 list.)
* The database dump is rewritten as a SiteFerry dump: the format's placeholder table prefix goes
  back to the source site's prefix, column lists are added, and the theme and active plugins, which
  the dump leaves empty and package.json holds, are filled in. Only DROP TABLE, CREATE TABLE and
  INSERT statements are accepted, as for SiteFerry's own dumps.
* Addresses and paths come from the backup's package.json; the import then replaces them as usual.
* Left out: other SiteFerry storage folders inside the backup, All-in-One WP Migration's own backup
  folder, and SiteFerry's plugin folder (this site keeps its own SiteFerry).
* Not supported: password-protected .wpress backups (listed, but they cannot be imported yet),
  multisite backups, and backups made with "Exclude database". The server needs room for the backup,
  the unpacked files and the database twice, as with any import.

Exporting as .wpress (Export tab: File, or `wp siteferry export --format=wpress`) makes a normal
SiteFerry backup first and then converts it: package.json first, then the files, then database.sql,
with each file's CRC-32 and the archive's CRC-32 in their headers. In the dump, the table prefix is
replaced by the format's placeholder in table names, in the user_roles option and at the start of
user-meta keys, so the importing plugin can use its own prefix; option names that start with "wp_"
whatever the prefix (such as wp_page_for_privacy_policy) stay as they are. Compression does not
apply to .wpress files. All-in-One WP Migration's free version imports such a file with "Import
from file", within the host's upload limit.

Tested with backups made by All-in-One WP Migration 7.113, and by importing SiteFerry's .wpress files
with that version.

== External services ==

SiteFerry works on your server and contacts other services only when you set one up or ask it to.
Nothing is sent in the background, there is no telemetry, and no service is contacted on a site
where none is set up.

**Storage you set up (S3-compatible, FTP/FTPS).** When you save the settings of an S3-compatible
storage (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces or another endpoint you
enter) or of an FTP/FTPS server, SiteFerry connects to that endpoint or server: to test the
connection, to list, send, download and delete backups when you ask (or when a schedule you set
runs), and to check a stored copy. It sends the backup files and the requests the service needs,
signed with your keys. Which service that is, and its terms, depend on your choice; for Amazon S3:
[terms](https://aws.amazon.com/service-terms/), [privacy](https://aws.amazon.com/privacy/).

**Google Drive.** When you connect Google Drive, SiteFerry uses Google's sign-in
(accounts.google.com, oauth2.googleapis.com) and the Drive API (www.googleapis.com) with the
drive.file scope: it uploads, lists, downloads and deletes the backup files it made, in the folder
you choose, when you ask or a schedule you set runs. Google's
[terms of service](https://policies.google.com/terms) and
[privacy policy](https://policies.google.com/privacy).

**Dropbox.** When you connect Dropbox, SiteFerry uses Dropbox's sign-in (www.dropbox.com,
api.dropboxapi.com) and its API (api.dropboxapi.com, content.dropboxapi.com) with access to its app
folder only: it uploads, lists, downloads and deletes backup files there when you ask or a schedule
you set runs. Dropbox's [terms of service](https://www.dropbox.com/terms) and
[privacy policy](https://www.dropbox.com/privacy).

**Sign-in service (auth.xsofty.com).** Only if you choose one-click "Sign in with Google" or "Sign in
with Dropbox" (instead of your own OAuth client or Dropbox app), your browser passes through
`https://auth.xsofty.com/siteferry`, run by the publisher of SiteFerry, while signing in, with this
site's SiteFerry page address, a random state and a PKCE challenge. For Google Drive this site
also sends it the one-time code with the PKCE verifier, and later the refresh token when access
needs renewing; the service adds SiteFerry's app credentials and passes the request to Google. For
Dropbox, this site only asks it for the public app key. It stores nothing; backups never go through
it. See "Sign-in service" above for every step. Publisher: [xsofty](https://xsofty.com/).

**Another WordPress site (moving a site).** Only when you paste a move key made on another site and
start the move, SiteFerry sends this site's encrypted backup to the address in that key.

== Changelog ==

= 0.23.1 =
* Storage: saving complete settings tests the connection at once; when it works, the card closes and a message says so. When it fails, the card stays open with the reason.

= 0.23.0 =
* Backups: "Back up now" sits in its own bar with what it will make shown as tags (whole site or what is left out, the file, where it goes) and a "Customize" button. Upload, Schedule (with its On/Off state) and Move a site are one row of matching buttons with icons; every page button has the same height and shape.
* Customize: changes apply to the next backup; "Save as my settings" keeps them for every "Back up now", "Reset to defaults" goes back to the whole site on this server, and a "Not saved" tag shows unsaved changes. The name, note and tags (for one backup only) come last.
* While a backup runs its button says "Backing up..."; the finished card can be closed and makes room when another panel opens; the new backup is marked New in the list.
* Shorter action names: Download, Add a name or note, Edit name and note, Check for damage.
* The page shows at once instead of after the storages have loaded; on phones "Back up now" comes first.

= 0.22.0 =
* Restore progress shows four stages (Check, Safety backup, Restore, Finish) instead of nine steps; the caption still names the step.
* One panel at a time: opening Look inside, a restore or a drawer closes the others (a running restore stays).
* Look inside lists the site's files only; the database dump, the backup's description and its checksums are one line of download links.
* Activity names the backup of each entry, calls a site move "Move to another site" and a check "Check of a backup".
* Storage: a greyed-out "Test connection" says why; Google Drive and Dropbox show "Save" only after signing in (signing in saves the settings); the S3 endpoint and addressing show only when the provider needs them or under "Advanced settings".

= 0.21.0 =
* Header: "Back up now" and "Backup options" sit together as one control; "Move" is now "Move a site"; the second "Set up a schedule" button is gone ("Schedule" does that).
* Move a site: first choose which side this is ("Receive a site here" on the new site, "Send this site to another server" on the old one); each side shows numbered steps and only its own fields. The help under the list explains all three ways to move a site here.
* Schedule: the list of parts is folded behind a one-line summary ("The whole site", "The site without media"); the backup before updates has its own box that says it works on its own; the button is "Save these settings".
* Look inside says which parts can be ticked for restoring.

= 0.20.0 =
* Restore: the text says what this backup really puts back and what stays as it is (for example a backup of the database only, or one made without media); choices a backup or site cannot take are no longer offered ("Only the files" without files, a new site address on a network or without the options table, must-use plugins and drop-ins without files). Look inside shows no tick boxes on a network, which is restored whole.
* Back up now: the choices in Options are remembered for each administrator, and a line under the buttons says what Back up now will make. "Use the defaults" goes back to the whole site on this server. A password, name, note or tags are never remembered.
* Moving a site: "Open SiteFerry there" opens the right page on the other site.
* Two warnings of the restore check are now translatable; the hint to import anyway is shown only in WP-CLI, which has that option.
* Uninstalling also removes the remembered choices.

= 0.19.0 =
* Translation-ready: every message of the admin page (wp.i18n, with script translations), of the server (errors, warnings, progress, connection tests, the check, emails, automatic backup names) uses the "siteferry" text domain, with translator notes; the template is languages/siteferry.pot. WP-CLI output stays English.
* readme: an "External services" section listing every service SiteFerry can contact, when, what it sends, and their terms and privacy policies.

= 0.18.0 =
* Multisite networks: whole-network backups (every site's tables, the network tables, all media) by super admins on the main site; restoring them into a network of the same kind, with every subsite's address mapped to the new network (subdomains keep their first label) and subsites made after the backup removed with their tables. The network stays network-activated for SiteFerry.

= 0.17.0 =
* Move a site straight to another server: "Move" makes a one-time key on the new site; pasted on the old site, it sends an encrypted backup straight there (resumable, checked chunks). "Move it here" on the new site restores it without asking for a password. WP-CLI: `wp siteferry receive` and `wp siteferry move <key>`.
* Backup names, notes and tags are read fresh before each change, so a long-running process cannot undo another request's change.

= 0.16.0 =
* Backups in parts: "Largest file on this storage" in a storage's settings (100 MB to 4 GB) stores larger backups there as numbered parts (.001, .002, ...). The list shows them as one backup ("Dropbox · 3 parts"; a missing part is shown); downloading, checking, restoring and deleting handle all parts, and a cancelled send removes the parts already stored.
* "Download in parts" in a backup's menu; uploading all parts of a backup at once joins them as they upload.

= 0.15.0 =
* Space report: opening Options measures the site (in short steps; kept for an hour) and shows about how big the backup will be with the choices made, before compression, next to the free space on the server, with a warning when it does not fit. "What takes the room" lists the database, media, plugins and themes (and what of them is inactive), cache and the rest, the largest files, media by year, the largest plugins, themes and tables, post revisions and spam comments. WP-CLI: `wp siteferry space`.

= 0.14.0 =
* Password-protected backups: AES-256-GCM encryption of the whole backup as it is written (.tar.enc / .tar.gz.enc), for backups made by hand (Options) and automatic ones (Schedule panel, also before updates). Restore, check and "Look inside" ask for the password; a wrong one is refused before anything changes. The key is derived with PBKDF2 (600,000 rounds); no password of a backup made by hand is kept. WP-CLI: `--password` for export, import and backups check (or the SITEFERRY_PASSWORD environment variable).
* Uploads accept .enc files.

= 0.13.0 =
* Check a copy of a backup: "Check the copy on this server" and "Check the copy on <storage>" in a backup's menu read it from start to end and compare every file with its checksums, without unpacking anything; a copy on a storage is downloaded for the check and deleted again. The place badges show the outcome. WP-CLI: `wp siteferry backups check <name> [--storage=<id>]`.

= 0.12.0 =
* Look inside a backup: "Look inside" in a backup's menu lists its folders and files (sizes, dates) and its database tables, and downloads single files from it. A .tar.gz is read in short steps the first time; the list is kept until the backup changes or is deleted.
* Restore only part of a backup: the whole database or single tables, all files, a folder of wp-content, or one plugin or theme. Tick them in "Look inside", or choose "Only the database" or "Only the files" under Advanced in the restore panel. Everything else on the site stays as it is. WP-CLI: `--only-database`, `--only-files`, `--tables=`, `--paths=`.
* New backups record the exact number of rows of every table (older ones listed the database server's estimate).

= 0.11.0 =
* Backups before updates: just before WordPress, a plugin or a theme updates, a quick backup of the database and that plugin or theme (the database only for WordPress), tagged "before-update", keeping the newest 3. Off by default (Schedule panel). Made within a time allowance; when it cannot be made in time, or another job runs, the update goes ahead without it.

= 0.10.0 =
* Safety backup before every restore: after you confirm, SiteFerry backs up the whole site as it is (an ordinary backup tagged "before-restore") before anything changes. Untick it to skip; WP-CLI `--skip-safety-backup`.
* "Undo this restore" on the Backups tab restores that safety backup. A restore that fails and leaves the site as it was removes its safety backup; one whose rollback had problems keeps it.
* Jobs that run inside another job (the safety backup inside a restore) never count as the active job.

= 0.9.0 =
* Scheduled backups: every day, week or month at a set time in the site's time zone, with the same choices as a backup made by hand, to this server or a storage (optionally keeping a local copy). Runs without anyone logged in: WP-Cron starts it and each step asks the site for the next one, with a five-minute check as a fallback that also stops a backup stuck for an hour.
* Retention: keep the newest N scheduled backups; older ones are deleted everywhere, only after a new one succeeded, and never backups made by hand.
* Email on failure (default), after every backup, or never. "Run it now", a status line with the next and last run, `wp siteferry schedule show|run`.
* A scheduled backup waits for another job (retrying every 15 minutes for two hours) instead of failing.
* On Windows servers, saving a job's state no longer fails while a page or the watchdog reads it at the same moment.

= 0.8.0 =
* Dropbox storage: one-click "Sign in with Dropbox" through SiteFerry's sign-in service, or your own Dropbox app; PKCE without any app secret, so tokens go straight between the site and Dropbox; App folder access; encrypted tokens renewed automatically; upload sessions in 8 MB parts that continue after an interruption; ranged downloads; connection test; revoke on disconnect.
* The sign-in service handles Dropbox as well (it only passes the browser through) and can be set up for Google Drive, Dropbox or both.

= 0.7.0 =
* Simpler backup and restore: one Backups tab with "Back up now", Options, "Upload a backup" and one list of all backups (this server and every storage) in columns; a guided panel to restore a backup of this site or move another site here, checked before anything changes; names, notes and tags for backups, with search and a tag filter. Export and Import tabs are folded into Backups, Log is now Activity.
* .wpress backups (All-in-One WP Migration's format): import them, with each file's CRC-32 checked, and export as .wpress. Password-protected .wpress backups are recognized but not supported yet.
* Reworked admin screen: a header with the site's backups and storages, "Where it goes" choices on the Export tab, a drop zone, a stored-backup picker and steps on the Import tab, list layouts that work on phones, storage cards, inline job logs, empty states and dismissible messages. Job progress is drawn as a route with one leg per step.
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
