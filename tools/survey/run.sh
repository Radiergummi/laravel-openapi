#!/usr/bin/env bash
# run.sh <app-name>
#
# Runs openapi:generate and openapi:lint inside an app that has already been
# bootstrapped and linked against this library, capturing specs, logs, and exit
# codes into the app dir. Prints a one-line summary for the scorecard.
#
# Requires WS to point at the external scratch workspace (see README.md).
#
# CLI surface exercised:
#   openapi:generate [spec?] --output=- --format=yaml|json --explain
#   openapi:lint --level=N|max --format=cli|json|github|markdown --only/--skip/...
#
# Run with a PHP 8.4 binary: PHP 8.5 leaks app-side deprecations into stdout and
# corrupts --output=-.
set -uo pipefail

WS="${WS:?set WS to your external survey workspace dir (see tools/survey/README.md)}"
APPS="$WS/apps"

if [[ $# -ne 1 ]]; then
  echo "usage: run.sh <app-name>" >&2
  exit 2
fi

name="$1"
appdir="$APPS/$name"
repodir="$appdir/repo"

if [[ ! -d "$repodir" ]]; then
  echo "no repo at $repodir — run setup.sh first" >&2
  exit 2
fi

cd "$repodir"

# Generate to stdout so we can redirect; stderr (incl. --explain, crashes) is
# captured separately. Do not abort on non-zero — a crash is data.
php artisan openapi:generate --output=- --format=json \
  > "$appdir/generated-spec.json" 2> "$appdir/generate.log"
gen_exit=$?

php artisan openapi:lint --format=json \
  > "$appdir/lint.json" 2> "$appdir/lint.log"
lint_exit=$?

# Count paths in the generated spec, tolerating a partial/broken document.
paths=$(php -r '
  $f = $argv[1];
  $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
  echo is_array($j["paths"] ?? null) ? count($j["paths"]) : "?";
' "$appdir/generated-spec.json" 2>/dev/null)

echo "$name: gen_exit=$gen_exit lint_exit=$lint_exit paths=$paths"
echo "  spec:  $appdir/generated-spec.json"
echo "  logs:  $appdir/generate.log  $appdir/lint.json  $appdir/lint.log"
