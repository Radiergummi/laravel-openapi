#!/usr/bin/env bash
# bootstrap/aureuserp.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"


# Friction: four native exts (gd/intl/bcmath/zip) absent on dev php@8.4; irrelevant to route registration.
composer install --no-interaction --no-progress \
  --ignore-platform-req=ext-gd \
  --ignore-platform-req=ext-intl \
  --ignore-platform-req=ext-bcmath \
  --ignore-platform-req=ext-zip

survey_scaffold_env

{
  echo "DB_CONNECTION=sqlite"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "SESSION_DRIVER=array"
  echo "CACHE_STORE=array"
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_MAILER=log"
} >> .env

survey_link_library || survey_blocked "library require failed (see composer output)"
survey_publish_config
