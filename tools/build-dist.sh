#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE_ROOT="$(mktemp -d)"
STAGE="$STAGE_ROOT/karetaker"
OUT="${1:-$ROOT/dist/karetaker-0.1.1.zip}"
mkdir -p "$(dirname "$OUT")" "$STAGE"

rsync -a \
  --exclude='.git' \
  --exclude='.DS_Store' \
  --exclude='.reference' \
  --exclude='.superpowers' \
  --exclude='.cursor' \
  --exclude='vendor' \
  --exclude='node_modules' \
  --exclude='composer.json' \
  --exclude='composer.lock' \
  --exclude='phpcs.xml.dist' \
  --exclude='phpcs.xml' \
  --exclude='.gitignore' \
  --exclude='.distignore' \
  --exclude='CLAUDE.md' \
  --exclude='AGENTS.md' \
  --exclude='CODE-NOTES.md' \
  --exclude='docs' \
  --exclude='tools' \
  --exclude='assets/banner-*.png' \
  --exclude='assets/icon-*.png' \
  --exclude='assets/screenshot-*.png' \
  --exclude='dist' \
  --exclude='tests' \
  "$ROOT/" "$STAGE/"

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
