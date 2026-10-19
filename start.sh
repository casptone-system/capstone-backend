#!/usr/bin/env bash
set -euo pipefail

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is not set. Password reset and signed verification links will fail." >&2
  exit 1
fi

# TiDB Cloud requires TLS. Render's CA bundle works for the public gateway.
export MYSQL_ATTR_SSL_CA="${MYSQL_ATTR_SSL_CA:-/etc/ssl/certs/ca-certificates.crt}"

php artisan migrate --force --no-interaction
php artisan storage:link --force --no-interaction || true
mkdir -p storage/app/public/profile_photos
chmod -R ug+rwx storage bootstrap/cache || true

# Production caches (config/route/view/event). Safe on free tiers; no Redis required.
php artisan optimize --no-interaction || true
php artisan event:cache --no-interaction || true

# Concurrent built-in server workers (free). Still not PHP-FPM; better than 1 process.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

# ---------------------------------------------------------------------------
# Queue worker (REQUIRED when QUEUE_CONNECTION=database, e.g. Railway free):
#   Create a second service with the same image/env. Start command:
#     php artisan queue:work database --queue=default,mail,exports --sleep=1 --tries=3 --timeout=90 --max-time=3600
#   Railway/Render restart the process on failure via the platform restart policy.
#   Split queues so a backlog of assignment mail (mail) cannot starve default work.
#
# When QUEUE_CONNECTION=sync (Render free default), no worker is needed; side
# effects use dispatch()->afterResponse() so they still leave the HTTP request.
# Auth emails (login code, verification, password reset) stay synchronous.
# ---------------------------------------------------------------------------

exec php \
  -d expose_php=Off \
  -d upload_max_filesize=64M \
  -d post_max_size=68M \
  -d memory_limit=256M \
  artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
