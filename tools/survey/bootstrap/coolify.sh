#!/usr/bin/env bash
# bootstrap/coolify.sh — fresh clone -> runnable. Run inside the app repo.
set -uo pipefail
SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SELF_DIR/_lib.sh"

# Friction: no .env.example; Coolify ships .env.development.example with pgsql/redis defaults.
# Write a minimal sqlite env directly.
if [[ ! -f .env ]]; then
  cat > .env <<'EOF'
APP_ENV=local
APP_DEBUG=true
APP_KEY=
DB_CONNECTION=sqlite
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
MAIL_MAILER=log
BROADCAST_CONNECTION=log
SSH_MUX_ENABLED=false
TELESCOPE_ENABLED=false
HORIZON_ENABLED=false
EOF
fi

touch database/database.sqlite
php artisan key:generate --force 2>/dev/null || true

# Use --no-scripts to skip npm/horizon post-install hooks.
composer install --no-interaction --no-progress --no-scripts

# Friction: Coolify pins zircote/swagger-php ^5.8.0 in prod. The library now supports
# ^5.8 || ^6.1.2, so composer picks 5.x and both constraints are satisfied.
survey_link_library || survey_blocked "library require failed (see composer output)"

php artisan package:discover --ansi 2>/dev/null || true
survey_publish_config
