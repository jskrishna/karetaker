#!/usr/bin/env bash
set -euo pipefail

SITE="${SITE:-$HOME/Local Sites/spice-web-media}"
REF="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.reference/spice-web-media"

if [ ! -d "$SITE" ]; then
	echo "site not found: $SITE" >&2
	exit 1
fi

PATHS=(
	"CLAUDE.md"
	"docs/security"
	"docs/audits"
	"app/public/wp-content/mu-plugins"
	"app/public/wp-content/themes/spice-web-media"
)

if [ -d "$REF" ]; then
	chmod -R u+w "$REF"
fi

mkdir -p "$REF"

for p in "${PATHS[@]}"; do
	if [ ! -e "$SITE/$p" ]; then
		echo "skipped (missing): $p" >&2
		continue
	fi
	mkdir -p "$REF/$(dirname "$p")"
	rsync -a --delete \
		--exclude '.git' \
		--exclude 'node_modules' \
		--exclude '*.map' \
		"$SITE/$p" "$REF/$(dirname "$p")/"
done

printf '%s\n' \
	"This directory is a READ-ONLY snapshot of the spice-web-media site, for context only." \
	"" \
	"It is a copy. Editing it changes nothing in the real site, and the real site is not" \
	"yours to change — it is built by a different agent in a different repo. Refresh with" \
	"tools/sync-reference.sh." \
	"" \
	"wp-config.php is deliberately absent: it holds database credentials." \
	"" \
	"Synced: $(date '+%Y-%m-%d %H:%M')" > "$REF/README.txt"

chmod -R a-w "$REF"

echo "reference synced: $(find "$REF" -type f | wc -l | tr -d ' ') files, $(du -sh "$REF" | cut -f1)"
echo "read-only: $REF"
