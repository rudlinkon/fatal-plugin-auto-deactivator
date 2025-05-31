<?php
/**
 * Utility functions for Fatal Plugin Auto Deactivator
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Utils
 *
 * Handles utility functions for the plugin
 */
class FPAD_Utils {

	/**
	 * Initialize utility functions
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
	}

	/**
	 * Load plugin text domain for translations
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'fatal-plugin-auto-deactivator', false, dirname( plugin_basename( FPAD_PLUGIN_DIR . 'fatal-plugin-auto-deactivator.php' ) ) . '/languages' );
	}
}
