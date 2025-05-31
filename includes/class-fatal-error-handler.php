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
			$deactivated_plugin = $this->maybe_deactivate_plugin( $error );

			// Display our custom error page if headers not sent
			if ( ! headers_sent() ) {
				$this->display_custom_error_page( $error, $deactivated_plugin );
			} else {
				// Let WordPress handle the error display as fallback
				if ( class_exists( 'WP_Fatal_Error_Handler' ) ) {
					$wp_handler = new WP_Fatal_Error_Handler();
					$wp_handler->handle();
				}
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
		$error_file         = $error['file'];
		$deactivated_plugin = null;

		// Get all active plugins
		$active_plugins = $this->get_active_plugins();

		foreach ( $active_plugins as $plugin_base ) {
			$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_base );

			if ( strpos( $error_file, $plugin_dir ) === 0 ) {
				$deactivated_plugin = $this->deactivate_plugin( $plugin_base, $error );
				break;
			}
		}

		return $deactivated_plugin;
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

		// Get plugin data if possible
		$plugin_data = array(
			'Name'        => $plugin_base,
			'PluginURI'   => '',
			'Description' => '',
			'Version'     => '',
			'Author'      => '',
		);

		if ( function_exists( 'get_plugin_data' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_base ) ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_base, false, false );
			if ( empty( $plugin_data['Name'] ) ) {
				$plugin_data['Name'] = $plugin_base;
			}
		}

		// Deactivate the plugin
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $plugin_base );
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "Fatal Plugin Auto Deactivator: Auto-deactivated plugin: {$plugin_base} due to fatal error in: {$error['file']}" );

			// Store deactivated plugin info for admin notice
			$this->store_deactivated_plugin_info( $plugin_base, $error );

			// Return plugin information
			return array(
				'plugin_base'    => $plugin_base,
				'plugin_name'    => $plugin_data['Name'],
				'plugin_version' => $plugin_data['Version'],
				'error'          => $error
			);
		}

		return null;
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
			'date'        => gmdate( 'Y-m-d H:i:s' ),
		);

		// Add to the log (limit to 100 entries to prevent database bloat)
		array_unshift( $deactivation_log, $log_entry );
		if ( count( $deactivation_log ) > 100 ) {
			$deactivation_log = array_slice( $deactivation_log, 0, 100 );
		}

		// Update the log
		update_option( 'fpad_deactivation_log', $deactivation_log );
	}

	/**
	 * Display a custom error page with warning and reload button
	 *
	 * @param array $error Error information
	 * @param array $deactivated_plugin Information about the deactivated plugin
	 */
	protected function display_custom_error_page( $error, $deactivated_plugin ) {
		// Set the HTTP status code
		http_response_code( 500 );

		// Get error type as string
		$error_type = 'Unknown Error';
		switch ( $error['type'] ) {
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

		// Get site name and home URL
		$site_name = 'WordPress Site';
		$home_url  = '/';
		if ( function_exists( 'get_bloginfo' ) ) {
			$site_name = get_bloginfo( 'name' );
			$home_url  = home_url();
		}

		// Prepare plugin information
		$plugin_info = '';
		if ( $deactivated_plugin ) {
			$plugin_name    = $deactivated_plugin['plugin_name'];
			$plugin_version = $deactivated_plugin['plugin_version'] ? ' v' . $deactivated_plugin['plugin_version'] : '';
			$plugin_info    = "<p>The plugin <strong>{$plugin_name}{$plugin_version}</strong> has been automatically deactivated to prevent further errors.</p>";
		}

		// Output the error page
		echo '<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>' . esc_html( $error_type ) . ' - ' . esc_html( $site_name ) . '</title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #f1f1f1;
					color: #444;
					line-height: 1.5;
					margin: 0;
					padding: 0;
				}
				.fpad_error_container {
					max-width: 800px;
					margin: 50px auto;
					padding: 30px;
					background: #fff;
					border-radius: 5px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
				}
				.fpad_error_header {
					background: #dc3232;
					color: #fff;
					padding: 15px 20px;
					margin: -30px -30px 20px;
					border-radius: 5px 5px 0 0;
					display: flex;
					align-items: center;
					justify-content: space-between;
				}
				.fpad_error_header h1 {
					margin: 0;
					font-size: 20px;
					font-weight: 600;
				}
				.fpad_error_details {
					background: #f8f8f8;
					padding: 15px;
					border-radius: 3px;
					margin: 20px 0;
					border-left: 4px solid #ddd;
					overflow-x: auto;
				}
				.fpad_error_message {
					font-family: monospace;
					margin: 0;
					word-break: break-word;
					white-space: pre-wrap;
				}
				.fpad_error_location {
					margin-top: 10px;
					font-size: 14px;
					color: #666;
				}
				.fpad_button {
					display: inline-block;
					padding: 8px 16px;
					background: #0073aa;
					color: #fff;
					text-decoration: none;
					border-radius: 3px;
					cursor: pointer;
					font-size: 14px;
					margin-right: 10px;
				}
				.fpad_button:hover {
					background: #005d8c;
				}
				.fpad_button.fpad_secondary {
					background: #f7f7f7;
					color: #555;
					border: 1px solid #ccc;
				}
				.fpad_button.fpad_secondary:hover {
					background: #f0f0f0;
				}
				.fpad_actions {
					margin-top: 25px;
				}
			</style>
		</head>
		<body>
			<div class="fpad_error_container">
				<div class="fpad_error_header">
					<h1>' . esc_html( $error_type ) . ' Detected</h1>
				</div>
				<p>A fatal error occurred on your website. The Fatal Plugin Auto Deactivator has detected and resolved the issue.</p>' .
		     //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		     ( defined( 'WP_DEBUG' ) && WP_DEBUG ? $plugin_info . '
				<div class="fpad_error_details">
					<p class="fpad_error_message">' . esc_html( $error['message'] ) . '</p>
					<p class="fpad_error_location">File: ' . esc_html( $error['file'] ) . ' on line ' . esc_html( $error['line'] ) . '</p>
				</div>' : '<div class="fpad_error_details">
					<p>A technical error occurred. The issue has been resolved by deactivating the problematic plugin.</p>
				</div>' ) . '
				<p>You can now safely reload the page to continue browsing the site.</p>
				<div class="fpad_actions">
					<a href="' . esc_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) . '" class="fpad_button">Reload Page</a>
					<a href="' . esc_url( $home_url ) . '" class="fpad_button fpad_secondary">Go to Homepage</a>
				</div>
			</div>
		</body>
		</html>';
		exit;
	}
}
