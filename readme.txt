=== Karetaker ===
Contributors: krikir
Tags: security, monitoring, activity log, file integrity, hardening
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Watches a WordPress site for compromise signals and tells the owner only when something needs them.

== Description ==

Karetaker is a **watchtower**, not a wall.

Most security plugins either shout at you all day or quietly lock you out of your own
site. Karetaker does neither. It watches the handful of changes that actually mean
something went wrong, keeps a readable record, and emails you only when a human needs
to act.

= What it watches =

* **Integrity**: core and plugin file changes, checked against published WordPress.org checksums
* **Options**: changes to the settings that matter, with before/after context
* **Privileges**: role and capability changes, new administrators, user promotions
* **Uploads**: files appearing in `wp-content/uploads` that do not belong there
* **Cron drift**: scheduled tasks that vanish, stall, or appear from nowhere

= One-checkbox catastrophes =

The quiet business killers that no scanner reports, because technically nothing is "hacked":

* Search engine visibility switched off
* Site email failing to send
* No administrators left on the site
* Invalid or unreachable admin email

= Opt-in hardening, with receipts =

A small set of hardening toggles, every one of them **off until you turn it on**, and
every one reversible from Karetaker → Harden. Each toggle shows *Desired* next to
*Live now*, so you always see what is actually in effect rather than what was merely
requested.

= How it reaches you =

* **Visibility is pull**: the Karetaker admin screen, WP-CLI, or an optional signed REST status endpoint
* **Notification is push**: email to the site owner, on ACT-severity events only
* **Hardening is off** until each toggle is switched on

= Who it is for =

* **Site owners** who want to know their site is fine without reading a dashboard every morning
* **Agencies** handing a site over to a client, who still need to know if something breaks later
* **Maintenance providers** who need one read-only status endpoint per site instead of another login

= It is deliberately not =

* A WAF or request firewall
* A malware signature scanner
* A login lockout or hide-login product by default
* A writer of `wp-config.php`, `.htaccess`, or server config

= Agency status endpoint =

An optional read-only endpoint at `/wp-json/karetaker/v1/status` stays off until you
generate a token. The token never grants remote control, status only. Regenerating it
invalidates the old one.

= Kill switch =

Define `KARETAKER_DISABLE` as true in `wp-config.php`, or place an empty file at
`wp-content/karetaker-disable`. The plugin then boots nothing. Uninstall removes the
plugin's table, options, and scheduled hooks.

= Open source =

Karetaker is GPL, built by [Team Krikir](https://www.krikir.com/). It does not phone
home, load third-party scripts, or show ads. Issues and pull requests are welcome.

== Installation ==

1. Upload the `karetaker` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate through the Plugins screen.
3. Open Karetaker in the admin sidebar to review overview, activity, harden toggles, and settings (alert email, trusted proxies, agency token).

== Frequently Asked Questions ==

= Is this a firewall? =

No. Karetaker watches and alerts. It does not filter HTTP traffic.

= Will it lock me out of wp-login? =

Not by default. There is no login lockout or renamed login URL in the default set.
Hardening toggles are opt-in and reversible from Karetaker → Harden.

= How do I stop it immediately? =

Define `KARETAKER_DISABLE` as true in `wp-config.php`, or create
`wp-content/karetaker-disable`. The plugin then boots nothing.

= What does the agency token do? =

It unlocks a read-only JSON status endpoint for monitoring scripts. Empty token = off.
Regenerating invalidates the old token. Authenticate with `Authorization: Bearer <token>`
only (query-string tokens are not accepted). It cannot change settings or run scans remotely.

= Does uninstall leave data behind? =

No. Uninstall drops the events table, plugin options, and cron hooks.

= What data leaves the site? =

By default, only integrity checks contact WordPress.org to fetch published core/plugin
checksums (same family of APIs WordPress itself uses). Optional features you turn on
yourself may also leave the site: ACT alert emails (to the address you choose), an ACT
webhook POST (to the URL you set), and the agency status endpoint (only when a token is
generated; read-only, inbound). Karetaker does not phone home to Team Krikir and does
not load third-party scripts or ads.

== Screenshots ==

1. Overview: product intro, last scan, Guard flags, and event counts.
2. Activity: the events log with Log/Watch/Act-now labels and readable context.
3. Harden: opt-in toggles with Desired vs Live now receipts.
4. Settings: alert email, trusted proxies, ACT webhook, and agency token controls.
5. Sample ACT email preview in Settings: what a site owner receives when something needs them.
6. A quiet week: the all-clear state, because staying silent is the point.

== Changelog ==

= 0.1.3 =
* Security: stop mail_failed Guard from recursing through ACT email sends; clear the flag when mail succeeds again.
* Fix uploads scan so multi-chunk runs keep all findings and resume by path; also flag .htaccess / .user.ini under uploads.
* Core checksums also flag unexpected PHP under wp-admin / wp-includes.
* Harden user-enum also blocks author archives and the users sitemap; webhooks use wp_safe_remote_post.
* ACT emails use the Watchtower HTML template (with plain-text fallback).
* Dist zip no longer ships GitHub README (avoids broken asset links).

= 0.1.2 =
* Settings shows a Sample ACT email preview (not sent) using the same builders as real alerts.

= 0.1.1 =
* Security: verify nonce and capabilities before recording file-editor AJAX events.
* Admin notices stay inside the Karetaker screen only (Guideline 11).
* Agency status auth is Bearer-header only; webhook URLs limited to http/https.
* i18n for alert emails and Harden live-status labels.
* Manual scans attribute the acting admin (cron still records as system/guest).

= 0.1.0 =
* Initial release: Watch, Guard, Tell, Harden, and optional agency status channel.

== Upgrade Notice ==

= 0.1.3 =
Important: fixes a mail-failure recursion that could flood the event log, plus uploads scan and checksum gaps. Update recommended.

= 0.1.2 =
Adds a Sample ACT email preview on Settings so you can see what owners receive before enabling alerts.

= 0.1.1 =
Security fix: file-editor AJAX events now verify nonce and capabilities before being
recorded. Agency status tokens are Bearer-header only. Update recommended for all sites.

= 0.1.0 =
First public release.
