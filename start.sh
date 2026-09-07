#!/usr/bin/env bash
set -euo pipefail

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

# TiDB Cloud requires TLS. Render's CA bundle works for the public gateway.
export MYSQL_ATTR_SSL_CA="${MYSQL_ATTR_SSL_CA:-/etc/ssl/certs/ca-certificates.crt}"

php artisan migrate --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
