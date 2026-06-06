#!/usr/bin/env bash
# bootstrap/bookstack.sh — fresh clone -> runnable. Run inside the app repo.
# Source of truth: the BookStack survey runbook (setup log + frictions).
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: BookStack pins config.platform.php=8.2.0, so composer evaluates the
# library's ^8.4 against 8.2.0 and refuses though the real runtime satisfies ^8.4.
composer config platform.php 8.4.0

composer install --no-interaction --no-progress
survey_scaffold_env
survey_link_library
survey_publish_config
