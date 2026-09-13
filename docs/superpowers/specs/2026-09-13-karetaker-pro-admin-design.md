# Karetaker Pro Admin — design

Date: 2026-09-13  
Status: **approved in conversation** (stack A: PHP + CSS + vanilla JS; phased delivery one-by-one)  
Supersedes: `2026-09-13-karetaker-admin-ui-design.md` (WP-native polish approach)  
Repo: `~/karetaker` only  
Parent: `docs/design.md` (product thesis unchanged)

## Goal

Make Karetaker feel like a **professional security product** in wp-admin: owned layout, clear hierarchy, agency-grade Activity ops, and Overview that answers “is this site OK?” — **without** becoming a WAF, signature scanner, or login-lockout plugin.

## Locked decisions

| Topic | Choice |
|---|---|
| Delivery | **Phased** — ship and review each phase before the next |
| Admin stack | **PHP templates + CSS design system + vanilla JS** — no React, no bundler |
| Menu | Top-level `add_menu_page`, position ~80, slug `karetaker` |
| Legacy URL | `tools.php?page=karetaker` → `admin.php?page=karetaker` (keep) |
| Push channel | Email on **ACT only** (unchanged); Phase 6 webhooks **ACT-only, opt-in** |
| Credits | Small footer on every tab — Team Krikir; no About tab |
| Front-end | **Zero** plugin CSS/JS on public site (admin-only assets) |

## Product boundaries (unchanged)

Still **out of scope** unless product thesis is explicitly reopened:

- WAF / request firewall, malware signatures, IP reputation, uptime monitoring
- Login lockout / hide-login as default
- Writing `wp-config.php`, `.htaccess`, server config
- Off-site log vault, malware removal service
- Dashboard widgets, admin nags, weekly email digests (Phase 6 webhook is **per ACT event**, not digest)

## Visual language

**Watchtower brand** (aligned with directory icon / marketing assets):

| Token | Role | Value (starting point) |
|---|---|---|
| `--kt-ink` | Headings, primary text | `#141414` |
| `--kt-muted` | Secondary copy | `#5c5c5c` |
| `--kt-surface` | Page background inside app | `#f5f4f0` |
| `--kt-panel` | Cards | `#ffffff` |
| `--kt-accent` | CTAs, active nav, key metrics | `#c17a2e` |
| `--kt-accent-soft` | Accent backgrounds | `#f3e8d8` |
| `--kt-line` | Dividers | `#e2e0da` |
| `--kt-danger` | Act-now, kill switch | `#8a2424` |
| `--kt-radius` | Cards, buttons | `10px` |
| `--kt-shadow` | Panels | `0 1px 2px rgba(20,20,20,.06), 0 8px 24px rgba(20,20,20,.04)` |

Typography: system UI stack (`-apple-system`, `Segoe UI`, etc.) — no external font CDN (wp.org friendly).

**Isolation:** All rules live under `.karetaker-app` (or `.karetaker-wrap.karetaker-app`) so we do not restyle global wp-admin. Hide reliance on `widefat`, `nav-tab-wrapper`, and `#wpbody-content .wrap` defaults inside our shell.

## Architecture

### Render surface

- **`Karetaker_Admin`** remains orchestrator: tabs, save handlers, nonces, capabilities.
- **Partials** (new): `includes/admin/partials/` — `shell-header.php`, `shell-nav.php`, `shell-footer.php`, per-tab bodies as needed.
- **`Karetaker_List_Table`** stays for Activity Phase 3 baseline; may gain filter args or yield to custom table markup in same phase if list table fights the design (prefer extending query + custom `display()` wrapper first).

### Assets

| File | Purpose |
|---|---|
| `assets/admin.css` | Full design system + layout |
| `assets/admin.js` | Nav affordances, event detail drawer, dismissible help, `Run scan` UX (spinner/disabled) |
| `assets/menu-icon.svg` | Sidebar menu (shipped in zip) |

Enqueue only on `toplevel_page_karetaker`. Version via `filemtime`.

### Routing

- Tabs: `admin.php?page=karetaker&tab={overview|activity|harden|settings}` (unchanged).
- Activity filters: **GET query args** (bookmarkable, no AJAX required): e.g. `severity`, `code`, `since`, `until`, `paged`, `s` (search — Phase 3).
- New actions: `admin_post_karetaker_run_scan` (Phase 2) — nonce + `manage_options`, then redirect back to Overview with `scan=started` or results flash.

### Security (all phases)

- Escape all output; context JSON only in `title` or `<pre>` inside drawer with `esc_html`.
- Every new POST: nonce + `manage_options`.
- Export (Phase 3): capability check, no `nopriv`, rate-limit via short transient per user if needed.

---

## Phase 1 — Custom shell

**Outcome:** Page reads as **Karetaker**, not “Settings with cards”.

### Layout

```
┌─────────────────────────────────────────────────────────┐
│ [icon] Karetaker · A watchtower for WordPress.          │
├─────────────────────────────────────────────────────────┤
│ Overview │ Activity │ Harden │ Settings   (pill nav)    │
├─────────────────────────────────────────────────────────┤
│ { tab content }                                         │
├─────────────────────────────────────────────────────────┤
│ Karetaker v0.x · by Team Krikir                         │
└─────────────────────────────────────────────────────────┘
```

- Replace core `nav-tab-wrapper` with **pill nav** inside `.karetaker-app`.
- Content max-width ~1200px; generous vertical rhythm.
- Empty states: illustration-free copy + one primary action (e.g. “Run first scan” in Phase 2).

### Acceptance

- [ ] Sidebar menu + custom icon unchanged from current top-level registration
- [ ] No dependency on WP card borders for hierarchy
- [ ] `admin.css` + optional `admin.js` ship in dist zip
- [ ] WPCS clean

---

## Phase 2 — Overview command centre

**Outcome:** Owner answers health in one screen.

### UI

- **Posture strip:** Guard bad count, ACT count (7d), last scan age, agency on/off — visual status (ok / attention / act).
- **Run scan now** button → `admin_post_karetaker_run_scan` → calls `Karetaker_Scanner::run()` (same as CLI).
- Last scan: human-relative time (“2 hours ago”) + UTC tooltip.
- Per-slice results from last run (muplugins, uploads, …) as compact status list with icons/colours.
- Kill-switch banner prominent when `karetaker_is_disabled()`.

### Backend

- Reuse `Karetaker_Scanner::run()`; record already exists via `scan_ran` event.
- Optional: admin notice on redirect `?scan=done` with slice summary (no new email).

### Acceptance

- [ ] Manual scan from UI matches `wp karetaker scan` behaviour
- [ ] No new front-end hooks
- [ ] Overview useful when zero events exist

---

## Phase 3 — Activity pro

**Outcome:** Audit log agencies can actually use.

### Filters (GET)

- Severity: All / Log / Watch / Act-now → maps to `min_severity` / exact severity band in `Karetaker_Events::query()` (extend query if needed for max severity or exact match).
- Event code: dropdown of known codes from `Karetaker_Events::codes()`.
- Date range: `since`, `until` (UTC, date inputs).
- Search `s`: SQL `LIKE` on `event_code` + JSON context (bounded, indexed-friendly: prefer code exact + context substring with limit).

### Table UX

- Columns: Time, Severity badge, Code, User (display name + ID), IP, Summary (existing context helper).
- **Row click** → side drawer or modal with full context JSON formatted, event id, copy button (JS + `navigator.clipboard` with fallback).
- Pagination: keep 20/page; show total count.

### Export

- `admin_post_karetaker_export_events` or GET with nonce: CSV of filtered result set (cap 5000 rows) — same filters as list.

### User display

- Resolve `user_id` → `display_name` when user exists; fallback “(deleted)” / “Guest”.

### Acceptance

- [ ] Filters composable and bookmarkable
- [ ] Export respects filters and capability
- [ ] No unescaped context in HTML

---

## Phase 4 — Harden & Settings UX

**Outcome:** Toggles feel intentional, not a spreadsheet.

### Harden

- One **card per toggle**: title, help, Desired switch, Live probe badge (ok/mismatch).
- Group: “Headers & exposure” vs “Access & accounts” (visual only).
- Save bar sticky at bottom on long viewports (CSS only).

### Settings

- Keep sections: Alerts, Retention, Trusted proxies, Agency.
- Agency: step-style copy (generate → copy token once → test curl); mask token always.

### Acceptance

- [ ] Save handlers unchanged (same option keys, same events on change)
- [ ] Harden probe text still from `Karetaker_Harden::probe()`

---

## Phase 5 — Ops & trust

**Outcome:** “Is Karetaker running?” is verifiable.

### Site Health

- Register `direct` or `async` test: last cron scan timestamp, stale if > 36h (configurable constant in code, not UI clutter).
- Link from Overview to Site Health filtered section.

### UI surfacing

- Show next scheduled scan time (`wp_next_scheduled( Karetaker_Scanner::CRON_HOOK )`).
- CLI parity note: “Same as `wp karetaker scan`” near Run scan.

### Acceptance

- [ ] Test appears under Site Health → Status
- [ ] No extra cron jobs

---

## Phase 6 — Agency+ (optional, last)

**Outcome:** Agencies integrate without a control plane.

### Webhook (opt-in)

- Setting: webhook URL + secret; fire **only on ACT** events (same gate as email).
- HMAC signature header; no retry storm (single attempt per event).
- Off by default.

### Status API docs

- In Settings: embedded examples (curl, JSON shape), link to `rest_url( 'karetaker/v1/status' )`.

### Out of scope for Phase 6

- Multisite network admin UI (defer unless requested)
- Slack OAuth app — webhook URL only

---

## Implementation order (one by one)

1. Phase 1 — Custom shell (replace WP-native styling from interim admin UI work)
2. Phase 2 — Overview command centre
3. Phase 3 — Activity pro
4. Phase 4 — Harden & Settings UX
5. Phase 5 — Ops & trust
6. Phase 6 — Agency+ (optional)

Each phase: implementation plan → build → Local smoke → user sign-off → next.

## Files likely touched (by phase)

| Phase | Files |
|---|---|
| 1 | `includes/class-admin.php`, `includes/admin/partials/*`, `assets/admin.css`, `assets/admin.js`, `CODE-NOTES.md` |
| 2 | `class-admin.php`, `class-scanner.php` (maybe thin public wrapper), new `admin_post` handler |
| 3 | `class-list-table.php`, `class-events.php` (query extend), `class-admin.php`, `admin.js`, export handler |
| 4 | `class-admin.php`, `admin.css` |
| 5 | new `class-site-health.php` or method on scanner, `class-admin.php` |
| 6 | `class-settings.php`, `class-alerts.php` or new webhook emitter on `karetaker_event_recorded` |

## Testing

- `composer phpcs` each phase
- Local: all four tabs, save round-trips, run scan, filter/export (Phase 3+)
- Dist zip includes `admin.css`, `admin.js`, `menu-icon.svg`; excludes marketing PNGs

## Acceptance (program)

- [ ] Admin UI no longer reads as default wp-admin Settings
- [ ] Overview + Activity usable for agency handover demo
- [ ] Product thesis and performance budget preserved (admin-only assets)
- [ ] readme.txt + screenshots updated before wp.org bump (not necessarily each phase)

## Non-goals reminder

No React. No public-facing assets. No weekly report email. No remote control API.
