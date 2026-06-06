#!/usr/bin/env bash
# bootstrap/bagisto.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

composer install --no-interaction --no-progress

survey_scaffold_env

{
  echo "DB_CONNECTION=sqlite"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "SESSION_DRIVER=array"
  echo "CACHE_STORE=array"
  echo "MAIL_MAILER=log"
  echo "RESPONSE_CACHE_ENABLED=false"
} >> .env

# Friction: Bagisto queries 'channels' table during provider boot — migrate before route:list.
# One migration fails on sqlite (dropForeign by name); --force continues past it; channels table exists.
php artisan migrate --force 2>/dev/null || true

survey_link_library || survey_blocked "library require failed (see composer output)"
survey_publish_config
