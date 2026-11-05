# SiteFerry tests

## Stand-alone CLI tests (no WordPress needed)

```powershell
# Lint every PHP file and run all suites on LocalWP's PHP 7.4, 8.2 and 8.5 builds.
powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1

# Cross-check the TAR format with bsdtar (Windows tar.exe) and GNU tar (Git for Windows).
powershell -ExecutionPolicy Bypass -File tests\interop-tar.ps1
```

Pass `-Keep` to either script to keep the test data in `tests\tmp\` (ignored by git).
A single suite can be run directly, for example `php -n tests\test-tar-roundtrip.php`.

| Suite | Covers |
| --- | --- |
| `test-tar-header.php` | ustar/pax encoding, prefix splitting, sizes over 8 GiB, GNU long names and base-256 numbers, checksums, truncated and corrupt input |
| `test-tar-roundtrip.php` | Pack, list and extract for plain and block-gzip archives; long and Unicode paths, empty files and folders; byte-limited slices with simulated crashes; resumable hashing on PHP 7.4 and 8 |
| `test-tar-changes.php` | Source files that shrink, grow, change or vanish between slices; writer misuse |
| `test-tar-safety.php` | Path validation; refusal of links, devices, FIFOs, sparse files, `..`, absolute paths, drive letters, pax/GNU name tricks, allow-list violations and planted links or junctions; entries left out by the skip option (not written, also when a slice ends right after one, and still refused when unsafe) |
| `test-jobs.php` | Job files, tokens, slice lock, step runner across slices and reloads, failure cleanup, warnings, storage deny files |
| `test-export-scan.php` | Export exclusion rules; resumable wp-content scan (order, counts, one-folder slices with junk from dead requests, junctions not followed) |
| `test-sql.php` | Column kinds and SQL literals in dumps (NULL, numbers, BIT, binary, escapes, UTF-8) |
| `test-import-sql.php` | Serialization-safe replacement (nested values, objects, references, broken and custom-serialized values); replacement pairs; resumable statement reading; statement rewriting and refusal of statements a dump never contains |
| `test-upload.php` | Chunked upload sessions: unsafe names, chunks only at the current end with a matching SHA-256, junk left by a dead request cut off, non-backups refused, safe backup-name lookup |
| `test-sigv4.php` | AWS Signature Version 4 against the examples AWS publishes (S3 GET with Range, PUT with "$" in the key, parameter without value, sorted query; IAM example with its signing key; the test suite's get-vanilla) |
| `test-s3.php` | S3 driver against an in-process fake S3 (`lib/class-fake-s3.php`, which checks every signature and payload hash): settings and presets, URLs in both addressing styles, connection test, multipart upload, listing over several pages, ranged download, part sizes, error messages (wrong key, region, bucket, clock), retries with backoff, a 200 answer with an error document, keep-alive spaces, refused DOCTYPEs |
| `test-ftp.php` | FTP driver through PHP's real ftp extension against `tools/fake-ftp-server.php` (started on 127.0.0.1 for each case): settings, line breaks refused, explicit FTPS on control and data connections, plain FTP, FTPS against a server without TLS, wrong password, upload in parts with a repeated part and a stale `.part` file, rename on finish, listing, ranged downloads, retry after a dropped connection, no retry for refusals, abort, the PASV address ignored, absolute and login folders |
| `test-contents.php` | Looking inside a backup: the index of a .tar, a .tar.gz (built over several calls when time runs out) and a .wpress, cached under the backup's name, size and time and forgotten on delete; folder listings (folders first, natural order, file counts and sizes); the partial-restore options (table names and paths checked, cleaned and deduplicated, `..`, drive letters and deeper paths refused) and which paths and tables an import then takes |
| `test-check.php` | Checking a copy of a backup: an intact .tar, .tar.gz and .wpress (also in one-chunk slices, with files cut by a slice continued, by saved hash state on PHP 8 and by reading again on 7.4); a changed byte, a cut archive, a missing file, an incomplete dump, a dump of the wrong size, a missing manifest or checksum list and an entry an import refuses, each named; the working files removed; outcomes kept per place, newest first, forgotten per place or per backup |
| `test-crypto.php` | Password-protected backups: the password check (right, wrong, a changed header, fewer rounds written in), .tar and .tar.gz written through the encrypting stream in slices with junk from dead requests cut off and read back exactly, reads at any position, nothing in the clear, and tampering refused (a changed byte, a missing final chunk, a cut file, swapped chunks, chunks under another header); sealing twice; an unsealed file refused |
| `test-space.php` | The space report's sums: parts of wp-content, media by year, plugins and themes with what is inactive, cache, top-level folders and the largest files (also over many files), and plugins kept outside wp-content |
| `test-parts.php` | Backups in parts: cutting (whole when it fits, numbered parts that add up exactly, at most 999), which stored names count, listings that show each set as one backup (in order, a missing part noticed, a whole file preferred), part sizes from the settings form |
| `test-gdrive.php` | Google Drive driver against an in-process fake Google (`lib/class-fake-google.php`): consent URL (drive.file only, offline, PKCE S256), code exchange (reused code, wrong verifier, missing scope, missing refresh token, wrong client secret), token refresh before expiry and after a 401 with the new token saved, revoked grant, folder created/found/re-created, resumable upload with a repeated and a half-stored part, replacing an earlier copy, paged listing, ranged downloads, trash, abort, rate limits retried, a full Drive and a foreign upload host refused, connection test, disconnect revokes; one-click mode through the relay (no client ID or secret on the site, renewal through the relay, an unreachable relay named in the error) |
| `test-relay.php` | The sign-in relay (`relay/src/class-relay.php`) with a fake Google transport: health check, return addresses that are not a SiteFerry admin page refused, signed state (tampered, changed return address, expired), callback back to the site with its own state or Google's refusal, token requests forwarded with the client secret added, malformed and oversized requests, Google unreachable; Dropbox start (app key, callback, offline access, permissions, PKCE, state within 500 bytes, an address too long refused), callback, `/dropbox/app`, Google and Dropbox states not interchangeable, no token route for Dropbox, a relay set up for only one provider |
| `test-schedule.php` | Backup schedule without WordPress: settings defaults and every invalid value refused (frequency, time, days, retention, format, storage, email with a header injection), next run daily/weekly/monthly in several time zones, across daylight saving time (including a skipped hour) and month and year ends, retention picking only the scheduled backups beyond the newest N, the export options a schedule makes; backups before updates: settings, which updates get one (not new installs or translations), and options that keep only the plugin or theme being updated (the database only for WordPress) |
| `test-dropbox.php` | Dropbox driver against an in-process fake Dropbox (`lib/class-fake-dropbox.php`, strict about Content-Type, request bodies and ASCII-only `Dropbox-API-Arg` like Dropbox): settings and folder rules, authorization URL (offline, four permissions, PKCE S256), code exchange without a secret (reused code, wrong verifier, missing permission, missing refresh token, wrong app key), renewal before expiry and after a 401 with the new token saved, revoked grant, upload sessions with a repeated part and lost answers to an append and to the finish, replacing an earlier copy, a skipped part and an expired session refused, downloads by GET without body or Content-Type, the token only to the API hosts, paged listing without folders or other files, ranged downloads, delete, 429/503/network retries, a full Dropbox, connection test, disconnect revokes; one-click mode through the real relay (the relay sees no token, renewal straight to Dropbox, an unreachable relay or one without Dropbox named in the error) |
| `test-wpress.php` | .wpress backups: header layout (also the older layout without CRCs), writing and reading with per-file and whole-archive CRC-32 in tiny resumable slices, a flipped byte and a cut-off archive detected, a writer that dies mid-file continued from its checkpoint, unsafe paths refused by Tar_Extractor, the dump converted both ways (placeholder prefix, column lists, one row per INSERT, transactions, theme and plugins filled in, wp_ option names kept) and accepted by Statement_Rewriter, package.json to manifest and back, which entries an import leaves out. With `tests/tmp/wpress/aiowm-plain.wpress` (and `aiowm-encrypted.wpress`) made by All-in-One WP Migration present, the real backup is read in full with every CRC checked and its dump converted |
| `test-secrets.php` | Encryption of stored keys with libsodium and OpenSSL: round trip, fresh nonces, tampering and wrong keys detected (`run-tests.ps1` loads both extensions) |
| `js/test-sha256.js` | The admin screen's SHA-256 fallback against Node's crypto (run by `run-tests.ps1` when Node is installed) |
| `compat-check.php` | Calls to built-in functions newer than PHP 7.4 (syntax is checked by `php -l` on 7.4 itself) |

## WordPress tests on LocalWP test sites

These change the site they run on; use the disposable `siteferry-*` sites only.

```powershell
# WP-CLI on a Local site by name, with that site's PHP build and php.ini.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-src option get siteurl

# End-to-end export test: links and activates the plugin, adds test content, runs the database
# dump round trip, exports with and without exclusions and verifies each backup with GNU tar and
# "sha256sum -c".
powershell -ExecutionPolicy Bypass -File tests\wp-export-check.ps1 -Site siteferry-src

# Database dump round trip only (creates and drops {prefix}sftest_/sfcopy_ tables).
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-src eval-file tests/wp/test-dump.php

# REST API: anonymous requests refused on every route, job token checks, an export driven with the
# token only, a chunked upload with a wrong offset and a damaged chunk. Removes the backups it makes.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-src eval-file tests/wp/test-rest.php

# Scheduled backups, with real small backups (no media, plugins or themes): settings through the
# REST API (admin only, checked, not autoloaded, next run in the site's time zone), a cron start,
# "Run it now" finished by the site's own background requests (the site must reach itself over
# HTTP), retention (scheduled backups only), email (caught with pre_wp_mail), waiting for another
# job and skipping after two hours, the watchdog carrying on a stalled backup and stopping a stuck
# one, the next run put back after a lost cron table and reactivation, WP-CLI. Refuses to run where
# a schedule is set up; removes its backups and the schedule at the end.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-schedule.php

# Look inside a backup: a small backup (the database and the smallest plugin), listed through the
# REST API (administrators only; files, tables with exact row counts, the prefix, one folder), single
# files downloaded over HTTP as a signed-in administrator and compared byte for byte, refusals (no
# login, a folder, a missing file, a path outside the backup), and the index removed with the backup.
# The site must reach itself over HTTP. Removes its backup.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-contents.php

# Checking a copy: a small backup (the database only) checked through the REST API (administrators
# only, one job at a time), a copy of it with one changed byte found damaged with the file named,
# the outcomes listed, and forgotten when the backups are deleted. Removes its backups.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-check.php

# Password-protected backups: a small protected backup (the database only) with nothing readable in
# it; .wpress and short passwords refused; listed as protected; check, "Look inside" (unlock token,
# encrypted file list) and a restore check each refuse a missing or wrong password and work with the
# right one (the restore check is cancelled); jobs keep no key once they end; the schedule's password
# kept encrypted and never sent back (the schedule settings are put back). Removes its backup.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-encrypted.php

# Space report: administrators only; measured in steps, with the media, plugin and database sizes
# compared with a walk of the folders and SHOW TABLE STATUS; a kept report used again, "fresh"
# measures again. Changes nothing but the kept report.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-space.php

# Site-to-site move: the site moves a small backup (the database only) to itself over HTTP. The key
# (administrators make it; the move routes answer to it alone; wrong keys drop it; it works once),
# the encrypted backup sent in chunks with the local copy removed, a chunk at the wrong offset
# (409), and the backup that arrived opening without a password for a check and a restore check
# (cancelled). Removes its backup.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-move.php

# Backups before updates: applies WordPress's upgrader_pre_download filter with real upgrader objects
# (nothing is downloaded or updated). Off by default; a plugin update backs up the database and only
# that plugin, a theme update only that theme, a WordPress update only the database (the archives are
# opened and checked); the download goes ahead unchanged; once per item; the newest two kept; skipped
# when too slow or another job runs; nothing for translations or new installs. Removes its backups.
powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-dst --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-before-updates.php

# Remote storage against fake servers on 127.0.0.1 (started and stopped by the script, with test
# logins generated for the run): S3 and Google Drive through the WordPress HTTP API (the test
# points the "siteferry_gdrive_endpoints" filter at the fake and goes through the sign-in: state,
# consent, connect), FTPS through PHP's ftp extension. Encrypted settings, connection test,
# export sent to storage and fetched back byte-identical, the stored copy checked (intact, then
# with a changed byte on the fake storage), a size limit (stored as three parts, listed as one,
# fetched and checked whole, deleted with all parts), sending in slices, cancel (the
# unfinished upload is removed), retried temporary failures, the WP-CLI storage commands, remote
# delete. Refuses to run when the site already has settings for that storage.
# gdrive-relay runs the Google Drive test in one-click mode, with the real relay
# (relay\public\index.php under "php -S", with curl) between the site and the fake Google.
# dropbox runs it against tools\fake-dropbox-server.php (the "siteferry_dropbox_endpoints" filter),
# dropbox-relay in one-click mode with the browser passing through the real relay; Dropbox has no
# way to remove an unfinished upload session, so after a cancel one is left for it to discard.
# -Driver s3|ftp|gdrive|gdrive-relay|dropbox|dropbox-relay|all; -Serve keeps one server running
# for browser tests. The backup sent first is the database only; -Full sends the whole site (slow on
# a big site: every part goes through a single-threaded "php -S").
powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Site siteferry-src

# Import tests. They REPLACE siteferry-dst's content; run them only with the owner's permission.
#   site:     export siteferry-src (with marker content) and import it into siteferry-dst
#   rollback: a backup of siteferry-dst itself (marker content), imported with a simulated failure
#             after the table swap; dst must be unchanged and the safety backup removed again
#   undo:     the same backup imported with a safety backup, then undone by importing the safety
#             backup; every table but the options and every file must be as before
#   partial:  the same backup, restoring only the posts and postmeta tables and the smallest
#             plugin after changing that plugin, uploads and an option; only the chosen parts may
#             change (every other table, the options and every other file stay as they are)
#   encrypted: a password-protected backup of siteferry-dst: unreadable, a wrong password refused
#             before anything changes, restored with the password (marker content back, files as before)
#   archive:  import an existing backup; tables not touched by search-replace must match the source
# rollback, undo, partial and encrypted do not touch siteferry-src.
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario site
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario rollback
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario undo
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario partial
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario encrypted
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario archive -Archive <backup.tar> -SourceChecksums <list>
```

`tests\wp\db-state.php` (read-only) prints a site's table checksums, addresses and leftovers as JSON
for these checks.

The logins of the test sites are in `tests\local-sites.credentials.json` (ignored by git).

## Admin screen checks in a browser

Done by hand (or by an assistant driving a browser) while logged in as an administrator:

* Backups tab with no backups: the empty list offers "Back up now" and "Upload a backup".
* "Back up now" with Options closed makes a whole-site backup; with a name, note and tags in
  Options they show in the list (and the fields empty afterwards). The backup downloads
  byte-identical; a `Range` request answers 206. "Also download it to my computer" starts the
  download when it is ready.
* "Name, note and tags" in a row's menu edits them in place; a tag click filters the list (the chip
  turns on, the summary says "1 of N"); a search with no match offers "Show all backups".
* "Upload a backup", reload the page partway, choose the same file again: the upload continues from
  the last stored chunk. The uploaded backup is offered as "Restore it" or "Move it here".
* Reload the page while a backup runs: the banner offers to resume or cancel it.
* "Restore" opens the panel and checks the backup without changing anything (title with the
  backup's name and date, no From/Into for this site's own backups, red "Restore now"); Cancel
  closes it and the check job is cancelled. On siteferry-dst only, with the owner's permission:
  confirm, and the site is restored. A backup from another site says "Move here", shows From/Into and
  needs the tick.
* Deleting the last copy of a backup also removes its name, note and tags.
* Storage tab, for each storage (with `wp-storage-check.ps1 -Driver <id> -Serve` running, or a
  real account): save the settings
  (the key field empties and the page says a key is saved), test the connection, export with
  "send it to", download the stored backup to this server, delete it there, remove the settings.
  Start a send, run one short slice, reload: the banner resumes it.
* Google Drive with the fake: run `wp-storage-check.ps1 -Driver gdrive -Serve` (or start
  `tools/fake-google-server.php` yourself) and add a temporary must-use plugin that points the
  `siteferry_gdrive_endpoints` filter at it. Save the client, Connect, Deny (the page says access
  was not allowed), Connect, Allow (connected; the code is gone from the address bar), test, send,
  download back, delete, Disconnect, remove. Delete the must-use plugin afterwards.
* One-click Google sign-in: the same with the relay running too (`wp-storage-check.ps1 -Driver
  gdrive-relay -Serve`) and the must-use plugin also setting `siteferry_relay_url`. "Sign in with
  Google" leads through the relay to the consent page; after Allow the page says connected with no
  client ID or secret entered. Switching to "Use my own OAuth client" shows the client fields.
* Dropbox with the fake: `wp-storage-check.ps1 -Driver dropbox -Serve` (or `-Driver dropbox-relay
  -Serve` for one-click sign-in) and a temporary must-use plugin that points the
  `siteferry_dropbox_endpoints` filter (and, with the relay, `siteferry_relay_url`) at it. The card
  shows "Sign in with Dropbox"; "Use my own Dropbox app" shows the redirect URI and the app key
  field and renames the button "Connect to Dropbox". Then as for Google Drive: Cancel on the
  authorization page, Allow, test, send, download back, delete, Disconnect, remove.
* .wpress (with the owner's permission for each import into siteferry-dst): make a backup with All-in-One
  WP Migration on siteferry-src and import it into siteferry-dst with `wp siteferry import <file>.wpress --yes`
  (check the theme, users and front page afterwards); export siteferry-src with `--format=wpress` and
  import that with All-in-One WP Migration's "Import from file" on siteferry-dst. Remove that plugin again
  from both sites afterwards.
* The progress box draws one leg per job step (export: 4, or 5 with sending; import: 8; send and
  fetch: 1) and ends with every leg solid, or stopped on the failed leg.
* At phone width (375 px) nothing scrolls sideways; the tab bar scrolls on its own.
* The browser console shows no errors.

## Exporting a site without installing SiteFerry

`--require` loads SiteFerry for one WP-CLI command; with `--dir` nothing is written to the site.
`--skip-plugins --skip-themes` keeps the site's own code from running and `DISABLE_WP_CRON`
stops WordPress from triggering scheduled tasks:

```powershell
wp --skip-plugins --skip-themes --exec="define('DISABLE_WP_CRON', true);" --require=D:\path\to\siteferry\siteferry.php siteferry export --dir=D:\backups
```

`run-tests.ps1`, `interop-tar.ps1` and the WordPress test scripts call external programs (tar,
sha256sum, PHP, WP-CLI, Node, "php -S" for the fake S3 and Google servers, a PHP process for the
fake FTP server, a junction for the safety test); the plugin itself never does.
