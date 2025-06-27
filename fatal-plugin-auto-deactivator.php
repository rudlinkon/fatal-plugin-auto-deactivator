<?php
/**
 * Plugin Name: Fatal Plugin Auto Deactivator
 * Plugin URI: https://wordpress.org/plugins/fatal-plugin-auto-deactivator/
 * Description: Automatically deactivates plugins that cause fatal errors to prevent site crashes.
 * Version: 1.1.0
 * Author: Linkon Miyan
 * Author URI: https://profiles.wordpress.org/rudlinkon/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: fatal-plugin-auto-deactivator
 * Domain Path: /languages
 * Requires at least: 5.3
 * Requires PHP: 7.0
 * Tested up to: 6.8
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants
if ( ! defined( 'FPAD_VERSION' ) ) {
	define( 'FPAD_VERSION', '1.1.0' );
}

if ( ! defined( 'FPAD_PLUGIN_BASENAME' ) ) {
	define( 'FPAD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'FPAD_PLUGIN_DIR' ) ) {
	define( 'FPAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'FPAD_PLUGIN_URL' ) ) {
	define( 'FPAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Include required files
 */
require_once FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php';
require_once FPAD_PLUGIN_DIR . 'includes/class-dropin-manager.php';
require_once FPAD_PLUGIN_DIR . 'includes/class-admin.php';
require_once FPAD_PLUGIN_DIR . 'includes/class-plugin-lifecycle.php';
require_once FPAD_PLUGIN_DIR . 'includes/class-utils.php';

/**
 * Initialize plugin classes
 */
FPAD_Utils::init();
FPAD_Admin::init();
FPAD_Plugin_Lifecycle::init();

/**
 * Register plugin hooks
 */
register_activation_hook( __FILE__, array( 'FPAD_Plugin_Lifecycle', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FPAD_Plugin_Lifecycle', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'FPAD_Plugin_Lifecycle', 'uninstall' ) );
