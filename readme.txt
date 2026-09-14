=== Karetaker ===
Contributors: krikir
Tags: security, monitoring, hardening, integrity, alerts
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
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

Visibility is pull (Karetaker admin screen, WP-CLI, optional signed REST status). Notification is push (email on ACT-severity events). Hardening is off until you turn each toggle on.

An optional agency status endpoint (`/wp-json/karetaker/v1/status`) is off until you generate a token. The token never grants remote control — status only.

Kill switch: define `KARETAKER_DISABLE` as true, or place an empty file at `wp-content/karetaker-disable`. Uninstall removes the plugin's table, options, and scheduled hooks.

== Installation ==

1. Upload the `karetaker` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate through the Plugins screen.
3. Open Karetaker in the admin sidebar to review overview, activity, harden toggles, and settings (alert email, trusted proxies, agency token).

== Frequently Asked Questions ==

= Is this a firewall? =

No. Karetaker watches and alerts. It does not filter HTTP traffic.

= Will it lock me out of wp-login? =

Not by default. There is no login lockout or renamed login URL in the default set. Hardening toggles are opt-in and reversible from Karetaker → Harden.

= How do I stop it immediately? =

Define `KARETAKER_DISABLE` as true in `wp-config.php`, or create `wp-content/karetaker-disable`. The plugin then boots nothing.

= What does the agency token do? =

It unlocks a read-only JSON status endpoint for monitoring scripts. Empty token = off. Regenerating invalidates the old token. Authenticate with `Authorization: Bearer <token>` only (query-string tokens are not accepted). It cannot change settings or run scans remotely.

= Does uninstall leave data behind? =

No. Uninstall drops the events table, plugin options, and cron hooks.

= What data leaves the site? =

By default, only integrity checks contact WordPress.org to fetch published core/plugin checksums (same family of APIs WordPress itself uses). Optional features you turn on yourself may also leave the site: ACT alert emails (to the address you choose), an ACT webhook POST (to the URL you set), and the agency status endpoint (only when a token is generated — read-only, inbound). Karetaker does not phone home to Team Krikir and does not load third-party scripts or ads.

== Screenshots ==

1. Overview — product intro, last scan, Guard flags, and event counts.
2. Activity — the events log with Log/Watch/Act-now labels and readable context.
3. Harden — opt-in toggles with Desired vs Live now receipts.
4. Settings — alert email, trusted proxies, ACT webhook, and agency token controls.

== Changelog ==

= 0.1.1 =
* Security: verify nonce and capabilities before recording file-editor AJAX events.
* Admin notices stay inside the Karetaker screen only (Guideline 11).
* Agency status auth is Bearer-header only; webhook URLs limited to http/https.
* i18n for alert emails and Harden live-status labels.
* Manual scans attribute the acting admin (cron still records as system/guest).

= 0.1.0 =
* Initial release: Watch, Guard, Tell, Harden, and optional agency status channel.
