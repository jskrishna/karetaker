# Karetaker: Agency channel slice

Date: 2026-09-13  
Status: approved in conversation (§1 auth/payload, §2 files/security)  
Repo: `~/karetaker` only: do not modify `spice-web-media`  
Parent design: `docs/design.md` (build order stage 6: agency channel)

## Goal

Give agencies a **read-only, opt-in, signed HTTP status** they can poll (cron, MainWP-style scripts) without logging into wp-admin and without any remote control surface.

CLI (`wp karetaker status` / `log` / `scan`) already exists; this slice adds the HTTP twin for status only.

Out of scope: remote scan/harden/disable, OAuth, JWT libraries, IP allowlists, rate limiting, dumping the full event log over HTTP, wp.org readme/docblocks.

## Constraints (inherited)

- **No inbound control channel** and no stored agency admin credentials on the site.
- Prefix `karetaker_`; ABSPATH on every file.
- Kill switch disables boot → no REST registration.
- Every REST route has a real `permission_callback` that fails closed; auth is not deferred to the handler alone.
- Never log the raw agency token (or any secret) into `karetaker_events`.
- Escape admin output; nonce + `manage_options` on token generate/regenerate/clear.
- Front HTML pages: unchanged budget (this route is REST-only).
- Commits only when the human asks.

## Architecture

```
Settings: agency_token (empty = off)
        │
        ▼
Karetaker_Agency::init()  →  register_rest_route karetaker/v1/status GET
        │
        permission_callback: Bearer or ?token=  (constant-time)
        │
        ▼
Karetaker_Status::snapshot()  ←── also used by WP-CLI status
        │
        ▼
JSON + sig = HMAC-SHA256( canonical_body_without_sig, token )
```

### Files

| File | Role |
|---|---|
| `includes/class-status.php` | Shared snapshot array builder |
| `includes/class-agency.php` | REST route, auth, HMAC, token helpers |
| `includes/class-settings.php` | `agency_token` default `''` |
| `includes/class-admin.php` | Settings UI for token lifecycle |
| `includes/class-cli.php` | Call `Karetaker_Status::snapshot()` instead of inline array |
| `karetaker.php` | Require + `Karetaker_Agency::init()` in boot |
| `CODE-NOTES.md` | Why Bearer, why no log dump, why HMAC |

## Auth

- **Default off:** empty `agency_token` → `permission_callback` returns false (`403`).
- **Token:** at least 32 bytes of entropy (prefer 48-char `wp_generate_password( 48, false, false )` or `bin2hex( random_bytes( 24 ) )`).
- **Request:** `Authorization: Bearer <token>` preferred; `token` query arg allowed for simple cron.
- **Compare:** `hash_equals( (string) stored, (string) provided )` after extracting Bearer.
- Wrong/missing → false (WP REST maps to 401/403). No hints about whether a token is configured beyond generic forbidden.

## Signing

After building the snapshot array (without `sig`):

1. `ksort_recursive` or build a **canonical JSON** string with stable key order (`wp_json_encode` after recursive `ksort`).
2. `sig = hash_hmac( 'sha256', $canonical, $token )`.
3. Add `sig` to the response object.

Pollers that know the token can verify integrity. Auth already required to fetch; HMAC is defense in depth against tampered caches/proxies.

## Snapshot payload

```json
{
  "version": "0.1.0",
  "site_url": "https://example.com",
  "disabled": false,
  "generated_at": "2026-09-13T00:00:00+00:00",
  "events": { "count": 0, "row_cap": 5000, "table_bytes": 0 },
  "last_scan": { "at": null, "results": {} },
  "guard": {},
  "act_this_week": 0,
  "harden": { "desired": { "headers": false, "…": false } },
  "sig": "…"
}
```

Sources:
- `version` → `KARETAKER_VERSION`
- `site_url` → `home_url( '/' )`
- `disabled` → `karetaker_is_disabled()` (will be false if endpoint reachable via normal boot)
- `generated_at` → `gmdate( 'c' )`
- `events` → `Karetaker_Schema::count()`, `row_cap()`, `size_bytes()`
- `last_scan` → from `Karetaker_Scanner::state()` (`last_run`, `last_results`)
- `guard` → `state['guard']` map of check → `{ bad, since }` (omit secrets; already structured)
- `act_this_week` → count of `Karetaker_Events::query( min_severity ACT, since week ago )` (cap query limit reasonably, e.g. 500, count returned)
- `harden.desired` → `Karetaker_Settings::harden()`

**Do not** include: full event rows, IPs, user emails, raw token, context blobs from the log.

## Admin UI (Settings tab)

- Section **Agency channel**
- If token empty: button **Generate token**
- If token set: masked display (`••••` + last 4), buttons **Regenerate** and **Clear**, copy hint for Bearer header and example `curl`
- After generate/regenerate: one-time admin notice showing full token (user must copy now)
- All actions: `manage_options` + dedicated nonces
- On change: `setting_changed` with `option=agency_token`, `value=generated|regenerated|cleared`: **never** the secret
- Overview: one row “Agency channel: Off | On”

## Boot

```php
require_once … class-status.php;
require_once … class-agency.php;
// in karetaker_boot when not disabled:
Karetaker_Agency::init();
```

`init()` always registers the route; permission_callback enforces token. (Registering when token empty is fine: all callers get 403.)

## CLI

Refactor `Karetaker_CLI::status()` to print `Karetaker_Status::snapshot()` **without** requiring a token, and **without** `sig` (or with sig only when token configured: prefer **no sig on CLI** to keep SSH output simple; HTTP always includes sig when token set).

HTTP path: snapshot + sig.  
CLI path: snapshot only (same fields minus `sig`).

## Testing

- Empty token → GET status → 403
- Generate token → Bearer GET → 200 + JSON fields + valid sig
- Wrong token → 403
- Query `?token=` works with correct secret
- Regenerate → old token 403, new token 200
- Clear → 403
- Kill switch → plugin inactive / route absent
- `wp karetaker status` still works and matches snapshot fields (minus sig)
- Confirm raw token never appears in `wp karetaker log`

## Success criteria

- Opt-in read-only status endpoint with Bearer auth + HMAC
- Shared snapshot with CLI
- Admin can generate/regenerate/clear safely
- No write methods, no event log dump, no secrets in the event table

## Non-goals reminder

Remote actions · OAuth · rate limit · IP allowlist · MainWP official add-on packaging.
