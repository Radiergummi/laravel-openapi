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
#
# corpus.sh resets the app's composer state (composer.json/lock, vendor/radiergummi,
# compiled caches) from git BEFORE the bootstrap runs, so this require always re-resolves
# against $LIB rather than no-op'ing on a lock that still pins a previous run's library sha.
# The post-link assertion is the safety net: a stale or missing link is recorded as
# blocked-compat instead of silently measuring the wrong library commit (#233).
survey_link_library() {
  composer config repositories.laravel-openapi path "$LIB" >/dev/null
  composer require "radiergummi/laravel-openapi:@dev" "$@" --no-interaction

  survey_assert_library_linked
}

# Assert the freshly required package actually resolves to $LIB. Composer can report
# "nothing to modify" and leave the vendor symlink pointing at a stale worktree; resolving
# both sides to their real path catches that (and a missing link). On mismatch, record the
# app as blocked-compat and fail the function so the caller's `|| survey_blocked` also fires.
survey_assert_library_linked() {
  local link="vendor/radiergummi/laravel-openapi"
  local resolved lib_real
  resolved="$(cd "$link" 2>/dev/null && pwd -P)" || resolved=""
  lib_real="$(cd "$LIB" 2>/dev/null && pwd -P)" || lib_real=""

  if [[ -z "$resolved" || "$resolved" != "$lib_real" ]]; then
    survey_blocked "library link stale: ${link} resolves to '${resolved:-missing}', expected '${lib_real}'"
    return 1
  fi
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
