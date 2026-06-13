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
        ├── class-admin.php                 ← notices, log page, action links
        ├── class-plugin-lifecycle.php      ← activate/deactivate/uninstall + admin_init check
        └── class-utils.php                 ← textdomain, self-update drop-in refresh
```

## The drop-in mechanism

WordPress (since 5.2) supports a `fatal-error-handler.php` **drop-in**: if that file exists in `wp-content/`, core's shutdown handler (registered very early in `wp-includes/load.php`, before plugins load) includes it and, if it returns an object with a `handle()` method, uses that object instead of the default `WP_Fatal_Error_Handler`. This is the most reliable hook point available — it fires even when a plugin fatals during its own loading.

The drop-in source (`includes/fatal-error-handler-dropin.php`) is deliberately tiny:

- Defines `ABSPATH` and `FPAD_PLUGIN_DIR` **relative to its own location** (`wp-content/`), so the copy works regardless of install path.
- Defines `QM_DISABLE_ERROR_HANDLER` to prevent Query Monitor's error handler from conflicting with ours.
- Requires `includes/class-fatal-error-handler.php` from the plugin directory and returns `new FPAD_Fatal_Error_Handler()`.

Note: `FPAD_Dropin_Manager::create_dropin_source()` can regenerate a fallback source file if the tracked one is missing. The generated fallback embeds an **absolute** plugin path, unlike the tracked source which computes it relatively. The tracked file is what normally ships; the generator is a recovery path only.

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
 ├── detect_error()            error_get_last(); only E_ERROR, E_PARSE, E_CORE_ERROR,
 │                             E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR qualify
 ├── maybe_deactivate_plugin() match error['file'] against WP_PLUGIN_DIR/<plugin dir>
 │    └── deactivate_plugin()  deactivate_plugins(); error_log(); store options:
 │         ├── fpad_deactivated_plugins  (admin-notice queue)
 │         └── fpad_deactivation_log     (permanent, capped at 100 entries)
 └── display_custom_error_page()  HTTP 500, inline-styled HTML page, exit
```

Key behaviors:

- **Culprit identification is a file-path prefix match**, not stack-trace analysis (readme.txt's marketing copy says otherwise). The directory of each active plugin's basename is compared against the start of `error['file']`. First match wins; loop breaks.
- Errors originating in **mu-plugins, themes, drop-ins, or core** match no active plugin: nothing is deactivated, but the custom error page still renders.
- Error detail (message, file, line, deactivated plugin name) is shown on the public error page **only when `WP_DEBUG` is true**; otherwise visitors get a generic message. (readme.txt says `WP_DEBUG_DISPLAY`; the code checks `WP_DEBUG` — see `display_custom_error_page()`.)
- `handle()` wraps everything in `try/catch (Exception)` and stays silent on failure, so the handler itself can never produce a secondary crash for `Exception`-class failures.

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

Ownership check: `FPAD_Dropin_Manager` identifies "our" drop-in by searching its contents for the string `FPAD_Fatal_Error_Handler`. A foreign `fatal-error-handler.php` (installed by another plugin) is **never removed or overwritten** — `remove_dropin()` leaves it alone and `is_dropin_installed()` returns false (which means `check_dropin()` will attempt an install but `install_dropin()` copies over it — see Known issues below).

## Class responsibilities

| Class | Context | Responsibility |
|-------|---------|----------------|
| `FPAD_Fatal_Error_Handler` | Shutdown (via drop-in) | Detect fatal, attribute to plugin, deactivate, log, render error page |
| `FPAD_Dropin_Manager` | Normal | Copy/remove/verify `wp-content/fatal-error-handler.php`; WP_Filesystem init |
| `FPAD_Admin` | Admin | Error notices, Tools → Fatal Plugin Log page, clear-log handling, "View Log" action link |
| `FPAD_Plugin_Lifecycle` | Normal | Activation/deactivation/uninstall hooks; `admin_init` drop-in health check |
| `FPAD_Utils` | Normal | Load textdomain; refresh drop-in after self-update |

There is no autoloader and no namespace — classes use the `FPAD_` prefix and are `require_once`'d explicitly in the main plugin file. Initialization is static (`::init()`) for `FPAD_Utils`, `FPAD_Admin`, and `FPAD_Plugin_Lifecycle`; `FPAD_Dropin_Manager` and `FPAD_Fatal_Error_Handler` are instantiated on demand.

## Integrations and compatibility

- **WordPress core fatal error handling**: this plugin *replaces* `WP_Fatal_Error_Handler` via the drop-in. Core's recovery mode email/link flow is bypassed in favor of immediate deactivation. If `WP_DISABLE_FATAL_ERROR_HANDLER` is defined true, core never invokes any handler — the plugin is inert.
- **Query Monitor**: the drop-in defines `QM_DISABLE_ERROR_HANDLER` to stop QM from registering its own error handler, which conflicted with ours (see commit `d3453d7`).
- **Other fatal-error-handler drop-ins**: detected by content check; never deleted by `remove_dropin()`.
- **Multisite**: not supported (single-site option storage and `active_plugins` only; network-activated plugins use `active_sitewide_plugins`, which is not consulted).

## Known issues / discrepancies (documented, not fixed)

These are observations from reading the code — verify intent before changing:

1. **`WP_DEBUG` vs `WP_DEBUG_DISPLAY`**: code gates public error detail on `WP_DEBUG`; readme.txt documents `WP_DEBUG_DISPLAY`.
2. **Foreign drop-in overwrite on `admin_init`**: `check_dropin()` calls `install_dropin()` whenever `is_dropin_installed()` is false — including when a *foreign* drop-in is present — and `install_dropin()` does an unconditional `copy()`. The FAQ promises foreign drop-ins are not overwritten; only `remove_dropin()` honors that.
3. **`handle()` catches `Exception` only**, not `Throwable` — a PHP 7 `Error` thrown inside the handler would escape.
4. **`assets.yml` workflow targets a `trunk` branch** that does not exist in this repo (branches are `master`/`dev`), so readme/asset-only deploys never trigger.
