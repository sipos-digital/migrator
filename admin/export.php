<?php
/**
 * Export view.
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap migrator-wrap">
	<h1><?php esc_html_e( 'Migrator – Export', 'migrator' ); ?></h1>
	<p><?php esc_html_e( 'Create a single .zip archive containing the database and the uploads directory. Themes and plugins are not included by default.', 'migrator' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="migrator_export" />
		<?php wp_nonce_field( Migrator_Admin::NONCE_ACTION ); ?>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Download Archive', 'migrator' ); ?>
			</button>
		</p>
	</form>

	<p class="description">
		<?php esc_html_e( 'Large sites may take a while. Make sure your PHP max_execution_time is high enough.', 'migrator' ); ?>
	</p>
</div>
