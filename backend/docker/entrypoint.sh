#!/usr/bin/env sh
set -e

if [ -f artisan ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
