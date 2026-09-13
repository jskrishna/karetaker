# Karetaker Harden Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship seven opt-in, reversible Harden toggles with a Tools → Karetaker Harden tab (Desired + Live now) and desired flags on `wp karetaker status`.

**Architecture:** Store `harden` bool map in `karetaker_settings`. `Karetaker_Harden::init()` registers hooks only for toggles that are on. Admin Harden tab saves via its own `admin_post` handler and probes live state without storing a “protected” score.

**Tech Stack:** WordPress 6.2+, PHP 7.4+, existing Settings/Events/Admin patterns. No build step.

**Spec:** `docs/superpowers/specs/2026-09-13-karetaker-harden-design.md`  
**Repo:** `/Users/jskrishna/karetaker` only.

## Global Constraints

- Every Harden toggle defaults OFF.
- Never write `wp-config.php`, `.htaccess`, or server config.
- Do not flip `blog_public`; no login lockouts; no CSP.
- Kill switch disables Harden for that boot (`karetaker_boot` returns early).
- Prefix `karetaker_` / `Karetaker_` / `KARETAKER_`; ABSPATH on every PHP file.
- Front-end: hooks only when toggle on; 0 DB writes on ordinary front requests.
- Idempotent with `spice-hardening` mu-plugin.
- Escape admin output; nonce + `manage_options` on save.
- Commits only when the human asks (optional commit steps).

---

## File map

| File | Responsibility |
|---|---|
| `includes/class-harden.php` | Toggle apply + `probe()` + defaults list |
| `includes/class-settings.php` | `harden` default + accessors/sanitize helpers |
| `includes/class-admin.php` | Harden tab + save handler |
| `includes/class-cli.php` | Add `harden.desired` to `status` |
| `karetaker.php` | Require + `Karetaker_Harden::init()` in boot |
| `CODE-NOTES.md` | No-CSP, registration filter, DISALLOW_FILE_EDIT |

---

### Task 1: Settings defaults + Harden core class (headers, xmlrpc, file_editor)

**Files:**
- Modify: `includes/class-settings.php`
- Create: `includes/class-harden.php`
- Modify: `karetaker.php`

**Interfaces:**
- Produces:
  - `Karetaker_Harden::KEYS` — list of seven keys
  - `Karetaker_Harden::defaults(): array`
  - `Karetaker_Harden::desired(): array` — merged bools
  - `Karetaker_Harden::is_on( string $key ): bool`
  - `Karetaker_Harden::init(): void`
  - `Karetaker_Harden::probe( string $key ): string`
  - `Karetaker_Settings::harden(): array` — convenience wrapper optional

- [ ] **Step 1: Extend settings defaults**

In `Karetaker_Settings::defaults()` add:

```php
'harden' => array(
	'headers'       => false,
	'xmlrpc'        => false,
	'file_editor'   => false,
	'user_enum'     => false,
	'version'       => false,
	'registration'  => false,
	'app_passwords' => false,
),
```

Add:

```php
public static function harden() {
	$h = self::get( 'harden' );
	if ( ! is_array( $h ) ) {
		$h = array();
	}
	return wp_parse_args( $h, Karetaker_Harden::defaults() );
}
```

(Require harden class before calling, or inline the same defaults array in Settings to avoid load-order issues — **prefer** duplicating the seven false defaults in Settings::defaults only, and Harden::defaults() returns the same structure; `harden()` merges via `Karetaker_Harden::defaults()` after Harden is loaded.)

- [ ] **Step 2: Create `includes/class-harden.php` with KEYS, defaults, desired, is_on, init skeleton**

`init()`:
1. Read desired flags.
2. If `headers` → add_action send_headers, login_init, admin_init → `send_headers()`.
3. If `xmlrpc` → xmlrpc_enabled false, xmlrpc_methods unset pingbacks, wp_headers unset X-Pingback.
4. If `file_editor` → if `! defined( 'DISALLOW_FILE_EDIT' )` define true.
5. Leave other toggles as empty private methods called from init when on (filled in Task 2).

Header values exactly as spec (no CSP). HSTS only if `is_ssl() && 'local' !== wp_get_environment_type()`.

- [ ] **Step 3: Wire boot**

```php
require_once KARETAKER_DIR . 'includes/class-harden.php';
// inside karetaker_boot after Guard/Alerts:
Karetaker_Harden::init();
```

- [ ] **Step 4: Verify defaults**

```bash
wp eval 'var_export( Karetaker_Settings::harden() );'
```

Expected: all seven `false`.

Enable headers via temporary update, curl -I front URL, look for `nosniff`. Disable after.

- [ ] **Step 5 (optional commit):** `feat: add Harden settings defaults and core toggles`

---

### Task 2: Remaining toggles (user_enum, version, registration, app_passwords) + probes

**Files:**
- Modify: `includes/class-harden.php`

**Interfaces:**
- Completes `init()` branches for all seven keys
- Completes `probe( $key ): string` for all keys per spec

- [ ] **Step 1: Implement user_enum**

- `rest_endpoints` filter: if `! is_user_logged_in()`, unset routes starting with `/wp/v2/users`
- `parse_request` action: if not admin and `isset( $_GET['author'] )` and digit-only after sanitize → `wp_safe_redirect( home_url( '/' ), 301 ); exit;`

- [ ] **Step 2: Implement version**

- `add_filter( 'the_generator', '__return_empty_string' );`
- `remove_action( 'wp_head', 'wp_generator' );` when toggle on (call from init)
- `style_loader_src` / `script_loader_src`: if `ver` query present, `remove_query_arg( 'ver', $src )`

- [ ] **Step 3: Implement registration**

```php
add_filter( 'pre_option_users_can_register', array( __CLASS__, 'force_registration_closed' ) );
// return '0';
```

- [ ] **Step 4: Implement app_passwords**

```php
add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_app_passwords_user' ), 10, 2 );
```

Return false unless `$user` has `manage_options`. If `$user` is not a WP_User, return `$available` unchanged or false safely.

- [ ] **Step 5: Implement probe()**

Return short English strings, escaped later by admin. Examples:
- headers off → `Off`; on → `Enabled for responses`
- file_editor: if defined and true and desired → `Blocked`; if defined and true and !desired → `Blocked by host/wp-config`; if !defined → `Editor allowed`
- xmlrpc: `apply_filters( 'xmlrpc_enabled', true )` is false → `Disabled` else `Enabled`
- registration: `(string) get_option( 'users_can_register' ) === '0'` → `Closed` else `Open`
- etc.

- [ ] **Step 6: Smoke**

Toggle registration on via `Karetaker_Settings::update`; `wp eval 'echo get_option("users_can_register");'` → `0`. Toggle off → may show 1 again if DB was 1.

- [ ] **Step 7 (optional commit):** `feat: complete Harden toggle filters and probes`

---

### Task 3: Harden admin tab + save handler

**Files:**
- Modify: `includes/class-admin.php`

**Interfaces:**
- Consumes: `Karetaker_Harden::KEYS`, `desired()`, `probe()`
- Produces: tab `harden`; `admin_post_karetaker_save_harden`

- [ ] **Step 1: Add tab**

```php
const TABS = array( 'overview', 'activity', 'harden', 'settings' );
```

Add label Harden; branch `render_harden()` in `render_page()`.

- [ ] **Step 2: Register save**

In `init()`:

```php
add_action( 'admin_post_karetaker_save_harden', array( __CLASS__, 'handle_save_harden' ) );
```

- [ ] **Step 3: render_harden()**

Form `method=post` `action=admin-post.php`:
- hidden `action=karetaker_save_harden`
- `wp_nonce_field( 'karetaker_save_harden' )`
- table: foreach KEYS — checkbox `harden[key]`, help text, Live now = `esc_html( Karetaker_Harden::probe( $key ) )`
- If `karetaker_is_disabled()` show error notice (also note: if disabled, Harden class never inits — probe may need to work from desired settings alone; **if kill switch on, boot returns before Harden::init**, so require Harden class always for probes OR show “plugin disabled” only). Prefer: always `require_once class-harden.php` at top level next to other requires (like Guard), call `init()` only inside boot when not disabled. Then admin can still read desired + explain inactive.

**Load-order fix (do this in Task 1 if not already):** `require_once class-harden.php` with other requires at file scope; `Karetaker_Harden::init()` only inside `karetaker_boot()` when not disabled.

- [ ] **Step 4: handle_save_harden()**

```php
if ( ! current_user_can( 'manage_options' ) ) { wp_die( ... ); }
check_admin_referer( 'karetaker_save_harden' );
$posted = isset( $_POST['harden'] ) && is_array( $_POST['harden'] ) ? wp_unslash( $_POST['harden'] ) : array();
$next = Karetaker_Harden::defaults();
foreach ( Karetaker_Harden::KEYS as $key ) {
	$next[ $key ] = ! empty( $posted[ $key ] );
}
$prev = Karetaker_Settings::harden();
foreach ( Karetaker_Harden::KEYS as $key ) {
	if ( (bool) $prev[ $key ] !== (bool) $next[ $key ] ) {
		Karetaker_Events::record(
			'setting_changed',
			array(
				'option' => 'harden.' . $key,
				'value'  => $next[ $key ] ? '1' : '0',
			)
		);
	}
}
Karetaker_Settings::update( array( 'harden' => $next ) );
wp_safe_redirect( admin_url( 'tools.php?page=karetaker&tab=harden&updated=1' ) );
exit;
```

Reuse or mirror settings updated notice for `updated=1` on harden tab.

- [ ] **Step 5: Browser/CLI smoke**

Open Harden tab; enable xmlrpc; confirm Live now; disable.

- [ ] **Step 6 (optional commit):** `feat: add Karetaker Harden admin tab`

---

### Task 4: CLI status + CODE-NOTES

**Files:**
- Modify: `includes/class-cli.php`
- Modify: `CODE-NOTES.md`

- [ ] **Step 1: Extend `status()` JSON**

Add:

```php
'harden' => array(
	'desired' => Karetaker_Settings::harden(),
),
```

- [ ] **Step 2: CODE-NOTES section for `class-harden.php`**

Document: defaults off; no CSP; registration via `pre_option_*` not DB write; `DISALLOW_FILE_EDIT` only if undefined; idempotent with spice-hardening; probes are derived.

- [ ] **Step 3: Kill-switch check**

With `wp-content/karetaker-disable` present, harden filters must not register (boot returns early). Remove file after.

- [ ] **Step 4 (optional commit):** `docs: note Harden implementation decisions`

---

## Spec coverage

| Spec item | Task |
|---|---|
| Seven toggles + defaults off | 1–2 |
| No CSP / no server writes | 1–2 |
| Probes | 2 |
| Harden tab + own save | 3 |
| setting_changed on toggle | 3 |
| status JSON harden.desired | 4 |
| CODE-NOTES | 4 |
| Kill switch | 4 (verify) |

## Plan self-review

- Explicit require-at-file-scope vs init-in-boot for Harden so admin probes work under kill switch messaging.
- Commits optional.
- No login throttle / CSP / agency REST.

---

**Plan complete and saved to `docs/superpowers/plans/2026-09-13-karetaker-harden.md`.**

Two execution options:

1. **Subagent-Driven (recommended)** — fresh subagent per task  
2. **Inline Execution** — this session with checkpoints  

Which approach?
