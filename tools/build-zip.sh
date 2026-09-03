#!/usr/bin/env bash
#
# Build an installable plugin zip.
#
# WordPress expects a single top-level folder inside the zip, containing the
# main plugin file. Everything outside mvoc-streeto-results/ — the tests, the
# fixtures, composer's dev dependencies — is development scaffolding and is
# deliberately left out: a results plugin has no runtime dependencies, and the
# fixtures contain real competitors' names.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="mvoc-streeto-results"
BUILD="$ROOT/build"

VERSION="$(grep -m1 "^ \* Version:" "$ROOT/$PLUGIN/$PLUGIN.php" | awk '{print $3}')"
ZIP="$BUILD/$PLUGIN-$VERSION.zip"

rm -rf "$BUILD"
mkdir -p "$BUILD"

cd "$ROOT"
zip -rq "$ZIP" "$PLUGIN" \
  -x "*/.*" \
  -x "*/node_modules/*" \
  -x "*/tests/*"

echo "Built $ZIP"
unzip -l "$ZIP" | tail -3
