<?php
/**
 * WordPress Fatal Error Handler
 *
 * This drop-in is part of the Fatal Plugin Auto Deactivator plugin.
 * It automatically deactivates plugins that cause fatal errors.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// Define constants needed for WordPress if they're not already defined
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/' );
}

// Define the path to the plugin directory
if ( ! defined( 'FPAD_PLUGIN_DIR' ) ) {
	// The drop-in is in wp-content, so the plugins directory is wp-content/plugins
	define( 'FPAD_PLUGIN_DIR', dirname( __FILE__ ) . '/plugins/fatal-plugin-auto-deactivator/' );
}

// Include the fatal error handler class
if ( ! class_exists( 'FPAD_Fatal_Error_Handler' ) ) {
	require_once FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php';
}

// Return an instance of our custom error handler
return new FPAD_Fatal_Error_Handler();
