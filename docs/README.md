# Fatal Plugin Auto Deactivator — Developer Documentation

Technical documentation for developing and maintaining the Fatal Plugin Auto Deactivator WordPress plugin.

| Document | Contents |
|----------|----------|
| [architecture.md](architecture.md) | The drop-in mechanism, error-handling flow, class responsibilities, execution contexts |
| [development.md](development.md) | Local setup, development workflow, coding standards, manual testing |
| [reference.md](reference.md) | Database/options structure, hooks used, admin pages, constants, class/method reference |
| [deployment.md](deployment.md) | Release process, version bumping, CI/CD workflows, WordPress.org SVN deployment |

## What the plugin does

When any active plugin causes a fatal PHP error, this plugin:

1. Detects the error during PHP shutdown via a WordPress **drop-in** (`wp-content/fatal-error-handler.php`).
2. Identifies the culprit by matching the error's file path against active plugin directories.
3. Deactivates only that plugin.
4. Logs the incident (admin notice queue + permanent log, both in `wp_options`).
5. Renders a custom error page with a reload button instead of the white screen of death.

It is zero-configuration: there are no settings, only a log viewer under **Tools → Fatal Plugin Log**.

## Quick facts

- **Slug / text domain:** `fatal-plugin-auto-deactivator`
- **Distribution:** [WordPress.org plugin directory](https://wordpress.org/plugins/fatal-plugin-auto-deactivator/)
- **Requirements:** WordPress ≥ 5.3, PHP ≥ 7.0
- **License:** GPL-2.0+
- **Stack:** plain PHP, no build step, no runtime dependencies, no custom DB tables
- **Branches:** `dev` (development) → `master` (release); releases deploy to WP.org SVN on tag push
