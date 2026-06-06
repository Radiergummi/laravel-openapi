#!/usr/bin/env bash
# bootstrap/lychee.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: ext-imagick absent on dev php@8.4 (image processing — irrelevant to route registration).
composer install --no-interaction --no-progress --ignore-platform-req=ext-imagick

survey_scaffold_env

# Lychee's .env.example already sets DB_CONNECTION=sqlite; layer additional neutralizations.
{
  echo "APP_ENV=local"
  echo "APP_DEBUG=true"
  echo "CACHE_DRIVER=array"
  echo "SESSION_DRIVER=array"
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_DRIVER=log"
} >> .env

# Library require also needs --ignore-platform-req=ext-imagick.
# The prior type-resolver ^1 / reflection-docblock conflict is resolved in the current library
# (no longer requires phpdocumentor/reflection-docblock at all).
composer config repositories.laravel-openapi path "$LIB" >/dev/null
composer require "radiergummi/laravel-openapi:@dev" --no-interaction --ignore-platform-req=ext-imagick \
  || survey_blocked "library require failed (see composer output)"

survey_publish_config
