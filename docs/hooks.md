# SiteFerry hooks and WP-CLI output

Reference for developers. All hook names start with `siteferry_`. They are fired through
`SiteFerry\Hooks`, which skips them where WordPress's plugin API is not loaded (for example
`wp siteferry export --dir=...` on a site where SiteFerry is not active) and catches anything an
action callback throws: a failing callback is written to the job's log (`job.log`) and never stops a
backup, restore or transfer.

`$job` is a `SiteFerry\Jobs\Job`. Useful methods: `id()`, `type()` (`export`, `import`, `send`,
`fetch`, `check`, `staging`, `staging-delete`), `status()`, `option( $key )`, `result()`,
`created()`, `warnings()`, `path( 'job.log' )`.

## Actions

### `siteferry_export_started`

`do_action( 'siteferry_export_started', Job $job )`

A backup job was created: Back up now, WP-CLI, a schedule, the quick backup before an update or a
risky change, or the safety backup a restore makes first (`$job->option( 'origin' )` is `admin`,
`schedule`, `update` or `safety`). Nothing has been written yet.

### `siteferry_export_done`

`do_action( 'siteferry_export_done', Job $job, array $result )`

The backup file is complete and named. `$result`: `file` (path), `name`, `size` (bytes), `files`,
`bytes` (site data), `changed`, `skipped`, `warnings` (count), `seconds`, `base` (the full backup a
changes-only backup needs, or `''`). Sending to a storage, if chosen, comes after this.

```php
add_action( 'siteferry_export_done', function ( $job, $result ) {
	error_log( 'Backup ready: ' . $result['file'] );
}, 10, 2 );
```

### `siteferry_import_started`

`do_action( 'siteferry_import_started', Job $job )`

A restore (or "move it here") job was created. It checks the backup next and then waits for
confirmation; nothing on the site has changed yet. `$job->option( 'archive' )` is the backup file.

### `siteferry_import_done`

`do_action( 'siteferry_import_done', Job $job, array $imported )`

A restore finished and the site was switched over. `$imported`: `from`, `to` (addresses), `tables`,
`rows`, `files`, `partial` (what a partial restore put back, or `''`), `rows_changed`, `warnings`,
`seconds`. The site now has the backup's database: options and users are the backup's.

### `siteferry_backup_sent`

`do_action( 'siteferry_backup_sent', Job $job, array $sent )`

A backup reached one storage; with several storages it fires once per storage that got it.
`$sent`: `name` (as stored), `size`, `where` (label), `destination` (storage id: `s3`, `ftp`,
`gdrive`, `dropbox`, or `move` for a site-to-site move).

### `siteferry_job_failed`

`do_action( 'siteferry_job_failed', Job $job, string $error )`

Any SiteFerry job failed or was cancelled, after its cleanup ran (a failed restore has already put
the previous site back).

## Filters

### `siteferry_export_exclusions`

`apply_filters( 'siteferry_export_exclusions', string[] $paths, array $options )`

Paths inside wp-content a backup leaves out, such as `uploads/2019` or `cache`. `*` matches any
characters within one folder name. Values that are not strings, contain `..` or are absolute are
dropped. SiteFerry's own plugin and storage folders, WordPress's upgrade folders and other backup
plugins' folders are always left out, whatever the filter returns. Staging copies use the same rules.

```php
add_filter( 'siteferry_export_exclusions', function ( $paths ) {
	$paths[] = 'uploads/big-videos';
	return $paths;
} );
```

### `siteferry_manifest_extra`

`apply_filters( 'siteferry_manifest_extra', array $extra, Job $job )`

Your own data, stored in the backup's manifest (`siteferry.json`) under `extra`. Use your plugin's
slug as the key (letters, digits, `_`, `-`; up to 64 characters). Each value must be
JSON-encodable and at most 64 KB; other entries are dropped. Read it back with
`SiteFerry\Backups::manifest( $file )['extra']['your-slug']`.

```php
add_filter( 'siteferry_manifest_extra', function ( $extra ) {
	$extra['my-shop'] = array( 'orders' => (int) wp_count_posts( 'shop_order' )->publish );
	return $extra;
} );
```

### `siteferry_backup_name_label`

`apply_filters( 'siteferry_backup_name_label', string $label, string $home )`

The site part of a backup file name (`<label>-<YYYY-MM-DD>-<random>.tar`). The result is reduced to
lower-case letters, digits and hyphens, at most 40 characters; an empty result keeps the default.

### Other filters

- `siteferry_move_export_options( array $options )`: the export options of a site-to-site move.
- `siteferry_update_backup_seconds( float $seconds, array $item )`: time limit of the quick backup
  before an update or risky change.
- `siteferry_relay_url( string $url )`: the one-click sign-in service (default
  `https://auth.xsofty.com/siteferry`).
- `siteferry_move_to_self( bool $allow )`, `siteferry_gdrive_endpoints( array )`,
  `siteferry_dropbox_endpoints( array )`: for tests.

## WP-CLI output

- Commands that list or report something take `--format=table|csv|json|yaml|count` where a list
  makes sense (`backups list`, `storage list`, `activity`; `schedule show` and `staging status`
  take table, json or yaml; `space` and `backups compare` take table or json). Outside a table,
  sizes are bytes.
- Commands that do something (`export`, `import`, `move`, `receive`, `backups check|delete|recheck`,
  `storage test|send|fetch|delete`, `schedule run`, `staging create|delete`) take `--porcelain`
  (print only the key result) and `--json` (one JSON object). WP-CLI turns `--json` into
  `--format=json`; on `export`, whose `--format` also takes tar or wpress, `--archive=wpress` then
  chooses the kind of file.
- With `--json` or `--porcelain` nothing else is printed (warnings go to STDERR). A failure with
  `--json` prints `{"ok": false, "error": "..."}` on STDOUT.
- A command that asks for confirmation (`import`, `backups delete`, `storage delete`,
  `staging delete`) needs `--yes` together with `--json` or `--porcelain`.
- Exit code: `0` on success, `1` on any error.
- Shared field names: `name` (backup file name), `path` (absolute), `size` (bytes), `created` (UTC,
  ISO 8601), `place` (`local` or a storage id), `where` (a storage's label), `status`, `warnings`.

JSON object of an action:

```json
{
  "ok": true,
  "name": "example-com-2026-11-10-1a2b3c4d5e6f.tar",
  "path": "/var/www/wp-content/siteferry-.../backups/example-com-2026-11-10-1a2b3c4d5e6f.tar",
  "size": 4194304,
  "warnings": []
}
```
