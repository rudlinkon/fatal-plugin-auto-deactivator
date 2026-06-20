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
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'display_admin_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_protection_notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_filter( 'plugin_action_links_' . FPAD_PLUGIN_BASENAME, array( __CLASS__, 'add_plugin_action_links' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
		add_filter( 'debug_information', array( __CLASS__, 'add_debug_information' ) );
		add_action( 'admin_post_fpad_export_log', array( __CLASS__, 'export_log' ) );
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

		$action_links = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=fpad-log&tab=settings' ) ),
				esc_html__( 'Settings', 'fatal-plugin-auto-deactivator' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'tools.php?page=fpad-log' ) ),
				esc_html__( 'View Log', 'fatal-plugin-auto-deactivator' )
			),
		);

		// Show our links first.
		return array_merge( $action_links, $links );
	}

	/**
	 * Render the admin page (Log and Settings tabs).
	 */
	public static function render_log_page() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::handle_settings_save();
		self::handle_clear_log();

		// Surface the outcome of a "Reinstall protection" action (post-redirect).
		if ( isset( $_GET['fpad_reinstalled'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '1' === $_GET['fpad_reinstalled'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
				add_settings_error( 'fpad_messages', 'fpad_reinstalled', __( 'Protection reinstalled successfully.', 'fatal-plugin-auto-deactivator' ), 'success' );
			} else {
				add_settings_error( 'fpad_messages', 'fpad_reinstalled', __( 'Protection could not be reinstalled. Check your wp-content directory permissions.', 'fatal-plugin-auto-deactivator' ), 'error' );
			}
		}

		// Surface the outcome of a per-entry delete (post-redirect).
		if ( isset( $_GET['fpad_deleted'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'fpad_messages', 'fpad_deleted', __( 'Log entry deleted.', 'fatal-plugin-auto-deactivator' ), 'success' );
		}

		// Determine the active tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log'; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'log', 'settings' ), true ) ) {
			$tab = 'log';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Fatal Plugin Auto Deactivator', 'fatal-plugin-auto-deactivator' ) . '</h1>';

		// Show any settings errors/messages
		settings_errors( 'fpad_messages' );

		self::render_protection_banner();

		$tabs = array(
			'log'      => __( 'Log', 'fatal-plugin-auto-deactivator' ),
			'settings' => __( 'Settings', 'fatal-plugin-auto-deactivator' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = admin_url( 'tools.php?page=fpad-log' . ( 'log' === $slug ? '' : '&tab=' . $slug ) );
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ),
				$slug === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( 'settings' === $tab ) {
			self::render_settings_tab();
		} else {
			self::render_log_tab();
		}

		echo '</div>';
	}

	/**
	 * Handle the "Clear Log" form submission.
	 */
	private static function handle_clear_log() {
		if ( ! isset( $_POST['fpad_clear_log'] ) ) {
			return;
		}

		check_admin_referer( 'fpad_clear_log', 'fpad_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'fpad_deactivation_log', array() );
		add_settings_error( 'fpad_messages', 'fpad_message', __( 'Fatal Plugin Auto Deactivator log cleared successfully.', 'fatal-plugin-auto-deactivator' ), 'success' );
	}

	/**
	 * Render the Log tab (summary cards + table + clear button).
	 */
	private static function render_log_tab() {
		$deactivation_log = get_option( 'fpad_deactivation_log', array() );
		if ( ! is_array( $deactivation_log ) ) {
			$deactivation_log = array();
		}
		$total_entries = count( $deactivation_log );

		// Read filters. These only affect the read-only display, so no nonce is needed.
		$f_source = isset( $_GET['fpad_source'] ) ? sanitize_key( wp_unslash( $_GET['fpad_source'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_status = isset( $_GET['fpad_status'] ) ? sanitize_key( wp_unslash( $_GET['fpad_status'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_query  = isset( $_GET['fpad_q'] ) ? sanitize_text_field( wp_unslash( $_GET['fpad_q'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<form method="post">';
		wp_nonce_field( 'fpad_clear_log', 'fpad_nonce' );
		submit_button( __( 'Clear Log', 'fatal-plugin-auto-deactivator' ), 'delete', 'fpad_clear_log', false );
		echo '</form><br>';

		if ( empty( $deactivation_log ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No fatal errors have been logged yet. When a plugin (or other code) triggers a fatal error, it will appear here.', 'fatal-plugin-auto-deactivator' ) . '</p></div>';

			return;
		}

		// Export the full log (not the filtered view).
		$export_csv  = wp_nonce_url( admin_url( 'admin-post.php?action=fpad_export_log&format=csv' ), 'fpad_export_log' );
		$export_json = wp_nonce_url( admin_url( 'admin-post.php?action=fpad_export_log&format=json' ), 'fpad_export_log' );
		echo '<p>';
		echo '<a href="' . esc_url( $export_csv ) . '" class="button">' . esc_html__( 'Export CSV', 'fatal-plugin-auto-deactivator' ) . '</a> ';
		echo '<a href="' . esc_url( $export_json ) . '" class="button">' . esc_html__( 'Export JSON', 'fatal-plugin-auto-deactivator' ) . '</a>';
		echo '</p>';

		self::render_filter_bar( $f_source, $f_status, $f_query );

		$filtered = self::filter_log( $deactivation_log, $f_source, $f_status, $f_query );

		if ( empty( $filtered ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No log entries match the current filters.', 'fatal-plugin-auto-deactivator' ) . '</p></div>';

			return;
		}

		if ( count( $filtered ) !== $total_entries ) {
			echo '<p class="description">' . sprintf(
				/* translators: 1: number of matching incidents, 2: total number of incidents */
				esc_html__( 'Showing %1$s of %2$s logged incidents.', 'fatal-plugin-auto-deactivator' ),
				esc_html( number_format_i18n( count( $filtered ) ) ),
				esc_html( number_format_i18n( $total_entries ) )
			) . '</p>';
		}

		self::render_log_summary( $filtered );
		self::render_log_table( $filtered );
	}

	/**
	 * Render a summary bar with at-a-glance counts.
	 *
	 * @param array $deactivation_log The deactivation log entries
	 */
	private static function render_log_summary( $deactivation_log ) {
		$total        = 0;
		$deactivated  = 0;
		$unattributed = 0;
		$latest_time  = 0;

		foreach ( $deactivation_log as $entry ) {
			// Count occurrences, not rows, so coalesced repeats are reflected honestly.
			$count           = isset( $entry['count'] ) ? (int) $entry['count'] : 1;
			$was_deactivated = isset( $entry['deactivated'] ) ? $entry['deactivated'] : ! empty( $entry['plugin'] );

			$total += $count;
			if ( $was_deactivated ) {
				$deactivated += $count;
			}
			if ( empty( $entry['plugin'] ) ) {
				$unattributed += $count;
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
			.fpad-badge-protected { background: #fcf0d6; color: #8a6d00; }
			.fpad-badge-logonly { background: #e5f0fa; color: #135e96; }
			.fpad-badge-source { background: #e5f0fa; color: #135e96; text-transform: capitalize; }
			.fpad-badge-count { background: #ededed; color: #50575e; }
			.fpad-log-table .fpad-meta {
				color: #646970;
				font-size: 12px;
				margin: 6px 0 0;
				word-break: break-word;
			}
		</style>';

		echo '<table class="widefat fpad-log-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Date', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Plugin', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'fatal-plugin-auto-deactivator' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $deactivation_log as $entry ) {
			// Get error type as string
			$error_type = self::get_error_type_string( $entry['error_type'] );

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

			$status_badge = self::status_badge( self::entry_status( $entry ) );

			$count = isset( $entry['count'] ) ? (int) $entry['count'] : 1;
			$time  = isset( $entry['time'] ) ? $entry['time'] : 0;

			// Date cell: last-seen timestamp, plus an "×N" badge for coalesced repeats.
			$date_cell = esc_html( wp_date( 'Y-m-d', $time ) ) . '<br><small>' . esc_html( wp_date( 'h:i:s a', $time ) ) . '</small>';
			if ( $count > 1 ) {
				$date_cell .= ' <span class="fpad-badge fpad-badge-count">×' . esc_html( number_format_i18n( $count ) ) . '</span>';
			}

			// Detail-row meta: occurrence span, request URL, and environment.
			$meta = array();
			if ( $count > 1 && isset( $entry['first_time'] ) ) {
				$meta[] = sprintf(
					/* translators: 1: occurrence count, 2: first-seen datetime, 3: last-seen datetime */
					esc_html__( 'Seen %1$s times · first %2$s · last %3$s', 'fatal-plugin-auto-deactivator' ),
					esc_html( number_format_i18n( $count ) ),
					esc_html( wp_date( 'Y-m-d H:i', $entry['first_time'] ) ),
					esc_html( wp_date( 'Y-m-d H:i', $time ) )
				);
			}
			if ( ! empty( $entry['request_uri'] ) ) {
				$meta[] = esc_html__( 'Request:', 'fatal-plugin-auto-deactivator' ) . ' ' . esc_html( $entry['request_uri'] );
			}
			$env = array();
			if ( ! empty( $entry['php_version'] ) ) {
				$env[] = 'PHP ' . esc_html( $entry['php_version'] );
			}
			if ( ! empty( $entry['wp_version'] ) ) {
				$env[] = 'WP ' . esc_html( $entry['wp_version'] );
			}
			if ( $env ) {
				$meta[] = implode( ' · ', $env );
			}

			// Actions cell: copy a bug report, plus a nonce-protected per-entry delete.
			$entry_key  = self::entry_key( $entry );
			$delete_url = wp_nonce_url(
				admin_url( 'tools.php?page=fpad-log&fpad_action=delete&key=' . rawurlencode( $entry_key ) ),
				'fpad_delete_' . $entry_key
			);
			$copy_button  = '<button type="button" class="button-link fpad-copy" data-fpad-done="' . esc_attr__( 'Copied', 'fatal-plugin-auto-deactivator' ) . '" data-fpad-report="' . esc_attr( self::build_report( $entry ) ) . '">' . esc_html__( 'Copy', 'fatal-plugin-auto-deactivator' ) . '</button>';
			$delete_link  = '<a href="' . esc_url( $delete_url ) . '" class="fpad-delete" onclick="return confirm(\'' . esc_js( __( 'Delete this log entry?', 'fatal-plugin-auto-deactivator' ) ) . '\');">' . esc_html__( 'Delete', 'fatal-plugin-auto-deactivator' ) . '</a>';
			$actions_cell = $copy_button . ' ' . $delete_link;

			echo '<tr class="log-entry-row">';
			echo '<td>' . $date_cell . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $source_badge . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $plugin_cell . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $status_badge . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td class="fpad-file">' . esc_html( $entry['error_file'] ) . ':' . esc_html( $entry['error_line'] ) . '</td>';
			echo '<td>' . $actions_cell . '</td>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
			echo '<tr class="log-entry-row error-row">';
			echo '<td colspan="6"><strong>' . esc_html( $error_type ) . '</strong><p class="fpad_error_message">' . esc_html( $entry['error_msg'] ) . '</p>';
			if ( $meta ) {
				echo '<p class="fpad-meta">' . implode( '<br>', $meta ) . '</p>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';

		// One delegated handler copies a row's bug report to the clipboard.
		echo '<script>
		document.addEventListener( "click", function ( e ) {
			var btn = e.target.closest ? e.target.closest( ".fpad-copy" ) : null;
			if ( ! btn ) { return; }
			var text = btn.getAttribute( "data-fpad-report" ) || "";
			var done = function () {
				var original = btn.textContent;
				btn.textContent = btn.getAttribute( "data-fpad-done" ) || "Copied";
				setTimeout( function () { btn.textContent = original; }, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var ta = document.createElement( "textarea" );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( "copy" ); } catch ( err ) {}
				document.body.removeChild( ta );
				done();
			}
		} );
		</script>';
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
		return self::source_label( self::source_key( $file ) );
	}

	/**
	 * Canonical source key for an error file path (locale-independent).
	 *
	 * Mirrors FPAD_Fatal_Error_Handler::detect_error_source().
	 *
	 * @param string $file Absolute path to the file that triggered the error.
	 * @return string One of: plugin, mu-plugin, theme, drop-in, core, unknown.
	 */
	private static function source_key( $file ) {
		if ( '' === $file ) {
			return 'unknown';
		}

		$file      = str_replace( '\\', '/', $file );
		$normalize = function ( $path ) {
			return rtrim( str_replace( '\\', '/', $path ), '/' );
		};

		if ( defined( 'WPMU_PLUGIN_DIR' ) && 0 === strpos( $file, $normalize( WPMU_PLUGIN_DIR ) . '/' ) ) {
			return 'mu-plugin';
		}

		if ( defined( 'WP_PLUGIN_DIR' ) && 0 === strpos( $file, $normalize( WP_PLUGIN_DIR ) . '/' ) ) {
			return 'plugin';
		}

		$theme_root = function_exists( 'get_theme_root' ) ? $normalize( get_theme_root() ) : '';
		if ( '' !== $theme_root && 0 === strpos( $file, $theme_root . '/' ) ) {
			return 'theme';
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
					return 'drop-in';
				}
			}
		}

		if ( defined( 'ABSPATH' ) ) {
			$abspath = $normalize( ABSPATH );
			if ( 0 === strpos( $file, $abspath . '/wp-includes/' ) || 0 === strpos( $file, $abspath . '/wp-admin/' ) ) {
				return 'core';
			}
		}

		return 'unknown';
	}

	/**
	 * Translated label for a source key.
	 *
	 * @param string $key Source key.
	 * @return string
	 */
	private static function source_label( $key ) {
		$labels = self::source_labels();

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $labels['unknown'];
	}

	/**
	 * Map of source key => translated label.
	 *
	 * @return array
	 */
	private static function source_labels() {
		return array(
			'plugin'    => __( 'plugin', 'fatal-plugin-auto-deactivator' ),
			'mu-plugin' => __( 'mu-plugin', 'fatal-plugin-auto-deactivator' ),
			'theme'     => __( 'theme', 'fatal-plugin-auto-deactivator' ),
			'drop-in'   => __( 'drop-in', 'fatal-plugin-auto-deactivator' ),
			'core'      => __( 'core', 'fatal-plugin-auto-deactivator' ),
			'unknown'   => __( 'unknown', 'fatal-plugin-auto-deactivator' ),
		);
	}

	/**
	 * Canonical status key for a log entry, inferring it for legacy entries.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private static function entry_status( $entry ) {
		if ( isset( $entry['status'] ) ) {
			return $entry['status'];
		}

		$deactivated = isset( $entry['deactivated'] ) ? $entry['deactivated'] : ! empty( $entry['plugin'] );

		return $deactivated ? 'deactivated' : ( ! empty( $entry['plugin'] ) ? 'logged' : 'unattributed' );
	}

	/**
	 * Filter log entries by source, status, and free-text search.
	 *
	 * @param array  $log    The full log.
	 * @param string $source Source key, or '' for all.
	 * @param string $status Status key, or '' for all.
	 * @param string $query  Free-text query, or '' for none.
	 * @return array
	 */
	private static function filter_log( $log, $source, $status, $query ) {
		if ( '' === $source && '' === $status && '' === $query ) {
			return $log;
		}

		$query_lc = '' !== $query ? strtolower( $query ) : '';
		$out      = array();

		foreach ( $log as $entry ) {
			if ( '' !== $source && self::source_key( isset( $entry['error_file'] ) ? $entry['error_file'] : '' ) !== $source ) {
				continue;
			}

			if ( '' !== $status && self::entry_status( $entry ) !== $status ) {
				continue;
			}

			if ( '' !== $query_lc ) {
				$haystack = strtolower(
					( isset( $entry['plugin_name'] ) ? $entry['plugin_name'] : '' ) . ' ' .
					( isset( $entry['plugin'] ) ? $entry['plugin'] : '' ) . ' ' .
					( isset( $entry['error_msg'] ) ? $entry['error_msg'] : '' ) . ' ' .
					( isset( $entry['error_file'] ) ? $entry['error_file'] : '' )
				);
				if ( false === strpos( $haystack, $query_lc ) ) {
					continue;
				}
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Render the source/status/search filter bar.
	 *
	 * @param string $f_source Selected source key.
	 * @param string $f_status Selected status key.
	 * @param string $f_query  Current search query.
	 */
	private static function render_filter_bar( $f_source, $f_status, $f_query ) {
		$sources  = self::source_labels();
		$statuses = array(
			'deactivated'  => __( 'Deactivated', 'fatal-plugin-auto-deactivator' ),
			'protected'    => __( 'Protected', 'fatal-plugin-auto-deactivator' ),
			'log_only'     => __( 'Log only', 'fatal-plugin-auto-deactivator' ),
			'logged'       => __( 'Logged only', 'fatal-plugin-auto-deactivator' ),
			'unattributed' => __( 'Not attributed', 'fatal-plugin-auto-deactivator' ),
		);

		echo '<form method="get" style="margin:8px 0 12px;">';
		echo '<input type="hidden" name="page" value="fpad-log">';

		echo '<select name="fpad_source"><option value="">' . esc_html__( 'All sources', 'fatal-plugin-auto-deactivator' ) . '</option>';
		foreach ( $sources as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $f_source, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';

		echo '<select name="fpad_status"><option value="">' . esc_html__( 'All statuses', 'fatal-plugin-auto-deactivator' ) . '</option>';
		foreach ( $statuses as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $f_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';

		echo '<input type="search" name="fpad_q" value="' . esc_attr( $f_query ) . '" placeholder="' . esc_attr__( 'Search plugin, message, file…', 'fatal-plugin-auto-deactivator' ) . '" class="regular-text"> ';

		submit_button( __( 'Filter', 'fatal-plugin-auto-deactivator' ), 'secondary', '', false );

		if ( '' !== $f_source || '' !== $f_status || '' !== $f_query ) {
			echo ' <a href="' . esc_url( admin_url( 'tools.php?page=fpad-log' ) ) . '" class="button-link">' . esc_html__( 'Clear filters', 'fatal-plugin-auto-deactivator' ) . '</a>';
		}

		echo '</form>';
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

	/**
	 * Map an outcome status to a coloured table badge.
	 *
	 * @param string $status One of: deactivated, protected, log_only, logged, unattributed.
	 * @return string
	 */
	private static function status_badge( $status ) {
		switch ( $status ) {
			case 'deactivated':
				return '<span class="fpad-badge fpad-badge-deactivated">' . esc_html__( 'Deactivated', 'fatal-plugin-auto-deactivator' ) . '</span>';
			case 'protected':
				return '<span class="fpad-badge fpad-badge-protected">' . esc_html__( 'Protected', 'fatal-plugin-auto-deactivator' ) . '</span>';
			case 'log_only':
				return '<span class="fpad-badge fpad-badge-logonly">' . esc_html__( 'Log only', 'fatal-plugin-auto-deactivator' ) . '</span>';
			default:
				return '<span class="fpad-badge fpad-badge-logged">' . esc_html__( 'Logged only', 'fatal-plugin-auto-deactivator' ) . '</span>';
		}
	}

	/**
	 * Read the plugin settings with defaults.
	 *
	 * @return array
	 */
	private static function get_settings() {
		$settings = get_option( 'fpad_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'log_only'          => ! empty( $settings['log_only'] ),
			'protected_plugins' => ( isset( $settings['protected_plugins'] ) && is_array( $settings['protected_plugins'] ) )
				? $settings['protected_plugins']
				: array(),
		);
	}

	/**
	 * Build a basename => display-name map of currently active plugins.
	 *
	 * @return array
	 */
	private static function get_active_plugin_choices() {
		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			return array();
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$choices = array();
		foreach ( $active as $basename ) {
			$file = WP_PLUGIN_DIR . '/' . $basename;
			$name = $basename;
			if ( file_exists( $file ) ) {
				$data = get_plugin_data( $file, false, false );
				if ( ! empty( $data['Name'] ) ) {
					$name = $data['Name'];
				}
			}
			$choices[ $basename ] = $name;
		}

		asort( $choices );

		return $choices;
	}

	/**
	 * Render the Settings tab (log-only mode + protected-plugins allowlist).
	 */
	private static function render_settings_tab() {
		$settings = self::get_settings();
		$active   = self::get_active_plugin_choices();

		echo '<form method="post">';
		wp_nonce_field( 'fpad_save_settings', 'fpad_settings_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Automatic deactivation', 'fatal-plugin-auto-deactivator' ) . '</th><td>';
		echo '<label><input type="checkbox" name="fpad_log_only" value="1"' . checked( $settings['log_only'], true, false ) . '> ';
		echo esc_html__( 'Log-only mode: detect and log fatal errors, but never deactivate any plugin.', 'fatal-plugin-auto-deactivator' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Use this if you prefer to investigate fatal errors yourself without plugins being switched off automatically.', 'fatal-plugin-auto-deactivator' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Protected plugins', 'fatal-plugin-auto-deactivator' ) . '</th><td>';
		if ( empty( $active ) ) {
			echo '<p>' . esc_html__( 'No active plugins found.', 'fatal-plugin-auto-deactivator' ) . '</p>';
		} else {
			echo '<fieldset>';
			echo '<p class="description">' . esc_html__( 'These plugins will never be deactivated automatically, even if they cause a fatal error. The error is still logged and an honest message is shown.', 'fatal-plugin-auto-deactivator' ) . '</p>';
			foreach ( $active as $basename => $name ) {
				echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="fpad_protected_plugins[]" value="' . esc_attr( $basename ) . '"' . checked( in_array( $basename, $settings['protected_plugins'], true ), true, false ) . '> ' . esc_html( $name ) . '</label>';
			}
			echo '</fieldset>';
		}
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save Settings', 'fatal-plugin-auto-deactivator' ) );
		echo '</form>';
	}

	/**
	 * Handle the Settings form submission.
	 */
	private static function handle_settings_save() {
		if ( ! isset( $_POST['fpad_settings_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'fpad_save_settings', 'fpad_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$log_only = ! empty( $_POST['fpad_log_only'] );

		$protected = array();
		if ( isset( $_POST['fpad_protected_plugins'] ) && is_array( $_POST['fpad_protected_plugins'] ) ) {
			$valid     = array_keys( self::get_active_plugin_choices() );
			$submitted = array_map( 'sanitize_text_field', wp_unslash( $_POST['fpad_protected_plugins'] ) );
			$protected = array_values( array_intersect( $submitted, $valid ) );
		}

		update_option(
			'fpad_settings',
			array(
				'log_only'          => $log_only,
				'protected_plugins' => $protected,
			)
		);

		add_settings_error( 'fpad_messages', 'fpad_settings_saved', __( 'Settings saved.', 'fatal-plugin-auto-deactivator' ), 'success' );
	}

	/**
	 * Current protection status, via the drop-in manager.
	 *
	 * @return string active|foreign|missing|unwritable|no_filesystem
	 */
	private static function get_protection_state() {
		$manager = new FPAD_Dropin_Manager();

		return $manager->get_status();
	}

	/**
	 * Human-readable explanation for a non-active protection status.
	 *
	 * @param string $status Protection status.
	 * @return string
	 */
	private static function protection_message( $status ) {
		switch ( $status ) {
			case 'foreign':
				return __( 'Another plugin currently owns wp-content/fatal-error-handler.php, so Fatal Plugin Auto Deactivator is not protecting your site.', 'fatal-plugin-auto-deactivator' );
			case 'unwritable':
				return __( 'Your wp-content directory is not writable, so the protection file could not be installed. Check file permissions.', 'fatal-plugin-auto-deactivator' );
			case 'no_filesystem':
				return __( 'WordPress could not access the filesystem (credentials may be required), so the protection file could not be installed.', 'fatal-plugin-auto-deactivator' );
			case 'missing':
				return __( 'The protection file is not installed, so your site is not currently protected.', 'fatal-plugin-auto-deactivator' );
		}

		return '';
	}

	/**
	 * Build a nonce-protected URL that reinstalls the drop-in.
	 *
	 * @return string
	 */
	private static function reinstall_url() {
		return wp_nonce_url( admin_url( 'tools.php?page=fpad-log&fpad_action=reinstall' ), 'fpad_reinstall' );
	}

	/**
	 * Show a site-wide admin notice when protection is not active.
	 */
	public static function maybe_show_protection_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = self::get_protection_state();
		if ( 'active' === $status ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Fatal Plugin Auto Deactivator', 'fatal-plugin-auto-deactivator' ) . ':</strong> ';
		echo esc_html( self::protection_message( $status ) );
		echo ' <a href="' . esc_url( self::reinstall_url() ) . '" class="button button-secondary">' . esc_html__( 'Reinstall protection', 'fatal-plugin-auto-deactivator' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Render the protection-status banner at the top of the admin page.
	 */
	private static function render_protection_banner() {
		$status = self::get_protection_state();

		if ( 'active' === $status ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Protection active — the fatal error handler drop-in is installed.', 'fatal-plugin-auto-deactivator' ) . '</p></div>';

			return;
		}

		echo '<div class="notice notice-error inline"><p>' . esc_html( self::protection_message( $status ) );
		echo ' <a href="' . esc_url( self::reinstall_url() ) . '" class="button button-secondary">' . esc_html__( 'Reinstall protection', 'fatal-plugin-auto-deactivator' ) . '</a></p></div>';
	}

	/**
	 * Handle admin GET actions (currently: reinstall the drop-in).
	 */
	public static function handle_admin_actions() {
		if ( ! isset( $_GET['fpad_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['fpad_action'] ) );

		if ( 'reinstall' === $action ) {
			check_admin_referer( 'fpad_reinstall' );

			$manager = new FPAD_Dropin_Manager();
			$manager->remove_dropin();
			$installed = $manager->install_dropin();

			wp_safe_redirect(
				add_query_arg(
					'fpad_reinstalled',
					$installed ? '1' : '0',
					admin_url( 'tools.php?page=fpad-log' )
				)
			);
			exit;
		}

		if ( 'delete' === $action ) {
			$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			check_admin_referer( 'fpad_delete_' . $key );

			$log = get_option( 'fpad_deactivation_log', array() );
			if ( is_array( $log ) ) {
				$log = array_values(
					array_filter(
						$log,
						function ( $entry ) use ( $key ) {
							return self::entry_key( $entry ) !== $key;
						}
					)
				);
				update_option( 'fpad_deactivation_log', $log );
			}

			wp_safe_redirect( add_query_arg( 'fpad_deleted', '1', admin_url( 'tools.php?page=fpad-log' ) ) );
			exit;
		}
	}

	/**
	 * Stable identity key for a log entry, used for per-entry actions.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private static function entry_key( $entry ) {
		$parts = array(
			isset( $entry['time'] ) ? $entry['time'] : '',
			isset( $entry['first_time'] ) ? $entry['first_time'] : '',
			isset( $entry['error_type'] ) ? $entry['error_type'] : '',
			isset( $entry['error_file'] ) ? $entry['error_file'] : '',
			isset( $entry['error_line'] ) ? $entry['error_line'] : '',
			isset( $entry['error_msg'] ) ? $entry['error_msg'] : '',
		);

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Neutralize a CSV cell against spreadsheet formula injection.
	 *
	 * Prefixes a leading formula trigger (= + - @, tab, CR) with an apostrophe so
	 * spreadsheet apps treat the value as text.
	 *
	 * @param string $value Raw cell value.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}

		return $value;
	}

	/**
	 * Stream the log as a CSV or JSON download (admin-post handler).
	 */
	public static function export_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the log.', 'fatal-plugin-auto-deactivator' ) );
		}

		check_admin_referer( 'fpad_export_log' );

		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		$log    = get_option( 'fpad_deactivation_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		nocache_headers();

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="fatal-plugin-log.json"' );
			echo wp_json_encode( $log ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="fatal-plugin-log.csv"' );

		//phpcs:ignore WordPress.WP.AlternativeFunctions
		$out = fopen( 'php://output', 'w' );
		//phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'date_utc', 'last_seen', 'first_seen', 'count', 'source', 'plugin', 'plugin_name', 'status', 'error_type', 'message', 'file', 'line', 'request_uri', 'php_version', 'wp_version' ) );

		foreach ( $log as $entry ) {
			//phpcs:ignore WordPress.WP.AlternativeFunctions
			fputcsv(
				$out,
				array(
					isset( $entry['date'] ) ? $entry['date'] : '',
					isset( $entry['time'] ) ? gmdate( 'Y-m-d H:i:s', $entry['time'] ) : '',
					isset( $entry['first_time'] ) ? gmdate( 'Y-m-d H:i:s', $entry['first_time'] ) : '',
					isset( $entry['count'] ) ? (int) $entry['count'] : 1,
					self::source_key( isset( $entry['error_file'] ) ? $entry['error_file'] : '' ),
					self::csv_safe( isset( $entry['plugin'] ) ? $entry['plugin'] : '' ),
					self::csv_safe( isset( $entry['plugin_name'] ) ? $entry['plugin_name'] : '' ),
					self::entry_status( $entry ),
					isset( $entry['error_type'] ) ? self::get_error_type_string( $entry['error_type'] ) : '',
					self::csv_safe( isset( $entry['error_msg'] ) ? $entry['error_msg'] : '' ),
					self::csv_safe( isset( $entry['error_file'] ) ? $entry['error_file'] : '' ),
					isset( $entry['error_line'] ) ? $entry['error_line'] : '',
					self::csv_safe( isset( $entry['request_uri'] ) ? $entry['request_uri'] : '' ),
					isset( $entry['php_version'] ) ? $entry['php_version'] : '',
					isset( $entry['wp_version'] ) ? $entry['wp_version'] : '',
				)
			);
		}

		//phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out );
		exit;
	}

	/**
	 * Build a plain-text report for a single entry, for pasting into a support thread.
	 *
	 * Intentionally untranslated: it is a developer-facing payload, not UI copy.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private static function build_report( $entry ) {
		$lines   = array();
		$lines[] = 'Plugin: ' . ( ! empty( $entry['plugin_name'] ) ? $entry['plugin_name'] : 'n/a' )
			. ( ! empty( $entry['plugin'] ) ? ' (' . $entry['plugin'] . ')' : '' );
		$lines[] = 'Status: ' . self::entry_status( $entry );
		$lines[] = 'Source: ' . self::source_key( isset( $entry['error_file'] ) ? $entry['error_file'] : '' );
		$lines[] = 'Error: ' . ( isset( $entry['error_type'] ) ? self::get_error_type_string( $entry['error_type'] ) : '' )
			. ': ' . ( isset( $entry['error_msg'] ) ? $entry['error_msg'] : '' );
		$lines[] = 'File: ' . ( isset( $entry['error_file'] ) ? $entry['error_file'] : '' )
			. ':' . ( isset( $entry['error_line'] ) ? $entry['error_line'] : '' );

		if ( ! empty( $entry['request_uri'] ) ) {
			$lines[] = 'Request: ' . $entry['request_uri'];
		}

		$env = array();
		if ( ! empty( $entry['php_version'] ) ) {
			$env[] = 'PHP ' . $entry['php_version'];
		}
		if ( ! empty( $entry['wp_version'] ) ) {
			$env[] = 'WP ' . $entry['wp_version'];
		}
		if ( $env ) {
			$lines[] = 'Environment: ' . implode( ', ', $env );
		}

		$count = isset( $entry['count'] ) ? (int) $entry['count'] : 1;
		if ( $count > 1 ) {
			$lines[] = 'Occurrences: ' . $count;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Register a Site Health test for protection status.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public static function register_site_health_test( $tests ) {
		$tests['direct']['fpad_protection'] = array(
			'label' => __( 'Fatal error protection', 'fatal-plugin-auto-deactivator' ),
			'test'  => array( __CLASS__, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Site Health test callback.
	 *
	 * @return array
	 */
	public static function site_health_test() {
		$status = self::get_protection_state();
		$active = ( 'active' === $status );

		$result = array(
			'label'       => $active
				? __( 'Fatal error protection is active', 'fatal-plugin-auto-deactivator' )
				: __( 'Fatal error protection is not active', 'fatal-plugin-auto-deactivator' ),
			'status'      => $active ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'fatal-plugin-auto-deactivator' ),
				'color' => $active ? 'green' : 'red',
			),
			'description' => '<p>' . esc_html(
				$active
					? __( 'The Fatal Plugin Auto Deactivator drop-in is installed and will catch fatal errors.', 'fatal-plugin-auto-deactivator' )
					: self::protection_message( $status )
			) . '</p>',
			'actions'     => '',
			'test'        => 'fpad_protection',
		);

		if ( ! $active ) {
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'tools.php?page=fpad-log' ) ),
				esc_html__( 'Review protection status', 'fatal-plugin-auto-deactivator' )
			);
		}

		return $result;
	}

	/**
	 * Add a debug-information section to Site Health.
	 *
	 * @param array $info Existing debug information.
	 * @return array
	 */
	public static function add_debug_information( $info ) {
		$log      = get_option( 'fpad_deactivation_log', array() );
		$settings = self::get_settings();
		$status   = self::get_protection_state();
		$last     = ! empty( $log[0]['time'] ) ? wp_date( 'Y-m-d H:i:s', $log[0]['time'] ) : '—';

		$info['fpad'] = array(
			'label'  => __( 'Fatal Plugin Auto Deactivator', 'fatal-plugin-auto-deactivator' ),
			'fields' => array(
				'version'       => array(
					'label' => __( 'Version', 'fatal-plugin-auto-deactivator' ),
					'value' => FPAD_VERSION,
				),
				'protection'    => array(
					'label' => __( 'Protection status', 'fatal-plugin-auto-deactivator' ),
					'value' => $status,
				),
				'log_only'      => array(
					'label' => __( 'Log-only mode', 'fatal-plugin-auto-deactivator' ),
					'value' => $settings['log_only'] ? __( 'Yes', 'fatal-plugin-auto-deactivator' ) : __( 'No', 'fatal-plugin-auto-deactivator' ),
				),
				'protected'     => array(
					'label' => __( 'Protected plugins', 'fatal-plugin-auto-deactivator' ),
					'value' => count( $settings['protected_plugins'] ),
				),
				'logged_fatals' => array(
					'label' => __( 'Logged fatal errors', 'fatal-plugin-auto-deactivator' ),
					'value' => count( $log ),
				),
				'last_fatal'    => array(
					'label' => __( 'Most recent fatal', 'fatal-plugin-auto-deactivator' ),
					'value' => $last,
				),
			),
		);

		return $info;
	}
}
