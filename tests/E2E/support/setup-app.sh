#!/bin/sh
# E2E only: prepares the app container of the isolated E2E project (tests/E2E/compose.e2e.yaml) — fresh database with
# the local demo data (DemoSeeder) and the demo student ready for the smoke test. Refuses to run anywhere else:
# STH_E2E=1 is set only on that project's app service, whose MySQL is a throwaway volume (CLAUDE.md rule 8).
#   docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml exec -T app sh tests/E2E/support/setup-app.sh
set -eu

if [ "${STH_E2E:-}" != 1 ]; then
    echo "[e2e] refused: run this only in the E2E compose project (STH_E2E=1)" >&2
    exit 1
fi

cd /var/www/html
# storage/ is an empty E2E volume (keeps the dev stack's storage untouched).
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

php artisan migrate:fresh --seed --force --no-interaction
php tests/E2E/support/prepare-student.php
# Precompile Blade views: the first requests are otherwise slow (bind mounts on Docker Desktop).
php artisan view:cache --no-interaction
