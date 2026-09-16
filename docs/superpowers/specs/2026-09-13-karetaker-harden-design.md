# Karetaker: Harden slice

Date: 2026-09-13  
Status: approved in conversation (§1 toggles, §2 admin/settings)  
Repo: `~/karetaker` only: do not modify `spice-web-media`  
Parent design: `docs/design.md` (build order stage 5)

## Goal

Ship **reversible, opt-in Harden** toggles so owners can apply a safe subset of hardening with one-click undo and live “active now” facts: without lockouts, SEO damage, or writing server config.

Out of scope: login throttle, CSP, WAF, agency REST, writing `wp-config.php` / `.htaccess`, wp.org `readme.txt`.

## Constraints (inherited)

- Every Harden toggle **defaults OFF** (observation on; blocking/hardening off until opted in).
- Never write `wp-config.php`, `.htaccess`, or server config.
- Nothing that locks anyone out of admin; do not flip `blog_public` (that is Guard).
- Kill switch (`karetaker_is_disabled()`) disables all Harden filters/defines for that boot.
- Prefix `karetaker_`; ABSPATH on every file.
- Front-end: register hooks only for toggles that are on; **0 DB writes** on ordinary front requests; settings read via existing `Karetaker_Settings` cache (`autoload = false` already).
- Idempotent with site mu-plugins such as `spice-hardening` (safe if both run).
- Escape admin output; nonce + `manage_options` on every save.

## Architecture

```
karetaker_settings['harden'][toggle] = bool (desired)
                │
                ▼
     Karetaker_Harden::init()  (on plugins_loaded boot, if not disabled)
                │
                ├── headers / xmlrpc / enum / version / registration / app_passwords  → filters
                └── file_editor → define DISALLOW_FILE_EDIT if not already defined
                │
Admin tab Harden ←── live probe (desired vs active this request)
```

### New / touched files

| File | Role |
|---|---|
| `includes/class-harden.php` | Toggle application + live probes |
| `includes/class-settings.php` | Defaults + sanitize for `harden` array |
| `includes/class-admin.php` | Harden tab + save fields |
| `karetaker.php` | Require + `Karetaker_Harden::init()` in boot |
| `CODE-NOTES.md` | Rationale (no CSP, filter registration, define rules) |

## Toggles

| Key | When ON | Undo |
|---|---|---|
| `headers` | Send: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (geolocation/microphone/camera/payment/usb empty), `Strict-Transport-Security: max-age=15552000` if `is_ssl()` and environment is not `local`. Hook `send_headers` / `login_init` / `admin_init` as needed; respect `headers_sent()`. **No CSP.** | Stop registering header callbacks |
| `xmlrpc` | `xmlrpc_enabled` → false; unset pingback methods; strip `X-Pingback` from `wp_headers` | Remove filters |
| `file_editor` | If `! defined( 'DISALLOW_FILE_EDIT' )`, `define( 'DISALLOW_FILE_EDIT', true )` for this request | Next request without define restores editor unless host/wp-config already defined it: UI must show “forced by host” when constant pre-exists |
| `user_enum` | For logged-out users, remove `/wp/v2/users` routes from `rest_endpoints`; on front `parse_request`, if `?author=` is digit-only → `wp_safe_redirect( home_url( '/' ), 301 )` and exit | Remove filters/actions |
| `version` | Remove generator via `the_generator` / remove `generator` from `wp_head` / strip version from script/style via `style_loader_src` / `script_loader_src` only when query arg is `ver` from core (standard pattern) | Remove filters |
| `registration` | `pre_option_users_can_register` → `0` (do not `update_option`) | Remove filter |
| `app_passwords` | Block creation/use for users lacking `manage_options` via `wp_is_application_passwords_available_for_user` (and related availability filters as appropriate for WP 6.2+) | Remove filters |

## Settings shape

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

- Merge with defaults on read; unknown keys dropped on save.
- Each checkbox change on save logs `setting_changed` with context `option` = `harden.{key}`, `value` = `0` or `1` (only when value actually changes).

## Admin UI

- New tab slug `harden` in `Karetaker_Admin::TABS` order: `overview`, `activity`, `harden`, `settings`.
- Table: label, short help, Desired checkbox, Live now (text from `Karetaker_Harden::probe( $key )`).
- Harden tab has **its own form** posting to `admin_post_karetaker_save_harden` (nonce `karetaker_save_harden`, `manage_options`). Keeps Settings focused on alerts/proxies/row cap; Harden owns only the seven toggles.
- If kill switch active, show notice that Harden is not applying.
- If `file_editor` desired but `DISALLOW_FILE_EDIT` already defined by host, Live now explains host wins; Desired may still be stored for when constant is absent.

## Live probes (`probe`)

Derived each admin render (and optionally CLI), never a stored “protected” flag:

| Key | Probe idea |
|---|---|
| `headers` | Desired on + harden booted → “will send on front responses” (cannot see response headers from admin PHP easily; state “enabled for requests”) |
| `xmlrpc` | `has_filter( 'xmlrpc_enabled' )` from our callback or apply filters and see false |
| `file_editor` | `defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT` |
| `user_enum` | Our rest_endpoints filter present |
| `version` | Our generator filter present |
| `registration` | `(int) get_option( 'users_can_register' ) === 0` while desired (filtered value) |
| `app_passwords` | Our availability filter present |

## Boot order

Inside `karetaker_boot()` after Settings-dependent modules, before or after Guard:

```php
require_once … class-harden.php;
Karetaker_Harden::init(); // no-op hooks if all toggles false
```

`init()` reads harden flags once; for each true flag, registers the corresponding hooks.

## CLI (minimal)

Extend status JSON with `harden: { desired: {…}, … }` **or** add `wp karetaker harden` listing keys and on/off. Required for this slice: at least surface desired flags on existing `wp karetaker status`. Optional enable/disable flags deferred.

## Testing (manual / CLI)

- All toggles default false on fresh settings.
- Enable `xmlrpc` → xmlrpc disabled; disable → restored (unless another plugin also disables).
- Enable `registration` → front behaves as closed; DB raw option may still be 1; Guard must not treat filtered-closed as compromise (already documented in CODE-NOTES).
- Enable `file_editor` without host constant → editor blocked; turn off → editor returns.
- Enable `headers` → response headers on a front request (curl -I).
- Kill switch file → no harden filters registered.
- Spice site with `spice-hardening` active → no fatals; double-disable of xmlrpc is fine.
- Front page: no Karetaker writes; harden adds only filter callbacks when on.

## Success criteria

- Seven toggles work independently, default off, reversible.
- Harden tab shows Desired + Live now.
- No CSP, no server-file writes, no login lockout features.
- CODE-NOTES documents no-CSP and registration-via-filter choices.

## Non-goals reminder

Agency signed endpoint · login rate limit · CSP UI · Harden as default-on profile.
