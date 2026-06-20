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
			echo '<p style="font-family: monospace;word-break: break-word;white-space: pre-wrap;">' . sprintf(
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
		//phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No fatal errors have been logged yet. When a plugin (or other code) triggers a fatal error, it will appear here.', 'fatal-plugin-auto-deactivator' ) . '</p></div>';
		} else {
			self::render_log_summary( $deactivation_log );
			self::render_log_table( $deactivation_log );
		}

		echo '</div>';
	}

	/**
	 * Render a summary bar with at-a-glance counts.
	 *
	 * @param array $deactivation_log The deactivation log entries
	 */
	private static function render_log_summary( $deactivation_log ) {
		$total        = count( $deactivation_log );
		$deactivated  = 0;
		$unattributed = 0;
		$latest_time  = 0;

		foreach ( $deactivation_log as $entry ) {
			$was_deactivated = isset( $entry['deactivated'] ) ? $entry['deactivated'] : ! empty( $entry['plugin'] );
			if ( $was_deactivated ) {
				$deactivated++;
			}
			if ( empty( $entry['plugin'] ) ) {
				$unattributed++;
			}
			if ( ! empty( $entry['time'] ) && $entry['time'] > $latest_time ) {
				$latest_time = $entry['time'];
			}
		}

		$cards = array(
			array(
				'label' => __( 'Total fatal errors', 'fatal-plugin-auto-deactivator' ),
				'value' => number_format_i18n( $total ),
			),
			array(
				'label' => __( 'Plugins deactivated', 'fatal-plugin-auto-deactivator' ),
				'value' => number_format_i18n( $deactivated ),
			),
			array(
				'label' => __( 'Not attributed to a plugin', 'fatal-plugin-auto-deactivator' ),
				'value' => number_format_i18n( $unattributed ),
			),
			array(
				'label' => __( 'Most recent', 'fatal-plugin-auto-deactivator' ),
				'value' => $latest_time ? wp_date( 'Y-m-d h:i a', $latest_time ) : '—',
			),
		);

		echo '<div class="fpad-summary">';
		foreach ( $cards as $card ) {
			echo '<div class="fpad-summary-card">';
			echo '<span class="fpad-summary-value">' . esc_html( $card['value'] ) . '</span>';
			echo '<span class="fpad-summary-label">' . esc_html( $card['label'] ) . '</span>';
			echo '</div>';
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
			.fpad-summary {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				margin: 16px 0;
			}
			.fpad-summary-card {
				flex: 1 1 160px;
				background: #fff;
				border: 1px solid #dcdcde;
				border-left: 4px solid #2271b1;
				border-radius: 4px;
				padding: 12px 16px;
				display: flex;
				flex-direction: column;
			}
			.fpad-summary-value {
				font-size: 22px;
				font-weight: 600;
				line-height: 1.2;
			}
			.fpad-summary-label {
				color: #646970;
				font-size: 12px;
				margin-top: 4px;
			}
			.fpad-log-table { margin-top: 8px; }
			.fpad-log-table tr.log-entry-row:nth-child(4n+1),
			.fpad-log-table tr.log-entry-row:nth-child(4n+2) {
				background-color: #f6f7f7;
			}
			.fpad-log-table tr.log-entry-row:nth-child(4n+3),
			.fpad-log-table tr.log-entry-row:nth-child(4n+4) {
				background-color: #ffffff;
			}
			.fpad-log-table tr.error-row td {
				border-bottom: 1px solid #c3c4c7;
				padding-top: 0;
			}
			.fpad-log-table .fpad_error_message {
				font-family: Menlo, Consolas, monospace;
				white-space: pre-wrap;
				word-wrap: break-word;
				margin: 6px 0 0;
				color: #d63638;
			}
			.fpad-log-table .fpad-file {
				font-family: Menlo, Consolas, monospace;
				font-size: 12px;
				word-break: break-all;
			}
			.fpad-badge {
				display: inline-block;
				font-size: 11px;
				font-weight: 600;
				line-height: 1.6;
				padding: 0 8px;
				border-radius: 9px;
				white-space: nowrap;
			}
			.fpad-badge-deactivated { background: #d5e8d4; color: #1a4d1a; }
			.fpad-badge-logged { background: #e6e6e6; color: #50575e; }
			.fpad-badge-source { background: #e5f0fa; color: #135e96; text-transform: capitalize; }
		</style>';

		echo '<table class="widefat fpad-log-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Date', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Plugin', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $deactivation_log as $entry ) {
			// Get error type as string
			$error_type = self::get_error_type_string( $entry['error_type'] );

			// Whether a plugin was actually deactivated. Older log entries predate
			// this flag, so infer it from the presence of a plugin reference.
			$deactivated = isset( $entry['deactivated'] ) ? $entry['deactivated'] : ! empty( $entry['plugin'] );

			// Plugin cell: show the plugin name/basename, or a fallback when the
			// error could not be attributed to a specific plugin.
			if ( ! empty( $entry['plugin_name'] ) ) {
				$plugin_cell = esc_html( $entry['plugin_name'] ) . '<br><small>' . esc_html( $entry['plugin'] ) . '</small>';
			} else {
				$plugin_cell = '<em>' . esc_html__( 'Not identified', 'fatal-plugin-auto-deactivator' ) . '</em>';
			}

			// Classify the originating source from the stored file path.
			$source       = self::classify_source( isset( $entry['error_file'] ) ? $entry['error_file'] : '' );
			$source_badge = '<span class="fpad-badge fpad-badge-source">' . esc_html( $source ) . '</span>';

			if ( $deactivated ) {
				$status_badge = '<span class="fpad-badge fpad-badge-deactivated">' . esc_html__( 'Deactivated', 'fatal-plugin-auto-deactivator' ) . '</span>';
			} else {
				$status_badge = '<span class="fpad-badge fpad-badge-logged">' . esc_html__( 'Logged only', 'fatal-plugin-auto-deactivator' ) . '</span>';
			}

			echo '<tr class="log-entry-row">';
			echo '<td>' . esc_html( wp_date( 'Y-m-d', $entry['time'] ) ) . '<br><small>' . esc_html( wp_date( 'h:i:s a', $entry['time'] ) ) . '</small></td>';
			echo '<td>' . $source_badge . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $plugin_cell . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $status_badge . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="fpad-file">' . esc_html( $entry['error_file'] ) . ':' . esc_html( $entry['error_line'] ) . '</td>';
			echo '</tr>';
			echo '<tr class="log-entry-row error-row">';
			echo '<td colspan="5"><strong>' . esc_html( $error_type ) . '</strong><p class="fpad_error_message">' . esc_html( $entry['error_msg'] ) . '</p></td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Classify the originating source of an error from its file path.
	 *
	 * Mirrors FPAD_Fatal_Error_Handler::detect_error_source(), but operates on
	 * the path stored in the log so old and new entries are labelled the same
	 * way in the viewer.
	 *
	 * @param string $file Absolute path to the file that triggered the error.
	 * @return string Human-readable source label.
	 */
	private static function classify_source( $file ) {
		if ( '' === $file ) {
			return __( 'unknown', 'fatal-plugin-auto-deactivator' );
		}

		$file      = str_replace( '\\', '/', $file );
		$normalize = function ( $path ) {
			return rtrim( str_replace( '\\', '/', $path ), '/' );
		};

		if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( $file, $normalize( WPMU_PLUGIN_DIR ) . '/' ) ) {
			return __( 'mu-plugin', 'fatal-plugin-auto-deactivator' );
		}

		if ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( $file, $normalize( WP_PLUGIN_DIR ) . '/' ) ) {
			return __( 'plugin', 'fatal-plugin-auto-deactivator' );
		}

		$theme_root = function_exists( 'get_theme_root' ) ? $normalize( get_theme_root() ) : '';
		if ( '' !== $theme_root && 0 === strpos( $file, $theme_root . '/' ) ) {
			return __( 'theme', 'fatal-plugin-auto-deactivator' );
		}

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
			foreach ( $dropins as $dropin ) {
				if ( $content_dir . '/' . $dropin === $file ) {
					return __( 'drop-in', 'fatal-plugin-auto-deactivator' );
				}
			}
		}

		if ( defined( 'ABSPATH' ) ) {
			$abspath = $normalize( ABSPATH );
			if ( 0 === strpos( $file, $abspath . '/wp-includes/' ) || 0 === strpos( $file, $abspath . '/wp-admin/' ) ) {
				return __( 'core', 'fatal-plugin-auto-deactivator' );
			}
		}

		return __( 'unknown', 'fatal-plugin-auto-deactivator' );
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
