#!/usr/bin/env bash
# bootstrap/_lib.sh — shared steps sourced by each per-app bootstrap script.
# Each per-app script: sources this, runs common scaffold, then its own deltas.
#
# Requires: WS (workspace), LIB (this library checkout). Run inside the app repo.
set -uo pipefail

LIB="${LIB:?set LIB to the laravel-openapi checkout under test}"

# Link the library under test via a composer path repo and require it as @dev.
# Extra args ($@) are forwarded to `composer require` — pass per-app flags
# (--ignore-platform-req=…, --no-scripts) or extra packages here so the path-repo
# registration always happens (forgetting it is a silent "library not found" trap).
survey_link_library() {
  composer config repositories.laravel-openapi path "$LIB" >/dev/null
  composer require "radiergummi/laravel-openapi:@dev" "$@" --no-interaction
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

# Record a blocked app: write the outcome to BOOT_OUTCOME_FILE so run.sh records
# it as data. (export can't cross the bootstrap subshell back to the runner.)
survey_blocked() {
  echo "BLOCKED: $1" >&2
  [[ -n "${BOOT_OUTCOME_FILE:-}" ]] && echo "blocked-compat" > "$BOOT_OUTCOME_FILE"
  return 0
}
