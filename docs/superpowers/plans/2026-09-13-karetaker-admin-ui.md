# Karetaker Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a top-level, branded, WP-native Karetaker admin UI (Overview intro, status cards, readable Activity, clearer Harden/Settings, footer credit) without changing security behaviour.

**Architecture:** Keep `Karetaker_Admin` + `Karetaker_List_Table` as the render/save surface. Add page-only `assets/admin.css` and a shipped `assets/menu-icon.svg`. Move menu registration to `add_menu_page` (~position 80) and point all internal links at `admin.php?page=karetaker`. Soft-redirect legacy `tools.php?page=karetaker`.

**Tech Stack:** PHP 7.4+, WordPress admin APIs, plain CSS, existing WP_List_Table; WPCS via `composer phpcs`.

## Global Constraints

- Repo: `/Users/jskrishna/karetaker` only: never edit `spice-web-media` source trees.
- Spec: `docs/superpowers/specs/2026-09-13-karetaker-admin-ui-design.md`
- No React / no bundler / no About tab / no Activity filters
- Save handlers, nonces, `manage_options`, Harden/Agency behaviour unchanged
- Text domain `karetaker`; docblocks on new public methods; rationale in `CODE-NOTES.md`
- Commits only when the human asks
- `.distignore`: keep marketing PNGs out of the zip; **do ship** `assets/admin.css` and `assets/menu-icon.svg`

---

### Task 1: Ship admin assets + distignore split

**Files:**
- Create: `assets/menu-icon.svg`
- Create: `assets/admin.css` (minimal shell styles; expanded in later tasks)
- Modify: `.distignore`

**Interfaces:**
- Consumes: existing watchtower brand (charcoal / amber from directory icon)
- Produces: `plugins_url( 'assets/menu-icon.svg', KARETAKER_FILE )` path that exists in dist zips

- [ ] **Step 1: Write `assets/menu-icon.svg`**

Simple 20×20 viewBox watchtower line icon (monochrome `#a7aaad` for the admin menu).

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" aria-hidden="true">
  <path stroke="#a7aaad" stroke-width="1.5" stroke-linejoin="round" d="M4 17h12M6 17V9l4-5 4 5v8M8 17v-4h4v4M10 4v2"/>
</svg>
```

Tune paths to the product silhouette; keep the file tiny.

- [ ] **Step 2: Create starter `assets/admin.css`**

```css
.karetaker-wrap { max-width: 1100px; }
.karetaker-header { display: flex; gap: 12px; align-items: center; margin: 8px 0 16px; }
.karetaker-header__icon { width: 40px; height: 40px; border-radius: 8px; }
.karetaker-header__title { margin: 0; font-size: 23px; font-weight: 400; line-height: 1.3; }
.karetaker-header__tagline { margin: 2px 0 0; color: #646970; }
.karetaker-footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #dcdcde; color: #646970; font-size: 12px; }
.karetaker-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px 20px; margin: 0 0 16px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
.karetaker-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 0 0 16px; }
.karetaker-status-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 14px 16px; }
.karetaker-status-card__label { display: block; color: #646970; font-size: 12px; margin-bottom: 4px; }
.karetaker-status-card__value { font-size: 16px; font-weight: 600; color: #1d2327; }
.karetaker-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 500; line-height: 1.6; }
.karetaker-badge--log { background: #f0f0f1; color: #50575e; }
.karetaker-badge--watch { background: #f0f6fc; color: #2c3338; }
.karetaker-badge--act { background: #fcf0f1; color: #8a2424; }
```

- [ ] **Step 3: Fix `.distignore` so admin assets ship**

Replace a bare `assets` exclude with:

```
assets/banner-*.png
assets/icon-*.png
assets/screenshot-*.png
```

Do **not** exclude `assets/admin.css` or `assets/menu-icon.svg`.

- [ ] **Step 4: Verify dist includes CSS/SVG**

Run: `./tools/build-dist.sh /tmp/karetaker-ui-test.zip && unzip -l /tmp/karetaker-ui-test.zip | rg 'admin.css|menu-icon|banner|screenshot'`  
Expected: `admin.css` and `menu-icon.svg` listed; banner/screenshot PNGs absent.

---

### Task 2: Top-level menu, enqueue, legacy redirect, chrome

**Files:**
- Modify: `includes/class-admin.php`
- Modify: `CODE-NOTES.md` (short note under Admin)

**Interfaces:**
- Consumes: `assets/menu-icon.svg`, `assets/admin.css`
- Produces: `register_menu()` via `add_menu_page`; `enqueue_assets( $hook )`; `admin_page_url( $tab = '' ): string`; `maybe_redirect_legacy_tools()`; `render_header()` / `render_footer()`

- [ ] **Step 1: Add URL helper + legacy redirect**

```php
public static function admin_page_url( $tab = '' ) {
	$args = array( 'page' => self::PAGE_SLUG );
	if ( '' !== $tab ) {
		$args['tab'] = sanitize_key( $tab );
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

public static function maybe_redirect_legacy_tools() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $pagenow;
	if ( 'tools.php' !== $pagenow ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( self::PAGE_SLUG !== $page ) {
		return;
	}
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_safe_redirect( self::admin_page_url( $tab ) );
	exit;
}
```

Hook: `add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_legacy_tools' ) );`

- [ ] **Step 2: Replace `add_management_page` with `add_menu_page`**

```php
public static function register_menu() {
	add_menu_page(
		__( 'Karetaker', 'karetaker' ),
		__( 'Karetaker', 'karetaker' ),
		'manage_options',
		self::PAGE_SLUG,
		array( __CLASS__, 'render_page' ),
		plugins_url( 'assets/menu-icon.svg', KARETAKER_FILE ),
		80
	);
}
```

- [ ] **Step 3: Enqueue admin.css on our hook only**

```php
public static function enqueue_assets( $hook ) {
	if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
		return;
	}
	$path = KARETAKER_DIR . 'assets/admin.css';
	wp_enqueue_style(
		'karetaker-admin',
		plugins_url( 'assets/admin.css', KARETAKER_FILE ),
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : KARETAKER_VERSION
	);
}
```

`add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );`

- [ ] **Step 4: Header + footer + wrap class; fix tab base URL**

In `render_page()`, use `class="wrap karetaker-wrap"`, call `render_header()` after capability check, `render_footer()` before closing wrap.  
In `render_tabs()`, set `$base = self::admin_page_url();` and `add_query_arg( 'tab', $slug, $base )`.  
Update any `tools.php?page=` redirects in save handlers to `admin_page_url( 'settings' )` / `admin_page_url( 'harden' )`.

- [ ] **Step 5: Smoke**

With Local DB up: open `/wp-admin/admin.php?page=karetaker`: sidebar item present.  
Open `/wp-admin/tools.php?page=karetaker`: lands on `admin.php?page=karetaker`.

---

### Task 3: Overview intro + status cards

**Files:**
- Modify: `includes/class-admin.php` (`render_overview`)
- Modify: `assets/admin.css` (if gaps)

**Interfaces:**
- Consumes: `Karetaker_Scanner::state()`, `Karetaker_Guard::CHECKS`, `Karetaker_Events::query`, `Karetaker_Schema::count`, `Karetaker_Settings::agency_token`, `karetaker_is_disabled()`
- Produces: intro card markup + status grid

- [ ] **Step 1: Rewrite `render_overview()`**

1. If `karetaker_is_disabled()` → error notice (kill switch).
2. Intro `.karetaker-card`: short what it is / is not (i18n strings from readme).
3. `.karetaker-status-grid`: Last scan, Guard, ACT this week, Events stored, Agency On/Off.

Reuse existing data gathering; do not change queries.

- [ ] **Step 2: Visual smoke on Overview tab**

Intro + cards render; no PHP notices.

---

### Task 4: Activity severity labels + readable context

**Files:**
- Modify: `includes/class-list-table.php`
- Modify: `assets/admin.css` (badges from Task 1)

**Interfaces:**
- Produces: severity label helper + context summary helper on the list table
- Verify constant names in `class-events.php` (`SEVERITY_LOG`, mid/watch, `SEVERITY_ACT`) and match exactly

- [ ] **Step 1: Map severity to Log / Watch / Act-now badges**

- [ ] **Step 2: Context one-liner from known keys** (`check`, `plugin`, `option`, `error`, `user_login`, etc.); full JSON in `title` only

- [ ] **Step 3: Update `column_default` for `severity` and `context`**

- [ ] **Step 4: Smoke Activity tab**

---

### Task 5: Harden + Settings layout polish

**Files:**
- Modify: `includes/class-admin.php` (`render_harden`, `render_settings`, `render_agency_settings`)
- Modify: `assets/admin.css`

**Interfaces:**
- No new save endpoints; same `harden[key]` fields and nonces

- [ ] **Step 1: Harden**: wrap in card / clearer row hierarchy; kill-switch notice; probe unchanged

- [ ] **Step 2: Settings**: section headings (Alerts, Event retention, Trusted proxies, Agency); card wrap

- [ ] **Step 3: Smoke save**: Harden toggle + Settings email round-trip

---

### Task 6: Docs, WPCS, dist check

**Files:**
- Modify: `CODE-NOTES.md`
- Optionally: regenerate `assets/screenshot-*.png`
- Run: `composer phpcs`, `./tools/build-dist.sh`

- [ ] **Step 1: CODE-NOTES**: menu position 80, legacy redirect, which assets ship

- [ ] **Step 2: `composer phpcs`**: 0 errors / 0 warnings

- [ ] **Step 3: Dist zip** contains `admin.css` + `menu-icon.svg`

- [ ] **Step 4: Optional screenshot refresh** for directory assets

---

## Spec coverage

| Spec item | Task |
|---|---|
| Top-level menu ~80 + icon | 2 |
| admin.css enqueue page-only | 1–2 |
| Header + footer credit | 2 |
| Overview intro + status | 3 |
| Activity labels + readable context | 4 |
| Harden/Settings layout only | 5 |
| Distignore split / ship admin assets | 1 |
| Legacy tools redirect | 2 |
| WPCS / CODE-NOTES | 6 |

## Plan self-review

- Handlers and Harden behaviour untouched.
- Commits only when the human asks.
- Severity constant names verified at Task 4 against source.
