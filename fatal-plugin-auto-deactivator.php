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

/**
 * Add plugin settings page
 */
function fpad_add_settings_page() {
	add_submenu_page(
		'tools.php',
		__( 'Fatal Plugin Auto Deactivator Log', 'fatal-plugin-auto-deactivator' ),
		__( 'Fatal Plugin Log', 'fatal-plugin-auto-deactivator' ),
		'manage_options',
		'fpad-log',
		'fpad_render_log_page'
	);
}

add_action( 'admin_menu', 'fpad_add_settings_page' );

/**
 * Render the log page
 */
function fpad_render_log_page() {
	// Check user capabilities
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle log clearing
	if ( isset( $_POST['fpad_clear_log'] ) && isset( $_POST['fpad_nonce'] ) && wp_verify_nonce( $_POST['fpad_nonce'], 'fpad_clear_log' ) ) {
		update_option( 'fpad_deactivation_log', array() );
		add_settings_error( 'fpad_messages', 'fpad_message', __( 'Deactivation log cleared successfully.', 'fatal-plugin-auto-deactivator' ), 'success' );
	}

	// Get the log
	$deactivation_log = get_option( 'fpad_deactivation_log', array() );

	// Start the page
	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Fatal Plugin Auto Deactivator Log', 'fatal-plugin-auto-deactivator' ) . '</h1>';

	// Show any settings errors/messages
	settings_errors( 'fpad_messages' );

	// Clear log button
	echo '<form method="post">';
	wp_nonce_field( 'fpad_clear_log', 'fpad_nonce' );
	submit_button( __( 'Clear Log', 'fatal-plugin-auto-deactivator' ), 'delete', 'fpad_clear_log', false );
	echo '</form><br>';

	// Display the log
	if ( empty( $deactivation_log ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'No plugin deactivations have been logged yet.', 'fatal-plugin-auto-deactivator' ) . '</p></div>';
	} else {
		echo '<table class="widefat striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Date', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Plugin', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $deactivation_log as $entry ) {
			// Get error type as string
			$error_type = 'Unknown';
			switch ( $entry['error_type'] ) {
				case E_ERROR:
					$error_type = 'Fatal Error';
					break;
				case E_PARSE:
					$error_type = 'Parse Error';
					break;
				case E_CORE_ERROR:
					$error_type = 'Core Error';
					break;
				case E_COMPILE_ERROR:
					$error_type = 'Compile Error';
					break;
				case E_USER_ERROR:
					$error_type = 'User Error';
					break;
				case E_RECOVERABLE_ERROR:
					$error_type = 'Recoverable Error';
					break;
			}

			echo '<tr>';
			echo '<td>' . esc_html( $entry['date'] ) . '</td>';
			echo '<td>' . esc_html( $entry['plugin_name'] ) . '<br><small>' . esc_html( $entry['plugin'] ) . '</small></td>';
			echo '<td><strong>' . esc_html( $error_type ) . '</strong><br>' . esc_html( $entry['error_msg'] ) . '</td>';
			echo '<td>' . esc_html( $entry['error_file'] ) . ':' . esc_html( $entry['error_line'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	echo '</div>';
}