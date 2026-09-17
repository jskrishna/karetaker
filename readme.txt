=== Karetaker ===
Contributors: krikir
Tags: security, monitoring, file integrity, activity log, hardening
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hacked-site alerts, file integrity, and new-admin watch for WordPress. A quiet watchtower, not a firewall.

== Description ==

Karetaker is a **watchtower**, not a wall.

Most security plugins either shout at you all day or quietly lock you out of your own
site. Karetaker does neither. It watches the handful of changes that actually mean
something went wrong, keeps a readable record, and emails you only when a human needs
to act.

= What it watches =

* **Scripts**: new external JavaScript domains after a local baseline (home, and cart/checkout when WooCommerce is active)
* **Search engine cloaking**: once a day, compares your home page as a visitor and as Googlebot, and flags hidden spam links
* **Integrity**: core and plugin file changes, checked against published WordPress.org checksums
* **Options**: changes to the settings that matter, plus curated suspicious option names and unusual new autoload names
* **Privileges**: role and capability changes, new administrators, user promotions
* **Uploads**: files appearing in `wp-content/uploads` that do not belong there
* **Cron drift**: scheduled tasks that vanish, stall, or appear from nowhere
* **Plugin risk**: closed or abandoned plugins on WordPress.org, and plugins whose listed owner changed
* **Optional vulnerability lookup**: when you enable it, active plugin versions are checked against public WPVulnerability data (off by default)

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
* **Optional Slack / Telegram / Discord / Microsoft Teams**: webhooks or Bot API, off until you turn them on
* **Optional weekly summary**: one short email on Monday mornings, off until you turn it on
* **Pause alerts** for 1 to 4 hours while you work on the site; one catch-up email afterwards if something needed you
* **Client report**: printable HTML summary from the Reports tab, emailed to a client only when you press the button
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

= Credits =

Karetaker ships no third-party code, fonts or images. It relies on these projects and services,
and thanks them:

* [WordPress](https://wordpress.org/) and the WordPress.org APIs for core and plugin checksums, plugin directory information, and security keys
* [WPVulnerability](https://www.wpvulnerability.com/) for the optional public vulnerability database (vulnerability details shown in Karetaker come from WPVulnerability and the sources it links to)
* [Simple Icons](https://simpleicons.org/) for the destination logos shown in Settings (CC0 1.0)
* [Slack](https://slack.com/) incoming webhooks, the [Telegram](https://telegram.org/) Bot API, [Discord](https://discord.com/) webhooks and [Microsoft Teams](https://www.microsoft.com/microsoft-teams/) workflows for the optional alert channels
* [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer), [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) and [PHPCompatibilityWP](https://github.com/PHPCompatibility/PHPCompatibilityWP), used during development only

WordPress is a registered trademark of the WordPress Foundation. Microsoft and Microsoft Teams
are trademarks of the Microsoft group of companies. Slack is a trademark of Slack Technologies, LLC.
Discord is a trademark of Discord Inc.
Google and Googlebot are trademarks of Google LLC. Telegram and all other trademarks are the property of their
respective owners. Karetaker is an independent project by Team Krikir. It is not created,
endorsed, sponsored or certified by any of these companies; their names are used only to
describe the services Karetaker can connect to.

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

= What if I think the site was hacked? =

Open Karetaker → Overview → “I think this site was hacked”. That runs a deeper multi-pass
scan and shows a checklist (admins, plugins, uploads PHP, integrity, Guard, passwords).
It does not clean malware or lock anyone out; it is a guided review, not a clean certificate.

= Can hosting providers use this? =

Yes. Karetaker does not ship a WAF, does not lock logins by default, and does not write
server config. Hosts can poll the optional agency status endpoint (Bearer token) and read
`host_profile` in the JSON. Site Health also reports scan freshness, Guard flags, and the
host safety profile. Kill switch: `KARETAKER_DISABLE` or `wp-content/karetaker-disable`.

= What data leaves the site? =

By default, only integrity checks contact WordPress.org to fetch published core/plugin
checksums (same family of APIs WordPress itself uses). Optional features you turn on
yourself may also leave the site: ACT alert emails (to the address you choose), an ACT
webhook POST (to the URL you set), Slack Incoming Webhooks, Telegram Bot API, Discord and
Microsoft Teams webhook messages (when enabled), the weekly summary and end-of-pause emails
(when enabled), client report emails (only to the address you enter, when you press Email to
client), vulnerability lookup requests to wpvulnerability.net (plugin slug only,
when enabled under Settings), and the agency status endpoint (only when a token is
generated; read-only, inbound). The daily "What Google sees" check requests your own home
page twice (once with a Googlebot user agent); it does not contact Google. Karetaker does not phone home to Team Krikir and does
not load third-party scripts or ads.

When you enable those optional services, you also accept their terms:

* Slack: https://slack.com/terms-of-service and https://slack.com/privacy-policy
* Telegram: https://telegram.org/tos/bot-developers and https://telegram.org/privacy
* Discord: https://discord.com/terms and https://discord.com/privacy
* Microsoft Teams: https://www.microsoft.com/servicesagreement and https://privacy.microsoft.com/privacystatement
* WPVulnerability: https://www.wpvulnerability.com/ (public vulnerability database API)

== Screenshots ==

1. Home when something needs you: a plain-language headline and a card per problem, each with "Show me what to do".
2. Home when there is nothing urgent: worth a look, but it can wait.
3. Home on a quiet week, because staying silent is the point.
4. Activity: everything Karetaker recorded, grouped by day and written in plain words.
5. Protection: optional protections you can switch on or off. None of them can lock you out.
6. Settings: where alerts go, the weekly summary, a one-hour pause, site type, and Advanced mode for agencies.
7. The one-minute setup that runs on first activation.

== Changelog ==

= 1.0.2 =
* Developer: an extension API (actions, filters and a JavaScript bridge) so add-ons can extend Karetaker without editing it.
* Tidier stylesheet comments.

= 1.0.1 =
* New email design for alerts, the weekly summary, the pause summary and test emails, matching the admin screens.
* Clearer subjects that lead with the status and name the site, for example "Act now: A must-use plugin file changed · example.com".
* Every email now has a readable plain-text version and a readable technical details table instead of raw data.
* The logo no longer breaks in email clients or when SMTP plugins are active.
* Summaries group repeated events into one line with a count.
* Preview any email from Advanced mode → Incidents → Alert routing.

= 1.0.0 =
First public release.

* Sixty-second setup: where to send alerts, what kind of site this is, a first check, and three safe protections.
* Home: plain-language status, a to-do card for anything that needs you, and the twelve checks Karetaker runs.
* Watches: critical files, WordPress core and plugin files against official checksums, administrators (including accounts hidden from the Users screen), plugin risk signals from WordPress.org, must-use plugins, the uploads folder, scheduled tasks, option names, external script domains, what search engines see, failed logins, search visibility and email delivery.
* Activity: everything Karetaker recorded, kept in your own database.
* Protection: seven optional protections that cannot lock you out. Karetaker never edits wp-config.php or .htaccess.
* Alerts by email, and optionally Telegram, Slack, Discord, Microsoft Teams or your own webhook, with a weekly summary and a one-hour pause while you work.
* Advanced mode for agencies and developers: issue tracking with owners, detailed monitoring, incident cases with a response checklist, client reports, CSV/JSON/ZIP exports, role permissions and read-only API tokens.
* WP-CLI commands and an emergency off switch.

== Upgrade Notice ==

= 1.0.2 =
Maintenance release with extension hooks for developers. No visible changes.

= 1.0.1 =
Clearer, better-looking alert and summary emails.

= 1.0.0 =
First public release.
