<?php
/**
 * Plugin Name: Fatal Plugin Auto Deactivator
 * Plugin URI: https://wordpress.org/plugins/fatal-plugin-auto-deactivator/
 * Description: Automatically deactivates plugins that cause fatal errors to prevent site crashes.
 * Version: 0.0.1
 * Author: Linkon Miyan
 * Author URI: https://profiles.wordpress.org/rudlinkon/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: fatal-plugin-auto-deactivator
 * Domain Path: /languages
 * Requires at least: 5.2
 * Requires PHP: 7.0
 * Tested up to: 6.8
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants
if ( ! defined( 'FPAD_VERSION' ) ) {
	define( 'FPAD_VERSION', '0.0.1' );
}

if ( ! defined( 'FPAD_PLUGIN_DIR' ) ) {
	define( 'FPAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'FPAD_PLUGIN_URL' ) ) {
	define( 'FPAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Load plugin text domain for translations
 */
function fpad_load_textdomain() {
	load_plugin_textdomain( 'fatal-plugin-auto-deactivator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'fpad_load_textdomain' );

/**
 * Include required files
 */
require_once FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php';
require_once FPAD_PLUGIN_DIR . 'includes/class-dropin-manager.php';

/**
 * Plugin activation hook
 */
function fpad_activate() {
	// Install the drop-in
	$dropin_manager = new FPAD_Dropin_Manager();
	$dropin_manager->install_dropin();
}

register_activation_hook( __FILE__, 'fpad_activate' );

/**
 * Plugin deactivation hook
 */
function fpad_deactivate() {
	// Remove the drop-in
	$dropin_manager = new FPAD_Dropin_Manager();
	$dropin_manager->remove_dropin();
}

register_deactivation_hook( __FILE__, 'fpad_deactivate' );

/**
 * Check if the drop-in is installed and working
 */
function fpad_check_dropin() {
	$dropin_manager = new FPAD_Dropin_Manager();

	// If the drop-in is not installed, try to install it
	if ( ! $dropin_manager->is_dropin_installed() ) {
		$dropin_manager->install_dropin();
	}
}

add_action( 'admin_init', 'fpad_check_dropin' );

/**
 * Display admin notice for deactivated plugins
 */
function fpad_admin_notices() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$deactivated_plugins = get_option( 'fpad_deactivated_plugins', [] );

	if ( empty( $deactivated_plugins ) ) {
		return;
	}

	foreach ( $deactivated_plugins as $key => $plugin_data ) {
		$plugin_file     = $plugin_data['plugin'];
		$plugin_data_obj = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
		$plugin_name     = $plugin_data_obj['Name'] ?: $plugin_file;
		$error_message   = $plugin_data['error']['message'];

		echo '<div class="notice notice-error is-dismissible">';
		echo '<p>' . sprintf(
			/* translators: 1: Plugin name, 2: Error message */
				esc_html__( 'Fatal Plugin Auto Deactivator has deactivated "%1$s" due to a fatal error: %2$s', 'fatal-plugin-auto-deactivator' ),
				'<strong>' . esc_html( $plugin_name ) . '</strong>',
				esc_html( $error_message )
			) . '</p>';
		echo '</div>';
	}

	// Clear the notices after displaying them
	update_option( 'fpad_deactivated_plugins', [] );
}

add_action( 'admin_notices', 'fpad_admin_notices' );

/**
 * Register uninstall hook
 */
register_uninstall_hook( __FILE__, 'fpad_uninstall' );

/**
 * Clean up plugin data on uninstall
 */
function fpad_uninstall() {
	// Remove the drop-in
	$dropin_manager = new FPAD_Dropin_Manager();
	$dropin_manager->remove_dropin();

	// Delete plugin options
	delete_option( 'fpad_deactivated_plugins' );
	delete_option( 'fpad_deactivation_log' );
}