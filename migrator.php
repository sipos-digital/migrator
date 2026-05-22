<?php
/**
 * Plugin Name:       Migrator
 * Plugin URI:        https://github.com/sipos-digital/migrator
 * Description:       Migrate WordPress sites between environments. Export your site (database + files) into a single archive and import it on another installation, with chunked AJAX progress and configurable inclusion filters.
 * Version:           0.2.6
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Sipos Digital
 * Author URI:        https://github.com/sipos-digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       migrator
 *
 * @package Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MIGRATOR_VERSION', '0.2.6' );
define( 'MIGRATOR_PLUGIN_FILE', __FILE__ );
define( 'MIGRATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIGRATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MIGRATOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-profile.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-job.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-exporter.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-importer.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-ajax.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-admin.php';

register_activation_hook( __FILE__, array( 'Migrator_Admin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Migrator_Admin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Migrator_Admin', 'init' ) );

/**
 * GitHub-based update checker.
 *
 * Updates are served from GitHub releases on sipos-digital/migrator.
 * Publishing an update:
 *   1. Bump the Version header above (e.g. 0.1.0 → 0.2.0)
 *   2. Commit and push to main
 *   3. Create a GitHub release with tag `v0.2.0` matching the version
 *
 * The auto-generated source zip is used; if a packaged plugin zip is
 * attached to the release it will be preferred (enableReleaseAssets).
 */
require_once MIGRATOR_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

$migrator_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/sipos-digital/migrator/',
	__FILE__,
	'migrator'
);

$migrator_update_checker->getVcsApi()->enableReleaseAssets();