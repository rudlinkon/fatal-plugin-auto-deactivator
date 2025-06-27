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
		add_action( 'upgrader_process_complete', array( __CLASS__, 'plugin_upgrade_hook' ), 10, 2 );
	}

	/**
	 * Load plugin text domain for translations
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'fatal-plugin-auto-deactivator', false, dirname( plugin_basename( FPAD_PLUGIN_DIR . 'fatal-plugin-auto-deactivator.php' ) ) . '/languages' );
	}

	/**
	 * Handles plugin upgrade hook to manage the removal of drop-ins during the plugin update process.
	 *
	 * @param object $upgrader_object The upgrader object responsible for the update process.
	 * @param array $options An array of options/action arguments related to the upgrade process.
	 *                                Expected keys include 'action', 'type', 'plugins', and 'plugin'.
	 *                                - 'action': The type of action being performed, e.g., 'update'.
	 *                                - 'type': The type of component being updated, e.g., 'plugin'.
	 *                                - 'plugins': (Optional) Array of plugin basenames involved in the update.
	 *                                - 'plugin': (Optional) Specific plugin basename being updated.
	 *
	 * @return void
	 */
	public function plugin_upgrade_hook( $upgrader_object, $options ) {
		if ( isset( $options['action'], $options['type'] ) && $options['action'] === 'update' && $options['type'] === 'plugin' ) {
			if ( ( isset( $options['plugins'] ) && in_array( FPAD_PLUGIN_BASENAME, $options['plugins'] ) ) ||
			     ( isset( $options['plugin'] ) && $options['plugin'] === FPAD_PLUGIN_BASENAME ) ) {
				// Remove the drop-in
				$dropin_manager = new FPAD_Dropin_Manager();
				$dropin_manager->remove_dropin();
				$dropin_manager->install_dropin();
			}
		}
	}
}
