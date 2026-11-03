=== SiteFerry ===
Tags: backup, migration, restore, tar
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up a whole WordPress site into one TAR file and restore or migrate it to another site.

== Description ==

SiteFerry exports the database and wp-content into a single standard TAR archive (optionally
gzip-compressed) and imports it on another site, replacing the site address where needed.

Work in progress. This version exports sites from WP-CLI; importing and the admin screen follow.

= WP-CLI =

* `wp siteferry export [--compress] [--exclude=media,plugins,themes,inactive-plugins,inactive-themes,spam-comments,revisions] [--include-cache] [--exclude-tables=...] [--exclude-paths=...] [--dir=...] [--porcelain]`
* `wp siteferry backups list [--dir=...] [--format=table|csv|json|yaml]`

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

= 0.2.0 =
* Export: resumable wp-content scan with exclusions, batched database dump, archive with manifest and checksums.
* Job runner with file-based state, per-job tokens and slice lock.
* WP-CLI: `wp siteferry export`, `wp siteferry backups list`; works with `--require` on sites without SiteFerry.

= 0.1.0 =
* Plugin skeleton and streaming TAR reader/writer.
