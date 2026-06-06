#!/usr/bin/env bash
# bootstrap/invoiceninja.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: private VCS repo invoiceninja/admin-api (404) causes composer to hang on eager clone.
# Remove it before any composer run.
composer config --unset repositories.invoiceninja/admin-api 2>/dev/null || true
# Broad removal in case the key differs from the package name.
# shellcheck disable=SC2016  # PHP single-quoted strings — $ is not a shell variable.
php -r '
  $json = json_decode(file_get_contents("composer.json"), true);
  $json["repositories"] = array_values(array_filter(
    $json["repositories"] ?? [],
    fn($r) => !isset($r["url"]) || strpos($r["url"], "admin-api") === false
  ));
  file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
' 2>/dev/null || true

composer install --no-interaction --no-progress

survey_scaffold_env

{
  echo "DB_CONNECTION=sqlite"
  echo "DB_DATABASE=$(pwd)/database/database.sqlite"
  echo "SESSION_DRIVER=array"
  echo "CACHE_STORE=array"
  echo "QUEUE_CONNECTION=sync"
  echo "MAIL_MAILER=log"
} >> .env

# Friction: app's composer.json sets config.platform.ext-redis=1.0.0 (stale); symfony/cache
# conflicts with ext-redis <6.1. Set the honest installed version.
composer config platform.ext-redis 6.1.0

survey_link_library || survey_blocked "library require failed (see composer output)"
survey_publish_config
