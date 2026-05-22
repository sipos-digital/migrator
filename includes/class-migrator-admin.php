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
		// Reserve ~30% of the limit for FormData overhead (boundaries, fields, headers).
		$chunk_bytes = max( 262144, (int) ( $limit * 0.7 ) );

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
