<?php
/**
 * Admin functionality for Fatal Plugin Auto Deactivator
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Admin
 *
 * Handles all admin-related functionality for the plugin
 */
class FPAD_Admin {

	/**
	 * Initialize admin functionality
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'display_admin_notices' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_filter( 'plugin_action_links_fatal-plugin-auto-deactivator/fatal-plugin-auto-deactivator.php', array( __CLASS__, 'add_plugin_action_links' ) );
	}

	/**
	 * Display admin notice for deactivated plugins
	 */
	public static function display_admin_notices() {
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

	/**
	 * Add plugin settings page
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'tools.php',
			__( 'Fatal Plugin Auto Deactivator Log', 'fatal-plugin-auto-deactivator' ),
			__( 'Fatal Plugin Log', 'fatal-plugin-auto-deactivator' ),
			'manage_options',
			'fpad-log',
			array( __CLASS__, 'render_log_page' )
		);
	}

	/**
	 * Add plugin action links
	 *
	 * @param array $links Existing plugin action links
	 *
	 * @return array Modified plugin action links
	 */
	public static function add_plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$log_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=fpad-log' ) ),
			esc_html__( 'View Log', 'fatal-plugin-auto-deactivator' )
		);

		// Add the log link at the beginning of the array
		$links[] = $log_link;

		return $links;
	}

	/**
	 * Render the log page
	 */
	public static function render_log_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle log clearing
		if ( isset( $_POST['fpad_clear_log'] ) && isset( $_POST['fpad_nonce'] ) && wp_verify_nonce( $_POST['fpad_nonce'], 'fpad_clear_log' ) ) {
			update_option( 'fpad_deactivation_log', array() );
			add_settings_error( 'fpad_messages', 'fpad_message', __( 'Fatal Plugin Auto Deactivator log cleared successfully.', 'fatal-plugin-auto-deactivator' ), 'success' );
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
			self::render_log_table( $deactivation_log );
		}

		echo '</div>';
	}

	/**
	 * Render the log table
	 *
	 * @param array $deactivation_log The deactivation log entries
	 */
	private static function render_log_table( $deactivation_log ) {
		// Add custom CSS for the log table
		echo '<style>
			.fpad-log-table tr.log-entry-row:nth-child(4n+1),
			.fpad-log-table tr.log-entry-row:nth-child(4n+2) {
				background-color: #f5f5f5;
			}
			.fpad-log-table tr.log-entry-row:nth-child(4n+3),
			.fpad-log-table tr.log-entry-row:nth-child(4n+4) {
				background-color: #ffffff;
			}
			.fpad-log-table tr.error-row {
				border-bottom: 1px solid #e5e5e5;
			}
		</style>';

		echo '<table class="widefat fpad-log-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Date', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Plugin', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $deactivation_log as $entry ) {
			// Get error type as string
			$error_type = self::get_error_type_string( $entry['error_type'] );

			echo '<tr class="log-entry-row">';
			echo '<td>' . esc_html( $entry['date'] ) . '</td>';
			echo '<td>' . esc_html( $entry['plugin_name'] ) . '<br><small>' . esc_html( $entry['plugin'] ) . '</small></td>';
			echo '<td>' . esc_html( $entry['error_file'] ) . ':' . esc_html( $entry['error_line'] ) . '</td>';
			echo '</tr>';
			echo '<tr class="log-entry-row error-row">';
			echo '<td colspan="3"><strong>' . esc_html( $error_type ) . '</strong><br><pre>' . esc_html( $entry['error_msg'] ) . '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Get error type as human-readable string
	 *
	 * @param int $error_type The error type constant
	 *
	 * @return string The error type string
	 */
	private static function get_error_type_string( $error_type ) {
		switch ( $error_type ) {
			case E_ERROR:
				return 'Fatal Error';
			case E_PARSE:
				return 'Parse Error';
			case E_CORE_ERROR:
				return 'Core Error';
			case E_COMPILE_ERROR:
				return 'Compile Error';
			case E_USER_ERROR:
				return 'User Error';
			case E_RECOVERABLE_ERROR:
				return 'Recoverable Error';
			default:
				return 'Unknown';
		}
	}
}
