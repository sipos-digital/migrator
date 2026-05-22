<?php
/**
 * Export view — profile form + progress UI.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap migrator-wrap" data-migrator-screen="export">
	<h1><?php esc_html_e( 'Migrator – Export', 'migrator' ); ?></h1>

	<form id="migrator-export-form" class="migrator-form" novalidate>

		<fieldset class="migrator-fieldset">
			<legend><?php esc_html_e( 'What to include', 'migrator' ); ?></legend>
			<label><input type="checkbox" name="include_database" checked /> <?php esc_html_e( 'Database', 'migrator' ); ?></label>
			<label><input type="checkbox" name="include_uploads" checked /> <?php esc_html_e( 'Uploads (wp-content/uploads/)', 'migrator' ); ?></label>
			<label><input type="checkbox" name="include_themes" /> <?php esc_html_e( 'Themes (wp-content/themes/)', 'migrator' ); ?></label>
			<label><input type="checkbox" name="include_plugins" /> <?php esc_html_e( 'Plugins (wp-content/plugins/) — excluding Migrator itself', 'migrator' ); ?></label>
			<label><input type="checkbox" name="include_mu_plugins" /> <?php esc_html_e( 'Must-use plugins (wp-content/mu-plugins/)', 'migrator' ); ?></label>
		</fieldset>

		<fieldset class="migrator-fieldset">
			<legend><?php esc_html_e( 'Database exclusions', 'migrator' ); ?></legend>
			<label><input type="checkbox" name="db_skip_spam" /> <?php esc_html_e( 'Skip spam comments', 'migrator' ); ?></label>
			<label><input type="checkbox" name="db_skip_revisions" /> <?php esc_html_e( 'Skip post revisions', 'migrator' ); ?></label>
			<label><input type="checkbox" name="db_skip_trash" /> <?php esc_html_e( 'Skip trashed posts', 'migrator' ); ?></label>
			<label><input type="checkbox" name="db_skip_transients" /> <?php esc_html_e( 'Skip transients (_transient_% options)', 'migrator' ); ?></label>
		</fieldset>

		<fieldset class="migrator-fieldset">
			<legend><?php esc_html_e( 'Custom file exclusions', 'migrator' ); ?></legend>
			<p class="description"><?php esc_html_e( 'One pattern per line. Supports fnmatch globs (*, ?, [abc]) and plain directory prefixes (e.g. uploads/cache/).', 'migrator' ); ?></p>
			<textarea name="file_excludes" rows="4" class="large-text code" placeholder="uploads/cache/&#10;uploads/backup-*"></textarea>
		</fieldset>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Start Export', 'migrator' ); ?></button>
		</p>
	</form>

	<div id="migrator-export-progress" class="migrator-progress" hidden>
		<h2><?php esc_html_e( 'Exporting…', 'migrator' ); ?></h2>
		<div class="migrator-progress-bar"><div class="migrator-progress-bar__fill" style="width: 0%"></div></div>
		<p class="migrator-progress-percent">0%</p>
		<p class="migrator-progress-label"></p>
		<p class="migrator-progress-phase"></p>
		<p>
			<button type="button" class="button migrator-cancel"><?php esc_html_e( 'Cancel', 'migrator' ); ?></button>
		</p>
	</div>

	<div id="migrator-export-done" class="migrator-done" hidden>
		<h2><?php esc_html_e( 'Export ready', 'migrator' ); ?></h2>
		<p><?php esc_html_e( 'Your archive has been built. Click below to download — the job will be cleaned up automatically once the download starts.', 'migrator' ); ?></p>
		<p><a href="#" class="button button-primary migrator-download"><?php esc_html_e( 'Download Archive', 'migrator' ); ?></a></p>
	</div>

	<div id="migrator-export-error" class="notice notice-error" hidden>
		<p class="migrator-error-message"></p>
	</div>
</div>
