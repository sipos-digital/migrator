<?php
/**
 * AJAX router for chunked export/import.
 *
 * Endpoints (all require `manage_options` + nonce + job ownership):
 *   migrator_export_start     →  POST profile fields, returns { job_id }
 *   migrator_export_step      →  POST job_id, returns snapshot
 *   migrator_export_cancel    →  POST job_id, destroys job
 *   migrator_export_download  →  GET  job_id, streams the archive
 *
 *   migrator_import_start     →  POST profile (currently empty), returns { job_id }
 *   migrator_import_upload    →  POST job_id + chunk_index + total_chunks + file slice (multipart)
 *   migrator_import_step      →  POST job_id, returns snapshot
 *   migrator_import_cancel    →  POST job_id, destroys job
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Ajax {

	const NONCE = 'migrator_ajax';

	public static function register(): void {
		$endpoints = array(
			'migrator_export_start'    => 'export_start',
			'migrator_export_step'     => 'export_step',
			'migrator_export_cancel'   => 'export_cancel',
			'migrator_export_download' => 'export_download',
			'migrator_import_start'    => 'import_start',
			'migrator_import_upload'   => 'import_upload',
			'migrator_import_step'     => 'import_step',
			'migrator_import_cancel'   => 'import_cancel',
		);
		foreach ( $endpoints as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	public static function export_start(): void {
		self::guard();
		$profile = Migrator_Profile::from_post( $_POST );

		if ( ! self::any_inclusion( $profile ) ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one thing to include.', 'migrator' ) ), 400 );
		}

		try {
			$job = Migrator_Job::create( Migrator_Job::TYPE_EXPORT, $profile );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success(
			array(
				'job_id'   => $job->id,
				'snapshot' => $job->snapshot(),
			)
		);
	}

	public static function export_step(): void {
		self::guard();
		$job = self::load_owned_job( $_POST['job_id'] ?? '' );

		if ( Migrator_Job::TYPE_EXPORT !== $job->type ) {
			wp_send_json_error( array( 'message' => 'Job type mismatch.' ), 400 );
		}

		$exporter = new Migrator_Exporter( $job );
		try {
			$snapshot = $exporter->step();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage(), 'snapshot' => $job->snapshot() ), 500 );
		}
		wp_send_json_success( $snapshot );
	}

	public static function export_cancel(): void {
		self::guard();
		$job = self::load_owned_job( $_POST['job_id'] ?? '' );
		$job->destroy();
		wp_send_json_success();
	}

	public static function export_download(): void {
		// Nonce on GET is acceptable here — the download URL embeds it and is single-use-ish (job is destroyed after).
		if ( ! current_user_can( Migrator_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'migrator' ) );
		}
		check_admin_referer( self::NONCE );

		$job = Migrator_Job::load( sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) ) );
		if ( null === $job || ! $job->owned_by_current_user() ) {
			wp_die( esc_html__( 'Job not found.', 'migrator' ) );
		}

		$exporter = new Migrator_Exporter( $job );
		$exporter->stream_download();
	}

	public static function import_start(): void {
		self::guard();

		try {
			$job = Migrator_Job::create( Migrator_Job::TYPE_IMPORT, new Migrator_Profile() );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		$source = 'upload';
		$incoming = sanitize_file_name( (string) wp_unslash( $_POST['incoming_file'] ?? '' ) );
		if ( '' !== $incoming ) {
			$src = Migrator_Admin::incoming_dir() . '/' . $incoming;
			// Belt-and-braces: sanitize_file_name strips path components, but
			// double-check the resolved path is inside the incoming dir.
			$real = realpath( $src );
			$dir_real = realpath( Migrator_Admin::incoming_dir() );
			if ( false === $real || false === $dir_real || 0 !== strpos( $real, $dir_real . DIRECTORY_SEPARATOR ) ) {
				$job->destroy();
				wp_send_json_error( array( 'message' => __( 'Incoming file not found.', 'migrator' ) ), 404 );
			}
			// Symlink (O(1)) when possible; fall back to copy on Windows or
			// filesystems where symlink fails. Either way the original file in
			// the incoming/ dir is preserved across job cleanup.
			$dst = $job->archive_path();
			@unlink( $dst );
			if ( ! @symlink( $real, $dst ) ) {
				if ( ! @copy( $real, $dst ) ) {
					$job->destroy();
					wp_send_json_error( array( 'message' => __( 'Could not stage incoming file.', 'migrator' ) ), 500 );
				}
			}
			$source = 'incoming';
		} else {
			// Initialize an empty archive file we'll append chunks to.
			file_put_contents( $job->archive_path(), '' );
		}

		$raw_url                               = trim( (string) wp_unslash( $_POST['new_url'] ?? '' ) );
		$job->state['data']['new_url']         = '' !== $raw_url ? untrailingslashit( esc_url_raw( $raw_url ) ) : untrailingslashit( get_site_url() );
		$job->state['data']['chunks_received'] = 0;
		$job->state['data']['source']          = $source;
		$job->save();

		wp_send_json_success(
			array(
				'job_id'   => $job->id,
				'snapshot' => $job->snapshot(),
				'source'   => $source,
			)
		);
	}

	public static function import_upload(): void {
		self::guard();
		$job = self::load_owned_job( $_POST['job_id'] ?? '' );

		if ( Migrator_Job::TYPE_IMPORT !== $job->type ) {
			wp_send_json_error( array( 'message' => 'Job type mismatch.' ), 400 );
		}

		$chunk_index  = (int) ( $_POST['chunk_index'] ?? 0 );
		$total_chunks = (int) ( $_POST['total_chunks'] ?? 1 );

		if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No chunk uploaded.', 'migrator' ) ), 400 );
		}

		$dest = fopen( $job->archive_path(), 'ab' );
		$src  = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
		if ( false === $dest || false === $src ) {
			wp_send_json_error( array( 'message' => 'Could not append chunk.' ), 500 );
		}
		while ( ! feof( $src ) ) {
			fwrite( $dest, fread( $src, 1048576 ) );
		}
		fclose( $src );
		fclose( $dest );

		$job->state['data']['chunks_received'] = $chunk_index + 1;
		$job->update_progress( 0.01 + 0.01 * ( ( $chunk_index + 1 ) / max( 1, $total_chunks ) ), __( 'Uploading archive', 'migrator' ), ( $chunk_index + 1 ) / max( 1, $total_chunks ) );
		$job->save();

		wp_send_json_success(
			array(
				'snapshot'      => $job->snapshot(),
				'upload_complete' => ( $chunk_index + 1 ) >= $total_chunks,
			)
		);
	}

	public static function import_step(): void {
		self::guard();
		$job = self::load_owned_job( $_POST['job_id'] ?? '' );

		if ( Migrator_Job::TYPE_IMPORT !== $job->type ) {
			wp_send_json_error( array( 'message' => 'Job type mismatch.' ), 400 );
		}

		$importer = new Migrator_Importer( $job );
		try {
			$snapshot = $importer->step();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage(), 'snapshot' => $job->snapshot() ), 500 );
		}
		wp_send_json_success( $snapshot );
	}

	public static function import_cancel(): void {
		self::guard();
		$job = self::load_owned_job( $_POST['job_id'] ?? '' );
		$job->destroy();
		wp_send_json_success();
	}

	private static function guard(): void {
		if ( ! current_user_can( Migrator_Admin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
		}
		check_ajax_referer( self::NONCE );
	}

	private static function load_owned_job( $raw_id ): Migrator_Job {
		$id  = sanitize_text_field( wp_unslash( $raw_id ) );
		$job = Migrator_Job::load( $id );
		if ( null === $job ) {
			wp_send_json_error( array( 'message' => __( 'Job not found.', 'migrator' ) ), 404 );
		}
		if ( ! $job->owned_by_current_user() ) {
			wp_send_json_error( array( 'message' => __( 'You do not own this job.', 'migrator' ) ), 403 );
		}
		return $job;
	}

	private static function any_inclusion( Migrator_Profile $p ): bool {
		return $p->include_database
			|| $p->include_uploads
			|| $p->include_themes
			|| $p->include_plugins
			|| $p->include_mu_plugins;
	}
}
