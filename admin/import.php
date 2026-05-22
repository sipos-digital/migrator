<?php
/**
 * Import view — chunked upload + progress UI.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap migrator-wrap" data-migrator-screen="import">
	<h1><?php esc_html_e( 'Migrator – Import', 'migrator' ); ?></h1>
	<p><?php esc_html_e( 'Upload a Migrator archive to restore the database and files. This will overwrite existing data — back up first.', 'migrator' ); ?></p>

	<form id="migrator-import-form" class="migrator-form">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="migrator_archive"><?php esc_html_e( 'Archive (.zip)', 'migrator' ); ?></label></th>
				<td>
					<input type="file" id="migrator_archive" name="migrator_archive" accept=".zip,application/zip" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="migrator_new_url"><?php esc_html_e( 'New Site URL', 'migrator' ); ?></label></th>
				<td>
					<input type="url" id="migrator_new_url" name="migrator_new_url" class="regular-text" value="<?php echo esc_attr( get_site_url() ); ?>" />
					<p class="description"><?php esc_html_e( 'URLs in the archive will be rewritten to this value.', 'migrator' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Start Import', 'migrator' ); ?></button>
		</p>
	</form>

	<div id="migrator-import-progress" class="migrator-progress" hidden>
		<h2><?php esc_html_e( 'Importing…', 'migrator' ); ?></h2>
		<div class="migrator-progress-bar"><div class="migrator-progress-bar__fill" style="width: 0%"></div></div>
		<p class="migrator-progress-percent">0%</p>
		<p class="migrator-progress-label"></p>
		<p class="migrator-progress-phase"></p>
		<p>
			<button type="button" class="button migrator-cancel"><?php esc_html_e( 'Cancel', 'migrator' ); ?></button>
		</p>
	</div>

	<div id="migrator-import-done" class="migrator-done" hidden>
		<h2><?php esc_html_e( 'Import complete', 'migrator' ); ?></h2>
		<p><?php esc_html_e( 'The site has been restored. You may need to log in again if user accounts changed.', 'migrator' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Go to Dashboard', 'migrator' ); ?></a></p>
	</div>

	<div id="migrator-import-error" class="notice notice-error" hidden>
		<p class="migrator-error-message"></p>
	</div>
</div>
