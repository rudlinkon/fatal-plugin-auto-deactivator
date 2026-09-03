# Technical Reference

## Constants

Defined in `fatal-plugin-auto-deactivator.php` (all wrapped in `! defined()` guards):

| Constant | Value | Purpose |
|----------|-------|---------|
| `FPAD_VERSION` | `'1.5.0'` | Plugin version (keep in sync with plugin header + readme.txt stable tag) |
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

No custom tables. Three rows in `wp_options`:

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

⚠ Two distinct md5 identities exist for log entries: `FPAD_Fatal_Error_Handler::log_fingerprint()` (`type|file|line|msg|plugin|status`, no timestamps — coalescing at write time) and `FPAD_Admin::entry_key()` (`time|first_time|type|file|line|msg` — addressing a row for per-entry delete). They hash different fields for different purposes; don't reuse one where the other is meant.

### `fpad_settings` — user settings (since 1.3.0; notification keys since 1.5.0)

```php
array(
    'log_only'              => false,                          // bool: detect & log, never deactivate
    'protected_plugins'     => array( 'woocommerce/woocommerce.php' ), // basenames never auto-deactivated
    'notify_email'          => false,                          // email alert channel enabled
    'notify_email_to'       => '',                             // comma-separated recipients; '' ⇒ admin_email at send time
    'notify_webhook'        => false,                          // webhook alert channel enabled
    'notify_webhook_url'    => '',                             // https URL (http allowed for localhost only)
    'notify_webhook_format' => 'json',                         // 'json' | 'slack'
    'notify_statuses'       => array( 'deactivated', 'protected', 'log_only', 'unavailable', 'unattributed' ), // outcomes that alert; saved-empty = deliberate "notify about nothing"
    'notify_cooldown'       => 900,                            // per-fingerprint alert cooldown, clamped 60–86400 s
)
```

Read in the shutdown handler via the guarded `FPAD_Fatal_Error_Handler::get_settings()` and in the admin via `FPAD_Admin::get_settings()` (public since 1.5.0 so `FPAD_Notifier` shares it — the two mirrors must keep identical defaults/normalization). Written by the Settings tab (`FPAD_Admin::handle_settings_save()`). A matched plugin that is in `protected_plugins`, or any match while `log_only` is on, is attributed and logged with `status` `protected`/`log_only` but **not** deactivated.

### `fpad_alert_state` — alert rate-limit state (since 1.5.0)

`array( md5-fingerprint => array( 'email' => ts, 'webhook' => ts ) )` — **per channel**, because transports become available at different points of WordPress' boot: with a single shared timestamp, a direct webhook send would suppress the queued email for the same persistently-crashing incident forever. Written by `send_alerts()` (shutdown, attempt-first — the attempt stamps the cooldown even if the transport throws) and `FPAD_Notifier::process_queue()` (on queued-item delivery); fingerprints whose newest channel timestamp is older than 7 days are pruned on write; stored with `autoload=false`. The fingerprint is the same `log_fingerprint()` identity the log uses to coalesce repeats.

### `fpad_alert_queue` — pending alerts (since 1.5.0)

Alerts whose transport function (`wp_mail`/`wp_remote_post`) did not exist at shutdown (very early fatals). Entries: `array( 'channel' => 'email'|'webhook', 'payload' => string, 'subject' => string (email only), 'fingerprint' => string, 'time' => int, 'attempts' => int (added on first delivery failure) )`. Cap 20, oldest dropped; duplicate fingerprint+channel not re-queued; `autoload=false`. **Retry policy:** fresh items (`attempts` 0/absent) are attempted by the `init` drain; failed items are retried only by the hourly cron backstop, each transport wrapped in its own try/catch so one throwing send cannot abort the batch, and items expire after 5 failed attempts or 24 h — a permanently broken transport can never hang every page load. Drained by `FPAD_Notifier::drain_queue()` on `init` (priority 20) and the hourly `fpad_notifier_drain` cron, under the `fpad_notifier_lock` transient. Supersession is judged against a **snapshot of the state as it was before the drain, per channel**: an item is skipped only when its *own* channel recorded a send newer than the item (a sibling channel's direct send, or an item delivered earlier in the same drain, never suppresses it). The final queue write **merges** in any entries appended by a concurrently crashing request during the drain. The drain cron is scheduled lazily on the settings save that enables a channel and self-healed from `activate()`/`check_dropin()` (settings survive deactivation; the cron does not).

### `fpad_watchdog_state` — protection watchdog state (since 1.5.0)

`array( 'status', 'since', 'last_alert', 'last_alert_status', 'last_recovery_alert', 'last_check', 'reclaim_attempts', 'last_reclaim', 'alerted_status' )` — read via `FPAD_Plugin_Lifecycle::get_watchdog_state()` with `isset()` defaults (no notices on partial/missing option); stored with `autoload=false`. `last_check` feeds the "Protection last verified" line and Site Health debug info. Two damping mechanisms survive recovery on purpose: the foreign-reclaim back-off decays 24 h after `last_reclaim`, and the lost-alert throttle (`last_alert` + `last_alert_status`) is not cleared when protection returns — combined with the once-per-24h `last_recovery_alert` gate, a flapping status (e.g. a competing plugin rewriting the drop-in hourly) produces at most one lost + one restored alert per day instead of hourly ping-pong.

All six options are deleted in `FPAD_Plugin_Lifecycle::uninstall()`.

## Hooks provided (for third parties)

| Hook | Type | Purpose |
|------|------|---------|
| `fpad_watchdog_interval` | filter (since 1.5.0) | Recurrence of the protection-watchdog cron event. Must be a registered cron schedule name; unknown values fall back to `'hourly'`. Applied in `FPAD_Plugin_Lifecycle::schedule_watchdog()` — the plugin's first public filter |

## WordPress hooks used

| Hook | Callback | Purpose |
|------|----------|---------|
| `plugins_loaded` | `FPAD_Utils::load_textdomain` | i18n |
| `upgrader_process_complete` | `FPAD_Utils::plugin_upgrade_hook` | Refresh drop-in after self-update |
| `init` (prio 10) | `FPAD_Plugin_Lifecycle::ensure_scheduled` | Cron self-heal reachable from front-end traffic (auto-updated unattended sites never see `admin_init`) (since 1.5.0) |
| `init` (prio 20) | `FPAD_Notifier::drain_queue` | Deliver alerts queued during early fatals; fresh items only — failure retries wait for the cron backstop (since 1.5.0) |
| `fpad_notifier_drain` (cron, hourly) | `FPAD_Notifier::drain_queue` | Drain backstop for front-end-only traffic; scheduled lazily when a channel is enabled, unscheduled when both disabled (since 1.5.0) |
| `fpad_watchdog_check` (cron, hourly) | `FPAD_Plugin_Lifecycle::watchdog_check` | Verify/heal protection; scheduled on activation + self-scheduled from `check_dropin()` (since 1.5.0) |
| `admin_init` | `FPAD_Plugin_Lifecycle::check_dropin` | Self-heal missing drop-in (foreign one only within the 24 h reclaim back-off) |
| `admin_init` | `FPAD_Admin::handle_admin_actions` | Handle the nonce-protected "Reinstall protection" and per-entry "delete" actions |
| `admin_post_fpad_export_log` | `FPAD_Admin::export_log` | Stream the log as a CSV or JSON download (nonce `fpad_export_log`) |
| `admin_post_fpad_test_alert` | `FPAD_Admin::handle_test_alert` | Send a test notification through one channel (nonce `fpad_test_alert`, since 1.5.0) |
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
| Settings tab | `tools.php?page=fpad-log&tab=settings` | `manage_options` | Log-only toggle + protected-plugins checklist + Notifications section (email/webhook channels, statuses, cooldown); saved to `fpad_settings` |
| Test notification | `admin-post.php?action=fpad_test_alert&channel=email\|webhook` | `manage_options` + nonce `fpad_test_alert` | Blocking test send; outcome (incl. HTTP code on webhook failure) surfaced via redirect + `settings_errors` |
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
| `handle()` | public | Entry point called by WP core. Bails on `WP_SANDBOX_SCRAPING`. detect → resolve plugin (deactivate / attribute) → record in log (always) → render page → alert → `exit`. Swallows `Throwable`, and each of the four inner steps has its own Throwable guard so one failure cannot cost the rest |
| `detect_error()` | protected | `error_get_last()`; returns the error array only for E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR |
| `match_active_plugin( $error )` | protected | Normalized prefix match of `$error['file']` against each active plugin's directory (single-file plugins matched exactly, symlinks resolved); returns basename or `''`. No side effects. Returns `''` immediately when `WP_PLUGIN_DIR` is undefined (fatal before `wp_plugin_directory_constants()`; reading it would throw on PHP 8). When the prefix pass finds nothing *and* the error file resolved outside `WP_PLUGIN_DIR`, a second pass runs `matches_symlinked_child()` on each active plugin's folder, catching a symlink *inside* a plugin (a shared `vendor/`) |
| `path_variants( $path )` | public static | Normalized path plus its `realpath()` counterpart when they differ (symlink support) |
| `path_is_inside( $files, $dir )` | public static | Whether any path variant of a file sits under `$dir` (both spellings) |
| `matches_symlinked_child( $files, $root, $depth = 1 )` | public static | Whether a file **is**, or lives inside, a symlinked child of `$root` (individually symlinked plugins/themes resolve outside `wp-content`; the child may be a directory or a single-file plugin). `$depth > 1` descends into *real* subdirectories only — symlinked ones are compared, never followed, so link loops cannot trap the scan. `detect_error_source()` and its admin mirror pass `2`; each level costs one `scandir()` per directory, so keep it small (~2.7 ms per unattributed fatal on a 32-plugin site) |
| `maybe_deactivate_plugin( $error )` | protected | Matches, then consults settings: `log_only` or a protected plugin → attribute only; otherwise deactivate. Returns outcome array or null |
| `get_settings()` | protected | Guarded read of `fpad_settings` → `{ log_only, protected_plugins }`, with defaults |
| `get_active_plugins()` | protected | `get_option( 'active_plugins' )` with manual includes fallback; returns `array()` when `get_option()` still does not exist (fatal before `functions.php` → `option.php`) or the option is not an array |
| `deactivate_plugin( $plugin_base, $error )` | protected | `deactivate_plugins()`, `error_log()`, queue admin notice; returns outcome array (status `deactivated`) or null |
| `get_plugin_header( $plugin_base )` | protected | Resolves Name/Version from the plugin header with fallbacks |
| `build_plugin_result( $plugin_base, $error, $deactivated, $status )` | protected | Builds the outcome array (`plugin_base`, `plugin_name`, `plugin_version`, `error`, `deactivated`, `status`) |
| `store_deactivated_plugin_info( $plugin_base, $error )` | protected | Appends to the `fpad_deactivated_plugins` admin-notice queue |
| `add_to_deactivation_log( $error, $plugin_result = null )` | protected | Prepends an entry to `fpad_deactivation_log` (caps at 100) for every fatal; records plugin info + `deactivated`/`status`. Guards `get_option`/`update_option` for shutdown context |
| `repair_options_api()` | protected | (1.5.0) Restores a usable options API when the fatal is what broke it: replays core's own includes, rebuilds `$wpdb` from the `DB_*` constants when `db.php` crashed, and loads `wp-includes/cache.php` when `object-cache.php` crashed. Only acts when `options_api_works()` is false, so a healthy request does nothing |
| `options_api_works()` | protected | (1.5.0) Performs a real `get_option()` read in a try/catch — `function_exists()` is true in exactly the broken case this guards |
| `can_load_core_cache()` | protected | (1.5.0) Whether `wp-includes/cache.php` can be included without a **non-catchable** "Cannot redeclare" fatal; reads the symbol list out of the installed file rather than hard-coding it |
| `wp_call( $function, $args, $fallback )` | protected | Calls a WP function only when it exists **and** does not throw; returns `$fallback` otherwise. Needed because a fatal in `wp_start_object_cache()` leaves `wp_cache_get()` undefined, so the whole options API — and `esc_html()`/`sanitize_text_field()`/`get_bloginfo()` through it — exists but throws |
| `esc( $text )` / `esc_link( $url )` | protected | Pure-PHP escaping for the error page (`htmlspecialchars( …, ENT_QUOTES, 'UTF-8' )`), deliberately not `esc_html()`/`esc_url()`: those reach `get_option( 'blog_charset' )` and can throw mid-crash. `esc_link()` emits only an absolute path or an explicit `http(s)://` URL, so no `javascript:`/`data:`/protocol-relative value can reach an `href` |
| `display_custom_error_page( $error, $plugin_result )` | protected | Clears all output buffers first (discarding PHP's own `display_errors` dump so the visitor gets this page and the status code is still settable), sends HTTP 500 when headers are not yet sent, prints a self-contained HTML page (detail gated on `WP_DEBUG`+`WP_DEBUG_DISPLAY`/`FPAD_SHOW_ERROR_DETAILS`; source/status-aware copy), **flushes all output buffers to the client, and returns `true` — the `exit` happens in `handle()` after the alert sends** (since 1.5.0) |
| `maybe_notify( $error, $plugin_result )` | protected | (1.5.0) Thin Throwable-guard wrapper around `send_alerts()`, called AFTER the page is rendered+flushed so transports can never delay the visitor and an alert failure cannot skip `handle()`'s exit |
| `send_alerts( $error, $plugin_result )` | protected | (1.5.0) Channel/status gates → **per-channel** fingerprint cooldown (`fpad_alert_state`) → direct guarded send (each transport in its own try/catch, attempt-first stamping) or queue fallback. Untranslated strings |
| `webhook_request_args( $url, $body )` | protected | (1.5.0) Hardened POST args: non-blocking, timeout 3, `redirection 0`, `reject_unsafe_urls` for non-loopback hosts (sync pair with `FPAD_Notifier::webhook_args()`) |
| `queue_alert( … )` | protected | (1.5.0) Append to `fpad_alert_queue` when a transport is unavailable (cap 20, fingerprint+channel dedupe) |
| `build_alert_subject()` / `build_alert_email_body()` | protected | (1.5.0) Email content; body field order mirrors `FPAD_Admin::build_report()` (sync pair) + `Log:` link |
| `build_webhook_json_payload()` / `build_webhook_slack_payload()` | protected | (1.5.0) FR-4 payloads; Slack severity 🟠 self-healed / 🔴 still broken |
| `status_verb()` / `error_type_name()` / `alert_recipients()` / `alert_site_url()` | protected | (1.5.0) Alert helpers; `alert_site_url()` falls back to a sanitized `HTTP_HOST` when `home_url()` is unavailable |

### `FPAD_Dropin_Manager` (`includes/class-dropin-manager.php`)

| Method | Behavior |
|--------|----------|
| `__construct()` | Sets `$dropin_path` (`WP_CONTENT_DIR . '/fatal-error-handler.php'`), `$source_path` (`includes/fatal-error-handler-dropin.php`), inits `WP_Filesystem` |
| `install_dropin()` | Bails if filesystem unavailable; regenerates source if missing, checks `wp-content` writability, `copy()`s source → drop-in, mirrors permissions. Returns bool |
| `remove_dropin()` | Deletes the drop-in only if owned (`dropin_is_ours()`) |
| `is_dropin_installed()` | File exists **and** owned |
| `get_status()` | Returns `active` / `foreign` / `missing` / `unwritable` / `no_filesystem` for admin surfacing |
| `refresh_if_stale()` | (1.5.0) Reinstalls our own drop-in when its content lacks `DROPIN_VERSION_TOKEN` (`fpad-dropin-version: 3`) — covers git/rsync/Composer deploys that never fire the upgrader hook; called from `check_dropin()` and `watchdog_check()`. Bump the token (tracked source + generator heredoc, sync pair) whenever drop-in contents change |
| `verify_protection()` | (1.5.0) End-to-end check: `array( 'status', 'detail' )`. Adds `disabled` (`WP_DISABLE_FATAL_ERROR_HANDLER` truthy, checked first) and `stranded` (drop-in ours but its require target — computed relative to `WP_CONTENT_DIR`, the way the drop-in computes it, **not** via `FPAD_PLUGIN_DIR` — is not readable); legacy statuses pass through. All admin surfaces + the watchdog use this |
| `dropin_is_ours()` / `read_dropin()` | (protected) Guarded read + ownership check against `OWNERSHIP_MARKER` |
| `create_dropin_source()` | Recovery: regenerates a drop-in source matching the committed one (relative path + `QM_DISABLE_ERROR_HANDLER`) |

### `FPAD_Admin` (`includes/class-admin.php`)

All static. Public methods are hook callbacks; private methods are internal helpers.

Hooked entry points:

| Method | Hook | Behavior |
|--------|------|----------|
| `init()` | — (called from bootstrap) | Registers every hook below |
| `handle_admin_actions()` | `admin_init` | Processes GET actions `fpad_action=reinstall` (nonce `fpad_reinstall`) and `fpad_action=delete&key=…` (nonce `fpad_delete_{key}`); redirects with a feedback query arg |
| `display_admin_notices()` | `admin_notices` | Renders one error notice per queued entry in `fpad_deactivated_plugins`, then empties the queue (cap `activate_plugins`) |
| `maybe_show_protection_notice()` | `admin_notices` | Site-wide warning + Reinstall button when `get_protection_state()` ≠ `active` |
| `add_settings_page()` | `admin_menu` | Registers **Tools → Fatal Plugin Log** (`fpad-log`, cap `manage_options`) |
| `add_plugin_action_links()` | `plugin_action_links_{basename}` | Prepends "Settings" and "View Log" links |
| `render_log_page()` | (page callback) | Runs `handle_settings_save()` + `handle_clear_log()`, surfaces redirect feedback, renders banner + tabs |
| `export_log()` | `admin_post_fpad_export_log` | Streams full log as CSV (`csv_safe()`-escaped) or JSON; cap + nonce `fpad_export_log`; `exit` |
| `handle_test_alert()` | `admin_post_fpad_test_alert` | (1.5.0) Cap + nonce, `FPAD_Notifier::send_test( $channel )`, redirect with `fpad_test`/`fpad_test_detail` query args |
| `maybe_suppress_admin_notices()` | `current_screen` | On `tools_page_fpad-log` only: `remove_all_actions()` on the notice hooks |
| `register_site_health_test()` / `site_health_test()` | `site_status_tests` | Direct test `fpad_protection`: `good` when active, else `critical` |
| `add_debug_information()` | `debug_information` | `fpad` section: version, protection status, settings, log stats |

Internal helpers (private):

| Method | Behavior |
|--------|----------|
| `render_log_tab()` | Clear-Log form, export links, filter bar, summary, table — or empty-state notices |
| `render_log_summary( $log )` | Summary cards; sums `count` per entry so coalesced repeats are counted as occurrences |
| `render_log_table( $log )` | Two `<tr>` per incident (data row + message/meta row), inline CSS, per-row Copy/Delete actions, inline JS for clipboard |
| `render_filter_bar( $source, $status, $query )` | GET form with `fpad_source`/`fpad_status`/`fpad_q` (read-only → deliberately no nonce) |
| `filter_log( $log, $source, $status, $query )` | Applies source/status filters + case-insensitive substring search over plugin name/basename/message/file |
| `render_settings_tab()` / `handle_settings_save()` | Log-only checkbox + protected-plugins checklist; save validates submissions against currently active plugins (nonce `fpad_save_settings`) |
| `handle_clear_log()` | Resets `fpad_deactivation_log` to `array()` (nonce `fpad_clear_log`, field `fpad_nonce`) |
| `get_settings()` | **Public since 1.5.0** (`FPAD_Notifier` reads through it). Reads `fpad_settings` with defaults incl. the notification keys — mirror of the handler's guarded version (sync pair) |
| `last_watchdog_check()` | (1.5.0) Timestamp of the watchdog's last run from `fpad_watchdog_state`, 0 when never; feeds the banner line + debug info |
| `get_active_plugin_choices()` | `active_plugins` → sorted `basename => display name` map |
| `source_key( $file )` / `source_label()` / `source_labels()` | Re-classify a stored error path — mirror of `FPAD_Fatal_Error_Handler::detect_error_source()` |
| `source_icon( $source_key )` / `unattributed_copy( $source_key )` | Card mark icon, and the headline + recovery note shown when an incident matched no plugin |
| `entry_status( $entry )` | Canonical status, inferring `deactivated`/`logged`/`unattributed` for pre-1.3.0 entries lacking `status` |
| `status_badge( $status )` / `get_error_type_string( $type )` | Badge HTML / E_* constant → label |
| `get_protection_state()` / `protection_message()` / `reinstall_url()` / `render_protection_banner()` | Drop-in status surfacing + nonce'd reinstall link |
| `entry_key( $entry )` | md5 of `time|first_time|error_type|error_file|error_line|error_msg` — row identity for per-entry delete |
| `csv_safe( $value )` | Prefixes `'` to leading `= + - @ \t \r` against spreadsheet formula injection |
| `build_report( $entry )` | Plain-text bug report for the clipboard button (intentionally untranslated) |

### `FPAD_Plugin_Lifecycle` (`includes/class-plugin-lifecycle.php`)

Static. `activate()` installs the drop-in + schedules the watchdog; `deactivate()` removes the drop-in + clears both cron events; `uninstall()` additionally deletes all six `fpad_*` options. `check_dropin()` (on `admin_init`) reinstalls when `is_dropin_installed()` is false — gated by `may_reclaim_dropin()`, so a *foreign* drop-in is reclaimed at most once per 24 h using the same `fpad_watchdog_state` back-off the watchdog uses (otherwise every wp-admin page load would steal the slot back and the "two plugins never fight" rule would hold only on sites nobody logs into; the banner's manual "Reinstall protection" action deliberately ignores the back-off) — and self-schedules the watchdog (upgrade path).

Watchdog methods (since 1.5.0):

| Method | Behavior |
|--------|----------|
| `schedule_watchdog()` | protected; schedules `fpad_watchdog_check` if absent, interval via the `fpad_watchdog_interval` filter validated against `wp_get_schedules()` |
| `watchdog_check()` | Cron callback: `verify_protection()` → heal `missing`/`foreign` (foreign reclaimed max once per 24 h) → re-verify → recovery handling or incident + alert (once per 24 h per distinct status). Persists `fpad_watchdog_state`; skips the write when only a <10-min-old heartbeat changed |
| `get_watchdog_state()` | protected; normalized state read with per-field defaults |
| `send_watchdog_alert( $event, $status, $detail )` | protected; delegates to `FPAD_Notifier::dispatch_event()` (severity good/bad + translated subject via `$data['subject']`); the notifier owns the no-channel `wp_mail` fallback and the log-page link |
| `describe_status( $status )` | protected; alert-body wording — **mirrors the private `FPAD_Admin::protection_message()` (sync pair)** |

### `FPAD_Notifier` (`includes/class-notifier.php`, since 1.5.0)

Static; normal-request side of alerting (the shutdown handler sends directly when transports exist and queues otherwise).

| Method | Visibility | Behavior |
|--------|------------|----------|
| `init()` | public | Hooks `drain_queue` to `init` (prio 20, after pluggables settle) and the `fpad_notifier_drain` cron |
| `drain_queue()` | public | Bails on empty queue (before touching the lock — zero cost when idle); `fpad_notifier_lock` transient (60 s) against concurrent drains; Throwable-guarded |
| `send_test( $channel )` | public | Blocking test send → `array( 'success', 'detail' )`; webhook detail carries `HTTP {code}` or the WP_Error message |
| `dispatch_event( $event, $message, $data )` | public | Generic entry point for other components (watchdog events `fpad.protection_lost`/`_restored`). Sends through every enabled channel; `$data['severity']` `bad`/`good` picks 🔴/🟢 for Slack; `$data['subject']` overrides the email subject (stripped from webhook payloads); **falls back to `wp_mail( admin_email )` when no channel is configured**. Appends the log-page link to every body — callers must not add it themselves. Never throws |
| `maybe_schedule_drain()` | public | Schedules the hourly drain cron when a usable channel is enabled and it isn't scheduled; called from settings save, `activate()`, and `check_dropin()` |
| `process_queue( $queue )` | private | Per-item: skip only when the item's own channel has a state send newer than the item (snapshot-based, per-channel supersession); send respecting current channel config (disabled channel ⇒ item dropped); failed transport ⇒ item retained for the cron backstop; merge-on-write preserves concurrently queued entries; updates + prunes `fpad_alert_state` |
| `webhook_args( $url, $body, $blocking, $timeout )` | private | Hardened POST args (no redirects; `reject_unsafe_urls` except loopback) — sync pair with the handler's `webhook_request_args()` |
| `recipients()` / `event_label()` / `queue_item_key()` | private | Recipient resolution (configured list or `admin_email`); event-name → fallback subject label; queue-entry identity for merge-on-write |

### `FPAD_Utils` (`includes/class-utils.php`)

Static. `load_textdomain()` on `plugins_loaded`. `plugin_upgrade_hook( $upgrader, $options )` on `upgrader_process_complete`: when `action === 'update' && type === 'plugin'` and this plugin's basename is in `$options['plugins']`/`$options['plugin']`, removes and reinstalls the drop-in.

## Internationalization

- Text domain: `fatal-plugin-auto-deactivator`; domain path `/languages`; POT at `languages/fatal-plugin-auto-deactivator.pot`.
- The custom error page and `error_log` messages are intentionally **not** translated — they run in shutdown context where translation APIs may be unavailable.
