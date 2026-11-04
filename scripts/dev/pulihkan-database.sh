#!/usr/bin/env bash
# smaga_server: loads a database copy made by salin-database.sh into the laptop database of THIS folder
# (PANDUAN-DEPLOY-SERVER.md, part A). Every table in the copy replaces the table of the same name: data entered after the
# copy was made is gone. Laptop only: never run it on the school's server. From Git Bash in the smaga_server folder:
#
#   bash scripts/dev/pulihkan-database.sh                         (salinan-db/sth-terbaru.sql)
#   bash scripts/dev/pulihkan-database.sh salinan-db/sth-20261104-1630.sql
set -euo pipefail

cd "$(dirname "$0")/../.."
file="${1:-salinan-db/sth-terbaru.sql}"

if [ ! -f "$file" ]; then
    echo "GAGAL: $file tidak ada." >&2
    exit 1
fi

if [ -f /opt/sth/app/.env ] || [ "$(pwd)" = "/opt/sth/app" ]; then
    echo "GAGAL: skrip ini hanya untuk laptop, bukan server sekolah." >&2
    exit 1
fi

docker compose up -d --wait mysql
docker compose exec -T mysql sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" sth' < "$file"
echo "Database laptop dipulihkan dari $file. Lanjutkan dengan start.bat."
