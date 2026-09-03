# UI Guide — Admin Screens, Design System, Build

How the plugin's interface is built: the design tokens, the component vocabulary, the Tailwind build, and the rules that keep a utility-CSS admin UI from fighting with wp-admin. Read this before touching anything that renders markup.

Companion docs: [architecture.md](architecture.md) (how the plugin works), [feature-map.md](feature-map.md) (feature → function lookup), [development.md](development.md) (setup and manual testing).

---

## 1. What the UI consists of

| Surface | Rendered by | Styled by | Context |
|---------|-------------|-----------|---------|
| **Tools → Fatal Plugin Log** (Log + Settings tabs) | `FPAD_Admin::render_log_page()` and friends, using `FPAD_Admin_UI` helpers | `assets/css/admin.css` (Tailwind build), `assets/js/admin.js` | wp-admin, our screen only |
| **Admin notices** (plugin deactivated / protection lost) | `FPAD_Admin::display_admin_notices()`, `maybe_show_protection_notice()` | `assets/css/notice.css` (hand-written, ~2 KB) | wp-admin, every screen |
| **Visitor error page** (HTTP 500 after a fatal) | `FPAD_Fatal_Error_Handler::display_custom_error_page()` | Inline `<style>` inside that method | **PHP shutdown, partially loaded WP** |
| **Site Health test + debug info** | `FPAD_Admin::site_health_test()`, `add_debug_information()` | Core Site Health styles | wp-admin |
| **Plugins-screen action links** | `FPAD_Admin::add_plugin_action_links()` | Core | wp-admin |

Files:

```
assets/
  src/admin.css      ← build entry: imports, @source, design tokens
  src/components.css ← the .fpad-* component rules (edit this for components)
  css/admin.css      ← built output, COMMITTED and shipped (never edit by hand)
  css/notice.css     ← hand-written notice styles (edit this directly)
  js/admin.js        ← vanilla JS behaviours, no build step
includes/
  class-admin-ui.php ← markup vocabulary: icons, buttons, badges, chips, panels, rows
  class-admin.php    ← data + composition (owns every screen)
  class-fatal-error-handler.php ← the visitor error page (self-contained styles)
package.json         ← build scripts only; never shipped (.distignore)
```

**The shipped plugin has no build step.** `assets/css/admin.css` is committed, so a WordPress.org download works without npm. The build only exists for developers.

---

## 2. Build pipeline

```bash
npm install          # once — installs the Tailwind CLI locally
npm run build        # assets/src/admin.css → assets/css/admin.css (minified)
npm run dev          # same, in watch mode while working on the UI
```

Rules:

- **Always commit `assets/css/admin.css` together with the change that needed it.** A PHP change that adds a utility class is broken until the CSS is rebuilt, because Tailwind only emits classes it finds in the scanned sources.
- Tailwind scans `includes/**/*.php` and `assets/js/**/*.js` (declared with `@source` in `assets/src/admin.css`). A class assembled from fragments at runtime (`'fpad:p-' . $n`) will **not** be generated — always write class names out in full.
- Before a release, run `npm run build` and confirm `git status` is clean; a stale stylesheet is the one UI bug that never reproduces locally.

### Tailwind configuration decisions

Configured entirely in CSS (Tailwind v4, no `tailwind.config.js`):

| Decision | Why |
|----------|-----|
| **Prefix `fpad:`** — utilities are written `fpad:flex`, `fpad:sm:grid-cols-2` | wp-admin and every other plugin share the same page. An unprefixed `.hidden`/`.fixed`/`.container` would leak into core markup |
| **No cascade layers** — imports carry no `layer()`, nothing is wrapped in `@layer` | **The one that bites.** wp-admin ships its CSS *unlayered*, and an unlayered rule beats a layered one no matter how specific the layered selector is. Inside `@layer components`, core's `a { color: #2271b1 }` beat `#fpad-app .fpad-tab.is-active { color: #fff }` and the active tab rendered blue-on-blue; the same silently applied to buttons and inputs. (Tailwind still emits one `@layer properties` block for `@property` fallbacks — that is fine, it holds no rules of ours) |
| **Import order is the cascade** — `theme.css` → `components.css` → `utilities.css` | Without layers, equal-specificity ties fall back to source order. Components import *before* utilities so a utility class in the markup can still override a component |
| **Preflight not imported** (`@import "tailwindcss/theme.css"` + `utilities.css` only, no `base`) | Preflight resets `h1`, `button`, `input`, `table`… — inside wp-admin that would wreck core screens the moment our stylesheet loaded |
| **`source(none)` + explicit `@source`** | Deterministic scanning; no surprise output from `node_modules` or the built file itself |
| **Components scoped under `#fpad-app`** | wp-admin styles form controls with element selectors (`input[type=text]`, specificity 0,0,1,1). An id-scoped component class (1,0,1,0) wins without `!important` |
| **Scoped reset via `:where(#fpad-app)`** at the top of `components.css` | Preflight is absent, so the browser default `p { margin: 1em 0 }` leaked into every component that only set `margin-top`. `:where()` drops the id to zero specificity, so the reset beats the user-agent sheet by origin but still loses to every `.fpad-*` rule |
| **Loaded on our screen only** (`FPAD_Admin::enqueue_assets()` gates on `SCREEN_ID`) | It is a full utility sheet; it has no business on other plugins' pages |

---

## 3. Design tokens

Defined in the `@theme` block of `assets/src/admin.css`; available as utilities (`fpad:bg-brand-500`) and as CSS variables (`var(--fpad-color-brand-500)`).

### Colour

| Scale | Purpose | Key stops |
|-------|---------|-----------|
| `brand` | Primary actions, active tab, focus ring | `500 #2271b1` (WordPress blue), `600 #1b5e94` hover, `50 #f0f6fc` tint |
| `ink` | Text, borders, surfaces | `900 #1d2327` headings, `600 #50575e` body, `500 #646970` muted, `100 #dcdcde` borders, `25 #f6f7f7` subtle fill |
| `ok` | Deactivated (we fixed it), protection active | `500 #00a32a`, `50 #edfaef`, `700 #005c12` |
| `warn` | Protected / needs attention | `500 #dba617`, `50 #fdf6e6`, `700 #7a5c00` |
| `danger` | Fatal errors, destructive actions, protection lost | `500 #d63638`, `50 #fdf1f1`, `700 #8a2424` |

The palette is deliberately WordPress-native: same hues core uses, extended into full ramps so tints and hovers stay in family. **Do not introduce a new hue** — map new meaning onto an existing scale.

### Type, radius, shadow

- Font: `--font-sans` = the wp-admin system stack; `--font-mono` for every file path, error message and payload.
- Base size 13px (matching wp-admin), 20px for stat values, 19px page title, 11–12px for meta.
- Radius: `md` (6px) for controls, `lg` (8px) for panels and cards, `full` for badges and switches.
- `--shadow-card` for resting surfaces, `--shadow-pop` for the sticky save bar.

The visitor error page repeats these values as plain CSS variables (`--fpad-brand`, `--fpad-ok`, …) because it cannot load the stylesheet — see §7.

---

## 4. Component vocabulary

Every class is prefixed `fpad-` (components) or `fpad:` (utilities). Components live in the `@layer components` block of `assets/src/admin.css`.

### Shell

| Class | Role |
|-------|------|
| `#fpad-app` | Page root; sets font and base size, and spans the full wp-admin content column (no max width — a fixed cap left a gutter on wide screens; prose blocks cap their own measure). **Every admin screen must render inside it** — components are scoped to it |
| `.fpad-masthead`, `.fpad-brand`, `.fpad-brand-mark`, `.fpad-title`, `.fpad-subtitle` | Page header: logo mark (the tile is painted by the SVG, so `.fpad-brand-mark` has no background of its own), plugin name, version line, protection badge |
| `.fpad-tabs`, `.fpad-tab`, `.fpad-tab.is-active`, `.fpad-tab-count` | Segmented tab bar (Log / Settings) with an entry counter |
| `.fpad-notices` | Wrapper around `settings_errors()`; restyles core `.notice` markup to match the page |

### Surfaces

| Class | Role |
|-------|------|
| `.fpad-panel` + `.fpad-panel-head` / `-title` / `-desc` / `-body` / `-foot` | The standard content container. `.fpad-panel-body-flush` removes padding for edge-to-edge lists |
| `.fpad-state` + `--ok` / `--bad` | Protection status card (icon, headline, explanation, "last verified" chip, reinstall button) |
| `.fpad-stats`, `.fpad-stat` (+ `--ok`/`--warn`/`--danger`), `.fpad-stat-icon/-value/-label` | The four at-a-glance counters above the log |
| `.fpad-empty` + `-icon` / `-title` / `-text` | Empty states (no incidents, no filter matches) |
| `.fpad-savebar` | Sticky footer holding "Save settings" and the test-send buttons |

### Log entries

| Class | Role |
|-------|------|
| `.fpad-entries` | Card tray: a tinted, padded column that spaces the incident cards |
| `.fpad-entry` + `-head` / `-ident` / `-badges` / `-title` / `-sub` / `-actions` / `-when` | One incident card |
| `.fpad-entry-mark` (+ `--ok` / `--warn` / `--danger` / `--info`) | Icon tile leading the card: glyph = error source, tint = outcome (same variants as the status badge) |
| `.fpad-entry-body` | Payload column under the head, indented (`sm:pl-12`) so message, path and chips line up with the title rather than the tile |
| `.fpad-entry-note` | Sans-serif explanation on an unattributed card (why nothing was deactivated, what to do) |
| `.fpad-code` | Monospace error block with a red rule; capped at `max-h-44` and scrolls inside itself (markup carries `tabindex="0"` plus `role="region"` and an `aria-label`, so the scroll region stays keyboard reachable and has an accessible name) |
| `.fpad-path` | `file:line`, monospace, breaks anywhere |
| `.fpad-entry-meta` + `.fpad-chip` | Context chips: first seen, request URI, PHP, WP |

### Controls

| Class | Role |
|-------|------|
| `.fpad-btn` + `--primary` / `--danger` / `--ghost` / `--sm` | Every button and button-styled link |
| `.fpad-input`, `.fpad-select`, `.fpad-search` | Form controls (id-scoped so wp-admin's element rules lose) |
| `.fpad-field` + `.fpad-field-label` | Labelled control in the filter toolbar |
| `.fpad-toolbar` | Wrapping row of fields and buttons |
| `.fpad-setting` + `-body` / `-label` / `-control`, `.fpad-help` | One settings row: label + help on the left, control on the right |
| `.fpad-switch` + `-track` / `-text` | On/off switch (hidden checkbox drives a CSS track) |
| `.fpad-checklist`, `.fpad-check`, `.fpad-check-text` | Grid of checkbox cards (protected plugins, notify statuses) |
| `.fpad-radio-row`, `.fpad-radio` | Inline radio group (webhook format) |
| `.fpad-badge` + `--ok` / `--warn` / `--danger` / `--info` / `--neutral` / `--source` | Status and source pills |
| `.fpad-inline-note`, `.fpad-sr-only` | Muted helper text; visually hidden text |

---

## 5. PHP helper API — `FPAD_Admin_UI`

All helpers **escape their own arguments** and **return** a string, so callers `echo` the result (with a `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped` comment, because the return value is already-escaped HTML).

| Method | Signature | Notes |
|--------|-----------|-------|
| `icon()` | `icon( $name, $class = '' )` | Inline 24×24 stroke SVG from `icon_paths()`; inherits `currentColor`. Unknown key → empty string |
| `logo()` | `logo( $size = 40 )` | The product mark: white shield + power symbol on the brand tile, self-contained SVG with prefixed gradient ids. **Sync pair with `.wordpress-org/src/icon.svg`**, the source the WP.org icons are rendered from |
| `badge()` | `badge( $label, $variant = 'neutral', $icon = '' )` | Variants: `ok`, `warn`, `danger`, `info`, `neutral`, `source` |
| `button()` | `button( array $args )` | `label`, `href` (→ `<a>`), `variant`, `size`, `icon`, `type`, `name`, `value`, `attrs` |
| `chip()` | `chip( $icon, $label, $value )` | Metadata chip; `$label` becomes screen-reader text and the tooltip prefix |
| `entry_mark()` | `entry_mark( $icon, $variant = 'neutral' )` | Icon tile for a log entry card; variants `ok`/`warn`/`danger`/`info`, `aria-hidden` (the badges beside it carry the meaning) |
| `stat()` | `stat( array $args )` | `value`, `label`, `icon`, `variant` |
| `empty_state()` | `empty_state( array $args )` | `title`, `text`, `icon`, `actions` (pre-rendered HTML) |
| `panel_open()` / `panel_close()` | `panel_open( array $args )`, `panel_close( $footer = '' )` | `title`, `desc`, `icon`, `actions`, `flush`. **Always pair them** |
| `setting_row()` | `setting_row( array $args )` | `label`, `help`, `control`, `stacked` (control below the label, for wide controls) |
| `switch_control()` | `switch_control( array $args )` | `name`, `checked`, `text`, `attrs` |
| `check_card()` | `check_card( $name, $value, $label, $checked )` | Emits `data-fpad-filterable` so the list filter can match it |
| `select()` | `select( $name, $options, $selected, $any = '' )` | `$any` renders the leading "All …" option |

The available icon keys are the array keys of `FPAD_Admin_UI::icon_paths()`: `shield-check`, `shield-alert`, `alert`, `activity`, `power`, `plug`, `help`, `clock`, `file`, `link`, `search`, `filter`, `download`, `trash`, `copy`, `bell`, `mail`, `globe`, `sliders`, `refresh`, `list`, `calendar`, `cpu`, `x`, `chevron`, `ban`, `check`, `send`.

---

## 6. JavaScript behaviours and their markup contracts

`assets/js/admin.js` is dependency-free, runs only on our screen, and is **strictly progressive** — filtering, saving, deleting, exporting and clearing all work with JS disabled.

| Data attribute | Element | Behaviour |
|----------------|---------|-----------|
| `data-fpad-report="<text>"` + class `fpad-copy` | Button | Copies the plain-text bug report to the clipboard, swapping the label to "Copied" for 1.6 s |
| `data-fpad-confirm="<question>"` | Link, button **or form** | `window.confirm()` guard before the destructive action proceeds |
| `data-fpad-filter="<selector>"` | Search input | Live-filters elements carrying `data-fpad-filterable` inside the target list |
| `data-fpad-filterable="<haystack>"` | List item | Lowercased text the filter matches against |
| `data-fpad-switch-text='["off","on"]'` | Switch checkbox | Swaps the caption next to the switch when toggled |

Strings come from `wp_localize_script( 'fpad-admin', 'fpadUi', … )` in `FPAD_Admin::enqueue_assets()` — add new UI strings there, never inline in JS.

---

## 7. The visitor error page is a separate world

`display_custom_error_page()` runs during PHP shutdown when WordPress may be half-loaded. It therefore:

- **Inlines all CSS** in a `<style>` block — no `wp_enqueue_style()`, no file reads, no external requests.
- Uses **only** `$this->esc()` / `$this->esc_link()` (pure PHP) for escaping, never `esc_html()`.
- Repeats the design tokens as CSS variables instead of importing them, and supports `prefers-color-scheme: dark` since it is a standalone page.
- Adapts its headline to the outcome: *"The problem has been fixed"* + green shield when a plugin was actually deactivated, *"This page is temporarily unavailable"* + amber alert otherwise. The per-source explanation strings are unchanged and remain deliberately untranslated (no textdomain at shutdown).
- Shows technical detail only when `WP_DEBUG` **and** `WP_DEBUG_DISPLAY` allow it (or `FPAD_SHOW_ERROR_DETAILS` overrides) — see [reference.md](reference.md).

**Never** add a WordPress API call, an enqueue, or a class from `FPAD_Admin_UI` to that method. If you restyle it, mirror the token values by hand and keep the markup self-contained.

---

## 8. Rules

1. **Escape at the boundary.** Helpers escape their arguments; composed HTML echoed from `FPAD_Admin` carries a `phpcs:ignore` comment explaining why. Never interpolate an unescaped value into a helper argument that is later echoed raw.
2. **Never `!important`.** If a wp-admin rule wins, scope the component under `#fpad-app` instead — and never wrap our CSS in `@layer`, which hands the fight to core automatically.
   Set margins explicitly on anything you style: with no preflight, an element you give only `fpad:mt-*` keeps the browser's default bottom margin unless the scoped reset covers its tag.
3. **Full class names only** — Tailwind cannot see runtime-assembled strings.
4. **Rebuild + commit the CSS** with any markup change that introduces a new utility.
5. **No external assets.** No CDN, no web fonts, no remote images — WordPress.org forbids it and the error page cannot fetch anything anyway.
6. **Accessibility:** every icon is `aria-hidden` and paired with real text; icon-only affordances get `aria-label`; the active tab carries `aria-current="page"`; visually hidden labels use `.fpad-sr-only`; focus states are visible (`:focus-visible` outline in brand blue). Keep it that way.
7. **i18n:** all admin strings go through `__()`/`esc_html__()` with the `fatal-plugin-auto-deactivator` textdomain. Shutdown-context strings stay untranslated by design.
8. **PHP 7.0 compatibility** — no arrow functions, no spread in array literals, no null coalescing assignment.
9. **RTL is currently physical-property based.** New layout work should prefer logical utilities (`fpad:ps-*`, `fpad:me-*`) so an RTL build is a small step rather than a rewrite.

---

## 9. Playbooks

### Add a stat card
`FPAD_Admin::render_log_summary()` → append to `$cards` with `label`, `value`, `icon`, `variant`. The grid is `1 / 2 / 4` columns; five cards will wrap unevenly, so replace rather than append unless you also change `.fpad-stats`.

### Add a settings field
1. `FPAD_Admin::render_settings_tab()` — add a `FPAD_Admin_UI::setting_row()` inside the right panel.
2. `handle_settings_save()` — sanitize and persist.
3. `get_settings()` — default + validation, **and** the mirrored `FPAD_Fatal_Error_Handler::get_settings()` if the shutdown handler reads it (documented sync pair).
4. Rebuild CSS if you used a new utility.

### Add a status value
`FPAD_Admin::status_meta()` is the single source of truth for label, badge variant and icon. Add the key there and it appears in the badges, the outcome filter and the notification checklist automatically — then handle it in `entry_status()` and the writer side (see [feature-map.md](feature-map.md#things-that-must-change-together-sync-pairs)).

### Add an icon
Add one entry to `FPAD_Admin_UI::icon_paths()` — the inner markup of a 24×24, `stroke="currentColor"`, `fill="none"` icon. Keep the stroke geometry consistent with the existing set (1.8 width, round caps).

### Change the page shell
`render_log_page()` owns the shell: masthead → tabs → notices → protection card → tab content. `render_masthead()` and `render_protection_banner()` are separate so either can change without touching tab rendering.

---

## 10. Verifying UI changes

There is no test suite; verify by rendering. From the WordPress root:

```bash
# Render both tabs headlessly and check for PHP notices
wp eval '
if ( ! defined( "WP_ADMIN" ) ) { define( "WP_ADMIN", true ); }
require_once ABSPATH . "wp-admin/includes/admin.php";
wp_set_current_user( 1 );
foreach ( array( "log", "settings" ) as $tab ) {
    $_GET = array( "page" => "fpad-log", "tab" => $tab );
    ob_start(); FPAD_Admin::render_log_page(); file_put_contents( "/tmp/fpad-$tab.html", ob_get_clean() );
}'
```

Then confirm every class the markup uses actually exists in the build — the failure mode Tailwind makes easy:

```bash
python3 - <<'PY'
import re
css = open('assets/css/admin.css').read()
html = open('/tmp/fpad-log.html').read() + open('/tmp/fpad-settings.html').read()
used = {c for a in re.findall(r'class="([^"]*)"', html) for c in a.split() if c.startswith('fpad')}
esc = lambda c: '.' + re.sub(r'([:.\[\]/#])', r'\\\1', c)
print('missing:', [c for c in sorted(used) if esc(c) not in css] or 'none')
PY
```

(`fpad-copy` is a JS hook with no styles and is expected to be reported as missing.)

States worth checking by hand, because each has its own layout:

- Log tab: empty log · filters matching nothing · a single incident · 100 incidents · a 60-line stack trace (scrolls inside the card) · an unattributed incident · a long request URI.
- Settings tab: no active plugins · ~50 active plugins (checklist grid + filter) · notifications off (save bar shows the hint) · both channels on (two test buttons).
- Protection card: `active` and each failure status (`missing`, `foreign`, `unwritable`, `no_filesystem`, `disabled`, `stranded`) — force one with `wp option patch`/by moving `wp-content/fatal-error-handler.php`.
- Notices: on a screen other than ours, with a pending deactivation and with protection lost.
- Error page: attributed+deactivated, protected, log-only, theme/mu-plugin/core source, with and without `FPAD_SHOW_ERROR_DETAILS`.
- Viewport: 782px (wp-admin's mobile breakpoint) and below — the entry head, toolbar and stat grid all reflow.
