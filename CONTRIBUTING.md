# CONTRIBUTING — cara berkontribusi ke STH

Baca `CLAUDE.md` dulu (aturan wajib, perintah Docker, arsitektur). Dokumen ini mengatur alur kerja Git
dan pull request untuk user, S, dan I.

## 1. Branch
- `main` selalu hijau dan bisa di-deploy. Tidak ada push langsung ke `main`; semua lewat PR.
- Nama branch: `<jenis>/<area>-<topik>`, huruf kecil, kata dipisah tanda hubung.
  - jenis: `feat` (fitur), `fix` (perbaikan bug), `chore` (tooling, dependensi), `docs`, `test`,
    `refactor`, `ops` (deploy/infra).
  - area: `auth`, `akun`, `lomba`, `pendaftaran`, `surat`, `laporan`, `poin`, `peringkat`, `talenta`,
    `humas`, `publik`, `admin`, `ui`, `deploy`, `ci`.
  - contoh: `feat/lomba-katalog`, `feat/surat-generate-pdf`, `fix/auth-rate-limit-nisn`,
    `ops/deploy-apache-vhost`.
- Satu branch = satu topik kecil. Rebase ke `main` sebelum minta review.

## 2. Commit
- Pesan commit dalam bahasa Inggris, format Conventional Commits:
  `feat(lomba): add catalog filters`, `fix(auth): share rate-limit bucket for NIS and NISN`.
- Jangan commit `.env`, dump database, file dari folder sumber sekolah, atau file upload.
- Agen AI tidak membuat commit kecuali diminta langsung oleh user di chat.

## 3. Sebelum membuka PR
Jalankan lewat Docker:
```sh
docker compose run --rm --no-deps app php artisan test
docker compose run --rm --no-deps app ./vendor/bin/pint --test
docker compose run --rm --no-deps app composer audit
```
Untuk perubahan frontend, pastikan build sukses:
`docker compose run --rm --no-deps node sh -c "npm ci && npm run build"` (pakai `npm install <paket>`
hanya saat menambah dependensi, lalu commit `package-lock.json`).

Test selalu berjalan di SQLite in-memory (`phpunit.xml` memakai `force="true"`), jadi aman dijalankan
kapan saja tanpa menghapus database MySQL dev.

## 4. Checklist PR
Salin ke deskripsi PR dan centang yang relevan.

**Umum**
- [ ] Judul PR jelas, menautkan milestone (`M1`…) dan baris `docs/UI-MAP.md` bila ada.
- [ ] Test Pest ditambahkan/diperbarui: aturan domain (unit), Action, Policy, route/Livewire (feature),
      termasuk kasus **ditolak** (peran lain, milik orang lain → 404, akun arsip → 403).
- [ ] `php artisan test` hijau dan `pint --test` bersih (via Docker).
- [ ] Hanya menyentuh file milik area sendiri, atau pemilik area lain sudah diminta review.
- [ ] Tidak ada keputusan lama yang dihidupkan lagi (lihat `CLAUDE.md` §8). Keputusan baru dicatat
      di `docs/DECISIONS.md` sebagai entri baru.
- [ ] `CLAUDE.md` §10 Status / `docs/UI-MAP.md` diperbarui bila perlu.

**Data pribadi**
- [ ] Tidak ada data siswa/guru nyata di kode, seeder, factory, test, fixture, dokumen, atau screenshot.
      Hanya data fiktif DESIGN-SPEC-v2 §5 atau Faker.
- [ ] Tidak ada NIS/NISN/HP/tanggal lahir di halaman publik, JSON publik, atau log.
- [ ] Pola password awal tidak muncul di UI, email, log, pesan error, atau output test.

**Keamanan** (rujukan `docs/SECURITY.md`)
- [ ] Setiap route/aksi baru punya Policy dan memanggilnya (`->can()`, `Gate::authorize`); list memakai
      scope `visibleTo()`. Livewire: ID `#[Locked]`, otorisasi ulang di setiap method aksi (§3c).
- [ ] Validasi lewat FormRequest atau aturan Livewire; tanpa `$request->all()`, tanpa `$guarded = []`;
      field status/poin/role tidak bisa diisi dari form (§3c).
- [ ] Upload hanya lewat `App\Domain\Files` ke disk privat; unduh lewat controller ber-policy (§3d).
- [ ] Output Blade memakai `{{ }}`; `{!! !!}` hanya untuk HTML yang sudah dipurifikasi (§3f).
- [ ] URL dari user divalidasi `https` dan tidak pernah di-fetch server (§3m-1).
- [ ] Export Excel/CSV aman dari injeksi formula (§3f).
- [ ] Perubahan status lewat Action dengan transaksi + `lockForUpdate()` + audit log (§3h).
- [ ] Rate limit untuk login, OTP, pencarian anggota, usulan, upload (§3a, §3b, §3m-3).
- [ ] Tidak ada flag mode uji OTP atau `APP_DEBUG=true` di konfigurasi produksi (§3b).
- [ ] Dependensi baru sudah dicek (`composer audit`/`npm audit`) dan disetujui user.

**Tampilan** (layar baru atau berubah)
- [ ] Screenshot desktop (1440) dan, bila mockup punya versi mobile, mobile (390) dilampirkan di
      samping board mockup yang sesuai.
- [ ] Memakai komponen `<x-sth.*>` dan token; tanpa style inline; tanpa emoji.
- [ ] Teks singkat berbahasa Indonesia, sentence case, siswa disapa "kamu", angka dengan koma desimal.
- [ ] Setiap input punya label, tombol ikon punya `aria-label`, target sentuh ≥ 44 px di mobile,
      status selalu punya teks.

**Deploy / infra** (PR dari I)
- [ ] Port hanya `127.0.0.1`, kontainer non-root, tanpa `docker.sock`, tanpa `privileged` (§3j).
- [ ] Rahasia tidak masuk image atau repo; `.env` produksi 600 di server.
- [ ] Langkah rollback ditulis di deskripsi PR.

## 5. Review & merge
- Semua PR direview oleh user. PR deploy/infra juga direview I; PR tampilan juga direview S.
- Minimal 1 approval dan CI hijau sebelum merge. Merge dengan **squash**.
- Reviewer mengecek fungsi, test, dan checklist di atas; komentar kecil boleh diselesaikan di PR
  lanjutan bila disepakati.

## 6. Pertanyaan dan keputusan
- Aturan produk yang belum jelas: jangan ditebak di kode. Tulis di PR atau `docs/dev-notes/`, lalu
  user membawanya ke sekolah. Daftar pertanyaan terbuka ada di `CLAUDE.md` §9.
