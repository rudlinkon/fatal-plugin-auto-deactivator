# Development Guide

## Setup

The plugin ships as plain PHP with no runtime dependencies — the one build artifact, the admin stylesheet, is committed, so a checkout runs as-is. Setup is just placing the repo in a WordPress install:

1. Clone into `wp-content/plugins/fatal-plugin-auto-deactivator/` of a local WordPress site (any local stack: wp-env, LocalWP, Docker, Studio, etc.).
2. Activate via the Plugins screen or `wp plugin activate fatal-plugin-auto-deactivator`.
3. Verify the drop-in installed: `wp-content/fatal-error-handler.php` should exist and contain the string `FPAD_Fatal_Error_Handler`. WP-CLI: `wp eval 'var_dump( file_exists( WP_CONTENT_DIR . "/fatal-error-handler.php" ) );'`

Requirements for the environment:

- WordPress ≥ 5.3 (drop-in support requires ≥ 5.2), PHP ≥ 7.0 — keep code compatible with PHP 7.0 (no typed properties, no arrow functions, no null coalescing assignment).
- `wp-content/` must be writable (the drop-in is copied there).
- For meaningful manual testing, set `define( 'WP_DEBUG', true );` in `wp-config.php` so the error page shows details. Note the detail gate (since 1.2.1): detail shows only when `WP_DEBUG` is on **and** `WP_DEBUG_DISPLAY` is not explicitly `false`; `define( 'FPAD_SHOW_ERROR_DETAILS', true );` forces it on regardless.

### Admin UI build (only when you touch the interface)

The admin stylesheet is generated from Tailwind CSS. The output `assets/css/admin.css` is committed and shipped; npm is needed only to regenerate it.

```bash
npm install      # once
npm run build    # assets/src/admin.css -> assets/css/admin.css
npm run dev      # watch mode
```

Rebuild and commit the CSS with any markup change that introduces a new `fpad:` utility class — Tailwind only emits classes it can find in `includes/**/*.php` and `assets/js/**/*.js`. Full guide, including the design tokens and component vocabulary: [ui.md](ui.md).

### Optional dev tooling

`composer.lock` (untracked in distribution) pins one local dev tool: [`eduardovillao/wp-since`](https://github.com/eduardovillao/wp-since), which static-analyzes the code to verify the declared "Requires at least" WordPress version matches the APIs actually used. There is no `composer.json` in the repo; if you need the tool, install it ad hoc:

```bash
composer require --dev eduardovillao/wp-since
./vendor/eduardovillao/wp-since/bin/wp-since
```

Do not commit `composer.json`/`vendor/` — both are excluded from distribution via `.distignore` and `vendor` is not tracked.

## Branching and workflow

- `dev` — day-to-day development branch.
- `master` — release branch; history shows `master` merged into `dev` and releases cut from `master`.
- Releases are git tags pushed to GitHub (see [deployment.md](deployment.md)).

Typical change flow: branch off / commit to `dev` → merge to `master` for release → bump version → tag.

## Coding standards

The codebase follows **WordPress Coding Standards** (WPCS) conventions, although no `phpcs.xml` is committed:

- Tabs for indentation; spaces inside parentheses: `if ( ! defined( 'WPINC' ) )`.
- Yoda conditions where WPCS expects them; `snake_case` functions/methods; class names prefixed `FPAD_`, files named `class-*.php`.
- Every output escaped (`esc_html`, `esc_url`, `esc_html__`); form handling nonce-protected (`wp_nonce_field` / `wp_verify_nonce`); capability checks (`current_user_can`) before admin output and actions.
- Options, hooks, and function prefixes all use `fpad_` / `FPAD_`.
- Translatable strings use text domain `fatal-plugin-auto-deactivator` with translator comments for placeholders. POT file lives at `languages/fatal-plugin-auto-deactivator.pot`.
- Existing `//phpcs:ignore` comments (e.g. for deliberate `error_log()` calls in the shutdown path) are intentional — keep them and add the same annotation style for new justified violations.
- Every file starts with the direct-access guard: `if ( ! defined( 'WPINC' ) ) { die; }` (the drop-in source is the exception — it defines `ABSPATH` itself because it may run before constants exist).

### The shutdown-context rule

The single most important convention: **code reachable from the drop-in (`class-fatal-error-handler.php`) must guard every WordPress function call** with `function_exists()` and load core files manually when needed. WordPress may be partially loaded when the handler runs. See [architecture.md](architecture.md#shutdown-context-constraints-critical).

### Documentation

Developer docs live in `docs/` and the agent guide in `CLAUDE.md`; both are excluded from distribution via `.distignore`, so they never reach WP.org users — write freely. When a change alters behavior, update in the same commit: the relevant `docs/` page(s), `readme.txt` (description/FAQ/changelog — this one *is* user-facing), and `CLAUDE.md` if architecture-level facts changed. [feature-map.md](feature-map.md) lists per-feature touch points.

## Testing

There is no automated test suite. Testing is manual against a live WordPress install.

Cheapest first check — syntax-lint every PHP file (must stay valid on PHP 7.0; ideally lint with a 7.x binary if available):

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

### Triggering a fatal error safely

Create a throwaway plugin that fatals, e.g. `wp-content/plugins/fpad-test-crash/fpad-test-crash.php`:

```php
<?php
/**
 * Plugin Name: FPAD Test Crash
 */
add_action( 'init', function () {
	this_function_does_not_exist();
} );
```

Activate it, then load any front-end page. Expected results:

1. The custom FPAD error page renders (HTTP 500) with a "Reload Page" button; with `WP_DEBUG` true it names the plugin and shows message/file/line.
2. The crash plugin is deactivated (`wp plugin list` or the Plugins screen).
3. An admin notice appears once on the next wp-admin page load, then clears.
4. The incident appears under **Tools → Fatal Plugin Log** (`tools.php?page=fpad-log`).
5. `error_log` contains a `Fatal Plugin Auto Deactivator: Auto-deactivated plugin: ...` line.

### Other scenarios worth checking after changes

- **Drop-in self-heal**: delete `wp-content/fatal-error-handler.php`, load any wp-admin page — it should reappear (`admin_init` check).
- **Deactivation cleanup**: deactivate the plugin — the drop-in must be removed; a *foreign* `fatal-error-handler.php` (without the `FPAD_Fatal_Error_Handler` string) must be left untouched.
- **Parse errors**: a syntax error in the test plugin (caught at include time) should also be attributed and deactivated.
- **Non-plugin fatals**: a fatal in a theme should render the error page but deactivate nothing (logged with status "Logged only").
- **Log-only mode** (since 1.3.0): enable it under Tools → Fatal Plugin Log → Settings, trigger the crash plugin — it must stay active, the entry gets a "Log only" badge, and the error page says deactivation is turned off.
- **Protected plugins** (since 1.3.0): add the crash plugin to the allowlist — it must stay active, with a "Protected" badge and matching error-page copy.
- **Protection status surfaces** (since 1.3.0): with the drop-in deleted or replaced by a foreign file, the site-wide admin notice, log-page banner, and Site Health test must all report it, and "Reinstall protection" must restore ours (without ever deleting a foreign drop-in).
- **Log coalescing** (since 1.4.0): trigger the same fatal repeatedly — one row with an `×N` count and first/last-seen times, not N rows.
- **Filters & export** (since 1.4.0): source/status filters and search narrow the table; CSV/JSON export downloads the full log; per-entry Delete removes one row (nonce-protected).
- **Log cap**: the permanent log keeps only the 100 newest entries.
- **Clear Log**: the button on the log page requires the nonce and `manage_options`.
- **Instant alerts** (since 1.5.0): enable email and/or webhook under Settings → Notifications, trigger the crash plugin — alert arrives naming the plugin, status, file:line, with a log link; the *same* fatal repeated within the cooldown sends nothing (per-fingerprint rate limit); a fatal with a status unchecked under "Notify about" sends nothing; "Send test email/webhook" buttons deliver and report the HTTP code on webhook failure. With **all notification settings at defaults, behavior must be identical to 1.4.0** (no sends, no new options, no cron rows).
- **Alert queue** (since 1.5.0): fatals early enough that `wp_mail`/`wp_remote_post` don't exist queue into `fpad_alert_queue` and deliver on the next normal request (`init` drain) or the hourly `fpad_notifier_drain` backstop.
- **OOM survival** (since 1.5.0): a `memory_limit`-exhaustion fatal must still produce a log entry and an alert (the drop-in's 256 KB reserved buffer, freed on `handle()` entry). Reinstall the drop-in after changing its source — deployed copies only update via a reinstall path.
- **Watchdog** (since 1.5.0): `wp cron event run fpad_watchdog_check` after deleting the drop-in reinstalls it silently; a foreign drop-in is reclaimed once, then (if foreign again within 24 h) left alone with an alert; `WP_DISABLE_FATAL_ERROR_HANDLER` in wp-config surfaces as status `disabled` everywhere; renaming the plugin folder surfaces as `stranded`. The banner shows "Protection last verified: … ago" after the first run.

A WordPress sandbox/WP-CLI environment is the fastest loop: `wp plugin activate`, `wp option get fpad_deactivation_log`, `wp option delete fpad_deactivated_plugins`, and tailing `debug.log` cover most verification without clicking through wp-admin.
