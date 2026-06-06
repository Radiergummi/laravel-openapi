#!/usr/bin/env bash
# bootstrap/pelican.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: Pelican pins config.platform.php=8.3; the library requires ^8.4. Pelican itself
# supports ^8.3||^8.4||^8.5 and the real interpreter is 8.4+.
composer config platform.php 8.4

composer install --no-interaction --no-progress --no-scripts

survey_scaffold_env

# Pelican's .env.example has only APP_* keys; write the DB + driver neutralizations.
{
  echo "DB_CONNECTION=sqlite"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "APP_INSTALLED=true"
  echo "CACHE_STORE=array"
  echo "SESSION_DRIVER=array"
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_MAILER=log"
  echo "BROADCAST_CONNECTION=log"
} >> .env

php artisan package:discover --ansi 2>/dev/null || true

# Friction: spatie/laravel-data <4.23 pulls reflection-docblock ^5 which conflicts with the
# library's transitive floor. laravel-data 4.23+ resolves the conflict; Pelican's constraint
# allows ^4.22, so bump to ^4.23.
composer config repositories.laravel-openapi path "$LIB" >/dev/null
composer require "radiergummi/laravel-openapi:@dev" "spatie/laravel-data:^4.23" \
  --no-interaction -W \
  || survey_blocked "library require failed (see composer output)"

survey_publish_config
