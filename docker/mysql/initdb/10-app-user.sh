#!/bin/bash
# Run by the mysql:8.4 entrypoint on FIRST start only (empty data volume); works whether the entrypoint sources
# it (mode 644) or executes it (mode 755, e.g. bind mounts from Windows). Mounted read-only by
# docker/compose.prod.yaml. If first-time initialisation fails, the half-initialised volume must be removed
# before retrying (docs/DEPLOY.md §8): the entrypoint skips init scripts once the data directory exists.
#
# Users (docs/SECURITY.md §3j):
#   root          MYSQL_ROOT_PASSWORD_FILE; used only by backup.sh/restore.sh inside the container
#   sth_migrate   MYSQL_USER/MYSQL_PASSWORD_FILE, created by the image with ALL on `sth`.*; used only by
#                 scripts/deploy/deploy.sh for `php artisan migrate --force`
#   sth_app       created here: DML only (no DDL, no TRUNCATE, no FILE/GRANT/SUPER); the runtime user in .env
#
# Passwords come from Docker secrets. Generate them with `openssl rand -hex 32` (hex = no quoting problems).
# No `set -u`/traps here: when sourced, they would leak into the entrypoint's own shell.

sth_app_user="${STH_DB_APP_USER:-sth_app}"
sth_app_password="$(cat /run/secrets/db_app_password)"

case "$sth_app_password" in
    '' | *\'* | *\\* | *'"'*)
        echo >&2 "[initdb] db_app_password is empty or contains quotes/backslashes; use: openssl rand -hex 32"
        exit 1
        ;;
esac

# The temporary init server listens on the socket only; root already has its final password at this point.
sth_cnf="$(mktemp)"
printf '[client]\nuser=root\npassword=%s\nsocket=/var/run/mysqld/mysqld.sock\n' \
    "$(cat "${MYSQL_ROOT_PASSWORD_FILE:-/run/secrets/db_root_password}")" > "$sth_cnf"

echo "[initdb] creating runtime user '${sth_app_user}' (SELECT, INSERT, UPDATE, DELETE on ${MYSQL_DATABASE}.*)"
if ! mysql --defaults-extra-file="$sth_cnf" --protocol=socket --database=mysql <<EOSQL
CREATE USER IF NOT EXISTS '${sth_app_user}'@'%' IDENTIFIED BY '${sth_app_password}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.* TO '${sth_app_user}'@'%';
EOSQL
then
    rm -f "$sth_cnf"
    echo >&2 "[initdb] creating ${sth_app_user} failed"
    exit 1
fi

rm -f "$sth_cnf"
unset sth_app_user sth_app_password sth_cnf
