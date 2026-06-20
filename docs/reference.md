# Technical Reference

## Constants

Defined in `fatal-plugin-auto-deactivator.php` (all wrapped in `! defined()` guards):

| Constant | Value | Purpose |
|----------|-------|---------|
| `FPAD_VERSION` | `'1.1.0'` | Plugin version (keep in sync with plugin header + readme.txt stable tag) |
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
    'error_type'  => E_ERROR,                // int PHP error constant
    'error_msg'   => '...',
    'error_file'  => '/path/to/file.php',
    'error_line'  => 42,
    'time'        => 1717171717,             // unix timestamp (used for wp_date display)
    'date'        => '2025-06-01 12:34:56',  // gmdate('Y-m-d H:i:s'), UTC
)
```

For fatals that cannot be attributed to an active plugin (theme/core/mu-plugin), `plugin`/`plugin_name` are empty and `deactivated` is `false` — the admin log page renders these as "Not identified / Logged only". Entries written before this field existed lack `deactivated`; the log page infers it from whether `plugin` is non-empty.

Both options are deleted in `FPAD_Plugin_Lifecycle::uninstall()`.

## WordPress hooks used

The plugin registers no custom actions/filters of its own (nothing for third parties to hook). Hooks it attaches to:

| Hook | Callback | Purpose |
|------|----------|---------|
| `plugins_loaded` | `FPAD_Utils::load_textdomain` | i18n |
| `upgrader_process_complete` | `FPAD_Utils::plugin_upgrade_hook` | Refresh drop-in after self-update |
| `admin_init` | `FPAD_Plugin_Lifecycle::check_dropin` | Self-heal missing drop-in |
| `admin_notices` | `FPAD_Admin::display_admin_notices` | Show + clear deactivation notices |
| `admin_menu` | `FPAD_Admin::add_settings_page` | Register Tools → Fatal Plugin Log |
| `plugin_action_links_fatal-plugin-auto-deactivator/fatal-plugin-auto-deactivator.php` | `FPAD_Admin::add_plugin_action_links` | "View Log" link on the Plugins screen |
| `register_activation_hook` | `FPAD_Plugin_Lifecycle::activate` | Install drop-in |
| `register_deactivation_hook` | `FPAD_Plugin_Lifecycle::deactivate` | Remove drop-in |
| `register_uninstall_hook` | `FPAD_Plugin_Lifecycle::uninstall` | Remove drop-in + delete options |

The error handler itself is **not** hook-based — it is invoked by WP core's shutdown handler through the drop-in.

## Admin UI

| Surface | Location | Capability | Notes |
|---------|----------|------------|-------|
| Error notices | All wp-admin pages (`admin_notices`) | `activate_plugins` | One dismissible error notice per queued deactivation; queue cleared after display |
| Log page | **Tools → Fatal Plugin Log** (`tools.php?page=fpad-log`) | `manage_options` | Table of incidents (date/time, plugin or "Not identified", file:line, type, message, deactivation status) + Clear Log button |
| Clear Log form | POST to the log page | `manage_options` + nonce `fpad_clear_log` (field `fpad_nonce`) | Resets `fpad_deactivation_log` to `array()` |
| Action link | Plugins screen row | `manage_options` | "View Log" → log page |

There is no REST API, no AJAX endpoints, no front-end assets, and no settings to store — the only admin interaction is the log page form above.

## Class & method reference

### `FPAD_Fatal_Error_Handler` (`includes/class-fatal-error-handler.php`)

Instantiated by the drop-in; all WP calls guarded for partial-load context.

| Method | Visibility | Behavior |
|--------|------------|----------|
| `handle()` | public | Entry point called by WP core. detect → deactivate (if matched) → record in log (always) → render page. Swallows `Throwable` |
| `detect_error()` | protected | `error_get_last()`; returns the error array only for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR |
| `maybe_deactivate_plugin( $error )` | protected | Prefix-matches `$error['file']` against each active plugin's directory; first match deactivated. Returns info array or null |
| `get_active_plugins()` | protected | `get_option( 'active_plugins' )` with manual includes fallback |
| `deactivate_plugin( $plugin_base, $error )` | protected | `deactivate_plugins()`, `error_log()`, queue admin notice; returns info array or null |
| `store_deactivated_plugin_info( $plugin_base, $error )` | protected | Appends to the `fpad_deactivated_plugins` admin-notice queue |
| `add_to_deactivation_log( $error, $deactivated_plugin = null )` | protected | Prepends an entry to `fpad_deactivation_log` (caps at 100) for every fatal; records plugin info + `deactivated` flag when attributed. Guards `get_option`/`update_option` for shutdown context |
| `display_custom_error_page( $error, $deactivated_plugin )` | protected | Sends HTTP 500, prints self-contained HTML page (detail only if `WP_DEBUG`), `exit` |

### `FPAD_Dropin_Manager` (`includes/class-dropin-manager.php`)

| Method | Behavior |
|--------|----------|
| `__construct()` | Sets `$dropin_path` (`WP_CONTENT_DIR . '/fatal-error-handler.php'`), `$source_path` (`includes/fatal-error-handler-dropin.php`), inits `WP_Filesystem` |
| `install_dropin()` | Regenerates source if missing, checks `wp-content` writability, `copy()`s source → drop-in, mirrors permissions |
| `remove_dropin()` | Deletes the drop-in only if its content contains `FPAD_Fatal_Error_Handler` (ownership check) |
| `is_dropin_installed()` | File exists **and** contains the ownership marker |
| `create_dropin_source()` | Recovery: regenerates the drop-in source with an absolute embedded plugin path |

### `FPAD_Admin` (`includes/class-admin.php`)

Static. `init()` wires the three admin hooks. `render_log_page()` handles the nonce-checked clear action and renders the log table; `get_error_type_string()` maps error constants to labels.

### `FPAD_Plugin_Lifecycle` (`includes/class-plugin-lifecycle.php`)

Static. `activate()` / `deactivate()` / `uninstall()` delegate to `FPAD_Dropin_Manager`; `uninstall()` also deletes both options. `check_dropin()` (on `admin_init`) reinstalls when `is_dropin_installed()` is false.

### `FPAD_Utils` (`includes/class-utils.php`)

Static. `load_textdomain()` on `plugins_loaded`. `plugin_upgrade_hook( $upgrader, $options )` on `upgrader_process_complete`: when `action === 'update' && type === 'plugin'` and this plugin's basename is in `$options['plugins']`/`$options['plugin']`, removes and reinstalls the drop-in.

## Internationalization

- Text domain: `fatal-plugin-auto-deactivator`; domain path `/languages`; POT at `languages/fatal-plugin-auto-deactivator.pot`.
- The custom error page and `error_log` messages are intentionally **not** translated — they run in shutdown context where translation APIs may be unavailable.
