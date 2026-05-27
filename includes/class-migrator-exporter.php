<?php
/**
 * Exporter — phase-based archive builder.
 *
 * The exporter is driven by Migrator_Ajax. Each call to step() advances the
 * job by one chunk:
 *
 *   init        →  prepare workspace, materialize file list and table list
 *   db_schema   →  write CREATE TABLE statements for all tables (one step)
 *   db_data     →  write INSERTs for one table batch (paginated)
 *   db_attach   →  add database.sql to the archive
 *   files       →  add a batch of files to the archive
 *   finalize    →  add manifest, close archive, expose download
 *   done        →  archive ready for download
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Exporter {

	const MANIFEST_FILE  = 'migrator-manifest.json';
	const DB_FILE              = 'database.sql';
	const DB_BATCH_ROWS        = 2000;
	const FILE_BATCH           = 200;
	const INSERT_TARGET_BYTES  = 1048576; // ~1 MB per multi-row INSERT — well under MySQL's 64 MB max_allowed_packet

	private Migrator_Job $job;

	public function __construct( Migrator_Job $job ) {
		$this->job = $job;
	}

	/**
	 * Advance the job by one chunk. Returns the job snapshot.
	 */
	public function step(): array {
		$phase = $this->job->state['phase'] ?? 'init';

		// Enable PCRE JIT for this request. Herd ships with `pcre.jit=0` —
		// big win if any future export-side code grows regex-heavy.
		@ini_set( 'pcre.jit', '1' );

		try {
			switch ( $phase ) {
				case 'init':
					$this->phase_init();
					break;
				case 'db_schema':
					$this->phase_db_schema();
					break;
				case 'db_data':
					$this->phase_db_data();
					break;
				case 'db_attach':
					$this->phase_db_attach();
					break;
				case 'files':
					$this->phase_files();
					break;
				case 'finalize':
					$this->phase_finalize();
					break;
				case 'done':
					// No-op; client should stop polling.
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
		$snapshot = $this->job->snapshot();
		if ( 'done' === $this->job->state['phase'] ) {
			$snapshot['download_url'] = $this->download_url();
		}
		return $snapshot;
	}

	private function phase_init(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'The ZipArchive PHP extension is required.', 'migrator' ) );
		}

		// Make sure any stale archive from a previous run is gone — first append
		// in a later phase will create a fresh zip.
		if ( file_exists( $this->job->archive_path() ) ) {
			@unlink( $this->job->archive_path() );
		}

		// Materialize the table list (only the ones we will dump).
		$tables = $this->job->profile->include_database ? $this->list_tables() : array();

		// Materialize the file list to disk so state.json stays small.
		$files = $this->build_file_list();
		file_put_contents( $this->job->files_list_path(), implode( "\n", $files ) );

		// Empty database.sql ready for appending.
		if ( $this->job->profile->include_database ) {
			file_put_contents( $this->job->sql_path(), $this->sql_header() );
		}

		$next = $this->job->profile->include_database ? 'db_schema' : ( empty( $files ) ? 'finalize' : 'files' );

		$this->job->set_phase(
			$next,
			array(
				'tables'             => $tables,
				'total_files'        => count( $files ),
				'file_index'         => 0,
				'table_index'        => 0,
				'row_offset'         => 0,
				'current_table_rows' => 0,
				'rows_written'       => 0,
				'total_rows'         => 0,
			)
		);
		$this->job->update_progress( 0.01, __( 'Initialized', 'migrator' ), 1.0 );
	}

	private function phase_db_schema(): void {
		global $wpdb;
		$data   = $this->job->state['data'];
		$tables = (array) $data['tables'];

		$handle = fopen( $this->job->sql_path(), 'a' );
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Could not open database.sql for writing.', 'migrator' ) );
		}

		fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

		$pks     = array();
		$max_pks = array();
		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
			if ( empty( $create[1] ) ) {
				continue;
			}
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create[1] . ";\n\n" );

			// Detect single-column primary key for cursor-based pagination.
			// Tables with composite or no PK fall back to OFFSET pagination
			// (much slower on large tables but always correct).
			$pk            = $this->detect_primary_key( $table );
			$pks[ $table ] = $pk;

			// Snapshot max PK at dump start. The cursor will cap at this value,
			// so rows inserted during the dump (e.g. by a concurrent Wordfence
			// scan filling wfFileMods) don't make us chase a moving tail. Tables
			// without a usable PK have no cap — they'll see inconsistency on a
			// live site, but those are rare in WP core.
			if ( null !== $pk ) {
				$max = $wpdb->get_var( "SELECT MAX(`" . esc_sql( $pk ) . "`) FROM `" . esc_sql( $table ) . '`' );
				$max_pks[ $table ] = ( null === $max ) ? null : (string) $max;
			}
		}
		fclose( $handle );

		// Count total rows up-front so progress is meaningful. Use the same
		// max_pk cap that the dump itself will use, so the count matches what
		// we will actually export.
		$total_rows = 0;
		foreach ( $tables as $table ) {
			$where_parts = array();
			if ( isset( $max_pks[ $table ] ) && null !== $max_pks[ $table ] && isset( $pks[ $table ] ) ) {
				$where_parts[] = sprintf( "`%s` <= '%s'", $pks[ $table ], esc_sql( $max_pks[ $table ] ) );
			}
			$where = $this->where_for_table( $table );
			if ( $where ) {
				$where_parts[] = $where;
			}
			$where_clause = empty( $where_parts ) ? '' : ' WHERE ' . implode( ' AND ', $where_parts );
			$sql          = "SELECT COUNT(*) FROM `{$table}`{$where_clause}";
			$total_rows  += (int) $wpdb->get_var( $sql );
		}
		$data['total_rows'] = $total_rows;
		$data['pks']        = $pks;
		$data['max_pks']    = $max_pks;
		$data['last_pks']   = array();

		$this->job->set_phase( 'db_data', $data );
		$this->job->update_progress( 0.05, __( 'Schema written', 'migrator' ), 1.0 );
	}

	/**
	 * Return the column name of the single-column primary key for $table,
	 * or null if the PK is composite / missing.
	 */
	private function detect_primary_key( string $table ): ?string {
		global $wpdb;
		$keys = $wpdb->get_results(
			'SHOW KEYS FROM `' . esc_sql( $table ) . "` WHERE Key_name = 'PRIMARY'",
			ARRAY_A
		);
		if ( empty( $keys ) || count( $keys ) > 1 ) {
			return null;
		}
		return (string) $keys[0]['Column_name'];
	}

	private function phase_db_data(): void {
		global $wpdb;
		$data         = $this->job->state['data'];
		$tables       = (array) $data['tables'];
		$table_index  = (int) $data['table_index'];
		$row_offset   = (int) $data['row_offset'];
		$rows_written = (int) $data['rows_written'];
		$total_rows   = max( 1, (int) $data['total_rows'] );
		$pks          = (array) ( $data['pks'] ?? array() );
		$last_pks     = (array) ( $data['last_pks'] ?? array() );
		$max_pks      = (array) ( $data['max_pks'] ?? array() );

		if ( $table_index >= count( $tables ) ) {
			$this->job->set_phase( 'db_attach', $data );
			$this->job->update_progress( 0.55, __( 'Database dump complete', 'migrator' ), 1.0 );
			return;
		}

		$table = $tables[ $table_index ];
		$pk    = $pks[ $table ] ?? null;
		$where = $this->where_for_table( $table );
		$batch = self::DB_BATCH_ROWS;

		// Cursor-based pagination is O(log N) per page on indexed PK; OFFSET
		// pagination is O(page * batch). For million-row tables the difference
		// is enormous.
		if ( null !== $pk ) {
			$where_parts = array();
			if ( isset( $last_pks[ $table ] ) ) {
				$where_parts[] = sprintf( "`%s` > '%s'", $pk, esc_sql( (string) $last_pks[ $table ] ) );
			}
			// Cap at the max PK we recorded in phase_db_schema. Without this
			// cap, rows inserted during the dump get pulled in and the dump
			// never finishes on a live, write-heavy table.
			if ( isset( $max_pks[ $table ] ) && null !== $max_pks[ $table ] ) {
				$where_parts[] = sprintf( "`%s` <= '%s'", $pk, esc_sql( (string) $max_pks[ $table ] ) );
			}
			if ( $where ) {
				$where_parts[] = $where;
			}
			$where_clause = empty( $where_parts ) ? '' : ' WHERE ' . implode( ' AND ', $where_parts );
			$sql          = "SELECT * FROM `{$table}`{$where_clause} ORDER BY `{$pk}` ASC LIMIT {$batch}";
		} else {
			$where_clause = $where ? " WHERE {$where}" : '';
			$sql          = "SELECT * FROM `{$table}`{$where_clause} LIMIT {$batch} OFFSET {$row_offset}";
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $rows ) ) {
			$data['table_index']        = $table_index + 1;
			$data['row_offset']         = 0;
			$this->job->state['data']   = $data;
			$this->job->set_phase( 'db_data', $data );
			$ratio = min( 0.99, $rows_written / $total_rows );
			$this->job->update_progress(
				0.05 + 0.5 * $ratio,
				sprintf( __( 'Finishing table %s', 'migrator' ), $table ),
				$ratio
			);
			return;
		}

		$handle = fopen( $this->job->sql_path(), 'a' );
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Could not open database.sql for writing.', 'migrator' ) );
		}

		$columns       = array_keys( $rows[0] );
		$columns_list  = '`' . implode( '`, `', $columns ) . '`';
		$insert_prefix = "INSERT INTO `{$table}` ({$columns_list}) VALUES ";

		// Group rows into multi-row INSERTs ~1 MB each. On import this reduces
		// statement-parse overhead by ~100x vs one INSERT per row.
		$current_values = '';
		$current_size   = 0;
		foreach ( $rows as $row ) {
			$values_str = '(' . $this->row_to_sql_values( $row ) . ')';
			$value_size = strlen( $values_str ) + 1; // + comma

			if ( $current_size > 0 && $current_size + $value_size > self::INSERT_TARGET_BYTES ) {
				fwrite( $handle, $insert_prefix . $current_values . ";\n" );
				$current_values = $values_str;
				$current_size   = $value_size;
			} else {
				$current_values .= ( '' === $current_values ? '' : ',' ) . $values_str;
				$current_size   += $value_size;
			}
		}
		if ( '' !== $current_values ) {
			fwrite( $handle, $insert_prefix . $current_values . ";\n" );
		}
		fclose( $handle );

		$batch_count          = count( $rows );
		$data['row_offset']   = $row_offset + $batch_count;
		$data['rows_written'] = $rows_written + $batch_count;

		// Advance cursor for next step.
		if ( null !== $pk ) {
			$last_row              = end( $rows );
			$last_pks[ $table ]    = $last_row[ $pk ];
			$data['last_pks']      = $last_pks;
		}
		$this->job->state['data'] = $data;

		// Clamp the ratio to 99% — total_rows is a snapshot from phase_db_schema,
		// and on a live site rows added between the COUNT(*) and the dump make
		// rows_written exceed total_rows. Without the clamp the progress bar
		// hits 100% while the dump is still running, which looks frozen.
		$ratio   = min( 0.99, $data['rows_written'] / $total_rows );
		$overall = 0.05 + 0.5 * $ratio;
		$label   = $data['rows_written'] > $total_rows
			? sprintf( __( 'Dumping %1$s (%2$s rows, more than initial estimate)', 'migrator' ), $table, number_format_i18n( $data['rows_written'] ) )
			: sprintf( __( 'Dumping %1$s (%2$s / %3$s rows)', 'migrator' ), $table, number_format_i18n( $data['rows_written'] ), number_format_i18n( $total_rows ) );
		$this->job->update_progress( $overall, $label, $ratio );
	}

	private function phase_db_attach(): void {
		$handle = fopen( $this->job->sql_path(), 'a' );
		fwrite( $handle, "\nSET FOREIGN_KEY_CHECKS=1;\n" );
		fclose( $handle );

		$zip = $this->open_archive_for_write();
		$zip->addFile( $this->job->sql_path(), self::DB_FILE );
		// Store-only: SQL dumps compress well, but for media-heavy sites the
		// archive is dominated by uncompressible JPEG/PNG/MP4 anyway. Skipping
		// DEFLATE everywhere keeps the CPU profile flat — the bottleneck on
		// large exports moves from CPU to disk I/O.
		$zip->setCompressionName( self::DB_FILE, ZipArchive::CM_STORE );
		$zip->close();

		$data = $this->job->state['data'];
		if ( ! empty( $data['total_files'] ) ) {
			$this->job->set_phase( 'files', $data );
		} else {
			$this->job->set_phase( 'finalize', $data );
		}
		$this->job->update_progress( 0.6, __( 'Database attached to archive', 'migrator' ), 1.0 );
	}

	private function phase_files(): void {
		$data        = $this->job->state['data'];
		$file_index  = (int) $data['file_index'];
		$total_files = (int) $data['total_files'];

		if ( $file_index >= $total_files ) {
			$this->job->set_phase( 'finalize', $data );
			$this->job->update_progress( 0.95, __( 'All files added', 'migrator' ), 1.0 );
			return;
		}

		$list_handle = fopen( $this->job->files_list_path(), 'r' );
		if ( false === $list_handle ) {
			throw new RuntimeException( __( 'Could not read file list.', 'migrator' ) );
		}

		// Seek to file_index by skipping lines.
		for ( $i = 0; $i < $file_index; $i++ ) {
			if ( false === fgets( $list_handle ) ) {
				break;
			}
		}

		try {
			$zip = $this->open_archive_for_write();
		} catch ( Throwable $e ) {
			fclose( $list_handle );
			throw $e;
		}

		$processed = 0;
		while ( $processed < self::FILE_BATCH && false !== ( $line = fgets( $list_handle ) ) ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			list( $absolute, $zip_path ) = explode( '|', $line, 2 );
			if ( file_exists( $absolute ) && is_file( $absolute ) ) {
				$zip->addFile( $absolute, $zip_path );
				// Store-only — see phase_db_attach for rationale.
				$zip->setCompressionName( $zip_path, ZipArchive::CM_STORE );
			}
			$processed++;
		}
		fclose( $list_handle );
		$zip->close();

		$data['file_index']      = $file_index + $processed;
		$this->job->state['data'] = $data;

		$overall = 0.6 + 0.35 * ( $data['file_index'] / max( 1, $total_files ) );
		$this->job->update_progress(
			$overall,
			sprintf( __( 'Adding files (%1$d / %2$d)', 'migrator' ), $data['file_index'], $total_files ),
			$data['file_index'] / max( 1, $total_files )
		);
	}

	private function phase_finalize(): void {
		$zip = $this->open_archive_for_write();

		$manifest = array(
			'plugin_version' => MIGRATOR_VERSION,
			'site_url'       => get_site_url(),
			'home_url'       => get_home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'created_at'     => gmdate( 'c' ),
			'table_prefix'   => $GLOBALS['wpdb']->prefix,
			'profile'        => $this->job->profile->to_array(),
		);
		if ( ! $zip->addFromString( self::MANIFEST_FILE, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) ) ) {
			$zip->close();
			throw new RuntimeException( __( 'Could not add migrator-manifest.json to archive.', 'migrator' ) );
		}
		$zip->setCompressionName( self::MANIFEST_FILE, ZipArchive::CM_STORE );
		if ( ! $zip->close() ) {
			throw new RuntimeException( __( 'Could not finalize archive.', 'migrator' ) );
		}

		// Remove intermediate database.sql; it's already in the zip.
		if ( file_exists( $this->job->sql_path() ) ) {
			@unlink( $this->job->sql_path() );
		}
		if ( file_exists( $this->job->files_list_path() ) ) {
			@unlink( $this->job->files_list_path() );
		}

		$this->job->set_phase( 'done', array() );
		$this->job->update_progress( 1.0, __( 'Archive ready for download', 'migrator' ), 1.0 );
	}

	/**
	 * Open the job's archive for writing. Creates the file if it does not exist
	 * yet — works around the PHP quirk where `ZipArchive::close()` on an empty
	 * new archive does not actually write a file to disk.
	 */
	private function open_archive_for_write(): ZipArchive {
		$path  = $this->job->archive_path();
		$flags = file_exists( $path ) ? 0 : ZipArchive::CREATE;
		$zip   = new ZipArchive();
		if ( true !== $zip->open( $path, $flags ) ) {
			throw new RuntimeException( __( 'Could not open archive.', 'migrator' ) );
		}
		return $zip;
	}

	public function stream_download(): void {
		$path = $this->job->archive_path();
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Archive not found.', 'migrator' ) );
		}

		$filename = sprintf( 'migrator-%s-%s.zip', sanitize_title( get_bloginfo( 'name' ) ), gmdate( 'Ymd-His' ) );
		$size     = filesize( $path );

		// Discard every output buffer accumulated by WP / themes / other plugins.
		// Otherwise the full archive ends up in memory before being sent, which
		// blows past PHP's memory_limit on multi-MB sites and the resulting
		// fatal surfaces as the WordPress "critical error" HTML page being
		// saved by the browser as a .zip file.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}
		@ini_set( 'zlib.output_compression', 'Off' );
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		// Stream the file in 1 MB chunks. Flat memory profile regardless of
		// archive size; no implicit buffering by readfile() in some PHP/SAPI
		// configurations.
		$fp = fopen( $path, 'rb' );
		if ( false === $fp ) {
			// Headers are already sent so we can't switch to a JSON error;
			// best we can do is emit nothing and let the client time out.
			exit;
		}
		while ( ! feof( $fp ) ) {
			echo fread( $fp, 1048576 );
			flush();
		}
		fclose( $fp );

		$this->job->destroy();
		exit;
	}

	private function download_url(): string {
		return add_query_arg(
			array(
				'action'  => 'migrator_export_download',
				'job_id'  => $this->job->id,
				'_wpnonce' => wp_create_nonce( Migrator_Ajax::NONCE ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	private function list_tables(): array {
		global $wpdb;
		$all     = (array) $wpdb->get_col( 'SHOW TABLES' );
		$profile = $this->job->profile;
		return array_values(
			array_filter(
				$all,
				function ( $t ) use ( $wpdb, $profile ) {
					$t = (string) $t;
					if ( 0 !== strpos( $t, $wpdb->prefix ) ) {
						return false;
					}
					if ( $profile->table_matches_skip( $t ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	private function where_for_table( string $table ): string {
		global $wpdb;
		$suffix  = substr( $table, strlen( $wpdb->prefix ) );
		$clauses = $this->job->profile->db_where_clauses();
		return $clauses[ $suffix ] ?? '';
	}

	private function sql_header(): string {
		return "-- Migrator database export\n-- Generated: " . gmdate( 'c' ) . "\n\n";
	}

	/**
	 * Serialize one row's values as SQL: NULLs preserved, strings escaped via
	 * esc_sql, then literal CR/LF replaced with the MySQL \r / \n escape
	 * sequences so the entire INSERT remains on a single physical line in the
	 * dump file. The importer's line-based parser depends on that.
	 */
	private function row_to_sql_values( array $row ): string {
		$values = array();
		foreach ( $row as $value ) {
			if ( null === $value ) {
				$values[] = 'NULL';
			} else {
				$escaped  = esc_sql( $value );
				$escaped  = str_replace( array( "\r\n", "\r", "\n" ), array( '\\n', '\\r', '\\n' ), $escaped );
				$values[] = "'" . $escaped . "'";
			}
		}
		return implode( ',', $values );
	}

	/**
	 * Build a flat list of files (absolute|zip-relative per line) honoring exclusions.
	 *
	 * @return string[]
	 */
	private function build_file_list(): array {
		$lines    = array();
		$dirs     = $this->job->profile->content_dirs();
		$exclude  = $this->job->profile;
		$base_dir = Migrator_Job::base_dir();

		foreach ( $dirs as $label => $absolute_root ) {
			if ( ! is_dir( $absolute_root ) ) {
				continue;
			}

			$iter = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $absolute_root, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iter as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$absolute = $file->getPathname();

				// Skip the Migrator working directory itself.
				if ( 0 === strpos( $absolute, $base_dir ) ) {
					continue;
				}
				// Skip the Migrator plugin's own directory when exporting plugins.
				if ( 'plugins' === $label && 0 === strpos( $absolute, MIGRATOR_PLUGIN_DIR ) ) {
					continue;
				}

				$relative_to_root = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $absolute_root ) ) ), '/' );
				$zip_path         = $label . '/' . $relative_to_root;

				if ( $exclude->file_matches_exclude( $zip_path ) ) {
					continue;
				}

				$lines[] = $absolute . '|' . $zip_path;
			}
		}

		return $lines;
	}
}
