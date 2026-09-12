# Karetaker — a WordPress security plugin for sites nobody is paid to watch

Design, 2026-09-13. Built on eight parallel research sweeps (~200 primary sources: NVD,
CISA KEV, WordPress.org stats API, USENIX papers, six Patchstack whitepapers, nine Sucuri
incident reports, Wordfence source code, ~100 WordPress.org 1★ reviews and support threads,
agency podcasts and forums).

Slug `karetaker`, prefix `karetaker_` on every function, class, constant and option
(`Karetaker_Events`, `KARETAKER_VERSION`, `karetaker_settings`, table `karetaker_events`).

The agency prefix was dropped on 2026-09-13. WordPress.org checks that the code prefix
matches the **slug**, not the author, and requires 4+ characters — so `karetaker_` is both
the shortest safe form and the self-documenting one, while `krikir_karetaker_` was two names
for one plugin. Three-letter forms like `krk_` are rejected outright by the review team and
are exactly the collision-prone shape it pends plugins for. The brand lives in the plugin
header's `Author: Team Krikir`, the admin page and the UI — the places a client actually
sees. `karetaker` was confirmed free on WordPress.org the same day.

## The problem, stated from evidence

The market failure in WordPress security is **not** that attacks get through. It is that
nobody notices.

- **94%** of malicious plugins installed over eight years were still active at time of
  study; median persistence **189–209 days**; 25% persist **525+ days**. Most *"do not
  employ evasion or obfuscation, preferring to brazenly hide in plain sight"* — they were
  not hiding, nothing was looking. (YODA, USENIX Security '22, 400,000+ webservers.)
- Only **10.8%** of compromised sites ever attempted a cleanup at all.
- **83–89%** of infected sites carried no blocklist warning from any authority.

The second finding: roughly **half** of compromises involve no vulnerability at all —
credentials and hygiene. WordPress still ships no MFA and no failed-login limit; sessions
last 48 hours, 14 days with "Remember Me"; rogue-admin persistence went **32.69% → 55.2%**
of database-malware sites in one year. **46.4%** of installs are not on the current major
version and **38.4%** run end-of-life PHP.

The third: the existing category is built for the wrong distribution. XSS is **45%** of
disclosed vulnerabilities and **1%** of observed exploitation. Broken Access Control is
~9–13% of disclosures and **57%** of exploitation — and it is invisible to payload
signatures, because the request is well-formed and authenticated.

And the use case this plugin exists for — an agency builds a site, hands it to a
non-technical owner, and nobody is paid to look at it again — is precisely where every
existing product fails. The decisive quote, from an agency owner on the WP Builds podcast:

> "I had one I sent off **without a security plugin** on. I didn't think they would
> understand what those plugins were telling them."

An agency chose *no security at all* over a plugin whose output would confuse the client.
That is the gap.

## What this is

**A watchtower, not a wall.** Four jobs:

| Module | Job |
|---|---|
| **Watch** | Notice the specific changes that indicate compromise |
| **Guard** | Notice the one-checkbox catastrophes that silently break a business |
| **Harden** | Apply the reversible subset, with receipts and one-click undo |
| **Tell** | Six act-now alerts to the owner; machine-readable status to the agency |

## What this is not, and why

Each refusal is evidence-led, not taste.

- **No WAF.** Signature matching addresses 1% of real exploitation. A PHP-level firewall
  also cannot see the two highest-value attack classes: a `.php` file dropped in
  `wp-content/uploads` is served by Apache/nginx and never routes through WordPress, and
  Broken Access Control has no payload to match. Meanwhile false positives block real
  customers — the single most commercially costly complaint in the review corpus.
- **No malware signature scanner.** The feed *is* the business model. Wordfence delays free
  rules 30 days; Patchstack charges $5/site/month for vPatch. A free signature feed is a
  commitment to fund a research team forever. Anything less is a scanner that says "clean"
  on a hacked site — which is what Jetpack Protect free actually does.
- **No login blocking by default.** Lockout is the number one lived failure in the entire
  review corpus, and recovery is *never* inside WordPress — FTP, cPanel, or a host ticket,
  every time. Reported durations: 8 days, 2 weeks, 3,000 hours, one year. At handover there
  is nobody with FTP.
- **No login-URL hiding.** *"Creates a false sense of security… crawlers almost immediately
  discover the renamed login page."* It also produces the "new URL 404s and I can't get in"
  failure across three separate plugins.
- **No writes to `wp-config.php`, `.htaccess`, `.user.ini`, or any server config.** Every
  catastrophic outage in the research traces to file surgery. Solid Security has been
  blanking `wp-config.php` and `.htaccess` from 2015 to 2026, still unfixed.
- **No cloud account, no licence key, no registration wall.** Paid security on a
  handed-over site is a liability that decays into an insecurity: the licence lapses, nobody
  remembers whose job renewal was, and the site is now unprotected *and* believes it is
  protected.

## Design rules

Each traces to a measured failure.

1. **Blocking off, observation on, by default.** Ship watching. Blocking is opt-in, per
   feature, after the owner has seen what would have been blocked.
2. **Never touch server config files.** PHP and the options/custom tables only.
3. **Hard row cap enforced at insert**, not a cron prune. Wordfence and Solid both prune on
   WP-Cron, and cron degrades exactly when rows grow fastest — under bot load. A table that
   *cannot* exceed N rows never needs the prune job to work.
4. **Log events, not traffic.** This is what makes the performance budget reachable and is
   the opposite of Live Traffic. Wordfence performs two full WordPress bootstraps per human
   visitor (an injected beacon hits a front-end URL to set `jsRun=1`); one 10-page site had
   400+ of those URLs indexed by Google.
5. **No score, no grade, no green tick.** Chrome removed the EV indicator after concluding
   positive security indicators are ineffective, confirmed by an experiment "many orders of
   magnitude larger than any prior study". A score is ignored when good and burns trust when
   a false positive moves it. Show falsifiable facts instead: *last checked*, *protections
   active*, *items needing you*, *events this week*.
6. **Visibility is pull; notification is push; they are governed separately.** See below.
7. **"Protected" is derived from a live check, never read from a stored option.** DollyWay
   disables Wordfence, NinjaFirewall and MalCare from a hardcoded list on every page load; a
   2024 sample renamed the `wordfence` directory and injected CSS so the settings toggles
   *looked* enabled. The admin saw a green dashboard.
8. **Uninstall provably total** — no rows, no files, no rules, no emails after deletion.
   Unclean uninstall is its own complaint category across three plugins; MalCare's leftovers
   broke a host migration, and *"the plugin changes names if you deactivate it"*.
9. **`REMOTE_ADDR` by default.** A proxy header is honoured only when the trusted-proxy CIDRs
   are configured *and* `REMOTE_ADDR` falls inside one. A spoofable IP key does not just let
   an attacker evade a limit — it lets them write to anyone else's counter and lock out the
   administrator. Behind a CDN, misconfigured IP detection collapses every visitor to one
   address, so one block takes the whole site down; this is the hidden root cause of the
   `127.0.0.1` lockouts reported against Wordfence, AIOS and Sucuri independently.
10. **Survives migration and backup restore unchanged**, and ships a documented kill switch
    (a `wp-config.php` constant and a file drop) that an agency can talk a panicking client
    through over the phone. Both are named uninstall triggers in the research.

## Visibility vs notification

The plugin has two audiences on one install. The agency wants signal; the client wants
calm. The research resolution is that this is solved by **where output goes**, not by how
loud it is.

**Client-visible, and deliberately generous (pull).** A full admin page: what is being
watched, when each check last ran, the complete activity log, which protections are active,
what happened this week. The owner chose this explicitly — a visible, credible surface is
what stops them installing a second security plugin on top, and a second security plugin is
a real hazard, not a hypothetical: AIOS *"overrides the existing configurations set by other
security plugins"*, and a documented Hide My WP × Wordfence conflict blocked logins outright.

This also answers the one failure mode of hardening-only plugins: they die because nothing
ever appears to happen. Headers Security Advanced sits at 90k installs on a 98 rating by
being narrow and *checkable*.

**Push, and deliberately scarce.** Only the six act-now events email the owner. No dashboard
widget, no admin notice, no weekly report, no badge, no nag — ever. Evidence: one user's host
suspended the account after 492 emails; another had their IP blacklisted by Google; agencies
that shipped client-facing security reports killed them because *"nobody was opening those
emails after the first two or three"*. Volume destroys the channel you need for the one
message that matters.

**Agency channel.** WP-CLI commands plus a read-only, signed status endpoint, pollable from
cron, MainWP, ManageWP or a script. **No inbound control channel and no stored credentials** —
agencies distrust central consoles precisely because they have *"effectively admin access to
everything, a nice target for hackers"*.

## Watch — the detections

Chosen because the attack research names them as high-yield and free tooling largely
ignores them.

| Detection | Why |
|---|---|
| `mu-plugins` directory contents | Auto-load, and **never appear on the Plugins screen**. A standing backdoor technique since Feb 2025 |
| Executable PHP appearing under `uploads` | Uploaders are 22.9% of backdoors; the file is served directly and never routes through WordPress |
| `users_can_register` + `default_role` | Flipping two rows is how one 2021 campaign hit 1.6M sites with 13.7M attacks in 36 hours |
| New administrator / role escalation | Rogue admins went 32.69% → 55.2% of database-malware sites in a year |
| Plugin & theme file hashes vs wordpress.org | **Not the version header** — the Modular DS campaign downgraded 15,000 sites to a vulnerable build and edited the header to show the patched version |
| Cron events with no registering plugin | 2.14% of cleanups had malicious cron re-dropping backdoors |
| `wp_options` diff against known keys | Database-layer backdoors (`_hdra_core`, `global_wordpress_setting`) survive every file scan |
| Theme/plugin file editor use, admin email change | Cheap, unambiguous, high signal |

## Guard — the one-checkbox catastrophes

Nothing on the market quietly watches these, and they are what clients actually call about.

- **Settings → Reading, "discourage search engines"** — one case cost eight months of
  ranking. *"I've had that happen to many clients. One checkbox."*
- **Forms submit but mail never sends** — *"clients who didn't know their contact forms
  stopped sending emails weeks earlier."* The governing line: *"Uptime green plus HTTP 200
  is the trap."*
- **Admin email pointing at a departed employee's mailbox**
- **Last administrator deleted or demoted**

## Harden — reversible only

Security headers, XML-RPC, file editor, user enumeration (REST + `?author=N`), version
disclosure, registration lock, application-password scoping. Each with one-click undo and a
verifiable before/after.

Nothing that can lock anyone out. Aggressive zero-config hardening is empirically settled:
AIOS's 1★ wall is *"You will get locked out of website"*, *"It broke my admin dashboard
access"*, and twice *"Unindexed website from Google and destroyed all my SEO"*.

## Tell — the six

Budget: one or two firings per year on a healthy site.

1. New administrator account appeared
2. An existing user was escalated to administrator
3. Registration opened, or default role changed to administrator
4. New file in `mu-plugins`, or executable PHP under `uploads`
5. A plugin or theme's files no longer match wordpress.org
6. A one-checkbox catastrophe fired

Rules: one event, one email, deduplicated; the plugin named in the subject; one-click
unsubscribe in every message; a real emergency is never suppressed by a rate cap; plain
language naming a thing the reader owns, a verdict, and one action. Never routine events in
threat language — *"Who at Wordfence thought it was a great idea to word plugin update
alerts like they were hacking attempts? This fear mongering over-embellishment is ridiculous
and predatory."*

## Technical

**Storage.** One custom table via `dbDelta()`, schema version in an option, migrated on
`plugins_loaded` (activation hooks do not run on update, nor per-site on network activation).
Thin rows: `BIGINT` id, UTC `DATETIME`, integer/slug event code (**never a translated
string** — the log must stay searchable and locale-independent), `severity` tinyint, IP as
`VARBINARY(16)` via `inet_pton()`, `user_id`, JSON context. Index `(event_time)` and
`(event_type, event_time)`. Hard row cap with delete-oldest in the same write.

**Options.** `$autoload` passed explicitly on every call — WP 6.6 changed the default to
`null` and 6.7 deprecated `yes`/`no`. One autoloaded settings array; nothing else. Never
autoload anything unbounded.

**Logging discipline.** The recorder takes an explicit allow-list of fields, never `$_POST`
wholesale. **Never log credentials** — AIOS wrote plaintext passwords into its audit log on
1M+ sites for two months.

**Background work.** WP-Cron with a WP-CLI command for every job and a Site Health test that
reports whether the last run actually happened. A security plugin that silently stops
checking is worse than none. No Action Scheduler in v1 — the row cap removes the job that
most needed reliability.

**Admin UI.** Plain PHP, Settings API, `WP_List_Table`. No build step, no React, no bundled
libraries. **Zero front-end CSS and JS.**

**Performance budget.** Front-end execution < 5 ms; **0 DB queries** and **0 DB writes** on
an ordinary front-end request; peak memory < 2 MB; 0 extra HTTP requests; 0 remote calls on
the request path; total plugin tables < 25 MB steady state. This clears SecuPress (12 ms,
the lightest plugin anyone has measured) and is ~11× lighter than Wordfence. It is reachable
only because we log events, not traffic.

**Plugin self-security.** Every REST route gets a real `permission_callback` — nothing
reading the log is public. Every admin-post/AJAX handler gets a nonce *and* a capability
check; no `wp_ajax_nopriv_`. Authentication logic lives in `permission_callback`, not inside
the handler, and fails closed — Really Simple Security shipped a CVSS 9.8 auth bypass to
4,000,000 sites by checking a helper's return value nowhere. **Escape every log field on
output**: an unauthenticated stored XSS in the log viewer is the single most likely bug in
this plugin, and WP Cerber shipped exactly that.

**Distribution.** Set the `Update URI` header — without it a slug collision lets
WordPress.org overwrite this plugin with a stranger's code. Ship **no updater code** in v1;
install by file copy or git. WordPress.org listing and a private update channel are mutually
exclusive (Guideline 8), and running an update server means becoming a supply chain — the
route by which a backdoor reached 400,000+ sites in the EssentialPlugin case. Revisit once
stable; `.org` is probably right, since clients then get updates through normal WordPress
with no token on any client site.

## Build order

1. **Foundation** — skeleton, capped table, event recorder, settings, clean uninstall, kill
   switch, WP-CLI scaffold
2. **Watch** — the eight detections, on cron, each with a CLI command
3. **Guard** — the four catastrophes
4. **Tell** — the six alerts, plus the client-visible dashboard and log viewer
5. **Harden** — reversible toggles with undo and receipts
6. **Agency channel** — signed read-only status endpoint

Stages 1–4 are already a useful product.

## Deliberately out of scope

Virtual patching · curated malware signatures · real-time IP reputation · uptime monitoring ·
Google/Norton blocklist checks · cloud WAF · off-site log retention · malware removal. Each
is a recurring human or infrastructure cost per site, forever. A free plugin can tell you
almost everything a paid one can; what it cannot do is stop the specific exploit or clean up
after it.
