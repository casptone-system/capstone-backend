#!/usr/bin/env bash
set -euo pipefail

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache || true

php artisan migrate --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
