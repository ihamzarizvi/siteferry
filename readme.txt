=== SiteFerry ===
Tags: backup, migration, restore, tar
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up a whole WordPress site into one TAR file and restore or migrate it to another site.

== Description ==

SiteFerry exports the database and wp-content into a single standard TAR archive (optionally
gzip-compressed) and imports it on another site, replacing the site address where needed.

Work in progress. This version exports and imports sites from WP-CLI; the admin screen follows.

= WP-CLI =

* `wp siteferry export [--compress] [--exclude=media,plugins,themes,inactive-plugins,inactive-themes,spam-comments,revisions] [--include-cache] [--exclude-tables=...] [--exclude-paths=...] [--dir=...] [--porcelain]`
* `wp siteferry import <file> [--replace-url=<url>] [--replace="old=>new;..."] [--include-mu-plugins] [--include-drop-ins] [--force] [--yes]`
* `wp siteferry backups list [--dir=...] [--format=table|csv|json|yaml]`

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

= 0.3.0 =
* Import: staging with checksum verification, temporary tables, serialization-safe search-replace, atomic table swap, journalled file moves, automatic rollback.
* WP-CLI: `wp siteferry import`.

= 0.2.0 =
* Export: resumable wp-content scan with exclusions, batched database dump, archive with manifest and checksums.
* Job runner with file-based state, per-job tokens and slice lock.
* WP-CLI: `wp siteferry export`, `wp siteferry backups list`; works with `--require` on sites without SiteFerry.

= 0.1.0 =
* Plugin skeleton and streaming TAR reader/writer.
