=== Migrator ===
Contributors: siposdigital
Tags: migration, backup, export, import, clone
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Migrate WordPress sites between environments. Export your site (database + uploads) into a single archive and restore it on another installation.

== Description ==

Migrator packages your WordPress site into a single .zip archive that can be downloaded and restored on another WordPress installation. URLs are rewritten on import, including in PHP-serialized option values.

Features:

* Chunked AJAX export and import with live progress bar and cancel
* Streamed database dump (fwrite + paginated SELECT) — works on large sites
* Selective inclusion: database, uploads, themes, plugins, must-use plugins
* Database exclusions: skip spam, post revisions, trashed posts, transients
* Custom file glob exclusions (e.g. `uploads/cache/`)
* Safe URL rewriting on import — handles PHP-serialized strings
* Admin-only — protected by `manage_options` capability and nonces
* GitHub-based update checker

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/migrator/` or install via the WordPress plugins screen.
2. Activate the plugin.
3. Use the *Migrator* menu in the WP admin to export or import.

== Frequently Asked Questions ==

= Are themes and plugins included in the archive? =

Not in this version. Only the database and uploads directory are bundled.

= Will it overwrite my existing site on import? =

Yes — the import drops and recreates the WordPress tables and copies media files into `wp-content/uploads`. Back up first.

== Changelog ==

= 0.2.0 =
* Chunked AJAX export/import with live progress bar and cancel button
* Streamed DB dump via fwrite + paginated SELECT (no more memory blow-ups)
* Profile-based inclusion/exclusion: pick database/uploads/themes/plugins/mu-plugins
* Database exclusions: skip spam comments, post revisions, trashed posts, transients
* Custom file exclusion patterns (fnmatch + directory prefixes)
* Chunked archive upload during import (works around upload_max_filesize)
* New `Migrator_Job` state machine persisted to `wp-content/uploads/migrator/job-<id>/`

= 0.1.0 =
* Initial release: export and import with URL rewriting.
