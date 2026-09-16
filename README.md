<p align="center">
  <img src="assets/banner-1544x500.png" alt="Karetaker: WordPress security watchtower" width="100%">
</p>

<h1 align="center">Karetaker</h1>

<p align="center">
  <strong>A watchtower, not a wall.</strong><br>
  Watches a WordPress site for compromise signals and tells the owner only when something needs them.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPLv2%20or%20later-blue.svg" alt="License: GPLv2 or later"></a>
  <img src="https://img.shields.io/badge/WordPress-6.2%2B-21759b.svg" alt="Requires WordPress 6.2+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg" alt="Requires PHP 7.4+">
  <img src="https://img.shields.io/badge/version-0.1.3-brightgreen.svg" alt="Version 0.1.3">
</p>

---

## What it does

| Module | Job |
| --- | --- |
| **Watch** | Notices the changes that usually mean compromise: file & option integrity, privilege changes, uploads surprises, cron drift |
| **Guard** | Catches one-checkbox business catastrophes: search engines off, mail failing, no administrators, invalid admin email |
| **Harden** | Opt-in hardening toggles with clear Desired vs Live receipts and one-click undo |
| **Tell** | Emails the site owner only for act-now events, not a noise firehose |

Visibility is **pull** (admin screen, WP-CLI, optional signed REST status). Notification is **push** (email on ACT-severity events). Hardening stays **off** until you turn each toggle on.

## What it is not

Karetaker deliberately refuses the shapes that lock owners out or lie about safety:

- Not a WAF or request firewall
- Not a malware signature scanner
- Not a login lockout / hide-login product by default
- Not a writer of `wp-config.php`, `.htaccess`, or server config

No cloud account. No licence key. No registration wall.

## Screenshots

| Overview | Activity |
| --- | --- |
| <img src="assets/screenshot-1.png" alt="Overview: product intro, last scan, Guard flags, and event counts" width="400"> | <img src="assets/screenshot-2.png" alt="Activity: events log with Log/Watch/Act-now labels" width="400"> |

| Harden | Settings |
| --- | --- |
| <img src="assets/screenshot-3.png" alt="Harden: opt-in toggles with Desired vs Live receipts" width="400"> | <img src="assets/screenshot-4.png" alt="Settings: alert email, trusted proxies, webhook, agency token" width="400"> |

| ACT alert email | Quiet week |
| --- | --- |
| <img src="assets/screenshot-5.png" alt="Settings: sample ACT alert email preview" width="400"> | <img src="assets/screenshot-6.png" alt="A quiet week: the all-clear state" width="400"> |

1. **Overview**: product intro, last scan, Guard flags, and event counts  
2. **Activity**: the events log with Log / Watch / Act-now labels and readable context  
3. **Harden**: opt-in toggles with Desired vs Live now receipts  
4. **Settings**: alert email, trusted proxies, ACT webhook, and agency token controls  
5. **Sample ACT email**: Settings preview of the alert a site owner receives  
6. **Quiet week**: the all-clear state, because staying silent is the point  

## Install

**From zip**

1. Download a release zip (or clone this repo and zip the plugin folder).
2. In WordPress: **Plugins → Add New → Upload Plugin**.
3. Activate **Karetaker**.
4. Open **Karetaker** in the admin sidebar.

**From Git**

```bash
cd wp-content/plugins
git clone https://github.com/jskrishna/karetaker.git
```

Then activate in **Plugins** and open the Karetaker screen.

Requirements: **WordPress 6.2+**, **PHP 7.4+**.

## Kill switch & uninstall

Stop everything immediately:

- Define `KARETAKER_DISABLE` as `true` in `wp-config.php`, **or**
- Place an empty file at `wp-content/karetaker-disable`

Uninstall removes the plugin’s events table, options, and scheduled hooks. Nothing left behind.

## Agency status (optional)

An optional read-only status endpoint is available at:

```text
/wp-json/karetaker/v1/status
```

It stays **off** until you generate a token in Settings. The token never grants remote control, status only. Regenerating invalidates the old token.

## Privacy

By default, only integrity checks contact WordPress.org to fetch published core/plugin checksums (same family of APIs WordPress itself uses).

Optional features you turn on yourself may also leave the site:

- ACT alert emails (to the address you choose)
- An ACT webhook POST (to the URL you set)
- The agency status endpoint (inbound, read-only, only when a token is generated)

Karetaker does **not** phone home to Team Krikir and does **not** load third-party scripts or ads.

## Docs

| Doc | What |
| --- | --- |
| [`readme.txt`](readme.txt) | WordPress.org plugin metadata, FAQ, changelog |
| [`docs/design.md`](docs/design.md) | Product design and evidence notes |
| [`LICENSE`](LICENSE) | GPLv2 |

## License

Karetaker is free software under the [GNU General Public License v2.0 or later](LICENSE).

---

<p align="center">
  <img src="assets/icon-128x128.png" alt="Karetaker icon" width="64" height="64"><br>
  <sub>Built by Team Krikir for sites nobody is paid to watch.</sub>
</p>
