# Karetaker Guard + Tell Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Guard (four catastrophe checks) and Tell (ACT emails + Tools → Karetaker admin UI) so stages 1–4 of `docs/design.md` form a useful product.

**Architecture:** Guard runs on cron/hooks and records `guard_tripped` only on good→bad transitions. `Karetaker_Events::record()` fires `karetaker_event_recorded`; Alerts sends one deduped email per ACT. Admin is plain PHP under Tools with Overview, Activity (`WP_List_Table`), and Settings.

**Tech Stack:** WordPress 6.2+, PHP 7.4+, Settings API, `WP_List_Table`, WP-Cron, WP-CLI, `wp_mail`. No build step, no React, no Composer required for this slice.

**Spec:** `docs/superpowers/specs/2026-09-13-karetaker-guard-tell-design.md`  
**Repo:** `~/karetaker` only: never modify `~/Local Sites/spice-web-media` (symlink only).

## Global Constraints

- Prefix everything `karetaker_` / `Karetaker_` / `KARETAKER_`.
- Every PHP file starts with `ABSPATH` (or `WP_UNINSTALL_PLUGIN`) guard.
- Zero front-end CSS/JS; admin CSS only if unavoidable and tiny.
- Front-end ordinary page load: 0 Karetaker DB queries / 0 writes from new code.
- Never write `wp-config.php`, `.htaccess`, or server config; no lockouts.
- Kill switch (`karetaker_is_disabled()`) suppresses recording and alerts.
- Escape every log field on output; nonce + `manage_options` on every admin state change.
- Commits: only when the human asks (do not auto-commit). Mark commit steps as optional.

---

## File map

| File | Responsibility |
|---|---|
| `includes/class-guard.php` | Four checks, state transitions, hooks + `scan()` |
| `includes/class-alerts.php` | ACT email + 24h dedupe |
| `includes/class-admin.php` | Menu, overview, settings |
| `includes/class-list-table.php` | Activity log table |
| `includes/class-events.php` | Fire `karetaker_event_recorded` after insert |
| `includes/class-scanner.php` | Call Guard in scan loop |
| `karetaker.php` | Require + boot new classes |
| `uninstall.php` | Delete `karetaker_scan_state` (and keep settings/table cleanup) |
| `CODE-NOTES.md` | Guard/Tell rationale |

---

### Task 1: Event action for Tell

**Files:**
- Modify: `includes/class-events.php` (`record()` after successful insert)
- Test: WP-CLI `wp karetaker emit` + temporary listener (removed after verify)

**Interfaces:**
- Consumes: existing `Karetaker_Events::record( $code, array $context = array(), $user_id = null ): int`
- Produces: `do_action( 'karetaker_event_recorded', int $id, string $code, int $severity, array $context )` only when `$id > 0`

- [ ] **Step 1: Add the action at the end of a successful `record()`**

After `$id = (int) $wpdb->insert_id;` and the trim block, before `return $id`:

```php
$severity = self::severity_for( $code );
$context  = self::sanitize_context( $context );

do_action( 'karetaker_event_recorded', $id, $code, $severity, $context );

return $id;
```

Note: `$context` was already sanitized for insert: pass the same sanitized array (re-sanitize is idempotent). Avoid double-encoding; use the array passed to `wp_json_encode`, not the JSON string.

Refactor the insert block so sanitized context is in a variable once:

```php
$clean_context = self::sanitize_context( $context );
$severity      = self::severity_for( $code );

// .. insert using $clean_context and $severity ...

do_action( 'karetaker_event_recorded', $id, $code, $severity, $clean_context );
```

- [ ] **Step 2: Verify the action fires**

In a one-off mu-plugin or `wp eval`:

```php
add_action( 'karetaker_event_recorded', function ( $id, $code, $severity, $context ) {
	WP_CLI::log( "fired:$id:$code:$severity" );
}, 10, 4 );
```

Run (from Local WP path, plugin active via symlink):

```bash
wp karetaker emit scan_ran
```

Expected: line containing `fired:` and a positive id; event still appears in `wp karetaker log`.

- [ ] **Step 3: Remove the temporary listener**

- [ ] **Step 4 (optional commit):** `feat: fire karetaker_event_recorded after event insert`

---

### Task 2: Guard class: state machine + scan checks

**Files:**
- Create: `includes/class-guard.php`
- Modify: `karetaker.php` (require file; call `Karetaker_Guard::init()` from `karetaker_boot`)
- Modify: `includes/class-scanner.php` (`run()` loop)
- Modify: `uninstall.php` (delete `karetaker_scan_state`)

**Interfaces:**
- Consumes: `Karetaker_Events::record`, `Karetaker_Scanner::state` / `save_state` pattern (Guard receives `&$state`)
- Produces:
  - `Karetaker_Guard::init(): void`
  - `Karetaker_Guard::scan( array &$state ): string`: return status like `tripped:1` / `clean`
  - `Karetaker_Guard::evaluate( string $check, bool $is_bad, array $context, array &$state ): bool`: returns true if a new event was recorded

- [ ] **Step 1: Create `includes/class-guard.php`**

```php
<?php
/**
 * One-checkbox catastrophe checks.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Guard {

	const CHECKS = array( 'blog_public', 'mail_failed', 'admin_email_invalid', 'no_administrator' );

	public static function init() {
		add_action( 'update_option_blog_public', array( __CLASS__, 'on_blog_public' ), 10, 2 );
		add_action( 'update_option_admin_email', array( __CLASS__, 'on_admin_email_option' ), 10, 2 );
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ), 10, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'on_user_capability_change' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'on_user_capability_change_add' ), 20, 2 );
		add_action( 'remove_user_role', array( __CLASS__, 'on_user_capability_change_remove' ), 20, 2 );
		add_action( 'deleted_user', array( __CLASS__, 'on_user_deleted' ), 20, 3 );
	}

	public static function scan( array &$state ) {
		$tripped = 0;

		if ( self::evaluate( 'blog_public', self::is_blog_public_bad(), array(), $state ) ) {
			++$tripped;
		}
		if ( self::evaluate( 'admin_email_invalid', self::is_admin_email_bad(), array(), $state ) ) {
			++$tripped;
		}
		if ( self::evaluate( 'no_administrator', self::administrator_count() < 1, array( 'count' => self::administrator_count() ), $state ) ) {
			++$tripped;
		}

		// mail_failed is hook-driven; scan only reports last known state.
		$guard = isset( $state['guard'] ) && is_array( $state['guard'] ) ? $state['guard'] : array();
		$mail  = ! empty( $guard['mail_failed']['bad'] );

		return $tripped ? ( 'tripped:' . $tripped ) : ( $mail ? 'clean:mail_still_bad' : 'clean' );
	}

	public static function evaluate( $check, $is_bad, array $context, array &$state ) {
		$check = sanitize_key( $check );
		if ( ! in_array( $check, self::CHECKS, true ) ) {
			return false;
		}

		if ( ! isset( $state['guard'] ) || ! is_array( $state['guard'] ) ) {
			$state['guard'] = array();
		}

		$prev     = isset( $state['guard'][ $check ] ) && is_array( $state['guard'][ $check ] ) ? $state['guard'][ $check ] : array();
		$was_bad  = ! empty( $prev['bad'] );
		$is_bad   = (bool) $is_bad;
		$recorded = false;

		if ( $is_bad && ! $was_bad ) {
			$ctx = array_merge( array( 'check' => $check ), $context );
			Karetaker_Events::record( 'guard_tripped', $ctx, 0 );
			$recorded = true;
		}

		$state['guard'][ $check ] = array(
			'bad'   => $is_bad,
			'since' => $is_bad ? ( $was_bad && ! empty( $prev['since'] ) ? $prev['since'] : current_time( 'mysql', true ) ) : null,
		);

		return $recorded;
	}

	public static function is_blog_public_bad() {
		return '0' === (string) get_option( 'blog_public' );
	}

	public static function is_admin_email_bad() {
		$email = (string) get_option( 'admin_email' );
		return '' === $email || ! is_email( $email );
	}

	public static function administrator_count() {
		$users = get_users(
			array(
				'capability' => 'manage_options',
				'fields'     => 'ID',
				'number'     => 2,
			)
		);
		return is_array( $users ) ? count( $users ) : 0;
	}

	public static function on_blog_public( $old, $new ) {
		$state = Karetaker_Scanner::state();
		self::evaluate( 'blog_public', '0' === (string) $new, array( 'old' => (string) $old, 'new' => (string) $new ), $state );
		Karetaker_Scanner::save_state( $state );
	}

	public static function on_admin_email_option( $old, $new ) {
		$state = Karetaker_Scanner::state();
		$bad   = '' === (string) $new || ! is_email( (string) $new );
		self::evaluate( 'admin_email_invalid', $bad, array(), $state );
		Karetaker_Scanner::save_state( $state );
	}

	public static function on_mail_failed( $error ) {
		$message = '';
		if ( is_wp_error( $error ) ) {
			$message = $error->get_error_message();
		}
		$state = Karetaker_Scanner::state();
		self::evaluate(
			'mail_failed',
			true,
			array( 'error' => sanitize_text_field( substr( (string) $message, 0, 200 ) ) ),
			$state
		);
		Karetaker_Scanner::save_state( $state );
	}

	public static function maybe_check_administrators() {
		$state = Karetaker_Scanner::state();
		$count = self::administrator_count();
		self::evaluate( 'no_administrator', $count < 1, array( 'count' => $count ), $state );
		Karetaker_Scanner::save_state( $state );
	}

	public static function on_user_capability_change( $user_id, $role, $old_roles ) {
		self::maybe_check_administrators();
	}

	public static function on_user_capability_change_add( $user_id, $role ) {
		self::maybe_check_administrators();
	}

	public static function on_user_capability_change_remove( $user_id, $role ) {
		self::maybe_check_administrators();
	}

	public static function on_user_deleted( $user_id, $reassign, $user = null ) {
		self::maybe_check_administrators();
	}
}
```

Fix `administrator_count()` if WP version lacks `capability` in `get_users` on very old installs: site requires 6.2+, and `capability` arg exists since 5.9. Keep as written.

Avoid calling `administrator_count()` twice in `scan()` for `no_administrator`: compute once:

```php
$count = self::administrator_count();
if ( self::evaluate( 'no_administrator', $count < 1, array( 'count' => $count ), $state ) ) {
	++$tripped;
}
```

- [ ] **Step 2: Require and init in `karetaker.php`**

After other requires:

```php
require_once KARETAKER_DIR . 'includes/class-guard.php';
```

Inside `karetaker_boot()` after hooks:

```php
Karetaker_Guard::init();
```

- [ ] **Step 3: Wire scanner**

In `Karetaker_Scanner::run()`, add `'guard'` to the foreach list (after `options` or before `checksums`):

```php
foreach ( array( 'muplugins', 'uploads', 'cron', 'options', 'guard', 'checksums' ) as $scan ) {
```

Add method:

```php
public static function scan_guard( &$state ) {
	return Karetaker_Guard::scan( $state );
}
```

- [ ] **Step 4: Uninstall scan state**

In `uninstall.php` after other deletes:

```php
delete_option( 'karetaker_scan_state' );
```

- [ ] **Step 5: Manual verify Guard**

```bash
wp option get blog_public
wp option update blog_public 0
wp karetaker log --code=guard_tripped --limit=5
wp option update blog_public 1
wp option update blog_public 0
wp karetaker log --code=guard_tripped --limit=5
```

Expected: first flip to `0` records one event; after clear to `1` then `0` again, a second event (transition). Two rapid updates while still bad must not spam.

- [ ] **Step 6 (optional commit):** `feat: add Guard catastrophe checks`

---

### Task 3: Alerts (Tell push)

**Files:**
- Create: `includes/class-alerts.php`
- Modify: `karetaker.php` (require + `Karetaker_Alerts::init()` in boot)

**Interfaces:**
- Consumes: `karetaker_event_recorded`, `Karetaker_Settings::alert_email()`, `alerts_enabled`, `karetaker_is_disabled()`
- Produces: `Karetaker_Alerts::init(): void`, `Karetaker_Alerts::maybe_send( int $id, string $code, int $severity, array $context ): bool`

- [ ] **Step 1: Create `includes/class-alerts.php`**

```php
<?php
/**
 * Scarce ACT-now email alerts.
 *
 * @package Karetaker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Karetaker_Alerts {

	const DEDUPE_TTL = DAY_IN_SECONDS;

	public static function init() {
		add_action( 'karetaker_event_recorded', array( __CLASS__, 'maybe_send' ), 10, 4 );
	}

	public static function maybe_send( $id, $code, $severity, $context ) {
		if ( karetaker_is_disabled() ) {
			return false;
		}

		if ( (int) $severity !== Karetaker_Events::SEVERITY_ACT ) {
			return false;
		}

		if ( ! Karetaker_Settings::get( 'alerts_enabled' ) ) {
			return false;
		}

		$code    = sanitize_key( $code );
		$context = is_array( $context ) ? $context : array();
		$key     = self::dedupe_key( $code, $context );

		if ( get_transient( $key ) ) {
			return false;
		}

		$to = Karetaker_Settings::alert_email();
		if ( ! is_email( $to ) ) {
			return false;
		}

		$subject = self::subject_for( $code, $context );
		$body    = self::body_for( $code, $context, (int) $id );

		$sent = wp_mail( $to, $subject, $body );

		if ( $sent ) {
			set_transient( $key, 1, self::DEDUPE_TTL );
		}

		return (bool) $sent;
	}

	public static function dedupe_key( $code, array $context ) {
		ksort( $context );
		return 'karetaker_alert_' . md5( $code . '|' . wp_json_encode( $context ) );
	}

	public static function subject_for( $code, array $context ) {
		$check = isset( $context['check'] ) ? (string) $context['check'] : '';

		$map = array(
			'admin_user_added'    => 'A new administrator account appeared',
			'role_escalated'      => 'A user was made administrator',
			'registration_opened' => 'Anyone can register on your site',
			'muplugin_changed'    => 'A must-use plugin file changed',
			'uploads_php_found'   => 'Executable PHP appeared under uploads',
			'file_hash_mismatch'  => 'A plugin or theme no longer matches wordpress.org',
			'guard_tripped'       => self::guard_subject( $check ),
		);

		$verdict = isset( $map[ $code ] ) ? $map[ $code ] : 'Something needs your attention';

		return '[Karetaker] ' . $verdict;
	}

	private static function guard_subject( $check ) {
		$map = array(
			'blog_public'          => 'Search engines were discouraged',
			'mail_failed'          => 'WordPress could not send email',
			'admin_email_invalid'  => 'The admin email address is invalid',
			'no_administrator'     => 'No administrator remains on the site',
		);
		return isset( $map[ $check ] ) ? $map[ $check ] : 'A site setting needs attention';
	}

	public static function body_for( $code, array $context, $id ) {
		$home = home_url( '/' );
		$tools = admin_url( 'tools.php?page=karetaker' );

		$lines   = array();
		$lines[] = self::subject_for( $code, $context );
		$lines[] = '';
		$lines[] = 'Site: ' . $home;
		$lines[] = 'Event: ' . $code . ' (#' . (int) $id . ')';
		if ( $context ) {
			$lines[] = 'Details: ' . wp_json_encode( $context );
		}
		$lines[] = '';
		$lines[] = 'Open Karetaker: ' . $tools;
		$lines[] = '';
		$lines[] = 'To stop these emails, open that page → Settings and turn off Enable alerts.';

		return implode( "\n", $lines );
	}
}
```

- [ ] **Step 2: Boot alerts in `karetaker.php`**

```php
require_once KARETAKER_DIR . 'includes/class-alerts.php';
// in karetaker_boot:
Karetaker_Alerts::init();
```

- [ ] **Step 3: Verify email path without spamming**

Use `wp eval` to short-circuit `wp_mail` or check mail debugger. Minimal check:

```bash
wp eval 'update_option("karetaker_settings", array_merge(Karetaker_Settings::all(), array("alerts_enabled"=>true,"alert_email"=>"you@example.com")), false);'
wp karetaker emit admin_user_added
wp karetaker emit admin_user_added
```

Expected: first emit attempts mail once; second suppressed by transient (same context `{source:cli}` from emit: emit uses `array( 'source' => 'cli' )`). Confirm with a counter filter:

```php
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$GLOBALS['karetaker_mail_count'] = 1 + (int) ( $GLOBALS['karetaker_mail_count'] ?? 0 );
	return false; // block real send during test
}, 10, 2 );
```

Expected count `1` after two identical ACT emits.

- [ ] **Step 4 (optional commit):** `feat: email ACT events with 24h dedupe`

---

### Task 4: Admin shell: menu, overview, settings

**Files:**
- Create: `includes/class-admin.php`
- Modify: `karetaker.php`

**Interfaces:**
- Consumes: `Karetaker_Scanner::state()`, `Karetaker_Events::query()`, `Karetaker_Settings::*`, `Karetaker_Schema::count()`
- Produces: Tools page slug `karetaker`; tabs `overview|activity|settings`

- [ ] **Step 1: Create admin class with menu + settings save**

Implement:

- `admin_menu` → `add_management_page( 'Karetaker', 'Karetaker', 'manage_options', 'karetaker', … )`
- Tabs via `$_GET['tab']` sanitized
- Overview: last scan from state, guard flags, ACT count this week (`query` with `min_severity` => 2 and `since` => gmdate week ago: add `since` support already in `query()`)
- Settings form: `alert_email`, `alerts_enabled` checkbox, `row_cap` number; save via `admin_post_karetaker_save_settings` with `check_admin_referer( 'karetaker_save_settings' )` and `current_user_can( 'manage_options' )`; sanitize email with `sanitize_email`, row_cap cast + clamp via `Karetaker_Settings::row_cap` after update

Trusted proxies fields can be a textarea of CIDRs (one per line) stored as array: sanitize each line.

- [ ] **Step 2: Require + `Karetaker_Admin::init()` on `admin_menu` / `admin_init` only**

```php
if ( is_admin() ) {
	require_once KARETAKER_DIR . 'includes/class-admin.php';
	Karetaker_Admin::init();
}
```

Do this inside `karetaker_boot` or a dedicated `admin_init` load so front-end never loads admin classes: prefer:

```php
add_action( 'admin_menu', … ) // registered from boot is fine; class file only required when `is_admin()`
```

Pattern:

```php
function karetaker_boot() {
	if ( karetaker_is_disabled() ) {
		return;
	}
	Karetaker_Events::init();
	Karetaker_Hooks::init();
	Karetaker_Guard::init();
	Karetaker_Alerts::init();
	Karetaker_Scanner::init();

	if ( is_admin() ) {
		require_once KARETAKER_DIR . 'includes/class-admin.php';
		require_once KARETAKER_DIR . 'includes/class-list-table.php';
		Karetaker_Admin::init();
	}
}
```

(List table file may be empty stub until Task 5, or create list table in Task 5 and only require it there.)

- [ ] **Step 3: Verify in browser**

Open `/wp-admin/tools.php?page=karetaker` as admin. Expected: overview renders, settings save persists `alert_email`.

- [ ] **Step 4 (optional commit):** `feat: add Karetaker Tools admin overview and settings`

---

### Task 5: Activity list table

**Files:**
- Create: `includes/class-list-table.php`
- Modify: `includes/class-admin.php` (activity tab)

**Interfaces:**
- Consumes: `Karetaker_Events::query( array $args )`
- Produces: `Karetaker_List_Table` extending `WP_List_Table`

- [ ] **Step 1: Create list table**

Require `WP_List_Table` from `ABSPATH . 'wp-admin/includes/class-wp-list-table.php'` if not loaded.

Columns: `event_time`, `severity`, `event_code`, `user_id`, `ip_display`, `context`: all escaped with `esc_html`. Context as JSON string escaped. No unescaped HTML.

Prepare items using query limit/offset from pagination; total via `Karetaker_Schema::count()` or a count query if filtering (acceptable to use count for unfiltered and page size 20).

- [ ] **Step 2: Render on Activity tab**

- [ ] **Step 3: XSS check**

```bash
wp karetaker emit guard_tripped
```

Or record with context containing `<script>alert(1)</script>` via `wp eval` calling `Karetaker_Events::record( 'guard_tripped', array( 'check' => 'blog_public', 'x' => '<script>alert(1)</script>' ) )`. Open Activity tab: Expected: script tags visible as text, not executed.

- [ ] **Step 4 (optional commit):** `feat: add Karetaker activity log list table`

---

### Task 6: Docs + front-end budget check

**Files:**
- Modify: `CODE-NOTES.md`
- Modify: `docs/design.md` only if a one-line “stages 3–4 landed” note is wanted (optional)

- [ ] **Step 1: Append CODE-NOTES sections** for `class-guard.php`, `class-alerts.php`, `class-admin.php` covering: transition-only trips, `wp_mail_failed` only, dedupe transient, Tools menu choice, no front-end assets, event action for alerts.

- [ ] **Step 2: Front-end query budget**

Load a public page with Query Monitor or:

```bash
wp eval '
define("SAVEQUERIES", true);
// not ideal in eval: prefer browser QM
'
```

Manual: open home as logged-out; confirm no queries against `wp_karetaker_events` and no writes.

- [ ] **Step 3: Kill switch**

```bash
wp eval 'file_put_contents(WP_CONTENT_DIR."/karetaker-disable","");'
wp karetaker emit admin_user_added
```

Expected, not recorded / error from CLI. Remove disable file after.

- [ ] **Step 4 (optional commit):** `docs: note Guard and Tell implementation decisions`

---

## Spec coverage checklist

| Spec requirement | Task |
|---|---|
| `guard_tripped` + four checks | 2 |
| Transition-only + scan state | 2 |
| `wp_mail_failed` only for mail | 2 |
| `karetaker_event_recorded` | 1 |
| ACT emails + 24h dedupe | 3 |
| Unsubscribe via settings copy | 3 + 4 |
| Tools → Karetaker overview/settings | 4 |
| WP_List_Table activity + escape | 5 |
| Uninstall scan state | 2 |
| CODE-NOTES | 6 |
| Front-end 0 queries | 6 |
| Harden / agency REST / readme.txt | explicitly out of scope |

## Plan self-review

- No TBD placeholders remain.
- Method names consistent: `evaluate`, `scan`, `maybe_send`, `dedupe_key`.
- Admin list table file required only when `is_admin()` to protect front-end autoload cost.

---

**Plan complete and saved to `docs/superpowers/plans/2026-09-13-karetaker-guard-tell.md`.**

Two execution options:

1. **Subagent-Driven (recommended)**: fresh subagent per task, review between tasks  
2. **Inline Execution**: this session, task-by-task with checkpoints  

Which approach?
