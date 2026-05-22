# Migrator

A WordPress plugin to migrate sites between environments. Package the database and uploads into a single `.zip` archive, then restore it on another WordPress install — with safe URL rewriting (including serialized data).

## Features

- **Chunked AJAX** export and import with live progress bar, per-phase status, and cancel button
- **Streamed DB dump** (`fwrite` + paginated `SELECT … LIMIT … OFFSET …`) — memory footprint stays low even on large databases
- **Selective inclusion** via a profile form: database, uploads, themes, plugins, must-use plugins
- **Database exclusions**: skip spam comments, post revisions, trashed posts, transients (`_transient_%`)
- **Custom file exclusions**: `fnmatch` glob patterns and plain directory prefixes (e.g. `uploads/cache/`)
- **Chunked archive upload** during import (works around `upload_max_filesize` / `post_max_size`)
- **Safe URL rewriting** — handles PHP-serialized strings by recomputing the `s:N:` byte prefix
- **GitHub-based update checker** (PUC v5)
- Admin-only: `manage_options` capability + WordPress nonces; jobs are scoped to the creating user

## Requirements

- WordPress 5.8+
- PHP 7.4+
- `ext-zip` (`ZipArchive`)

## Installation

1. Clone or download this repository into `wp-content/plugins/migrator/`:
   ```bash
   git clone https://github.com/sipos-digital/migrator.git wp-content/plugins/migrator
   ```
2. In the WP admin, activate **Migrator** on the *Plugins* screen.
3. Use the **Migrator** menu item to export or import.

## Usage

### Export

1. Go to **Migrator → Export**.
2. Tick the things you want to include — database, uploads, themes, plugins, mu-plugins.
3. Optionally tick database exclusions (spam, revisions, trash, transients) and/or add custom file glob patterns to exclude.
4. Click **Start Export**. The page switches to a live progress UI:
   - **Phase:** `init` → `db_schema` → `db_data` → `db_attach` → `files` → `finalize` → `done`
   - Cancel at any time — the working directory is cleaned up.
5. When the archive is ready, click **Download Archive**. The job is destroyed automatically after download.

### Import

1. On the target site, install and activate Migrator.
2. Go to **Migrator → Import**, pick your `.zip`, confirm the **New Site URL**.
3. Click **Start Import**. Flow:
   - Archive is **chunked-uploaded** to `wp-content/uploads/migrator/job-<id>/upload.zip` in 5 MB slices
   - **Validate** manifest
   - **Extract** entries in batches
   - **db_restore** — applies SQL statements with URL rewriting (incl. serialized values)
   - **files_copy** — copies extracted files back to `uploads/`, `themes/`, `plugins/`, `mu-plugins/`
   - **finalize** — cleans up the working directory

> **Warning:** Import overwrites the current database and matching directories. Take a backup first.

## How it works (architecture)

Migrator drives long-running operations through a **job state machine** persisted to `wp-content/uploads/migrator/job-<id>/state.json`. The browser polls a single AJAX endpoint that advances one chunk per request:

```
JS:  while (phase !== 'done')
       snapshot = await fetch('admin-ajax.php?action=migrator_export_step', { job_id })
       updateProgressBar(snapshot.overall_progress)
       updateLabel(snapshot.label)
```

Each step reads `state.json`, processes one batch (e.g. 500 DB rows or 50 files), writes the new state, and returns a snapshot. Defaults are:

| Batch | Default | Configurable via |
|---|---|---|
| DB rows per step | 500 | `Migrator_Exporter::DB_BATCH_ROWS` |
| Files per step | 50 | `Migrator_Exporter::FILE_BATCH` |
| Import statements per step | 200 | `Migrator_Importer::DB_BATCH_ROWS` |
| Upload chunk size | 5 MB | `MigratorConfig.chunkKb` in PHP |
| Poll delay | 250 ms | `MigratorConfig.pollMs` in PHP |

The archive itself is built incrementally by reopening the same `output.zip` across requests — `ZipArchive::open($path)` without the `CREATE` flag preserves existing entries.

## What is *not* included (yet)

- WP-CLI command (`wp migrator export …`)
- Periodic/scheduled exports via WP-Cron
- Cloud storage destinations (S3, Dropbox, etc.)
- Resumable uploads across page reloads (state survives, but the JS loop has to be restarted manually)

## Updates from GitHub

Migrator ships with [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) (vendored in `lib/plugin-update-checker/`). WordPress will check this repository for updates and surface them on the **Plugins** screen just like a wp.org plugin.

### Publishing a new version

1. Bump the `Version:` header in `migrator.php` (e.g. `0.1.0` → `0.2.0`) and `Stable tag:` in `readme.txt`.
2. Commit and push to `main`.
3. Create a GitHub release with a tag matching the version (prefixed with `v`, e.g. `v0.2.0`):
   ```bash
   gh release create v0.2.0 --title "v0.2.0" --notes "Release notes here"
   ```
4. Users will see the update on their WP admin within ~12 hours (or sooner if they hit *Check again* on the Updates screen).

If you want to ship a custom-packaged plugin zip (e.g. without dev files), attach it as a release asset — `enableReleaseAssets()` is on, so the asset will be preferred over the auto-generated source zip.

## Development

```
migrator/
├── migrator.php                          # Plugin bootstrap + PUC update checker
├── uninstall.php                         # Cleanup on delete
├── includes/
│   ├── class-migrator-profile.php        # Inclusion/exclusion config
│   ├── class-migrator-job.php            # Job lifecycle + state.json persistence
│   ├── class-migrator-exporter.php       # Phase-based export state machine
│   ├── class-migrator-importer.php       # Phase-based import state machine
│   ├── class-migrator-ajax.php           # admin-ajax router (start / step / cancel / download)
│   └── class-migrator-admin.php          # Menu, asset enqueuing
├── admin/
│   ├── dashboard.php                     # Environment summary
│   ├── export.php                        # Profile form + progress UI
│   └── import.php                        # Chunked upload + progress UI
├── assets/
│   ├── admin.css                         # Progress bar, fieldsets, cards
│   └── admin.js                          # State-machine loop, chunked upload
└── lib/plugin-update-checker/            # Vendored YahnisElsts/plugin-update-checker
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
