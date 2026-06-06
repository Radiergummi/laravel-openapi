#!/usr/bin/env bash
# bootstrap/vito.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Use --no-scripts to skip npm/horizon post-install hooks.
composer install --no-interaction --no-progress --no-scripts

survey_scaffold_env

# Vito's .env.example has minimal APP_*/MAIL_* only; DB defaults to sqlite via env('DB_CONNECTION','sqlite').
{
  echo "DB_CONNECTION=sqlite"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
} >> .env

# Run package:discover manually (skipped by --no-scripts).
php artisan package:discover --ansi 2>/dev/null || true

survey_link_library || survey_blocked "library require failed (see composer output)"
survey_publish_config
