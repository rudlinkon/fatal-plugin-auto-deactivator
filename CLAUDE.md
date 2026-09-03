# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Fatal Plugin Auto Deactivator** (slug: `fatal-plugin-auto-deactivator`) is a WordPress.org-distributed plugin that automatically deactivates any plugin causing a fatal PHP error, logs the incident, and shows a custom error page instead of the white screen of death. Plain PHP, no test suite. No REST endpoints, no AJAX. Since 1.5.0: two cron events (watchdog + alert drain) and one public filter (`fpad_watchdog_interval`).

**The shipped plugin has no build step**, but the admin stylesheet is generated: `assets/src/admin.css` (Tailwind CSS v4) → `assets/css/admin.css` via `npm run build`, and the **built file is committed**. Any markup change that introduces a new `fpad:` utility class requires a rebuild + commit of `assets/css/admin.css`, or the style silently does not exist. Read `docs/ui.md` before touching anything that renders markup or CSS.

Detailed docs live in `docs/` (excluded from distribution; keep them updated when behavior changes):
- `docs/feature-map.md` — **feature → file/function lookup, sync pairs, playbooks for common changes; start here when locating or changing a feature**
- `docs/ui.md` — **design tokens, `fpad-*` component vocabulary, Tailwind build, `FPAD_Admin_UI` API, JS markup contracts; start here for any UI change**
- `docs/architecture.md` — entry points, drop-in mechanism, error flow, execution contexts
- `docs/reference.md` — option schemas, hook table, nonces/admin surfaces, per-class method reference
- `docs/development.md` — setup, conventions, manual test scenarios
- `docs/deployment.md` — release checklist and CI

- Minimum: WordPress 5.3, PHP 7.0 (the drop-in mechanism requires WP ≥ 5.2; code must stay PHP 7.0 compatible).
- Code follows WordPress Coding Standards (tabs, Yoda conditions, `esc_html`/`esc_url` escaping, nonces). `phpcs:ignore` annotations are used deliberately — keep them.
- Admin CSS is Tailwind with the `fpad:` prefix and **no preflight** (it must never reset wp-admin). Components are `.fpad-*` classes scoped under `#fpad-app`; never use `!important`. The visitor error page is styled by inline CSS inside `display_custom_error_page()` and must stay self-contained (no enqueue, no WP APIs) — the design tokens are duplicated there on purpose (sync pair).

## Architecture: the drop-in mechanism

The whole plugin revolves around WordPress's **drop-in** system. On activation, `includes/fatal-error-handler-dropin.php` is copied to `wp-content/fatal-error-handler.php`. WordPress core loads that drop-in during its shutdown handler when a fatal error occurs and calls `handle()` on the object it returns — this works even when the crashing plugin took the whole request down.

Flow on a fatal error:
1. WP core shutdown handler loads `wp-content/fatal-error-handler.php` (the drop-in).
2. Drop-in defines `FPAD_PLUGIN_DIR` relative to its own location, requires `includes/class-fatal-error-handler.php` from this plugin, defines `QM_DISABLE_ERROR_HANDLER` (Query Monitor conflict), and returns `new FPAD_Fatal_Error_Handler()`.
3. `FPAD_Fatal_Error_Handler::handle()` reads `error_get_last()`, matches the error file path against each active plugin's directory (prefix match on the error file path — not stack trace analysis), calls `deactivate_plugins()` if matched, **always** records the incident in `fpad_deactivation_log` (even when no plugin matched), renders an inline HTML 500 page, and exits.

**Critical constraint**: `FPAD_Fatal_Error_Handler` and the drop-in run in a context where WordPress may be only partially loaded. Every WP function call in that class must be guarded with `function_exists()` / file includes, as the existing code does. Never add unguarded WP API calls, hooks, or plugin-loaded assumptions to `class-fatal-error-handler.php` or the drop-in. **This includes constants**: core registers the fatal handler at `wp-settings.php`'s `wp_register_fatal_error_handler()`, *before* it loads `formatting.php` (escaping), `functions.php` → `option.php` (options API), and `wp_plugin_directory_constants()` (`WP_PLUGIN_DIR`) — so a fatal in an early drop-in (`advanced-cache.php`, `object-cache.php`, `db.php`, `sunrise.php`) hits this class without them, and on PHP 8 an undefined constant throws. Guard constants with `defined()`. **For functions, `function_exists()` is necessary but NOT sufficient**: when the fatal is inside `wp_start_object_cache()` (a broken `object-cache.php` — the most common case), core never reaches `wp-includes/cache.php`, so `wp_cache_get()` is undefined and everything built on the options API *exists but throws* — `get_option()` directly, and `esc_html()`/`sanitize_text_field()`/`get_bloginfo()` indirectly via `wp_check_invalid_utf8()`'s `get_option( 'blog_charset' )`. Route such calls through `FPAD_Fatal_Error_Handler::wp_call( $fn, $args, $fallback )`, escape page output with the pure-PHP `esc()`/`esc_link()`, and keep each step of `handle()` in its own `try/catch`.

### Drop-in lifecycle (three reinstall paths — keep them in sync)

The drop-in file must always exist and reference a valid class file inside this plugin's directory:
- `FPAD_Plugin_Lifecycle::activate()/deactivate()/uninstall()` — install/remove via `FPAD_Dropin_Manager`.
- `FPAD_Plugin_Lifecycle::check_dropin()` on `admin_init` — reinstalls if missing; a *foreign* drop-in is reclaimed at most once per 24 h via `may_reclaim_dropin()`, which shares the watchdog's back-off state so admin page loads cannot fight another plugin for the slot.
- `FPAD_Utils::plugin_upgrade_hook()` on `upgrader_process_complete` — removes and reinstalls the drop-in when *this* plugin is updated (the plugin dir is wiped during update, which would strand the drop-in's `require`).

`FPAD_Dropin_Manager::remove_dropin()`/`is_dropin_installed()` identify "our" drop-in by searching its content for the string `FPAD_Fatal_Error_Handler` — never remove a foreign drop-in.

### Classes (all in `includes/`, no autoloader — required explicitly in the main file)

- `FPAD_Fatal_Error_Handler` — error detection, plugin matching/deactivation, logging, custom error page (shutdown context, guarded WP calls only). Deactivation honors `fpad_settings` (log-only mode + protected-plugins allowlist), read via the guarded `get_settings()`; a matched-but-not-deactivated plugin is still attributed and logged with a `status`.
- `FPAD_Dropin_Manager` — install/remove/verify the drop-in copy in `wp-content/`; `get_status()` reports protection state (`active`/`foreign`/`missing`/`unwritable`/`no_filesystem`). Ownership is matched against the `OWNERSHIP_MARKER` constant.
- `FPAD_Admin_UI` — presentation-only helpers for the admin screens: inline SVG icons, buttons, badges, chips, stat cards, panels, setting rows, switches, checkbox cards, selects. Every helper escapes its own arguments and returns HTML. `FPAD_Admin` owns data and composition; this class owns markup.
- `FPAD_Admin` — admin notices, Tools → "Fatal Plugin Log" page (`tools.php?page=fpad-log`) with **Log** + **Settings** tabs and a protection-status banner, clear-log form (nonce `fpad_clear_log`), settings form (nonce `fpad_save_settings`), nonce'd reinstall action (`fpad_reinstall`), site-wide protection warning notice, Site Health test + debug info, "Settings"/"View Log" plugin action links. The log viewer (since 1.4.0) also supports source/status filters + search (GET), per-entry delete (`fpad_delete_{key}` nonce), CSV/JSON export (`admin_post_fpad_export_log`, nonce `fpad_export_log`), and a vanilla-JS copy-to-clipboard bug report. On the log screen it also strips other plugins'/core `admin_notices` via `current_screen` (`maybe_suppress_admin_notices`), keeping only its own inline banner and `settings_errors()` feedback.
- `FPAD_Plugin_Lifecycle` — activation/deactivation/uninstall hooks + `admin_init` drop-in check + hourly protection **watchdog** (`fpad_watchdog_check` cron: `verify_protection()` → heal missing/foreign → alert; state in `fpad_watchdog_state`; interval filter `fpad_watchdog_interval` — the plugin's only public hook).
- `FPAD_Notifier` — (1.5.0) alert delivery on normal requests: drains `fpad_alert_queue` (`init` prio 20 + lazy hourly cron `fpad_notifier_drain`), test sends, and `dispatch_event()` used by the watchdog (falls back to `wp_mail(admin_email)` when no channel configured).
- `FPAD_Utils` — textdomain loading + self-update drop-in refresh.

Since 1.5.0 the shutdown handler also sends **instant alerts** (`maybe_notify()`: email + webhook json/slack, per-fingerprint cooldown via `fpad_alert_state`, queue fallback when transports are missing) and the drop-in reserves a 256 KB buffer freed on `handle()` entry so OOM fatals still log/alert. `verify_protection()` adds statuses `disabled` (`WP_DISABLE_FATAL_ERROR_HANDLER`) and `stranded` (drop-in's require target unreadable) on top of `get_status()`.

### Data storage (wp_options only, no custom tables)

- `fpad_deactivated_plugins` — pending admin-notice queue; written only when a plugin is actually deactivated; cleared after notices display.
- `fpad_deactivation_log` — permanent log, newest first, capped at 100 entries; written for **every** detected fatal (attributed or not). Entries carry `deactivated` (bool) and `status` (`deactivated`/`protected`/`log_only`/`unavailable`/`unattributed`); unattributed fatals have empty `plugin`/`plugin_name`. Since 1.4.0 identical repeats are coalesced (fingerprint of type|file|line|msg|plugin|status) into one entry with `count` + `first_time`, and entries also store `request_uri`/`php_version`/`wp_version`; `error_msg` is capped at 2000 chars.
- `fpad_settings` — user settings: `log_only` (bool), `protected_plugins` (array of basenames never auto-deactivated), and since 1.5.0 the notification keys (`notify_email`, `notify_email_to`, `notify_webhook`, `notify_webhook_url`, `notify_webhook_format`, `notify_statuses`, `notify_cooldown`). Read via **two mirrored `get_settings()` implementations** (guarded in the handler, public in `FPAD_Admin`) whose defaults must stay identical — a documented sync pair.
- `fpad_alert_state` (1.5.0) — per-fingerprint alert cooldown timestamps, 7-day prune.
- `fpad_alert_queue` (1.5.0) — alerts queued when transports were unavailable at shutdown; cap 20; drained by `FPAD_Notifier`.
- `fpad_watchdog_state` (1.5.0) — watchdog heartbeat/incident state.

All six options are deleted on uninstall. Cron events: `fpad_watchdog_check` (scheduled on activation + self-healed from `check_dropin()`), `fpad_notifier_drain` (lazily scheduled only while a notification channel is enabled). Both cleared on deactivation/uninstall.

## Versioning and release

Version must be bumped in **three places**: the `Version:` plugin header and `FPAD_VERSION` constant in `fatal-plugin-auto-deactivator.php`, and `Stable tag:` in `readme.txt` (plus a changelog entry there). Also keep `Tested up to:` (in both the plugin header and `readme.txt`) set to a real, current **WordPress** version — not a PHP version.

- Branches: `dev` for development, `master` for releases.
- Pushing a git **tag** triggers `.github/workflows/release.yml` → deploys to WordPress.org SVN (10up action; `SVN_USERNAME`/`SVN_PASSWORD` secrets).
- `.github/workflows/build-archive.yml` (manual dispatch) builds a distributable zip honoring `.distignore`.
- `.github/workflows/assets.yml` triggers on a `trunk` branch that doesn't exist in this repo — currently dormant.

## Gotchas

- Detailed error output on the public error page is gated (since 1.2.1) on `WP_DEBUG` **and** `WP_DEBUG_DISPLAY` (detail shows only when `WP_DEBUG` is on and `WP_DEBUG_DISPLAY` is not explicitly `false`); the `FPAD_SHOW_ERROR_DETAILS` constant overrides the gate. Code and readme.txt now agree.
- There is no `composer.json` tracked; `composer.lock` and `vendor/` (dev tool `eduardovillao/wp-since`) exist only locally and are not part of the distribution.
- Only errors originating in `wp-content/plugins/<dir>` files are attributed; errors in mu-plugins, themes, or core still show the custom error page but deactivate nothing.
