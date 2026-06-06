#!/usr/bin/env bash
# setup.sh <app-name> <repo-url> <ref>
#
# Clones an OSS Laravel app into the standard survey layout and stamps a
# runbook. Does NOT run the app's bootstrap (composer install, key:generate,
# migrations) — that is the time-boxed manual work, app-specific by nature.
#
# Requires WS to point at an EXTERNAL scratch workspace (never inside this repo
# or any app checkout). See tools/survey/README.md.
#
# Layout produced:
#   $WS/apps/<app-name>/repo/        the clone, at <ref>
#   $WS/apps/<app-name>/runbook.md   copied from the template, SHA stamped
set -euo pipefail

WS="${WS:?set WS to your external survey workspace dir (see tools/survey/README.md)}"
HARNESS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPS="$WS/apps"

if [[ $# -ne 3 ]]; then
  echo "usage: setup.sh <app-name> <repo-url> <ref>" >&2
  exit 2
fi

name="$1"; url="$2"; ref="$3"
appdir="$APPS/$name"
repodir="$appdir/repo"

mkdir -p "$appdir"

if [[ -d "$repodir/.git" ]]; then
  echo "repo already present at $repodir — leaving as-is" >&2
else
  # Shallow clone of the single ref keeps these big repos cheap.
  git clone --depth 1 --branch "$ref" "$url" "$repodir"
fi

sha="$(git -C "$repodir" rev-parse HEAD)"

if [[ ! -f "$appdir/runbook.md" ]]; then
  sed -e "s|{{APP}}|$name|g" \
      -e "s|{{REPO}}|$url|g" \
      -e "s|{{REF}}|$ref|g" \
      -e "s|{{SHA}}|$sha|g" \
      "$HARNESS/runbook-template.md" > "$appdir/runbook.md"
fi

echo "ready: $name @ $sha"
echo "  repo:    $repodir"
echo "  runbook: $appdir/runbook.md"
echo
echo "Next (manual, time-boxed): composer install; cp .env.example .env;"
echo "php artisan key:generate; point DB at sqlite; add a composer path repo for"
echo "this library and composer require radiergummi/laravel-openapi:@dev;"
echo "vendor:publish --tag=openapi-config. Then: WS=\$WS $HARNESS/run.sh $name"
