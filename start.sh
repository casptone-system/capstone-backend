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

# Queue workers belong in a separate process/service:
# php artisan queue:work --sleep=1 --tries=3 --timeout=90
exec php \
  -d upload_max_filesize=64M \
  -d post_max_size=68M \
  -d memory_limit=256M \
  artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
