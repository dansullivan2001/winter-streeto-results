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

# A tag that already exists is fine if it's the one CI just checked out to
# build this exact release; it's only a mistake if it points somewhere else,
# meaning the version header was never bumped after the last tag.
if git -C "$ROOT" rev-parse "v$VERSION" >/dev/null 2>&1; then
  TAGGED_COMMIT="$(git -C "$ROOT" rev-parse "v$VERSION^{commit}")"
  HEAD_COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
  if [ "$TAGGED_COMMIT" != "$HEAD_COMMIT" ]; then
    echo "error: v$VERSION is already tagged elsewhere — bump the Version header in $PLUGIN.php first" >&2
    exit 1
  fi
fi

rm -rf "$BUILD"
mkdir -p "$BUILD"

cd "$ROOT"
zip -rq "$ZIP" "$PLUGIN" \
  -x "*/.*" \
  -x "*/node_modules/*" \
  -x "*/tests/*"

echo "Built $ZIP"
unzip -l "$ZIP" | tail -3
