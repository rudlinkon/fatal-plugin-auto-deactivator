# Fatal Plugin Auto Deactivator — Developer Documentation

Technical documentation for developing and maintaining the Fatal Plugin Auto Deactivator WordPress plugin. Written to be equally navigable by humans and AI coding agents.

| Document | Contents |
|----------|----------|
| [architecture.md](architecture.md) | Entry points and init flow, the drop-in mechanism, error-handling flow, how the two halves communicate, class responsibilities, execution contexts |
| [feature-map.md](feature-map.md) | **Feature → file/function lookup**, sync pairs (things that must change together), playbooks for common changes |
| [ui.md](ui.md) | **Admin UI**: design tokens, component vocabulary, the Tailwind build, `FPAD_Admin_UI` helper API, JS markup contracts, UI playbooks |
| [reference.md](reference.md) | Options/data schemas, every hook used, admin pages and nonces, constants, per-class method reference |
| [development.md](development.md) | Local setup, branching, coding standards, the shutdown-context rule, manual test scenarios |
| [deployment.md](deployment.md) | Release checklist, version bumping, CI workflows, WordPress.org SVN deployment, rollback |
| [prd/](prd/README.md) | Product requirements documents for proposed features (researched, prioritized, implementation-ready) |

## Route by task

| You want to… | Start at |
|--------------|----------|
| Understand how fatal handling works end to end | [architecture.md](architecture.md) |
| Find which file/function implements a feature | [feature-map.md](feature-map.md#feature--implementation-lookup) |
| Change or add a feature safely (touch points, mirrors) | [feature-map.md](feature-map.md#playbooks-for-common-changes) → check [sync pairs](feature-map.md#things-that-must-change-together-sync-pairs) |
| Change anything that renders markup or CSS | [ui.md](ui.md) — read before editing admin screens or the error page |
| Look up an option schema, hook, nonce, or method | [reference.md](reference.md) |
| Modify anything reachable from the drop-in | [architecture.md → shutdown constraints](architecture.md#shutdown-context-constraints-critical) first — non-negotiable |
| Set up locally / test a change manually | [development.md](development.md) |
| Cut a release | [deployment.md](deployment.md) |

## What the plugin does

When any active plugin causes a fatal PHP error, this plugin:

1. Detects the error during PHP shutdown via a WordPress **drop-in** (`wp-content/fatal-error-handler.php`).
2. Identifies the culprit by matching the error's file path against active plugin directories.
3. Deactivates only that plugin — unless log-only mode is on or the plugin is on the protected-plugins allowlist, in which case the fatal is only attributed and logged.
4. Logs the incident (admin notice queue + permanent log, both in `wp_options`).
5. Renders a custom error page with a reload button instead of the white screen of death.

It works out of the box with no required configuration. Optional settings (since 1.3.0) live under **Tools → Fatal Plugin Log → Settings**: a log-only mode and a protected-plugins allowlist. The same screen hosts the log viewer (filters, search, per-entry delete, CSV/JSON export since 1.4.0) and a protection-status card. The whole admin interface is built on a Tailwind-based design system — see [ui.md](ui.md).

## Quick facts

- **Slug / text domain:** `fatal-plugin-auto-deactivator`
- **Distribution:** [WordPress.org plugin directory](https://wordpress.org/plugins/fatal-plugin-auto-deactivator/) (this `docs/` directory is excluded from the shipped plugin)
- **Requirements:** WordPress ≥ 5.3, PHP ≥ 7.0 — all code must stay PHP 7.0 compatible
- **License:** GPL-2.0+
- **Stack:** plain PHP, no runtime dependencies, no custom DB tables, no test suite (manual testing — see development.md). The admin stylesheet is built from Tailwind CSS by the developer and **committed**, so the shipped plugin still needs no build step — see [ui.md](ui.md)
- **Surface:** no REST endpoints, no AJAX; two cron events (protection watchdog, alert-queue drain) and one public filter (`fpad_watchdog_interval`) since 1.5.0
- **Branches:** `dev` (development) → `master` (release); releases deploy to WP.org SVN on tag push
