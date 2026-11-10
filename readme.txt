=== SiteFerry ===
Contributors: hamzarizvi
Tags: backup, migration, restore, multisite, cloud backup
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.45.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up a whole WordPress site into one TAR file and restore or migrate it to another site.

== Description ==

SiteFerry exports the database and wp-content into a single standard TAR archive (optionally
gzip-compressed) and imports it on another site, replacing the site address where needed. It also
imports and writes .wpress files, the backup format of the All-in-One WP Migration plugin (see
"Can it import and make .wpress backups?" in the FAQ).

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
  restores that safety backup (see "What are the safety backup and Undo?" in the FAQ).
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

Everything can also be done with `wp siteferry ...`, with machine-readable output for scripts
(see "Which WP-CLI commands are there?" in the FAQ).

= Storage =

Backups go to this server, or to a storage set up on the Storage tab: S3-compatible services
(Amazon S3, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, MinIO and others), FTP and
FTPS servers, Google Drive and Dropbox. Uploads and downloads run in resumable parts of 8 MB, so
large backups fit within PHP's time limits, and "Test connection" writes, reads and deletes a small
file. Google Drive and Dropbox connect with one click through SiteFerry's sign-in service (see
"External services"), or with your own OAuth client or Dropbox app. The FAQ has the details of
each.

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
you choose, when you ask or a schedule you set runs. To choose that folder in your Drive, the
Storage tab loads Google's folder picker (apis.google.com) only when you click "Choose a folder in
Drive"; picking a folder gives SiteFerry access to that folder only. Google's
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
it. See "What is the sign-in service (auth.xsofty.com)?" in the FAQ for every step. Publisher:
[xsofty](https://xsofty.com/products/siteferry); its
[privacy policy](https://xsofty.com/products/siteferry/privacy-policy) and
[terms and conditions](https://xsofty.com/products/siteferry/terms-and-conditions).

**Another WordPress site (moving a site).** Only when you paste a move key made on another site and
start the move, SiteFerry sends this site's encrypted backup to the address in that key.


== Frequently Asked Questions ==

= Which WP-CLI commands are there? =

* `wp siteferry export` (back up; `--format=tar|wpress`, `--compress`, `--exclude=...`, `--changes-only`, `--password`, `--send=<id>`, `--dir=...`), `wp siteferry import <file>` (restore; `--only-database`, `--only-files`, `--tables=...`, `--paths=...`, `--replace-url=...`, `--yes`)
* `wp siteferry backups list|check|compare|delete|recheck`, `wp siteferry activity`, `wp siteferry space`
* `wp siteferry storage test|list|send|fetch|delete <id> ...` (s3, ftp, gdrive, dropbox; settings come from the Storage tab)
* `wp siteferry schedule show|run`, `wp siteferry staging create|status|delete`, `wp siteferry receive`, `wp siteferry move <key>`

Output for scripts, the same everywhere: commands that list or report take `--format=table|csv|json|yaml|count` (sizes are bytes outside tables); commands that do something take `--porcelain` (only the key result, such as the backup's path) and `--json` (one JSON object with `"ok"`, the result and `"warnings"`; WP-CLI treats it as `--format=json`, so on export, choose a .wpress file with `--archive=wpress` then). In those modes nothing else is printed, and a failure with `--json` prints `{"ok":false,"error":"..."}`. Exit code: 0 on success, 1 on any error. Shared field names: name, path, size, created (UTC), place ("local" or a storage id), status. Commands that would ask for confirmation need `--yes` with `--json` or `--porcelain`. WP-CLI runs with the server's rights, so SiteFerry's role settings do not apply to it. `wp help siteferry <command>` lists every option.

= Developer hooks =

Actions: `siteferry_export_started( $job )`, `siteferry_export_done( $job, $result )` (file, name, size, files, bytes, base), `siteferry_import_started( $job )`, `siteferry_import_done( $job, $imported )`, `siteferry_backup_sent( $job, $sent )` (name, where, destination; once per storage), `siteferry_job_failed( $job, $error )` (any SiteFerry job, after its cleanup). A callback that throws is logged in the job's log and never stops a backup or restore.

Filters: `siteferry_export_exclusions( $paths, $options )` (paths inside wp-content to leave out; SiteFerry's own and other backup plugins' folders are always left out), `siteferry_manifest_extra( $extra, $job )` (your data in the backup's manifest under "extra"; use your plugin's slug as the key; JSON values, at most 64 KB each), `siteferry_backup_name_label( $label, $home )` (the site part of backup file names: letters, digits, hyphens), `siteferry_move_export_options( $options )`, `siteferry_update_backup_seconds( $seconds, $item )`, `siteferry_relay_url( $url )`.

Example: `add_filter( 'siteferry_export_exclusions', function ( $paths ) { $paths[] = 'uploads/big-videos'; return $paths; } );`

= What are changes-only backups? =

A changes-only backup holds the whole database and only the files that were added or changed
since a full backup on this server, plus the list of files deleted since then. It is much smaller
and quicker to make: on a test site of 400 MB with two changed files it took 7 seconds and 11 MB,
against 16 minutes and 425 MB for the full backup.

* Choose "Only changes since the last full backup" under Customize, or in the schedule "Make a full
  backup every N runs" (the runs in between hold only the changes), or `wp siteferry export
  --changes-only`.
* It is always built on one full backup, never on another changes-only backup, and takes that
  backup's choices of what to leave out. Restoring it uses both: the changed files from it, the
  rest from the full backup (checked against its checksums), the database from it.
* The full backup must stay: SiteFerry refuses to delete it (on this server or on a storage) while
  changes-only backups built on it are there, and "keep the newest N" keeps it longer when needed.
  Sending a changes-only backup to a storage sends its full backup first when the storage does not
  have it; downloading one for a restore downloads its full backup too.
* Files are compared by size and modification time. A file changed without changing either would
  be missed; in practice editors, updates and uploads always change the time.
* Not available yet for password-protected backups or .wpress files.
= How do I make a staging copy? =

On the Backups tab, click "Staging", choose a subfolder name (default: staging) and click "Create the staging copy". SiteFerry copies WordPress's files into that subfolder of WordPress's folder (leaving out what a backup leaves out: caches, backup folders, SiteFerry's storage; optionally the media library) and copies this site's database tables under a new prefix (stg + 4 letters/digits + _) in the same database. It then changes the addresses in the copy, writes its own wp-config.php (only the table prefix and the address differ; database login and keys stay), and adds a small must-use plugin that shows a STAGING badge, keeps search engines away and holds back outgoing email (the badge's menu can turn email on). SiteFerry's storage logins, schedule and backups before updates are not copied, so the copy never sends backups.

Sign in to the copy with the same user name and password. One copy at a time; delete it from the same place: SiteFerry checks the record and the folder's marker file and drops only the copy's tables and folder. Uninstalling SiteFerry does not delete the copy: delete it first.

Not supported yet: multisite networks, sites whose WordPress files are in a different folder from the site's address, wp-content or the media folder moved elsewhere, a wp-config.php that sets the table prefix or ABSPATH in an unusual way or loads other files by a relative path (SiteFerry says which), copying changes back to the live site. On nginx servers, pretty permalinks in the copy need one rule in the site's nginx configuration; SiteFerry shows it.

= Can other users use SiteFerry? =

Administrators can always do everything. To let other roles use parts of SiteFerry, click "Who can use it" on the Backups tab and tick, per role: see backups and activity; make backups (make, download, check, send); restore (restore whole sites, single files or posts, delete backups and the staging copy); settings (storages, schedule, emergency kits, staging, moving the site, and this list). Anything but "see" includes seeing. Give these only to people you trust with the whole site: a backup holds the whole database, including every user's email address and password hash, and a restore can replace the site and its users. On a multisite network only network administrators use SiteFerry. WP-CLI is not limited by these settings, as it already needs access to the server. The capabilities are siteferry_view, siteferry_backup, siteferry_restore and siteferry_settings, so role editor plugins can set them too.

= Can I take one site out of a network, or add a site to one? =

Yes. "Take one site out…" in a network backup's menu (or `wp siteferry backups extract`) makes a
single-site backup of one of its sites: its tables, its users (super admins become its
administrators), its media and all plugins and themes. It imports into any single site, and
the network backup and the network stay as they are. On a network, `wp siteferry export
--site=<id>` does the same from the live network.

The other way round, restoring a single-site backup on a network offers "Add as a new site of
this network": the backup becomes a new site at the name you give, and nothing of the network is
replaced. A backup user whose e-mail address the network already has is that user (password and
rights unchanged) and gets the backup's role on the new site; the others are added with their
own passwords, and a login that is taken gets "-2". Post, comment and link authors follow the
new user numbers; plugin tables that store user numbers are not changed. Plugins and themes the
network already has are never replaced. Password-protected and .wpress backups cannot be
used for either yet.

= What if my site shows a white screen? =

Make an emergency kit before you need it: on the Backups tab, click "Emergency kit", choose a password (at least 12 characters) and download the file. Keep it, with its password, somewhere safe and private, not on the server. If the site later breaks and you cannot log in, upload the file by FTP or your hosting file manager into WordPress's main folder (the one with wp-config.php or wp-load.php and wp-admin) and open it in your browser at your site's address followed by the file name. Sign in with the kit's password. It works without WordPress: it can turn off every plugin except SiteFerry, switch to a default "Twenty" theme (both can be undone) and download SiteFerry's backups. Once the dashboard opens again, restore a backup with SiteFerry if needed, and delete the kit with its "Delete this kit" button. A kit works for 7, 30 or 90 days, only on the site it was made for; revoke it in SiteFerry if you lose the file. It cannot repair damaged WordPress files or a broken database: then restore through your hosting control panel, or ask your host.

= How does S3-compatible storage work? =

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

= How do FTP and FTPS work? =

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

= How does Google Drive work? =

Two ways to sign in, chosen on the Storage tab:

* "Sign in with Google" (the default): click the button and allow access on Google's page. No
  Google Cloud project or keys are needed; SiteFerry's own Google app is used, through SiteFerry's
  sign-in service (see "What is the sign-in service (auth.xsofty.com)?" in the FAQ).
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

= How does Dropbox work? =

Two ways to sign in, chosen on the Storage tab; neither needs a secret, because Dropbox lets an app
sign in with PKCE and its public app key alone:

* "Sign in with Dropbox" (the default): click the button and allow access on Dropbox's page.
  SiteFerry's own Dropbox app is used; your browser passes through SiteFerry's sign-in service on
  the way (see "What is the sign-in service (auth.xsofty.com)?" in the FAQ), but the access itself never does.
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

= What is the sign-in service (auth.xsofty.com)? =

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

= How are keys, passwords and tokens stored? =

Secret keys, passwords and OAuth tokens are encrypted before they are saved (libsodium secretbox, or OpenSSL
AES-256-GCM if libsodium is missing) with a key derived from the site's WordPress salts. They are
never shown again or sent to the browser, never logged, left out of every export, kept when a
backup is imported into this site, and removed when SiteFerry is deleted. If the salts change,
enter them again. To use a key that survives changed salts, define `SITEFERRY_ENCRYPTION_KEY` (at
least 32 random characters) in wp-config.php before saving the settings.

= How does an import work? =

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

= How do password-protected backups work? =

"Protect the backup with a password" in Options (and, for automatic backups, in the Schedule
panel) encrypts the whole backup with AES-256-GCM as it is written; the file name ends in .enc. The
key comes from the password (PBKDF2-HMAC-SHA256, 600,000 rounds, and HKDF); every 2 MB chunk is
authenticated, so a changed, reordered or cut-off file is refused. Restoring, checking or looking
inside asks for the password; a wrong one is refused before anything changes. The password of a
backup made by hand is not kept anywhere. The password of automatic backups is kept on the site,
encrypted with a key from the WordPress salts. Safety backups made before a restore are not
encrypted (they stay on this server). Needs the PHP OpenSSL extension.

Copies sent to a storage leave your server, so a password is recommended for them: a new schedule
that sends to a storage has it ticked already, and an existing one shows the advice until you set a
password or click "Not now". With a password, the whole backup is encrypted, the copy on this server
too. "Test my password" in the Schedule panel checks that you still know it (it is compared with the
stored one and never shown; five tries per fifteen minutes), and once a month SiteFerry asks you to
test it. Changes-only backups cannot have a password yet: choose full backups every run, or no
password.

= Does it work on multisite networks? =

On a network, SiteFerry is for super admins, on the main site, and backs up the whole network:
every site's tables, the network tables and all of wp-content. Such a backup restores into a
network of the same kind (subfolders or subdomains); every subsite is put under the new network's
domain and path, and subsites made after the backup are removed with their tables. A single site
cannot be restored into a network (or the other way round) yet, nor a part of a network.

= How do I move a site straight to another server? =

"Move a site" on the new site makes a one-time key (24 hours). Pasted under "Move a site" on the old site, it
shows the new site and then moves the old one there: a fresh backup, encrypted with the key's
256-bit secret (AES-256-GCM), sent straight from server to server in chunks with their SHA-256;
an interrupted transfer continues where it stopped. The routes that receive it answer only to the
key, which works once and is dropped after 20 wrong tries. On the new site the backup waits in the
list; "Move it here" restores it after the usual check and confirmation, with the key's secret as
its password (kept, encrypted, until the backup is deleted). Only the address in the key is
contacted.

= What does checking a copy do? =

"Check the copy on ..." in a backup's menu reads that copy from start to end without unpacking
anything. Every file is compared with the backup's own SHA-256 checksum list; the manifest must be
there, the database dump complete and of the size the manifest gives, and entries an import would
refuse count as damage. A copy on a storage is downloaded into SiteFerry's protected folder first
and deleted afterwards. The outcome shows on the copy's place badge (a tick, or a warning) and
stays until the copy is deleted. A .wpress file has no checksums: its check only makes sure it
reads to its end.

= What are the safety backup and Undo? =

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

= What is never included in an export? =

Left out of every export: cache folders (unless `--include-cache`), WordPress's upgrade folders,
other backup plugins' folders, `.git`, `node_modules`, SiteFerry itself, its storage folder and its
settings (including storage credentials).

= Where are backups stored? =

In `wp-content/siteferry-<random>/backups/`, with `.htaccess`, `web.config` and `index.php` deny
files. Web servers that ignore those files (nginx, including LocalWP) will serve a backup to anyone
who knows its exact URL; the random folder and file names make that URL unguessable. To block it on
nginx as well, add a rule such as `location ~ /wp-content/siteferry-[a-f0-9]+/ { deny all; }`.

= What is in a SiteFerry backup file? =

A SiteFerry backup is a plain POSIX TAR file (ustar with pax extended headers) that any TAR tool can
open. It contains:

* `siteferry.json` - the manifest (site address, versions, export options).
* `database.sql` - the database dump.
* `wp-content/...` - the site files.
* `siteferry.sha256` - SHA-256 checksums of every file above except the manifest, in `sha256sum` format.

Compressed backups (`.tar.gz`) are a series of independent gzip members of about 8 MB each, so they
are valid gzip files and an interrupted job can resume at a member boundary.

= Can it import and make .wpress backups? =

.wpress is the backup format of the All-in-One WP Migration plugin. SiteFerry reads and writes it
from the format's layout, for moving sites between the two plugins. SiteFerry is not affiliated
with, endorsed by or sponsored by ServMask or the All-in-One WP Migration plugin, and contains none
of its code. All-in-One WP Migration is a trademark of ServMask, Inc., used here only to say which
files SiteFerry can read and write.

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

== Changelog ==

= 0.45.0 =
* Networks: take one site out of a network backup as an ordinary single-site backup ("Take one site out…" in a network backup's menu, `wp siteferry backups extract <backup> --site=<id>`, or `wp siteferry export --site=<id|address>` on a network). It holds the site's tables renamed for a single site, its users (super admins become administrators), its media and all plugins and themes; it imports into any single site.
* Networks: add a single-site backup to a network as a new site ("Add as a new site of this network" when restoring on a network, or `wp siteferry import <file> --as-new-site=<name>`). Nothing of the network is replaced: users with an e-mail address the network has are matched to them, others are added with their passwords (a taken login gets -2), media go to the new site's folder, and only plugins and themes the network lacks are copied. A failure removes the new site again.

= 0.44.0 =
* WP-CLI for scripts, the same way in every command: lists and reports take `--format=table|csv|json|yaml|count` (sizes in bytes outside tables; backups list and storage list add kind, base, encrypted, place, complete); commands that do something take `--porcelain` (only the key result) and `--json` (one JSON object with ok, the result and warnings; a failure prints {"ok":false,"error":...}); exit code 0 or 1. `wp siteferry export --archive=wpress --json` makes a .wpress file with JSON output (WP-CLI turns --json into --format=json).
* New `wp siteferry backups delete <name>... [--yes]` (a full backup that changes-only backups need is kept).
* Developer hooks: actions siteferry_export_started, siteferry_export_done, siteferry_import_started, siteferry_import_done, siteferry_backup_sent, siteferry_job_failed; filters siteferry_export_exclusions, siteferry_manifest_extra, siteferry_backup_name_label. A callback that throws is logged and never stops a backup or restore. FAQ: "Which WP-CLI commands are there?" and "Developer hooks".
* The summary of `wp siteferry backups compare` says "1 plugin" rather than "1 plugins".

= 0.43.1 =
* readme: the changelog keeps the newest versions; the full history moved to changelog.txt (WordPress.org shortens long changelogs).

= 0.43.0 =
* Passwords for copies sent to storages: a new schedule that sends to a storage has "Protect automatic backups with a password" ticked already; an existing one shows a dismissible recommendation instead (nothing changes by itself). Back up now recommends a password when sending to a storage.
* "Test my password" in the Schedule panel checks that you still know the automatic backups' password: it is compared with the stored one and never shown, five tries per fifteen minutes. Once a month SiteFerry asks you to test it ("Remind me next month" waits another month).
* Changes-only backups still cannot have a password; the schedule says so when both are chosen.

= 0.42.0 =
* Who can use SiteFerry: four capabilities (see backups and activity; make backups; restore; settings) that "Who can use it" on the Backups tab gives to other roles, with plain warnings about what each allows. Administrators always have all of them. Every REST route checks the matching one, and the page hides what a user may not do. On a network only network administrators use SiteFerry, as before; WP-CLI is not limited. Uninstalling removes the capabilities from every role.

= 0.41.0 =
* Activity: "Download CSV" saves the whole activity list as a spreadsheet file: time (site time zone and UTC), kind, backup, result, duration, size, where, who started it and the message. Opens correctly in Excel (UTF-8); cells that a spreadsheet would treat as formulas are made plain text.
* WP-CLI: `wp siteferry activity [--since=<YYYY-MM-DD>] [--type=<kind>] [--format=table|csv|json]`.
* Jobs now record who started them (a user, WP-CLI, the schedule or an automatic backup).

= 0.40.0 =
* While SiteFerry works (a backup, restore, upload, download, check or staging copy), every admin page shows "SiteFerry 42%" in the admin bar, linking to the SiteFerry page; when it ends, "Backup done ✓" (or a red "… failed"). It updates every three seconds while the tab is visible and stops when the job has ended; nothing is loaded when no job runs. It stays visible on phones. Background checks of the weekly re-check are not shown.

= 0.39.1 =
* Fixed: checking a changes-only backup (by hand or in the weekly check) reported the files kept in its full backup as missing, so a healthy copy was called damaged. Those files are now counted as in the full backup, and the result says so.
* The SiteFerry page footer credits xsofty with "Plugin by" and its logo; the logo ships with the plugin.

Older versions: see changelog.txt in the plugin folder.
