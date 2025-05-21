<?php
/**
 * Custom Fatal Error Handler for WordPress
 *
 * This class extends WordPress's built-in fatal error handling to automatically
 * deactivate plugins that cause fatal errors.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Fatal_Error_Handler
 */
class FPAD_Fatal_Error_Handler {

	/**
	 * Handle fatal errors
	 */
	public function handle() {
		try {
			// Detect the error
			$error = $this->detect_error();
			if ( ! $error ) {
				return;
			}

			// Try to deactivate the problematic plugin
			$this->maybe_deactivate_plugin( $error );

			// Let WordPress handle the error display
			if ( class_exists( 'WP_Fatal_Error_Handler' ) ) {
				$wp_handler = new WP_Fatal_Error_Handler();
				$wp_handler->handle();
			}
		} catch ( Exception $e ) {
			// Catch exceptions and remain silent
		}
	}

	/**
	 * Detect if a fatal error occurred
	 *
	 * @return array|false Error array or false if no error
	 */
	protected function detect_error() {
		$error = error_get_last();

		if ( ! $error ) {
			return false;
		}

		$fatal_error_types = array(
			E_ERROR,
			E_PARSE,
			E_CORE_ERROR,
			E_COMPILE_ERROR,
			E_USER_ERROR,
			E_RECOVERABLE_ERROR,
		);

		if ( ! in_array( $error['type'], $fatal_error_types, true ) ) {
			return false;
		}

		return $error;
	}

	/**
	 * Try to deactivate the plugin that caused the error
	 *
	 * @param array $error Error information
	 */
	protected function maybe_deactivate_plugin( $error ) {
		$error_file = $error['file'];

		// Get all active plugins
		$active_plugins = $this->get_active_plugins();

		foreach ( $active_plugins as $plugin_base ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_base );

			if ( strpos( $error_file, $plugin_dir ) === 0 ) {
				$this->deactivate_plugin( $plugin_base, $error );
				break;
			}
		}
	}

	/**
	 * Get all active plugins
	 *
	 * @return array List of active plugins
	 */
	protected function get_active_plugins() {
		// Make sure we have access to WordPress functions
		if ( ! function_exists( 'get_option' ) ) {
			if ( file_exists( ABSPATH . 'wp-includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-includes/plugin.php';
			}
			if ( file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
		}

		// Get active plugins
		return get_option( 'active_plugins', array() );
	}

	/**
	 * Deactivate a plugin and log the error
	 *
	 * @param string $plugin_base Plugin base name
	 * @param array $error Error information
	 */
	protected function deactivate_plugin( $plugin_base, $error ) {
		// Make sure we have access to WordPress functions
		if ( ! function_exists( 'deactivate_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Deactivate the plugin
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $plugin_base );
			error_log( "Fatal Plugin Auto Deactivator: Auto-deactivated plugin: {$plugin_base} due to fatal error in: {$error['file']}" );

			// Store deactivated plugin info for admin notice
			$this->store_deactivated_plugin_info( $plugin_base, $error );
		}
	}

	/**
	 * Store information about the deactivated plugin for admin notices
	 *
	 * @param string $plugin_base Plugin base name
	 * @param array $error Error information
	 */
	protected function store_deactivated_plugin_info( $plugin_base, $error ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		// Store for admin notice (temporary storage, cleared after displaying)
		$deactivated_plugins   = get_option( 'fpad_deactivated_plugins', array() );
		$deactivated_plugins[] = array(
			'plugin' => $plugin_base,
			'error'  => $error,
			'time'   => time(),
		);
		update_option( 'fpad_deactivated_plugins', $deactivated_plugins );

		// Store in permanent log
		$this->add_to_deactivation_log( $plugin_base, $error );
	}

	/**
	 * Add an entry to the permanent deactivation log
	 *
	 * @param string $plugin_base Plugin base name
	 * @param array $error Error information
	 */
	protected function add_to_deactivation_log( $plugin_base, $error ) {
		// Get the current log
		$deactivation_log = get_option( 'fpad_deactivation_log', array() );

		// Get plugin data if possible
		$plugin_name = $plugin_base;
		if ( function_exists( 'get_plugin_data' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_base ) ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_base );
			$plugin_name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin_base;
		}

		// Create a new log entry
		$log_entry = array(
			'plugin'      => $plugin_base,
			'plugin_name' => $plugin_name,
			'error_type'  => $error['type'],
			'error_msg'   => $error['message'],
			'error_file'  => $error['file'],
			'error_line'  => $error['line'],
			'time'        => time(),
			'date'        => date( 'Y-m-d H:i:s' ),
		);

		// Add to the log (limit to 100 entries to prevent database bloat)
		array_unshift( $deactivation_log, $log_entry );
		if ( count( $deactivation_log ) > 100 ) {
			$deactivation_log = array_slice( $deactivation_log, 0, 100 );
		}

		// Update the log
		update_option( 'fpad_deactivation_log', $deactivation_log );
	}
}
