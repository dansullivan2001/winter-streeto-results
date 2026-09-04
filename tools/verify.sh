#!/usr/bin/env bash
#
# Every check, in one command, exiting non-zero if any fails.
#
# Exists because a release once went out with a red suite: the steps were run
# individually and the failure scrolled past. A release should be gated on one
# thing that either passes or does not.
#
# Usage: ./tools/verify.sh [path-to-wordpress]
#
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHP="${PHP:-$(command -v php || true)}"
if [ -z "$PHP" ]; then
  PHP="$(ls -d "$HOME"/Library/Application\ Support/Local/lightning-services/php-*/bin/*/bin/php 2>/dev/null | head -1 || true)"
fi
[ -n "$PHP" ] || { echo "no php found; set PHP=/path/to/php" >&2; exit 2; }

PHPUNIT="${PHPUNIT:-$ROOT/vendor/bin/phpunit}"
[ -x "$PHPUNIT" ] || PHPUNIT="/tmp/phpunit.phar"

fail=0
step() { printf '  %-12s %s\n' "$1" "$2"; }

if find mvoc-streeto-results tests tools -name '*.php' -print0 | xargs -0 -n1 "$PHP" -l 2>&1 | grep -v '^No syntax errors'; then
  step lint FAILED; fail=1
else
  step lint "clean"
fi

if out=$( "$PHP" tools/check-references.php 2>&1 ); then step references "$out"; else step references "FAILED: $out"; fail=1; fi

if out=$( "$PHP" "$PHPUNIT" 2>&1 | tail -1 ); then step unit "$out"; else step unit "FAILED: $out"; fail=1; fi

SITE="${1:-}"
if [ -n "$SITE" ] && [ -r "$SITE/wp-load.php" ]; then
  if out=$( "$PHP" tools/integration-test.php "$SITE" 2>&1 | tail -1 ); then
    step integration "$out"
  else
    step integration "FAILED: $out"; fail=1
  fi
else
  step integration "skipped (no WordPress path given)"
fi

[ "$fail" -eq 0 ] && echo "  all checks passed" || echo "  SOMETHING FAILED — do not release" >&2
exit "$fail"
