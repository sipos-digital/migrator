<?php
/**
 * Admin UI controller.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Migrator_Admin {

	const MENU_SLUG  = 'migrator';
	const CAPABILITY = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		Migrator_Ajax::register();
	}

	public static function activate() {
		Migrator_Job::base_dir();
		self::incoming_dir();
	}

	/**
	 * Returns (and creates if needed) the directory where users can drop
	 * pre-uploaded archives via FTP/SCP/Finder to skip the chunked-HTTP-upload
	 * phase of import. Multi-GB archives via HTTP take minutes; via filesystem,
	 * they're instant.
	 */
	public static function incoming_dir(): string {
		$dir = Migrator_Job::base_dir() . '/incoming';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	/** @return string[] basenames of zip files in the incoming directory */
	public static function list_incoming_files(): array {
		$dir   = self::incoming_dir();
		$files = glob( $dir . '/*.zip' );
		if ( false === $files ) {
			return array();
		}
		// Filter out anything we accidentally globbed (just basenames, only .zip)
		$out = array();
		foreach ( $files as $f ) {
			$basename = basename( $f );
			if ( '' !== $basename && is_file( $f ) ) {
				$out[] = $basename;
			}
		}
		sort( $out );
		return $out;
	}

	public static function deactivate() {
		// Keep state on deactivate.
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Migrator', 'migrator' ),
			__( 'Migrator', 'migrator' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-migrate',
			81
		);

		add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'migrator' ), __( 'Dashboard', 'migrator' ), self::CAPABILITY, self::MENU_SLUG, array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Export', 'migrator' ), __( 'Export', 'migrator' ), self::CAPABILITY, self::MENU_SLUG . '-export', array( __CLASS__, 'render_export' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Import', 'migrator' ), __( 'Import', 'migrator' ), self::CAPABILITY, self::MENU_SLUG . '-import', array( __CLASS__, 'render_import' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'migrator-admin',
			MIGRATOR_PLUGIN_URL . 'assets/admin.css',
			array(),
			MIGRATOR_VERSION
		);

		wp_enqueue_script(
			'migrator-admin',
			MIGRATOR_PLUGIN_URL . 'assets/admin.js',
			array(),
			MIGRATOR_VERSION,
			true
		);

		$post_max   = self::ini_bytes( 'post_max_size' );
		$upload_max = self::ini_bytes( 'upload_max_filesize' );
		$limit      = min( array_filter( array( $post_max, $upload_max ) ) ) ?: 2097152;
		// Reserve ~30% of the PHP limit for FormData overhead (boundaries, fields, headers).
		$chunk_bytes = max( 262144, (int) ( $limit * 0.7 ) );

		// Hard ceiling — Herd's default nginx vhost has client_max_body_size 2M,
		// which is BELOW most PHP post_max_size settings (typically 256M).
		// Sending PHP-sized chunks gets the request rejected by nginx with a 413
		// before it ever reaches PHP, producing an HTML error page that the
		// importer's JSON parser can't read. 1.5 MB leaves headroom even on
		// stricter nginx configs and FormData overhead.
		$max_ceiling = defined( 'MIGRATOR_CHUNK_BYTES_MAX' ) ? (int) MIGRATOR_CHUNK_BYTES_MAX : 1500000;
		$chunk_bytes = min( $chunk_bytes, $max_ceiling );

		wp_localize_script(
			'migrator-admin',
			'MigratorConfig',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( Migrator_Ajax::NONCE ),
				'chunkBytes' => $chunk_bytes,
				'postMax'    => $post_max,
				'uploadMax'  => $upload_max,
				'pollMs'     => 250,
				'i18n'       => array(
					'starting'       => __( 'Starting…', 'migrator' ),
					'cancelled'      => __( 'Cancelled.', 'migrator' ),
					'failed'         => __( 'Failed', 'migrator' ),
					'confirmCancel'  => __( 'Cancel this job?', 'migrator' ),
					'confirmImport'  => __( 'This will overwrite the database and files of this site. Continue?', 'migrator' ),
					'noInclusion'    => __( 'Select at least one thing to include.', 'migrator' ),
					'noFile'         => __( 'Please choose an archive to import.', 'migrator' ),
					'uploadComplete' => __( 'Upload complete', 'migrator' ),
					'postTooLarge'   => __( 'The server rejected the upload chunk because it exceeds PHP\'s post_max_size. Increase post_max_size / upload_max_filesize in php.ini and try again.', 'migrator' ),
					'nginxTooLarge'  => __( 'Nginx rejected the upload chunk (Request Entity Too Large). Raise client_max_body_size in this site\'s nginx vhost, or define MIGRATOR_CHUNK_BYTES_MAX in wp-config.php to lower the chunk size.', 'migrator' ),
				),
			)
		);
	}

	private static function ini_bytes( string $key ): int {
		$value = (string) ini_get( $key );
		if ( '' === $value ) {
			return 0;
		}
		return function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $value ) : (int) $value;
	}

	public static function render_dashboard() {
		include MIGRATOR_PLUGIN_DIR . 'admin/dashboard.php';
	}

	public static function render_export() {
		include MIGRATOR_PLUGIN_DIR . 'admin/export.php';
	}

	public static function render_import() {
		include MIGRATOR_PLUGIN_DIR . 'admin/import.php';
	}
}
