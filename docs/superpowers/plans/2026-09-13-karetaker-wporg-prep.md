# Karetaker WordPress.org Prep Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Ship `readme.txt` and WordPress-Docs docblocks so Karetaker is directory-submittable on paper.

**Architecture:** Documentation-only change set in `~/karetaker`. No behaviour changes. `Update URI: false` stays until a human removes it at first .org upload.

**Tech Stack:** WordPress plugin `readme.txt` format; PHPDoc / WordPress-Docs (`@param`, `@return`, `@since`, `@package`).

## Global Constraints

- Repo: `~/karetaker` only: never edit `spice-web-media` source trees.
- Stable tag / `KARETAKER_VERSION`: `0.1.0`
- Requires at least: `6.2`; Requires PHP: `7.4`; Tested up to: `7.1`
- Keep `Update URI: false` in `karetaker.php`
- Docblocks describe existing behaviour only; rationale stays in `CODE-NOTES.md`
- Preserve WP-CLI `## OPTIONS` blocks in `class-cli.php`
- Commits only when the human asks

---

### Task 1: `readme.txt`

**Files:**
- Create: `readme.txt`
- Modify: none

**Interfaces:**
- Consumes: product claims from `docs/design.md`; versions from Global Constraints
- Produces: WordPress.org-parsable `readme.txt` at plugin root

- [x] **Step 1: Create `readme.txt`**

```
=== Karetaker ===
Contributors: teamkrikir
Tags: security, monitoring, hardening, integrity, alerts
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Watches a WordPress site for compromise signals and tells the owner only when something needs them.

== Description ==

Karetaker is a **watchtower**, not a wall.

It notices the changes that usually mean compromise (file and option integrity, privilege changes, uploads surprises, cron drift), watches for one-checkbox business catastrophes (search engines off, mail failing, no administrators, invalid admin email), can apply a small set of **opt-in** hardening toggles with clear receipts, and emails the site owner only for act-now events.

It is deliberately **not**:

* a WAF or request firewall
* a malware signature scanner
* a login lockout / hide-login product by default
* a writer of `wp-config.php`, `.htaccess`, or server config

Visibility is pull (Tools → Karetaker, WP-CLI, optional signed REST status). Notification is push (email on ACT-severity events). Hardening is off until you turn each toggle on.

An optional agency status endpoint (`/wp-json/karetaker/v1/status`) is off until you generate a token. The token never grants remote control: status only.

Kill switch: define `KARETAKER_DISABLE` as true, or place an empty file at `wp-content/karetaker-disable`. Uninstall removes the plugin's table, options, and scheduled hooks.

== Installation ==

1. Upload the `karetaker` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate through the Plugins screen.
3. Open Tools → Karetaker to review overview, activity, harden toggles, and settings (alert email, trusted proxies, agency token).

== Frequently Asked Questions ==

= Is this a firewall? =

No. Karetaker watches and alerts. It does not filter HTTP traffic.

= Will it lock me out of wp-login? =

Not by default. There is no login lockout or renamed login URL in the default set. Hardening toggles are opt-in and reversible from Tools → Karetaker → Harden.

= How do I stop it immediately? =

Define `KARETAKER_DISABLE` as true in `wp-config.php`, or create `wp-content/karetaker-disable`. The plugin then boots nothing.

= What does the agency token do? =

It unlocks a read-only JSON status endpoint for monitoring scripts. Empty token = off. Regenerating invalidates the old token. It cannot change settings or run scans remotely.

= Does uninstall leave data behind? =

No. Uninstall drops the events table, plugin options, and cron hooks.

== Changelog ==

= 0.1.0 =
* Initial release: Watch, Guard, Tell, Harden, and optional agency status channel.
```

- [x] **Step 2: Verify headers**

Run: `head -20 /Users/jskrishna/karetaker/readme.txt`  
Expected: Stable tag `0.1.0`, Tested up to `7.1`, Requires at least `6.2`, Requires PHP `7.4`

- [x] **Step 3: Do not commit** unless the human asks

---

### Task 2: Bootstrap + schema + settings + status docblocks

**Files:**
- Modify: `karetaker.php`, `uninstall.php`, `includes/class-schema.php`, `includes/class-settings.php`, `includes/class-status.php`

**Interfaces:**
- Consumes: existing function/method signatures
- Produces: file/class/method PHPDoc with `@param` / `@return` / `@since 0.1.0` where applicable

- [x] **Step 1: Document `karetaker.php` functions**

Add before each function (examples):

```php
/**
 * Whether the kill switch is engaged.
 *
 * @since 0.1.0
 * @return bool
 */
function karetaker_is_disabled() {

/**
 * Activation: install schema and schedule the scanner.
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_activate() {

/**
 * Deactivation: unschedule the scanner (data retained).
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_deactivate() {

/**
 * Boot watchers when the kill switch is off.
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_boot() {

/**
 * Run schema upgrades if needed.
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_maybe_upgrade() {

/**
 * Load the plugin text domain.
 *
 * @since 0.1.0
 * @return void
 */
function karetaker_load_textdomain() {
```

Leave the plugin header block intact; keep `Update URI: false`.

- [x] **Step 2: Document `uninstall.php`**

Expand file docblock only (no functions). Note it deletes schema, settings, cron, and scan options.

- [x] **Step 3: Document `Karetaker_Schema`, `Karetaker_Settings`, `Karetaker_Status`**

For every method: one-line summary, `@param` for each argument with type, `@return` with type, `@since 0.1.0` on public methods. Class docblock + `@since 0.1.0`.

Example for settings:

```php
/**
 * Plugin settings stored in `karetaker_settings`.
 *
 * @since 0.1.0
 * @package Karetaker
 */
class Karetaker_Settings {

	/**
	 * Default settings array.
	 *
	 * @since 0.1.0
	 * @return array<string,mixed>
	 */
	public static function defaults() {
```

- [x] **Step 4: Smoke-check syntax**

Run: `php -l karetaker.php && php -l uninstall.php && php -l includes/class-schema.php && php -l includes/class-settings.php && php -l includes/class-status.php`  
Expected: No syntax errors

---

### Task 3: Events, hooks, checksums, scanner docblocks

**Files:**
- Modify: `includes/class-events.php`, `includes/class-hooks.php`, `includes/class-checksums.php`, `includes/class-scanner.php`

**Interfaces:**
- Same PHPDoc shape as Task 2
- `Karetaker_Events::record` documents the `karetaker_event_recorded` side effect in one sentence max

- [x] **Step 1–4:** Add class + method docblocks for each of the four files (every method listed in `grep -E 'function '` for those files)
- [x] **Step 5:** `php -l` each file: expect no syntax errors

---

### Task 4: Guard, alerts, harden, agency, CLI, admin, list-table docblocks

**Files:**
- Modify: `includes/class-guard.php`, `includes/class-alerts.php`, `includes/class-harden.php`, `includes/class-agency.php`, `includes/class-cli.php`, `includes/class-admin.php`, `includes/class-list-table.php`

**Interfaces:**
- CLI: keep existing WP-CLI markdown docs; add `@param` / `@return` only if missing under those blocks
- Admin handlers: document capability/nonce expectations in one short sentence where relevant

- [x] **Step 1–7:** Docblock each file completely
- [x] **Step 8:** `php -l` all seven files

---

### Task 5: Housekeeping docs

**Files:**
- Modify: `CLAUDE.md`, `CODE-NOTES.md`

- [x] **Step 1:** Update `CLAUDE.md` § Before submission: mark readme + docblocks done; keep checklist: remove `Update URI: false`, Plugin Check/WPCS, re-confirm slug free, screenshots optional
- [x] **Step 2:** Add a short `CODE-NOTES.md` entry under a `readme.txt` / docs heading noting Tested up to 7.1 and that Update URI stays until upload
- [x] **Step 3:** Acceptance grep

Run:

```bash
# Methods still lacking a preceding docblock are rare; spot-check:
rg -n "^\s*(public|private|protected|static).*function " includes/ --glob '*.php' | wc -l
rg -n "@return" includes/ --glob '*.php' | wc -l
test -f readme.txt && grep -E "^(Stable tag|Tested up to|Requires at least|Requires PHP):" readme.txt
grep "Update URI" karetaker.php
```

Expected: `readme.txt` headers match constraints; `Update URI: false` still present; `@return` count in the same ballpark as method count (CLI may have fewer `@return` if void omitted: prefer explicit `@return void`).

---

## Self-review

1. Spec coverage: readme ✓ Task 1; docblocks ✓ Tasks 2–4; hygiene ✓ Task 5; Update URI left alone ✓ Global Constraints.
2. Placeholders: none intentional.
3. Versions consistent: 0.1.0 / 6.2 / 7.4 / 7.1.
