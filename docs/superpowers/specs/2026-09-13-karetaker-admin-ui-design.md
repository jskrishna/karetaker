# Karetaker Admin UI: design

Date: 2026-09-13  
Status: approved in conversation (approach 1: WP-native polish)  
Repo: `~/karetaker` only: do not edit `spice-web-media`  
Parent: `docs/design.md` (Admin UI: plain PHP, Settings-style, WP_List_Table; no React / no build)

## Goal

Replace the basic Tools submenu screen with a top-level, branded, user-friendly admin UI that still feels like WordPress: suitable for WordPress.org: without changing Watch / Guard / Harden / Tell / Agency behaviour.

## Decisions locked

| Topic | Choice |
|---|---|
| Menu | Top-level `add_menu_page`, position ~80 (near Plugins / Tools) |
| Intro + credits | Product-first Overview intro; small Team Krikir footer on every tab (no About tab) |
| Activity | Human severity labels + readable context; keep list table |
| Visual approach | WP-native polish (CSS on our page only), not a custom mini design system |
| Stack | Plain PHP + CSS (+ JS only if required); no React, no bundler |

## Out of scope

- Changing detection, scan, harden filters, alerts, or agency signing behaviour
- React / Vue / build pipeline
- Dark theme that fights wp-admin globally
- New About tab
- Activity filters (deferred; labels + readable context only)
- Removing `Update URI: false` from the working tree (upload package only)
- Editing the spice-web-media site

## Surfaces

### 1. Menu & routing

- Replace `add_management_page` with `add_menu_page`.
- Capability: `manage_options` (unchanged).
- Slug: keep `karetaker` so bookmarks can migrate with a soft redirect from `tools.php?page=karetaker` → `admin.php?page=karetaker` (same query args).
- Menu icon: plugin SVG (watchtower), base64 data URI or `plugins_url` to an SVG under `assets/` that **is** shipped in the plugin zip (directory marketing PNGs stay in SVN `assets/` via `.distignore`; ship a small `assets/menu-icon.svg` or `assets/admin/` that is **not** excluded: adjust `.distignore` so admin CSS/SVG ship, marketing banner/screenshots do not).
- All internal links use `admin_url( 'admin.php?page=karetaker&tab=…' )`.

### 2. Assets enqueue

- On `admin_enqueue_scripts`, if `$hook` is our page: enqueue `assets/admin.css` (and `assets/admin.js` only if needed).
- Version with `KARETAKER_VERSION` or `filemtime`.
- No global admin CSS.

### 3. Page chrome

Every tab:

- Header row: icon + title “Karetaker” + tagline “A watchtower for WordPress.”
- Existing four tabs: Overview, Activity, Harden, Settings.
- Footer: “Karetaker v{version} · by Team Krikir” (link Author URI if/when set; plain text is fine for 0.1.0).

### 4. Overview

- Intro card: short plain-language what it is / is not (from `docs/design.md` / `readme.txt`), no essay.
- Status cards (or compact status grid): last scan time + outcome summary, Guard flags (ok/bad), ACT events this week, events stored, agency on/off.
- Kill-switch notice when `karetaker_is_disabled()` (already exists on Harden; show on Overview too).

### 5. Activity

- Keep `Karetaker_List_Table`.
- Severity column: labels **Log** / **Watch** / **Act-now** (map from severity constants), styled as badges via admin CSS.
- Context column: human one-liner from known keys (e.g. check name, plugin slug, option); full JSON only as `title` attribute or visually secondary, not the primary cell text.
- User column: keep ID for 0.1.0 (numeric ID already noted in prior work); optional display name later.

### 6. Harden & Settings

- Behaviour, nonces, capabilities, and save handlers unchanged.
- Harden: clearer visual hierarchy (label + help + Desired checkbox + Live now); cards or spaced rows via CSS, not a new data model.
- Settings: section headings (Alerts, Retention, Proxies, Agency) for scanability.
- Agency generate / regenerate / clear unchanged.

### 7. Branding files

| Ship in plugin zip | SVN / marketing only (`.distignore`) |
|---|---|
| `assets/admin.css` | `banner-*.png`, `icon-*.png`, `screenshot-*.png` |
| `assets/menu-icon.svg` (or under `assets/admin/`) | |

Regenerate directory screenshots after UI ships.

### 8. Standards (review gate)

- Escape all output; existing nonce + `manage_options` on POSTs.
- All new strings through `__()` / `esc_html__()` with text domain `karetaker`.
- Docblocks on new public methods; `composer phpcs` clean.
- Plugin Check against a dist zip still only ERROR for `Update URI` until upload strip.
- No comments in code beyond WordPress/plugin headers and required phpcs ignores; rationale in `CODE-NOTES.md`.

## Acceptance

- [ ] Karetaker appears as its own sidebar item near Plugins/Tools with custom icon
- [ ] Overview shows intro + status without feeling empty
- [ ] Activity shows Log/Watch/Act-now and readable context
- [ ] Harden/Settings behaviour unchanged; layout clearer
- [ ] Footer credit present; no About tab
- [ ] Old Tools URL still reaches the UI
- [ ] WPCS clean; no behaviour regressions on scan/harden/agency

## Non-goals reminder

Push notifications stay scarce (no admin nags beyond existing save notices). Dashboard widgets stay out (design.md).
