# Technical Reference

## Constants

Defined in `fatal-plugin-auto-deactivator.php` (all wrapped in `! defined()` guards):

| Constant | Value | Purpose |
|----------|-------|---------|
| `FPAD_VERSION` | `'1.4.0'` | Plugin version (keep in sync with plugin header + readme.txt stable tag) |
| `FPAD_PLUGIN_BASENAME` | `plugin_basename( __FILE__ )` | Used to detect self-updates in `FPAD_Utils::plugin_upgrade_hook()` |
| `FPAD_PLUGIN_DIR` | `plugin_dir_path( __FILE__ )` | Filesystem path; also defined independently by the drop-in relative to `wp-content/` |
| `FPAD_PLUGIN_URL` | `plugin_dir_url( __FILE__ )` | Currently unused by code; reserved for assets |

Defined by the drop-in (`wp-content/fatal-error-handler.php`):

| Constant | Purpose |
|----------|---------|
| `ABSPATH` | Fallback definition if WP hasn't defined it yet |
| `FPAD_PLUGIN_DIR` | Computed as `dirname( __FILE__ ) . '/plugins/fatal-plugin-auto-deactivator/'` |
| `QM_DISABLE_ERROR_HANDLER` | Disables Query Monitor's error handler to avoid conflict |

Honored external constants: `WP_DEBUG` + `WP_DEBUG_DISPLAY` (together gate error detail on the public error page — detail shows only when `WP_DEBUG` is on and `WP_DEBUG_DISPLAY` is not explicitly `false`), `FPAD_SHOW_ERROR_DETAILS` (explicit override of that gate), `WP_SANDBOX_SCRAPING` (WP core; the handler returns early during plugin/theme editor syntax checks), `WP_DISABLE_FATAL_ERROR_HANDLER` (WP core; if true, the drop-in never runs).

## Database structure

No custom tables. Two rows in `wp_options`:

### `fpad_deactivated_plugins` — admin-notice queue (transient by convention)

Array of pending notices. Written by the error handler, read and **emptied** by `FPAD_Admin::display_admin_notices()` after rendering. Each entry:

```php
array(
    'plugin' => 'some-plugin/some-plugin.php',  // plugin basename
    'error'  => array(                           // raw error_get_last() array
        'type'    => E_ERROR,
        'message' => '...',
        'file'    => '/path/to/file.php',
        'line'    => 42,
    ),
    'time'   => 1717171717,                      // unix timestamp
)
```

### `fpad_deactivation_log` — permanent log

Array of log entries, **newest first**, capped at 100 entries (`array_unshift` + `array_slice`). Written by `handle()` for **every** detected fatal, whether or not a plugin was attributed. Each entry:

```php
array(
    'plugin'      => 'some-plugin/some-plugin.php', // '' when no plugin was attributed
    'plugin_name' => 'Some Plugin',          // resolved display name; '' when unattributed
    'deactivated' => true,                   // bool: was a plugin actually deactivated?
    'status'      => 'deactivated',          // deactivated|protected|log_only|unavailable|unattributed (since 1.3.0)
    'error_type'  => E_ERROR,                // int PHP error constant
    'error_msg'   => '...',
    'error_file'  => '/path/to/file.php',
    'error_line'  => 42,
    'time'        => 1717171717,             // unix timestamp of the most recent occurrence (used for wp_date display)
    'first_time'  => 1717170000,             // unix timestamp of the first occurrence (since 1.4.0)
    'count'       => 1,                      // occurrences coalesced into this entry (since 1.4.0)
    'date'        => '2025-06-01 12:34:56',  // gmdate('Y-m-d H:i:s'), UTC
    'request_uri' => '/some/page',           // sanitized REQUEST_URI, bounded to 255 chars (since 1.4.0)
    'php_version' => '8.1.0',                 // PHP_VERSION at the time of the fatal (since 1.4.0)
    'wp_version'  => '6.8',                   // $GLOBALS['wp_version'] if available (since 1.4.0)
)
```

Since 1.4.0, identical repeated fatals are **coalesced**: `add_to_deactivation_log()` fingerprints `error_type|error_file|error_line|error_msg|plugin|status` and, on a match, increments `count` and updates `time` instead of inserting a duplicate (keeping `first_time`). `error_msg` is bounded to 2000 chars. The viewer sums `count` for the summary cards and shows `×N` per row. All of this stays shutdown-safe (constants/superglobals + array logic only).

For fatals that cannot be attributed to an active plugin (theme/core/mu-plugin), `plugin`/`plugin_name` are empty and `deactivated` is `false` — the admin log page renders these as "Not identified / Logged only". Entries written before the `deactivated`/`status` fields existed lack them; the log page infers `deactivated` from whether `plugin` is non-empty, and `status` from `deactivated`.

### `fpad_settings` — user settings (since 1.3.0)

```php
array(
    'log_only'          => false,                          // bool: detect & log, never deactivate
    'protected_plugins' => array( 'woocommerce/woocommerce.php' ), // basenames never auto-deactivated
)
```

Read in the shutdown handler via the guarded `FPAD_Fatal_Error_Handler::get_settings()` and in the admin via `FPAD_Admin::get_settings()`. Written by the Settings tab (`FPAD_Admin::handle_settings_save()`). A matched plugin that is in `protected_plugins`, or any match while `log_only` is on, is attributed and logged with `status` `protected`/`log_only` but **not** deactivated.

All three options are deleted in `FPAD_Plugin_Lifecycle::uninstall()`.

## WordPress hooks used

The plugin registers no custom actions/filters of its own (nothing for third parties to hook). Hooks it attaches to:

| Hook | Callback | Purpose |
|------|----------|---------|
| `plugins_loaded` | `FPAD_Utils::load_textdomain` | i18n |
| `upgrader_process_complete` | `FPAD_Utils::plugin_upgrade_hook` | Refresh drop-in after self-update |
| `admin_init` | `FPAD_Plugin_Lifecycle::check_dropin` | Self-heal missing drop-in |
| `admin_init` | `FPAD_Admin::handle_admin_actions` | Handle the nonce-protected "Reinstall protection" and per-entry "delete" actions |
| `admin_post_fpad_export_log` | `FPAD_Admin::export_log` | Stream the log as a CSV or JSON download (nonce `fpad_export_log`) |
| `current_screen` | `FPAD_Admin::maybe_suppress_admin_notices` | On the log screen only, `remove_all_actions()` on the notice hooks to hide other plugins'/core notices |
| `admin_notices` | `FPAD_Admin::display_admin_notices` | Show + clear deactivation notices |
| `admin_notices` | `FPAD_Admin::maybe_show_protection_notice` | Warn (site-wide) when protection is not active |
| `admin_menu` | `FPAD_Admin::add_settings_page` | Register Tools → Fatal Plugin Log |
| `plugin_action_links_{basename}` | `FPAD_Admin::add_plugin_action_links` | "Settings" + "View Log" links on the Plugins screen |
| `site_status_tests` | `FPAD_Admin::register_site_health_test` | Site Health test for protection status |
| `debug_information` | `FPAD_Admin::add_debug_information` | Site Health debug section (status, settings, recent fatals) |
| `register_activation_hook` | `FPAD_Plugin_Lifecycle::activate` | Install drop-in |
| `register_deactivation_hook` | `FPAD_Plugin_Lifecycle::deactivate` | Remove drop-in |
| `register_uninstall_hook` | `FPAD_Plugin_Lifecycle::uninstall` | Remove drop-in + delete options |

The error handler itself is **not** hook-based — it is invoked by WP core's shutdown handler through the drop-in.

## Admin UI

| Surface | Location | Capability | Notes |
|---------|----------|------------|-------|
| Error notices | All wp-admin pages (`admin_notices`) | `activate_plugins` | One dismissible error notice per queued deactivation; queue cleared after display |
| Protection warning | All wp-admin pages (`admin_notices`) | `manage_options` | Shown when `FPAD_Dropin_Manager::get_status()` is not `active`; includes a nonce'd "Reinstall protection" button |
| Log page | **Tools → Fatal Plugin Log** (`tools.php?page=fpad-log`), **Log** tab | `manage_options` | Status banner + incident table (date/time + `×N`, source, plugin, status badge, file:line, message, request/PHP/WP meta, Actions) + Clear Log button |
| Settings tab | `tools.php?page=fpad-log&tab=settings` | `manage_options` | Log-only toggle + protected-plugins checklist; saved to `fpad_settings` |
| Filter bar | GET on the Log tab (`fpad_source`, `fpad_status`, `fpad_q`) | `manage_options` | Read-only filtering/search; no nonce (no state change) |
| Clear Log form | POST to the log page | `manage_options` + nonce `fpad_clear_log` (field `fpad_nonce`) | Resets `fpad_deactivation_log` to `array()` |
| Save Settings form | POST to the settings tab | `manage_options` + nonce `fpad_save_settings` (field `fpad_settings_nonce`) | Writes `fpad_settings` |
| Reinstall action | GET `?fpad_action=reinstall` | `manage_options` + nonce `fpad_reinstall` | Removes + reinstalls the drop-in, then redirects |
| Delete entry action | GET `?fpad_action=delete&key=…` | `manage_options` + nonce `fpad_delete_{key}` | Removes one entry (matched by `entry_key()`), then redirects |
| Export | `admin-post.php?action=fpad_export_log&format=csv|json` | `manage_options` + nonce `fpad_export_log` | Streams the full log as a download |
| Copy report | Per-row button (vanilla JS) | `manage_options` | Copies a plain-text bug report to the clipboard; no request |
| Action links | Plugins screen row | `manage_options` | "Settings" + "View Log" → admin page |
| Site Health | Status test + Info section | (core-gated) | Reports protection status and recent fatals |

There is no REST API and no AJAX endpoints. The only stored settings are in `fpad_settings`; all admin forms use the manual nonce-POST pattern (not the Settings API / `options.php`). On the log screen, other plugins'/core `admin_notices` are removed via `current_screen` so the page stays focused; the protection banner and `settings_errors()` feedback render inline in the page body and are unaffected.

## Class & method reference

### `FPAD_Fatal_Error_Handler` (`includes/class-fatal-error-handler.php`)

Instantiated by the drop-in; all WP calls guarded for partial-load context.

| Method | Visibility | Behavior |
|--------|------------|----------|
| `handle()` | public | Entry point called by WP core. Bails on `WP_SANDBOX_SCRAPING`. detect → resolve plugin (deactivate / attribute) → record in log (always) → render page. Swallows `Throwable` |
| `detect_error()` | protected | `error_get_last()`; returns the error array only for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR |
| `match_active_plugin( $error )` | protected | Normalized prefix match of `$error['file']` against each active plugin's directory (single-file plugins matched exactly); returns basename or `''`. No side effects |
| `maybe_deactivate_plugin( $error )` | protected | Matches, then consults settings: `log_only` or a protected plugin → attribute only; otherwise deactivate. Returns outcome array or null |
| `get_settings()` | protected | Guarded read of `fpad_settings` → `{ log_only, protected_plugins }`, with defaults |
| `get_active_plugins()` | protected | `get_option( 'active_plugins' )` with manual includes fallback |
| `deactivate_plugin( $plugin_base, $error )` | protected | `deactivate_plugins()`, `error_log()`, queue admin notice; returns outcome array (status `deactivated`) or null |
| `get_plugin_header( $plugin_base )` | protected | Resolves Name/Version from the plugin header with fallbacks |
| `build_plugin_result( $plugin_base, $error, $deactivated, $status )` | protected | Builds the outcome array (`plugin_base`, `plugin_name`, `plugin_version`, `error`, `deactivated`, `status`) |
| `store_deactivated_plugin_info( $plugin_base, $error )` | protected | Appends to the `fpad_deactivated_plugins` admin-notice queue |
| `add_to_deactivation_log( $error, $plugin_result = null )` | protected | Prepends an entry to `fpad_deactivation_log` (caps at 100) for every fatal; records plugin info + `deactivated`/`status`. Guards `get_option`/`update_option` for shutdown context |
| `display_custom_error_page( $error, $plugin_result )` | protected | Returns if `headers_sent()`; otherwise sends HTTP 500 and prints a self-contained HTML page (detail gated on `WP_DEBUG`+`WP_DEBUG_DISPLAY`/`FPAD_SHOW_ERROR_DETAILS`; source/status-aware copy), `exit` |

### `FPAD_Dropin_Manager` (`includes/class-dropin-manager.php`)

| Method | Behavior |
|--------|----------|
| `__construct()` | Sets `$dropin_path` (`WP_CONTENT_DIR . '/fatal-error-handler.php'`), `$source_path` (`includes/fatal-error-handler-dropin.php`), inits `WP_Filesystem` |
| `install_dropin()` | Bails if filesystem unavailable; regenerates source if missing, checks `wp-content` writability, `copy()`s source → drop-in, mirrors permissions. Returns bool |
| `remove_dropin()` | Deletes the drop-in only if owned (`dropin_is_ours()`) |
| `is_dropin_installed()` | File exists **and** owned |
| `get_status()` | Returns `active` / `foreign` / `missing` / `unwritable` / `no_filesystem` for admin surfacing |
| `dropin_is_ours()` / `read_dropin()` | protected | Guarded read + ownership check against `OWNERSHIP_MARKER` |
| `create_dropin_source()` | Recovery: regenerates a drop-in source matching the committed one (relative path + `QM_DISABLE_ERROR_HANDLER`) |

### `FPAD_Admin` (`includes/class-admin.php`)

Static. `init()` wires the admin hooks (notices, protection notice, menu, action links, admin_init actions, Site Health). `render_log_page()` renders the **Log** and **Settings** tabs and a protection-status banner; `handle_clear_log()`/`handle_settings_save()`/`handle_admin_actions()` process the nonce-protected forms/actions. `get_settings()` reads `fpad_settings`; `get_active_plugin_choices()` lists active plugins for the allowlist; `maybe_show_protection_notice()` warns site-wide; `site_health_test()`/`add_debug_information()` feed Site Health.

### `FPAD_Plugin_Lifecycle` (`includes/class-plugin-lifecycle.php`)

Static. `activate()` / `deactivate()` / `uninstall()` delegate to `FPAD_Dropin_Manager`; `uninstall()` also deletes `fpad_deactivated_plugins`, `fpad_deactivation_log`, and `fpad_settings`. `check_dropin()` (on `admin_init`) reinstalls when `is_dropin_installed()` is false.

### `FPAD_Utils` (`includes/class-utils.php`)

Static. `load_textdomain()` on `plugins_loaded`. `plugin_upgrade_hook( $upgrader, $options )` on `upgrader_process_complete`: when `action === 'update' && type === 'plugin'` and this plugin's basename is in `$options['plugins']`/`$options['plugin']`, removes and reinstalls the drop-in.

## Internationalization

- Text domain: `fatal-plugin-auto-deactivator`; domain path `/languages`; POT at `languages/fatal-plugin-auto-deactivator.pot`.
- The custom error page and `error_log` messages are intentionally **not** translated — they run in shutdown context where translation APIs may be unavailable.
