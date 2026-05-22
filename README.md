# Migrator

A WordPress plugin to migrate sites between environments. Package the database and uploads into a single `.zip` archive, then restore it on another WordPress install — with safe URL rewriting (including serialized data).

## Features

- One-click export of the WordPress database (tables sharing the configured prefix)
- Bundles the uploads directory into the same archive
- Restores onto another installation with URL rewriting
- Handles PHP-serialized values when replacing URLs
- Admin-only: protected by the `manage_options` capability and WordPress nonces

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
2. Click **Download Archive**.
3. A `.zip` file containing `database.sql`, the `uploads/` directory, and a manifest is downloaded.

### Import

1. On the target site, install and activate Migrator.
2. Go to **Migrator → Import**.
3. Upload the archive.
4. Confirm the **New Site URL** (defaults to the current site URL).
5. Click **Run Import**.

The importer will:

- Extract the archive
- Drop and recreate WordPress tables from the dump
- Rewrite URLs (including in serialized values)
- Copy uploads into `wp-content/uploads/`

> **Warning:** Import overwrites the current database. Always take a backup first.

## What is *not* included (yet)

- Theme and plugin files (use git/composer to deploy code)
- Chunked/streamed export for very large sites
- Background job processing (everything runs in the request)
- CLI command (WP-CLI integration)

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

The plugin follows the standard WordPress structure:

```
migrator/
├── migrator.php                 # Plugin bootstrap
├── uninstall.php                # Cleanup on delete
├── includes/
│   ├── class-migrator-admin.php
│   ├── class-migrator-exporter.php
│   └── class-migrator-importer.php
├── admin/
│   ├── dashboard.php
│   ├── export.php
│   └── import.php
└── assets/
    ├── admin.css
    └── admin.js
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
