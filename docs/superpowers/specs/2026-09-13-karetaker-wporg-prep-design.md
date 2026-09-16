# Karetaker: WordPress.org prep slice

Date: 2026-09-13  
Status: approved in conversation (readme.txt + docblock backlog; leave `Update URI: false` until first upload)  
Repo: `~/karetaker` only: do not modify `spice-web-media`  
Parent design: `docs/design.md`; house rules: `CLAUDE.md` § Before submission

## Goal

Make the plugin directory-submittable on paper: a valid `readme.txt`, and WordPress-Docs-shaped docblocks on every file, class, method, and function: without adding product features.

## Out of scope

- Removing `Update URI: false` (pre-upload checklist only; keep until first .org upload)
- Screenshots / banner assets (omit Screenshots section until assets exist)
- Full PHPCS CI / GitHub Actions
- Complete i18n audit
- Version bump to 1.0.0
- Reserving the slug on WordPress.org (operator checklist: re-confirm `karetaker` free before upload)

## Constraints

- Prefix `karetaker_`; ABSPATH / uninstall guard unchanged.
- Docblocks are mechanical (`@param`, `@return`, short summary, `@since 0.1.0` on public API). Design rationale stays in `CODE-NOTES.md`.
- Do not rewrite CLI WP-CLI-style docs into a different shape: keep `## OPTIONS` blocks that power `wp help`.
- Do not invent behaviour in docblocks; document what the code does.
- Commits only when the human asks.
- Tested up to: **7.1** (matches Local site `wp_version` at plan time). Requires at least / Requires PHP from plugin header: **6.2** / **7.4**. Stable tag **0.1.0**.

## Deliverables

### 1. `readme.txt` (plugin root)

WordPress.org header + sections:

- Plugin Name, Contributors (`teamkrikir`), Tags (security, monitoring, hardening: no trademark stuffing), Requires at least, Tested up to, Requires PHP, Stable tag, License / License URI
- Short description (≤150 chars), Description (watchtower positioning from `docs/design.md`), Installation, FAQ (what it is / is not, kill switch, uninstall, agency token), Changelog for 0.1.0
- Honest, not a WAF, not malware signatures, login lockout not on by default

### 2. Docblock backlog

Every PHP file under the plugin root and `includes/`:

| Surface | Requirement |
|---|---|
| File | Existing `@package Karetaker` kept; expand one-line summary if thin |
| Class | Class docblock with summary + `@since 0.1.0` |
| Method / function | Summary; `@param` for each arg; `@return`; `@since 0.1.0` on public/protected; private methods get the same param/return shape |
| Constants | Optional brief; not required unless clarifying |

Files: `karetaker.php`, `uninstall.php`, and every `includes/class-*.php` (including `class-cli.php`: fill any missing method docs without breaking WP-CLI blocks).

### 3. Hygiene notes

- Update `CLAUDE.md` § Before submission and `CODE-NOTES.md` to mark readme + docblocks done; list remaining pre-upload: strip `Update URI: false`, Plugin Check / WPCS, re-confirm slug free, optional screenshots.
- Optional local Plugin Check / `phpcs` if available: record result; do not fail the slice if tooling is absent.

## Acceptance

- [ ] `readme.txt` present and parsable (headers match version 0.1.0 / 6.2 / 7.4 / Tested up to 7.1)
- [ ] Every function and method has `@param` / `@return` as applicable
- [ ] `Update URI: false` still present
- [ ] No edits under `spice-web-media` except via existing symlink (source only in `~/karetaker`)
