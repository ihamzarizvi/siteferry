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
| `test-tar-safety.php` | Path validation; refusal of links, devices, FIFOs, sparse files, `..`, absolute paths, drive letters, pax/GNU name tricks, allow-list violations and planted links or junctions |
| `test-jobs.php` | Job files, tokens, slice lock, step runner across slices and reloads, failure cleanup, warnings, storage deny files |
| `test-export-scan.php` | Export exclusion rules; resumable wp-content scan (order, counts, one-folder slices with junk from dead requests, junctions not followed) |
| `test-sql.php` | Column kinds and SQL literals in dumps (NULL, numbers, BIT, binary, escapes, UTF-8) |
| `test-import-sql.php` | Serialization-safe replacement (nested values, objects, references, broken and custom-serialized values); replacement pairs; resumable statement reading; statement rewriting and refusal of statements a dump never contains |
| `test-upload.php` | Chunked upload sessions: unsafe names, chunks only at the current end with a matching SHA-256, junk left by a dead request cut off, non-backups refused, safe backup-name lookup |
| `test-sigv4.php` | AWS Signature Version 4 against the examples AWS publishes (S3 GET with Range, PUT with "$" in the key, parameter without value, sorted query; IAM example with its signing key; the test suite's get-vanilla) |
| `test-s3.php` | S3 driver against an in-process fake S3 (`lib/class-fake-s3.php`, which checks every signature and payload hash): settings and presets, URLs in both addressing styles, connection test, multipart upload, listing over several pages, ranged download, part sizes, error messages (wrong key, region, bucket, clock), retries with backoff, a 200 answer with an error document, keep-alive spaces, refused DOCTYPEs |
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

# Remote storage through the WordPress HTTP API against a fake S3 server on 127.0.0.1 (started and
# stopped by the script, with test keys generated for the run): encrypted settings, connection
# test, export sent to storage and fetched back byte-identical, sending in slices, cancel (the
# multipart upload is aborted), retried 503s, the WP-CLI storage commands, remote delete.
# Refuses to run when the site already has S3 settings. -Serve keeps the server running for
# browser tests.
powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Site siteferry-src

# Import tests. They REPLACE siteferry-dst's content; run them only with the owner's permission.
#   site:     export siteferry-src (with marker content) and import it into siteferry-dst
#   rollback: the same with a simulated failure after the table swap; siteferry-dst must be unchanged
#   archive:  import an existing backup; tables not touched by search-replace must match the source
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario site
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario rollback
powershell -ExecutionPolicy Bypass -File tests\wp-import-check.ps1 -Scenario archive -Archive <backup.tar> -SourceChecksums <list>
```

`tests\wp\db-state.php` (read-only) prints a site's table checksums, addresses and leftovers as JSON
for these checks.

The logins of the test sites are in `tests\local-sites.credentials.json` (ignored by git).

## Admin screen checks in a browser

Done by hand (or by an assistant driving a browser) while logged in as an administrator:

* Export from the Export tab; the backup is listed and downloads byte-identical; a `Range` request
  answers 206.
* Upload a backup on the Import tab, reload the page partway, choose the same file again: the
  upload continues from the last stored chunk.
* Reload the page while an export runs: the banner offers to resume or cancel it.
* On siteferry-dst only, with the owner's permission: check a backup, cancel at the confirmation
  (nothing changes), then import it; the page then asks you to log in with the imported site's users.
* Storage tab (with `wp-storage-check.ps1 -Serve` running, or a real account): save the settings
  (the key field empties and the page says a key is saved), test the connection, export with
  "send it to", download the stored backup to this server, delete it there, remove the settings.
  Start a send, run one short slice, reload: the banner resumes it.
* The browser console shows no errors.

## Exporting a site without installing SiteFerry

`--require` loads SiteFerry for one WP-CLI command; with `--dir` nothing is written to the site.
`--skip-plugins --skip-themes` keeps the site's own code from running and `DISABLE_WP_CRON`
stops WordPress from triggering scheduled tasks:

```powershell
wp --skip-plugins --skip-themes --exec="define('DISABLE_WP_CRON', true);" --require=D:\path\to\siteferry\siteferry.php siteferry export --dir=D:\backups
```

`run-tests.ps1`, `interop-tar.ps1` and the WordPress test scripts call external programs (tar,
sha256sum, PHP, WP-CLI, Node, "php -S" for the fake S3 server, a junction for the safety test); the
plugin itself never does.
