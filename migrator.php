<?php
/**
 * Plugin Name:       Migrator
 * Plugin URI:        https://github.com/sipos-digital/migrator
 * Description:       Migrate WordPress sites between environments. Export your site (database + uploads) into a single archive and import it on another installation.
 * Version:           0.1.0
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

define( 'MIGRATOR_VERSION', '0.1.0' );
define( 'MIGRATOR_PLUGIN_FILE', __FILE__ );
define( 'MIGRATOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MIGRATOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MIGRATOR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-exporter.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-importer.php';
require_once MIGRATOR_PLUGIN_DIR . 'includes/class-migrator-admin.php';

register_activation_hook( __FILE__, array( 'Migrator_Admin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Migrator_Admin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Migrator_Admin', 'init' ) );