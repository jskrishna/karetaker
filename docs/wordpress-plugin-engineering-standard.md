# WordPress Plugin Engineering Standard

**Status:** Locked house standard for all free WordPress.org plugins  
**Scope:** Every plugin already shipped and every plugin built going forward  
**Goal:** Private source of truth, clean distributable packages, directory-safe code

This document is the single architecture and pipeline contract. Do not invent per-plugin shortcuts that violate it. Official WordPress.org rules still win when they change-check the links below before every submission.

**Authoritative references**

- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [Common issues](https://developer.wordpress.org/plugins/wordpress-org/common-issues/)
- [Plugin Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/)
- [Plugin security](https://developer.wordpress.org/plugins/security/)
- [readme.txt](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Plugin assets](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/)
- [Submit a plugin](https://wordpress.org/plugins/developers/add/)

---

## 1. Two-layer model

| Layer | Contents | Audience |
| --- | --- | --- |
| **Source repository (Git)** | Runtime plugin code, admin assets, design docs, engineering notes, Composer/PHPCS, build tools, tests | Maintainers |
| **Distribution package** | Only files required to install and run the plugin on a WordPress site | Reviewers, SVN, end users |

**Rules**

1. Design rationale, research, audits, and “why this exists” live in `docs/` and `CODE-NOTES.mdot in long PHPDoc essays.
2. Development tooling never ships in the installable zip (`vendor/`, `composer.json`, `phpcs.xml*`, `tools/`, `tests/`, agent/editor folders).
3. A build script produces the zip and applies release transforms (for example stripping `Update URI: false` for directory upload).
4. WordPress.org SVN is a **release** repository. Day-to-day work stays in Git. Only finished versions are committed to SVN.

```
┌──────────────────────────────────────────────────────────┐
│  SOURCE REPO (Git)                                       │
│  {slug}.php · includes/ · runtime assets                 │
│  docs/ · CODE-NOTES.md · Composer · PHPCS · tools/       │
│  Update URI: false while distributing outside .org       │
└────────────────────────────┬─────────────────────────────┘
                             │  tools/build-dist.sh
                             │  (.distignore / rsync excludes)
                             ▼
┌──────────────────────────────────────────────────────────┐
│  DIST ZIP  ({slug}/…)                                    │
│  bootstrap · includes · uninstall · readme.txt · LICENSE │
│  runtime CSS/JS only · no docs/tools/vendor · no banners │
│  Update URI removed for WordPress.org upload             │
└────────────────────────────┬─────────────────────────────┘
                             │  first upload → manual review
                             │  later releases → SVN trunk + tags
                             ▼
┌──────────────────────────────────────────────────────────┐
│  WORDPRESS.ORG                                           │
│  SVN assets/ for banners, icons, screenshots             │
│  Directory-hosted updates for end users                  │
└──────────────────────────────────────────────────────────┘
```

---

## 2. Product constraints (directory / free)

Every plugin under this standard must:

- Ship under **GPLv2 or later** (or another GPL-compatible license), declared in the plugin header, `readme.txt`, and `LICENSE`.
- Be **fully usable** with no trial lock, feature paywall, or “license key to unlock” for code already in the package.
- Place any future premium surface in a **separate add-on** outside the directory (Guideline 5).
- Avoid a custom update server for the directory-hosted plugin (Guideline 8).
- Avoid outbound tracking or analytics without explicit opt-in (Guideline 7).
- Use a free, trademark-safe slug and a **distinctive prefix aligned to that slug**.

---

## 3. Repository layout

```text
{plugin-slug}/
├── {plugin-slug}.php                 # Bootstrap only
├── uninstall.php                     # ABSPATH + WP_UNINSTALL_PLUGIN guards
├── readme.txt                        # Directory metadata
├── LICENSE                           # GPLv2 or later
├── includes/
│   ├── class-*.php                   # One concern per class
│   └── admin/partials/               # Admin view templates
├── assets/
│   ├── admin.css / admin.js          # Shipped (runtime)
│   ├── banner-1544x500.png           # Directory assets only (exclude from zip)
│   ├── banner-772x250.png
│   ├── icon-256x256.png
│   ├── icon-128x128.png
│   └── screenshot-*.png              # Listed in readme; live in SVN assets/
├── tools/
│   └── build-dist.sh                 # Stage, strip, zip
├── docs/                             # Design, plans, audits (exclude from zip)
├── CODE-NOTES.md                     # Engineering rationale
├── composer.json                     # require-dev tooling only (WPCS, etc.)
├── phpcs.xml.dist
├── .distignore
└── dist/                             # Generated packages (excluded)
```

### Bootstrap

`{plugin-slug}.php` may contain only:

1. Plugin header block  
2. Direct-access guard (`ABSPATH`)  
3. Constants (`VERSION`, `FILE`, `DIR`, `SLUG`)  
4. Class loading (`require_once` or a small autoloader)  
5. Activation / deactivation hooks  
6. A single boot entry point  

No settings registration, AJAX handlers, CPT registration, or feature logic in the bootstrap file.

### Modularity

- One feature → one class under `includes/`.
- Prefer splitting before a class exceeds roughly 200 lines of real logic.
- Prefix **all** globals: functions, classes, constants, options, transients, cron hooks, REST namespaces, custom tables, script/style handles, nonces.

| Element | Convention | Example |
| --- | --- | --- |
| Slug / text domain | `lowercase-hyphen` | `example-guard` |
| Functions / options | `{slug}_` | `example_guard_settings` |
| Classes | `{Slug}_` | `Example_Guard_Events` |
| Constants | `{SLUG}_` | `EXAMPLE_GUARD_VERSION` |
| REST namespace | `{slug}/v1` | `example-guard/v1` |

**Prefix policy:** Prefer the full slug as the prefix when practical. Very short prefixes (two or three letters) are collision-prone and frequently delayed in review. WordPress.org expects the code prefix to identify *this* plugin clearly.

### UI and CSS (not locked to native admin chrome)

This standard does **not** require WordPress default admin styling. Visual design is owned by the plugin author.

| Allowed | Required constraint |
| --- | --- |
| Custom CSS / layout / typography / color system on **plugin screens** | Ship styles locally; enqueue with `wp_enqueue_style()` |
| Custom admin JS for the plugin UI | Enqueue with `wp_enqueue_script()`; depend on WP-bundled libs when using them (e.g. jQuery) |
| Distinct brand look that differs from core wp-admin | Load assets **only** on this plugin’s pages (`$hook` / screen checks) |
| Optional build step (Sass, PostCSS, bundlers) | Dist must include compiled CSS/JS; document source if minified (Guideline 4) |

| Not required | Not allowed |
| --- | --- |
| Matching core metabox / `#wpbody` stock look | Remote CDN CSS/JS (fonts may be excepted per Guideline 8) |
| Using only `form-table` / default admin components | Styles or scripts that restyle or break unrelated wp-admin screens |
| Zero custom CSS | Obfuscated / unreadable front-end assets without public source |
| | Forced front-end “powered by” chrome (Guideline 10) |

**Practical rule:** Architecture, security, and packaging are locked. **Look and feel are free*esign each product’s admin UI as needed, as long as assets are local, scoped, and directory-safe.

---

## 4. End-to-end pipeline

### Phase pec

1. Write a short design document: problem, in-scope / out-of-scope, audiences, storage model.  
2. Confirm the slug is free on WordPress.org.  
3. Lock prefix = slug (or a strong slug-derived form).  
4. List modules and build order.

### Phase oundation

1. Scaffold bootstrap, `uninstall.php`, empty module classes, `readme.txt` stub.  
2. Settings via the Settings API (or one options array with explicit `$autoload`).  
3. Activation creates schema if required; deactivation unschedules cron.  
4. Provide a documented emergency disable path if the plugin can block admin access.  
5. Uninstall must remove tables, options, cron events, and transientothing left behind.

### Phase eatures

1. Implement one module at a time; each owns its hooks.  
2. Admin UI: PHP templates (Settings API / `WP_List_Table` optional). Custom CSS/JS allowed; enqueue only on plugin screens. Visual design is not constrained to native wp-admin chrome.  
3. REST routes always use a real `permission_callback` (fail closed).  
4. All user-facing strings are internationalized; text domain equals slug.  
5. For directory-hosted plugins, prefer WordPress auto-loading of translations (since 4.6). If `load_plugin_textdomain()` is used, hook it on `init` (WP 6.7+).

### Phase uality gate

```bash
# Syntax
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# Coding standards
composer install
composer phpcs

# Distributable package
./tools/build-dist.sh
```

Run [Plugin Check](https://wordpress.org/plugins/plugin-check/) against the **built package**, not the raw Git tree. Development files create false noise. Fix Errors before upload; treat Warnings as review risk.

### Phase ackage prep

1. Complete `readme.txt`; headers must match the plugin header.  
2. PHPDoc on files, classes, and methods (`@param`, `@return`, `@since`). Rationale stays in `CODE-NOTES.md`.  
3. Prepare directory assets (banners, icons, screenshots) for SVN `assets/ot for the installable zip.  
4. Strip `Update URI: false` in the **dist** build only. Keep it in Git while hand-distributing so a slug collision cannot overwrite installs.  
5. Zip must be under **10 MB** and install via Plugins → Upload Plugin.

### Phase ubmit and release

1. Upload at [Add Plugin](https://wordpress.org/plugins/developers/add/).  
2. Await manual review; reply on the email thread; keep a human-monitored contact address.  
3. After approval: SVN `trunk`, immutable `tags/{version}`, `Stable tag` in trunk `readme.txt`.  
4. Commit banners/icons/screenshots to SVN `assets/` (sibling of `trunk`).

### Phase aintenance

- Develop in Git; release through SVN.  
- Increment the version on every release (Guideline 15).  
- Avoid noisy SVN commits (Guideline 14).  
- Keep the directory package aligned with the real product (Guideline 3).

---

## 5. Build contract

### Must exclude from dist

- `.git`, editor/agent folders, `docs/`, `CODE-NOTES.md`, internal markdown  
- `vendor/`, `node_modules/`, `composer.json`, `composer.lock`, `phpcs.xml*`  
- `tools/`, `tests/`, `dist/`  
- Directory marketing assets: `assets/banner-*.png`, `assets/icon-*.png`, `assets/screenshot-*.png`

### Must include in dist

- Bootstrap, `includes/`, `uninstall.php`, `readme.txt`, `LICENSE`  
- Runtime assets only (admin CSS/JS, in-plugin icons actually enqueued)

### `tools/build-dist.sh` must

1. Stage a folder named exactly `{slug}/`.  
2. Copy with the exclude set above.  
3. Remove `Update URI` lines from the staged bootstrap for directory builds.  
4. Fail if `Update URI` remains or required headers are missing.  
5. Write `dist/{slug}-{version}.zip` and print a short listing for smoke verification.

Keep `.distignore` synchronized with the same exclude set.

---

## 6. Security contract

| Requirement | Practice |
| --- | --- |
| Sanitize input | Sanitize on receipt (`sanitize_*`, `absint`, `wp_kses_*`, `wp_unslash`) |
| Escape output | Late escape at render (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`) |
| Database | `$wpdb->prepare()` for all variable SQL |
| Nonces | Every state-changing form, `admin-post`, and AJAX action |
| Capabilities | `current_user_can()` before privileged work |
| AJAX | Prefer authenticated `wp_ajax_`; use `nopriv` only when public by design |
| REST | Real `permission_callback`; never open sensitive routes |
| Direct access | `ABSPATH` guard on every PHP file |
| Uninstall | Guard with `WP_UNINSTALL_PLUGIN` |
| Secrets | Never log passwords, tokens, or raw request bodies wholesale |
| Libraries | Use WordPress-bundled libraries; do not ship duplicates (Guideline 13) |
| Remote calls | Disclose in readme; require opt-in when not inherent to a documented service |

---

## 7. WordPress.org guidelines (all 18)

Source: [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/). Re-verify before each upload.

### 1. GPL-compatible license

Declare GPLv2 or later (preferred). Headers, readme, and bundled assets/libraries must all be compatible. Unverifiable licenses or API terms → do not ship.

### 2. Developer responsibility

Every file and third-party integration is the submitter’s responsibility. Do not restore code the review team required removed. Keep profile contact information accurate and reachable by a human.

### 3. Stable version from the directory

The directory-hosted package must be the current distributable product. Do not let alternate channels drift while .org stays stale.

### 4. Human-readable code

No obfuscation or deliberately opaque naming. Minified assets require accompanying source in the package **or** a documented public source link in the readme. Runtime Composer usage should include `composer.json` so dependencies are reviewable.

### 5. No trialware

No locked features, timers, or quotas that disable included functionality. Paid extras belong in a separate off-directory add-on. Upsells must stay within Guideline 11.

### 6. SaaS permitted

Documented interfaces to real external services are allowed (including paid services). Fake license servers that only unlock already-shipped code are not. Storefront-only plugins with no substantive local behavior are not.

### 7. No tracking without consent

No external telemetry without explicit opt-in. Document collection and use in the readme. Configuring a documented SaaS implies consent for that service only. Do not load unrelated third-party assets for tracking.

### 8. No third-party executable delivery

While hosted on WordPress.org: no custom updater for this plugin, no remote premium zip installs, no third-party CDNs for JS/CSS (fonts excepted), no admin UIs loaded through remote iframes.

### 9. No illegal or dishonest conduct

No review fraud, sockpuppets, plagiarized plugins, crypto-mining, harassment, or false legal-compliance guarantees.

### 10. No forced public credits

Front-end “powered by” links must be optional and off by default. Functionality must not depend on displaying credit links.

### 11. Do not hijack the admin

Notices belong on plugin screens or must be dismissible / self-clearing. Avoid permanent advertising widgets. Errors must explain remediation and clear when resolved.

### 12. No readme spam

Maximum five tags. No competitor tags or keyword stuffing. Disclose affiliates; no cloaked redirects. Write for people.

### 13. Use WordPress default libraries

Enqueue WordPress-registered copies of bundled libraries (for example jQuery). Do not bundle duplicates.

### 14. Avoid frequent SVN commits

SVN is for releases, not continuous integration. Use descriptive commit messages. Do not game “recently updated.” Updating “Tested up to” alone is an accepted exception.

### 15. Increment version numbers

Every release bumps the plugin header version and readme Stable tag. Trunk `readme.txt` must reflect the current stable line.

### 16. Complete plugin at submission

The zip must install and work. No placeholders and no name reservations. Size under 10 MB.

### 17. Respect trademarks

Do not lead the slug with another project’s brand unless legal representation is proven. Prefer original naming, or “X for Brand” for integrations.

### 18. Directory maintenance rights

Guidelines may change. Plugins may be closed or patched for public safety. Repeat violations can remove all plugins and ban the developer.

---

## 8. Common rejection causes

1. Unescaped output, unsanitized input, or missing nonces  
2. Missing or mismatched GPL declarations  
3. Undisclosed or non-consensual outbound calls  
4. Shipping development debris (`.git`, `node_modules`, full `vendor`, internal docs)  
5. Obfuscation or minified code without public source  
6. Trial locks or license walls over included code  
7. Custom updater while targeting directory hosting  
8. Weak or colliding prefixes  
9. Incomplete stubs submitted to reserve a name  
10. Trademarked or misleading slugs  
11. Admin notice spam or forced credit links  
12. Bundled copies of WordPress core libraries  

---

## 9. `readme.txt` template

```text
=== Plugin Display Name ===
Contributors: wporg-username
Tags: tag-one, tag-two, tag-three
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-sentence summary, 150 characters or fewer.

== Description ==

What the plugin does. What it does not do. Any external services
(name, when contacted, why), with links to terms and privacy policy.

== Installation ==

1. Install from Plugins → Add New, or upload the zip.
2. Activate the plugin.
3. Configure under …

== Frequently Asked Questions ==

= …

…

== Screenshots ==

1. Primary screen
2. Settings screen

== Changelog ==

= 1.0.0 =
* Initial release.
```

Keep Stable tag, plugin header `Version`, and the version constant synchronized.

---

## 10. SVN layout after approval

```text
{plugin-slug}/
├── assets/           # Banners, icons, screenshots only
├── trunk/            # Current directory snapshot
└── tags/
    └── 1.0.0/        # Immutable release matching Stable tag
```

Users receive the tag named by trunk `readme.txt` → `Stable tag`. Do not place directory banners inside the installable plugin tree.

---

## 11. Code quality bar

| Area | Required standard |
| --- | --- |
| Architecture | Thin bootstrap; feature classes; no god-files |
| UI / CSS | Author-owned design; custom styles allowed; scoped enqueue only |
| Documentation | PHPDoc for machines/review; rationale in `CODE-NOTES.md` |
| Standards | Composer + WPCS (`WordPress`, prefix sniff, text-domain sniff) |
| Performance | No unnecessary front-end cost; no surprise remote calls on ordinary requests |
| Uninstall | Provably complete |
| Honesty | Readme claims match shipped behavior |
| Licensing model | Entire directory package works without accounts or license keys |

---

## 12. Pre-upload checklist

### Product

- [ ] Slug confirmed available  
- [ ] Prefix consistent and distinctive  
- [ ] Full functionality available without payment inside this package  
- [ ] External services documented in readme  

### Code

- [ ] Bootstrap is loader-only  
- [ ] `ABSPATH` on every PHP file  
- [ ] Sanitize / escape / prepare / nonce / capability applied correctly  
- [ ] REST permissions fail closed  
- [ ] Text domain equals slug; strings wrapped  
- [ ] `uninstall.php` removes all plugin data  
- [ ] No obfuscation; no duplicated WordPress libraries  
- [ ] No custom updater; no forbidden CDN scripts  

### Package

- [ ] `readme.txt` headers match versions  
- [ ] `LICENSE` present  
- [ ] Dist built via script; excludes docs/tools/vendor  
- [ ] `Update URI: false` removed from dist for .org upload  
- [ ] Plugin Check reports zero Errors on dist  
- [ ] `composer phpcs` clean (or only documented intentional ignores)  
- [ ] Fresh-site install and smoke test passed  
- [ ] Zip under 10 MB  

### Assets (may follow initial approval)

- [ ] Banner 1544×500 (optional 772×250)  
- [ ] Icon 256×256 (and 128×128)  
- [ ] Screenshots match readme  

### Account

- [ ] WordPress.org account ready; email monitored  
- [ ] Contributors field matches the account  

---

## 13. Greenfield / rewrite order

When replacing a single-file or ad-hoc plugin, follow this sequence:

1. Design document  
2. Scaffold (bootstrap, constants, classes, uninstall, readme stub)  
3. Settings and admin shell (capability + nonce)  
4. Feature modules (one at a time)  
5. Internationalization and PHPDoc  
6. PHPCS and Plugin Check on dist  
7. Final readme and assets  
8. Submit zip  
9. SVN release process after approval  

---

## 14. Decision table

| Question | Required answer under this standard |
| --- | --- |
| Where is development done? | Private Git repository |
| What do users install? | Dist zip, then directory updates after approval |
| May premium code live in the same package? | Neparate off-directory add-on |
| May the plugin ship a custom updater? | Not while hosted on WordPress.org |
| May the plugin phone home for analytics? | Only with explicit opt-in and readme disclosure |
| Where do banners and screenshots live? | SVN `assets/`, not the installable plugin folder |
| When is submission allowed? | Installs, works, secure, documented, Plugin Check clean on dist |

---

## 15. Adoption rule

Copy this file into each plugin repository as `docs/wordpress-plugin-engineering-standard.md` (or keep a single shared copy and link it from every repo README).

**No plugin is considered release-ready until Section 12 is complete.** Exceptions require a written note in that plugin’s `CODE-NOTES.md` explaining what was waived and why.
