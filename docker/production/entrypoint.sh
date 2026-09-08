#!/bin/sh
set -eu

umask 0002

if [ "${GAELD_CACHE_CONFIG:-true}" = "true" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
    php artisan event:cache --no-interaction
fi

exec "$@"

