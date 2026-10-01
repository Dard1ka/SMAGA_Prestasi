#!/bin/sh
# SMAGA Talent Hub: production entrypoint for the app, queue and scheduler containers (docker/Dockerfile.prod).
# Rebuilds Laravel's caches at every start into bootstrap/cache (a per-container tmpfs), so a container never runs
# with caches from an older image or an older .env. A failure here stops the container on purpose (fail fast):
# e.g. AppServiceProvider refuses to boot with APP_DEBUG=true or OTP test mode outside local.
#
#   STH_SKIP_OPTIMIZE=1   skip caching (one-off commands such as migrations, see scripts/deploy/deploy.sh)
#   STH_CACHE_VIEWS=1     also precompile Blade views into storage/framework/views (set on the app service only,
#                         because that directory is a volume shared with queue and scheduler)
set -eu

cd /var/www/html

if [ "${STH_SKIP_OPTIMIZE:-0}" != "1" ]; then
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan event:cache --no-interaction
    # A view that fails to compile breaks only its own page, not the whole app: warn loudly, keep booting.
    if [ "${STH_CACHE_VIEWS:-0}" = "1" ]; then
        php artisan view:cache --no-interaction \
            || echo "[sth-entrypoint] WARNING: view:cache failed (see above); views will compile on first use" >&2
    fi
fi

# Same convention as the official image: "docker run sth-app -F" means "php-fpm -F".
if [ "$#" -eq 0 ] || [ "${1#-}" != "$1" ]; then
    set -- php-fpm "$@"
fi

exec "$@"
