#!/bin/sh
# HTTPS on the server's IP address (compose.https-ip.yaml, https://112.78.41.197:8149): a Let's Encrypt IP address
# certificate (only with the "shortlived" profile, valid about 6 days; Certbot 5.4 or newer for --ip-address with
# webroot). Certbot runs from its Docker image, so the host (Ubuntu 18.04) needs no Certbot of its own. The HTTP-01
# check comes in on port 80, where the host's Apache serves /.well-known/acme-challenge/ from STH_ACME_WEBROOT
# (an Alias set up once, see the deploy guide). Run as root, from anywhere:
#   sh scripts/server/sertifikat-ip.sh coba       # dry run against Let's Encrypt staging: proves the check works, saves nothing
#   sh scripts/server/sertifikat-ip.sh minta      # the real certificate (first time; afterwards cron renews it)
#   sh scripts/server/sertifikat-ip.sh perbarui   # renews when due (about halfway), then reloads nginx; for cron, twice a day
set -eu

IP="${STH_PUBLIC_IP:-112.78.41.197}"
WEBROOT="${STH_ACME_WEBROOT:-/var/www/acme-ip}"
LE_DIR="${STH_LETSENCRYPT_DIR:-/etc/letsencrypt}"
# Pinned on purpose (2026-11-10 release); update by hand like the other images.
IMAGE="certbot/certbot:v5.10.0"

# The smaga_server folder, so `docker compose` reads its .env (COMPOSE_FILE).
cd "$(dirname "$0")/../.."

certbot() {
    docker run --rm \
        -v "$LE_DIR:/etc/letsencrypt" \
        -v /var/lib/letsencrypt:/var/lib/letsencrypt \
        -v /var/log/letsencrypt:/var/log/letsencrypt \
        -v "$WEBROOT:$WEBROOT" \
        "$IMAGE" "$@"
}

request() {
    mkdir -p "$WEBROOT/.well-known/acme-challenge"
    certbot certonly --non-interactive --agree-tos --register-unsafely-without-email \
        --preferred-profile shortlived \
        --webroot --webroot-path "$WEBROOT" \
        --ip-address "$IP" --cert-name "$IP" "$@"
}

case "${1:-}" in
    coba)
        request --dry-run
        ;;
    minta)
        request
        echo "Sertifikat: $LE_DIR/live/$IP/fullchain.pem"
        ;;
    perbarui)
        certbot renew --quiet
        # nginx reads the certificate only at start or reload; harmless when nothing was renewed.
        if docker compose ps --status running --services 2>/dev/null | grep -qx web; then
            docker compose exec -T web nginx -s reload
        fi
        ;;
    *)
        echo "Pakai: sh scripts/server/sertifikat-ip.sh coba|minta|perbarui" >&2
        exit 2
        ;;
esac
