# Development Guide

## Setup

The plugin is plain PHP with no build step, no npm, and no runtime Composer dependencies. Setup is just placing the repo in a WordPress install:

1. Clone into `wp-content/plugins/fatal-plugin-auto-deactivator/` of a local WordPress site (any local stack: wp-env, LocalWP, Docker, Studio, etc.).
2. Activate via the Plugins screen or `wp plugin activate fatal-plugin-auto-deactivator`.
3. Verify the drop-in installed: `wp-content/fatal-error-handler.php` should exist and contain the string `FPAD_Fatal_Error_Handler`. WP-CLI: `wp eval 'var_dump( file_exists( WP_CONTENT_DIR . "/fatal-error-handler.php" ) );'`

Requirements for the environment:

- WordPress ≥ 5.3 (drop-in support requires ≥ 5.2), PHP ≥ 7.0 — keep code compatible with PHP 7.0 (no typed properties, no arrow functions, no null coalescing assignment).
- `wp-content/` must be writable (the drop-in is copied there).
- For meaningful manual testing, set `define( 'WP_DEBUG', true );` in `wp-config.php` so the error page shows details.

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

## Testing

There is no automated test suite. Testing is manual against a live WordPress install:

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
- **Non-plugin fatals**: a fatal in a theme should render the error page but deactivate nothing.
- **Log cap**: the permanent log keeps only the 100 newest entries.
- **Clear Log**: the button on the log page requires the nonce and `manage_options`.

A WordPress sandbox/WP-CLI environment is the fastest loop: `wp plugin activate`, `wp option get fpad_deactivation_log`, `wp option delete fpad_deactivated_plugins`, and tailing `debug.log` cover most verification without clicking through wp-admin.
