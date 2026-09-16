# Plugin Check: 2026-09-13 (final .org prep)

Ran against **`dist/karetaker-0.1.0.zip`** unpacked as `karetaker-pcp` on Local
(`spice-web-media.local`), Plugin Check 2.1.0. Working tree still has
`Update URI: false`; the zip strips it via `tools/build-dist.sh`.

## Slug

`https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=karetaker`
→ `{"error":"Plugin not found."}`: **still free** (re-checked 2026-09-13 12:56 IST).

## Package

| Check | Result |
|---|---|
| Zip | `dist/karetaker-0.1.0.zip` (~58K) |
| `Update URI` in zip | absent |
| Banner/icon/screenshots in zip | absent (SVN `/assets` only) |
| Plugin Check ERROR | **0** |
| Plugin Check WARNING | 22 (Direct DB family: accepted) |
| WPCS (`composer phpcs`) | clean |

## Upload checklist (human)

1. WordPress.org plugin author account (Contributors: `krikir` must match your login).
2. Re-confirm slug free the day you upload.
3. Upload **`~/karetaker/dist/karetaker-0.1.0.zip`** via Plugins → Add New (or SVN `trunk`).
4. After the plugin is approved / SVN exists, commit directory assets to **`/assets`** (not trunk):
   - `banner-1544x500.png`, `banner-772x250.png`
   - `icon-128x128.png`, `icon-256x256.png`
   - `screenshot-1.png` … `screenshot-4.png` (captions already in `readme.txt`)
5. Keep `Update URI: false` in the **git** working tree until you decide to distribute only via .org; every release zip from `tools/build-dist.sh` already omits it.

## Do not

- Upload the live symlink tree or a zip that still contains `Update URI: false`.
- Put marketing PNGs inside the plugin zip.
- Bump to 1.0.0 for the first upload: Stable tag is `0.1.0`.
