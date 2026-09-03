# Feature Map — Where Everything Lives

Task-oriented lookup: find the feature you need to change, get the exact files and functions to touch. Complements [architecture.md](architecture.md) (how it works) and [reference.md](reference.md) (full API/data reference).

The codebase is small and flat — one bootstrap file plus five classes in `includes/`, no autoloader, no namespaces:

| File | Class | Runs in |
|------|-------|---------|
| `fatal-plugin-auto-deactivator.php` | — (bootstrap) | Normal plugin load |
| `includes/class-fatal-error-handler.php` | `FPAD_Fatal_Error_Handler` | **PHP shutdown after a fatal** (partial WP — guard everything) |
| `includes/fatal-error-handler-dropin.php` | — (drop-in source, copied to `wp-content/fatal-error-handler.php`) | **PHP shutdown after a fatal** |
| `includes/class-dropin-manager.php` | `FPAD_Dropin_Manager` | Normal (admin/lifecycle/cron) |
| `includes/class-admin.php` | `FPAD_Admin` | wp-admin only |
| `includes/class-admin-ui.php` | `FPAD_Admin_UI` | wp-admin only (presentation helpers — see [ui.md](ui.md)) |
| `includes/class-notifier.php` | `FPAD_Notifier` | Normal requests + cron (alert delivery, since 1.5.0) |
| `includes/class-plugin-lifecycle.php` | `FPAD_Plugin_Lifecycle` | Activation/deactivation/uninstall + `admin_init` + watchdog cron |
| `includes/class-utils.php` | `FPAD_Utils` | Normal plugin load |

## Feature → implementation lookup

### Shutdown runtime (the actual crash handling)

All in `includes/class-fatal-error-handler.php` unless noted. Everything here must follow the [shutdown-context rule](architecture.md#shutdown-context-constraints-critical).

| Feature | Function(s) | Data touched | Since |
|---------|-------------|--------------|-------|
| Entry point on fatal | `FPAD_Fatal_Error_Handler::handle()` — called by WP core via the drop-in | — | 1.0.0 |
| Fatal-error detection (which PHP error types qualify) | `detect_error()` | `error_get_last()` | 1.0.0 |
| Step-aside during plugin/theme editor syntax checks | `handle()` (checks `WP_SANDBOX_SCRAPING`) | — | 1.2.1 |
| Culprit plugin attribution (path prefix match) | `match_active_plugin()`, `get_active_plugins()` | `active_plugins` option | 1.0.0 |
| Deactivation decision (settings enforcement) | `maybe_deactivate_plugin()`, `get_settings()` | `fpad_settings` option (read) | 1.3.0 |
| Actual deactivation + server log line | `deactivate_plugin()` | `deactivate_plugins()`, `error_log()` | 1.0.0 |
| Admin-notice queueing | `store_deactivated_plugin_info()` | `fpad_deactivated_plugins` option (write) | 1.0.0 |
| Permanent logging (every fatal, attributed or not) | `add_to_deactivation_log()` | `fpad_deactivation_log` option (write) | 1.2.0 |
| Repeat coalescing (×N counting) | `add_to_deactivation_log()`, `log_fingerprint()` | same | 1.4.0 |
| Log context capture (request URI, PHP/WP version, message cap) | `add_to_deactivation_log()`, `current_request_uri()` | same | 1.4.0 |
| Error-source classification (plugin/theme/mu-plugin/drop-in/core) | `detect_error_source()` — admin mirror: `FPAD_Admin::source_key()` | — | 1.2.0 |
| Instant alerts: gates, cooldown, direct send | `maybe_notify()` → `send_alerts()` (called AFTER the page is rendered and flushed) | `fpad_settings` (read), `fpad_alert_state` (write) | 1.5.0 |
| Instant alerts: payload/content builders | `build_alert_subject()`, `build_alert_email_body()`, `build_webhook_json_payload()`, `build_webhook_slack_payload()`, `status_verb()`, `error_type_name()`, `alert_site_url()` | — | 1.5.0 |
| Instant alerts: early-fatal queue fallback | `queue_alert()` — drained by `FPAD_Notifier` on normal requests | `fpad_alert_queue` (write) | 1.5.0 |
| OOM survival (reserved memory buffer) | Drop-in reserves 256 KB in `$GLOBALS['fpad_reserved_memory']`; `handle()` frees it first | — | 1.5.0 |
| Custom 500 error page (HTML + inline CSS) | `display_custom_error_page()` | — | 1.0.0 |
| Error-detail gating on the public page | `display_custom_error_page()` (checks `WP_DEBUG`, `WP_DEBUG_DISPLAY`, `FPAD_SHOW_ERROR_DETAILS`) | — | 1.2.1 |
| Handler self-protection (never crash the crash handler) | `handle()` `try/catch (Throwable)`; `headers_sent()` guard in `display_custom_error_page()` | — | 1.2.0 |
| Per-step isolation (one failing step can't cost the others) | `handle()` — separate Throwable guard around attribute / log / render / alert | — | 1.5.0 |
| Earliest-boot survival (fatal in `advanced-cache.php`, `object-cache.php`, `db.php`, `sunrise.php`) | `match_active_plugin()` (`WP_PLUGIN_DIR` guard), `get_active_plugins()` (`get_option` guard), `wp_call()`, pure-PHP `esc()` / `esc_link()` | — | 1.5.0 |
| Logging a drop-in crash (restore the options API so the entry can be written) | `repair_options_api()`, `options_api_works()`, `can_load_core_cache()` | `fpad_deactivation_log` (write) | 1.5.0 |
| Custom page + HTTP 500 when `WP_DEBUG_DISPLAY` is on | Drop-in `ob_start()` (sync pair with the generator) + buffer clear in `display_custom_error_page()` | — | 1.5.0 |
| Query Monitor conflict guard | `includes/fatal-error-handler-dropin.php` (`QM_DISABLE_ERROR_HANDLER`) | — | 1.0.1 |

### Drop-in lifecycle (install / verify / heal)

| Feature | Function(s) | File | Since |
|---------|-------------|------|-------|
| Install the drop-in (copy source → `wp-content/`) | `FPAD_Dropin_Manager::install_dropin()` | `class-dropin-manager.php` | 1.0.0 |
| Remove only *our* drop-in (never foreign) | `FPAD_Dropin_Manager::remove_dropin()`, `dropin_is_ours()`, `OWNERSHIP_MARKER` | `class-dropin-manager.php` | 1.0.0 |
| Protection-status reporting | `FPAD_Dropin_Manager::get_status()` → `active`/`foreign`/`missing`/`unwritable`/`no_filesystem` | `class-dropin-manager.php` | 1.3.0 |
| End-to-end protection verification (+`disabled`, `stranded`) | `FPAD_Dropin_Manager::verify_protection()` | `class-dropin-manager.php` | 1.5.0 |
| Protection watchdog (hourly verify, heal, alert) | `FPAD_Plugin_Lifecycle::watchdog_check()`, `schedule_watchdog()`, `get_watchdog_state()` on cron `fpad_watchdog_check`; interval filter `fpad_watchdog_interval` | `class-plugin-lifecycle.php` | 1.5.0 |
| Watchdog alerting (lost/restored events) | `FPAD_Plugin_Lifecycle::send_watchdog_alert()`, `describe_status()` → `FPAD_Notifier::dispatch_event()` or `wp_mail` fallback | `class-plugin-lifecycle.php`, `class-notifier.php` | 1.5.0 |
| Alert queue drain + test sends + generic events | `FPAD_Notifier::drain_queue()`, `send_test()`, `dispatch_event()` | `class-notifier.php` | 1.5.0 |
| Drop-in source regeneration fallback | `FPAD_Dropin_Manager::create_dropin_source()` — **must stay in sync with the tracked source file** | `class-dropin-manager.php` | 1.0.0 |
| Install on activation / remove on deactivation & uninstall | `FPAD_Plugin_Lifecycle::activate()` / `deactivate()` / `uninstall()` | `class-plugin-lifecycle.php` | 1.0.0 |
| Self-heal missing/overwritten drop-in | `FPAD_Plugin_Lifecycle::check_dropin()` on `admin_init` | `class-plugin-lifecycle.php` | 1.0.0 |
| Foreign-reclaim back-off shared by `admin_init` and the watchdog | `may_reclaim_dropin()` + `get_watchdog_state()` | `fpad_watchdog_state` | 1.5.0 |
| Survive self-update (plugin dir wiped on update) | `FPAD_Utils::plugin_upgrade_hook()` on `upgrader_process_complete` | `class-utils.php` | 1.0.1 |
| Options cleanup on uninstall | `FPAD_Plugin_Lifecycle::uninstall()` | `class-plugin-lifecycle.php` | 1.0.0 |

### Admin UI (`includes/class-admin.php` + `includes/class-admin-ui.php`)

The screen is **Tools → Fatal Plugin Log** (`tools.php?page=fpad-log`), registered by `add_settings_page()`, rendered by `render_log_page()` with two tabs (Log, Settings). `FPAD_Admin` owns the data and composition; `FPAD_Admin_UI` owns the markup vocabulary (icons, buttons, badges, chips, panels, setting rows). Styling lives in `assets/` — read [ui.md](ui.md) before changing any of it.

| Feature | Function(s) | Since |
|---------|-------------|-------|
| Hook wiring for everything below | `init()` | 1.0.0 |
| "Plugin X was deactivated" notices (queue → display → clear) | `display_admin_notices()` | 1.0.0 |
| Site-wide "protection not active" warning | `maybe_show_protection_notice()`, `protection_message()`, `get_protection_state()` | 1.3.0 |
| Page shell: masthead, tabs, feedback messages | `render_log_page()`, `render_masthead()` | 1.1.0 (tabs 1.3.0) |
| Protection-status card on the log page | `render_protection_banner()` | 1.3.0 |
| Asset loading (Tailwind build on our screen, notice CSS elsewhere) | `enqueue_assets()`, `has_pending_notice()`, `SCREEN_ID` | 1.6.0 |
| Markup vocabulary (icons, buttons, badges, chips, panels, rows, switches) | `FPAD_Admin_UI::*` — see [ui.md §5](ui.md#5-php-helper-api--fpad_admin_ui) | 1.6.0 |
| One-click "Reinstall protection" | `handle_admin_actions()` (`fpad_action=reinstall`), `reinstall_url()` | 1.3.0 |
| Log tab: summary cards | `render_log_summary()` | 1.2.0 |
| Log tab: incident cards, badges, ×N counts, context chips | `render_entries()`, `status_badge()`/`status_meta()`, `get_error_type_string()`, `source_key()`/`source_label()`/`source_icon()`/`unattributed_copy()`, `entry_status()` | 1.1.0–1.6.0 |
| Filter by source/status + free-text search | `render_filter_bar()`, `filter_log()`, `status_labels()` (GET params `fpad_source`, `fpad_status`, `fpad_q`) | 1.4.0 |
| Per-entry delete | `handle_admin_actions()` (`fpad_action=delete`), `entry_key()` | 1.4.0 |
| Clear whole log | `handle_clear_log()` (POST, processed during `render_log_page()`) | 1.1.0 |
| CSV/JSON export | `export_log()` (`admin_post_fpad_export_log`), `csv_safe()` | 1.4.0 |
| Copy-to-clipboard bug report | `build_report()` + `assets/js/admin.js` (`.fpad-copy` / `data-fpad-report`) | 1.4.0 |
| Confirm guards, live list filter, switch captions | `assets/js/admin.js` (`data-fpad-confirm`, `data-fpad-filter`, `data-fpad-switch-text`) | 1.6.0 |
| Settings tab: log-only mode + protected plugins | `render_settings_tab()`, `handle_settings_save()`, `get_settings()`, `get_active_plugin_choices()` | 1.3.0 |
| Settings tab: Notifications section (channels, statuses, cooldown) + lazy drain-cron scheduling | `render_settings_tab()`, `handle_settings_save()` | 1.5.0 |
| Test notification buttons | `handle_test_alert()` (`admin_post_fpad_test_alert`) → `FPAD_Notifier::send_test()`; feedback via `fpad_test` query args in `render_log_page()` | 1.5.0 |
| "Protection last verified" heartbeat chip + debug-info row | `last_watchdog_check()`, `render_protection_banner()`, `add_debug_information()` | 1.5.0 |
| Suppress other plugins' notices on the log screen | `maybe_suppress_admin_notices()` on `current_screen` | 1.4.0 |
| Site Health: protection test | `register_site_health_test()`, `site_health_test()` | 1.3.0 |
| Site Health: debug info section | `add_debug_information()` | 1.3.0 |
| "Settings" / "View Log" links on the Plugins screen | `add_plugin_action_links()` | 1.1.0 |

### Platform

| Feature | Where | Notes |
|---------|-------|-------|
| Bootstrap: constants, requires, `::init()` calls, lifecycle hook registration | `fatal-plugin-auto-deactivator.php` | The only file WordPress loads directly |
| i18n | `FPAD_Utils::load_textdomain()`; POT at `languages/fatal-plugin-auto-deactivator.pot` | Shutdown-context strings are deliberately untranslated |
| WP.org listing copy, FAQ, changelog | `readme.txt` | Must be updated alongside user-visible behavior changes |
| CI: release deploy / zip build / (dormant) asset sync | `.github/workflows/release.yml`, `build-archive.yml`, `assets.yml` | See [deployment.md](deployment.md) |
| Admin stylesheet build (Tailwind CLI) | `package.json` scripts, `assets/src/admin.css` → `assets/css/admin.css` | Output is committed; the shipped plugin has no build step. See [ui.md](ui.md) |
| Distribution exclusions | `.distignore` (authoritative), `.gitattributes` (partial mirror) | `docs/`, `CLAUDE.md`, `.claude`, `assets/src`, `package.json` never ship |

There are **no REST endpoints, no AJAX handlers, no shortcodes, and no blocks**. Since 1.5.0 there are two cron events (`fpad_watchdog_check`, hourly; `fpad_notifier_drain`, hourly + lazily scheduled) and one public filter (`fpad_watchdog_interval`). The only HTTP entry points beyond normal page loads are the admin-page GET/POST actions and the two `admin-post.php` actions (`fpad_export_log`, `fpad_test_alert`) listed above.

## Things that must change together (sync pairs)

The classic trap in this codebase is editing one side of a mirrored pair. Check this list before every change:

| If you change… | Also change… | Why |
|----------------|--------------|-----|
| `includes/fatal-error-handler-dropin.php` (tracked drop-in source) | The heredoc in `FPAD_Dropin_Manager::create_dropin_source()` | The generator is a recovery path that must produce an equivalent drop-in (relative paths + `QM_DISABLE_ERROR_HANDLER`) |
| Anything about the drop-in's contents | Keep the literal string `FPAD_Fatal_Error_Handler` in it | It is `FPAD_Dropin_Manager::OWNERSHIP_MARKER` — without it, the plugin no longer recognizes (or removes) its own drop-in |
| Anything about the drop-in's contents (again) | Bump the `fpad-dropin-version: N` token in the tracked source, the generator heredoc, AND `FPAD_Dropin_Manager::DROPIN_VERSION_TOKEN` | `refresh_if_stale()` compares installed copies against the token so non-upgrader deploys (git/rsync/Composer) still get the new drop-in |
| `FPAD_Fatal_Error_Handler::detect_error_source()` | `FPAD_Admin::source_key()` | Deliberate mirror: the error page and the log viewer must label sources identically (the admin copy re-classifies stored paths so old entries stay consistent) |
| `FPAD_Fatal_Error_Handler::get_settings()` | `FPAD_Admin::get_settings()` | Same defaults/validation (incl. all `notify_*` keys since 1.5.0), duplicated because the handler cannot depend on the admin class. `FPAD_Notifier` deliberately reads through the admin mirror so only two copies exist |
| `FPAD_Fatal_Error_Handler::build_alert_email_body()` field order | `FPAD_Admin::build_report()` | The alert email mirrors the copy-to-clipboard bug report layout; the handler cannot call the admin class at shutdown |
| `FPAD_Plugin_Lifecycle::describe_status()` wording | `FPAD_Admin::protection_message()` | Watchdog alert bodies and the admin banner must tell the same story; the admin method is private, so the wording is duplicated |
| The `stranded` path in `FPAD_Dropin_Manager::verify_protection()` | `FPAD_PLUGIN_DIR` computation in the drop-in (and its generator) | Both derive the handler path relative to `WP_CONTENT_DIR`; on symlinked installs this deliberately differs from the main plugin's `FPAD_PLUGIN_DIR` constant |
| The `status` vocabulary in `build_plugin_result()` / `add_to_deactivation_log()` | `FPAD_Admin::status_meta()` (label + badge variant + icon, feeds the badges, the outcome filter and the notification checklist) and `entry_status()` | New status values need presentation, a filter option, and legacy-inference handling |
| Any markup that adds a `fpad:` utility class | Run `npm run build` and commit `assets/css/admin.css` | Tailwind only emits classes it finds when scanning; a stale build silently drops the style |
| The design tokens in `assets/src/admin.css` (`@theme`) | The CSS variables inlined in `FPAD_Fatal_Error_Handler::display_custom_error_page()` | The error page runs at shutdown and cannot load the stylesheet, so it repeats the palette by hand |
| Error-type map in `FPAD_Admin::get_error_type_string()` | The `switch` in `display_custom_error_page()` and the allowlist in `detect_error()` | Three copies of the E_* vocabulary |
| Log entry schema in `add_to_deactivation_log()` | `render_entries()` (display), `export_log()` (CSV columns), `build_report()` (copy text), possibly `log_fingerprint()`/`entry_key()`, and the schema doc in [reference.md](reference.md) | Every consumer of `fpad_deactivation_log` |
| `Version:` header in the main file | `FPAD_VERSION` constant + `Stable tag:` in `readme.txt` | Three-place version bump (see [deployment.md](deployment.md)) |
| `.distignore` | `.gitattributes` `export-ignore` entries | Both filter distribution artifacts (`.distignore` wins for the 10up deploy; `.gitattributes` currently lags — see deployment.md) |

Note there are **two different md5 identities** for log entries — do not conflate them:

- `FPAD_Fatal_Error_Handler::log_fingerprint()` — `type|file|line|msg|plugin|status`, **no timestamps**. Used to coalesce repeats at write time.
- `FPAD_Admin::entry_key()` — `time|first_time|type|file|line|msg`, **no plugin/status**. Used to address one row for the per-entry delete action (nonce `fpad_delete_{key}`).

## Playbooks for common changes

### Add a new setting

1. `FPAD_Admin::render_settings_tab()` — add the form field.
2. `FPAD_Admin::handle_settings_save()` — sanitize + persist into the `fpad_settings` array.
3. `FPAD_Admin::get_settings()` — add the default + validation.
4. If the shutdown handler consumes it: `FPAD_Fatal_Error_Handler::get_settings()` (same default, fully guarded) and the enforcement point (usually `maybe_deactivate_plugin()`).
5. Update the `fpad_settings` schema in [reference.md](reference.md), the readme.txt FAQ, and (if behavior changed) the error-page copy in `display_custom_error_page()`.

### Add a field to log entries

1. `FPAD_Fatal_Error_Handler::add_to_deactivation_log()` — add to the `$log_entry` array. Only constants/superglobals/guarded calls — this runs at shutdown.
2. Decide whether the field belongs in `log_fingerprint()` (it does only if two entries differing in this field are *different incidents*).
3. Read it with `isset()` fallbacks everywhere — **old entries in the wild will not have it** (see the legacy-entry inference in `FPAD_Admin::entry_status()` for the pattern).
4. Surface it: `render_log_table()` (usually the meta row), `export_log()` (new CSV column + `csv_safe()` if user-influenced), `build_report()` if support-relevant.
5. Update the entry schema in [reference.md](reference.md).

### Change anything visual

Read [ui.md](ui.md) first: it lists the design tokens, the `fpad-*` component classes, the `FPAD_Admin_UI` helper API and the JS markup contracts. Then rebuild (`npm run build`) and commit `assets/css/admin.css` with your change.

### Change the public error page

Everything is in `FPAD_Fatal_Error_Handler::display_custom_error_page()`: one self-contained HTML document with inline CSS, `$this->esc()`/`$this->esc_link()` (shutdown-safe `esc_html`/`esc_url` wrappers) on all dynamic values. Constraints: no enqueued assets, no unguarded WP calls, keep the `headers_sent()` bail and the `WP_DEBUG`/`WP_DEBUG_DISPLAY`/`FPAD_SHOW_ERROR_DETAILS` detail gate. Source-specific copy lives in the `switch ( $source )` block; deactivation-outcome copy in its `plugin` case.

### Change which plugin gets blamed (attribution)

`FPAD_Fatal_Error_Handler::match_active_plugin()` is the whole algorithm (normalized path prefix match; single-file plugins matched exactly; symlink-aware via `path_variants()`/`path_is_inside()`, plus a `matches_symlinked_child()` fallback pass for symlinks *inside* a plugin folder). If you change *source semantics* (what counts as plugin/theme/core), update both `detect_error_source()` and its admin mirror `FPAD_Admin::source_key()`.

### Add a state-changing admin action

Follow an existing pattern:
- **GET action with redirect** (like reinstall/delete): branch in `FPAD_Admin::handle_admin_actions()` on `admin_init` — `current_user_can( 'manage_options' )`, `check_admin_referer( ... )`, act, `wp_safe_redirect()` + `exit`, surface the outcome via a query arg read in `render_log_page()`.
- **POST form on the page** (like clear-log/settings): nonce field in the form, a `handle_*()` processor called at the top of `render_log_page()`, feedback via `add_settings_error( 'fpad_messages', ... )`.
- **File download** (like export): `admin_post_{action}` hook + capability + nonce, stream, `exit`.

### Modify drop-in behavior

The drop-in itself should stay minimal (define paths, require the class, return the handler). Real behavior goes in `FPAD_Fatal_Error_Handler`. If you must touch the drop-in: edit `includes/fatal-error-handler-dropin.php` **and** `create_dropin_source()`, keep the `FPAD_Fatal_Error_Handler` marker string, and remember deployed sites only pick up the new copy when a reinstall path runs (activation, `admin_init` self-heal after deletion, self-update hook, or the Reinstall button).

### Where does a brand-new feature go?

- Needs to run **when the site crashes** → `FPAD_Fatal_Error_Handler` (guarded, no new files — the drop-in only loads this one class).
- Admin-facing UI/reporting → `FPAD_Admin`.
- Anything about the drop-in file's existence/health → `FPAD_Dropin_Manager` (mechanics) + `FPAD_Plugin_Lifecycle` (when it runs).
- New class → create `includes/class-<name>.php` with the `FPAD_` prefix, `require_once` it in `fatal-plugin-auto-deactivator.php`, and never `require` it from the drop-in path unless it obeys the shutdown rule.
