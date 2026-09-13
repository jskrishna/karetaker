# Karetaker Agency Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in, read-only, HMAC-signed REST status endpoint (`GET /wp-json/karetaker/v1/status`) authenticated by a site-local agency token, sharing one snapshot builder with WP-CLI.

**Architecture:** `Karetaker_Status::snapshot()` builds the fact payload. `Karetaker_Agency` registers the GET route, checks Bearer/`token` in `permission_callback`, and attaches `sig`. Admin Settings manages generate/regenerate/clear. Empty token = channel off (403).

**Tech Stack:** WordPress REST API, `hash_hmac` / `hash_equals`, existing Settings/Events/Scanner/Guard/Harden APIs. No Composer JWT libs.

**Spec:** `docs/superpowers/specs/2026-09-13-karetaker-agency-design.md`  
**Repo:** `/Users/jskrishna/karetaker` only.

## Global Constraints

- No inbound control (GET status only).
- Prefix `karetaker_` / `Karetaker_` / `KARETAKER_`; ABSPATH on every PHP file.
- Kill switch skips boot → no route.
- `permission_callback` fails closed; auth not only inside the handler.
- Never log the raw agency token in events.
- Escape admin output; nonce + `manage_options` on token actions.
- Commits only when the human asks.

---

## File map

| File | Role |
|---|---|
| `includes/class-status.php` | `snapshot()` |
| `includes/class-agency.php` | REST + auth + HMAC + token helpers |
| `includes/class-settings.php` | `agency_token` default |
| `includes/class-admin.php` | Settings UI + overview row |
| `includes/class-cli.php` | Use snapshot() |
| `karetaker.php` | Require + init |
| `CODE-NOTES.md` | Rationale |

---

### Task 1: Status snapshot + settings key + CLI refactor

**Files:**
- Create: `includes/class-status.php`
- Modify: `includes/class-settings.php`
- Modify: `includes/class-cli.php`
- Modify: `karetaker.php` (require status only for now, or with agency in Task 2)

**Interfaces:**
- `Karetaker_Status::snapshot(): array` — keys exactly as spec minus `sig`
- Settings default `'agency_token' => ''`
- `Karetaker_Settings::agency_token(): string`

- [ ] **Step 1: Add `agency_token` to `defaults()`**

- [ ] **Step 2: Create `class-status.php`**

```php
public static function snapshot() {
	$state = Karetaker_Scanner::state();
	$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
	// Normalize guard entries to bad/since only.
	$guard_out = array();
	foreach ( $guard as $check => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$guard_out[ sanitize_key( $check ) ] = array(
			'bad'   => ! empty( $entry['bad'] ),
			'since' => ! empty( $entry['since'] ) ? (string) $entry['since'] : null,
		);
	}

	$since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
	$act   = Karetaker_Events::query(
		array(
			'min_severity' => Karetaker_Events::SEVERITY_ACT,
			'since'        => $since,
			'limit'        => 500,
		)
	);

	return array(
		'version'       => KARETAKER_VERSION,
		'site_url'      => home_url( '/' ),
		'disabled'      => karetaker_is_disabled(),
		'generated_at'  => gmdate( 'c' ),
		'events'        => array(
			'count'       => Karetaker_Schema::count(),
			'row_cap'     => Karetaker_Settings::row_cap(),
			'table_bytes' => Karetaker_Schema::size_bytes(),
		),
		'last_scan'     => array(
			'at'      => isset( $state['last_run'] ) ? (string) $state['last_run'] : null,
			'results' => isset( $state['last_results'] ) && is_array( $state['last_results'] ) ? $state['last_results'] : array(),
		),
		'guard'         => $guard_out,
		'act_this_week' => count( $act ),
		'harden'        => array(
			'desired' => Karetaker_Settings::harden(),
		),
	);
}
```

- [ ] **Step 3: CLI `status()` prints `wp_json_encode( Karetaker_Status::snapshot(), JSON_PRETTY_PRINT )`**

Require `class-status.php` from `karetaker.php` at file scope with other includes.

- [ ] **Step 4: Verify**

```bash
wp karetaker status
```

Expected: JSON with `version`, `harden.desired`, `guard`, no `sig`.

- [ ] **Step 5 (optional commit):** `feat: extract shared Karetaker status snapshot`

---

### Task 2: Agency REST route (auth + HMAC)

**Files:**
- Create: `includes/class-agency.php`
- Modify: `karetaker.php`

**Interfaces:**
- `Karetaker_Agency::init(): void`
- `Karetaker_Agency::permission_check(): bool|WP_Error`
- `Karetaker_Agency::get_status(): WP_REST_Response`
- `Karetaker_Agency::request_token(): string` — from Bearer or `token` query
- `Karetaker_Agency::sign( array $payload, string $token ): string`
- `Karetaker_Agency::canonical_json( array $data ): string` — recursive ksort + wp_json_encode

- [ ] **Step 1: Implement recursive ksort + HMAC**

```php
public static function ksort_recursive( &$arr ) {
	if ( ! is_array( $arr ) ) {
		return;
	}
	ksort( $arr );
	foreach ( $arr as &$v ) {
		if ( is_array( $v ) ) {
			self::ksort_recursive( $v );
		}
	}
}

public static function canonical_json( array $data ) {
	self::ksort_recursive( $data );
	return wp_json_encode( $data );
}

public static function sign( array $payload, $token ) {
	return hash_hmac( 'sha256', self::canonical_json( $payload ), (string) $token );
}
```

- [ ] **Step 2: permission_check**

```php
$token = (string) Karetaker_Settings::get( 'agency_token' );
if ( '' === $token ) {
	return false;
}
$provided = self::request_token();
if ( '' === $provided || ! hash_equals( $token, $provided ) ) {
	return false;
}
return true;
```

Extract Bearer: if `Authorization` header matches `/^Bearer\s+(\S+)$/i`, use capture; else `isset( $_GET['token'] )` sanitized as plain string (do not use `sanitize_key` — it would mangle the password). Use `sanitize_text_field( wp_unslash( ... ) )` or raw unslash with length cap.

- [ ] **Step 3: register_rest_route on `rest_api_init`**

```php
register_rest_route(
	'karetaker/v1',
	'/status',
	array(
		'methods'             => 'GET',
		'callback'            => array( __CLASS__, 'get_status' ),
		'permission_callback' => array( __CLASS__, 'permission_check' ),
	)
);
```

- [ ] **Step 4: get_status**

```php
$payload = Karetaker_Status::snapshot();
$token   = (string) Karetaker_Settings::get( 'agency_token' );
$payload['sig'] = self::sign( $payload, $token );
return rest_ensure_response( $payload );
```

Sign **before** adding `sig` (payload must not include sig in HMAC input).

- [ ] **Step 5: Boot**

```php
require_once KARETAKER_DIR . 'includes/class-agency.php';
// in karetaker_boot:
Karetaker_Agency::init();
```

- [ ] **Step 6: Manual REST tests**

```bash
# empty token
curl -s -o /dev/null -w '%{http_code}' http://SITE/wp-json/karetaker/v1/status
# expect 401 or 403

wp eval 'Karetaker_Settings::update(array("agency_token"=>"test-token-for-local-only-32chars!!"));'
curl -s -H 'Authorization: Bearer test-token-for-local-only-32chars!!' http://SITE/wp-json/karetaker/v1/status | head
# clear token after
wp eval 'Karetaker_Settings::update(array("agency_token"=>""));'
```

Verify `sig` present; recompute HMAC offline matches.

- [ ] **Step 7 (optional commit):** `feat: add signed agency status REST endpoint`

---

### Task 3: Admin token UI + overview

**Files:**
- Modify: `includes/class-admin.php`
- Optionally helpers on `Karetaker_Agency::generate_token()`, `mask_token()`

- [ ] **Step 1: Token helpers on Agency**

```php
public static function generate_token() {
	return wp_generate_password( 48, false, false );
}
public static function mask_token( $token ) {
	$token = (string) $token;
	if ( strlen( $token ) < 4 ) {
		return '••••';
	}
	return '••••' . substr( $token, -4 );
}
```

- [ ] **Step 2: admin_post handlers**

Three actions (or one with `karetaker_agency_action` field):
- `karetaker_agency_generate`
- `karetaker_agency_regenerate`
- `karetaker_agency_clear`

Each: `manage_options` + `check_admin_referer( 'karetaker_agency_token' )`.

Generate/regenerate: create token, `Settings::update`, `Events::record( 'setting_changed', array( 'option' => 'agency_token', 'value' => 'generated'|'regenerated' ) )`, store plaintext once in a **user meta transient** or `set_transient( 'karetaker_agency_token_once_' . get_current_user_id(), $token, 60 )` for the notice — **do not** put token in the redirect URL.

Clear: set `''`, log `value=cleared`.

- [ ] **Step 3: Settings section UI**

In `render_settings()`, Agency channel block with forms/buttons as spec.

- [ ] **Step 4: Notice**

On `admin_notices`, if once-transient set, show full token in `<code>` with copy warning; delete transient after display.

- [ ] **Step 5: Overview row**

Agency channel: Off if token empty, else On.

- [ ] **Step 6: Smoke in admin / eval**

Generate → status 200 with Bearer → clear → 403. Confirm log has no raw token:

```bash
wp karetaker log --code=setting_changed --limit=5
```

- [ ] **Step 7 (optional commit):** `feat: admin UI for agency status token`

---

### Task 4: CODE-NOTES + final smoke

**Files:**
- Modify: `CODE-NOTES.md`

- [ ] **Step 1: Document** class-status / class-agency — empty token off; Bearer + query; HMAC canonicalization; no event log on HTTP; never log token; CLI without sig.

- [ ] **Step 2: Kill switch** — with disable file, REST should 404 or not register; remove after.

- [ ] **Step 3 (optional commit):** `docs: note agency status channel`

---

## Spec coverage

| Requirement | Task |
|---|---|
| Shared snapshot | 1 |
| CLI without sig | 1 |
| GET route + permission_callback | 2 |
| Bearer + query token | 2 |
| HMAC sig | 2 |
| Empty token 403 | 2 |
| Admin generate/regen/clear | 3 |
| Never log secret | 3 |
| Overview on/off | 3 |
| CODE-NOTES | 4 |

## Plan self-review

- Token must not use `sanitize_key`.
- HMAC input excludes `sig`.
- No POST routes.

---

**Plan complete and saved to `docs/superpowers/plans/2026-09-13-karetaker-agency.md`.**

Two execution options:

1. **Subagent-Driven (recommended)**  
2. **Inline Execution**

Which approach?
