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

**The backlog this leaves:** every class except `class-cli.php` was written under the old rule
and currently has a file docblock and nothing else. They need class and method docblocks before
submission.

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

## Before submission

- **There is no `readme.txt` yet.** The directory will not accept the plugin without one, and it
  is what renders the plugin page — the stable tag, the tested-up-to version, the screenshots
  and the FAQ all live in it.
- Run the WPCS pass and the docblock backlog above.
- Confirm the slug is still free before the first upload; `karetaker` was confirmed free on
  2026-09-13 and a name can be taken between then and submission.
