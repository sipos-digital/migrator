<?php
/**
 * Dashboard view.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap migrator-wrap">
	<h1><?php esc_html_e( 'Migrator', 'migrator' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Migrate WordPress sites between environments. Export a full package (database + uploads) and import it on another installation.', 'migrator' ); ?>
	</p>

	<div class="migrator-cards">
		<div class="migrator-card">
			<h2><?php esc_html_e( 'Export', 'migrator' ); ?></h2>
			<p><?php esc_html_e( 'Package this site into a downloadable archive.', 'migrator' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=migrator-export' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Go to Export', 'migrator' ); ?>
			</a>
		</div>

		<div class="migrator-card">
			<h2><?php esc_html_e( 'Import', 'migrator' ); ?></h2>
			<p><?php esc_html_e( 'Restore a Migrator archive onto this installation.', 'migrator' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=migrator-import' ) ); ?>" class="button">
				<?php esc_html_e( 'Go to Import', 'migrator' ); ?>
			</a>
		</div>
	</div>

	<h2><?php esc_html_e( 'Environment', 'migrator' ); ?></h2>
	<table class="widefat striped migrator-env">
		<tbody>
			<tr><th><?php esc_html_e( 'Site URL', 'migrator' ); ?></th><td><?php echo esc_html( get_site_url() ); ?></td></tr>
			<tr><th><?php esc_html_e( 'WordPress Version', 'migrator' ); ?></th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'PHP Version', 'migrator' ); ?></th><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
			<tr><th><?php esc_html_e( 'ZipArchive', 'migrator' ); ?></th><td><?php echo class_exists( 'ZipArchive' ) ? '&#10003;' : '&#10007;'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Upload Max', 'migrator' ); ?></th><td><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Post Max', 'migrator' ); ?></th><td><?php echo esc_html( ini_get( 'post_max_size' ) ); ?></td></tr>
		</tbody>
	</table>
</div>
