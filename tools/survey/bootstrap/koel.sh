#!/usr/bin/env bash
# bootstrap/koel.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: Koel pins config.platform.php=8.3; the library requires ^8.4. The real interpreter
# is 8.4+, and Koel itself allows >=8.3, so override the resolution platform to match reality.
composer config platform.php 8.4

# Use --no-scripts to skip Koel's npm/asset post-install steps.
composer install --no-interaction --no-progress --no-scripts

survey_scaffold_env

# Friction: Koel's AppServiceProvider hits DB during boot — need an absolute DB_DATABASE path.
{
  echo "DB_CONNECTION=sqlite"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "CACHE_DRIVER=array"
  echo "CACHE_STORE=array"
  echo "SESSION_DRIVER=array"
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_MAILER=log"
  echo "SCOUT_DRIVER=null"
  echo "APP_ENV=local"
} >> .env

php artisan package:discover --ansi 2>/dev/null || true
survey_link_library || survey_blocked "library require failed (see composer output)"
survey_publish_config
