# Karetaker — why the code is what it is

No comments in the code body. The plugin and file headers stay, because WordPress parses
them and Plugin Check requires them. Everything else is here.

Design rationale, the research behind it and the out-of-scope list live in
`docs/superpowers/specs/2026-09-13-karetaker-security-plugin-design.md` in the site repo.
Read that first; this file only covers what looks removable from inside the code.

## `karetaker.php`

**`Update URI: false` is deliberate and must stay.** Without it, a slug collision lets
WordPress.org push a *different* plugin of a similar name over this one — that is the
documented reason the header exists. `false` disables update checks entirely, which is
correct while the plugin is installed by file copy. If it is ever listed on WordPress.org,
remove the header; if it is ever self-hosted, point it at the update host. Never leave it
blank.

**The schema check is NOT on `plugins_loaded`, and that is a performance fix, not an
oversight.** The first version called `Karetaker_Schema::maybe_upgrade()` from
`karetaker_boot()`, which reads `karetaker_schema_version` — an option
deliberately stored with `autoload = false`. Measured with `SAVEQUERIES` on a front-end
load: **one extra `SELECT` on every request, forever**, against a stated budget of zero. The
check now runs on `admin_init`, on the scan cron, and on `init` under WP-CLI — the only
contexts where a schema migration can matter. Measured after: **0 queries.**

Do not "fix" this by autoloading the version option instead. Autoload is a site-wide
resource, Site Health flags the total as critical around 800KB, and a value only needed in
admin has no business in the bundle every front-end request pays for.

**`karetaker_is_disabled()` checks a constant first and a file second, in that
order.** The constant is free; the `file_exists()` is a stat call that only runs when the
constant is absent. Both exist because the kill switch has to work for a panicking client on
the phone: an agency can talk someone through pasting one line into `wp-config.php`, or
through creating an empty file in `wp-content/` with their host's file manager. Every lockout
complaint in the research resolved through FTP, cPanel or a host ticket; this is the in-band
replacement, and it must not depend on the database or the admin screen being reachable.

**Text domain loads on `init`, not `plugins_loaded`.** WP 6.7 added the
`_load_textdomain_just_in_time` `_doing_it_wrong` notice and broke a lot of plugins that
loaded earlier.

## `includes/class-schema.php`

**`maybe_upgrade()` exists because activation hooks do not run on plugin *update*, and do
not run per-site on network activation.** A schema version in an option, checked in admin,
is the only thing that reliably migrates an installed site.

**`trim()` deletes by `id`, not by date, and it is called from the insert path — not from
cron.** This is the single most important design decision in the storage layer. Wordfence
and Solid Security both prune their log tables on WP-Cron, and both fail the same way:
WP-Cron is traffic-driven and degrades exactly when rows accumulate fastest — under bot
load, on a slow site, on an overloaded host. Growth rate goes up and prune reliability goes
down at the same moment. A table that *cannot* exceed the cap never needs the prune job to
work.

Deleting `WHERE id <= $latest_id - $cap` uses the primary key and touches no other index, so
it stays cheap regardless of table size. It relies on `id` being a gapless-enough
autoincrement; gaps only make the table *smaller* than the cap, never larger, which is the
safe direction.

**`count()` and `size_bytes()` are for the admin screen and WP-CLI only.** `COUNT(*)` is a
full scan of the retention window; never call it on a front-end path, and never use it to
decide whether to trim — that is what the id arithmetic is for.

## `includes/class-events.php`

**`record()` rejects any event code not in `codes()`.** The registry is the allow-list: it
fixes the severity of each code in one place and makes an unknown or attacker-supplied code
a no-op rather than a row. Severity is never passed in by the caller.

**Event codes are stored as slugs, never as translated strings.** A translated string in the
log makes it unsearchable and locale-dependent — the same event would be two different rows
on two sites. Translate at render.

**`sanitize_context()` takes an allow-list shape, and the caller must pass named fields —
never `$_POST` wholesale.** All-In-One Security wrote submitted passwords in plaintext into
its own audit log, twice per successful login, across 1M+ sites for about two months. The
logger cannot be the thing that decides what is sensitive; the call site is. The key cap and
the value truncation exist so a single crafted event cannot blow up the row size the
retention maths depends on.

**`client_ip()` returns `REMOTE_ADDR` unless a trusted proxy is configured, and this is a
security boundary, not a convenience.** A spoofable IP key does not merely let an attacker
evade a rate limit — it lets them **write into any other visitor's counter**, so a forged
header carrying the administrator's address locks the administrator out. The brute-force
defence becomes a denial-of-service primitive handed to the attacker. There is a CVE of
exactly this shape: *WordPress Web Application Firewall ≤2.1.2, IP address spoofing to
protection mechanism bypass*.

Both `forwarded_header` and `trusted_proxies` must be set, **and** `REMOTE_ADDR` must fall
inside one of the CIDRs, before any header is read. Naming the header alone does nothing —
that is the point: the header is only trustworthy because of who it came from.

The opposite failure is just as real and is why the setting exists at all: behind a CDN with
IP detection unconfigured, every visitor collapses to one address, so one block takes the
whole site down. That is the documented root cause of the `127.0.0.1` lockouts reported
independently against Wordfence, AIOS and Sucuri.

**`ip_in_cidr()` compares packed addresses from `inet_pton()`**, so IPv4 and IPv6 share one
code path and a v4-against-v6 comparison is rejected by length rather than silently matching.

**IP is stored as `VARBINARY(16)`, not a string.** It holds IPv6 in the same column, and it
keeps the row and any index on it small — the row size is what the retention cap is really
budgeting.

**`query()` builds its `WHERE` from a fixed set of clauses and passes every value through
`prepare()`.** Nothing from a request reaches the SQL as a string. When the log viewer is
built, **escape every field on output** — an unauthenticated stored XSS in the log viewer is
the most likely bug this plugin will ever ship, because the log deliberately stores
attacker-controlled strings (usernames, user agents, request paths). WP Cerber shipped
exactly that bug.

## `includes/class-settings.php`

**One option, one array, `autoload = false`.** Settings are read in admin and cron, not on
every front-end request, so they do not belong in the autoload bundle. The static `$cache`
makes repeated reads within a request free.

**`row_cap()` clamps rather than trusts.** The cap governs how large the table can grow; a
zero or a huge number from a bad write would either delete everything or disable retention.
Clamping in the accessor means every caller gets a sane value without repeating the check.

**`alert_email()` falls back to `admin_email`.** A handed-over site will often never have
this set, and an alert with nowhere to go is the same as no alert.

## `uninstall.php`

**Uninstall must be provably total: table dropped, options deleted, cron unscheduled.**
Unclean uninstall is its own complaint category across three of the major plugins — leftover
`.htaccess` entries, orphan tables, alert emails still arriving after deletion, and in one
case leftovers that broke a host migration. One review of a competitor reads *"the plugin
changes names if you deactivate it."*

The cron unschedule loops rather than calling `wp_next_scheduled()` once, because duplicate
events for the same hook can and do accumulate.

## Testing

There is no test harness in the plugin yet. The foundation was verified with a throwaway
script against the live local site — 27 assertions covering event codes, severity mapping,
the three IP trust cases, CIDR maths for v4 and v6, context sanitising, query round-trip,
the row cap, and the kill switch. When this gets a real suite, those are the cases to port
first; the IP trust cases especially, since that is the one place where a regression is a
vulnerability rather than a bug.

## `includes/class-hooks.php`

**`$creating` is set from `wp_pre_insert_user_data` and cleared in `user_register`, and the
ordering is the whole point.** `wp_insert_user()` calls `$user->set_role()` — which fires
`set_user_role` — **before** it fires `user_register`. The first version set the guard inside
`on_user_register()`, which is too late, and it failed two ways at once: creating an
administrator logged `role_escalated` *before* `admin_user_added` (three rows for one act),
and because the guard was a per-user array that nothing ever cleared, **every later
escalation of that user was silently suppressed forever**. Both were caught by test, not by
reading. `wp_pre_insert_user_data` fires before the insert and carries `$update`, so it is
the only hook early enough.

**Failed logins are logged as a burst, never individually — and this protects signal, not
size.** The row cap keeps the table small no matter what, but it cannot keep the table
*useful*: under a brute-force run, thousands of `login_failure_burst`-shaped rows per hour
would evict every admin-created, file-changed and option-flipped event out of the retention
window. The attack would erase the evidence of itself by filling the log with its own noise.
So a transient counts attempts per IP and exactly one row is written when the threshold is
crossed — `self::BURST_THRESHOLD !== $count` rather than `>=`, so a long attack writes one
row per window, not one per attempt after the tenth.

The same reasoning is why there is no per-request logging anywhere in this plugin. Log
events, not traffic.

**`on_login_failed()` passes user id `0` deliberately.** A failed login has no authenticated
user, and `get_current_user_id()` would attribute the row to whoever happens to be logged in
in that process.

**The `update_option_*` listeners compare old to new and stay silent on a no-op**, because
WordPress fires these for any write, not only for a change of value. Closing registration or
setting a harmless default role records `setting_changed`, not an act-now event — only the
dangerous direction is an alert.

**A wrinkle worth knowing before the options scanner is written.** If something pins an
option with a `pre_option_*` filter — `spice-hardening` on the Spice site pins
`users_can_register` to `0` — then `update_option()` compares the *filtered* old value
against the new one, decides it changed, writes a row that already holds that value, gets
zero affected rows, and returns `false` **without firing the action**. The raw database row
can therefore sit at `1` indefinitely while the site behaves as `0`.

Two consequences. The hook layer cannot see that drift at all, which is fine — behaviour is
what matters and the filter wins. But the **options scanner reads raw values**, so it must
either compare against the filtered value or report the mismatch as its own finding. A raw
`1` with a filter forcing `0` is not a compromise, and reporting it as one would be exactly
the false positive this plugin exists to avoid.

**`on_file_editor()` hooks `wp_ajax_edit-theme-plugin-file` at priority 0**, so it records
the attempt before core decides whether to allow it. That is intentional: an attempt to use
the file editor is the signal, and with `DISALLOW_FILE_EDIT` set the core handler will refuse
anyway.

## `includes/class-scanner.php`

**Everything here runs on cron or WP-CLI, never on a request path**, and every scan checks
`out_of_time()` before it starts. Measured on the Spice site (565 upload files, 60 mu-plugin
files): a fresh run that takes every baseline costs **0.013s**, a warm diff-only run **0.005s**,
peak memory increase **0.0 MB**.

**Only `mu-plugins` is hashed. Uploads is matched by filename, not content.** That is what
keeps the cost linear and tiny on a photo-heavy site: hashing scales with bytes, name
matching scales with entries. `mu-plugins` is always small and is the directory that
auto-loads and never appears in the Plugins screen, so it is worth the full hash.

**There is no whole-site file baseline in v1, deliberately.** Hashing every plugin and theme
file means storing tens of thousands of hashes, and the storage design for that is a separate
problem — an option would be autoload poison and a table needs its own retention thinking.
Core and WordPress.org-hosted plugins are better served by the published checksums anyway.
Themes, premium plugins and the child theme are the genuine gap, and they are what a baseline
store would be *for*; do that deliberately, not as a side effect.

**WordPress.org publishes plugin checksums but not theme checksums.** Verified 2026-09-13:
`downloads.wordpress.org/plugin-checksums/{slug}/{version}.json` returns 200 with per-file
`md5` and `sha256`; the theme equivalent 404s. Core has its own endpoint
(`api.wordpress.org/core/checksums/1.0/`, md5 only). **The per-file value is sometimes a
string and sometimes an array** — an array when a file legitimately has several accepted
hashes across point releases — so any comparison must handle both or it will report false
mismatches on perfectly clean installs.

**`scan_uploads()` resumes by index, and the cursor resets only on a completed pass.** A
partial pass merges what it found into `uploads_found` rather than replacing it, because a
half-finished list would look like files had disappeared. Only a full pass is allowed to
replace the list, which is what makes "this file is gone now" trustworthy.

**`scan_cron()` requires a hook to be seen orphaned on two consecutive scans before it is
reported, and this is the false-positive guard.** Cron runs on a front-end request, so any
plugin that registers its callback only in the admin looks orphaned from there. Measured on
the Spice site: 25 scheduled hooks, 2 flagged — `wpseo-reindex`, which is Yoast registering
conditionally and is a **false positive**, and
`elementor_one/image_optimizer_license_info_hook`, which is a **true positive** left behind
by a plugin that has been deleted from disk.

Two scans do not fix the admin-only blind spot (both scans share it), so the honest position
is that this check is **lower precision than the others** and is deliberately
`SEVERITY_ATTENTION`, never one of the six act-now alerts, and must be worded as something to
look at rather than a threat. Mixing a low-precision check in with high-precision ones is
what spends the credibility of the high-precision ones.

`cron_reported` exists so a confirmed orphan is announced once, not on every run forever.

**`scan_options()` reads the raw database value *and* the filtered value, and compares
baselines against the filtered one.** See the `update_option` wrinkle in the hooks section:
an option pinned by a `pre_option_*` filter can leave a stale raw row that never matches
behaviour. The mismatch is recorded in `options_masked` for the dashboard to show as context,
and is deliberately **not** an alert — a raw `1` with a filter forcing `0` is a correctly
hardened site, and calling that a compromise is exactly the false positive this plugin exists
to avoid.

**`scan_muplugins()` returns `baseline_taken` on first sight and never alerts on it.** A
plugin installed onto an already-compromised site would otherwise bless the backdoor as the
baseline — which is a real limitation, not a solved problem. The checksum scan is what
catches pre-existing tampering; the baseline only catches change from now on. Both are needed
and neither replaces the other.

## `includes/class-checksums.php`

**This is the only check that can catch tampering that predates the plugin.** Everything else
here diffs against a baseline taken on first run, so a backdoor already present when Karetaker
is installed becomes part of the baseline. Published checksums have no such blind spot. The
two are complementary and neither replaces the other.

**Per-file hashes are sometimes a string and sometimes an array**, and `hash_matches()` exists
solely because of that. WordPress.org lists several accepted hashes for a file that changed
legitimately across point releases — `readme.txt` most often. Comparing a string against the
array directly would report a false mismatch on a perfectly clean install. `hash_equals()`
rather than `===` because this is a hash comparison and constant time costs nothing here.

**`looks_broken()` is the Wordfence lesson encoded.** In November 2024 Wordfence flagged
**2,425 WordPress core files** as unknown, all at High severity, because its mirror of the
core release "did not complete normally and stopped halfway". An admin with 200+ client sites
wrote that it *"caused such a panic on our end"*, and users ended up verifying core against
GitHub themselves — doing the scanner's job for it.

So: if more than 20% of checked files mismatch, or more than 25 mismatch with nothing checked,
the **check is more likely broken than the site**. That case records a `scan_ran` row noting
the suppression and raises nothing. A real compromise modifies a handful of files; it does not
modify a fifth of core. The floor of 25 exists so a tiny plugin with four files cannot trip the
ratio.

**Core verification is resumable, and this was a real flaw before it was fixed.** Measured:
3,338 core files take **2.81s cold** and **0.668s warm**. On a slow shared host that can exceed
the whole scan budget, and the first version simply returned `partial`, never set
`core_verified`, and started from the beginning on the next run — **retrying forever and never
reporting anything**. It now carries `core_offset`, `core_carry` (mismatches found so far) and
`core_checked` between runs. Verified by driving it with a 0.02s deadline: 10 partial passes,
then complete, with `checked` totalling exactly the same 3,338 as an uninterrupted run.

**`core_checked` has to carry, not just the mismatch list.** Without it a resumed run would
finish reporting a handful of files checked against a full list of accumulated mismatches, and
`looks_broken()` would suppress a genuine finding on a bogus ratio — the sanity guard would
become the bug.

**`wp-content/` entries in the core manifest are skipped.** The core package ships default
themes and a `plugins/hello.php` that any site may legitimately remove or replace; treating
those as core files produces noise about a site being perfectly normal.

**A missing file is not reported, only a changed one.** A partial install, a host that strips
files, or a removed default theme are all common and none of them is an attack. Added files
are the interesting case, and for plugins that is what `scan_uploads` and the `mu-plugins`
baseline cover.

**Plugins not on WordPress.org degrade to `unavailable`, never to a finding.** Verified live:
`elementor-pro`, `animation-addons-for-elementor-pro` and `novamira-pro` all return
`unavailable` while `elementor`, `animation-addons-for-elementor` and `wordpress-seo` verify
complete. A premium plugin is not suspicious for being premium, and Karetaker itself is
`unavailable` for the same reason.

**Fetched checksum JSON is cached for 12 hours and plugins are verified round-robin, three per
run.** Elementor's manifest alone is 535KB; fetching every plugin's manifest on every run would
be both slow and rude to WordPress.org. The cursor in `plugin_cursor` walks the active list so
every plugin comes round in a few days.

**Live results from the Spice site, 2026-09-13:** core 3,338 files / 0 modified, Elementor
3,023 / 0, Yoast 1,964 / 0. A tampered `readme.txt` was detected and the file restored
byte-identical.

## `includes/class-guard.php`

**A Guard trip is transition-only: bad ∧ ¬was_bad, never a sticky re-fire.** `evaluate()`
compares the new condition against the flag stored in `karetaker_scan_state['guard'][$check]`
and only calls `Karetaker_Events::record( 'guard_tripped', … )` on the rising edge. Once a
check is already bad, cron and hooks keep refreshing `bad` / `since` but write no more rows —
otherwise a discouraged-search-engines site would emit `guard_tripped` on every scan forever
and burn the ACT alert budget on noise. Clearing the condition resets the edge so a later
re-trip is a real new event.

**Mail is `wp_mail_failed` only — not a successful send, not SMTP probes, not a custom
transport.** The research failure mode was plugins that treat "we tried mail" as evidence and
then spam the owner whenever the host's mailer is merely slow. Hooking the failure action
means the check lights up when WordPress itself reports that delivery failed, and the sticky
`mail_failed` flag in scan state keeps the Overview honest until something clears it; the
event still fires only once, via the same transition rule.

**Administrator absence is counted by capability (`manage_options`), not by the
`administrator` role slug alone.** A site that renames or splits the role still has someone
who can recover it; counting capability matches how the rest of WordPress decides who is an
admin. The count is capped at two in the query — we only need "zero vs at least one".

## `includes/class-alerts.php`

**Alerts listen to `karetaker_event_recorded`, not to the hooks that produced the event.** The
event layer is the single place that has already decided the code, severity and sanitised
context; Tell must not re-derive "was this ACT?" from `set_user_role` or a scan result, or the
two layers drift. Severity is checked first: only `SEVERITY_ACT` can mail.

**Dedupe is a 24-hour transient keyed on `md5( code | json(context) )`, set only after
`wp_mail` reports success.** A failed send must be allowed to retry; a successful one must
not mail again for the same shape within a day — that is the whole scarcity model. Context is
`ksort`'d before hashing so key order cannot create two identities for one fact. The kill
switch is checked again here even though `record()` already bails when disabled, because an
event recorded *before* the file appeared could still be mid-flight in the same request.

**The body points at Tools → Karetaker Settings to turn alerts off.** There is no separate
unsubscribe token or public endpoint — that would be a new attack surface for a plugin whose
job is to shrink surface area. Settings copy is enough for a site the owner can still reach;
if they cannot reach it, the kill-switch file is the out-of-band path.

## `includes/class-harden.php`

**Every toggle defaults off.** Observation and Guard stay on; Harden only registers hooks for
keys the owner has set to true. A fresh install therefore ships zero Harden filters and zero
`DISALLOW_FILE_EDIT` from this class.

**No CSP.** Headers send nosniff, SAMEORIGIN frame, referrer, permissions-policy, and HSTS
only when `is_ssl()` and the environment is not `local`. Content-Security-Policy is left to
the host or a dedicated plugin — a wrong CSP locks the admin UI and that is not this plugin's
job.

**Registration is closed with `pre_option_users_can_register`, not `update_option`.** Writing
the option would fight the owner's Settings → General choice and confuse Guard / scanners
that read raw SQL. The filter forces behaviour to closed while Desired is on; turning Desired
off removes the filter and the stored option is what it always was.

**`DISALLOW_FILE_EDIT` is defined only when undefined.** If the host or `wp-config.php`
already set the constant, Harden does not redefine it (PHP would fatal). Live now reports
`Blocked by host/wp-config` when Desired is off but the constant is already true.

**Idempotent with `spice-hardening` (and similar mu-plugins).** Double-disabling XML-RPC,
double-closing registration via `pre_option_*`, or sending the same headers twice is safe —
Harden never assumes it is the only actor and never writes server config files.

**Probes are derived each render, never stored.** There is no “protected” score in settings;
`probe()` reads Desired, boot state, filters, and constants for the current request so Live
now cannot drift from a cached boolean.

**Kill switch:** `karetaker_boot()` returns before `Karetaker_Harden::init()`, so no Harden
filters or defines register for that boot. The class file is still required at load so Admin /
CLI can read Desired and explain inactivity.

## `includes/class-admin.php`

**The screen lives under Tools (`add_management_page`), not a top-level admin menu.** A
security plugin that adds its own sidebar icon on every client site trains agencies to ignore
yet another badge; Tools is where "look at the log / change the email" belongs for a plugin
that is meant to stay quiet. Capability is `manage_options` end to end — menu, render, and
`admin_post` save — and the save handler checks the nonce before touching settings.

**Harden has its own tab and `admin_post_karetaker_save_harden` form.** Settings stays focused
on alerts, proxies and row cap; each Harden checkbox change logs `setting_changed` with
`option` = `harden.{key}` only when the value actually flipped.

**No front-end assets are enqueued, from this class or anywhere else in the plugin.** The
admin UI is plain `wrap` markup and core list-table styles. A public CSS/JS bundle would be a
permanent front-end cost for a product whose stated budget is zero queries and zero assets on
the logged-out home page.

**`class-admin.php` (and the list table) load only inside `is_admin()` in `karetaker_boot()`.**
Guard and Alerts still `require` / `init` on every request so option hooks and
`karetaker_event_recorded` work when something changes from the front or from cron; Admin is
UI-only and has no business on that path.

## `includes/class-list-table.php`

**Every column goes through `esc_html`, including context rendered as JSON.** The activity
log deliberately stores attacker-controlled strings; an unescaped viewer is the most likely
XSS this plugin will ever ship (see the events section). Severity and user id are cast before
escape so a weird DB type cannot slip markup through.

**The User column is the numeric `user_id`, not a display name.** Resolving logins on every
row would add per-page user lookups to a screen that already runs `COUNT(*)` for pagination,
and a deleted user would leave a blank that looks like a bug. Filters (severity, code, date)
are deferred — v1 is newest-first, page size 20, unfiltered `Karetaker_Schema::count()` —
because shipping the escape-correct table mattered more than shipping a query UI on top of it.
