#!/usr/bin/env bash
# SMAGA Talent Hub: per-table privileges of the runtime DB user sth_app (docs/SECURITY.md §3h, docs/DEPLOY.md §8.2).
#
#   grants.sh            apply: GRANT/REVOKE until sth_app has exactly the policy below (idempotent, safe to repeat)
#   grants.sh --check    compare only, change nothing; exit 1 on any drift (monthly routine, DEPLOY.md §13)
#
# Policy, recomputed from information_schema on every run (new tables from new migrations are covered):
#   activity_log             SELECT, INSERT                   append-only audit log: no UPDATE, DELETE, TRUNCATE
#   audit_seals              SELECT, INSERT                   hash chain over activity_log (sealed once, never changed)
#   migrations               SELECT                           schema history, written only by sth_migrate
#   every other base table   SELECT, INSERT, UPDATE, DELETE
#   no database-wide or global privileges (a grant on `sth`.* would override the table rules), no DDL, no TRUNCATE
#
# Called by deploy.sh after every migrate and by restore.sh after a production restore. Run as the sth user.
# The SQL runs as root inside the db container; the root password is read there from the Docker secret (never argv).
# Other compose project (throwaway tests): COMPOSE_PROJECT_NAME=sth-test bash scripts/deploy/grants.sh
set -Eeuo pipefail

SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
APP_DIR="${STH_APP_DIR:-$(cd "$(dirname "$SELF")/../.." && pwd)}"
COMPOSE_FILE="$APP_DIR/docker/compose.prod.yaml"
export STH_ENV_FILE="${STH_ENV_FILE:-$APP_DIR/.env}" STH_SECRETS_DIR="${STH_SECRETS_DIR:-$APP_DIR/secrets}"

MODE=apply
case "${1:-}" in
    '') ;;
    --check) MODE=check ;;
    -h|--help) sed -n '2,15p' "$SELF"; exit 0 ;;
    *) printf '[grants] ERROR: unknown option: %s\n' "$1" >&2; exit 2 ;;
esac

# Everything below the marker runs inside the db container (bash from the mysql image), fed through stdin.
# shellcheck disable=SC2016  # the script body is expanded inside the container, not here
read -r -d '' IN_CONTAINER <<'EOS' || true
set -euo pipefail
mode="$1"
db="${MYSQL_DATABASE:-sth}"
user="${STH_DB_APP_USER:-sth_app}"
grantee="'${user}'@'%'"
APPEND_ONLY="activity_log audit_seals"   # SELECT, INSERT (a missing table is simply not in the list below)
READ_ONLY="migrations"         # SELECT

cnf="$(mktemp)"; trap 'rm -f "$cnf"' EXIT
printf '[client]\nuser=root\npassword=%s\nsocket=/var/run/mysqld/mysqld.sock\n' \
    "$(cat "${MYSQL_ROOT_PASSWORD_FILE:-/run/secrets/db_root_password}")" > "$cnf"
q() { mysql --defaults-extra-file="$cnf" --protocol=socket --batch --skip-column-names "$@"; }

want() {
    case " $APPEND_ONLY " in *" $1 "*) echo "SELECT,INSERT"; return ;; esac
    case " $READ_ONLY " in *" $1 "*) echo "SELECT"; return ;; esac
    echo "SELECT,INSERT,UPDATE,DELETE"
}
has() { case ",$1," in *",$2,"*) return 0 ;; esac; return 1; }

[ "$(q -e "SELECT COUNT(*) FROM mysql.user WHERE user = '${user}' AND host = '%'")" = 1 ] \
    || { echo "[grants] ERROR: user ${grantee} does not exist (docker/mysql/initdb/10-app-user.sh)" >&2; exit 1; }

mapfile -t tables < <(q -e "SELECT table_name FROM information_schema.tables
    WHERE table_schema = '${db}' AND table_type = 'BASE TABLE' ORDER BY table_name")
[ "${#tables[@]}" -gt 0 ] || { echo "[grants] ERROR: no tables in ${db}: run the migrations first" >&2; exit 1; }

declare -A current=()
while IFS=$'\t' read -r t p; do
    [ -n "$t" ] && current[$t]="${current[$t]:+${current[$t]},}${p}"
done < <(q -e "SELECT table_name, privilege_type FROM information_schema.table_privileges
    WHERE grantee = \"${grantee}\" AND table_schema = '${db}'")

schema_privs="$(q -e "SELECT GROUP_CONCAT(privilege_type ORDER BY privilege_type SEPARATOR ', ')
    FROM information_schema.schema_privileges WHERE grantee = \"${grantee}\" AND table_schema = '${db}'")"
[ "$schema_privs" != NULL ] || schema_privs=""
global_privs="$(q -e "SELECT GROUP_CONCAT(privilege_type SEPARATOR ', ') FROM information_schema.user_privileges
    WHERE grantee = \"${grantee}\" AND privilege_type <> 'USAGE'")"
[ "$global_privs" != NULL ] || global_privs=""

sql=""
drift=0
for t in "${tables[@]}"; do
    [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] || { echo "[grants] ERROR: unexpected table name: $t" >&2; exit 1; }
    w="$(want "$t")"
    have="${current[$t]:-}"
    missing=""; extra=""
    IFS=, read -r -a wl <<< "$w"
    for p in "${wl[@]}"; do has "$have" "$p" || missing="${missing:+$missing, }$p"; done
    if [ -n "$have" ]; then
        IFS=, read -r -a hl <<< "$have"
        for p in "${hl[@]}"; do has "$w" "$p" || extra="${extra:+$extra, }$p"; done
    fi
    if [ -n "$missing" ]; then
        drift=1
        echo "[grants] ${t}: missing ${missing}"
        sql+="GRANT ${missing} ON \`${db}\`.\`${t}\` TO ${grantee};"$'\n'
    fi
    if [ -n "$extra" ]; then
        drift=1
        echo "[grants] ${t}: extra ${extra}"
        sql+="REVOKE ${extra} ON \`${db}\`.\`${t}\` FROM ${grantee};"$'\n'
    fi
done
# Database-wide privileges are removed only after the table grants exist, so the app never loses access.
if [ -n "$schema_privs" ]; then
    drift=1
    echo "[grants] database-wide privileges on ${db}.* (override the table rules): ${schema_privs}"
    sql+="REVOKE ${schema_privs} ON \`${db}\`.* FROM ${grantee};"$'\n'
fi
if [ -n "$global_privs" ]; then
    echo "[grants] ERROR: ${grantee} has global privileges (${global_privs}); revoke them by hand" >&2
    exit 1
fi

if [ "$drift" = 0 ]; then
    echo "[grants] OK: ${#tables[@]} tables match the policy (${APPEND_ONLY// /, }: SELECT, INSERT; ${READ_ONLY}: SELECT; others: DML)"
    exit 0
fi
if [ "$mode" = check ]; then
    echo "[grants] DRIFT: run scripts/deploy/grants.sh (without --check) to fix" >&2
    exit 1
fi

printf '%s' "$sql" | q
echo "[grants] applied"
EOS

compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

[ -n "$(compose ps --status running -q db 2>/dev/null)" ] || {
    printf '[grants] ERROR: the db service is not running (docker compose -f %s up -d --wait db)\n' "$COMPOSE_FILE" >&2
    exit 1
}

compose exec -T db bash -c "$IN_CONTAINER" grants "$MODE"
if [ "$MODE" = apply ]; then
    # Second pass in check mode: proves the policy now holds (exit 1 otherwise).
    compose exec -T db bash -c "$IN_CONTAINER" grants check
fi
