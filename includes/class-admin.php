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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . FPAD_PLUGIN_BASENAME, array( __CLASS__, 'add_plugin_action_links' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
		add_filter( 'debug_information', array( __CLASS__, 'add_debug_information' ) );
		add_action( 'admin_post_fpad_export_log', array( __CLASS__, 'export_log' ) );
		add_action( 'admin_post_fpad_test_alert', array( __CLASS__, 'handle_test_alert' ) );
		add_action( 'current_screen', array( __CLASS__, 'maybe_suppress_admin_notices' ) );
	}

	/**
	 * Screen id of the plugin's admin page.
	 */
	const SCREEN_ID = 'tools_page_fpad-log';

	/**
	 * Enqueue the admin stylesheet and script.
	 *
	 * The Tailwind build is loaded only on our own screen — it is a full utility
	 * sheet and has no business on every admin page. Notices render everywhere,
	 * so they get their own tiny hand-written stylesheet instead.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::SCREEN_ID === $hook ) {
			wp_enqueue_style( 'fpad-admin', FPAD_PLUGIN_URL . 'assets/css/admin.css', array(), FPAD_VERSION );
			wp_enqueue_script( 'fpad-admin', FPAD_PLUGIN_URL . 'assets/js/admin.js', array(), FPAD_VERSION, true );
			wp_localize_script(
				'fpad-admin',
				'fpadUi',
				array(
					'copied'      => __( 'Copied', 'fatal-plugin-auto-deactivator' ),
					'copyFailed'  => __( 'Press Ctrl/Cmd+C to copy', 'fatal-plugin-auto-deactivator' ),
					'confirmText' => __( 'Delete this log entry? This cannot be undone.', 'fatal-plugin-auto-deactivator' ),
				)
			);

			return;
		}

		if ( self::has_pending_notice() ) {
			wp_enqueue_style( 'fpad-notice', FPAD_PLUGIN_URL . 'assets/css/notice.css', array(), FPAD_VERSION );
		}
	}

	/**
	 * Whether this request will render one of the plugin's admin notices.
	 *
	 * @return bool
	 */
	private static function has_pending_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}

		$pending = get_option( 'fpad_deactivated_plugins', array() );
		if ( ! empty( $pending ) ) {
			return true;
		}

		return current_user_can( 'manage_options' ) && 'active' !== self::get_protection_state();
	}

	/**
	 * Display an admin notice for plugins that were just deactivated.
	 *
	 * All pending deactivations are grouped into a single notice so a cascade of
	 * failures cannot bury the rest of the screen.
	 */
	public static function display_admin_notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$deactivated_plugins = get_option( 'fpad_deactivated_plugins', [] );

		if ( empty( $deactivated_plugins ) ) {
			return;
		}

		$items = '';
		foreach ( $deactivated_plugins as $plugin_data ) {
			$plugin_file = $plugin_data['plugin'];
			$plugin_name = $plugin_file;

			// The plugin may have been deleted since it crashed, so only read its
			// header when the file is still there.
			if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$plugin_data_obj = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
				$plugin_name     = $plugin_data_obj['Name'] ?: $plugin_file;
			}
			$error_message   = isset( $plugin_data['error']['message'] ) ? $plugin_data['error']['message'] : '';

			$items .= '<li class="fpad-notice-item">'
				. '<span class="fpad-notice-plugin">' . esc_html( $plugin_name ) . '</span>'
				. '<span class="fpad-notice-error">' . esc_html( $error_message ) . '</span>'
				. '</li>';
		}

		$title = _n(
			'A plugin was deactivated after a fatal error',
			'Plugins were deactivated after fatal errors',
			count( $deactivated_plugins ),
			'fatal-plugin-auto-deactivator'
		);

		echo '<div class="notice notice-error is-dismissible fpad-notice fpad-notice--danger">';
		echo '<div class="fpad-notice-inner">';
		echo '<span class="fpad-notice-icon">' . FPAD_Admin_UI::icon( 'shield-alert' ) . '</span>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="fpad-notice-body">';
		echo '<p class="fpad-notice-title">' . esc_html( $title ) . '</p>';
		echo '<p class="fpad-notice-text">' . esc_html__( 'Your site stayed online. Review the details below, fix or update the plugin, then reactivate it.', 'fatal-plugin-auto-deactivator' ) . '</p>';
		echo '<ul class="fpad-notice-list">' . $items . '</ul>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<p class="fpad-notice-actions"><a href="' . esc_url( admin_url( 'tools.php?page=fpad-log' ) ) . '">' . esc_html__( 'Open the fatal error log', 'fatal-plugin-auto-deactivator' ) . '</a></p>';
		echo '</div></div></div>';

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

		// Surface the outcome of a test notification (post-redirect).
		if ( isset( $_GET['fpad_test'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$test_ok     = '1' === $_GET['fpad_test']; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$test_detail = isset( $_GET['fpad_test_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['fpad_test_detail'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $test_ok ) {
				add_settings_error( 'fpad_messages', 'fpad_test', __( 'Test notification sent.', 'fatal-plugin-auto-deactivator' ), 'success' );
			} else {
				add_settings_error(
					'fpad_messages',
					'fpad_test',
					sprintf(
						/* translators: %s: transport error detail, e.g. an HTTP status code. */
						__( 'Test notification failed: %s', 'fatal-plugin-auto-deactivator' ),
						// settings_errors() prints messages unescaped, and this query
						// arg is craftable without a nonce — escape and bound it.
						esc_html( substr( $test_detail, 0, 200 ) )
					),
					'error'
				);
			}
		}

		// Determine the active tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'log'; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'log', 'settings' ), true ) ) {
			$tab = 'log';
		}

		$log     = self::get_log();
		$entries = count( $log );

		echo '<div class="wrap" id="fpad-app">';

		self::render_masthead();

		echo '<nav class="fpad-tabs" aria-label="' . esc_attr__( 'Fatal Plugin Auto Deactivator sections', 'fatal-plugin-auto-deactivator' ) . '">';
		$tabs = array(
			'log'      => array(
				'label' => __( 'Log', 'fatal-plugin-auto-deactivator' ),
				'icon'  => 'list',
				'count' => $entries,
			),
			'settings' => array(
				'label' => __( 'Settings', 'fatal-plugin-auto-deactivator' ),
				'icon'  => 'sliders',
				'count' => null,
			),
		);
		foreach ( $tabs as $slug => $item ) {
			$url = admin_url( 'tools.php?page=fpad-log' . ( 'log' === $slug ? '' : '&tab=' . $slug ) );
			echo '<a href="' . esc_url( $url ) . '" class="fpad-tab' . ( $slug === $tab ? ' is-active' : '' ) . '"' . ( $slug === $tab ? ' aria-current="page"' : '' ) . '>';
			echo FPAD_Admin_UI::icon( $item['icon'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<span>' . esc_html( $item['label'] ) . '</span>';
			if ( null !== $item['count'] ) {
				echo '<span class="fpad-tab-count">' . esc_html( number_format_i18n( $item['count'] ) ) . '</span>';
			}
			echo '</a>';
		}
		echo '</nav>';

		// Show any settings errors/messages. The .wp-header-end marker matters:
		// without it core's common.js relocates every .notice to just after the
		// first .wrap h1 — which here lives inside the masthead. With the marker
		// inside this container, relocated notices stay where they belong.
		echo '<div class="fpad-notices"><hr class="wp-header-end">';
		settings_errors( 'fpad_messages' );
		echo '</div>';

		self::render_protection_banner();

		if ( 'settings' === $tab ) {
			self::render_settings_tab();
		} else {
			self::render_log_tab( $log );
		}

		echo '</div>';
	}

	/**
	 * Render the page masthead: identity, version and protection state at a glance.
	 */
	private static function render_masthead() {
		$status = self::get_protection_state();
		$active = ( 'active' === $status );

		echo '<div class="fpad-masthead">';
		echo '<div class="fpad-brand">';
		// The logo, not a state glyph: protection state is carried by the badge on
		// the right of this row and by the banner below it.
		echo '<span class="fpad-brand-mark">' . FPAD_Admin_UI::logo() . '</span>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div>';
		echo '<h1 class="fpad-title">' . esc_html__( 'Fatal Plugin Auto Deactivator', 'fatal-plugin-auto-deactivator' ) . '</h1>';
		echo '<p class="fpad-subtitle">' . sprintf(
			/* translators: %s: plugin version number. */
			esc_html__( 'Keeps your site online by switching off whatever crashes it. Version %s', 'fatal-plugin-auto-deactivator' ),
			esc_html( FPAD_VERSION )
		) . '</p>';
		echo '</div></div>';

		echo '<div class="fpad-masthead-actions">';
		echo $active //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			? FPAD_Admin_UI::badge( __( 'Protection active', 'fatal-plugin-auto-deactivator' ), 'ok', 'shield-check' )
			: FPAD_Admin_UI::badge( __( 'Not protected', 'fatal-plugin-auto-deactivator' ), 'danger', 'shield-alert' );
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Read the deactivation log, normalised to an array.
	 *
	 * @return array
	 */
	private static function get_log() {
		$log = get_option( 'fpad_deactivation_log', array() );

		return is_array( $log ) ? $log : array();
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
	 * Render the Log tab: stats, filters and the incident list.
	 *
	 * @param array $deactivation_log The full deactivation log, newest first.
	 */
	private static function render_log_tab( $deactivation_log ) {
		$total_entries = count( $deactivation_log );

		// Read filters. These only affect the read-only display, so no nonce is needed.
		$f_source = isset( $_GET['fpad_source'] ) ? sanitize_key( wp_unslash( $_GET['fpad_source'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_status = isset( $_GET['fpad_status'] ) ? sanitize_key( wp_unslash( $_GET['fpad_status'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$f_query  = isset( $_GET['fpad_q'] ) ? sanitize_text_field( wp_unslash( $_GET['fpad_q'] ) ) : ''; //phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $deactivation_log ) ) {
			echo FPAD_Admin_UI::panel_open( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'title' => __( 'Fatal error log', 'fatal-plugin-auto-deactivator' ),
					'desc'  => __( 'Every fatal error this plugin detects is recorded here, whether or not it could be traced to a plugin.', 'fatal-plugin-auto-deactivator' ),
					'icon'  => 'list',
					'flush' => true,
				)
			);
			echo FPAD_Admin_UI::empty_state( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'icon'  => 'shield-check',
					'title' => __( 'No fatal errors logged', 'fatal-plugin-auto-deactivator' ),
					'text'  => __( 'Nothing has crashed since protection was switched on. If a plugin, theme or drop-in ever throws a fatal error, the incident shows up here with everything you need to debug it.', 'fatal-plugin-auto-deactivator' ),
				)
			);
			echo FPAD_Admin_UI::panel_close(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			return;
		}

		self::render_log_summary( $deactivation_log );

		$filtered = self::filter_log( $deactivation_log, $f_source, $f_status, $f_query );

		// Export always covers the full log, not the filtered view.
		$export_csv  = wp_nonce_url( admin_url( 'admin-post.php?action=fpad_export_log&format=csv' ), 'fpad_export_log' );
		$export_json = wp_nonce_url( admin_url( 'admin-post.php?action=fpad_export_log&format=json' ), 'fpad_export_log' );

		$actions = FPAD_Admin_UI::button(
			array(
				'label' => __( 'Export CSV', 'fatal-plugin-auto-deactivator' ),
				'href'  => $export_csv,
				'icon'  => 'download',
				'size'  => 'sm',
			)
		) . FPAD_Admin_UI::button(
			array(
				'label' => __( 'Export JSON', 'fatal-plugin-auto-deactivator' ),
				'href'  => $export_json,
				'icon'  => 'download',
				'size'  => 'sm',
			)
		);

		echo FPAD_Admin_UI::panel_open( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title'   => __( 'Fatal error log', 'fatal-plugin-auto-deactivator' ),
				'desc'    => __( 'Newest first. Identical repeats are grouped, and the log keeps the 100 most recent incidents.', 'fatal-plugin-auto-deactivator' ),
				'icon'    => 'list',
				'actions' => $actions,
				'flush'   => true,
			)
		);

		self::render_filter_bar( $f_source, $f_status, $f_query );

		if ( empty( $filtered ) ) {
			echo FPAD_Admin_UI::empty_state( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'icon'    => 'search',
					'title'   => __( 'No matching incidents', 'fatal-plugin-auto-deactivator' ),
					'text'    => __( 'No log entry matches the current filters. Try a different source, status or search term.', 'fatal-plugin-auto-deactivator' ),
					'actions' => FPAD_Admin_UI::button(
						array(
							'label' => __( 'Clear filters', 'fatal-plugin-auto-deactivator' ),
							'href'  => admin_url( 'tools.php?page=fpad-log' ),
							'icon'  => 'x',
							'size'  => 'sm',
						)
					),
				)
			);
		} else {
			self::render_entries( $filtered );
		}

		// Footer: how much of the log is on screen, plus the destructive action.
		$showing = sprintf(
			/* translators: 1: number of matching incidents, 2: total number of incidents */
			esc_html__( 'Showing %1$s of %2$s incidents', 'fatal-plugin-auto-deactivator' ),
			esc_html( number_format_i18n( count( $filtered ) ) ),
			esc_html( number_format_i18n( $total_entries ) )
		);

		$clear_form = '<form method="post" data-fpad-confirm="' . esc_attr__( 'Clear the entire log? This cannot be undone.', 'fatal-plugin-auto-deactivator' ) . '">'
			. wp_nonce_field( 'fpad_clear_log', 'fpad_nonce', true, false )
			. FPAD_Admin_UI::button(
				array(
					'label'   => __( 'Clear log', 'fatal-plugin-auto-deactivator' ),
					'variant' => 'danger',
					'size'    => 'sm',
					'icon'    => 'trash',
					'type'    => 'submit',
					'name'    => 'fpad_clear_log',
					'value'   => '1',
				)
			)
			. '</form>';

		echo FPAD_Admin_UI::panel_close( '<p class="fpad-inline-note">' . $showing . '</p>' . $clear_form ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render the at-a-glance stat cards above the log.
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
				'label'   => __( 'Fatal errors caught', 'fatal-plugin-auto-deactivator' ),
				'value'   => number_format_i18n( $total ),
				'icon'    => 'activity',
				'variant' => 'danger',
			),
			array(
				'label'   => __( 'Plugins deactivated', 'fatal-plugin-auto-deactivator' ),
				'value'   => number_format_i18n( $deactivated ),
				'icon'    => 'power',
				'variant' => 'ok',
			),
			array(
				'label'   => __( 'Not attributed to a plugin', 'fatal-plugin-auto-deactivator' ),
				'value'   => number_format_i18n( $unattributed ),
				'icon'    => 'help',
				'variant' => 'warn',
			),
			array(
				'label'   => __( 'Most recent incident', 'fatal-plugin-auto-deactivator' ),
				'value'   => $latest_time ? wp_date( 'M j, g:i a', $latest_time ) : '—',
				'icon'    => 'clock',
				'variant' => 'brand',
			),
		);

		echo '<div class="fpad-stats">';
		foreach ( $cards as $card ) {
			echo FPAD_Admin_UI::stat( $card ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}

	/**
	 * Render the incident list.
	 *
	 * Each entry is a card rather than a table row: the interesting payload is a
	 * multi-line error message, which a fixed grid of columns cannot show without
	 * either truncating it or breaking the layout on small screens.
	 *
	 * @param array $deactivation_log The deactivation log entries to display.
	 */
	private static function render_entries( $deactivation_log ) {
		echo '<div class="fpad-entries">';

		foreach ( $deactivation_log as $entry ) {
			$error_type  = self::get_error_type_string( $entry['error_type'] );
			$status      = self::entry_status( $entry );
			$status_meta = self::status_meta( $status );
			$source_key  = self::source_key( isset( $entry['error_file'] ) ? $entry['error_file'] : '' );
			$source      = self::source_label( $source_key );
			$count       = isset( $entry['count'] ) ? (int) $entry['count'] : 1;
			$time        = isset( $entry['time'] ) ? $entry['time'] : 0;
			$entry_key   = self::entry_key( $entry );

			$delete_url = wp_nonce_url(
				admin_url( 'tools.php?page=fpad-log&fpad_action=delete&key=' . rawurlencode( $entry_key ) ),
				'fpad_delete_' . $entry_key
			);

			echo '<article class="fpad-entry">';

			echo '<div class="fpad-entry-head">';

			echo FPAD_Admin_UI::entry_mark( self::source_icon( $source_key ), $status_meta['variant'] ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			echo '<div class="fpad-entry-ident">';
			echo '<div class="fpad-entry-badges">';
			echo self::status_badge( $status ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo FPAD_Admin_UI::badge( $source, 'source' ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( $count > 1 ) {
				echo FPAD_Admin_UI::badge( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					sprintf(
						/* translators: %s: number of times this identical error was seen. */
						__( '%s×', 'fatal-plugin-auto-deactivator' ),
						number_format_i18n( $count )
					),
					'neutral'
				);
			}
			echo '</div>';

			if ( ! empty( $entry['plugin_name'] ) ) {
				echo '<h3 class="fpad-entry-title">' . esc_html( $entry['plugin_name'] ) . '</h3>';
				echo '<p class="fpad-entry-sub">' . esc_html( $entry['plugin'] ) . '</p>';
			} else {
				// "Not traced to a plugin" only said what did not happen. Name the
				// origin instead, and say what the admin can actually do about it.
				$unattributed = self::unattributed_copy( $source_key );
				echo '<h3 class="fpad-entry-title">' . esc_html( $unattributed['title'] ) . '</h3>';
				echo '<p class="fpad-entry-note">' . esc_html( $unattributed['note'] ) . '</p>';
			}
			echo '</div>';

			echo '<div class="fpad-entry-actions">';
			echo '<span class="fpad-entry-when"><strong>' . esc_html( wp_date( 'M j, Y', $time ) ) . '</strong>' . esc_html( wp_date( 'g:i:s a', $time ) ) . '</span>';
			echo FPAD_Admin_UI::button( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'label' => __( 'Copy', 'fatal-plugin-auto-deactivator' ),
					'icon'  => 'copy',
					'size'  => 'sm',
					'attrs' => array(
						'class'            => 'fpad-copy',
						'data-fpad-report' => self::build_report( $entry ),
						'aria-label'       => __( 'Copy this incident as a bug report', 'fatal-plugin-auto-deactivator' ),
					),
				)
			);
			echo FPAD_Admin_UI::button( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'label'   => __( 'Delete', 'fatal-plugin-auto-deactivator' ),
					'href'    => $delete_url,
					'icon'    => 'trash',
					'size'    => 'sm',
					'variant' => 'danger',
					'attrs'   => array( 'data-fpad-confirm' => __( 'Delete this log entry? This cannot be undone.', 'fatal-plugin-auto-deactivator' ) ),
				)
			);
			echo '</div>';

			echo '</div>';

			// Payload column, indented to line up with the headline rather than
			// with the icon tile beside it.
			echo '<div class="fpad-entry-body">';

			// tabindex: the block is a fixed-height scroll region (see .fpad-code),
			// which has to stay reachable without a mouse.
			echo '<div class="fpad-code" tabindex="0" role="region" aria-label="' . esc_attr__( 'Error message', 'fatal-plugin-auto-deactivator' ) . '"><strong>' . esc_html( $error_type ) . '</strong>' . esc_html( $entry['error_msg'] ) . '</div>';
			echo '<p class="fpad-path">' . esc_html( $entry['error_file'] ) . ':' . esc_html( $entry['error_line'] ) . '</p>';

			// Context chips: what was being requested, on which stack, and how long
			// this error has been recurring.
			$chips = '';
			if ( $count > 1 && ! empty( $entry['first_time'] ) ) {
				$chips .= FPAD_Admin_UI::chip(
					'calendar',
					__( 'First seen', 'fatal-plugin-auto-deactivator' ),
					wp_date( 'M j, Y g:i a', $entry['first_time'] )
				);
			}
			if ( ! empty( $entry['request_uri'] ) ) {
				$chips .= FPAD_Admin_UI::chip( 'link', __( 'Request', 'fatal-plugin-auto-deactivator' ), $entry['request_uri'] );
			}
			if ( ! empty( $entry['php_version'] ) ) {
				$chips .= FPAD_Admin_UI::chip( 'cpu', __( 'PHP', 'fatal-plugin-auto-deactivator' ), 'PHP ' . $entry['php_version'] );
			}
			if ( ! empty( $entry['wp_version'] ) ) {
				$chips .= FPAD_Admin_UI::chip( 'file', __( 'WordPress', 'fatal-plugin-auto-deactivator' ), 'WP ' . $entry['wp_version'] );
			}
			if ( '' !== $chips ) {
				echo '<div class="fpad-entry-meta">' . $chips . '</div>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</div>';

			echo '</article>';
		}

		echo '</div>';
	}

	/**
	 * Icon key for an error source, used on the entry card's mark.
	 *
	 * @param string $source_key One of the keys returned by source_key().
	 * @return string Icon key understood by FPAD_Admin_UI::icon().
	 */
	private static function source_icon( $source_key ) {
		$map = array(
			'plugin'    => 'plug',
			'mu-plugin' => 'plug',
			'theme'     => 'file',
			'drop-in'   => 'cpu',
			'core'      => 'globe',
			'unknown'   => 'help',
		);

		return isset( $map[ $source_key ] ) ? $map[ $source_key ] : 'help';
	}

	/**
	 * Headline and explanation for an incident that could not be pinned on a plugin.
	 *
	 * Only errors inside wp-content/plugins/<dir> belong to a plugin we can switch
	 * off; everything else is logged with no attribution. Rather than repeat that
	 * as a shrug, each source gets the reason nothing was deactivated and the one
	 * move that recovers the site. Wording follows the visitor error page in
	 * FPAD_Fatal_Error_Handler::display_custom_error_page() (a loose sync pair:
	 * same explanations, different voice — that page speaks to a visitor, this one
	 * to the administrator).
	 *
	 * @param string $source_key One of the keys returned by source_key().
	 * @return array{title:string,note:string}
	 */
	private static function unattributed_copy( $source_key ) {
		switch ( $source_key ) {
			case 'theme':
				return array(
					'title' => __( 'Fatal error in the active theme', 'fatal-plugin-auto-deactivator' ),
					'note'  => __( 'Themes are never deactivated automatically. Switch to a default theme to bring the site back.', 'fatal-plugin-auto-deactivator' ),
				);

			case 'mu-plugin':
				return array(
					'title' => __( 'Fatal error in a must-use plugin', 'fatal-plugin-auto-deactivator' ),
					'note'  => __( 'Must-use plugins load before this one can step in and cannot be switched off. Fix or remove the file in wp-content/mu-plugins.', 'fatal-plugin-auto-deactivator' ),
				);

			case 'drop-in':
				return array(
					'title' => __( 'Fatal error in a drop-in', 'fatal-plugin-auto-deactivator' ),
					'note'  => __( 'Drop-ins such as object-cache.php or advanced-cache.php load before plugins. Rename or delete the file in wp-content to recover.', 'fatal-plugin-auto-deactivator' ),
				);

			case 'core':
				return array(
					'title' => __( 'Fatal error in WordPress core', 'fatal-plugin-auto-deactivator' ),
					'note'  => __( 'The crash came from WordPress itself, not from a plugin. Reinstall core files or check the PHP version and extensions on the server.', 'fatal-plugin-auto-deactivator' ),
				);

			case 'plugin':
				return array(
					'title' => __( 'Fatal error in an unrecognized plugin', 'fatal-plugin-auto-deactivator' ),
					'note'  => __( 'The file sits in the plugins directory but matched no active plugin. It may have been renamed, deleted or already switched off.', 'fatal-plugin-auto-deactivator' ),
				);

			default:
				return array(
					'title' => __( 'Fatal error from an unidentified file', 'fatal-plugin-auto-deactivator' ),
					'note'  => __( 'PHP reported no usable file path, so the crash could not be traced. The message below is everything it gave us.', 'fatal-plugin-auto-deactivator' ),
				);
		}
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

		// Symlinked plugins/themes are logged under their resolved path, so match
		// both spellings — see FPAD_Fatal_Error_Handler::path_variants().
		$files = FPAD_Fatal_Error_Handler::path_variants( $file );

		if ( defined( 'WPMU_PLUGIN_DIR' ) && FPAD_Fatal_Error_Handler::path_is_inside( $files, WPMU_PLUGIN_DIR ) ) {
			return 'mu-plugin';
		}

		if ( defined( 'WP_PLUGIN_DIR' ) && FPAD_Fatal_Error_Handler::path_is_inside( $files, WP_PLUGIN_DIR ) ) {
			return 'plugin';
		}

		$theme_root = function_exists( 'get_theme_root' ) ? $normalize( get_theme_root() ) : '';
		if ( '' !== $theme_root && FPAD_Fatal_Error_Handler::path_is_inside( $files, $theme_root ) ) {
			return 'theme';
		}

		// A plugin/theme symlinked in individually resolves outside wp-content, so
		// the root prefix tests above cannot see it. Two levels deep, matching
		// FPAD_Fatal_Error_Handler::detect_error_source(), so a symlink inside a
		// plugin or theme folder is classified too.
		if ( defined( 'WPMU_PLUGIN_DIR' ) && FPAD_Fatal_Error_Handler::matches_symlinked_child( $files, WPMU_PLUGIN_DIR, 2 ) ) {
			return 'mu-plugin';
		}
		if ( defined( 'WP_PLUGIN_DIR' ) && FPAD_Fatal_Error_Handler::matches_symlinked_child( $files, WP_PLUGIN_DIR, 2 ) ) {
			return 'plugin';
		}
		if ( '' !== $theme_root && FPAD_Fatal_Error_Handler::matches_symlinked_child( $files, $theme_root, 2 ) ) {
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
	 * Render the source/status/search filter toolbar.
	 *
	 * @param string $f_source Selected source key.
	 * @param string $f_status Selected status key.
	 * @param string $f_query  Current search query.
	 */
	private static function render_filter_bar( $f_source, $f_status, $f_query ) {
		$has_filters = ( '' !== $f_source || '' !== $f_status || '' !== $f_query );

		echo '<form method="get" class="fpad:border-b fpad:border-ink-100 fpad:px-5 fpad:py-3">';
		echo '<input type="hidden" name="page" value="fpad-log">';
		echo '<div class="fpad-toolbar">';

		echo '<div class="fpad-field">';
		echo '<label class="fpad-field-label" for="fpad_q">' . esc_html__( 'Search', 'fatal-plugin-auto-deactivator' ) . '</label>';
		echo '<input type="search" id="fpad_q" name="fpad_q" class="fpad-input fpad-search" value="' . esc_attr( $f_query ) . '" placeholder="' . esc_attr__( 'Plugin, message or file…', 'fatal-plugin-auto-deactivator' ) . '">';
		echo '</div>';

		echo '<div class="fpad-field">';
		echo '<label class="fpad-field-label" for="fpad_source">' . esc_html__( 'Source', 'fatal-plugin-auto-deactivator' ) . '</label>';
		echo FPAD_Admin_UI::select( 'fpad_source', self::source_labels(), $f_source, __( 'All sources', 'fatal-plugin-auto-deactivator' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<div class="fpad-field">';
		echo '<label class="fpad-field-label" for="fpad_status">' . esc_html__( 'Outcome', 'fatal-plugin-auto-deactivator' ) . '</label>';
		echo FPAD_Admin_UI::select( 'fpad_status', self::status_labels(), $f_status, __( 'All outcomes', 'fatal-plugin-auto-deactivator' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo FPAD_Admin_UI::button( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label' => __( 'Filter', 'fatal-plugin-auto-deactivator' ),
				'icon'  => 'filter',
				'type'  => 'submit',
			)
		);

		if ( $has_filters ) {
			echo FPAD_Admin_UI::button( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'label'   => __( 'Reset', 'fatal-plugin-auto-deactivator' ),
					'href'    => admin_url( 'tools.php?page=fpad-log' ),
					'icon'    => 'x',
					'variant' => 'ghost',
				)
			);
		}

		echo '</div>';
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
	 * Label, badge variant and icon for an outcome status.
	 *
	 * Single source of truth for how a status looks and reads, shared by the
	 * badges, the filter dropdown and the notification settings.
	 *
	 * @param string $status One of: deactivated, protected, log_only, unavailable, logged, unattributed.
	 * @return array {
	 *     @type string $label   Translated label.
	 *     @type string $variant Badge variant.
	 *     @type string $icon    Icon key.
	 * }
	 */
	private static function status_meta( $status ) {
		$map = array(
			'deactivated'  => array(
				'label'   => __( 'Deactivated', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'ok',
				'icon'    => 'power',
			),
			'protected'    => array(
				'label'   => __( 'Protected', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'warn',
				'icon'    => 'shield-check',
			),
			'log_only'     => array(
				'label'   => __( 'Log only', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'info',
				'icon'    => 'list',
			),
			'unavailable'  => array(
				'label'   => __( 'Could not deactivate', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'danger',
				'icon'    => 'ban',
			),
			'logged'       => array(
				'label'   => __( 'Logged only', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'neutral',
				'icon'    => 'list',
			),
			'unattributed' => array(
				'label'   => __( 'Not attributed', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'neutral',
				'icon'    => 'help',
			),
		);

		return isset( $map[ $status ] ) ? $map[ $status ] : $map['logged'];
	}

	/**
	 * Map an outcome status to a coloured badge.
	 *
	 * @param string $status One of: deactivated, protected, log_only, logged, unattributed.
	 * @return string
	 */
	private static function status_badge( $status ) {
		$meta = self::status_meta( $status );

		return FPAD_Admin_UI::badge( $meta['label'], $meta['variant'], $meta['icon'] );
	}

	/**
	 * Status key => label map, in the order the UI presents them.
	 *
	 * @return array
	 */
	private static function status_labels() {
		$labels = array();
		foreach ( array( 'deactivated', 'protected', 'log_only', 'unavailable', 'logged', 'unattributed' ) as $key ) {
			$meta            = self::status_meta( $key );
			$labels[ $key ] = $meta['label'];
		}

		return $labels;
	}

	/**
	 * Read the plugin settings with defaults.
	 *
	 * Public because FPAD_Notifier reads the notification settings through this
	 * method; keep it the single admin-side reader so only two mirrors exist
	 * (this one and the guarded copy in FPAD_Fatal_Error_Handler — sync pair).
	 *
	 * @return array
	 */
	public static function get_settings() {
		$notify_statuses_default = array( 'deactivated', 'protected', 'log_only', 'unavailable', 'unattributed' );

		$settings = get_option( 'fpad_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array(
			'log_only'              => ! empty( $settings['log_only'] ),
			'protected_plugins'     => ( isset( $settings['protected_plugins'] ) && is_array( $settings['protected_plugins'] ) )
				? $settings['protected_plugins']
				: array(),
			'notify_email'          => ! empty( $settings['notify_email'] ),
			'notify_email_to'       => ( isset( $settings['notify_email_to'] ) && is_string( $settings['notify_email_to'] ) )
				? $settings['notify_email_to']
				: '',
			'notify_webhook'        => ! empty( $settings['notify_webhook'] ),
			'notify_webhook_url'    => ( isset( $settings['notify_webhook_url'] ) && is_string( $settings['notify_webhook_url'] ) )
				? $settings['notify_webhook_url']
				: '',
			'notify_webhook_format' => ( isset( $settings['notify_webhook_format'] ) && in_array( $settings['notify_webhook_format'], array( 'json', 'slack' ), true ) )
				? $settings['notify_webhook_format']
				: 'json',
			// A saved-but-empty array is a deliberate "notify about nothing" choice;
			// only a missing/malformed value falls back to the defaults.
			'notify_statuses'       => ( isset( $settings['notify_statuses'] ) && is_array( $settings['notify_statuses'] ) )
				? array_values( array_intersect( $settings['notify_statuses'], $notify_statuses_default ) )
				: $notify_statuses_default,
			'notify_cooldown'       => ( isset( $settings['notify_cooldown'] ) && is_numeric( $settings['notify_cooldown'] ) )
				? max( 60, min( 86400, (int) $settings['notify_cooldown'] ) )
				: 900,
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
	 * Render the Settings tab: deactivation behaviour, allowlist and notifications.
	 */
	private static function render_settings_tab() {
		$settings = self::get_settings();
		$active   = self::get_active_plugin_choices();

		echo '<form method="post">';
		wp_nonce_field( 'fpad_save_settings', 'fpad_settings_nonce' );

		/* ------------------------------------------------ Deactivation behaviour */

		echo FPAD_Admin_UI::panel_open( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title' => __( 'When a plugin crashes your site', 'fatal-plugin-auto-deactivator' ),
				'desc'  => __( 'Choose what happens the moment a fatal error is traced back to a plugin.', 'fatal-plugin-auto-deactivator' ),
				'icon'  => 'power',
				'flush' => true,
			)
		);

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Log-only mode', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Detect and log fatal errors, but never deactivate anything. Use this if you would rather investigate crashes yourself than have plugins switched off automatically.', 'fatal-plugin-auto-deactivator' ),
				'control' => FPAD_Admin_UI::switch_control(
					array(
						'name'    => 'fpad_log_only',
						'checked' => $settings['log_only'],
						'text'    => $settings['log_only']
							? __( 'Never deactivate', 'fatal-plugin-auto-deactivator' )
							: __( 'Deactivate automatically', 'fatal-plugin-auto-deactivator' ),
						'attrs'   => array( 'data-fpad-switch-text' => wp_json_encode( array( __( 'Deactivate automatically', 'fatal-plugin-auto-deactivator' ), __( 'Never deactivate', 'fatal-plugin-auto-deactivator' ) ) ) ),
					)
				),
			)
		);

		echo FPAD_Admin_UI::panel_close(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* --------------------------------------------------------- Allowlist */

		echo FPAD_Admin_UI::panel_open( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title' => __( 'Protected plugins', 'fatal-plugin-auto-deactivator' ),
				'desc'  => __( 'These plugins are never deactivated automatically, even when they cause the crash. The error is still logged and visitors still get an honest error page.', 'fatal-plugin-auto-deactivator' ),
				'icon'  => 'shield-check',
			)
		);

		if ( empty( $active ) ) {
			echo '<p class="fpad-help">' . esc_html__( 'No active plugins found.', 'fatal-plugin-auto-deactivator' ) . '</p>';
		} else {
			echo '<div class="fpad:mb-3 fpad:flex fpad:items-center fpad:gap-2">';
			echo '<label class="fpad-sr-only" for="fpad-plugin-filter">' . esc_html__( 'Filter plugins', 'fatal-plugin-auto-deactivator' ) . '</label>';
			echo '<input type="search" id="fpad-plugin-filter" class="fpad-input fpad-search" data-fpad-filter="#fpad-protected-list" placeholder="' . esc_attr__( 'Filter plugins…', 'fatal-plugin-auto-deactivator' ) . '">';
			echo '<span class="fpad-inline-note">' . sprintf(
				/* translators: %s: number of protected plugins. */
				esc_html__( '%s protected', 'fatal-plugin-auto-deactivator' ),
				esc_html( number_format_i18n( count( $settings['protected_plugins'] ) ) )
			) . '</span>';
			echo '</div>';

			echo '<fieldset id="fpad-protected-list" class="fpad-checklist">';
			echo '<legend class="fpad-sr-only">' . esc_html__( 'Plugins that must never be deactivated automatically', 'fatal-plugin-auto-deactivator' ) . '</legend>';
			foreach ( $active as $basename => $name ) {
				echo FPAD_Admin_UI::check_card( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					'fpad_protected_plugins[]',
					$basename,
					$name,
					in_array( $basename, $settings['protected_plugins'], true )
				);
			}
			echo '</fieldset>';
		}

		echo FPAD_Admin_UI::panel_close(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* ----------------------------------------------------- Notifications */

		echo FPAD_Admin_UI::panel_open( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'title' => __( 'Notifications', 'fatal-plugin-auto-deactivator' ),
				'desc'  => __( 'Get an instant email or webhook alert when a fatal error is detected. Alerts carry the same detail as the log, so send them only to endpoints you control.', 'fatal-plugin-auto-deactivator' ),
				'icon'  => 'bell',
				'flush' => true,
			)
		);

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Email alerts', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Send an email the moment a fatal error is detected.', 'fatal-plugin-auto-deactivator' ),
				'control' => FPAD_Admin_UI::switch_control(
					array(
						'name'    => 'fpad_notify_email',
						'checked' => $settings['notify_email'],
						'text'    => __( 'Enabled', 'fatal-plugin-auto-deactivator' ),
					)
				),
			)
		);

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Email recipients', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Comma-separated addresses. Leave empty to use the site admin email.', 'fatal-plugin-auto-deactivator' ),
				'control' => '<input type="text" name="fpad_notify_email_to" class="fpad-input fpad:w-full fpad:sm:w-80" value="' . esc_attr( $settings['notify_email_to'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '">',
			)
		);

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Webhook alerts', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'POST the incident to a URL, such as a Slack incoming webhook or your own endpoint.', 'fatal-plugin-auto-deactivator' ),
				'control' => FPAD_Admin_UI::switch_control(
					array(
						'name'    => 'fpad_notify_webhook',
						'checked' => $settings['notify_webhook'],
						'text'    => __( 'Enabled', 'fatal-plugin-auto-deactivator' ),
					)
				),
			)
		);

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Webhook URL', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Must use https:// (plain http:// is allowed only for localhost).', 'fatal-plugin-auto-deactivator' ),
				'control' => '<input type="url" name="fpad_notify_webhook_url" class="fpad-input fpad:w-full fpad:sm:w-80" value="' . esc_url( $settings['notify_webhook_url'] ) . '" placeholder="https://example.com/webhook">',
			)
		);

		$formats = '<div class="fpad-radio-row">';
		foreach ( array(
			'json'  => __( 'Generic JSON', 'fatal-plugin-auto-deactivator' ),
			'slack' => __( 'Slack-compatible', 'fatal-plugin-auto-deactivator' ),
		) as $format => $format_label ) {
			$formats .= '<label class="fpad-radio"><input type="radio" name="fpad_notify_webhook_format" value="' . esc_attr( $format ) . '"' . checked( $settings['notify_webhook_format'], $format, false ) . '> ' . esc_html( $format_label ) . '</label>';
		}
		$formats .= '</div>';

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Webhook format', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Slack format posts a ready-to-read message; generic JSON posts the raw incident fields.', 'fatal-plugin-auto-deactivator' ),
				'control' => $formats,
			)
		);

		$statuses = '<div class="fpad-checklist">';
		foreach ( array( 'deactivated', 'protected', 'log_only', 'unavailable', 'unattributed' ) as $status_key ) {
			$meta      = self::status_meta( $status_key );
			$statuses .= FPAD_Admin_UI::check_card(
				'fpad_notify_statuses[]',
				$status_key,
				$meta['label'],
				in_array( $status_key, $settings['notify_statuses'], true )
			);
		}
		$statuses .= '</div>';

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Notify about', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Which outcomes are worth an alert. Unchecking everything silences alerts without turning the channels off.', 'fatal-plugin-auto-deactivator' ),
				'control' => $statuses,
				'stacked' => true,
			)
		);

		echo FPAD_Admin_UI::setting_row( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Alert cooldown', 'fatal-plugin-auto-deactivator' ),
				'help'    => __( 'Minimum time between repeated alerts for the same identical error, so a looping fatal cannot flood your inbox or channel.', 'fatal-plugin-auto-deactivator' ),
				'control' => '<div class="fpad:flex fpad:items-center fpad:gap-2">'
					. '<input type="number" name="fpad_notify_cooldown" class="fpad-input fpad:w-24" value="' . esc_attr( $settings['notify_cooldown'] ) . '" min="60" max="86400" step="1">'
					. '<span class="fpad-inline-note">' . esc_html__( 'seconds', 'fatal-plugin-auto-deactivator' ) . '</span>'
					. '</div>',
			)
		);

		echo FPAD_Admin_UI::panel_close(); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		/* --------------------------------------------------------- Save bar */

		// Test sends are nonce'd GET links to admin-post.php, so they can sit
		// inside the settings form without nesting one form in another.
		$tests = '';
		if ( $settings['notify_email'] ) {
			$tests .= FPAD_Admin_UI::button(
				array(
					'label' => __( 'Send test email', 'fatal-plugin-auto-deactivator' ),
					'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=fpad_test_alert&channel=email' ), 'fpad_test_alert' ),
					'icon'  => 'mail',
					'size'  => 'sm',
				)
			);
		}
		if ( $settings['notify_webhook'] && '' !== $settings['notify_webhook_url'] ) {
			$tests .= FPAD_Admin_UI::button(
				array(
					'label' => __( 'Send test webhook', 'fatal-plugin-auto-deactivator' ),
					'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=fpad_test_alert&channel=webhook' ), 'fpad_test_alert' ),
					'icon'  => 'send',
					'size'  => 'sm',
				)
			);
		}

		echo '<div class="fpad-savebar">';
		echo '<div class="fpad:flex fpad:flex-wrap fpad:items-center fpad:gap-2">';
		echo '' !== $tests //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			? $tests
			: '<span class="fpad-inline-note">' . esc_html__( 'Enable a channel above to send a test alert.', 'fatal-plugin-auto-deactivator' ) . '</span>';
		echo '</div>';
		echo FPAD_Admin_UI::button( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Save settings', 'fatal-plugin-auto-deactivator' ),
				'variant' => 'primary',
				'icon'    => 'check',
				'type'    => 'submit',
			)
		);
		echo '</div>';

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

		// Notifications: email channel. Invalid addresses are dropped silently;
		// an empty list means "use the admin email" at send time.
		$notify_email = ! empty( $_POST['fpad_notify_email'] );

		$email_to = '';
		if ( isset( $_POST['fpad_notify_email_to'] ) ) {
			$recipients = array();
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['fpad_notify_email_to'] ) ) ) as $candidate ) {
				$candidate = sanitize_email( trim( $candidate ) );
				if ( '' !== $candidate ) {
					$recipients[] = $candidate;
				}
			}
			$email_to = implode( ',', $recipients );
		}

		// Notifications: webhook channel. The URL is POSTed to on every alert, so
		// require https (plain http only for local development targets).
		$notify_webhook = ! empty( $_POST['fpad_notify_webhook'] );

		$webhook_url = '';
		if ( ! empty( $_POST['fpad_notify_webhook_url'] ) ) {
			$raw      = esc_url_raw( wp_unslash( $_POST['fpad_notify_webhook_url'] ) );
			$scheme   = wp_parse_url( $raw, PHP_URL_SCHEME );
			$host     = wp_parse_url( $raw, PHP_URL_HOST );
			$loopback = in_array( $host, array( 'localhost', '127.0.0.1' ), true );

			if ( $loopback && in_array( $scheme, array( 'http', 'https' ), true ) ) {
				// wp_http_validate_url() rejects loopback hosts outright, so the
				// documented localhost exception is validated manually; the send
				// paths skip reject_unsafe_urls for loopback for the same reason.
				$webhook_url = $raw;
			} else {
				$candidate = wp_http_validate_url( $raw );
				if ( $candidate && 'https' === wp_parse_url( $candidate, PHP_URL_SCHEME ) ) {
					$webhook_url = $candidate;
				} else {
					add_settings_error( 'fpad_messages', 'fpad_webhook_url', __( 'Webhook URL rejected: enter a valid https:// URL (http:// is allowed only for localhost).', 'fatal-plugin-auto-deactivator' ), 'error' );
				}
			}
		}

		$webhook_format = 'json';
		if ( isset( $_POST['fpad_notify_webhook_format'] ) ) {
			$candidate = sanitize_key( wp_unslash( $_POST['fpad_notify_webhook_format'] ) );
			if ( in_array( $candidate, array( 'json', 'slack' ), true ) ) {
				$webhook_format = $candidate;
			}
		}

		// Unchecking every status is a valid "notify about nothing" choice.
		$notify_statuses = array();
		if ( isset( $_POST['fpad_notify_statuses'] ) && is_array( $_POST['fpad_notify_statuses'] ) ) {
			$allowed_statuses = array( 'deactivated', 'protected', 'log_only', 'unavailable', 'unattributed' );
			$notify_statuses  = array_values( array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST['fpad_notify_statuses'] ) ), $allowed_statuses ) );
		}

		$notify_cooldown = 900;
		if ( isset( $_POST['fpad_notify_cooldown'] ) && '' !== $_POST['fpad_notify_cooldown'] ) {
			$notify_cooldown = max( 60, min( 86400, absint( wp_unslash( $_POST['fpad_notify_cooldown'] ) ) ) );
		}

		update_option(
			'fpad_settings',
			array(
				'log_only'              => $log_only,
				'protected_plugins'     => $protected,
				'notify_email'          => $notify_email,
				'notify_email_to'       => $email_to,
				'notify_webhook'        => $notify_webhook,
				'notify_webhook_url'    => $webhook_url,
				'notify_webhook_format' => $webhook_format,
				'notify_statuses'       => $notify_statuses,
				'notify_cooldown'       => $notify_cooldown,
			)
		);

		// The queue-drain cron exists only while a usable channel is enabled, so
		// installs that never enable notifications keep zero fpad cron rows.
		if ( $notify_email || ( $notify_webhook && '' !== $webhook_url ) ) {
			FPAD_Notifier::maybe_schedule_drain();
		} else {
			wp_clear_scheduled_hook( 'fpad_notifier_drain' );
		}

		add_settings_error( 'fpad_messages', 'fpad_settings_saved', __( 'Settings saved.', 'fatal-plugin-auto-deactivator' ), 'success' );
	}

	/**
	 * Current protection status, via the drop-in manager.
	 *
	 * Cached per request: the masthead, the status card, the notice gate and the
	 * Site Health test all ask for it, and each call hits the filesystem.
	 *
	 * @return string active|foreign|missing|unwritable|no_filesystem|disabled|stranded
	 */
	private static function get_protection_state() {
		static $status = null;

		if ( null === $status ) {
			$manager      = new FPAD_Dropin_Manager();
			$verification = $manager->verify_protection();
			$status       = $verification['status'];
		}

		return $status;
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
			case 'disabled':
				return __( 'The WP_DISABLE_FATAL_ERROR_HANDLER constant is enabled (usually in wp-config.php), so WordPress never runs any fatal error handler and Fatal Plugin Auto Deactivator cannot protect your site. Remove that constant to restore protection.', 'fatal-plugin-auto-deactivator' );
			case 'stranded':
				return __( 'The protection file is installed but points at nothing: the plugin folder appears to have been moved or renamed, so wp-content/plugins/fatal-plugin-auto-deactivator no longer contains the handler it tries to load. Restore the plugin to that folder to fix protection.', 'fatal-plugin-auto-deactivator' );
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

		echo '<div class="notice notice-error fpad-notice fpad-notice--danger">';
		echo '<div class="fpad-notice-inner">';
		echo '<span class="fpad-notice-icon">' . FPAD_Admin_UI::icon( 'shield-alert' ) . '</span>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="fpad-notice-body">';
		echo '<p class="fpad-notice-title">' . esc_html__( 'Your site is not protected against fatal errors', 'fatal-plugin-auto-deactivator' ) . '</p>';
		echo '<p class="fpad-notice-text">' . esc_html( self::protection_message( $status ) ) . '</p>';
		echo '<p class="fpad-notice-actions">';
		echo '<a class="fpad-notice-button" href="' . esc_url( self::reinstall_url() ) . '">' . esc_html__( 'Reinstall protection', 'fatal-plugin-auto-deactivator' ) . '</a>';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=fpad-log' ) ) . '">' . esc_html__( 'Review protection status', 'fatal-plugin-auto-deactivator' ) . '</a>';
		echo '</p>';
		echo '</div></div></div>';
	}

	/**
	 * Keep the Fatal Plugin Log screen focused by removing other plugins'/core
	 * admin notices there. Our own protection banner and action feedback render
	 * inline in the page body, so they are unaffected.
	 *
	 * @param WP_Screen $screen Current admin screen.
	 */
	public static function maybe_suppress_admin_notices( $screen ) {
		if ( ! $screen || 'tools_page_fpad-log' !== $screen->id ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Render the protection-status card at the top of the admin page.
	 */
	private static function render_protection_banner() {
		$status = self::get_protection_state();
		$active = ( 'active' === $status );

		// Watchdog heartbeat, so admins can see protection is re-verified on a schedule.
		$last_check = self::last_watchdog_check();
		if ( $last_check ) {
			/* translators: %s: human-readable time difference, e.g. "5 mins". */
			$verified = sprintf( __( '%s ago', 'fatal-plugin-auto-deactivator' ), human_time_diff( $last_check ) );
		} else {
			$verified = __( 'not yet', 'fatal-plugin-auto-deactivator' );
		}

		echo '<div class="fpad-state ' . ( $active ? 'fpad-state--ok' : 'fpad-state--bad' ) . '">';
		echo '<span class="fpad-state-icon">' . FPAD_Admin_UI::icon( $active ? 'shield-check' : 'shield-alert' ) . '</span>'; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="fpad-state-body">';
		echo '<p class="fpad-state-title">' . ( $active
			? esc_html__( 'Protection is active', 'fatal-plugin-auto-deactivator' )
			: esc_html__( 'Protection is not active', 'fatal-plugin-auto-deactivator' ) ) . '</p>';
		echo '<p class="fpad-state-text">' . ( $active
			? esc_html__( 'The fatal error handler drop-in is installed, so crashes are caught, logged and contained before visitors see them.', 'fatal-plugin-auto-deactivator' )
			: esc_html( self::protection_message( $status ) ) ) . '</p>';
		echo '</div>';

		echo '<div class="fpad:flex fpad:flex-wrap fpad:items-center fpad:gap-2">';
		echo FPAD_Admin_UI::chip( 'clock', __( 'Last verified', 'fatal-plugin-auto-deactivator' ), $verified ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo FPAD_Admin_UI::button( //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'label'   => __( 'Reinstall protection', 'fatal-plugin-auto-deactivator' ),
				'href'    => self::reinstall_url(),
				'icon'    => 'refresh',
				'variant' => $active ? 'default' : 'primary',
			)
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Timestamp of the watchdog's most recent check, or 0 when it has not run.
	 *
	 * Reads fpad_watchdog_state defensively: the option does not exist until
	 * the first watchdog run and must not raise notices when absent.
	 *
	 * @return int
	 */
	private static function last_watchdog_check() {
		$state = get_option( 'fpad_watchdog_state', array() );

		return ( is_array( $state ) && ! empty( $state['last_check'] ) ) ? (int) $state['last_check'] : 0;
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
	 * Send a test notification (admin-post handler), then redirect back to the
	 * Settings tab with the outcome — including the webhook HTTP code on failure.
	 */
	public static function handle_test_alert() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to send test notifications.', 'fatal-plugin-auto-deactivator' ) );
		}

		check_admin_referer( 'fpad_test_alert' );

		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';

		$result = FPAD_Notifier::send_test( $channel );

		wp_safe_redirect(
			add_query_arg(
				array(
					'fpad_test'        => $result['success'] ? '1' : '0',
					'fpad_test_detail' => rawurlencode( $result['detail'] ),
				),
				admin_url( 'tools.php?page=fpad-log&tab=settings' )
			)
		);
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
		$verified = self::last_watchdog_check();

		$info['fpad'] = array(
			'label'  => __( 'Fatal Plugin Auto Deactivator', 'fatal-plugin-auto-deactivator' ),
			'fields' => array(
				'version'             => array(
					'label' => __( 'Version', 'fatal-plugin-auto-deactivator' ),
					'value' => FPAD_VERSION,
				),
				'protection'          => array(
					'label' => __( 'Protection status', 'fatal-plugin-auto-deactivator' ),
					'value' => $status,
				),
				'log_only'            => array(
					'label' => __( 'Log-only mode', 'fatal-plugin-auto-deactivator' ),
					'value' => $settings['log_only'] ? __( 'Yes', 'fatal-plugin-auto-deactivator' ) : __( 'No', 'fatal-plugin-auto-deactivator' ),
				),
				'protected'           => array(
					'label' => __( 'Protected plugins', 'fatal-plugin-auto-deactivator' ),
					'value' => count( $settings['protected_plugins'] ),
				),
				'logged_fatals'       => array(
					'label' => __( 'Logged fatal errors', 'fatal-plugin-auto-deactivator' ),
					'value' => count( $log ),
				),
				'last_fatal'          => array(
					'label' => __( 'Most recent fatal', 'fatal-plugin-auto-deactivator' ),
					'value' => $last,
				),
				'last_watchdog_check' => array(
					'label' => __( 'Protection last verified', 'fatal-plugin-auto-deactivator' ),
					'value' => $verified ? wp_date( 'Y-m-d H:i:s', $verified ) : '—',
				),
			),
		);

		return $info;
	}
}
