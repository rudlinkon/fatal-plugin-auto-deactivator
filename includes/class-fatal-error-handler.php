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

			// Always record the fatal error in our log, regardless of WP_DEBUG and
			// regardless of whether the error could be attributed to a plugin.
			$this->add_to_deactivation_log( $error, $deactivated_plugin );

			// Display our custom error page
			$this->display_custom_error_page( $error, $deactivated_plugin );
		} catch ( Throwable $e ) {
			// Catch any error or exception thrown by the handler and remain silent
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
	 * Determine where a fatal error originated based on its file path.
	 *
	 * Order matters: mu-plugins and plugins live under wp-content, and drop-ins
	 * sit directly in the wp-content root, so the most specific directories are
	 * checked before the broader ones.
	 *
	 * @param array $error Error information.
	 * @return string One of: plugin, theme, mu-plugin, dropin, core, unknown.
	 */
	protected function detect_error_source( $error ) {
		$file = isset( $error['file'] ) ? $error['file'] : '';

		if ( '' === $file ) {
			return 'unknown';
		}

		// Normalize directory separators so prefix matching works on Windows too.
		$file = str_replace( '\\', '/', $file );

		$normalize = function ( $path ) {
			return rtrim( str_replace( '\\', '/', $path ), '/' );
		};

		// Must-use plugins (checked before the generic plugins directory).
		if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( $file, $normalize( WPMU_PLUGIN_DIR ) . '/' ) ) {
			return 'mu-plugin';
		}

		// Regular plugins.
		if ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( $file, $normalize( WP_PLUGIN_DIR ) . '/' ) ) {
			return 'plugin';
		}

		// Themes.
		$theme_root = '';
		if ( function_exists( 'get_theme_root' ) ) {
			$theme_root = $normalize( get_theme_root() );
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$theme_root = $normalize( WP_CONTENT_DIR ) . '/themes';
		}
		if ( '' !== $theme_root && 0 === strpos( $file, $theme_root . '/' ) ) {
			return 'theme';
		}

		// Drop-ins live directly in the wp-content root.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_dir = $normalize( WP_CONTENT_DIR );
			$dropins     = array(
				'advanced-cache.php',
				'object-cache.php',
				'db.php',
				'db-error.php',
				'fatal-error-handler.php',
				'maintenance.php',
				'php-error.php',
				'sunrise.php',
				'blog-deleted.php',
				'blog-inactive.php',
				'blog-suspended.php',
			);
			if ( in_array( $file, array_map( function ( $name ) use ( $content_dir ) {
				return $content_dir . '/' . $name;
			}, $dropins ), true ) ) {
				return 'dropin';
			}
		}

		// WordPress core files.
		if ( defined( 'ABSPATH' ) ) {
			$abspath = $normalize( ABSPATH );
			if ( 0 === strpos( $file, $abspath . '/wp-includes/' ) || 0 === strpos( $file, $abspath . '/wp-admin/' ) ) {
				return 'core';
			}
		}

		return 'unknown';
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
	}

	/**
	 * Add an entry to the permanent error log.
	 *
	 * Called for every detected fatal error so administrators can review it on
	 * the Fatal Plugin Log page, whether or not a plugin could be attributed and
	 * deactivated.
	 *
	 * @param array      $error              Error information
	 * @param array|null $deactivated_plugin Info about the deactivated plugin, or null if none was identified
	 */
	protected function add_to_deactivation_log( $error, $deactivated_plugin = null ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		// Get the current log
		$deactivation_log = get_option( 'fpad_deactivation_log', array() );

		// Resolve plugin details from the deactivation result, if any.
		$plugin_base = $deactivated_plugin ? $deactivated_plugin['plugin_base'] : '';
		$plugin_name = $deactivated_plugin ? $deactivated_plugin['plugin_name'] : '';

		// Create a new log entry
		$log_entry = array(
			'plugin'      => $plugin_base,
			'plugin_name' => $plugin_name,
			'deactivated' => ! empty( $deactivated_plugin ),
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

		// Tailor the messaging to the detected source of the error and whether we
		// were actually able to take corrective action. Only a fatal attributed to
		// a regular plugin can be auto-deactivated; everything else is reported
		// honestly as requiring manual attention.
		$source = $this->detect_error_source( $error );

		switch ( $source ) {
			case 'plugin':
				if ( $deactivated_plugin ) {
					$intro_message   = 'A fatal error was caused by a plugin on your website. The Fatal Plugin Auto Deactivator identified the plugin and automatically deactivated it to resolve the issue.';
					$generic_message = 'A plugin caused a technical error. The issue has been resolved by automatically deactivating the problematic plugin.';
					$closing_message = 'You can now safely reload the page to continue browsing the site.';
				} else {
					$intro_message   = 'A fatal error appears to have been caused by a plugin, but it could not be deactivated automatically. The plugin may need to be disabled manually.';
					$generic_message = 'A plugin caused a technical error, but it could not be deactivated automatically and may require manual attention.';
					$closing_message = 'You can try reloading the page, but the error may persist until the plugin is disabled manually.';
				}
				break;

			case 'theme':
				$intro_message   = 'A fatal error appears to be related to your active theme. Themes cannot be deactivated automatically, so this issue could not be resolved for you.';
				$generic_message = 'A technical error appears to originate from your theme and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the theme issue is fixed manually.';
				break;

			case 'mu-plugin':
				$intro_message   = 'A fatal error appears to originate from a must-use (MU) plugin. Must-use plugins cannot be deactivated automatically, so this issue could not be resolved for you.';
				$generic_message = 'A technical error appears to originate from a must-use plugin and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the must-use plugin is fixed manually.';
				break;

			case 'dropin':
				$intro_message   = 'A fatal error appears to be related to a WordPress drop-in. Drop-ins cannot be deactivated automatically, so this issue could not be resolved for you.';
				$generic_message = 'A technical error appears to originate from a drop-in and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the drop-in issue is fixed manually.';
				break;

			case 'core':
				$intro_message   = 'A fatal error appears to be related to WordPress core. Core errors cannot be resolved automatically and usually require manual attention.';
				$generic_message = 'A technical error appears to originate from WordPress core and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the underlying issue is fixed manually.';
				break;

			default:
				$intro_message   = 'A fatal error occurred on your website. The Fatal Plugin Auto Deactivator could not attribute it to a specific plugin, so it could not be resolved automatically.';
				$generic_message = 'A technical error occurred. Its source could not be determined automatically, so it could not be resolved and may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the underlying issue is fixed manually.';
				break;
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
				<p>' . esc_html( $intro_message ) . '</p>' .
		     //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		     ( defined( 'WP_DEBUG' ) && WP_DEBUG ? $plugin_info . '
				<div class="fpad_error_details">
					<p class="fpad_error_message">' . esc_html( $error['message'] ) . '</p>
					<p class="fpad_error_location">File: ' . esc_html( $error['file'] ) . ' on line ' . esc_html( $error['line'] ) . '</p>
				</div>' : '<div class="fpad_error_details">
					<p>' . esc_html( $generic_message ) . '</p>
				</div>' ) . '
				<p>' . esc_html( $closing_message ) . '</p>
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
