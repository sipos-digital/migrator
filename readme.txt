=== Migrator ===
Contributors: siposdigital
Tags: migration, backup, export, import, clone
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 0.7.2
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

= 0.7.2 =
* Fix: another nginx 502 in `sql_rewrite` on large dumps. Herd ships with `pcre.jit=0` in php.ini, which makes `preg_replace_callback` on the URL/prefix rewrite roughly 10x slower than with JIT enabled. The importer (and exporter) now run `ini_set( 'pcre.jit', '1' )` at the start of each AJAX step so the rewrite uses JIT for this one request without changing system config.
* Time-budget check now fires every line instead of every 8/64 lines. A single 1 MB multi-row INSERT was enough to keep us past PHP-FPM's `request_terminate_timeout` without ever triggering the previous coarse check.

= 0.7.1 =
* Fix: import step that finished the extract phase (and then synchronously ran the URL + table-prefix rewrite across the full SQL dump) could exceed PHP-FPM's `request_terminate_timeout` on multi-GB sites, producing an nginx 502. The rewrite now runs in its own resumable phase (`sql_rewrite`) between `extract` and `files_copy`, processing the file line-by-line with a 15-second wall-clock budget per AJAX step. Progress is reported as MB / total MB.
* Added the same 15-second time budget to `db_restore` and `files_copy` loops so individual steps can never overrun PHP-FPM regardless of how big a particular multi-row INSERT or a single uploaded file turns out to be.

= 0.7.0 =
* New: drop large archives into `wp-content/uploads/migrator/incoming/` via FTP / SCP / Finder and pick them from a dropdown on the Import screen. The browser's chunked HTTP upload phase is skipped entirely — the archive is symlinked (or copied as a fallback) into the job directory, and the original file in `incoming/` is preserved across the import. Multi-GB imports go from "many minutes over HTTP" to "instant". The dropdown lives next to the file input; if no pre-uploaded archive is selected, the browser upload behaves exactly as before.

= 0.6.0 =
* The importer now **remaps the table prefix** during import instead of refusing the import on mismatch. Previously you had to edit `$table_prefix` in `wp-config.php` on the target before running the import; now the SQL dump is streamed through an extra pass that rewrites `` `<source_prefix>X` `` → `` `<target_prefix>X` `` in table identifiers and `<source_prefix>X` → `<target_prefix>X` in prefix-keyed values (option_name like `<prefix>_user_roles`). Serialized PHP strings are length-corrected too. URL rewriting and prefix rewriting run in a single line-by-line pass over the dump so the cost is the same as before.

= 0.5.1 =
* Fix: upload chunk size is now capped at 1.5 MB regardless of PHP's `post_max_size`. PHP commonly allows 256 MB POSTs but nginx vhosts often limit `client_max_body_size` to 2 MB (Herd's default), so chunks sized purely from PHP limits got rejected by nginx with `413 Request Entity Too Large` before reaching PHP — surfacing in the browser as "JSON Parse error: Unrecognized token '<'". The ceiling is overridable via `define( 'MIGRATOR_CHUNK_BYTES_MAX', 8388608 );` in `wp-config.php` for sites with raised nginx limits.
* The importer now recognises HTTP 413 / nginx's "Request Entity Too Large" response body and shows an actionable error message instead of a cryptic JSON parse failure.

= 0.5.0 =
* New: "Skip database tables" textarea in the Export profile. Accepts one fnmatch glob per line (case-insensitive), matched against the full table name including the prefix. Skipped tables are removed before `list_tables()` returns, so they never reach `COUNT(*)`, `SHOW CREATE TABLE`, or the dump. Useful for wide, regeneratable plugin tables (Wordfence's `*wfFileMods` / `*wfHits` / `*wfBlocks*` / `*wfLogins` / `*wfSecurityEvents`, WooCommerce's `*woocommerce_sessions`, ActionScheduler's `*actionscheduler_logs`) which the target site will rebuild on its own.

= 0.4.1 =
* Fix: snapshot `MAX(pk)` per table at dump start and cap the cursor at that value. Without the cap, rows inserted during the export (e.g. by a concurrent Wordfence scan filling `wfFileMods` at 100k+ rows/minute) got pulled into the dump and the cursor chased a moving tail — the user saw 1.73M / 361k rows and growing. The COUNT(*) for the progress total now uses the same cap so the ratio is meaningful.
* Fix: progress bar no longer hits 100% mid-DB-phase. The old formula clamped overshoot to 1.0, which looked frozen. Ratio is now capped at 99% during db_data and the label reads "1.2M rows, more than initial estimate" if the count was off.

= 0.4.0 =
Performance pass for big e-shops (inspired by how All-in-One WP Migration is fast on multi-GB sites).

* Archive entries are now stored uncompressed (`ZipArchive::CM_STORE`) instead of DEFLATE. On media-heavy sites the archive was dominated by uncompressible JPEG/PNG/MP4 anyway; skipping DEFLATE moves the bottleneck from CPU to disk I/O — roughly 4-8x faster export and a slightly larger archive.
* DB dump now emits **multi-row INSERTs** (`INSERT INTO t VALUES (..), (..), (..);` capped at ~1 MB per statement instead of one INSERT per row). On import this cuts statement-parse overhead by ~100x.
* DB dump uses **cursor pagination** (`WHERE pk > last_pk ORDER BY pk LIMIT N`) instead of `LIMIT N OFFSET M` when a single-column primary key is detected. OFFSET is O(page * batch) so the 400 000th row on a million-row table was ~400x slower than the first. Cursor stays O(log N) regardless of position.
* Literal `\r` / `\n` in row values are now escaped to MySQL's `\r` / `\n` sequences so multi-row INSERTs always stay on one physical line — the importer's line-based parser depends on that.
* Batch sizes bumped now that each step does much more work per row: export 2000 rows/step + 200 files/step, import 500 statements/step + 200 files/step.

= 0.3.3 =
* Fix: archive download no longer fatals on large exports. `readfile()` was running with WP's accumulated output buffers and zlib compression still active, so multi-MB archives were buffered into memory and tripped `memory_limit`. The resulting PHP fatal produced the WordPress "critical error" HTML page, which the browser then saved with a `.zip` extension (because `Content-Disposition` was already set). The download is now streamed in 1 MB chunks with all output buffering, gzip, and mod_deflate explicitly disabled — flat memory profile regardless of archive size.

= 0.3.2 =
* Fix: import now copies files BEFORE restoring the database. Previously the DB restore replaced `wp_options.active_plugins` with the source's list, but the source's plugin files hadn't been copied to wp-content/plugins yet. The next AJAX request would load WordPress, see the new active_plugins, fail to include the plugin files (partially or entirely missing), and PHP would fatal mid-request — surfacing in the browser as "JSON Parse error: Unrecognized token '<'".
* Defensive: never overwrite the running Migrator plugin with a copy from the archive, even if a malformed export ever included it. (Our exporter already skips itself, but importing a corrupt third-party archive could still try.)
* Phase order is now: init → extract → files_copy → db_restore → finalize.

= 0.3.1 =
* Replace operator-preservation with a simpler, race-free approach: never touch the target site's `wp_users` and `wp_usermeta` tables during DB restore. The operator's account, password hash, capabilities, and session tokens are left exactly as they were on the target. No ID collisions (the previous "Duplicate entry '1' for key 'wp_users.PRIMARY'" error is gone), no logout mid-import. Source-site users are not migrated; posts authored by source users will fall back to WordPress's standard "unknown author" handling.
* Removed `snapshot_operator()` / `restore_operator()` and the on-disk `operator.json` snapshot file (no longer needed; nothing sensitive lingers in the job dir).

= 0.3.0 =
* Preserve the operator across import. Before the first DB restore step, snapshot the current user's wp_users row + all usermeta (including session_tokens) to operator.json inside the job dir. After every batch — and at finalize — re-insert the operator so they remain in wp_users with the same password hash, admin capabilities, and session tokens. Result: the user driving the import stays logged in and can continue clicking through the UI even though the source site's users replaced theirs.
* Refuse imports across mismatched $table_prefix. Surfaces a clear instruction to align wp-config.php instead of producing a half-broken site where the imported tables sit alongside the active ones.

= 0.2.6 =
* Fix: "rewrite_serialized(): Return value must be of type string, null returned" during the URL-rewriting step. The PCRE engine was hitting `pcre.backtrack_limit` on long INSERT lines containing many escaped quotes, causing `preg_replace_callback` to return null. Added a possessive quantifier to the serialized-data regex, switched the rewriter to stream the SQL dump line-by-line through a tmp file (so memory and regex scope stay bounded), and added defensive null handling that logs the PCRE error code and leaves the segment untouched instead of crashing the import.

= 0.2.5 =
* Fix: import accepts archives that were re-zipped by macOS Finder. Finder's "Compress" wraps everything in a single directory named after the archive and adds a __MACOSX/ resource fork tree, which previously caused "Archive is missing migrator-manifest.json". The importer now locates the manifest anywhere in the archive, detects the wrapper prefix, and strips it (plus __MACOSX/ and .DS_Store entries) on extract and copy.
* Better error message when the manifest is genuinely missing: lists up to 30 archive entries so you can see what was actually uploaded.

= 0.2.4 =
* Fix: import chunk size now derives from `post_max_size` / `upload_max_filesize` (with a 30% safety margin for FormData overhead) instead of a hard-coded 5 MB, which exceeded the default PHP limit of 2 MB and made every upload fail with a PHP warning that surfaced in Safari as a generic "string did not match the expected pattern" error.
* When the server still rejects a chunk for being too large (HTML "POST Content-Length exceeds the limit" warning), the importer now shows a clear message about raising PHP limits instead of a cryptic JSON parse error.
* MigratorConfig now exposes `postMax`, `uploadMax`, and `chunkBytes` for debugging.

= 0.2.3 =
* Diagnostics: log every AJAX request/response to the console (URL, action, HTTP status, content-type); register a global error/unhandledrejection listener; wrap fetch in try/catch with action-specific error messages so the failing step is visible.
* Defensive: fall back to `window.ajaxurl` if `MigratorConfig.ajaxUrl` is missing; drop the `accept` filter on the file input in case Safari's MIME-handling triggers spurious validation.

= 0.2.2 =
* Fix: "The string did not match the expected pattern." on import — caused by Safari's native HTML5 validation on the `type=url` field, which fires before our submit handler and cannot be cancelled by preventDefault. Switched the New Site URL field to `type=text`, added `novalidate` to both forms, and moved required-file checks to JS so we can show our own messages.
* Defensive: send a filename ("chunk.bin") with each upload chunk so `$_FILES['chunk']['name']` is populated on hosts that require it. Log uncaught import/export errors to the console for easier debugging.

= 0.2.1 =
* Fix: "Could not open archive to append database." — the empty zip created in the init phase was not written to disk by PHP's ZipArchive (a known PHP quirk on empty archives), so the next phase had nothing to open. Archive is now created lazily on the first append using the CREATE flag.

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
