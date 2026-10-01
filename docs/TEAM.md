# TEAM.md — Pembagian kerja & milestone STH

> Pembagian tugas tim SMAGA Talent Hub. Konteks teknis ada di `CLAUDE.md`, daftar layar di
> `docs/UI-MAP.md`, proses PR di `CONTRIBUTING.md`. Tanggal tidak dicantumkan karena tenggat proyek
> belum diketahui (DECISIONS §F-5). Urutan milestone tetap berlaku.

## 1. Tim

| Kode | Peran | Porsi | Fokus |
|------|-------|-------|-------|
| **user** | Lead & backend core | 40% | arsitektur, aturan domain, auth, alur data, review kode |
| **S** | Frontend & fitur | 35% | design system, semua layar per peran, grafik, e-flyer, export |
| **I** | Deploy & QA | 25% | image produksi, server sekolah, CI, backup, monitoring, uji E2E, UAT |

Semua PR direview oleh **user**. PR yang menyentuh auth, upload, surat, poin, atau halaman publik juga
dicek terhadap `docs/SECURITY.md` §4 oleh I sebelum rilis.

## 2. Tanggung jawab

### user — backend core & lead (40%)
- Arsitektur dan kontrak data (`docs/ARCHITECTURE.md`), log keputusan (`docs/DECISIONS.md`).
- Model data: migrasi, enum, model, factory, seeder fiktif, policy untuk setiap model.
- Aturan domain di `app/Domain`: poin + versi bobot, batas laporan 45 hari, arsip 18 bulan,
  penomoran surat, password awal, upload file.
- Auth & akun: login NIS/NISN, aktivasi (ganti password → email → OTP), mode uji OTP, import siswa
  (dry-run, queue), reset kata sandi & email oleh Admin, nonaktif/arsip manual, 2FA staf.
- State machine pendaftaran & Action: undangan dan konfirmasi anggota tim, persetujuan Kepala Sekolah,
  pembatalan.
- Surat tugas: nomor manual TU, generate PDF dengan satu gambar ttd + stempel, QR verifikasi.
- Laporan hasil: verifikasi Kesiswaan, pembuatan prestasi per anggota, poin, rekalkulasi bobot.
- Notifikasi (mail + database) & queue, audit log, job terjadwal.
- Review kode semua PR; menjaga `CLAUDE.md` tetap benar.

### S — frontend & fitur (35%)
- Komponen design system `<x-sth.*>`, layout per peran, token dari DESIGN-SPEC-v2.
- Semua layar sesuai `docs/UI-MAP.md`:
  - **Siswa:** beranda (desktop + mobile), katalog + usulkan lomba, detail lomba + daftar tim,
    lomba saya (tracker, surat, lapor), lapor hasil (mobile), portofolio, profil talenta, tur menu.
  - **Staf:** kelola lomba + verifikasi upload (Kesiswaan), verifikasi hasil, persetujuan Kepala
    Sekolah, surat tugas TU, publikasi & apresiasi Humas, talenta siswa.
  - **Admin:** import siswa, pengaturan (bobot, batas & arsip, surat, pengguna, email).
  - **Publik:** beranda, prestasi per bidang (drill-down sampai siswa) + pemenang per lomba, peringkat.
- Grafik (dashboard sekolah, prestasi per bidang), template e-flyer Humas, export Excel/PDF.
- Uji Livewire untuk tiap layar (render, validasi, akses ditolak untuk peran lain).

### I — deploy & QA (25%)
- Image Docker produksi (multi-stage, non-root) dan compose produksi.
- Vhost Apache 2.4.29 + TLS (certbot) untuk `prestasi.sman3-smg.sch.id` di Ubuntu 18.04.
- Docker Engine static binaries (U-02), firewall (`DOCKER-USER`, hanya 22/80/443), isolasi dari
  situs utama (U-06).
- Backup terenkripsi di luar server + uji restore, rotasi log, monitoring & alert.
- CI: test, Pint, `composer audit`, `npm audit`, build image, scan image.
- Email noreply (SMTP, SPF/DKIM/DMARC) dan pengaktifan OTP nyata.
- Checklist keamanan pra-rilis (SECURITY §4), uji E2E smoke, UAT bersama sekolah, panduan pengguna.
- Titik awal sudah ada dari wave 1: `docs/DEPLOY.md`, `docker/Dockerfile.prod`, `docker/compose.prod.yaml`,
  contoh vhost `docker/apache/`, `scripts/deploy/{deploy,backup,restore}.sh`, `.github/workflows/ci.yml`
  (lihat `docs/dev-notes/W1-E.md`; uji di host Ubuntu 18.04 asli belum dilakukan).

## 3. Milestone

Setiap milestone selesai bila kriteria penerimaannya lulus di lingkungan Docker lokal (M4: di server).
Semua skenario memakai data fiktif DESIGN-SPEC-v2 §5.

### M0 · Fondasi (wave 1)
Isi: stack Docker dev, skeleton Laravel 13, migrasi + enum + model + factory + seeder fiktif, layanan
domain + unit test, auth dasar, UI kit + layout, CI awal, dokumentasi proyek.
Status terkini ada di `CLAUDE.md` §10 (sebagian besar selesai oleh agen wave 1, direview user).
Kriteria penerimaan:
- Clone bersih di Windows dan Linux → mengikuti README → aplikasi terbuka di http://127.0.0.1:8080.
- `php artisan test` hijau dan `pint --test` bersih (lewat Docker).
- Rumus poin, batas laporan 45 hari, tanggal arsip, dan nomor surat punya unit test dengan contoh spec
  (mis. 80 × 0,85 × 1,00 = 68; kelas X 2026/2027 → arsip 2030-01-01).
- Login siswa fiktif dengan NIS dan NISN → wajib aktivasi; staf masuk dengan username/email dan wajib
  2FA (di luar lokal); boot gagal bila mode uji OTP menyala di luar `local` (test).
- Showcase komponen di `/_dev` hanya muncul di `APP_ENV=local`.
- Tidak ada data siswa nyata di repo (cek manual + grep).

### M1 · Katalog, pendaftaran, persetujuan, surat
Isi: kelola lomba + antrean verifikasi upload, usulan siswa, katalog + detail, pendaftaran individu/tim
dengan undangan + konfirmasi, persetujuan Kepala Sekolah, surat tugas TU (2 nomor → 2 PDF), lomba saya
(fase 1–4), notifikasi, import siswa, pengaturan surat/ttd, pengguna & reset akun, tur menu.
Kriteria penerimaan:
- Lomba yang diunggah siswa, guru, atau Kesiswaan tidak tampil di katalog sebelum diverifikasi;
  verifikator dan waktu tercatat.
- Skenario NSIF: ketua mengundang 2 anggota → keduanya konfirmasi sendiri → status menunggu Kepala
  Sekolah → disetujui → TU mengisi nomor → 1 surat siswa berisi 3 nama + 1 surat guru, ber-QR →
  siswa dan guru bisa mengunduh; roster terkunci.
- Nomor surat ganda ditolak (termasuk 20 request paralel); TU tidak bisa generate sebelum disetujui;
  Admin tidak bisa menyetujui atas nama Kepala Sekolah (test).
- Import Excel: dry-run, baris salah dilaporkan, sel formula ditolak, NIS/NISN unik.
- Matriks peran × route untuk semua route M1 hijau.

### M2 · Laporan hasil, poin, peringkat, portofolio
Isi: transisi terjadwal (berlangsung, menunggu laporan), batas 45 hari + pengingat, lapor hasil
(desktop + mobile), verifikasi hasil Kesiswaan (verifikasi, minta revisi, tolak), prestasi + poin per
anggota, versi bobot + rekalkulasi, peringkat kumulatif, portofolio, job arsip otomatis.
Kriteria penerimaan:
- Skenario WICE: medali emas → setiap anggota mendapat 100 × 1,00 × 0,40 = 40 poin penuh.
- Laporan setelah batas ditolak kecuali batas diubah Admin; pengingat terkirim ke Mailpit.
- Mengubah bobot membuat versi baru, rekalkulasi tercatat, dan poin lama menyimpan versi bobotnya.
- Peringkat menjumlahkan semua tahun dan memuat alumni dengan chip "Alumni".
- Job arsip idempoten (jalan 2× tidak mengubah apa pun); akun arsip ditolak saat login.

### M3 · Humas, talenta, dashboard, publik
Isi: antrean publikasi + e-flyer + apresiasi, profil talenta siswa + talenta siswa (Kesiswaan),
dashboard sekolah, halaman publik (beranda, prestasi per bidang, prestasi siswa, pemenang per lomba,
peringkat), export, halaman guru pembimbing.
Kriteria penerimaan:
- Halaman publik hanya memuat nama + kelas + prestasi; tidak ada NIS/NISN/HP/tanggal lahir di HTML
  maupun JSON; foto dan nama mengikuti flag persetujuan; halaman siswa `noindex`; rate limit aktif.
- Humas tidak bisa mempublikasikan prestasi siswa tanpa persetujuan (test).
- Talenta: siswa bisa memilih lebih dari satu jenis; kelas XII boleh; status per pilihan dikelola
  Kesiswaan; export Excel aman dari injeksi formula.
- Angka dashboard sama dengan data (uji query), chip "Data contoh" hanya di data seed.

### M4 · Hardening, UAT, go-live
Isi: image produksi, vhost + TLS, firewall, backup + uji restore, monitoring, SMTP noreply + OTP nyata
(mode uji mati), checklist SECURITY §4, uji E2E smoke, UAT dengan sekolah, panduan pengguna, jadwal
aktivasi per kelas.
Kriteria penerimaan:
- Semua item SECURITY §4 tercentang atau punya penerimaan risiko tertulis yang ditandatangani.
- Formulir penerimaan risiko login (D-14) ditandatangani Kepala Sekolah.
- Restore dari backup berhasil dan dicatat (tanggal + bukti).
- Uji E2E smoke hijau di staging; UAT disetujui perwakilan sekolah.
- `STH_OTP_TEST_MODE` tidak ada di env/compose produksi; OTP email nyata diuji end-to-end.

## 4. Ketergantungan
- S membutuhkan Action, policy, dan nama route dari user sebelum layar bisa tersambung. Sampai itu siap,
  S boleh membangun layar dengan data factory dan komponen, lalu menyambungkannya kemudian.
- I membutuhkan image produksi dan daftar variabel env dari user untuk deploy; user membutuhkan CI dari I
  untuk menjaga main tetap hijau.
- Keputusan sekolah yang masih terbuka (template surat, persetujuan orang tua, SMTP, Ubuntu Pro) memblokir
  sebagian M1, M3, dan M4. Lihat `CLAUDE.md` §9.

## 5. Serah terima ke anggota tim
Sebelum S atau I mulai:
1. Clone repo → ikuti README → jalankan test.
2. Baca `CLAUDE.md`, lalu `docs/DECISIONS.md` dan bagian `docs/SECURITY.md` yang menyangkut areanya.
3. Baca semua catatan di `docs/dev-notes/` untuk tahu apa yang sudah dibangun.
4. Ambil pekerjaan dari tabel `docs/UI-MAP.md` (S) atau M4 (I); buat branch sesuai `CONTRIBUTING.md`.
