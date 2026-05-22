<?php
/**
 * Exports the WordPress database and uploads directory into a portable archive.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Exporter {

	const MANIFEST_FILE = 'migrator-manifest.json';
	const DB_FILE       = 'database.sql';
	const UPLOADS_DIR   = 'uploads';

	public function export() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'migrator_no_zip', __( 'The ZipArchive PHP extension is required to export.', 'migrator' ) );
		}

		$work_dir = $this->work_dir();
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}

		$sql_path = trailingslashit( $work_dir ) . self::DB_FILE;
		$sql_dump = $this->dump_database();
		if ( is_wp_error( $sql_dump ) ) {
			return $sql_dump;
		}

		if ( false === file_put_contents( $sql_path, $sql_dump ) ) {
			return new WP_Error( 'migrator_write_failed', __( 'Could not write database dump to disk.', 'migrator' ) );
		}

		$archive_name = sprintf( 'migrator-%s-%s.zip', sanitize_title( get_bloginfo( 'name' ) ), gmdate( 'Ymd-His' ) );
		$archive_path = trailingslashit( $work_dir ) . $archive_name;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'migrator_zip_open', __( 'Could not create archive file.', 'migrator' ) );
		}

		$manifest = array(
			'plugin_version' => MIGRATOR_VERSION,
			'site_url'       => get_site_url(),
			'home_url'       => get_home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'created_at'     => gmdate( 'c' ),
			'table_prefix'   => $GLOBALS['wpdb']->prefix,
		);

		$zip->addFromString( self::MANIFEST_FILE, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$zip->addFile( $sql_path, self::DB_FILE );

		$uploads_basedir = wp_upload_dir()['basedir'];
		if ( is_dir( $uploads_basedir ) ) {
			$this->add_directory_to_zip( $zip, $uploads_basedir, self::UPLOADS_DIR );
		}

		$zip->close();

		@unlink( $sql_path );

		return $archive_path;
	}

	public function stream_archive( $archive_path ) {
		if ( ! file_exists( $archive_path ) ) {
			wp_die( esc_html__( 'Archive not found.', 'migrator' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $archive_path ) . '"' );
		header( 'Content-Length: ' . filesize( $archive_path ) );

		readfile( $archive_path );
		@unlink( $archive_path );
		exit;
	}

	private function work_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'migrator';

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'migrator_mkdir', __( 'Could not create working directory.', 'migrator' ) );
		}

		return $dir;
	}

	private function dump_database() {
		global $wpdb;

		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) {
			return new WP_Error( 'migrator_no_tables', __( 'No tables found in the database.', 'migrator' ) );
		}

		$prefix = $wpdb->prefix;
		$output = "-- Migrator database export\n";
		$output .= '-- Generated: ' . gmdate( 'c' ) . "\n\n";
		$output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

		foreach ( $tables as $table ) {
			// Only dump tables that share the WP prefix.
			if ( 0 !== strpos( $table, $prefix ) ) {
				continue;
			}

			$create_row = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
			if ( empty( $create_row[1] ) ) {
				continue;
			}

			$output .= "DROP TABLE IF EXISTS `{$table}`;\n";
			$output .= $create_row[1] . ";\n\n";

			$rows = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $table ) . '`', ARRAY_A );
			if ( empty( $rows ) ) {
				continue;
			}

			$columns      = array_keys( $rows[0] );
			$columns_list = '`' . implode( '`, `', $columns ) . '`';

			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . esc_sql( $value ) . "'";
					}
				}
				$output .= "INSERT INTO `{$table}` ({$columns_list}) VALUES (" . implode( ', ', $values ) . ");\n";
			}

			$output .= "\n";
		}

		$output .= "SET FOREIGN_KEY_CHECKS=1;\n";

		return $output;
	}

	private function add_directory_to_zip( ZipArchive $zip, $source_dir, $local_root ) {
		$source_dir = rtrim( $source_dir, '/\\' );
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$path     = $file->getPathname();
			$relative = ltrim( substr( $path, strlen( $source_dir ) ), '/\\' );
			$relative = str_replace( '\\', '/', $relative );

			// Skip Migrator's own working directory inside uploads.
			if ( 0 === strpos( $relative, 'migrator/' ) || 'migrator' === $relative ) {
				continue;
			}

			$zip_path = $local_root . '/' . $relative;
			if ( $file->isDir() ) {
				$zip->addEmptyDir( $zip_path );
			} else {
				$zip->addFile( $path, $zip_path );
			}
		}
	}
}