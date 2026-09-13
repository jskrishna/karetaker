# Karetaker — Seed alert email on activate

Date: 2026-09-13  
Status: approved in conversation  
Repo: `~/karetaker` only — do not modify `spice-web-media`  
Related: `Karetaker_Settings::alert_email()`, Settings Alerts panel in `class-admin.php`

## Goal

On first activation, bind the site’s WordPress `admin_email` into `karetaker_settings['alert_email']` so ACT alerts have an explicit recipient out of the box. Site admins can change that address later under Tools → Karetaker → Settings.

## Why

Empty `alert_email` already falls back to `admin_email` at send time. That is correct but invisible: Settings shows a blank field, and a stored placeholder (e.g. from a local smoke test) bypasses the fallback and mails the wrong address. Seeding on activate makes the default recipient visible and editable without changing the send-path contract.

## Behaviour

1. **Activate seed** — During `karetaker_activate()`, if the stored `alert_email` is empty **and** `get_option( 'admin_email' )` passes `is_email()`, write that address into `karetaker_settings['alert_email']` (merge with existing settings / defaults; do not wipe other keys).
2. **Never overwrite** — If `alert_email` is already a non-empty string, leave it unchanged on reactivate/upgrade activate.
3. **Settings** — Existing Alert email field remains the override. Admin may set any valid email or clear the field.
4. **Send-path fallback unchanged** — `Karetaker_Settings::alert_email()` continues: empty or invalid stored value → `admin_email`. Safety net when activate could not seed (bad/missing admin email) or the field is cleared later.
5. **No live sync** — Changing WordPress Settings → General → Administration Email Address does **not** rewrite Karetaker’s stored `alert_email`. The bound value is a snapshot until the admin edits Karetaker Settings.

## Implementation sketch

| Touch | Change |
|---|---|
| `includes/class-settings.php` | Add `seed_alert_email()`: read stored settings; if `alert_email` is `''` and admin email is valid, `update_option` with merged array; clear in-request cache. |
| `karetaker.php` `karetaker_activate()` | Call `Karetaker_Settings::seed_alert_email()` after schema/schedule (settings class already loaded by activate path). |
| `includes/class-admin.php` | Shorten/clarify description: field is filled from the site admin email on install; change it here to send elsewhere; blank still uses admin email. |
| `CODE-NOTES.md` | Note activate seed + “no overwrite / no live sync” next to the existing `alert_email()` fallback note. |

No new options, transients, files, or uninstall paths.

## Out of scope

- `muplugin_changed` hash filtering or alert dedupe key changes.
- Auto-updating Karetaker when WP `admin_email` changes.
- Migrating or scrubbing already-stored placeholder addresses on existing installs (admin clears/edits Settings, or empties the field to re-engage fallback; optional later).
- Agency webhook / status email recipients.

## Acceptance

- Fresh activate on a site with a valid `admin_email`: Settings shows that address in Alert email; ACT mail goes there.
- Reactivate after the admin set a custom alert email: custom value preserved.
- Activate when `admin_email` is empty/invalid: `alert_email` stays empty; send path still uses whatever `alert_email()` resolves (may fail `is_email` and skip mail — same as today).
- Clearing Alert email in Settings and saving: stored empty; subsequent alerts use current `admin_email` via fallback.
