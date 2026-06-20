# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Fatal Plugin Auto Deactivator** (slug: `fatal-plugin-auto-deactivator`) is a WordPress.org-distributed plugin that automatically deactivates any plugin causing a fatal PHP error, logs the incident, and shows a custom error page instead of the white screen of death. Plain PHP — no build step, no test suite, no node/npm. Detailed docs live in `docs/`.

- Minimum: WordPress 5.3, PHP 7.0 (the drop-in mechanism requires WP ≥ 5.2; code must stay PHP 7.0 compatible).
- Code follows WordPress Coding Standards (tabs, Yoda conditions, `esc_html`/`esc_url` escaping, nonces). `phpcs:ignore` annotations are used deliberately — keep them.

## Architecture: the drop-in mechanism

The whole plugin revolves around WordPress's **drop-in** system. On activation, `includes/fatal-error-handler-dropin.php` is copied to `wp-content/fatal-error-handler.php`. WordPress core loads that drop-in during its shutdown handler when a fatal error occurs and calls `handle()` on the object it returns — this works even when the crashing plugin took the whole request down.

Flow on a fatal error:
1. WP core shutdown handler loads `wp-content/fatal-error-handler.php` (the drop-in).
2. Drop-in defines `FPAD_PLUGIN_DIR` relative to its own location, requires `includes/class-fatal-error-handler.php` from this plugin, defines `QM_DISABLE_ERROR_HANDLER` (Query Monitor conflict), and returns `new FPAD_Fatal_Error_Handler()`.
3. `FPAD_Fatal_Error_Handler::handle()` reads `error_get_last()`, matches the error file path against each active plugin's directory (prefix match — not stack trace analysis, despite what readme.txt says), calls `deactivate_plugins()` if matched, **always** records the incident in `fpad_deactivation_log` (even when no plugin matched), renders an inline HTML 500 page, and exits.

**Critical constraint**: `FPAD_Fatal_Error_Handler` and the drop-in run in a context where WordPress may be only partially loaded. Every WP function call in that class must be guarded with `function_exists()` / file includes, as the existing code does. Never add unguarded WP API calls, hooks, or plugin-loaded assumptions to `class-fatal-error-handler.php` or the drop-in.

### Drop-in lifecycle (three reinstall paths — keep them in sync)

The drop-in file must always exist and reference a valid class file inside this plugin's directory:
- `FPAD_Plugin_Lifecycle::activate()/deactivate()/uninstall()` — install/remove via `FPAD_Dropin_Manager`.
- `FPAD_Plugin_Lifecycle::check_dropin()` on `admin_init` — reinstalls if missing or overwritten by another plugin.
- `FPAD_Utils::plugin_upgrade_hook()` on `upgrader_process_complete` — removes and reinstalls the drop-in when *this* plugin is updated (the plugin dir is wiped during update, which would strand the drop-in's `require`).

`FPAD_Dropin_Manager::remove_dropin()`/`is_dropin_installed()` identify "our" drop-in by searching its content for the string `FPAD_Fatal_Error_Handler` — never remove a foreign drop-in.

### Classes (all in `includes/`, no autoloader — required explicitly in the main file)

- `FPAD_Fatal_Error_Handler` — error detection, plugin matching/deactivation, logging, custom error page (shutdown context, guarded WP calls only). Deactivation honors `fpad_settings` (log-only mode + protected-plugins allowlist), read via the guarded `get_settings()`; a matched-but-not-deactivated plugin is still attributed and logged with a `status`.
- `FPAD_Dropin_Manager` — install/remove/verify the drop-in copy in `wp-content/`; `get_status()` reports protection state (`active`/`foreign`/`missing`/`unwritable`/`no_filesystem`). Ownership is matched against the `OWNERSHIP_MARKER` constant.
- `FPAD_Admin` — admin notices, Tools → "Fatal Plugin Log" page (`tools.php?page=fpad-log`) with **Log** + **Settings** tabs and a protection-status banner, clear-log form (nonce `fpad_clear_log`), settings form (nonce `fpad_save_settings`), nonce'd reinstall action (`fpad_reinstall`), site-wide protection warning notice, Site Health test + debug info, "Settings"/"View Log" plugin action links. The log viewer (since 1.4.0) also supports source/status filters + search (GET), per-entry delete (`fpad_delete_{key}` nonce), CSV/JSON export (`admin_post_fpad_export_log`, nonce `fpad_export_log`), and a vanilla-JS copy-to-clipboard bug report. On the log screen it also strips other plugins'/core `admin_notices` via `current_screen` (`maybe_suppress_admin_notices`), keeping only its own inline banner and `settings_errors()` feedback.
- `FPAD_Plugin_Lifecycle` — activation/deactivation/uninstall hooks + `admin_init` drop-in check.
- `FPAD_Utils` — textdomain loading + self-update drop-in refresh.

### Data storage (wp_options only, no custom tables)

- `fpad_deactivated_plugins` — pending admin-notice queue; written only when a plugin is actually deactivated; cleared after notices display.
- `fpad_deactivation_log` — permanent log, newest first, capped at 100 entries; written for **every** detected fatal (attributed or not). Entries carry `deactivated` (bool) and `status` (`deactivated`/`protected`/`log_only`/`unavailable`/`unattributed`); unattributed fatals have empty `plugin`/`plugin_name`. Since 1.4.0 identical repeats are coalesced (fingerprint of type|file|line|msg|plugin|status) into one entry with `count` + `first_time`, and entries also store `request_uri`/`php_version`/`wp_version`; `error_msg` is capped at 2000 chars.
- `fpad_settings` — user settings: `log_only` (bool) and `protected_plugins` (array of basenames never auto-deactivated). Read in the shutdown handler (guarded) and the admin.

All three options are deleted on uninstall.

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
