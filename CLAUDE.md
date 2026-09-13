# Karetaker — house rules

A WordPress security plugin for sites nobody is paid to watch, headed for the WordPress.org
directory. Design and evidence: `docs/design.md`. Architecture notes: `CODE-NOTES.md`.

## This repo does not inherit the Spice Web Media rules

The plugin was extracted from the `spice-web-media` site repo on 2026-09-13 because that repo's
house rules are wrong for a distributable plugin. **Nothing in that repo's `CLAUDE.md` applies
here.** The one that mattered:

**Write docblocks.** The site repo forbids comments and keeps its reasoning in markdown. That is
a good rule for a site nobody else reads and the wrong one for a plugin submitted to
WordPress.org, whose own coding standard (`WordPress-Docs`) requires a docblock on every file,
class, method and function, with `@param` and `@return`.

The extraction happened because the rule was already breaking down in place: `class-cli.php`
carries 38 comment lines while `class-scanner.php` carries 5 across 434 lines — because WP-CLI
synthesises its `wp help` output from docblocks and simply does not work without them. That is
the rule failing against the platform, not an inconsistency to tidy up.

**Docblocks are required on every file, class, method and function** (`@param`, `@return`,
`@since`). The Watch–Agency classes were brought up to that bar in the 2026-09-13 wp.org prep
slice. Keep them current when you add methods.

## Standards

- **WordPress Coding Standards**, checked with PHPCS + WPCS. `WordPress-Docs` included, per above.
- **Prefix everything `karetaker_`** — functions, classes, constants, options, table names
  (`Karetaker_Events`, `KARETAKER_VERSION`, `karetaker_settings`, `karetaker_events`). This is a
  directory requirement: WordPress.org checks the prefix against the **slug**, is strict about
  4+ characters, and rejects three-letter forms outright. See `docs/design.md` for why the
  agency prefix was dropped.
- **Every file starts with the `ABSPATH` guard.** Already true across the tree; keep it true.
- Sanitise on input, escape on output, nonce every state change, capability-check every handler.
  A security plugin that is itself an attack surface is worse than no plugin.

## Where it runs

The working copy lives here, and `wp-content/plugins/karetaker` in the Local site
(`~/Local Sites/spice-web-media`) is a **symlink to this directory** — so the plugin is active
in that install and can be exercised against real content, while its source stays out of the
site repo. The site's `deploy.sh` excludes the path, so nothing here reaches the client's
production site.

Do not copy the directory back into the site repo. If the symlink is lost, recreate it:

    ln -s ~/karetaker "~/Local Sites/spice-web-media/app/public/wp-content/plugins/karetaker"

## The site is not yours to change

Two agents work on two repos and they must never write to the same thing. **This repo is
yours. `~/Local Sites/spice-web-media` is not** — it is built by Claude Code in its own repo,
and an edit made here to a file over there is exactly the conflict this split exists to
prevent.

**Open Cursor on this directory, not on the site.** That is most of the guarantee: a folder
that is not in the workspace cannot be edited by accident.

**To read the site, use `.reference/spice-web-media/`.** It is a narrow snapshot — the theme,
the mu-plugins, `CLAUDE.md`, and the security and performance docs, 151 files — and it is
**`chmod a-w`, so the filesystem itself refuses writes**. It is a copy, not a link: editing it
would change nothing in the real site even if it were writable. Refresh it with
`tools/sync-reference.sh` whenever the site has moved on.

`wp-config.php` is deliberately not in the snapshot; it holds the database credentials, and a
security plugin's repo is the last place they should be copied to.

**The one thing worth reading there first is `spice-hardening.php`.** It is a site mu-plugin
doing a narrower version of this plugin's job — headers, XML-RPC, author scanning, a login
throttle — so it is both a working reference and the thing to avoid duplicating.

**Testing does not depend on any of this.** `wp-content/plugins/karetaker` in that install is a
symlink to this directory, so the plugin is live in a real site with real content the moment you
save a file. Nothing needs copying anywhere for it to run.

## Before submission

- **`readme.txt` is in place** (Stable tag 0.1.0, Tested up to 7.1). Add screenshots/banner
  assets when you have them; the Screenshots section is omitted until then.
- **Docblock backlog is done** for plugin PHP (file/class/method `@param` / `@return`). Keep
  new methods documented the same way; design rationale still lives in `CODE-NOTES.md`.
- **Remove `Update URI: false` from `karetaker.php` immediately before the first .org upload**
  (keep it while distributing by hand — see `CODE-NOTES.md`).
- Run Plugin Check and the WPCS / `WordPress-Docs` pass.
- Confirm the slug is still free before the first upload; `karetaker` was confirmed free on
  2026-09-13 and a name can be taken between then and submission.
