<?php
/**
 * Imports a Migrator archive: restores the database and uploads, and rewrites URLs.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Importer {

	public function import( array $uploaded_file, $new_url = '' ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'migrator_no_zip', __( 'The ZipArchive PHP extension is required to import.', 'migrator' ) );
		}

		if ( empty( $uploaded_file['tmp_name'] ) || ! is_uploaded_file( $uploaded_file['tmp_name'] ) ) {
			return new WP_Error( 'migrator_upload', __( 'Uploaded archive is invalid.', 'migrator' ) );
		}

		if ( ! empty( $uploaded_file['error'] ) ) {
			return new WP_Error( 'migrator_upload_error', __( 'Upload failed. Check PHP upload_max_filesize and post_max_size.', 'migrator' ) );
		}

		$work_dir = $this->work_dir();
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}

		$extract_dir = trailingslashit( $work_dir ) . 'extract-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $extract_dir ) ) {
			return new WP_Error( 'migrator_mkdir', __( 'Could not create extraction directory.', 'migrator' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $uploaded_file['tmp_name'] ) ) {
			$this->rrmdir( $extract_dir );
			return new WP_Error( 'migrator_zip_open', __( 'Could not open archive.', 'migrator' ) );
		}
		$zip->extractTo( $extract_dir );
		$zip->close();

		$manifest_path = trailingslashit( $extract_dir ) . Migrator_Exporter::MANIFEST_FILE;
		if ( ! file_exists( $manifest_path ) ) {
			$this->rrmdir( $extract_dir );
			return new WP_Error( 'migrator_no_manifest', __( 'Archive does not contain a Migrator manifest.', 'migrator' ) );
		}

		$manifest = json_decode( file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['site_url'] ) ) {
			$this->rrmdir( $extract_dir );
			return new WP_Error( 'migrator_bad_manifest', __( 'Manifest file is invalid.', 'migrator' ) );
		}

		$sql_path = trailingslashit( $extract_dir ) . Migrator_Exporter::DB_FILE;
		if ( ! file_exists( $sql_path ) ) {
			$this->rrmdir( $extract_dir );
			return new WP_Error( 'migrator_no_sql', __( 'Archive does not contain a database dump.', 'migrator' ) );
		}

		$sql      = file_get_contents( $sql_path );
		$old_url  = $manifest['site_url'];
		$target   = $new_url ? untrailingslashit( $new_url ) : untrailingslashit( get_site_url() );

		if ( $old_url !== $target ) {
			$sql = $this->replace_urls( $sql, $old_url, $target );
		}

		$restore = $this->restore_database( $sql );
		if ( is_wp_error( $restore ) ) {
			$this->rrmdir( $extract_dir );
			return $restore;
		}

		$uploads_source = trailingslashit( $extract_dir ) . Migrator_Exporter::UPLOADS_DIR;
		if ( is_dir( $uploads_source ) ) {
			$uploads_target = wp_upload_dir()['basedir'];
			$this->copy_directory( $uploads_source, $uploads_target );
		}

		$this->rrmdir( $extract_dir );

		return true;
	}

	private function work_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'migrator';
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'migrator_mkdir', __( 'Could not create working directory.', 'migrator' ) );
		}
		return $dir;
	}

	private function restore_database( $sql ) {
		global $wpdb;

		$statements = $this->split_sql( $sql );
		if ( empty( $statements ) ) {
			return new WP_Error( 'migrator_empty_sql', __( 'SQL dump is empty.', 'migrator' ) );
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $statements as $statement ) {
			$statement = trim( $statement );
			if ( '' === $statement || 0 === strpos( $statement, '--' ) ) {
				continue;
			}
			$result = $wpdb->query( $statement );
			if ( false === $result ) {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
				return new WP_Error( 'migrator_sql_error', sprintf( /* translators: %s: MySQL error */ __( 'SQL error during import: %s', 'migrator' ), $wpdb->last_error ) );
			}
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );

		return true;
	}

	private function split_sql( $sql ) {
		// Naive split on ";\n" — adequate for dumps we generated ourselves.
		$parts = preg_split( "/;\s*\n/", $sql );
		return array_filter( array_map( 'trim', $parts ) );
	}

	/**
	 * Replace URLs in the dump. Handles plain strings and PHP-serialized strings
	 * by rewriting the byte-length prefix when sizes differ.
	 */
	private function replace_urls( $sql, $old, $new ) {
		$old = (string) $old;
		$new = (string) $new;

		if ( $old === $new ) {
			return $sql;
		}

		$sql = $this->replace_serialized( $sql, $old, $new );
		$sql = str_replace( $old, $new, $sql );

		return $sql;
	}

	private function replace_serialized( $sql, $old, $new ) {
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

	private function copy_directory( $source, $target ) {
		if ( ! is_dir( $target ) ) {
			wp_mkdir_p( $target );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = ltrim( substr( $item->getPathname(), strlen( $source ) ), '/\\' );
			$dest     = trailingslashit( $target ) . $relative;
			if ( $item->isDir() ) {
				if ( ! file_exists( $dest ) ) {
					wp_mkdir_p( $dest );
				}
			} else {
				copy( $item->getPathname(), $dest );
			}
		}
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
