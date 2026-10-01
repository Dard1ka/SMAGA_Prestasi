# SMAGA Talent Hub (STH)

Aplikasi web **SMA Negeri 3 Semarang** untuk penjaringan talenta dan alur lomba siswa dari awal sampai
akhir: katalog lomba yang diverifikasi sekolah → pendaftaran individu/tim → persetujuan Kepala Sekolah
→ surat tugas PDF dari Tata Usaha → laporan hasil → verifikasi Kesiswaan → poin, peringkat kumulatif,
portofolio, publikasi Humas, dan halaman prestasi publik.

Alamat produksi (rencana): `https://prestasi.sman3-smg.sch.id`.
Stack: Laravel 13 · PHP 8.4 · MySQL 8.4 · Livewire 4 · Tailwind CSS 4, semuanya di **Docker**.

## Peran pengguna
Siswa · Guru pembimbing · Kesiswaan · Kepala Sekolah · Tata Usaha · Humas · Admin.
Halaman publik (tanpa login) hanya menampilkan nama, kelas, dan prestasi yang sudah diverifikasi,
sesuai izin publikasi.

## Mulai cepat
Semua perintah dijalankan lewat Docker. **Jangan memasang PHP, Composer, atau Node di komputer.**

### Prasyarat
- **Windows 10/11:** Docker Desktop dengan backend WSL 2, plus Git for Windows. Perintah di bawah bisa
  dijalankan di PowerShell atau Git Bash dari folder repo.
- **Linux:** Docker Engine + plugin Docker Compose v2 (`docker compose version`). User Anda masuk
  grup `docker`, atau jalankan dengan `sudo`.

### Cara termudah (Windows)
1. Buka Docker Desktop dan tunggu sampai statusnya **Engine running**.
2. Klik dua kali **`start.bat`** di folder repo. Skrip ini membuat `.env` (kalau belum ada), memasang
   dependensi, menyalakan semua container, menjalankan migrasi, mengisi data demo fiktif pada
   jalankan pertama, membangun aset frontend, lalu membuka http://127.0.0.1:8080.
3. Selesai bekerja, klik dua kali **`stop.bat`**. Data database tetap tersimpan.

Kalau database kosong tetapi `.env` sudah ada (misalnya setelah volume MySQL dihapus), isi data awal
dengan `docker compose exec app php artisan db:seed`.

### Langkah pertama kali (manual / Linux)
```sh
cp .env.example .env                                   # PowerShell: Copy-Item .env.example .env
docker compose build app
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed
docker compose run --rm --no-deps node sh -c "npm ci && npm run build"
```
Buka http://127.0.0.1:8080.

### Sehari-hari
```sh
docker compose up -d                                   # nyalakan
docker compose down                                    # matikan (data MySQL tetap ada)
docker compose --profile frontend up -d node           # Vite dengan hot reload
docker compose run --rm --no-deps app php artisan test # jalankan test
docker compose run --rm --no-deps app ./vendor/bin/pint # rapikan kode
docker compose logs -f app queue                       # lihat log
```
Daftar perintah lengkap ada di `CLAUDE.md` §4.

Catatan:
- Di Linux, file yang dibuat dari dalam kontainer dimiliki root. Perbaiki dengan
  `sudo chown -R "$USER": .`.
- Di Git Bash, path absolut seperti `/var/www/html` bisa diubah otomatis. Awali perintah dengan
  `MSYS_NO_PATHCONV=1` atau pakai PowerShell.

## Alamat pengembangan

| Layanan | Alamat |
|---------|--------|
| Aplikasi | http://127.0.0.1:8080 |
| Mailpit (semua email dev tertangkap di sini) | http://127.0.0.1:8025 |
| Vite (profil `frontend`) | http://127.0.0.1:5173 |
| MySQL | `127.0.0.1:3307`, database `sth` (kredensial dev ada di `compose.yaml`) |
| Showcase komponen (hanya `APP_ENV=local`) | http://127.0.0.1:8080/_dev/components |

Semua port hanya terbuka di `127.0.0.1`.

## Akun demo (hanya untuk pengembangan)
`DemoSeeder` membuat akun **fiktif** dan hanya berjalan bila `APP_ENV=local`. Nama dan nomor diambil
dari data contoh desain (`docs/design/DESIGN-SPEC-v2.md` §5), bukan data sekolah.

| Peran | Login (username) | Password |
|-------|------------------|----------|
| Kesiswaan | `kesiswaan` | `password` |
| Kepala Sekolah | `kepsek` | `password` |
| Tata Usaha | `tu` | `password` |
| Humas | `humas` | `password` |
| Admin | `operator` | `password` |
| Guru pembimbing | `retno.w`, `hendra.k`, `maya.a`, `agus.s` | `password` |
| Siswa | NIS atau NISN fiktif, mis. Nadia `225226201` / `0098765432` | password awal sesuai pola sekolah dari tanggal lahir fiktif di `database/seeders/DemoSeeder.php` |

- Staf juga bisa masuk dengan email `<username>@sth.test`. Siswa wajib aktivasi saat pertama masuk
  (ganti password → email → kode 6 digit).
- 2FA staf (aplikasi authenticator) wajib di semua lingkungan kecuali lokal. Untuk mencoba alurnya di
  lokal, set `STH_REQUIRE_STAFF_2FA=true` di `.env`.
- Mode uji OTP (`STH_OTP_TEST_MODE=true`) hanya berlaku bila `APP_ENV=local`: kode 6 digit apa pun
  diterima. Di lingkungan lain aplikasi menolak jalan bila flag ini menyala.
- Akun dan password demo **tidak boleh** ada di server produksi. Password awal siswa tidak pernah
  ditampilkan di aplikasi.
- `php artisan test` selalu memakai SQLite di memori, jadi aman dijalankan kapan saja: data demo di
  MySQL tidak ikut terhapus.

## Dokumentasi

| Dokumen | Isi |
|---------|-----|
| `CLAUDE.md` | konteks proyek, aturan wajib, arsitektur, perintah, status (baca pertama) |
| `CONTRIBUTING.md` | alur branch, checklist PR |
| `docs/TEAM.md` | pembagian tugas tim dan milestone |
| `docs/UI-MAP.md` | daftar layar → route → komponen → akses → pemilik |
| `docs/DECISIONS.md` | log keputusan produk & teknis |
| `docs/ARCHITECTURE.md` | kontrak data, enum, state machine, aturan domain |
| `docs/SECURITY.md` | model ancaman, kontrol keamanan, checklist pra-rilis |
| `docs/TALENT-FORM.md` | struktur penjaringan talenta |
| `docs/DEPLOY.md` | deploy produksi: image, compose, Apache, backup/restore, CI |
| `docs/design/` | spesifikasi desain v2 dan mockup 24 layar ([kanvas live](https://claude.ai/artifact/7m2XGmACRwHFfYQ9EaeV2m)) |
| `docs/dev-notes/` | catatan serah terima tiap anggota/agen |

## Data pribadi
Repo ini **tidak boleh** berisi data siswa atau guru yang nyata (nama, NIS/NISN, tanggal lahir, foto,
tanda tangan). Semua data contoh, seeder, dan test memakai data fiktif. Dokumen sumber dari sekolah
disimpan di luar repo.
