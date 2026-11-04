#!/usr/bin/env bash
# smaga_server: copies the laptop database into salinan-db/ so a second developer gets exactly the same data
# (PANDUAN-DEPLOY-SERVER.md, part A). The database lives in a Docker volume, not in this folder, so a zip of the folder
# alone has no data. Run from Git Bash in the smaga_server folder while the stack runs:
#
#   bash scripts/dev/salin-database.sh
#
# salinan-db/ is in .gitignore and in the pre-push hook: it holds real staff emails and password hashes.
set -euo pipefail

cd "$(dirname "$0")/../.."
mkdir -p salinan-db

out="salinan-db/sth-$(date +%Y%m%d-%H%M).sql"
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers sth' > "$out"

if ! tail -1 "$out" | grep -q 'Dump completed'; then
    echo "GAGAL: salinan tidak lengkap ($out). Pastikan Docker dan stack menyala (start.bat), lalu ulangi." >&2
    exit 1
fi

cp "$out" salinan-db/sth-terbaru.sql
echo "Salinan database: $out ($(du -h "$out" | cut -f1)), juga disimpan sebagai salinan-db/sth-terbaru.sql"
