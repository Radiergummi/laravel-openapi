#!/usr/bin/env bash
# bootstrap/_lib.sh — shared steps sourced by each per-app bootstrap script.
# Each per-app script: sources this, runs common scaffold, then its own deltas.
#
# Requires: WS (workspace), LIB (this library checkout). Run inside the app repo.
set -uo pipefail

LIB="${LIB:?set LIB to the laravel-openapi checkout under test}"

# Link the library under test via a composer path repo and require it as @dev.
survey_link_library() {
  composer config repositories.laravel-openapi path "$LIB" >/dev/null
  composer require "radiergummi/laravel-openapi:@dev" --no-interaction
}

# Prepare a minimal runnable env: .env, app key, sqlite. App-specific configs are
# layered by the caller after this returns.
survey_scaffold_env() {
  [[ -f .env ]] || cp .env.example .env 2>/dev/null || true
  touch database/database.sqlite 2>/dev/null || true
  php artisan key:generate --force 2>/dev/null || true
}

# Publish the package config (apps that remap config_path still resolve it).
survey_publish_config() {
  php artisan vendor:publish --tag=openapi-config --no-interaction 2>/dev/null || true
}

# Record a blocked app: write BOOT_OUTCOME so run.sh/metrics record it as data.
# Usage: survey_blocked "<reason>"
survey_blocked() {
  echo "BLOCKED: $1" >&2
  export BOOT_OUTCOME="blocked-compat"
  return 0
}
