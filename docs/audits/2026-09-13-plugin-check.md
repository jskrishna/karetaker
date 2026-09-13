# Plugin Check — 2026-09-13

Ran against a **clean dist copy** (dev files excluded) via Plugin Check 2.1.0 on Local
(`spice-web-media.local`). Live symlink tree also smoke-tested.

## Slug

`https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=karetaker`
→ **404 Plugin not found** — slug still free.

## CLI smoke (symlink install)

- `wp karetaker status` — OK (version 0.1.0, harden defaults off)
- `wp karetaker scan --budget=5` — OK
- `wp karetaker emit` / `log` — OK

## Clean-package findings

### Errors (1)

| Code | Meaning | Action |
|---|---|---|
| `plugin_updater_detected` | `Update URI: false` in header | **Remove only at first .org upload** (keep while hand-distributing) |

### Warnings (expected / accepted)

| Code | Notes |
|---|---|
| `WordPress.DB.DirectDatabaseQuery.*` | Own events table — product, not a bug |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | Mitigated: `Schema::table()` uses `esc_sql()`; query builder still prepares values |
| (cleared) `load_plugin_textdomainFound` | Removed — .org auto-loads under the slug |

### Repo-only noise (not in dist)

`phpcs.xml.dist`, `composer.*`, `CLAUDE.md`, `CODE-NOTES.md`, `.gitignore` — excluded by `.distignore`.

## How to re-run

```bash
ln -sfn "$HOME/Library/Application Support/Local/run/VtIo8OKoV/mysql/mysqld.sock" /tmp/mysql.sock
wp --path="$HOME/Local Sites/spice-web-media/app/public" plugin check karetaker --slug=karetaker
```

For a fair .org preview, rsync with `.distignore` into a temporary plugin folder first.

## Slug re-check (step 2)

2026-09-13 06:09 UTC: API `plugin_information` for `karetaker` still **404 Plugin not found**; directory page also unavailable. Slug remains free.

## Upload package (step 3)

Built with `tools/build-dist.sh` → `dist/karetaker-0.1.0.zip`.
- Dev files excluded (same as `.distignore`).
- `Update URI: false` stripped **in the zip only**; working tree still has it.
- Assets stay in `assets/` for SVN `/assets`, not inside the zip.
- Actual WordPress.org submission still needs a human (account + SVN/upload UI).
