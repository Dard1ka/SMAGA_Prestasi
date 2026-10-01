#!/usr/bin/env bash
# SMAGA Talent Hub: deploy on the production host (docs/DEPLOY.md §8, §14).
#
#   deploy.sh [--ref <git-ref>] [--first] [--skip-backup]   fetch + checkout <ref> (default origin/main), build,
#                                                          backup, migrate --force, recreate containers, smoke test
#   deploy.sh --rollback <image-tag>                       run older, still-present images (no build, no migrate)
#
# Run as the `sth` service user (member of group docker), never as root:
#   sudo -u sth -H bash /opt/sth/app/scripts/deploy/deploy.sh
# Laravel caches (config/route/event/view) are rebuilt by the container entrypoint on every (re)start.
set -Eeuo pipefail

SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
APP_DIR="${STH_APP_DIR:-$(cd "$(dirname "$SELF")/../.." && pwd)}"
COMPOSE_FILE="$APP_DIR/docker/compose.prod.yaml"
ENV_FILE="${STH_ENV_FILE:-$APP_DIR/.env}"
SECRETS_DIR="${STH_SECRETS_DIR:-$APP_DIR/secrets}"
HISTORY_FILE="${STH_DEPLOY_HISTORY:-$(dirname "$APP_DIR")/deploy-history.log}"
KEEP_IMAGES="${STH_KEEP_IMAGES:-3}"
HTTP_PORT="${STH_HTTP_PORT:-8080}"
export STH_ENV_FILE="$ENV_FILE" STH_SECRETS_DIR="$SECRETS_DIR"

REF="origin/main"
FIRST=0
SKIP_BACKUP=0
NO_PULL=0
ROLLBACK_TAG=""
MAINTENANCE=0

log()  { printf '[deploy %s] %s\n' "$(date '+%F %T')" "$*"; }
warn() { printf '[deploy %s] WARNING: %s\n' "$(date '+%F %T')" "$*" >&2; }
die()  { printf '[deploy %s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; exit 1; }
compose() { docker compose -f "$COMPOSE_FILE" "$@"; }

# Read KEY from .env without sourcing it (the file is data, not shell).
env_value() {
    local value
    value="$(sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1)"
    value="${value%\"}"; value="${value#\"}"
    printf '%s' "$value"
}

usage() { sed -n '2,11p' "$SELF"; exit "${1:-0}"; }

# shellcheck disable=SC2329  # invoked by the ERR trap
on_error() {
    local rc=$?
    warn "deploy failed (exit $rc) at line ${BASH_LINENO[0]}."
    if [ "$MAINTENANCE" = 1 ]; then
        warn "The site is still in maintenance mode on purpose. Fix the cause, then either re-run deploy.sh,"
        warn "or roll back: deploy.sh --rollback <previous tag from $HISTORY_FILE> (restore.sh if a migration ran)."
    fi
    exit "$rc"
}
trap on_error ERR

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --ref) REF="${2:?--ref needs a value}"; shift 2 ;;
            --first) FIRST=1; SKIP_BACKUP=1; shift ;;
            --skip-backup) SKIP_BACKUP=1; shift ;;
            --no-pull) NO_PULL=1; shift ;;
            --rollback) ROLLBACK_TAG="${2:?--rollback needs an image tag}"; shift 2 ;;
            -h|--help) usage 0 ;;
            *) warn "unknown option: $1"; usage 1 ;;
        esac
    done
}

preflight() {
    [ "$(id -u)" -ne 0 ] || die "run as the sth user (sudo -u sth -H bash $SELF), not as root"
    command -v docker >/dev/null || die "docker not found"
    docker compose version >/dev/null 2>&1 || die "docker compose plugin not found"
    docker info >/dev/null 2>&1 || die "cannot talk to dockerd (is $(id -un) in group docker?)"
    command -v curl >/dev/null || die "curl not found"

    [ -f "$ENV_FILE" ] || die "$ENV_FILE missing (template: docker/env.production.example)"
    [ -r "$ENV_FILE" ] || die "$ENV_FILE is not readable by $(id -un) (chown sth:sth)"
    [ "$(stat -c '%a' "$ENV_FILE")" = 600 ] || die "$ENV_FILE must be chmod 600"
    [ "$(env_value APP_ENV)" = production ] || die "APP_ENV must be production"
    [ "$(env_value APP_DEBUG)" = false ] || die "APP_DEBUG must be false"
    case "$(env_value APP_KEY)" in base64:?*) ;; *) die "APP_KEY is not set (see DEPLOY.md §7)" ;; esac
    case "$(env_value APP_URL)" in https://*) ;; *) die "APP_URL must start with https://" ;; esac
    [ "$(env_value SESSION_SECURE_COOKIE)" = true ] || die "SESSION_SECURE_COOKIE must be true"
    if grep -Eiq '^(STH_OTP_TEST_MODE|STH_OTP_DEV_BYPASS)=' "$ENV_FILE"; then
        die "remove STH_OTP_TEST_MODE/STH_OTP_DEV_BYPASS from $ENV_FILE (never in production, CLAUDE.md rule 7)"
    fi
    if grep -q 'CHANGE_ME' "$ENV_FILE"; then
        warn "placeholders left in .env: $(grep -o '^[A-Z_]*=CHANGE_ME' "$ENV_FILE" | cut -d= -f1 | tr '\n' ' ')"
        [ "$(env_value DB_PASSWORD)" != CHANGE_ME ] || die "DB_PASSWORD is still CHANGE_ME"
    fi

    [ -d "$SECRETS_DIR" ] || die "$SECRETS_DIR missing (DEPLOY.md §7)"
    [ "$(stat -c '%a' "$SECRETS_DIR")" = 700 ] || die "$SECRETS_DIR must be chmod 700"
    local s
    for s in db_root_password db_migrate_password db_app_password; do
        [ -s "$SECRETS_DIR/$s.txt" ] || die "secret $SECRETS_DIR/$s.txt missing or empty"
    done
    [ "$(tr -d '\r\n' < "$SECRETS_DIR/db_app_password.txt")" = "$(env_value DB_PASSWORD)" ] \
        || die "DB_PASSWORD in .env differs from secrets/db_app_password.txt"
}

update_checkout() {
    cd "$APP_DIR"
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        die "the checkout has local changes (git status); deploy only committed code"
    fi
    log "fetching $REF"
    git fetch --prune --tags origin
    git checkout --quiet --detach "$REF"
    log "checked out $(git rev-parse --short=12 HEAD): $(git log -1 --format=%s)"
    # Continue with the deploy script of the commit we just checked out.
    exec bash "$SELF" --no-pull "${ORIGINAL_ARGS[@]}"
}

app_running() { [ -n "$(compose ps --status running -q app 2>/dev/null)" ]; }

smoke_test() {
    local host code
    host="$(env_value APP_URL)"; host="${host#https://}"; host="${host%%/*}"
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 \
        -H "Host: $host" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$HTTP_PORT/up" || true)"
    [ "$code" = 200 ] || die "smoke test: GET /up via 127.0.0.1:$HTTP_PORT returned $code (docker compose logs app web)"
    log "smoke test: /up = 200"
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 \
        -H 'Host: evil.test' "http://127.0.0.1:$HTTP_PORT/up" || true)"
    if [ "$code" = 200 ]; then
        warn "a foreign Host header was accepted (TrustHosts not active?): SECURITY §3e"
    else
        log "smoke test: foreign Host rejected ($code)"
    fi
}

recreate_services() {
    log "starting containers for tag $STH_IMAGE_TAG"
    compose up -d --remove-orphans --force-recreate --wait app queue scheduler web
    if [ "$MAINTENANCE" = 1 ]; then
        compose exec -T app php artisan up
        MAINTENANCE=0
    fi
    compose exec -T app php artisan about --only=environment,cache || true
    smoke_test
}

prune_images() {
    [ -f "$HISTORY_FILE" ] || return 0
    local keep repo tag
    keep="$(awk '{print $2}' "$HISTORY_FILE" | tac | awk '!seen[$0]++' | head -n "$KEEP_IMAGES")"
    for repo in sth-app sth-web; do
        docker image ls "$repo" --format '{{.Tag}}' | while read -r tag; do
            [ "$tag" = "$STH_IMAGE_TAG" ] && continue
            grep -qx -- "$tag" <<<"$keep" && continue
            log "removing old image $repo:$tag"
            docker image rm "$repo:$tag" >/dev/null || true
        done
    done
    docker image prune -f >/dev/null || true
}

record() { printf '%s %s %s\n' "$(date -Is)" "$STH_IMAGE_TAG" "$1" >> "$HISTORY_FILE"; }

rollback() {
    export STH_IMAGE_TAG="$ROLLBACK_TAG"
    docker image inspect "sth-app:$STH_IMAGE_TAG" "sth-web:$STH_IMAGE_TAG" >/dev/null 2>&1 \
        || die "images sth-app/sth-web:$STH_IMAGE_TAG not present (docker image ls sth-app)"
    warn "rollback to $STH_IMAGE_TAG: database migrations are NOT reverted (restore.sh if the schema changed)"
    recreate_services
    record "rollback"
    log "rollback done"
}

main() {
    ORIGINAL_ARGS=("$@")
    parse_args "$@"
    preflight
    cd "$APP_DIR"

    if [ -n "$ROLLBACK_TAG" ]; then
        rollback
        return
    fi

    [ "$NO_PULL" = 1 ] || update_checkout   # re-executes itself with --no-pull

    export STH_IMAGE_TAG
    STH_IMAGE_TAG="$(git rev-parse --short=12 HEAD)"
    log "deploying $STH_IMAGE_TAG"

    log "building images (sth-app, sth-web)"
    compose build app web

    if [ "$SKIP_BACKUP" = 0 ] && app_running; then
        log "pre-deploy backup"
        bash "$APP_DIR/scripts/deploy/backup.sh" --label "pre-deploy-$STH_IMAGE_TAG"
    elif [ "$FIRST" = 0 ]; then
        warn "no pre-deploy backup (skipped or app not running)"
    fi

    log "starting database"
    compose up -d --wait db

    if app_running; then
        log "maintenance mode on"
        compose exec -T app php artisan down --retry=60
        MAINTENANCE=1
    fi

    log "running migrations as sth_migrate"
    DB_PASSWORD="$(tr -d '\r\n' < "$SECRETS_DIR/db_migrate_password.txt")" \
        compose run --rm --no-deps -T \
            -e STH_SKIP_OPTIMIZE=1 -e DB_USERNAME=sth_migrate -e DB_PASSWORD \
            app php artisan migrate --force --no-interaction

    recreate_services
    record "${REF}"
    prune_images
    log "deploy of $STH_IMAGE_TAG done"
}

main "$@"; exit $?
