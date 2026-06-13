# Deployment & Release Process

The plugin is distributed through the [WordPress.org plugin directory](https://wordpress.org/plugins/fatal-plugin-auto-deactivator/). Deployment to WP.org SVN is automated via GitHub Actions.

## Release checklist

1. **Finish work on `dev`**, merge to `master`.
2. **Bump the version in three places** (they must match):
   - `fatal-plugin-auto-deactivator.php` — `Version:` plugin header
   - `fatal-plugin-auto-deactivator.php` — `FPAD_VERSION` constant
   - `readme.txt` — `Stable tag:`
3. **Update `readme.txt`**:
   - Add a `== Changelog ==` entry (format: `= X.Y.Z - DD/MM/YYYY =` with `- Added/Improved/Fixed:` bullets).
   - Add an `== Upgrade Notice ==` entry if the release warrants one.
   - Bump `Tested up to:` if the plugin has been verified against a newer WordPress release (both `readme.txt` and the plugin header carry it).
4. **Regenerate the POT file** if translatable strings changed (`wp i18n make-pot . languages/fatal-plugin-auto-deactivator.pot`).
5. **Commit and push `master`**, then **create and push a git tag** matching the version (e.g. `1.2.0`):
   ```bash
   git tag 1.2.0
   git push origin master --tags
   ```
6. The tag push triggers the WP.org deploy (below). Verify the release appears on the plugin's WP.org page and that an in-dashboard update installs cleanly (the drop-in must survive the update — see note below).

## GitHub Actions workflows

### `release.yml` — Deploy to WordPress.org

- **Trigger:** push of any tag.
- **Action:** [`10up/action-wordpress-plugin-deploy@stable`](https://github.com/10up/action-wordpress-plugin-deploy) — commits the tagged tree to WP.org SVN `tags/<tag>` and `trunk`.
- **Secrets required:** `SVN_USERNAME`, `SVN_PASSWORD` (WP.org credentials, configured in the GitHub repo).
- **File filtering:** `.distignore` controls what is excluded from the SVN deploy (dotfiles, CI config, composer files, `vendor`, `tests`, `.wordpress-org`, archives, etc.). `.gitattributes` `export-ignore` entries mirror this for `git archive`-based packaging. Keep both in sync when adding tooling files.
- **WP.org assets:** banners, icons, and screenshots live in `.wordpress-org/` and are deployed to the SVN `assets/` directory by the 10up actions.

### `assets.yml` — readme/assets-only update

- **Trigger:** push to branch `trunk`.
- **Action:** `10up/action-wordpress-plugin-asset-update@stable` — updates only `readme.txt` and `.wordpress-org/` assets on SVN without a release.
- **⚠ Currently dormant:** this repo's branches are `master` and `dev`; no `trunk` branch exists, so this workflow never fires. To use it, either create/push a `trunk` branch or change the trigger to `master`.

### `build-archive.yml` — manual zip build

- **Trigger:** manual (`workflow_dispatch`) from the GitHub Actions tab.
- **Action:** `rudlinkon/action-wordpress-build-zip@master` — produces an installable plugin zip as a workflow artifact (7-day retention). Useful for pre-release smoke testing or distributing a build outside WP.org.

## Update-survival of the drop-in

A plugin update wipes and replaces the plugin directory while the drop-in at `wp-content/fatal-error-handler.php` keeps `require`-ing a class file inside that directory. Two safeguards cover this:

- `FPAD_Utils::plugin_upgrade_hook()` (on `upgrader_process_complete`) removes and reinstalls the drop-in immediately after this plugin is updated.
- `FPAD_Plugin_Lifecycle::check_dropin()` (on `admin_init`) reinstalls the drop-in on the next admin page load if it is ever missing.

When changing the drop-in source (`includes/fatal-error-handler-dropin.php`), remember that **already-deployed sites only get the new drop-in when one of these reinstall paths runs** — the file in `wp-content/` is a copy, not a symlink.

## Rollback

WP.org serves whatever `Stable tag` in `trunk/readme.txt` points to. To roll back a bad release: tag and deploy a new patch release, or (manually, via SVN) set `Stable tag` back to the previous version. Site owners can also install a previous version zip from the WP.org "Advanced" section of the plugin page.
