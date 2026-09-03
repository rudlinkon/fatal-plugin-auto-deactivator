# Architecture

## Overview

The plugin has two distinct halves running in two very different execution contexts:

1. **The normal plugin** (`fatal-plugin-auto-deactivator.php` + admin/lifecycle/utils classes) — loaded by WordPress like any plugin. Manages the drop-in file, admin UI, notices, and the log page.
2. **The fatal error handler** (`includes/class-fatal-error-handler.php`, loaded via the drop-in) — runs during PHP shutdown after a fatal error, when WordPress may be only partially bootstrapped and the crashing plugin has already taken the request down.

```
wp-content/
├── fatal-error-handler.php          ← drop-in (copy, managed by the plugin)
└── plugins/fatal-plugin-auto-deactivator/
    ├── fatal-plugin-auto-deactivator.php   ← bootstrap: constants, requires, init, lifecycle hooks
    └── includes/
        ├── fatal-error-handler-dropin.php  ← drop-in SOURCE (copied to wp-content/)
        ├── class-fatal-error-handler.php   ← shutdown-context error handler
        ├── class-dropin-manager.php        ← install/remove/verify the drop-in copy
        ├── class-admin.php                 ← notices, log page, settings, action links
        ├── class-admin-ui.php              ← admin markup vocabulary: icons, buttons, badges, panels
        ├── class-notifier.php              ← alert delivery on normal requests (1.5.0)
        ├── class-plugin-lifecycle.php      ← activate/deactivate/uninstall + admin_init check + watchdog
        └── class-utils.php                 ← textdomain, self-update drop-in refresh
    └── assets/
        ├── src/admin.css                   ← Tailwind source (dev only, not shipped)
        ├── css/admin.css                   ← built admin stylesheet (committed + shipped)
        ├── css/notice.css                  ← hand-written admin-notice styles
        └── js/admin.js                     ← admin behaviours (copy, confirm, filter, switch captions)
```

## Entry points and initialization

### Normal request (plugin loaded by WordPress)

`fatal-plugin-auto-deactivator.php` is the only file WordPress loads directly. It runs top-to-bottom at plugin load:

1. Direct-access guard (`WPINC`), then constants: `FPAD_VERSION`, `FPAD_PLUGIN_BASENAME`, `FPAD_PLUGIN_DIR`, `FPAD_PLUGIN_URL`.
2. `require_once` of the five class files in `includes/` (no autoloader).
3. `FPAD_Utils::init()`, `FPAD_Admin::init()`, `FPAD_Notifier::init()`, `FPAD_Plugin_Lifecycle::init()` — these only register hooks (see the [hook table in reference.md](reference.md#wordpress-hooks-used)); nothing executes until the hooks fire.
4. `register_activation_hook` / `register_deactivation_hook` / `register_uninstall_hook` → `FPAD_Plugin_Lifecycle`.

`FPAD_Dropin_Manager` and `FPAD_Fatal_Error_Handler` register nothing — they are instantiated on demand.

### Fatal request (the crash path)

No hooks involved: WP core's shutdown handler loads `wp-content/fatal-error-handler.php` (the drop-in), which requires `class-fatal-error-handler.php` and returns `new FPAD_Fatal_Error_Handler()`; core calls `handle()` on it. This works even when the plugin itself never loaded on that request.

### All runtime entry points (exhaustive)

| Entry point | Trigger | Handler |
|-------------|---------|---------|
| Plugin bootstrap | Every normal request | `fatal-plugin-auto-deactivator.php` |
| Fatal error handling | WP core shutdown handler after a fatal | Drop-in → `FPAD_Fatal_Error_Handler::handle()` |
| Log/Settings page render | `tools.php?page=fpad-log` (+ `&tab=settings`) | `FPAD_Admin::render_log_page()` |
| Clear log / save settings | POST to that page (processed at the top of the render) | `FPAD_Admin::handle_clear_log()` / `handle_settings_save()` |
| Reinstall protection / delete log entry | GET `?fpad_action=reinstall` / `?fpad_action=delete&key=…` on `admin_init`, then redirect | `FPAD_Admin::handle_admin_actions()` |
| Log export | `admin-post.php?action=fpad_export_log&format=csv\|json` | `FPAD_Admin::export_log()` |
| Activation / deactivation / uninstall | Plugin lifecycle | `FPAD_Plugin_Lifecycle::activate()` / `deactivate()` / `uninstall()` |
| Drop-in self-heal | Every `admin_init` | `FPAD_Plugin_Lifecycle::check_dropin()` |
| Self-update drop-in refresh | `upgrader_process_complete` | `FPAD_Utils::plugin_upgrade_hook()` |
| Test notification | `admin-post.php?action=fpad_test_alert&channel=…` | `FPAD_Admin::handle_test_alert()` |
| Alert-queue drain | `init` (prio 20) + hourly cron `fpad_notifier_drain` (lazily scheduled while a channel is enabled) | `FPAD_Notifier::drain_queue()` |
| Protection watchdog | Hourly cron `fpad_watchdog_check` (interval filterable via `fpad_watchdog_interval`) | `FPAD_Plugin_Lifecycle::watchdog_check()` |

There are no REST routes, AJAX handlers, shortcodes, or blocks.

## How the two halves communicate

The shutdown handler and the normal plugin never call each other. They share exactly two things: the drop-in file (deployed by the manager, consumed by core) and three `wp_options` rows:

| Option | Written by | Read by | Emptied/deleted by |
|--------|-----------|---------|--------------------|
| `fpad_deactivation_log` (permanent log) | `FPAD_Fatal_Error_Handler::add_to_deactivation_log()` — every fatal | `FPAD_Admin` log tab, export, Site Health debug info | Clear Log, per-entry delete, uninstall |
| `fpad_deactivated_plugins` (notice queue) | `FPAD_Fatal_Error_Handler::store_deactivated_plugin_info()` — only on actual deactivation | `FPAD_Admin::display_admin_notices()` | Cleared immediately after notices display; uninstall |
| `fpad_settings` (user settings) | `FPAD_Admin::handle_settings_save()` | `FPAD_Fatal_Error_Handler::get_settings()` (guarded) + `FPAD_Admin::get_settings()` (also used by `FPAD_Notifier`) | Uninstall |
| `fpad_alert_state` (alert cooldowns) | `FPAD_Fatal_Error_Handler::maybe_notify()` + `FPAD_Notifier::process_queue()` | Both writers | 7-day prune on write; uninstall |
| `fpad_alert_queue` (pending alerts) | `FPAD_Fatal_Error_Handler::queue_alert()` — only when a transport is missing at shutdown | `FPAD_Notifier::drain_queue()` (then emptied) | Drain; uninstall |
| `fpad_watchdog_state` (watchdog incidents/heartbeat) | `FPAD_Plugin_Lifecycle::watchdog_check()` | Same + `FPAD_Admin::last_watchdog_check()` | Uninstall |

This one-way data flow (settings flow admin → handler; incidents flow handler → admin) is why the handler duplicates small helpers like `get_settings()` instead of reusing admin code: the shutdown context cannot depend on any other class being loadable. Full schemas: [reference.md](reference.md#database-structure).

## The drop-in mechanism

WordPress (since 5.2) supports a `fatal-error-handler.php` **drop-in**: if that file exists in `wp-content/`, core's shutdown handler (registered very early in `wp-includes/load.php`, before plugins load) includes it and, if it returns an object with a `handle()` method, uses that object instead of the default `WP_Fatal_Error_Handler`. This is the most reliable hook point available — it fires even when a plugin fatals during its own loading.

The drop-in source (`includes/fatal-error-handler-dropin.php`) is deliberately tiny:

- Defines `ABSPATH` and `FPAD_PLUGIN_DIR` **relative to its own location** (`wp-content/`), so the copy works regardless of install path.
- Defines `QM_DISABLE_ERROR_HANDLER` to prevent Query Monitor's error handler from conflicting with ours.
- Reserves a 256 KB buffer in `$GLOBALS['fpad_reserved_memory']` (since 1.5.0) which `handle()` frees as its first statement — headroom so logging and alerts survive out-of-memory fatals.
- Calls `ob_start()` (since 1.5.0) **when PHP is set to display errors** (`WP_DEBUG_DISPLAY`, or php.ini `display_errors`). PHP prints the fatal itself before any shutdown handler runs; once that output reaches the client the response is pinned to HTTP 200 and the error page can only be appended to a raw stack trace. Owning a buffer from the earliest point WordPress offers lets `display_custom_error_page()` discard it and send a real 500. Skipped entirely when errors are not displayed, so the common production configuration is unbuffered as before. **Sync pair** with the generator heredoc in `class-dropin-manager.php`; both carry `fpad-dropin-version: 3`.
- Requires `includes/class-fatal-error-handler.php` from the plugin directory and returns `new FPAD_Fatal_Error_Handler()`.

Note: `FPAD_Dropin_Manager::create_dropin_source()` can regenerate the source file if the tracked one is missing. The generated content is kept functionally identical to the tracked source — paths derived relative to the drop-in's own location, `QM_DISABLE_ERROR_HANDLER` defined — only the header comment differs. The tracked file is what normally ships; the generator is a recovery path only. **When editing either, update the other** (see the sync-pairs table in [feature-map.md](feature-map.md#things-that-must-change-together-sync-pairs)).

## Fatal error flow

```
Fatal error in any active plugin
        │
        ▼
PHP shutdown → WP core shutdown handler
        │
        ▼
wp-content/fatal-error-handler.php (drop-in)
        │  requires class, returns FPAD_Fatal_Error_Handler
        ▼
handle()
 ├── bail early on WP_SANDBOX_SCRAPING (core's plugin/theme editor loopback check)
 ├── detect_error()            error_get_last(); only E_ERROR, E_PARSE, E_CORE_ERROR,
 │                             E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR qualify
 ├── maybe_deactivate_plugin() match error['file'] against WP_PLUGIN_DIR/<plugin dir>
 │    ├── fpad_settings check: log_only mode or protected plugin → attribute only,
 │    │                        status log_only / protected (no deactivation)
 │    └── deactivate_plugin()  deactivate_plugins(); error_log(); queue admin notice:
 │         │                   (status unavailable if deactivate_plugins() can't load)
 │         └── fpad_deactivated_plugins  (admin-notice queue)
 ├── add_to_deactivation_log() ALWAYS — write the fatal to fpad_deactivation_log
 │                             (permanent, capped at 100, identical repeats coalesced
 │                             with a count); records the plugin + status if one was
 │                             attributed, else an "unattributed" entry
 ├── display_custom_error_page()  HTTP 500, inline-styled HTML page, then FLUSHED to
 │                             the client (skipped entirely if headers already sent)
 ├── maybe_notify()            best-effort email/webhook alerts (1.5.0), deliberately
 │                             AFTER the page flush so a hung transport can never
 │                             delay the visitor; own Throwable guard; per-channel
 │                             fingerprint cooldown; queues when transports missing
 └── exit                      (only when the page was rendered)
```

Key behaviors:

- **Culprit identification is a file-path prefix match**, not stack-trace analysis. The directory of each active plugin's basename is compared against the start of `error['file']` (paths normalized for Windows; single-file plugins matched exactly). Both the logical path and its `realpath()` are compared on each side, so **symlinked plugin directories** still match — PHP reports the resolved path in `error_get_last()`. When that pass finds nothing and the error file resolved outside `WP_PLUGIN_DIR`, a second pass scans each active plugin folder for symlinked children, so a fatal inside a **symlinked `vendor/` directory** is still attributed. First match wins; loop breaks.
- Errors originating in **mu-plugins, themes, drop-ins, or core** match no active plugin: nothing is deactivated, but the fatal is still recorded in the log (as a "logged only" entry) and the custom error page still renders.
- **Every detected fatal is recorded** in the `fpad_deactivation_log` option by `add_to_deactivation_log()`, called unconditionally from `handle()` after the deactivation attempt — so the admin-visible log (Tools → Fatal Plugin Log) captures every fatal regardless of `WP_DEBUG` and regardless of plugin attribution. Entries carry `deactivated` and `status` fields, plus (since 1.4.0) `count`/`first_time` for coalesced repeats and `request_uri`/`php_version`/`wp_version` context. (The pre-existing `error_log()` in `deactivate_plugin()` still records the deactivation action to the server log when a plugin is matched.)
- Error detail (message, file, line, deactivated plugin name) is shown on the public error page **only when `WP_DEBUG` is on and `WP_DEBUG_DISPLAY` is not explicitly `false`** (since 1.2.1; see `display_custom_error_page()`); the `FPAD_SHOW_ERROR_DETAILS` constant overrides the gate in either direction. Otherwise visitors get a generic message. Display gating never affects logging.
- `handle()` wraps everything in `try/catch (Throwable)` and stays silent on failure, so the handler itself can never produce a secondary crash. Since 1.5.0 the four inner steps (attribute, log, render, alert) each carry their **own** Throwable guard as well, so a throw in one — `deactivate_plugins()` and `update_option()` both fire third-party hooks in a half-broken request — cannot cost the others. Without this, one throwing hook meant a blank page.
- **The earliest-boot window is a supported case.** WP registers the fatal error handler at `wp-settings.php`'s `wp_register_fatal_error_handler()` (line 69 in 7.0), but loads `formatting.php` (escaping, 115), `functions.php` → `option.php` (the options API, 117), and `wp_plugin_directory_constants()` (`WP_PLUGIN_DIR`, 488) *after* it. A fatal in `advanced-cache.php` (97), `object-cache.php` (151), `db.php` (136), `sunrise.php`, or an early core file therefore reaches `handle()` with those missing — and on PHP 8 reading an undefined constant **throws**. Two distinct guards are needed:
  - *Missing* — `match_active_plugin()` bails when `WP_PLUGIN_DIR` is undefined; `get_active_plugins()` bails when `get_option()` is.
  - *Present but broken* — the harder case. A fatal inside `wp_start_object_cache()` aborts before core loads `wp-includes/cache.php`, so `wp_cache_get()` never exists and the whole options API **exists but throws**: `get_option()` directly, and `esc_html()`/`sanitize_text_field()`/`get_bloginfo()` indirectly through `wp_check_invalid_utf8()`'s `get_option( 'blog_charset' )` lookup. `function_exists()` cannot see this. Such calls go through `wp_call( $fn, $args, $fallback )`, and the page escapes with the pure-PHP `esc()`/`esc_link()` instead of the WP helpers.

  - *Broken outright* — a crashed `db.php`/`object-cache.php` leaves no usable options API at all, so the incident could not be logged. `repair_options_api()` performs the fallback core would have performed itself: rebuild `$wpdb` from the `DB_*` constants, load `wp-includes/cache.php`, and replay the core includes `advanced-cache.php` aborted before. Guarded by `can_load_core_cache()`, because a partially-declared cache API would make the include a *non-catchable* redeclare fatal.

  Net effect: the fatal is reported as `unattributed` (correctly — no plugin has loaded that early), the branded 500 page renders, and the incident is logged. Verified on a live install for all of `advanced-cache.php`, `object-cache.php` and `db.php`: same request goes from 0 bytes and no log row, to a full page plus a log entry.

### Shutdown-context constraints (critical)

`FPAD_Fatal_Error_Handler` and the drop-in cannot assume WordPress is loaded. The existing code guards every WP call:

- `function_exists( 'get_option' )` / `function_exists( 'deactivate_plugins' )` etc., with `require_once` of `wp-includes/plugin.php` / `wp-admin/includes/plugin.php` as fallbacks.
- No hooks, no `__()` translations, no plugin APIs in this class.
- Output is a self-contained HTML page with inline CSS — no enqueued assets.

**Any change to `class-fatal-error-handler.php` or the drop-in source must preserve these guards.** An unguarded call that fatals inside the handler means the user gets a raw white screen with no protection at all.

## Drop-in lifecycle: three reinstall paths

The drop-in copy in `wp-content/` must always exist and point at a valid class file. Three code paths maintain it:

| Trigger | Code | Purpose |
|---------|------|---------|
| Activation / deactivation / uninstall | `FPAD_Plugin_Lifecycle::activate()` / `deactivate()` / `uninstall()` | Install on activate; remove on deactivate/uninstall |
| Every `admin_init` | `FPAD_Plugin_Lifecycle::check_dropin()` | Self-heal: reinstall if the drop-in is missing or was overwritten by another plugin |
| `upgrader_process_complete` (this plugin updated) | `FPAD_Utils::plugin_upgrade_hook()` | Remove + reinstall: a plugin update wipes the plugin directory, which would strand the drop-in's `require` of the class file |

Ownership check: `FPAD_Dropin_Manager` identifies "our" drop-in by searching its contents for the string `FPAD_Fatal_Error_Handler`. A foreign `fatal-error-handler.php` (installed by another plugin) is **never removed** — `remove_dropin()` leaves it alone. `is_dropin_installed()` returns false for a foreign drop-in, so `check_dropin()` will attempt an install and `install_dropin()` copies over it: while this plugin is active it deliberately claims the drop-in slot (only one can exist), but on deactivation/uninstall it deletes only its own (see the resolved notes at the end of this document).

## Class responsibilities

| Class | Context | Responsibility |
|-------|---------|----------------|
| `FPAD_Fatal_Error_Handler` | Shutdown (via drop-in) | Detect fatal, attribute to plugin, deactivate, log, render error page |
| `FPAD_Dropin_Manager` | Normal | Copy/remove/verify `wp-content/fatal-error-handler.php`; WP_Filesystem init |
| `FPAD_Admin` | Admin | Error notices + protection warning, Tools → Fatal Plugin Log page (Log + Settings tabs, protection banner, filters/search, per-entry delete, CSV/JSON export, copy-report), clear-log/settings/reinstall handling, Site Health test + debug info, "Settings"/"View Log" action links, notice suppression on the log screen |
| `FPAD_Plugin_Lifecycle` | Normal | Activation/deactivation/uninstall hooks; `admin_init` drop-in health check; hourly protection watchdog (verify → heal → alert) |
| `FPAD_Notifier` | Normal | Deliver queued alerts (`init` + cron backstop); test sends; generic `dispatch_event()` used by the watchdog |
| `FPAD_Admin_UI` | Admin | Presentation-only helpers (icons, buttons, badges, chips, panels, setting rows) used by `FPAD_Admin`; see [ui.md](ui.md) |
| `FPAD_Utils` | Normal | Load textdomain; refresh drop-in after self-update |

There is no autoloader and no namespace — classes use the `FPAD_` prefix and are `require_once`'d explicitly in the main plugin file. Initialization is static (`::init()`) for `FPAD_Utils`, `FPAD_Admin`, and `FPAD_Plugin_Lifecycle`; `FPAD_Dropin_Manager` and `FPAD_Fatal_Error_Handler` are instantiated on demand.

## Integrations and compatibility

- **WordPress core fatal error handling**: this plugin *replaces* `WP_Fatal_Error_Handler` via the drop-in. Core's recovery mode email/link flow is bypassed in favor of immediate deactivation. If `WP_DISABLE_FATAL_ERROR_HANDLER` is defined true, core never invokes any handler — the plugin is inert.
- **Query Monitor**: the drop-in defines `QM_DISABLE_ERROR_HANDLER` to stop QM from registering its own error handler, which conflicted with ours (see commit `d3453d7`).
- **Other fatal-error-handler drop-ins**: detected by content check; never deleted by `remove_dropin()`.
- **Multisite**: not supported (single-site option storage and `active_plugins` only; network-activated plugins use `active_sitewide_plugins`, which is not consulted).

## Known issues / discrepancies (documented, not fixed)

These are observations from reading the code — verify intent before changing:

1. **`assets.yml` workflow targets a `trunk` branch** that does not exist in this repo (branches are `master`/`dev`), so readme/asset-only deploys never trigger.

Resolved (kept here for history):

- **Debug constants control front-end display only** — `display_custom_error_page()` gates error detail on `WP_DEBUG` + `WP_DEBUG_DISPLAY` (with the `FPAD_SHOW_ERROR_DETAILS` override, since 1.2.1). `handle()` calls `add_to_deactivation_log()` unconditionally, so every fatal is recorded in the admin-visible `fpad_deactivation_log` regardless of debug settings and regardless of whether a plugin could be attributed (unattributed fatals are stored with status `unattributed`, shown as "Logged only").
- **Foreign drop-in overwrite** — by design, `install_dropin()` replaces any existing `fatal-error-handler.php` while this plugin is active (only one such drop-in can exist), and `remove_dropin()` removes only our own. The FAQ in readme.txt now describes this behavior accurately.
- **`handle()` caught `Exception` only** — now catches `Throwable`, so a PHP 7 `Error` raised inside the handler no longer escapes.
