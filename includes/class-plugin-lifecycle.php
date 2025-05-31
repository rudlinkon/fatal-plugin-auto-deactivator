<?php
/**
 * Plugin lifecycle management for Fatal Plugin Auto Deactivator
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Plugin_Lifecycle
 *
 * Handles plugin activation, deactivation, and uninstall processes
 */
class FPAD_Plugin_Lifecycle {

	/**
	 * Initialize lifecycle hooks
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'check_dropin' ) );
	}

	/**
	 * Plugin activation hook
	 */
	public static function activate() {
		// Install the drop-in
		$dropin_manager = new FPAD_Dropin_Manager();
		$dropin_manager->install_dropin();
	}

	/**
	 * Plugin deactivation hook
	 */
	public static function deactivate() {
		// Remove the drop-in
		$dropin_manager = new FPAD_Dropin_Manager();
		$dropin_manager->remove_dropin();
	}

	/**
	 * Check if the drop-in is installed and working
	 */
	public static function check_dropin() {
		$dropin_manager = new FPAD_Dropin_Manager();

		// If the drop-in is not installed, try to install it
		if ( ! $dropin_manager->is_dropin_installed() ) {
			$dropin_manager->install_dropin();
		}
	}

	/**
	 * Clean up plugin data on uninstall
	 */
	public static function uninstall() {
		// Remove the drop-in
		$dropin_manager = new FPAD_Dropin_Manager();
		$dropin_manager->remove_dropin();

		// Delete plugin options
		delete_option( 'fpad_deactivated_plugins' );
		delete_option( 'fpad_deactivation_log' );
	}
}
