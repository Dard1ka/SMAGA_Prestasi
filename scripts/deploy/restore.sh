#!/usr/bin/env bash
# SMAGA Talent Hub: restore a backup made by backup.sh (docs/DEPLOY.md §12).
#
#   restore.sh --from <backup dir> --identity <age identity file> [--target test|production]
#              [--db-only | --files-only] [--keep] [--yes]
#
#   --target test (default)  monthly restore test: imports the dump into an ISOLATED compose project
#                            "sth-restoretest" (own volume, db only, production untouched), prints table/row
#                            counts and verifies the file archive, then removes the test project (unless --keep).
#   --target production      real restore: safety backup first, maintenance mode, stop workers, DROP + import the
#                            database, move storage/app/private aside (private.before-restore-<stamp>) and unpack.
#   --identity               age identity (private key) brought in from offline storage; for gpg backups the
#                            private key must be in the keyring and this option is ignored.
# Run as the sth user. The decrypted stream is piped straight into MySQL/tar and never written to disk.
set -Eeuo pipefail
umask 077

SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
APP_DIR="${STH_APP_DIR:-$(cd "$(dirname "$SELF")/../.." && pwd)}"
COMPOSE_FILE="$APP_DIR/docker/compose.prod.yaml"
export STH_ENV_FILE="${STH_ENV_FILE:-$APP_DIR/.env}" STH_SECRETS_DIR="${STH_SECRETS_DIR:-$APP_DIR/secrets}"
TEST_PROJECT="sth-restoretest"

FROM=""
IDENTITY=""
TARGET="test"
DO_DB=1
DO_FILES=1
KEEP=0
ASSUME_YES=0
STAMP="$(date +%Y%m%d-%H%M%S)"

log()  { printf '[restore %s] %s\n' "$(date '+%F %T')" "$*"; }
die()  { printf '[restore %s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; exit 1; }
trap 'printf "[restore] ERROR: failed at line %s\n" "${BASH_LINENO[0]}" >&2' ERR

while [ "$#" -gt 0 ]; do
    case "$1" in
        --from) FROM="${2:?}"; shift 2 ;;
        --identity) IDENTITY="${2:?}"; shift 2 ;;
        --target) TARGET="${2:?}"; shift 2 ;;
        --db-only) DO_FILES=0; shift ;;
        --files-only) DO_DB=0; shift ;;
        --keep) KEEP=1; shift ;;
        --yes) ASSUME_YES=1; shift ;;
        -h|--help) sed -n '2,17p' "$SELF"; exit 0 ;;
        *) die "unknown option: $1" ;;
    esac
done

[ -n "$FROM" ] && [ -d "$FROM" ] || die "--from <backup dir> is required"
case "$TARGET" in test|production) ;; *) die "--target must be test or production" ;; esac
FROM="$(cd "$FROM" && pwd)"

if [ -f "$FROM/db.sql.gz.age" ] || [ -f "$FROM/private.tar.gz.age" ]; then
    EXT=age
    command -v age >/dev/null || die "age not installed"
    [ -n "$IDENTITY" ] && [ -f "$IDENTITY" ] || die "--identity <age identity file> is required for .age backups"
    decrypt() { age --decrypt --identity "$IDENTITY" "$1"; }
elif [ -f "$FROM/db.sql.gz.gpg" ] || [ -f "$FROM/private.tar.gz.gpg" ]; then
    EXT=gpg
    command -v gpg >/dev/null || die "gpg not installed"
    decrypt() { gpg --batch --quiet --decrypt "$1"; }
else
    die "no db.sql.gz.age/.gpg or private.tar.gz.age/.gpg in $FROM"
fi

log "verifying checksums of $FROM"
(cd "$FROM" && sha256sum --check --quiet SHA256SUMS)
[ ! -f "$FROM/meta.txt" ] || sed 's/^/[restore]   /' "$FROM/meta.txt"

if [ "$TARGET" = test ]; then
    compose() { docker compose -f "$COMPOSE_FILE" -p "$TEST_PROJECT" "$@"; }
else
    compose() { docker compose -f "$COMPOSE_FILE" "$@"; }
fi

# Runs SQL (stdin) as root inside the db container; password via a private temp option file, never argv.
# shellcheck disable=SC2016
MYSQL_ROOT='set -eu
cnf="$(mktemp)"; trap '\''rm -f "$cnf"'\'' EXIT
printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/db_root_password)" > "$cnf"
mysql --defaults-extra-file="$cnf" --default-character-set=utf8mb4 "$@"'
db_sql() { compose exec -T db sh -c "$MYSQL_ROOT" mysql-restore "$@"; }

restore_db() {
    [ -f "$FROM/db.sql.gz.$EXT" ] || die "missing $FROM/db.sql.gz.$EXT"
    log "importing database dump"
    # shellcheck disable=SC2016  # literal backticks = SQL identifier quoting
    db_sql -e 'DROP DATABASE IF EXISTS `sth`;'
    decrypt "$FROM/db.sql.gz.$EXT" | gunzip | db_sql
    log "database imported"
}

report_db() {
    log "tables and approximate rows in sth:"
    db_sql --table -e "SELECT table_name AS tbl, table_rows AS approx_rows FROM information_schema.tables
                       WHERE table_schema = 'sth' ORDER BY table_name;"
    db_sql --table -e "SELECT COUNT(*) AS users FROM sth.users;
                       SELECT COUNT(*) AS migrations, MAX(migration) AS latest_migration FROM sth.migrations;" || true
}

verify_files() {
    [ -f "$FROM/private.tar.gz.$EXT" ] || die "missing $FROM/private.tar.gz.$EXT"
    local count
    count="$(decrypt "$FROM/private.tar.gz.$EXT" | tar -tzf - | wc -l)"
    log "file archive OK: $count entries (tar listing, gzip CRC checked)"
}

restore_files() {
    [ -f "$FROM/private.tar.gz.$EXT" ] || die "missing $FROM/private.tar.gz.$EXT"
    log "moving current storage/app/private aside to private.before-restore-$STAMP and unpacking"
    decrypt "$FROM/private.tar.gz.$EXT" | compose run --rm --no-deps -T --entrypoint sh app -c "
        set -eu
        cd /var/www/html/storage/app
        if [ -d private ]; then mv private private.before-restore-$STAMP; fi
        tar -xzf -
        test -d private"
    log "files restored (delete storage/app/private.before-restore-$STAMP after checking)"
}

if [ "$TARGET" = test ]; then
    log "restore TEST into isolated project '$TEST_PROJECT' (production is not touched)"
    if [ "$DO_DB" = 1 ]; then
        compose up -d --wait db
        restore_db
        report_db
    fi
    [ "$DO_FILES" = 0 ] || verify_files
    if [ "$KEEP" = 0 ]; then
        log "removing test project $TEST_PROJECT (its own containers and volume only)"
        compose down --volumes --remove-orphans
    else
        log "kept: docker compose -f docker/compose.prod.yaml -p $TEST_PROJECT down --volumes  when done"
    fi
    log "restore test finished: record date, backup name and the counts above in the restore log (DEPLOY.md §12)"
    exit 0
fi

# --- production restore -----------------------------------------------------------------------------------------
log "PRODUCTION restore from $FROM: the current database$([ "$DO_FILES" = 1 ] && echo ' and private files') will be replaced"
if [ "$ASSUME_YES" = 0 ]; then
    read -r -p "Type RESTORE to continue: " answer
    [ "$answer" = RESTORE ] || die "aborted"
fi

log "safety backup of the current state"
bash "$APP_DIR/scripts/deploy/backup.sh" --label "pre-restore-$STAMP"

log "maintenance mode on, stopping queue and scheduler"
compose exec -T app php artisan down --retry=60 || true
compose stop queue scheduler

if [ "$DO_DB" = 1 ]; then
    restore_db
    # Table privileges survive DROP DATABASE in MySQL, but a dump from another schema version may add tables.
    log "re-applying per-table grants for the runtime DB user"
    bash "$APP_DIR/scripts/deploy/grants.sh"
fi
[ "$DO_FILES" = 0 ] || restore_files

log "restarting services"
compose up -d --force-recreate --wait app queue scheduler web
compose exec -T app php artisan up
report_db
log "production restore finished; check the site, then tell the team what was restored and from when"
