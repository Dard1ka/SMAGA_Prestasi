#!/usr/bin/env bash
# smaga_server: builds the update package for the school's server (PANDUAN-DEPLOY-SERVER.md, part C).
# Run from Git Bash in the smaga_server folder:   bash scripts/dev/buat-paket-server.sh
#
# The package = every tracked file + public/build + the two local files the server needs (AKUN-UJI.md and
# database/seeders/data/akun-sekolah.php, both outside git) + VERSI.txt (the commit it was built from). CLAUDE.md and
# AGENTS.md stay out (the lead removed them from the server). Never inside: .env, vendor, storage, node_modules.
# Files are owned by root (0:0) in the archive, like everything else in /opt/sth/app.
set -euo pipefail

cd "$(dirname "$0")/../.."

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "PERINGATAN: ada perubahan yang belum di-commit. Paket memakai isi folder apa adanya."
    echo "            Commit dulu kalau ingin VERSI.txt menunjuk ke kode yang sama persis."
fi

for f in public/build/manifest.json AKUN-UJI.md database/seeders/data/akun-sekolah.php; do
    if [ ! -f "$f" ]; then
        echo "GAGAL: $f tidak ada. Lihat PANDUAN-DEPLOY-SERVER.md bagian C." >&2
        exit 1
    fi
done

date_tag=$(date +%Y%m%d-%H%M)
out_dir="../kirim-server"
out="$out_dir/smaga_server-update-$date_tag.tar.gz"
list=$(mktemp)
mkdir -p "$out_dir"

{
    echo "commit $(git rev-parse --short HEAD) ($(git log -1 --format=%cd --date=format:%Y-%m-%d)) $(git log -1 --format=%s | cut -c1-80)"
    echo "paket dibuat $(date '+%Y-%m-%d %H:%M')"
} > VERSI.txt

{
    git ls-files | grep -v -x -E 'CLAUDE\.md|AGENTS\.md'
    find public/build -type f
    echo AKUN-UJI.md
    echo database/seeders/data/akun-sekolah.php
    echo VERSI.txt
} | sort -u > "$list"

tar --force-local --owner=0 --group=0 --numeric-owner -czf "$out" -T "$list"
rm -f "$list"

echo
echo "Paket : $(cd "$out_dir" && pwd)/$(basename "$out")"
echo "Ukuran: $(du -h "$out" | cut -f1)"
echo "Isi   : $(tar --force-local -tzf "$out" | wc -l) file"
echo "Versi : $(head -1 VERSI.txt)"
echo "SHA256: $(sha256sum "$out" | cut -d' ' -f1)"
echo
echo "Langkah berikutnya: PANDUAN-DEPLOY-SERVER.md bagian D (kirim dengan scp)."
