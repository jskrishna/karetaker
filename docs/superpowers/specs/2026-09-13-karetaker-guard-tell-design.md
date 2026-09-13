# Karetaker — Guard + Tell slice

Date: 2026-09-13  
Status: approved in conversation (Guard §1, Tell §2)  
Repo: `~/karetaker` only — do not modify `spice-web-media`  
Parent design: `docs/design.md` (build order stages 3–4)

## Goal

Complete stages **3 Guard** and **4 Tell** so stages 1–4 form a useful product: Watch already records compromise signals; Guard adds one-checkbox catastrophe detection; Tell pushes the six ACT alerts and gives the owner a pull surface (admin page + log).

Out of scope for this slice: Harden, agency signed REST endpoint, WordPress.org `readme.txt`, full docblock backlog, PHPCS CI.

## Constraints (inherited)

- No front-end CSS/JS; admin UI is plain PHP + Settings API + `WP_List_Table`.
- Front-end request path: 0 DB queries / 0 writes from Guard and Tell work (cron, hooks that already fire, admin only).
- Never touch `wp-config.php`, `.htaccess`, or server config.
- No lockouts, no WAF, no score/grade/green tick.
- Prefix `karetaker_`; ABSPATH guard on every file.
- Kill switch (`KARETAKER_DISABLE` / `wp-content/karetaker-disable`) suppresses boot — alerts must not send when disabled.
- Uninstall remains total (new options/cron/hooks cleaned in `uninstall.php`).

## Architecture

```
Watch (existing) ──► Karetaker_Events::record()
Guard (new)      ──► record( 'guard_tripped', { check, … } )
                         │
                         ▼
              do_action( 'karetaker_event_recorded', $id, $code, $severity, $context )
                         │
                         ▼
              Karetaker_Alerts (new) ──► wp_mail if ACT + enabled + not deduped
                         │
Admin (new) ◄────────────┘  (pull: overview, log, settings)
```

### New files

| File | Role |
|---|---|
| `includes/class-guard.php` | Four catastrophe checks; cron + relevant hooks |
| `includes/class-alerts.php` | ACT email sender + dedupe |
| `includes/class-admin.php` | Menu, overview, settings forms |
| `includes/class-list-table.php` | Activity log `WP_List_Table` |

Wire requires from `karetaker.php`; `Karetaker_Admin::init()` on `admin_init` / `admin_menu`; `Karetaker_Alerts::init()` on `plugins_loaded` after Events; Guard invoked from `Karetaker_Scanner::run()` and from hooks listed below.

### Touch existing

- `class-scanner.php` — call `Karetaker_Guard::scan( $state )` in the scan loop; persist guard snapshot in `karetaker_scan_state['guard']`.
- `class-events.php` — fire `karetaker_event_recorded` after successful insert; keep `guard_tripped` as ACT.
- `class-hooks.php` — optional thin hooks for `blog_public` and last-admin (or Guard registers its own hooks in `init()`).
- `class-cli.php` — `wp karetaker scan` already runs full scan; ensure Guard is included; optional `wp karetaker status` fields for last guard checks.
- `uninstall.php` — clear alert dedupe transients pattern if any options added; unschedule nothing new if Guard uses existing `karetaker_scan` hook only. Delete any new options if introduced (prefer reusing `karetaker_settings` / scan state).
- `CODE-NOTES.md` — document Guard mail probe choice and alert dedupe.

## Guard

### Checks

| `check` slug | Condition | When |
|---|---|---|
| `blog_public` | `(string) get_option( 'blog_public' ) === '0'` | Scan + `update_option_blog_public` when new value is `0` |
| `mail_failed` | See mail detection below | On `wp_mail_failed` (preferred) and/or scan note if Site Health mail test failed recently |
| `admin_email_invalid` | `admin_email` empty or `! is_email( $email )` | Scan + after `admin_email` updates |
| `no_administrator` | Zero users with `manage_options` (query via `get_users` / count, admin/cron only) | Scan + after role/delete hooks when count hits 0 |

Single event code: `guard_tripped` (ACT). Context always includes `check` plus minimal detail (`old`/`new` where relevant). Plain language for Tell comes from a map keyed by `check`.

### Dedup / state

Store under `karetaker_scan_state['guard']`:

```
{
  "blog_public": { "bad": true|false, "since": "mysql-utc"|null },
  "mail_failed": { … },
  "admin_email_invalid": { … },
  "no_administrator": { … }
}
```

Record `guard_tripped` only on transition **good → bad** (or first observation of bad). Clearing when good again is silent (optional LOG later; not required this slice).

### Mail detection (explicit choice)

Do **not** send probe emails to the owner or to external sinks.

1. Primary: hook `wp_mail_failed` — when WordPress fails a real send, record Guard trip with `check=mail_failed` and sanitized error message (no credentials, truncate).
2. Secondary (scan): **omit in this slice.** Site Health mail signals are version-fragile; rely on `wp_mail_failed` only until a stable core signal is confirmed.

Do not invent SMTP plugin-specific APIs in v1.

### Last administrator

Use capability `manage_options`, not role slug alone (custom roles). Count must run only in admin/cron/CLI. If count is 0, trip Guard. Hook path: after `remove_user_role` / `deleted_user` / `set_user_role`, re-count; if 0, trip.

## Tell — push (alerts)

### Trigger

`Karetaker_Alerts::init()` listens to `karetaker_event_recorded`. Send mail only when:

- severity === ACT (`Karetaker_Events::SEVERITY_ACT`)
- `Karetaker_Settings::get( 'alerts_enabled' )` is true
- plugin not disabled
- dedupe allows

ACT codes (six product alerts; multiple codes map to the product list):

1. `admin_user_added`
2. `role_escalated`
3. `registration_opened` (and registration/default_role danger already recorded as ACT by Watch hooks — include those ACT codes in the allow-list)
4. `muplugin_changed`, `uploads_php_found`
5. `file_hash_mismatch`
6. `guard_tripped`

Also allow any other code currently mapped to ACT in `Karetaker_Events::codes()` so Guard and Watch stay consistent.

### Dedupe

Transient key: `karetaker_alert_` + md5( `$code . '|' . wp_json_encode( sorted context subset )` ).  
TTL: 24 hours. Same ACT + same material context → one email per window.  
Do not apply a global rate cap that swallows distinct ACT events.

### Message

- To: `Karetaker_Settings::alert_email()`
- Subject: `[Karetaker] {short plain verdict}` — no threat theater
- Body: what happened, why it matters, one concrete action, site URL, link to Tools → Karetaker
- Unsubscribe (this slice): plain-language instruction in the body to turn off **Enable alerts** under Tools → Karetaker → Settings. No one-click signed public link in v1 (avoids a new unauthenticated endpoint). A nonce-gated admin-post handler can be added later if needed.
- Headers: reasonable `From` via `wp_mail` defaults; no bundling of multiple events

### Failure

If `wp_mail` returns false, do not loop; optionally record severity LOG `setting_changed`-style is wrong — skip extra noise unless useful for CLI debug later. Prefer silent fail for this slice.

## Tell — pull (admin)

### Menu

**Tools → Karetaker** (`manage_options`).

### Screens

1. **Overview** — last scan time/results (from scan state), list of watch/guard checks as facts (last run / currently bad), count of ACT events this week, link to log. No score.
2. **Activity** — `WP_List_Table` over `Karetaker_Events::query()`; columns: time, severity, code, user, IP, context (escaped). Filters: severity, code. Pagination.
3. **Settings** — Settings API fields already conceptualized: `alert_email`, `alerts_enabled`, `row_cap`, `trusted_proxies`, `forwarded_header`. Sanitize on save; escape on display.

### Security

- Cap check on every page and action.
- Nonce on every state change.
- Escape every log field on output (attacker-controlled strings in context).
- No `wp_ajax_nopriv_`. No public REST in this slice.

## Build order (implementation)

1. Guard class + scanner/hooks integration + state transitions  
2. `karetaker_event_recorded` action + Alerts class + settings already present  
3. Admin menu + Overview + Settings  
4. List table Activity screen  
5. Update `CODE-NOTES.md` + smoke via WP-CLI / Local symlink  

## Testing (manual / CLI this slice)

- Flip `blog_public` to 0 → one `guard_tripped`; flip back → no spam; flip again → one new event after clear  
- Trigger `wp_mail_failed` (or mock) → mail_failed once per dedupe window for alerts  
- Invalidate admin email → guard trip  
- Remove last admin in a staging copy only — careful  
- ACT event → exactly one email; duplicate record within 24h → no second email  
- Overview + log render escaped context with XSS-like payload in context  
- Front-end HTML page: still 0 Karetaker DB queries (SAVEQUERIES or Query Monitor)  
- Kill switch on → no emails, admin may 404/inactive as today  

## Success criteria

- All four Guard checks can trip and clear without front-end cost  
- All ACT events can email once per dedupe window  
- Owner can open Tools → Karetaker, see facts + log, change alert email / disable alerts  
- Uninstall still removes table, settings, scan state, cron; no further emails  

## Non-goals reminder

Harden toggles · signed agency endpoint · readme.txt · full WPCS docblocks · Action Scheduler.
