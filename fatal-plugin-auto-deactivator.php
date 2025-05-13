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
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Tested up to: 6.8
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants
define( 'FPAD_VERSION', '1.0.0' );
define( 'FPAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FPAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin text domain for translations
 */
function fpad_load_textdomain() {
	load_plugin_textdomain( 'fatal-plugin-auto-deactivator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'plugins_loaded', 'fpad_load_textdomain' );

/**
 * Auto-deactivate plugins that cause fatal errors
 */
add_action( 'shutdown', function () {
	$error = error_get_last();

	if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ] ) ) {
		$error_file = $error['file'];

		// Get all active plugins
		$active_plugins = get_option( 'active_plugins', [] );

		foreach ( $active_plugins as $plugin_base ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_base );

			if ( strpos( $error_file, $plugin_dir ) === 0 ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				deactivate_plugins( $plugin_base );
				error_log( "Auto-deactivated plugin: {$plugin_base} due to fatal error in: {$error_file}" );

				// Store deactivated plugin info for admin notice
				$deactivated_plugins   = get_option( 'fpad_deactivated_plugins', [] );
				$deactivated_plugins[] = [
					'plugin' => $plugin_base,
					'error'  => $error,
					'time'   => time()
				];
				update_option( 'fpad_deactivated_plugins', $deactivated_plugins );
			}
		}
	}
} );

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
	delete_option( 'fpad_deactivated_plugins' );
}