#!/usr/bin/env bash
# bootstrap/advisingapp.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: ext-mailparse (native) not installed on dev php@8.4; irrelevant to route registration.
composer install --no-interaction --no-progress --ignore-platform-req=ext-mailparse

survey_scaffold_env

# Friction: two pgsql connections (landlord + tenant) hardcoded in config/database.php.
# Rewrite both to sqlite so artisan boots without a running DB.
sed -i '' "s/'driver' => 'pgsql'/'driver' => 'sqlite'/g" config/database.php 2>/dev/null || true

{
  echo "DB_CONNECTION=landlord"
  echo "LANDLORD_APP_URL=http://localhost"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "TENANT_DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "CACHE_STORE=array"
  echo "SESSION_DRIVER=array"
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_MAILER=log"
  echo "BROADCAST_CONNECTION=log"
  echo "SCOUT_DRIVER=null"
} >> .env

# Library require also needs --ignore-platform-req=ext-mailparse (composer re-checks platform on require).
composer config repositories.laravel-openapi path "$LIB" >/dev/null
composer require "radiergummi/laravel-openapi:@dev" --no-interaction --ignore-platform-req=ext-mailparse \
  || survey_blocked "library require failed (see composer output)"

survey_publish_config
