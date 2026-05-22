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

	const DB_BATCH_ROWS = 200;
	const FILE_BATCH    = 50;

	private Migrator_Job $job;

	public function __construct( Migrator_Job $job ) {
		$this->job = $job;
	}

	public function step(): array {
		$phase = $this->job->state['phase'] ?? 'init';

		try {
			switch ( $phase ) {
				case 'init':
					$this->phase_init();
					break;
				case 'extract':
					$this->phase_extract();
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

		$manifest_raw = $zip->getFromName( Migrator_Exporter::MANIFEST_FILE );
		if ( false === $manifest_raw ) {
			$zip->close();
			throw new RuntimeException( __( 'Archive is missing migrator-manifest.json.', 'migrator' ) );
		}
		$manifest = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) || empty( $manifest['site_url'] ) ) {
			$zip->close();
			throw new RuntimeException( __( 'Manifest is invalid.', 'migrator' ) );
		}

		$total_entries = $zip->numFiles;
		$zip->close();

		$extract_dir = $this->job->dir() . '/extract';
		wp_mkdir_p( $extract_dir );

		$data = $this->job->state['data'];
		$data['manifest']        = $manifest;
		$data['extract_dir']     = $extract_dir;
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
			if ( false !== $name ) {
				$entries[] = $name;
			}
			$processed++;
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
		$sql_file = $data['extract_dir'] . '/' . Migrator_Exporter::DB_FILE;
		if ( file_exists( $sql_file ) ) {
			// Apply URL rewriting once, in-place.
			$manifest  = (array) $data['manifest'];
			$old_url   = untrailingslashit( (string) $manifest['site_url'] );
			$new_url   = untrailingslashit( (string) $data['new_url'] );
			if ( $old_url !== $new_url ) {
				$this->rewrite_urls_in_file( $sql_file, $old_url, $new_url );
			}
			$data['sql_total']  = filesize( $sql_file );
			$data['sql_offset'] = 0;
			$this->job->set_phase( 'db_restore', $data );
			$this->job->update_progress( 0.3, __( 'Restoring database', 'migrator' ), 0.0 );
		} else {
			$this->prepare_files_copy_phase( $data );
		}
	}

	private function phase_db_restore(): void {
		global $wpdb;
		$data       = $this->job->state['data'];
		$sql_file   = $data['extract_dir'] . '/' . Migrator_Exporter::DB_FILE;
		$sql_total  = (int) $data['sql_total'];
		$sql_offset = (int) $data['sql_offset'];

		$handle = fopen( $sql_file, 'r' );
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Could not open SQL dump for reading.', 'migrator' ) );
		}
		if ( $sql_offset > 0 ) {
			fseek( $handle, $sql_offset );
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

		$statements    = 0;
		$buffer        = '';
		while ( $statements < self::DB_BATCH_ROWS && false !== ( $line = fgets( $handle ) ) ) {
			$buffer .= $line;
			if ( preg_match( '/;\s*$/', $line ) ) {
				$statement = trim( $buffer );
				$buffer    = '';
				if ( '' === $statement || 0 === strpos( $statement, '--' ) ) {
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
			$this->prepare_files_copy_phase( $data );
			return;
		}

		$this->job->state['data'] = $data;
		$overall = 0.3 + 0.4 * ( $new_offset / max( 1, $sql_total ) );
		$this->job->update_progress(
			$overall,
			sprintf( __( 'Restoring database (%d statements applied)', 'migrator' ), $statements ),
			$new_offset / max( 1, $sql_total )
		);
	}

	private function prepare_files_copy_phase( array $data ): void {
		$copy_map = $this->build_copy_map( $data['extract_dir'] );
		file_put_contents( $this->job->files_list_path(), implode( "\n", $copy_map['lines'] ) );
		$data['total_copy'] = $copy_map['count'];
		$data['file_index'] = 0;

		if ( 0 === $copy_map['count'] ) {
			$this->job->set_phase( 'finalize', $data );
			$this->job->update_progress( 0.95, __( 'No files to copy', 'migrator' ), 1.0 );
			return;
		}

		$this->job->set_phase( 'files_copy', $data );
		$this->job->update_progress( 0.7, __( 'Copying files', 'migrator' ), 0.0 );
	}

	private function phase_files_copy(): void {
		$data       = $this->job->state['data'];
		$file_index = (int) $data['file_index'];
		$total_copy = (int) $data['total_copy'];

		if ( $file_index >= $total_copy ) {
			$this->job->set_phase( 'finalize', $data );
			$this->job->update_progress( 0.95, __( 'All files copied', 'migrator' ), 1.0 );
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

		$processed = 0;
		while ( $processed < self::FILE_BATCH && false !== ( $line = fgets( $handle ) ) ) {
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

		$overall = 0.7 + 0.25 * ( $data['file_index'] / max( 1, $total_copy ) );
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

	private function build_copy_map( string $extract_dir ): array {
		// Map each top-level directory in extract/ to its destination on disk.
		$destinations = array(
			'uploads'    => wp_upload_dir()['basedir'],
			'themes'     => WP_CONTENT_DIR . '/themes',
			'plugins'    => WP_PLUGIN_DIR,
			'mu-plugins' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins',
		);

		$lines = array();
		foreach ( $destinations as $label => $dest_root ) {
			$source_root = $extract_dir . '/' . $label;
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
				$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $source_root ) ) ), '/' );
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
	 * Rewrite URLs in the SQL dump, handling PHP-serialized strings safely.
	 * The whole file is read into memory at once — acceptable because the DB
	 * dump is on the same order of magnitude as the database itself, and the
	 * alternative (line-by-line) can't see serialized headers split mid-stream.
	 */
	private function rewrite_urls_in_file( string $path, string $old, string $new ): void {
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return;
		}
		$contents = $this->rewrite_serialized( $contents, $old, $new );
		$contents = str_replace( $old, $new, $contents );
		file_put_contents( $path, $contents );
	}

	private function rewrite_serialized( string $sql, string $old, string $new ): string {
		$pattern = '/s:(\d+):\\\\"((?:[^"\\\\]|\\\\.)*)\\\\"/';
		return preg_replace_callback(
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
