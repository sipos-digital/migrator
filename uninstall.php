<?php
/**
 * Uninstall handler — runs when the user deletes the plugin from the WP UI.
 *
 * @package Migrator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$upload_dir = wp_upload_dir();
$work_dir   = trailingslashit( $upload_dir['basedir'] ) . 'migrator';

if ( is_dir( $work_dir ) ) {
	$items = glob( trailingslashit( $work_dir ) . '*' );
	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( is_file( $item ) ) {
				@unlink( $item );
			}
		}
	}
	@rmdir( $work_dir );
}
