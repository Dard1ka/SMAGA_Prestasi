#!/usr/bin/env bash
# SMAGA Talent Hub: encrypted backup of the database and private uploads (docs/DEPLOY.md §11, SECURITY §3j).
#
#   backup.sh [--label <text>]
#
# Produces $STH_BACKUP_DIR/sth-<YYYYmmdd-HHMMSS>[-label]/ with
#   db.sql.gz.<age|gpg>        mysqldump of `sth` (single transaction, routines, triggers, events)
#   private.tar.gz.<age|gpg>   storage/app/private from the sth_storage volume
#   SHA256SUMS, meta.txt       checksums of the encrypted files + non-secret metadata
# Plaintext never touches the disk: dump → gzip → encrypt is one stream. Encryption uses PUBLIC keys only;
# the private key stays offline (printed/USB in the Kepsek/TU safe), so a compromised server cannot read backups.
#
# Configuration: environment or $STH_BACKUP_CONFIG (default /opt/sth/backup.env, chmod 600):
#   STH_BACKUP_DIR               default /opt/sth/backups
#   STH_BACKUP_RETENTION_DAYS    local retention, default 14
#   STH_BACKUP_ENCRYPTION        age (default) | gpg
#   STH_BACKUP_AGE_RECIPIENTS    file with age public key(s), default /opt/sth/backup-recipients.txt
#   STH_BACKUP_GPG_RECIPIENT     key id / fingerprint (public key imported into the sth user's keyring)
#   STH_BACKUP_RCLONE_REMOTE     optional off-site copy, e.g. sekolah-nas:sth-backup (rclone must be configured)
#   STH_BACKUP_REMOTE_RETENTION_DAYS   optional, delete remote copies older than N days (default: keep)
#   STH_BACKUP_PING_URL          optional dead-man's-switch URL (healthchecks-style: URL on success, URL/fail on error)
# Cron (user sth): 30 1 * * *  bash /opt/sth/app/scripts/deploy/backup.sh >> /opt/sth/logs/backup.log 2>&1
set -Eeuo pipefail
umask 077

SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
APP_DIR="${STH_APP_DIR:-$(cd "$(dirname "$SELF")/../.." && pwd)}"
BASE_DIR="$(dirname "$APP_DIR")"
CONFIG="${STH_BACKUP_CONFIG:-$BASE_DIR/backup.env}"
# shellcheck disable=SC1090
[ -f "$CONFIG" ] && . "$CONFIG"

COMPOSE_FILE="$APP_DIR/docker/compose.prod.yaml"
BACKUP_DIR="${STH_BACKUP_DIR:-$BASE_DIR/backups}"
RETENTION_DAYS="${STH_BACKUP_RETENTION_DAYS:-14}"
ENCRYPTION="${STH_BACKUP_ENCRYPTION:-age}"
AGE_RECIPIENTS="${STH_BACKUP_AGE_RECIPIENTS:-$BASE_DIR/backup-recipients.txt}"
GPG_RECIPIENT="${STH_BACKUP_GPG_RECIPIENT:-}"
RCLONE_REMOTE="${STH_BACKUP_RCLONE_REMOTE:-}"
REMOTE_RETENTION_DAYS="${STH_BACKUP_REMOTE_RETENTION_DAYS:-}"
PING_URL="${STH_BACKUP_PING_URL:-}"
export STH_ENV_FILE="${STH_ENV_FILE:-$APP_DIR/.env}" STH_SECRETS_DIR="${STH_SECRETS_DIR:-$APP_DIR/secrets}"

LABEL=""
WORK=""

log()  { printf '[backup %s] %s\n' "$(date '+%F %T')" "$*"; }
die()  { printf '[backup %s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; exit 1; }
compose() { docker compose -f "$COMPOSE_FILE" "$@"; }
ping_monitor() { [ -z "$PING_URL" ] || curl -fsS -m 10 --retry 3 -o /dev/null "$PING_URL$1" || true; }

on_error() {
    local rc=$?
    printf '[backup %s] ERROR: backup failed (exit %s, line %s)\n' "$(date '+%F %T')" "$rc" "${BASH_LINENO[0]}" >&2
    [ -z "$WORK" ] || rm -rf -- "$WORK"
    ping_monitor /fail
    exit "$rc"
}
trap on_error ERR

while [ "$#" -gt 0 ]; do
    case "$1" in
        --label) LABEL="$(printf '%s' "${2:?--label needs a value}" | tr -c 'A-Za-z0-9._-' '-')"; shift 2 ;;
        -h|--help) sed -n '2,24p' "$SELF"; exit 0 ;;
        *) die "unknown option: $1" ;;
    esac
done

case "$ENCRYPTION" in
    age)
        command -v age >/dev/null || die "age not installed (DEPLOY.md §11)"
        [ -s "$AGE_RECIPIENTS" ] || die "age recipients file $AGE_RECIPIENTS missing"
        EXT=age
        encrypt() { age --encrypt --recipients-file "$AGE_RECIPIENTS"; }
        ;;
    gpg)
        command -v gpg >/dev/null || die "gpg not installed"
        [ -n "$GPG_RECIPIENT" ] || die "STH_BACKUP_GPG_RECIPIENT not set"
        EXT=gpg
        encrypt() { gpg --batch --yes --quiet --trust-model always --encrypt --recipient "$GPG_RECIPIENT"; }
        ;;
    *) die "STH_BACKUP_ENCRYPTION must be age or gpg" ;;
esac

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
exec 9>"$BACKUP_DIR/.lock"
flock -n 9 || die "another backup is running"

[ -n "$(compose ps --status running -q db)" ] || die "db container is not running"

NAME="sth-$(date +%Y%m%d-%H%M%S)${LABEL:+-$LABEL}"
DEST="$BACKUP_DIR/$NAME"
WORK="$DEST.partial"
mkdir -p "$WORK"
log "writing $DEST ($ENCRYPTION)"

# 1. Database. The root password is read inside the container from the Docker secret and written to a private
#    temp option file, so it never appears in a process list (host users can read container argv).
# shellcheck disable=SC2016
DUMP_SCRIPT='set -eu
cnf="$(mktemp)"; trap '\''rm -f "$cnf"'\'' EXIT
printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/db_root_password)" > "$cnf"
mysqldump --defaults-extra-file="$cnf" --single-transaction --quick --routines --triggers --events \
  --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 --databases sth'
compose exec -T db sh -c "$DUMP_SCRIPT" \
    | awk '{ print } END { if ($0 !~ /^-- Dump completed/) { print "dump incomplete" > "/dev/stderr"; exit 3 } }' \
    | gzip -6 | encrypt > "$WORK/db.sql.gz.$EXT"
log "database dump: $(du -h --apparent-size "$WORK/db.sql.gz.$EXT" | cut -f1)"

# 2. Private uploads (a one-off container of the app image; works even when the app service is down).
compose run --rm --no-deps -T -e STH_SKIP_OPTIMIZE=1 --entrypoint tar app \
    -C /var/www/html/storage/app -czf - private \
    | encrypt > "$WORK/private.tar.gz.$EXT"
log "private files: $(du -h --apparent-size "$WORK/private.tar.gz.$EXT" | cut -f1)"

# 3. Checksums + metadata (no secrets).
(
    cd "$WORK"
    sha256sum ./*."$EXT" > SHA256SUMS
    {
        echo "created=$(date -Is)"
        echo "host=$(hostname)"
        echo "encryption=$ENCRYPTION"
        echo "git_commit=$(git -C "$APP_DIR" rev-parse --short=12 HEAD 2>/dev/null || echo unknown)"
        echo "app_image=$(docker inspect --format '{{.Config.Image}}' "$(compose ps -q app 2>/dev/null | head -n1)" 2>/dev/null || echo unknown)"
        echo "mysql=$(compose exec -T db mysqld --version 2>/dev/null | tr -d '\r' || echo unknown)"
    } > meta.txt
)
mv "$WORK" "$DEST"
WORK=""
log "backup complete: $DEST"

# 4. Local retention (complete backups older than N days; stale .partial dirs older than 1 day).
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name 'sth-*' ! -name '*.partial' -mtime +"$RETENTION_DAYS" \
    -print -exec rm -rf -- {} +
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name 'sth-*.partial' -mtime +1 -exec rm -rf -- {} +

# 5. Off-site copy (3-2-1). Encrypted files only.
if [ -n "$RCLONE_REMOTE" ]; then
    command -v rclone >/dev/null || die "rclone not installed but STH_BACKUP_RCLONE_REMOTE is set"
    rclone copy "$DEST" "$RCLONE_REMOTE/$NAME" --transfers 2 --retries 3
    log "copied off-site to $RCLONE_REMOTE/$NAME"
    if [ -n "$REMOTE_RETENTION_DAYS" ]; then
        rclone delete "$RCLONE_REMOTE" --min-age "${REMOTE_RETENTION_DAYS}d"
        rclone rmdirs "$RCLONE_REMOTE" --leave-root
    fi
fi

ping_monitor ""
