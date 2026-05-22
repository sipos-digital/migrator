<?php
/**
 * Import view.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap migrator-wrap">
	<h1><?php esc_html_e( 'Migrator – Import', 'migrator' ); ?></h1>
	<p><?php esc_html_e( 'Upload a Migrator archive to restore the database and uploads. This will overwrite existing data — back up first.', 'migrator' ); ?></p>

	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="migrator_import" />
		<?php wp_nonce_field( Migrator_Admin::NONCE_ACTION ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="migrator_archive"><?php esc_html_e( 'Archive (.zip)', 'migrator' ); ?></label>
				</th>
				<td>
					<input type="file" id="migrator_archive" name="migrator_archive" accept=".zip,application/zip" required />
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="migrator_new_url"><?php esc_html_e( 'New Site URL', 'migrator' ); ?></label>
				</th>
				<td>
					<input type="url" id="migrator_new_url" name="migrator_new_url" class="regular-text" value="<?php echo esc_attr( get_site_url() ); ?>" />
					<p class="description"><?php esc_html_e( 'URLs in the archive will be rewritten to this value. Leave as-is unless you know what you are doing.', 'migrator' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'This will overwrite the current database and uploads. Continue?', 'migrator' ) ); ?>');">
				<?php esc_html_e( 'Run Import', 'migrator' ); ?>
			</button>
		</p>
	</form>
</div>
