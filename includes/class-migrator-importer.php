<?php
/**
 * Importer — phase-based archive restorer.
 *
 * Phases:
 *   init        →  validate uploaded archive, prepare manifest
 *   extract     →  unpack zip in batches of FILE_BATCH entries
 *   db_restore  →  execute SQL statements in batches of DB_BATCH_ROWS
 *   files_copy  →  copy extracted files into their destinations in batches
 *   finalize    →  cleanup
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Importer {

	const DB_BATCH_ROWS = 500;   // statements per step — each statement is now a multi-row INSERT, so this is many more rows than the old per-row layout
	const FILE_BATCH    = 200;
	const STEP_BUDGET   = 15.0;  // hard cap on wall-clock per step; well under PHP-FPM's default request_terminate_timeout

	private Migrator_Job $job;

	public function __construct( Migrator_Job $job ) {
		$this->job = $job;
	}

	public function step(): array {
		$phase = $this->job->state['phase'] ?? 'init';

		// Enable PCRE JIT for this request. Herd ships with `pcre.jit=0` in
		// php.ini, which makes preg_replace_callback an order of magnitude
		// slower on the URL+prefix rewrite of a multi-MB SQL dump (~10x).
		// pcre.jit is INI_ALL on PHP 8.x; the previous value is restored
		// automatically when the request ends.
		@ini_set( 'pcre.jit', '1' );

		try {
			switch ( $phase ) {
				case 'init':
					$this->phase_init();
					break;
				case 'extract':
					$this->phase_extract();
					break;
				case 'sql_rewrite':
					$this->phase_sql_rewrite();
					break;
				case 'db_restore':
					$this->phase_db_restore();
					break;
				case 'files_copy':
					$this->phase_files_copy();
					break;
				case 'finalize':
					$this->phase_finalize();
					break;
				case 'done':
					break;
				default:
					throw new RuntimeException( sprintf( 'Unknown phase: %s', $phase ) );
			}
		} catch ( Throwable $e ) {
			$this->job->state['phase'] = 'error';
			$this->job->state['label'] = $e->getMessage();
			$this->job->save();
			throw $e;
		}

		$this->job->save();
		return $this->job->snapshot();
	}

	private function phase_init(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'The ZipArchive PHP extension is required.', 'migrator' ) );
		}

		$archive = $this->job->archive_path();
		if ( ! file_exists( $archive ) ) {
			throw new RuntimeException( __( 'Uploaded archive not found.', 'migrator' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) ) {
			throw new RuntimeException( __( 'Could not open archive.', 'migrator' ) );
		}

		// Locate the manifest. It may be at the archive root (our own exports) or
		// nested under a wrapper directory if the archive was re-zipped by macOS
		// Finder (which also adds a __MACOSX/ resource fork tree).
		$manifest_name = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );
			if ( false === $entry ) {
				continue;
			}
			if ( 0 === strpos( $entry, '__MACOSX/' ) ) {
				continue;
			}
			if ( basename( $entry ) === Migrator_Exporter::MANIFEST_FILE ) {
				$manifest_name = $entry;
				break;
			}
		}

		if ( null === $manifest_name ) {
			$contents = array();
			$count    = min( 30, $zip->numFiles );
			for ( $i = 0; $i < $count; $i++ ) {
				$contents[] = $zip->getNameIndex( $i );
			}
			$more = $zip->numFiles > $count ? sprintf( ' (and %d more)', $zip->numFiles - $count ) : '';
			$zip->close();
			throw new RuntimeException(
				sprintf(
					/* translators: 1: list of archive entries, 2: '… and N more' suffix or empty */
					__( 'Archive is missing migrator-manifest.json. The archive contains: %1$s%2$s. This usually means the archive was created by a different tool, or by an older version of Migrator (pre-0.2.1) that did not write the manifest correctly. Re-export the source site with this plugin version and try again.', 'migrator' ),
					implode( ', ', $contents ) ?: '(no entries)',
					$more
				)
			);
		}

		$manifest_raw = $zip->getFromName( $manifest_name );
		$manifest     = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['site_url'] ) ) {
			$zip->close();
			throw new RuntimeException( __( 'Manifest is invalid.', 'migrator' ) );
		}

		// Cross-prefix imports are supported by rewriting the source prefix
		// to the target prefix in the SQL dump (see after_extract). Both
		// table identifiers in backticks and prefix-prefixed option_name
		// values like `nast_2642024a_user_roles` get rewritten.
		global $wpdb;

		// Prefix = wrapper directory inside the zip (empty string for archives
		// produced by Migrator itself; "migrator-foo-20260522-202439/" for
		// archives re-zipped by macOS Finder).
		$archive_prefix = substr( $manifest_name, 0, -strlen( Migrator_Exporter::MANIFEST_FILE ) );

		$total_entries = $zip->numFiles;
		$zip->close();

		$extract_dir = $this->job->dir() . '/extract';
		wp_mkdir_p( $extract_dir );

		$data = $this->job->state['data'];
		$data['manifest']        = $manifest;
		$data['extract_dir']     = $extract_dir;
		$data['archive_prefix']  = $archive_prefix;
		$data['total_entries']   = $total_entries;
		$data['entry_index']     = 0;
		$data['sql_offset']      = 0;
		$data['sql_total']       = 0;
		$data['file_index']      = 0;
		$data['files_to_copy']   = array();
		$data['new_url']         = $data['new_url'] ?? untrailingslashit( get_site_url() );

		$this->job->set_phase( 'extract', $data );
		$this->job->update_progress( 0.02, __( 'Archive validated', 'migrator' ), 1.0 );
	}

	private function phase_extract(): void {
		$data          = $this->job->state['data'];
		$total_entries = (int) $data['total_entries'];
		$entry_index   = (int) $data['entry_index'];

		if ( $entry_index >= $total_entries ) {
			$this->after_extract( $data );
			return;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $this->job->archive_path() ) ) {
			throw new RuntimeException( __( 'Could not open archive during extract.', 'migrator' ) );
		}

		$processed = 0;
		$entries   = array();
		while ( $processed < self::FILE_BATCH && ( $entry_index + $processed ) < $total_entries ) {
			$name = $zip->getNameIndex( $entry_index + $processed );
			$processed++;
			if ( false === $name ) {
				continue;
			}
			// Skip macOS resource forks and Finder metadata — they're never useful.
			if ( 0 === strpos( $name, '__MACOSX/' ) || '.DS_Store' === basename( $name ) ) {
				continue;
			}
			$entries[] = $name;
		}

		if ( ! empty( $entries ) && ! $zip->extractTo( $data['extract_dir'], $entries ) ) {
			$zip->close();
			throw new RuntimeException( __( 'Failed to extract archive entries.', 'migrator' ) );
		}
		$zip->close();

		$data['entry_index']      = $entry_index + $processed;
		$this->job->state['data'] = $data;

		$overall = 0.02 + 0.28 * ( $data['entry_index'] / max( 1, $total_entries ) );
		$this->job->update_progress(
			$overall,
			sprintf( __( 'Extracting (%1$d / %2$d)', 'migrator' ), $data['entry_index'], $total_entries ),
			$data['entry_index'] / max( 1, $total_entries )
		);

		if ( $data['entry_index'] >= $total_entries ) {
			$this->after_extract( $data );
		}
	}

	private function after_extract( array $data ): void {
		// Archives re-zipped by macOS Finder land everything under a wrapper
		// directory inside the extract dir. Resolve through that wrapper if
		// present so the subsequent phases see the same layout regardless.
		$base = $data['extract_dir'];
		if ( ! empty( $data['archive_prefix'] ) ) {
			$base = trailingslashit( $base ) . rtrim( $data['archive_prefix'], '/' );
		}
		$data['archive_base'] = $base;

		// Prepare URL-rewritten SQL file now so we don't have to re-read the
		// manifest later. Files are copied BEFORE the DB is restored — otherwise
		// the new wp_options.active_plugins points at plugin files that the
		// importer hasn't copied yet, and the next AJAX request fatals trying
		// to include them.
		$sql_file = $base . '/' . Migrator_Exporter::DB_FILE;
		if ( file_exists( $sql_file ) ) {
			global $wpdb;
			$manifest = (array) $data['manifest'];

			$rewrites = array();

			// URL rewrite: source site URL → target site URL.
			$old_url = untrailingslashit( (string) $manifest['site_url'] );
			$new_url = untrailingslashit( (string) $data['new_url'] );
			if ( $old_url !== $new_url ) {
				$rewrites[] = array( 'old' => $old_url, 'new' => $new_url, 'serialized' => true );
			}

			// Prefix rewrite: source $table_prefix → target $wpdb->prefix.
			// Rewrites both table identifiers in backticks (`old_X` → `new_X`)
			// and prefix-keyed option_name values ('old_user_roles' →
			// 'new_user_roles'). With serialized=true we also fix s:N: byte
			// lengths if the prefix happens to appear inside serialized data.
			$source_prefix = (string) ( $manifest['table_prefix'] ?? '' );
			$target_prefix = (string) $wpdb->prefix;
			if ( '' !== $source_prefix && $source_prefix !== $target_prefix ) {
				$rewrites[] = array( 'old' => $source_prefix, 'new' => $target_prefix, 'serialized' => true );
			}

			$data['has_sql'] = true;

			if ( ! empty( $rewrites ) ) {
				// Hand off to phase_sql_rewrite. The rewrite is its own phase
				// instead of a synchronous call in after_extract because the
				// dump can be 100+ MB and a single sync pass blows past
				// PHP-FPM's request_terminate_timeout (→ nginx 502).
				$data['sql_file']       = $sql_file;
				$data['rewrites']       = $rewrites;
				$data['rewrite_offset'] = 0;
				$data['rewrite_total']  = filesize( $sql_file );
				$this->job->set_phase( 'sql_rewrite', $data );
				$this->job->update_progress( 0.31, __( 'Rewriting URLs and table prefix', 'migrator' ), 0.0 );
				return;
			}

			$data['sql_total']  = filesize( $sql_file );
			$data['sql_offset'] = 0;
		} else {
			$data['has_sql'] = false;
		}

		$this->prepare_files_copy_phase( $data );
	}

	private function phase_sql_rewrite(): void {
		$data     = $this->job->state['data'];
		$sql_file = (string) ( $data['sql_file'] ?? '' );
		$rewrites = (array) ( $data['rewrites'] ?? array() );
		$offset   = (int) ( $data['rewrite_offset'] ?? 0 );
		$total    = max( 1, (int) ( $data['rewrite_total'] ?? 0 ) );

		if ( '' === $sql_file || ! file_exists( $sql_file ) ) {
			throw new RuntimeException( __( 'SQL file disappeared before rewrite.', 'migrator' ) );
		}

		$tmp = $sql_file . '.rewriting';
		$in  = fopen( $sql_file, 'r' );
		$out = fopen( $tmp, 0 === $offset ? 'w' : 'a' );
		if ( false === $in || false === $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			throw new RuntimeException( __( 'Could not open SQL files for rewriting.', 'migrator' ) );
		}

		if ( $offset > 0 ) {
			fseek( $in, $offset );
		}

		$start = microtime( true );
		$lines = 0;
		while ( false !== ( $line = fgets( $in ) ) ) {
			foreach ( $rewrites as $r ) {
				if ( ! empty( $r['serialized'] ) ) {
					$line = $this->rewrite_serialized( $line, $r['old'], $r['new'] );
				}
				$line = str_replace( $r['old'], $r['new'], $line );
			}
			fwrite( $out, $line );
			$lines++;

			// Check time budget every line. Individual lines can be ~1 MB
			// (multi-row INSERT cap) and rewrite_serialized on them is
			// non-trivial — checking only every Nth line means a single slow
			// line can keep us past PHP-FPM's request_terminate_timeout
			// without ever triggering the budget. microtime() per line is
			// ~100 ns, dwarfed by the regex cost on any line that matters.
			if ( ( microtime( true ) - $start ) > self::STEP_BUDGET ) {
				break;
			}
		}

		$new_offset = ftell( $in );
		$eof        = feof( $in );
		fclose( $in );
		fclose( $out );

		if ( $eof ) {
			if ( ! rename( $tmp, $sql_file ) ) {
				@unlink( $tmp );
				throw new RuntimeException( __( 'Could not finalize SQL rewriting.', 'migrator' ) );
			}
			unset( $data['rewrites'], $data['sql_file'], $data['rewrite_offset'], $data['rewrite_total'] );
			$data['sql_total']  = filesize( $sql_file );
			$data['sql_offset'] = 0;
			$this->prepare_files_copy_phase( $data );
			return;
		}

		$data['rewrite_offset']   = $new_offset;
		$this->job->state['data'] = $data;

		$ratio = $new_offset / $total;
		$this->job->update_progress(
			0.31 + 0.04 * $ratio,
			sprintf(
				/* translators: 1: MB processed, 2: total MB */
				__( 'Rewriting SQL dump (%1$s / %2$s MB)', 'migrator' ),
				number_format_i18n( $new_offset / 1048576, 1 ),
				number_format_i18n( $total / 1048576, 1 )
			),
			$ratio
		);
	}

	private function phase_db_restore(): void {
		global $wpdb;
		$data       = $this->job->state['data'];

		$sql_file   = ( $data['archive_base'] ?? $data['extract_dir'] ) . '/' . Migrator_Exporter::DB_FILE;
		$sql_total  = (int) $data['sql_total'];
		$sql_offset = (int) $data['sql_offset'];

		$handle = fopen( $sql_file, 'r' );
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Could not open SQL dump for reading.', 'migrator' ) );
		}
		if ( $sql_offset > 0 ) {
			fseek( $handle, $sql_offset );
		}

		// Tables we never touch — keeps the operator's account, password, and
		// session tokens intact across the import (so they stay logged in) and
		// avoids ID collisions on wp_users.PRIMARY when the source dump
		// re-inserts user ID 1.
		$protected_tables = array(
			$wpdb->users,
			$wpdb->usermeta,
		);

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

		$start      = microtime( true );
		$statements = 0;
		$skipped    = 0;
		$buffer     = '';
		while ( $statements < self::DB_BATCH_ROWS
			&& ( microtime( true ) - $start ) < self::STEP_BUDGET
			&& false !== ( $line = fgets( $handle ) ) ) {
			$buffer .= $line;
			if ( preg_match( '/;\s*$/', $line ) ) {
				$statement = trim( $buffer );
				$buffer    = '';
				if ( '' === $statement || 0 === strpos( $statement, '--' ) ) {
					continue;
				}
				if ( $this->statement_targets_table( $statement, $protected_tables ) ) {
					$skipped++;
					$statements++;
					continue;
				}
				$result = $wpdb->query( $statement );
				if ( false === $result ) {
					$err = $wpdb->last_error;
					fclose( $handle );
					$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
					throw new RuntimeException( sprintf( /* translators: %s: MySQL error */ __( 'SQL error: %s', 'migrator' ), $err ) );
				}
				$statements++;
			}
		}

		$new_offset = ftell( $handle );
		$eof        = feof( $handle );
		fclose( $handle );

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

		$data['sql_offset'] = $new_offset;

		if ( $eof ) {
			$this->job->set_phase( 'finalize', $data );
			$this->job->update_progress( 0.95, __( 'Database restored', 'migrator' ), 1.0 );
			return;
		}

		$this->job->state['data'] = $data;
		$overall = 0.65 + 0.3 * ( $new_offset / max( 1, $sql_total ) );
		$this->job->update_progress(
			$overall,
			sprintf( __( 'Restoring database (%d statements applied, %d skipped)', 'migrator' ), $statements - $skipped, $skipped ),
			$new_offset / max( 1, $sql_total )
		);
	}

	/**
	 * Inspect a SQL statement and return true if its target table is in $tables.
	 * Recognises the DDL/DML our exporter emits: DROP TABLE, CREATE TABLE,
	 * INSERT INTO, plus UPDATE / DELETE / LOCK / UNLOCK for defence in depth.
	 */
	private function statement_targets_table( string $statement, array $tables ): bool {
		if ( ! preg_match( '/^\s*(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|LOCK\s+TABLES|UNLOCK\s+TABLES|ALTER\s+TABLE|TRUNCATE\s+TABLE)\s+`?([^`\s(;]+)`?/i', $statement, $m ) ) {
			return false;
		}
		return in_array( $m[1], $tables, true );
	}

	private function prepare_files_copy_phase( array $data ): void {
		$copy_map = $this->build_copy_map( $data['archive_base'] ?? $data['extract_dir'] );
		file_put_contents( $this->job->files_list_path(), implode( "\n", $copy_map['lines'] ) );
		$data['total_copy'] = $copy_map['count'];
		$data['file_index'] = 0;

		if ( 0 === $copy_map['count'] ) {
			// Skip straight to db_restore when there are no files to copy.
			if ( ! empty( $data['has_sql'] ) ) {
				$this->job->set_phase( 'db_restore', $data );
				$this->job->update_progress( 0.65, __( 'Restoring database', 'migrator' ), 0.0 );
			} else {
				$this->job->set_phase( 'finalize', $data );
				$this->job->update_progress( 0.95, __( 'Nothing to do', 'migrator' ), 1.0 );
			}
			return;
		}

		$this->job->set_phase( 'files_copy', $data );
		$this->job->update_progress( 0.15, __( 'Copying files', 'migrator' ), 0.0 );
	}

	private function phase_files_copy(): void {
		$data       = $this->job->state['data'];
		$file_index = (int) $data['file_index'];
		$total_copy = (int) $data['total_copy'];

		if ( $file_index >= $total_copy ) {
			// Files are in place — now safe to restore the DB (which will swap
			// active_plugins to the source's set, pointing at files we just copied).
			if ( ! empty( $data['has_sql'] ) ) {
				$this->job->set_phase( 'db_restore', $data );
				$this->job->update_progress( 0.65, __( 'Restoring database', 'migrator' ), 0.0 );
			} else {
				$this->job->set_phase( 'finalize', $data );
				$this->job->update_progress( 0.95, __( 'All files copied', 'migrator' ), 1.0 );
			}
			return;
		}

		$handle = fopen( $this->job->files_list_path(), 'r' );
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Could not read copy list.', 'migrator' ) );
		}
		for ( $i = 0; $i < $file_index; $i++ ) {
			if ( false === fgets( $handle ) ) {
				break;
			}
		}

		$start     = microtime( true );
		$processed = 0;
		while ( $processed < self::FILE_BATCH
			&& ( microtime( true ) - $start ) < self::STEP_BUDGET
			&& false !== ( $line = fgets( $handle ) ) ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			list( $src, $dst ) = explode( '|', $line, 2 );
			$this->ensure_dir( dirname( $dst ) );
			if ( file_exists( $src ) && is_file( $src ) ) {
				@copy( $src, $dst );
			}
			$processed++;
		}
		fclose( $handle );

		$data['file_index']      = $file_index + $processed;
		$this->job->state['data'] = $data;

		$overall = 0.15 + 0.5 * ( $data['file_index'] / max( 1, $total_copy ) );
		$this->job->update_progress(
			$overall,
			sprintf( __( 'Copying files (%1$d / %2$d)', 'migrator' ), $data['file_index'], $total_copy ),
			$data['file_index'] / max( 1, $total_copy )
		);
	}

	private function phase_finalize(): void {
		$data = $this->job->state['data'];

		// Cleanup extract dir + intermediate files.
		if ( ! empty( $data['extract_dir'] ) && is_dir( $data['extract_dir'] ) ) {
			$this->rrmdir( $data['extract_dir'] );
		}
		if ( file_exists( $this->job->files_list_path() ) ) {
			@unlink( $this->job->files_list_path() );
		}

		$this->job->set_phase( 'done', array() );
		$this->job->update_progress( 1.0, __( 'Import complete', 'migrator' ), 1.0 );
	}

	private function build_copy_map( string $extract_base ): array {
		// Map each top-level directory in extract/ to its destination on disk.
		$destinations = array(
			'uploads'    => wp_upload_dir()['basedir'],
			'themes'     => WP_CONTENT_DIR . '/themes',
			'plugins'    => WP_PLUGIN_DIR,
			'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
		);

		$lines = array();
		foreach ( $destinations as $label => $dest_root ) {
			$source_root = $extract_base . '/' . $label;
			if ( ! is_dir( $source_root ) ) {
				continue;
			}
			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_root, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$absolute = $file->getPathname();
				// Skip macOS resource forks if they slipped past the extract filter.
				if ( '.DS_Store' === $file->getBasename() || false !== strpos( $absolute, '/__MACOSX/' ) ) {
					continue;
				}
				$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $source_root ) ) ), '/' );
				// Never overwrite the running Migrator plugin with a copy from the archive —
				// it would clobber the running PHP and crash mid-import.
				if ( 'plugins' === $label && ( 'migrator' === $relative || 0 === strpos( $relative, 'migrator/' ) ) ) {
					continue;
				}
				$lines[]  = $absolute . '|' . trailingslashit( $dest_root ) . $relative;
			}
		}
		return array(
			'lines' => $lines,
			'count' => count( $lines ),
		);
	}

	private function ensure_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}

	/**
	 * Apply one or more search-replace passes to the SQL dump.
	 *
	 * Each entry in $rewrites: { 'old' => string, 'new' => string, 'serialized' => bool }
	 *
	 * Streams the file line by line into a tmp file then renames over the
	 * original. Per-line processing bounds both memory use and regex scope —
	 * each INSERT in our own dumps lives on a single line, so serialized
	 * blobs never get split mid-stream.
	 */
	private function rewrite_sql_file( string $path, array $rewrites ): void {
		$rewrites = array_values(
			array_filter(
				$rewrites,
				fn( $r ) => ! empty( $r['old'] ) && $r['old'] !== ( $r['new'] ?? '' )
			)
		);
		if ( empty( $rewrites ) ) {
			return;
		}

		$tmp = $path . '.tmp';
		$in  = fopen( $path, 'r' );
		$out = fopen( $tmp, 'w' );
		if ( false === $in || false === $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			throw new RuntimeException( __( 'Could not open SQL dump for rewriting.', 'migrator' ) );
		}

		while ( false !== ( $line = fgets( $in ) ) ) {
			foreach ( $rewrites as $r ) {
				if ( ! empty( $r['serialized'] ) ) {
					$line = $this->rewrite_serialized( $line, $r['old'], $r['new'] );
				}
				$line = str_replace( $r['old'], $r['new'], $line );
			}
			fwrite( $out, $line );
		}

		fclose( $in );
		fclose( $out );

		if ( ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			throw new RuntimeException( __( 'Could not finalize SQL rewriting.', 'migrator' ) );
		}
	}

	/**
	 * Replace $old with $new inside PHP-serialized string headers (s:N:"...").
	 * The possessive *+ quantifier prevents pathological backtracking when an
	 * INSERT contains many escaped quotes; if the engine still aborts (huge
	 * lines, JIT stack, malformed UTF-8), we return the line untouched and
	 * let the subsequent plain str_replace pick up URLs outside serialized
	 * values.
	 */
	private function rewrite_serialized( string $sql, string $old, string $new ): string {
		$pattern = '/s:(\d+):\\\\"((?:[^"\\\\]|\\\\.)*+)\\\\"/';
		$result  = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $old, $new ) {
				$value = stripslashes( $matches[2] );
				if ( false === strpos( $value, $old ) ) {
					return $matches[0];
				}
				$replaced = str_replace( $old, $new, $value );
				$escaped  = addslashes( $replaced );
				return 's:' . strlen( $replaced ) . ':\\"' . $escaped . '\\"';
			},
			$sql
		);
		if ( null === $result ) {
			error_log( '[Migrator] rewrite_serialized: preg_replace_callback failed (PCRE error ' . preg_last_error() . '), falling back to plain str_replace for this segment.' );
			return $sql;
		}
		return $result;
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}
		@rmdir( $dir );
	}
}
