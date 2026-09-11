#!/bin/sh
set -e

# Auto-install PHP dependencies on first run
if [ ! -f vendor/autoload.php ]; then
    echo "vendor/autoload.php not found — running composer install..."
    composer install --no-interaction --prefer-dist
fi

# Auto-install and build frontend on first run
#
# This container runs as root (it binds port 80), but public/build is a bind
# mount shared with the host. Anything root writes there the developer cannot
# later delete, and Vite's own output-dir cleanup then fails with EACCES on
# the next build run outside the container. Hand the results back to UID 1000
# — the sail user here, the developer on the host.
if [ ! -f public/build/manifest.json ]; then
    echo "Vite manifest not found — installing and building frontend..."
    pnpm install --frozen-lockfile
    pnpm run build
    chown -R 1000:1000 public/build node_modules 2>/dev/null || true
fi

# Generate app key if not set
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "APP_KEY not set — generating..."
    php artisan key:generate --force
fi

exec "$@"
