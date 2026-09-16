# Master promparetaker WordPress.org assets

Copy everything inside the fence below into Claude Code (or any image/design agent).  
Do not soften the size or naming rules.

---

```text
You are a senior brand + product designer shipping WordPress.org Plugin Directory assets for ONE plugin.

## Product
- Name: Karetaker
- Author: Team Krikir
- One-line: A watchtower, not a wall.
- Tagline (use exactly when text appears): A watchtower for WordPress.
- Positioning: calm monitoring / integrity / alertOT a WAF, NOT malware scanner, NOT lockout product.
- Metaphor: watchtower / lighthouse observatioOT shield, padlock, firewall brick wall, bug, virus, skull, or “hacker hoodie”.

## Output directory (overwrite existing marketing files)
Save final files to:
`/Users/jskrishna/karetaker/assets/`

Do NOT put banners/icons/screenshots inside `trunk` packaging logic yourselnly write these filenames:

### A) Directory marketing (exact pixel sizeon-negotiable)
1. `banner-1544x500.png544×500 px, RGB, PNG
2. `banner-772x250.png72×250 px, RGB, PNG (same art as #1, scaled cleanlot a different layout)
3. `icon-256x256.pngXACTLY 256×256 px (not 1024 labeled as 256)
4. `icon-128x128.pngXACTLY 128×128 px (downscale from the same master mark)

### B) Admin menu mark (ships in plugin zip)
5. `menu-icon.svg0×20 viewBox, single-color stroke friendly for wp-admin sidebar (`#a7aaad` stroke OK). Same watchtower geometry as the brand mark, simplified.

### C) Optional logo lockups (for README / GitHuot SVN assets/)
6. `logo-wordmark.pngransparent or dark background, horizontal: mark + “KARETAKER”
7. `logo-mark.pngark only, square, transparent background, ≥512×512 master then also export 256/128 if useful

## Brand system (lock these)
Colors:
- Night charcoal: `#141414` / `#1d2327` (backgrounds)
- Amber gold accent: `#f0c36d` / `#C9A227` / admin accent `#b36a1e`
- Soft cream text on dark: `#F2EFE8`
- Muted gray: `#646970` / `#a7aaad`
- Avoid: purple/indigo gradients, neon glow, cyan cyber grid, stock “security” clichés, cream+terracotta editorial poster look

Typography (banners / wordmarks):
- Strong condensed or geometric sans for “KARETAKER” (all caps, tracked)
- Tagline smaller, muted, sentence case as given
- No Inter/Roboto/Arial look if you can choose; pick a distinctive but readable display sans
- Brand name must dominate the banner; tagline secondary; no extra marketing paragraphs

Mark geometry:
- Geometric line-art watchtower: base, tapered shaft, observation deck, small roof / lantern
- Consistent stroke weight; flat vector; sharp; printable at tiny sizes
- Icon: mark ONLo text on 128/256 icons
- Banner: left text lockup + right (or far-side) large mark with generous negative space
- Full-bleed dark field; no inset card; no floating badge stickers on the hero

## Quality bar
- Pixel-perfect exact dimensions (verify with `sips -g pixelWidth -g pixelHeight` or equivalent)
- Crisp edges; no muddy AI mush; no watermark; no WordPress logo; no “AI generated” look
- Icons must read at 32px equivalent (simple silhouette)
- Banner must read on wordpress.org plugin header (name legible at ~half width)
- Same mark family across banner, icons, menu SVG, logone system, not four unrelated drawings
- GPL-safe original artwork only (no scraped brand marks)

## Screenshots (separate tasnly if asked in the same run)
If generating/capturing plugin UI screenshots for the directory:
- `screenshot-1.png` … `screenshot-4.png`
- Prefer ~1280×900 (or wider), PNG, sharp UI text
- Captions in readme (do not bake caption text into the image):
  1. Overvieroduct intro, last scan, Guard flags, and event counts
  2. Activitvents log with Log/Watch/Act-now labels
  3. Hardeesired vs Live receipts
  4. Settinglert email, proxies, webhook, agency token
- Capture real admin UI if a local site exists; do not fake misleading product claims

## Deliverables checklist (print when done)
- [ ] Exact sizes verified for all 4 directory PNGs
- [ ] icon-256 is 256×256 (not 1024)
- [ ] banner-772 matches banner-1544 composition
- [ ] menu-icon.svg simplified and consistent
- [ ] No purple/glow/cyber cliché
- [ ] Files written under `/Users/jskrishna/karetaker/assets/`
- [ ] Brief note: which files replaced

## Explicit non-goals
- Do not redesign the PHP admin CSS system unless asked
- Do not commit to SVN
- Do not change plugin version / readme Stable tag
- Do not invent a second metaphor (keep watchtower)

Start by drafting the mark silhouette, then produce icon masters, then banners, then resize exports, then verify dimensions.
```

---

## After Claude Code finishes

1. Verify sizes:

```bash
sips -g pixelWidth -g pixelHeight \
  assets/banner-1544x500.png \
  assets/banner-772x250.png \
  assets/icon-256x256.png \
  assets/icon-128x128.png
```

2. Sync into the already-staged SVN working copy:

```bash
cp assets/banner-*.png assets/icon-*.png assets/screenshot-*.png ~/svn/karetaker/assets/
```

3. Then commit when you are happy with the look.
