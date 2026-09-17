#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE_ROOT="$(mktemp -d)"
STAGE="$STAGE_ROOT/karetaker"
VERSION="$(grep -E "^\s*\* Version:" "$ROOT/karetaker.php" | head -1 | sed -E 's/.*Version:[[:space:]]*//')"
OUT="${1:-$ROOT/dist/karetaker-${VERSION}.zip}"
mkdir -p "$(dirname "$OUT")" "$STAGE"

# Ship only files tracked in git, minus development and WordPress.org listing files.
git -C "$ROOT" ls-files \
  | grep -vE '^\.|/\.' \
  | grep -vE '\.md$' \
  | grep -vE '^(composer\.(json|lock)|phpcs\.xml(\.dist)?|tools/)' \
  | grep -vE '^assets/(banner-.*\.png|icon-.*\.png|icon\.svg|logo-.*\.png|screenshot-.*\.png)$' \
  | rsync -a --files-from=- "$ROOT/" "$STAGE/"

python3 - "$STAGE/karetaker.php" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
p.write_text("".join(L for L in p.read_text().splitlines(True) if "Update URI" not in L))
PY

grep -q "Plugin Name: Karetaker" "$STAGE/karetaker.php"
if grep -q "Update URI" "$STAGE/karetaker.php"; then
  echo "Update URI still present in staged file" >&2
  exit 1
fi

rm -f "$OUT"
(
  cd "$STAGE_ROOT"
  zip -rq "$OUT" karetaker
)
rm -rf "$STAGE_ROOT"
echo "Wrote $OUT"
unzip -l "$OUT" | head -30
echo "--- header ---"
unzip -p "$OUT" karetaker/karetaker.php | head -15
