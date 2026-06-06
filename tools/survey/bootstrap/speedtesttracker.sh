#!/usr/bin/env bash
# bootstrap/speedtesttracker.sh — fresh clone -> runnable. Run inside the app repo.
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
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_MAILER=log"
  echo "BROADCAST_CONNECTION=log"
} >> .env

# SpeedtestTracker pins zircote/swagger-php ^5.8.3 in prod. The library supports
# ^5.8 || ^6.1.2, so composer picks 5.x; both constraints are satisfied.
# Use --no-scripts to skip boost:update post-install hook (app-side, exits non-zero when Boost not set up).
survey_link_library --no-scripts \
  || survey_blocked "library require failed (see composer output)"

php artisan package:discover --ansi 2>/dev/null || true
survey_publish_config
