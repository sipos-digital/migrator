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

	const MENU_SLUG     = 'migrator';
	const CAPABILITY    = 'manage_options';
	const NONCE_ACTION  = 'migrator_action';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_migrator_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_migrator_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	public static function activate() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'migrator';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	public static function deactivate() {
		// Intentionally left blank — keep user data on deactivate.
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

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'migrator' ),
			__( 'Dashboard', 'migrator' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Export', 'migrator' ),
			__( 'Export', 'migrator' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-export',
			array( __CLASS__, 'render_export' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Import', 'migrator' ),
			__( 'Import', 'migrator' ),
			self::CAPABILITY,
			self::MENU_SLUG . '-import',
			array( __CLASS__, 'render_import' )
		);
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
			array( 'jquery' ),
			MIGRATOR_VERSION,
			true
		);
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

	public static function handle_export() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'migrator' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$exporter = new Migrator_Exporter();
		$result   = $exporter->export();

		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'export', 'error', $result->get_error_message() );
		}

		// Stream file to browser.
		$exporter->stream_archive( $result );
	}

	public static function handle_import() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'migrator' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		if ( empty( $_FILES['migrator_archive'] ) || ! is_array( $_FILES['migrator_archive'] ) ) {
			self::redirect_with_notice( 'import', 'error', __( 'No archive uploaded.', 'migrator' ) );
		}

		$new_url = isset( $_POST['migrator_new_url'] ) ? esc_url_raw( wp_unslash( $_POST['migrator_new_url'] ) ) : '';
		$importer = new Migrator_Importer();
		$result   = $importer->import( $_FILES['migrator_archive'], $new_url );

		if ( is_wp_error( $result ) ) {
			self::redirect_with_notice( 'import', 'error', $result->get_error_message() );
		}

		self::redirect_with_notice( 'import', 'success', __( 'Import completed successfully.', 'migrator' ) );
	}

	public static function render_notices() {
		if ( empty( $_GET['migrator_notice'] ) || empty( $_GET['migrator_status'] ) ) {
			return;
		}

		$status  = sanitize_key( wp_unslash( $_GET['migrator_status'] ) );
		$message = sanitize_text_field( wp_unslash( $_GET['migrator_notice'] ) );
		$class   = ( 'success' === $status ) ? 'notice-success' : 'notice-error';

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	private static function redirect_with_notice( $screen, $status, $message ) {
		$url = add_query_arg(
			array(
				'page'             => self::MENU_SLUG . '-' . $screen,
				'migrator_status'  => $status,
				'migrator_notice'  => rawurlencode( $message ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}