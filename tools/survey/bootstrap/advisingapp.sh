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
# Rewrite both to sqlite so artisan boots without a running DB. (PHP edit, not
# `sed -i ''` — the empty-suffix form is macOS-only and silently no-ops on GNU sed/CI.)
php -r '$f="config/database.php"; if(is_file($f)){file_put_contents($f, str_replace("\x27driver\x27 => \x27pgsql\x27", "\x27driver\x27 => \x27sqlite\x27", (string)file_get_contents($f)));}'

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
survey_link_library --ignore-platform-req=ext-mailparse \
  || survey_blocked "library require failed (see composer output)"

survey_publish_config
