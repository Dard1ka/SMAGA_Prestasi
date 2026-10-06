#!/bin/sh
# SMAGA Talent Hub — local development only. Run INSIDE the running `app` container:
#     docker compose exec -T app sh docker/php/dev-cache.sh        (start.bat and reload.bat do this)
#
# Makes php-fpm pick up edited code and keeps its two container-local caches (docker/php/fpm.dev.conf):
#   1. compiled Blade views in /tmp/sth-cache/views whose source changed are thrown away (php-fpm no longer
#      compares them with their source, so an edited .blade.php would otherwise never show);
#   2. /tmp/sth-cache/config.php is rebuilt when .env or a config file is newer than it (or with --force);
#   3. php-fpm reloads (USR2), which empties opcache;
#   4. a background warm-up compiles the PHP files php-fpm had loaded before the reload again
#      (App\Support\Dev\OpcacheWarmup), so the first click after a reload is not a 5-7 s wait.
#      /tmp/sth-cache/warmed appears when it is done. STH_NO_WARMUP=1 skips it.
# The config cache is written with APP_CONFIG_CACHE set for this one command only, so it never lands in
# bootstrap/cache/ where the test suite would find it.
set -eu

CACHE_DIR=/tmp/sth-cache
CONFIG_CACHE="$CACHE_DIR/config.php"
VIEWS="$CACHE_DIR/views"

cd /var/www/html

# Compiled views are no longer compared with their source on every request, so do it here once: drop the ones
# whose source is newer or gone (each compiled file ends with "/**PATH <source> ENDPATH**/"). The rest stay, so
# pages do not have to be compiled again after every reload. --force drops all of them (use it after changing
# a Blade directive or a component class).
if [ "${1:-}" = "--force" ]; then
    rm -rf "$VIEWS"
fi
mkdir -p "$VIEWS"
for compiled in "$VIEWS"/*.php; do
    [ -e "$compiled" ] || continue
    source=$(tail -c 400 "$compiled" | sed -n 's|.*/\*\*PATH \(.*\) ENDPATH\*\*/.*|\1|p')
    if [ -z "$source" ] || [ ! -f "$source" ] || [ "$source" -nt "$compiled" ]; then
        rm -f "$compiled"
    fi
done

rebuild=0
if [ "${1:-}" = "--force" ] || [ ! -f "$CONFIG_CACHE" ]; then
    rebuild=1
elif [ -n "$(find .env config -newer "$CONFIG_CACHE" 2>/dev/null | head -n 1)" ]; then
    rebuild=1
fi

if [ "$rebuild" = "1" ]; then
    if APP_CONFIG_CACHE="$CONFIG_CACHE" VIEW_COMPILED_PATH="$VIEWS" VIEW_CHECK_CACHE_TIMESTAMPS=false \
        php artisan config:cache --no-interaction >/dev/null; then
        echo "[sth-dev] config cache rebuilt"
    else
        # Without the file Laravel simply loads the config the normal (slower) way.
        rm -f "$CONFIG_CACHE"
        echo "[sth-dev] WARNING: config:cache failed, pages still work but load slower" >&2
    fi
fi

# GET a path of the app through nginx (service `web`); quiet, never fails the script.
fetch() {
    php -r '
        $ctx = stream_context_create(["http" => ["header" => "Host: 127.0.0.1:8080\r\n", "timeout" => (int) $argv[2], "ignore_errors" => true]]);
        @file_get_contents("http://web".$argv[1], false, $ctx);
    ' "$1" "$2" >/dev/null 2>&1 || true
}

# PID 1 is php-fpm in the `app` service. Anywhere else (a one-off `run` container) there is nothing to reload.
if [ -r /proc/1/comm ] && grep -q "php-fpm" /proc/1/comm; then
    warm=1
    [ "${STH_NO_WARMUP:-}" = "1" ] && warm=0

    # Remember which PHP files php-fpm has compiled so far (App\Support\Dev\OpcacheWarmup, routes/dev.php).
    [ "$warm" = "1" ] && fetch /_dev/opcache/save 20

    kill -USR2 1
    echo "[sth-dev] php-fpm reloaded"

    # Warm-up in the background: the reload emptied opcache, so the next request would read the framework from
    # the Windows drive again (measured: 7 s for the login page, 5.7 s for the first page after logging in).
    # `/up` loads the framework once, then three requests side by side (one per php-fpm worker, fpm.dev.conf)
    # resolve every remembered path and share the compiling, then two pages compile their Blade views.
    # Failures are ignored (`web` not up yet, first start without a saved list).
    if [ "$warm" = "1" ]; then
        rm -f "$CACHE_DIR/warmed"
        (
            sleep 1
            fetch /up 90
            for part in 0 1 2; do
                fetch "/_dev/opcache/warm?part=$part&of=3" 180 &
            done
            wait
            fetch /login 60
            fetch / 60
            date > "$CACHE_DIR/warmed"
        ) >/dev/null 2>&1 &
        echo "[sth-dev] warming up in the background (about 15 seconds)"
    fi
fi
