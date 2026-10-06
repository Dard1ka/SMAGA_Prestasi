# SECURITY.md — SMAGA Talent Hub (STH)

> Threat model + security baseline untuk STH (SMA Negeri 3 Semarang) di `https://prestasi.sman3-smg.sch.id`.
> Status: **baseline v2 (draft untuk review)** · Dibuat & diperbarui: 2026-09-30 (v2 memasukkan keputusan sekolah 30 Sep 2026: subdomain, Apache 2.4.29 tanpa upgrade OS, login tanpa Kode Aktivasi, OTP ditunda, alur v2) · Pemilik: Security Lead
> Dirujuk dari `CLAUDE.md`; keputusan produk/teknis dicatat di `docs/DECISIONS.md`. Kata **WAJIB** = tidak boleh rilis tanpa ini; **SEBAIKNYA** = butuh alasan tertulis jika tidak dilakukan.
> Prinsip kerja: *crosscheck setiap fitur terhadap bagian 3 dan checklist bagian 4 sebelum merge.*
> **Pembaruan 4 Okt 2026 (M1, keputusan lead D-78…D-91):** login siswa dengan email, urutan aktivasi baru dan ganti kata sandi opsional untuk siswa (§3a: K1, K4, K7, K8, K11 + tambahan risiko), import tahunan memperbarui kelas (§3f), nomor surat diketik lengkap, lambang di repo, dan cetak inline (§3g, §3m-4), alumni tanpa kelas terakhir (§3m-7), checklist §4. Analisis dan contoh kode lama **tidak dihapus**: bagian yang tidak berlaku lagi diberi tanda "Riwayat" atau "Pembaruan 4 Okt 2026".
> **Pembaruan 6 Okt 2026 (M2, D-92…D-101, perbaikan dari uji QA 5 Okt 2026):** setelah reset Admin siswa wajib membuat kata sandi baru, "Buka ulang" tidak pernah memendekkan batas, daftar kata sandi umum, sesi lama dihapus saat sesi diperbarui (§3a: K2, K4, K5, K10, "Hashing & sesi", tambahan risiko D-79), halaman kredensial tidak disimpan browser dan halaman error tidak pernah mencetak teks exception (§3f), checklist §4. Ditandai "Pembaruan 6 Okt 2026".
> **Pembaruan 6 Okt 2026 (M3, D-103…D-108, enam permintaan lead 6 Okt 2026):** "Buka" berkas tampil di popup, jadi `files.show` boleh mengirim PDF inline untuk di-frame origin ini sendiri (§3d: header, alasan tanpa `sandbox`, permintaan HEAD, apa yang tetap `DENY`; §3f: `frame-src 'self'`; §3m-2); STH tidak lagi membuat e-flyer dan Humas mendapat "Data untuk flyer" (§3c, §3d, §3m-7); berkas demo realistis hanya di `APP_ENV=local` (§3i); alasan menolak lomba opsional (tidak mengubah kontrol di dokumen ini); checklist §4. Ditandai "Pembaruan 6 Okt 2026 (M3)" atau "(D-10x)"; teks lama tetap sebagai riwayat.

---

## 0. Temuan yang mengubah arsitektur (baca dulu)

1. **Stack sudah diputuskan: Laravel 13 + PHP 8.4 + MySQL 8.4 LTS** (D-17). Laravel 12 hanya menerima security fix sampai 24 Feb 2027. Laravel 13 (rilis 17 Mar 2026) mendapat security fix sampai **17 Mar 2028**, dan PHP 8.4 sampai 31 Des 2028. Upgrade ke Laravel 14 dijadwalkan sebelum Mar 2028.
2. **Host tidak bisa diganti atau di-upgrade (Ubuntu 18.04 + Apache 2.4.29), dan tanpa Ubuntu Pro pintu depannya tidak menerima patch.** Paket apache2 publik terakhir untuk bionic adalah `2.4.29-1ubuntu4.27` (9 Mar 2023). Semua perbaikan sesudahnya hanya ada di build `+esmN` Ubuntu Pro, mis.:
   - CVE-2024-38473 (bypass autentikasi di mod_proxy)
   - USN-8396-1 (Jun 2026)
   - USN-8589-1 (Jul 2026, `+esm11`)

   Kernel 4.15 sama: CVE-2024-1086 (LPE di nf_tables, masuk CISA KEV, dipakai ransomware) hanya diperbaiki di kernel ESM. **Ubuntu Pro adalah kontrol kompensasi terpenting saat ini** (§3j).
3. **Docker apt di bionic beku di docker-ce 24.0.2 + containerd.io 1.6.21.** runc-nya < 1.1.12, jadi rentan container escape (CVE-2024-21626, CVE-2025-31133/52565/52881). Rencana: pakai **static binaries Docker Engine 29.8.1** (15 Sep 2026; containerd 2.3.5, runc 1.5.1) dengan unit systemd dan rutinitas update bulanan.
   - Syarat kernel resmi ≥ 3.10 terpenuhi oleh 4.15, tetapi uji staging tetap **WAJIB**.
   - cgroup v1 (bawaan 18.04) sudah deprecated dan hanya dijanjikan didukung sampai minimal **Mei 2029**. Itu tenggat keras migrasi OS (§3j).
4. **Login tanpa Kode Aktivasi adalah risiko yang diterima sekolah** (D-14). Dengan pola `sm4g4_DDMMYY`, akun yang belum diaktivasi bisa diambil alih oleh siapa pun yang tahu NIS/NISN + tanggal lahir, cukup dengan 1 percobaan. Sekolah memilih "Admin membantu bila akun dibajak". Dokumen ini mencatat risiko residualnya dan kontrol kompensasi K1–K12 (§3a):
   - jendela aktivasi pendek dan pembekuan
   - deteksi
   - laporan per kelas
   - reset Admin beraudit

   Penerimaan risiko **WAJIB** ditandatangani Kepala Sekolah. **Pembaruan 4 Okt 2026 (D-79):** siswa boleh tetap memakai password awal setelah aktivasi, jadi untuk siswa itu risiko ini tidak lagi berhenti saat aktivasi (rincian di §3a, "Tambahan risiko sejak D-79").
5. **Subdomain `prestasi.sman3-smg.sch.id` sudah dipilih, jadi origin-nya terpisah.** Tetapi STH masih *same-site* dan *same-host* dengan situs utama:
   - `sch.id` ada di Public Suffix List, sehingga `sman3-smg.sch.id` adalah registrable domain. SameSite dan cookie domain induk tidak memisahkan keduanya.
   - Situs utama tampaknya WordPress (HTML publik memuat `/wp-content/`, dicek 30 Sep 2026) dan berjalan di server yang sama. Kompromi WordPress bisa menjadi pijakan ke host (§3e).
6. **Halaman publik tanpa login menampilkan nama anak + prestasi** (D-16). UU PDP berlaku penuh sejak 17 Okt 2024. Data anak = data pribadi spesifik (Pasal 4(2)) dan wajib persetujuan orang tua/wali (Pasal 25). Tampilan publik nama, foto, dan sertifikat **WAJIB** bergantung pada flag persetujuan per siswa. Gambar sertifikat publik disarankan diganti badge "Terverifikasi" (§3m-7). DPIA (Pasal 34) dan DPO (Pasal 53) kemungkinan besar wajib.
7. **OTP email ditunda, dan mode dev menerima 6 digit apa pun** (D-15). Bypass ini hanya boleh aktif bila `APP_ENV=local` **dan** flag eksplisit menyala. Aplikasi menolak boot di environment lain bila flag menyala, dan CI mengujinya (§3b).
8. **Ubuntu Pro gratis hanya untuk "personal use" (≤ 5 mesin fisik).** Server sekolah hampir pasti tidak memenuhi syarat. Harga daftar $500/server fisik/tahun (ubuntu.com/pricing/pro, dicek 30 Sep 2026). Tanyakan harga pendidikan ke Canonical.

---

## 1. Ringkasan risiko teratas (Top 10, diurutkan kemungkinan × dampak)

| # | Risiko | Dampak | Mitigasi konkret (ringkas) | Pemilik |
|---|--------|--------|----------------------------|---------|
| 1 | **Pengambilalihan akun siswa sebelum aktivasi**. Password awal = NIS/NISN + tanggal lahir, tanpa Kode Aktivasi; risiko ini diterima sekolah | Akun dikuasai, email penyerang terikat, pendaftaran/laporan palsu, data siswa bocor | Jendela aktivasi 14 hari + pembekuan setelah 10 gagal, rate limit per akun (NIS & NISN satu bucket), deteksi "password awal di akun aktif", laporan aktivasi per kelas, "Reset kata sandi & email" oleh Admin (password sementara acak, audit), notifikasi email + "Bukan saya?" (§3a) | backend + Kesiswaan/Admin |
| 2 | **Host EOL tanpa patch**: Apache 2.4.29 sebagai pintu depan, kernel 4.15, Docker 24.0.2/runc lama | RCE/bypass di proxy, LPE ke root, container escape → semua data STH dan situs utama | Ubuntu Pro (esm-infra/esm-apps), Docker static 29.8.1 + update bulanan, Apache sebagai thin proxy dengan modul minimal, kontainer non-root (§3j, §3e) | devops + sekolah (anggaran) |
| 3 | **Broken access control / IDOR** di 7 peran: undangan tim, pendaftaran, surat, file, laporan, akun arsip | Kebocoran data anak, pendaftaran/persetujuan/poin palsu | Policy per model, scope `visibleTo()`, ULID, state machine, read-only arsip via policy, uji matriks peran × route (§3c, §3m-8) | backend |
| 4 | **Data anak di halaman publik tanpa persetujuan**: nama, foto, sertifikat yang memuat NISN/TTL; scraping | Pelanggaran UU PDP Pasal 25, risiko keselamatan anak, reputasi sekolah | Flag persetujuan per jenis (default tidak), sertifikat tampil sebagai badge, resource dengan allowlist kolom, slug acak, `noindex`, rate limit (§3m-7, §3i) | backend + sekolah |
| 5 | **Pivot dari situs utama (tampaknya WordPress) di host yang sama** | Shell `www-data` → baca file STH; LPE kernel → root → Docker dan DB | User Unix terpisah, izin 750/600, `www-data` tidak di grup docker, DB terpisah, port hanya `127.0.0.1`, patch kernel via ESM (§3e) | devops + pengelola situs utama |
| 6 | **Akun berhak tinggi dibobol/disalahgunakan**: kekuasaan Admin, TU + ttd Kepsek, penggantian akun Kepsek | Surat resmi palsu, persetujuan/poin palsu, export massal data anak | 2FA TOTP wajib (Admin/Kepsek/TU/Kesiswaan), akun break-glass tersegel, konfirmasi ulang password, tunda 24 jam + notifikasi saat mengganti akun Kepsek, audit log hash-chain (§3m-9) | backend + sekolah |
| 7 | **Pemalsuan surat & manipulasi poin**: gambar ttd disalin, nomor ganda, bobot/poin diubah, farming lewat anggota tim | Surat palsu beredar, apresiasi tidak adil | QR + kode verifikasi publik, SHA-256 PDF, nomor unik per jenis surat (constraint DB `(type, full_number)` sejak D-82; sebelumnya per jenis + tahun), roster tim dikunci setelah disetujui, bobot berversi + audit (§3g, §3h) | backend |
| 8 | **Upload & mesin PDF berbahaya**: sertifikat, brosur usulan, ttd; dompdf ≤ 3.1.5 (chroot bypass, baca file via SVG `data:`); EXIF GPS | XSS, baca file server (`.env`), malware ke staf, lokasi rumah anak bocor, DoS disk | Allowlist + re-encode, private disk + controller ber-policy, dompdf ≥ 3.1.6 tanpa remote/JS/`data://`, chroot sempit, kuota (§3d, §3g) | backend + devops |
| 9 | **XSS, injeksi formula, phishing via tautan usulan lomba** | Pembajakan sesi staf, eksekusi formula di laptop TU, siswa diarahkan ke situs palsu | Blade `{{ }}`, CSP nonce, `url:https` + tampilkan host, `rel="nofollow noopener noreferrer ugc"`, URL tidak pernah di-fetch server (tanpa SSRF), `StringValueBinder` (§3f, §3m-1) | frontend + backend |
| 10 | **Salah konfigurasi & rantai pasok**: bypass OTP lolos ke production, `APP_DEBUG=true`, `.env`/`APP_KEY` bocor, port DB terbuka, backup tidak ada, paket usang (Livewire CVE-2025-54068, dompdf) | Takeover massal, RCE, kehilangan data total | Boot guard + test CI, `.env` 600 di luar git/image, compose bind `127.0.0.1`, backup terenkripsi + uji restore, `composer audit`/Trivy/Dependabot (§3b, §3j, §3k) | devops + backend |

---

## 2. Aset & aktor ancaman

### 2.1 Aset

| Aset | C/I/A utama | Catatan |
|------|-------------|---------|
| Data pribadi siswa (nama, NIS, NISN, kelas, email, HP, tgl lahir, foto) | **C** tinggi | Data anak = data spesifik (UU PDP Pasal 4(2)) |
| Kredensial, hash password awal, sesi, OTP, secret 2FA, amplop break-glass | C, I | Kunci ke semua aset lain. Password awal berpola (§3a) |
| File upload (juknis/brosur, usulan lomba, sertifikat, dokumentasi, laporan penelitian) | C, I | Foto anak + metadata EXIF; sertifikat memuat NISN/TTL |
| Surat Tugas (nomor manual dari TU, PDF hasil generate) | **I** tinggi | Dokumen resmi, target pemalsuan |
| Gambar tanda tangan + stempel Kepala Sekolah (satu file) | **C, I** tinggi | Siapa pun yang memegangnya bisa membuat surat tampak resmi (§3m-5) |
| Halaman publik (nama, kelas, prestasi anak) | I, C | Tampil tanpa login; butuh persetujuan orang tua (§3m-7) |
| Poin, bobot, leaderboard, status verifikasi | **I** tinggi | Insentif kuat bagi siswa untuk curang |
| Audit log & security log | I, A | Bukti saat insiden |
| `APP_KEY`, `.env`, kredensial SMTP/DB, kunci backup | C | Bocor = kompromi total |
| Backup DB + file | C, A | Harus terenkripsi dan berada di luar server |
| Reputasi domain email sekolah (noreply) | I, A | Bisa rusak oleh spam/abuse OTP |

### 2.2 Aktor ancaman

| Aktor | Motif | Kemampuan realistis | Serangan utama |
|-------|-------|---------------------|----------------|
| **A1 Siswa iseng / teman sekelas** | Iseng, balas dendam, pamer/mengejar poin | Tahu NIS + tgl lahir target, pinjam HP teman, DevTools, Burp dari tutorial YouTube, satu jaringan WiFi sekolah | Takeover pra-aktivasi, IDOR (ganti ID di URL), ubah field hidden (poin/status), upload file palsu, XSS di kolom teks |
| **A2 Akun staf dibobol** | Dibawa oleh A1/A3 | Phishing, password dipakai ulang, PC bersama di ruang guru/TU tanpa logout | Verifikasi palsu, ubah bobot, export massal data siswa, terbitkan surat |
| **A3 Orang luar** | Otomatis/oportunistik, ransomware, defacement | Bot credential stuffing, scanner CVE (Livewire/`.env`/`.git`), spam OTP | Eksploitasi komponen usang, brute force, email bombing via endpoint OTP, SSRF via PDF |
| **A4 Situs utama `sman3-smg.sch.id` (tampaknya WordPress) di host & site yang sama** | Tidak sengaja (plugin/tema rentan) atau dikuasai A3 | Kode PHP sebagai `www-data` di host yang sama; JS di site yang sama (tetapi **bukan** origin yang sama) | Membaca file STH yang izinnya longgar, LPE kernel → root → Docker, cookie tossing `Domain=sman3-smg.sch.id`, CSRF same-site, memanggil `127.0.0.1:8080` langsung (memalsukan `X-Forwarded-For`), phishing dari domain resmi |
| **A5 Orang dalam (staf sah)** | Nepotisme, salah pakai wewenang | Hak akses sah | Ubah tingkat/kategori tanpa jejak, verifikasi binaan sendiri |
| **A6 Supply chain** | Kompromi paket/image | Paket composer/npm/image | Kode berbahaya saat build/runtime |

### 2.3 Batas kepercayaan (trust boundaries)

```
[Browser siswa/staf/publik] --HTTPS--> [Apache 2.4.29 di host: TLS + vhost prestasi.sman3-smg.sch.id]
                                          |   vhost lain: sman3-smg.sch.id (WordPress?)  <-- A4, host yang sama
                                          | ProxyPass → 127.0.0.1:8080
                                          v
                  +------------ Docker host (Ubuntu 18.04 EOL, kernel 4.15, cgroup v1) ------------+
                  | [nginx-unprivileged] -> [php-fpm app] -> [mysql 8.4] (network internal)        |
                  |                           [queue worker] -> SMTP noreply (keluar; menyusul)     |
                  |                           [scheduler]    -> arsip otomatis, backup off-site     |
                  +--------------------------------------------------------------------------------+
```
Setiap panah = titik validasi. Semua data dari browser, file upload, Excel, URL usulan lomba, dan header `X-Forwarded-*`/`Host` diperlakukan **tidak terpercaya**. Proses lokal di host juga tidak terpercaya (A4).

---

## 3. Detail kontrol per area (Laravel 13)

### 3a. Autentikasi, skema password awal & penerimaan risiko

**Keputusan sekolah (30 Sep 2026, `DECISIONS.md` D-14):**
- Siswa login dengan **NIS atau NISN** + password.
- Password awal memakai pola `sm4g4_DDMMYY` (tanggal lahir, D-24).
- **Tidak ada Kode Aktivasi.** Bila akun dibajak, Admin membantu memulihkan.

**Keputusan lead (4 Okt 2026, D-78 dan D-79):**
- Siswa yang **sudah selesai aktivasi** juga boleh login dengan **email** yang dihubungkan (selain NIS/NISN). Ini tidak menambah jalan masuk: email bukan rahasia, yang rahasia tetap password.
- Urutan login pertama menjadi **hubungkan email → kode OTP → kata sandi**. Di langkah kata sandi, **siswa boleh memilih** mengganti password atau tetap memakai password yang sekarang; **staf wajib** mengganti.

**Perbaikan dari uji QA 5 Okt 2026 (6 Okt 2026, D-92…D-95; lead: "lakukan sesuai saranmu"):**
- **Setelah reset Admin (K10), siswa wajib membuat password baru** (D-92). "Tetap memakai password yang sekarang" kini hanya berlaku untuk **password awal dari import**. Password sementara pernah tampil di layar Admin, jadi tidak boleh menjadi password tetap.
- **Halaman kredensial tidak disimpan browser dan sesi lama dihapus** saat ID sesi diperbarui (D-93; rincian di "Hashing & sesi" dan §3f).
- **Password umum, username, dan nama email ditolak** untuk semua akun (D-94; K5).
- **"Buka ulang" tidak pernah memendekkan jendela aktivasi** dan alasannya minimal 10 karakter (D-95; K2).

Rekomendasi Kode Aktivasi di baseline v1 **dicabut sebagai WAJIB**. Kode ini tetap tersedia sebagai opsi bila pemicu tinjau ulang tercapai.

**Analisis risiko yang diterima:**
- Prefix `sm4g4_` sama untuk semua siswa, jadi entropinya hanya dari tanggal lahir. Siswa satu angkatan ≤ ~1.100 kandidat tanggal. Teman sekelas **sudah tahu** tanggal lahir korban, jadi cukup 1 percobaan. NIS/NISN tercetak di surat tugas, daftar kelas, dan rapor.
- **Skenario takeover pra-aktivasi:**
  1. A1 masuk dengan NIS/NISN + tanggal lahir korban sebelum korban mengaktivasi akunnya.
  2. A1 mengganti password dan mengikat email miliknya.
  3. Korban terkunci. **Rate limit dan pesan generik tidak mencegah ini.**
- Orang luar (A3) tidak tahu tanggal lahir korban. Dengan pembekuan setelah 10 kali gagal (K3), peluang berhasilnya ≤ 10/1.100 (< 1%) per akun, dan pembekuan itu memicu alert.
- **Risiko residual:** takeover terarah oleh orang yang tahu tanggal lahir korban, selama akun belum diaktivasi. Kontrol di bawah **memperpendek jendela, mendeteksi, dan memulihkan**. Kontrol ini tidak mencegah takeover sepenuhnya.

**Tambahan risiko sejak D-79 (4 Okt 2026; analisis di atas ditulis untuk urutan lama dan dibiarkan sebagai riwayat):**
- Dalam skenario takeover di atas, langkah 2 kini berbunyi: A1 mengikat email miliknya (kode OTP masuk ke email A1), lalu boleh **melewati** ganti password.
- Siswa yang memilih "Lewati" tetap memakai password awal. Password itu bisa ditebak dari tanggal lahir **selama masih dipakai**, bukan hanya sampai aktivasi (dulu paling lama jendela K1, 14 hari). Jendela K1 berakhir saat aktivasi selesai, jadi sesudahnya password awal itu berlaku tanpa batas waktu.
- **K7 tidak bisa melihatnya.** K7 hanya menyala bila pola awal *gagal* di akun aktif; pada akun yang tetap memakai password awal, login dengan pola itu berhasil.
- **Takeover bisa tidak ketahuan.** Orang yang aktivasi lebih dulu mengikat email miliknya lalu melewati langkah password; pemilik asli tetap bisa masuk dengan password sekolah dan tidak melihat tanda apa pun. Email yang terikat itu juga menjadi alamat tujuan kode "Lupa kata sandi" akun tersebut.
- **Penyeimbang yang ada:** laporan K9 (tiap siswa memeriksa email tersamar di barisnya), event audit `activation.password_kept` dan `account.email_bound`, serta K8 untuk pemilik email yang salah ketik. Akun siswa yang tetap memakai password awal = akun aktif dengan `password_changed_at` kosong.
- **Penyeimbang yang belum ada (backlog CLAUDE.md #45):** tanda "masih pakai kata sandi dari sekolah" di Status aktivasi dan pengingat untuk mengganti password.
- **Login dengan email (D-78) tidak menambah risiko ini:** email hanya dikenali setelah aktivasi selesai, memakai jatah percobaan akun yang sama, dan email yang tidak dikenali diperlakukan seperti akun yang tidak ada (K11).
- **Pembaruan 6 Okt 2026 (D-92): risiko ini kini terbatas pada password awal dari import.** Sejak D-79 sampai perbaikan ini, siswa yang direset Admin juga bisa menekan "Lewati" dan akunnya aktif dengan password sementara yang pernah tampil di layar Admin (temuan QA Q2-02). Sejak D-92 tombol itu tidak ada untuk akun yang direset: kolom `users.temporary_password_issued_at` menandai password sementara, `CompleteActivation::keepPassword()` menolaknya (403, diperiksa lagi pada baris yang dikunci), dan tanda itu baru hilang saat pemilik memilih password sendiri. Jadi pemulihan K10 selalu berakhir dengan password yang hanya diketahui pemiliknya. Akun yang sudah terlanjur mempertahankan password sementara sebelum 6 Okt 2026 tidak dipaksa mengganti (hanya ada di data uji lokal).

**Formulir penerimaan risiko (WAJIB sebelum go-live)** ditandatangani Kepala Sekolah dan memuat:
- risiko residual di atas, **termasuk tambahan risiko D-79** (password awal boleh tetap dipakai siswa)
- kontrol K1–K12
- pemilik proses pemulihan (Admin + Kesiswaan) dan target waktu pemulihan
- pemicu tinjau ulang: ≥ 3 kasus pembajakan per semester → pertimbangkan Kode Aktivasi atau aktivasi terawasi

**Kontrol kompensasi:**

| # | Kontrol | Status |
|---|---------|--------|
| K1 | **Jendela aktivasi pendek:** `initial_password_expires_at = imported_at + 14 hari` (bisa diatur Admin, maks. 30). Setelah lewat, password awal ditolak dengan pesan generik. **SEBAIKNYA** aktivasi dilakukan serentak per kelas (jam TIK/wali kelas) agar jendela terbukanya hitungan jam, bukan hari. *Pembaruan 4 Okt 2026 (D-79):* jendela ini hanya membatasi akun yang **belum** aktivasi. Saat aktivasi selesai, batas waktunya dihapus, juga bila siswa memilih tetap memakai password awal; untuk siswa itu password awal berlaku terus sampai ia menggantinya. | WAJIB |
| K2 | **Jendela aktivasi ulang:** Admin/Kesiswaan bisa membuka jendela baru 72 jam untuk satu siswa (mis. siswa sakit atau akun dibekukan). Alasan wajib diisi dan tercatat di audit log. *Pembaruan 6 Okt 2026 (D-95):* "Buka ulang" **tidak pernah memendekkan** jendela. Batas baru = yang lebih jauh antara batas sekarang dan sekarang + 72 jam (`ReopenActivationWindow::deadlineAfter()`). Bila tidak ada yang berubah (tidak dibekukan dan sisa waktunya lebih dari 72 jam) tombolnya tidak tampil dan Action menolak. Alasan minimal 10 karakter; audit `account.activation_reopened` mencatat batas lama (`previous_expires_at`) dan batas baru. | WAJIB |
| K3 | **Pembekuan akun belum aktif:** 10 percobaan gagal kumulatif → status `aktivasi_dibekukan`. Hanya Admin/Kesiswaan yang bisa membukanya (lewat K2). Akun yang **sudah aktif** tidak pernah dikunci permanen, hanya diberi jeda progresif, agar teman tidak bisa sengaja mengunci korban. | WAJIB |
| K4 | **Aktivasi wajib saat login pertama (urutan sejak 4 Okt 2026, D-79).** Sesi pertama hanya boleh mengakses route aktivasi (middleware `activated` = `EnsureActivated`). Urutannya: **hubungkan email** → **kode OTP** (§3b; email tersimpan terverifikasi, akun masih menunggu) → **kata sandi**: siswa memilih mengganti (aturan K5, harus berbeda dari password sekarang, sesi lain dicabut) **atau** tetap memakai password sekarang; staf wajib mengganti → `activated_at` diisi dan batas waktu password awal dihapus. Yang wajib bagi siswa kini adalah **email terverifikasi**, bukan password baru (risikonya: "Tambahan risiko sejak D-79" di atas).<br>*Riwayat (sampai 3 Okt 2026):* "Wajib ganti password saat login pertama. Urutannya: `session()->regenerate()` → password baru → ikat email (+ OTP bila sudah aktif, §3b) → `activated_at` diisi → `initial_password_hash` di-null-kan."<br>*Pembaruan 6 Okt 2026 (D-92):* pilihan "tetap memakai password sekarang" hanya untuk **password awal dari import**. Siswa yang password-nya adalah password sementara dari reset Admin (K10; `users.temporary_password_issued_at` terisi) wajib mengganti, sama seperti staf: tombol "Lewati" tidak tampil dan `keepPassword()` menjawab 403. Pembaruan ID sesi di langkah-langkah ini menghapus sesi lama (D-93). | WAJIB |
| K5 | **Aturan password baru:** `Password::min(10)->letters()->numbers()->uncompromised()` + rule kustom yang menolak password yang **sama dengan atau mengandung**: tanggal lahir (semua format: `ddmmyyyy`, `dd-mm-yyyy`, `yyyymmdd`, `yyyy-mm-dd`, `ddmmyy`), `sm4g4`/`smaga`/`sman3`, NIS, NISN, atau token nama ≥ 4 huruf. Perbandingan case-insensitive setelah pemisah dihapus. *Pembaruan 6 Okt 2026 (D-94), untuk semua akun (siswa dan staf):* juga ditolak (a) **username** akun itu dan **bagian email sebelum "@"**, utuh atau potongan ≥ 4 karakter yang berisi huruf (staf tidak punya NIS, jadi ini pemeriksaan identitas mereka); (b) **password umum**: kata dari daftar bawaan `PasswordPolicy::COMMON_WORDS` (67 kata, mis. password, sandi, rahasia, admin, semarang, indonesia, juga ejaan seperti passw0rd; "@" dan "$" dibaca a dan s), urutan huruf abjad/keyboard ≥ 4, urutan angka ≥ 5, atau satu karakter diulang ≥ 4 kali, bila huruf lain yang tersisa kurang dari 5 (angka dan simbol tidak dihitung). `password123`, `Qwerty!2026`, `P@ssw0rd-99` ditolak; `sandiwara malam 77` diterima. Daftar ini pendek dan bukan pengganti `uncompromised()` (HIBP) di produksi. Password sementara yang diketik Admin untuk akun staf tidak diperiksa dengan daftar ini. | WAJIB |
| K6 | **Login hanya membandingkan hash tersimpan** (`Hash::check`). Tidak ada kode autentikasi yang membangun `'sm4g4_'.$dob`, karena itu akan membuat pola tetap berlaku setelah password diganti. | WAJIB |
| K7 | **Deteksi pembajakan.** Kondisi: login gagal pada akun yang **sudah aktif**, dan password yang dicoba sama dengan pola awal pemiliknya. Tindakan: catat event `security.initial_password_on_active` dan tampilkan di dashboard Admin/Kesiswaan (kemungkinan pemilik asli terkunci). Pemeriksaan ini hanya untuk deteksi: dijalankan **setelah** `Hash::check` gagal, tidak mengubah respons, dan tidak pernah memberi akses. *Batas sejak D-79 (4 Okt 2026):* K7 tidak bisa menyala untuk akun siswa yang tetap memakai password awal, karena di akun itu login dengan pola awal **berhasil**. Akun seperti itu dikenali dari `password_changed_at` yang kosong atau event `activation.password_kept`. | WAJIB |
| K8 | **Notifikasi email + tautan "Bukan saya?"** Saat email diikat atau diganti, notifikasi dikirim ke email baru (dan ke email lama saat ganti). Tautan "Bukan saya?" (signed URL sekali pakai, 7 hari) membekukan akun, mencabut semua sesi, dan membuat tiket untuk Admin. Batasannya: pada takeover pra-aktivasi, notifikasi pertama justru masuk ke email penyerang. Jadi kontrol ini melindungi pemilik email yang salah diketik dan pembajakan **setelah** aktivasi. Email unik antar akun; ganti email = password + OTP. *Sejak D-79 (4 Okt 2026):* pada aktivasi, notifikasi dikirim saat email terhubung (langkah 2, kode diterima), ketika akun masih menunggu aktivasi; langkah kata sandi tidak mengirim email. | WAJIB |
| K9 | **Laporan status aktivasi per kelas** (Admin & Kesiswaan). Kolom: nama, kelas, status (belum/aktif/kedaluwarsa/dibekukan), waktu aktivasi, asal (jaringan sekolah/luar), email ter-masking (`n•••@gmail.com`). Wali kelas meminta setiap siswa memeriksa barisnya ("apakah ini emailmu?"). Baris ditandai otomatis bila aktivasi terjadi di luar jam sekolah, dari luar jaringan sekolah, atau bila ≥ 3 akun diaktivasi dari satu sesi browser dalam 1 jam. | WAJIB |
| K10 | **"Reset kata sandi & email" oleh Admin** (proses pemulihan pilihan sekolah):<br>1. Verifikasi identitas tatap muka (kartu pelajar/wali kelas).<br>2. Admin mengisi alasan.<br>3. Sistem memutus email, mencabut semua sesi & remember token, membatalkan OTP, **menghapus TOTP (secret, recovery codes, waktu konfirmasi; D-66)**, dan membuat **password sementara acak** (12 karakter, tampil sekali, berlaku 72 jam, wajib diganti). Pola tanggal lahir **tidak** dipakai lagi.<br>4. Email yang dilepas karena pembajakan diblokir untuk akun itu.<br>5. Linimasa aktivitas akun selama masa pembajakan (pendaftaran, undangan tim, laporan, usulan) ditampilkan agar Kesiswaan bisa membatalkan yang palsu.<br>Semua langkah masuk audit. Alert bila satu Admin melakukan > 10 reset/jam.<br>*Pembaruan 6 Okt 2026 (D-92):* "wajib diganti" kini benar-benar dipaksa. Sejak D-79 (4 Okt 2026) sampai perbaikan ini, siswa yang direset bisa menekan "Lewati" dan tetap memakai password sementara itu. Sekarang reset mengisi `users.temporary_password_issued_at`, dan selama kolom itu terisi langkah kata sandi di aktivasi tidak punya tombol lewati. Kolom dikosongkan `SetNewPassword` saat pemilik memilih password sendiri (aktivasi, Keamanan akun, Lupa kata sandi). | WAJIB |
| K11 | **Pesan generik tanpa enumerasi.** Satu field "NIS atau NISN" dengan pesan yang sama untuk semua kegagalan: *"NIS/NISN atau kata sandi salah. Jika belum bisa masuk, hubungi Admin atau Kesiswaan."* Waktu respons seragam (`Hash::check` tetap dijalankan dengan hash dummy bila user tidak ada). Resolusi identifier: 10 digit → NISN, selain itu → NIS. Bila satu input cocok dengan dua akun berbeda → tolak + catat event. *Sejak D-78 (4 Okt 2026):* field berlabel "NIS, NISN, atau email". Input ber-`@` dicocokkan ke siswa **hanya bila aktivasinya sudah selesai** (status aktif, email terverifikasi, tidak wajib ganti password). Dalam semua keadaan lain (email belum diverifikasi, langkah kata sandi belum selesai, akun nonaktif, arsip, alamat tidak dikenal) hasilnya sama dengan akun yang tidak ada: pesan yang sama, hash dummy, dan bucket HMAC untuk identifier tidak dikenal. Email yang dikenali memakai bucket akun `u:<id>` bersama NIS/NISN. Pesan tetap *"NIS/NISN atau kata sandi salah."* Siswa tidak pernah login dengan username; staf tidak berubah (username atau email). | WAJIB |
| K12 | Pola password **tidak pernah** ditampilkan di UI atau halaman publik. Pola hanya disampaikan lewat wali kelas/surat edaran. | WAJIB |
| Opsi | Kode Aktivasi acak ~40 bit per siswa (rekomendasi v1), atau aktivasi terawasi di lab hanya dari IP sekolah. Dipakai bila pemicu tinjau ulang tercapai. | Opsional |

**Rate limit & jeda progresif** (di `AppServiceProvider::boot`). NIS dan NISN milik satu siswa **berbagi satu bucket** (dipetakan ke `user_id`); kalau tidak, penyerang mendapat jatah percobaan ganda. Sejak D-78, email siswa yang dikenali (aktivasi selesai) masuk ke bucket yang sama. Siswa di sekolah berbagi 1 IP publik (NAT), jadi batas per-IP harus longgar dan batas per-akun ketat:
```php
RateLimiter::for('login', function (Request $r) {
    $key = app(LoginIdentifier::class)->bucketKey((string) $r->input('identifier')); // 'u:<user_id>' atau HMAC stabil bila tidak ada
    return [
        Limit::perMinute(5)->by('login:'.$key),
        Limit::perHour(20)->by('login-h:'.$key),
        Limit::perMinute(60)->by('login-ip:'.$r->ip()),   // longgar: NAT sekolah
    ];
});
// routes: Route::post('/login', ...)->middleware('throttle:login');
// Akun belum aktif: hitung gagal kumulatif di kolom `failed_activation_attempts` → 10 = dibekukan (K3).
```
- Jeda progresif untuk akun aktif: gagal ke-5 → 1 menit, ke-10 → 15 menit, ke-20 → 1 jam, plus event `security.login_lockout`. Korban tetap bisa memakai "lupa password" (OTP email) atau K10.
- `bucketKey()` untuk identifier yang tidak ada tetap mengembalikan kunci stabil, sehingga perilaku limiter tidak membocorkan keberadaan akun.
- `$r->ip()` hanya benar bila **TrustProxies** dikonfigurasi (§3e). Tanpa itu semua user tampak dari IP gateway Docker dan seluruh sekolah ikut terkunci.
- Alert bila satu IP **di luar** jaringan sekolah mencoba ≥ 20 identifier berbeda per jam (password spraying).

**Hashing & sesi:**
- `HASH_DRIVER=argon2id` (image resmi `php:8.4-fpm` dikompilasi dengan `--with-password-argon2`) atau `bcrypt` dengan `BCRYPT_ROUNDS=12`. Biarkan verifikasi algoritma hash Laravel (`HASH_VERIFY`) aktif. Batasi panjang password maks. 64 karakter (bcrypt memotong di 72 byte).
- Import 1.000+ siswa: hash password awal dihitung di **queue** per chunk. Plaintext pola tidak pernah ditulis ke log atau file sementara, dan hanya boleh ada di payload job yang `ShouldBeEncrypted`.
- `session()->regenerate()` saat login, aktivasi, ganti password, aktifkan 2FA, dan perubahan peran. `Auth::logoutOtherDevices($pwd)` setelah ganti password. `SESSION_DRIVER=database` agar sesi bisa dicabut per user (dipakai K8/K10 dan arsip otomatis).
  - **Pembaruan 6 Okt 2026 (D-93; temuan QA Q1-03, Q2-07):** `session()->regenerate()` tanpa argumen membuat ID baru tetapi **membiarkan baris sesi lama** di tabel `sessions`: "Perangkat yang masuk" menampilkan perangkat hantu (sampai 7), dan ID lama masih hidup sampai kedaluwarsa. Di layar aplikasi kini dipakai `session()->regenerate(true)` (baris lama dihapus): `Auth\Activation` (setelah kode diterima, setelah ganti password, setelah "Lewati"), `Akun\Keamanan::changePassword`, `Auth\TwoFactorSetup::confirm`. Pembaruan hanya dilakukan **setelah** Action berhasil: password sekarang yang salah atau password baru yang ditolak tidak lagi menyentuh sesi. Login dan tantangan 2FA milik Fortify tidak diubah: baris yang tertinggal di sana adalah sesi tamu dari sebelum login (tanpa `user_id`), jadi tidak pernah tampil sebagai perangkat dan tidak pernah berstatus masuk.
  - **Halaman kredensial tidak disimpan browser (D-93; temuan QA Q2-01):** lihat §3f.
- `SESSION_LIFETIME=120` untuk siswa. Idle-timeout 15 menit untuk Admin/Kepsek/TU/Kesiswaan dan 30 menit untuk staf lain (PC bersama di ruang guru/TU).
- **Remember-me:** tidak ada untuk staf. Untuk siswa opsional, maks. 30 hari (`remember` = 43200 menit di konfigurasi guard; default Laravel ±400 hari). Dicabut otomatis saat ganti password atau reset Admin, dan tidak tersedia di sesi aktivasi.

**Akun staf (Guru, Kesiswaan, Kepala Sekolah, TU, Humas, Admin):**
```php
Password::defaults(fn () => app()->isProduction()
    ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
    : Password::min(8));
```
- `uncompromised()` memanggil API HIBP (k-anonymity), jadi kontainer app butuh akses keluar. Jika gagal, fallback ke daftar lokal password umum.
- **2FA TOTP (Laravel Fortify + recovery codes)**: **WAJIB** untuk Admin, Kepala Sekolah, TU, dan Kesiswaan. Middleware menolak semua route staf sampai 2FA terdaftar. **SEBAIKNYA** juga untuk Guru & Humas. Email OTP hanya fallback (dan belum aktif, §3b). Mereset 2FA orang lain butuh maker-checker + audit log. Akun break-glass: §3m-9. **Akun siswa tidak pernah punya TOTP (D-66):** semua route pengelolaan 2FA Fortify (`two-factor.*`) dan `/akun/2fa` menjawab 404 untuk akun siswa (middleware `RestrictTwoFactorToStaff`), dan login siswa tidak pernah diarahkan ke `/two-factor-challenge`.
- Tidak ada akun bersama ("tu@", "admin" dipakai beramai-ramai). Satu orang satu akun.
- Admin tidak bisa *impersonate* siswa. Jika fitur ini diperlukan: read-only, banner, dan audit log.

### 3b. OTP email & reset password

**Status 30 Sep 2026 (D-15): OTP email ditunda.** Selama pengembangan, layar verifikasi menerima 6 digit apa pun. Email noreply nyata dikonfigurasi tim deploy nanti. Aturan bypass (**WAJIB**):
1. Bypass aktif hanya bila **dua** syarat terpenuhi: `APP_ENV=local` **dan** `STH_OTP_TEST_MODE=true`. Default `false`. Flag ini tidak boleh ada di template env production maupun di compose production.
2. **Boot guard:** aplikasi menolak start (HTTP, queue, scheduler, artisan) bila flag menyala di environment selain `local`. Aplikasi juga menolak start bila `MAIL_MAILER` = `log`/`array` di production.
3. Selama aktif: tampilkan banner "Mode pengembangan: kode apa pun diterima", catat event `security.otp_bypass_used` setiap kali dipakai, tetap validasi format `^\d{6}$`, dan tetap jalankan rate limit.
4. Email yang diikat saat bypass aktif disimpan dengan `email_verified_at = null`. Setelah OTP nyata aktif, siswa diminta verifikasi ulang saat login.
5. CI menguji kedua arah (di bawah). SMTP produksi yang berfungsi adalah syarat go-live (§4).

```php
// config/sth.php
'otp_dev_bypass' => (bool) env('STH_OTP_TEST_MODE', false),

// AppServiceProvider::boot()
if (config('sth.otp_dev_bypass') && ! $this->app->environment('local')) {
    throw new RuntimeException('STH_OTP_TEST_MODE hanya boleh di APP_ENV=local.');
}
if ($this->app->isProduction() && in_array(config('mail.default'), ['log', 'array'], true)) {
    throw new RuntimeException('MAIL_MAILER log/array dilarang di production.');
}

// OtpVerifier::verify()
if (! preg_match('/^\d{6}$/', $code)) return false;
if (app()->environment('local') && config('sth.otp_dev_bypass')) {
    Log::channel('security')->warning('otp_bypass_used', ['user' => $user->id, 'purpose' => $purpose]);
    return true;
}
return $this->verifyStored($user, $purpose, $code);   // hash_equals + TTL + attempts (tabel di bawah)
```
```php
// tests/Feature/OtpBypassGuardTest.php (Pest)
it('menolak boot bila bypass menyala di production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['sth.otp_dev_bypass' => true]);
    expect(fn () => (new AppServiceProvider(app()))->boot())->toThrow(RuntimeException::class);
});
it('menolak kode sembarang bila bypass mati', function () {
    config(['sth.otp_dev_bypass' => false]);
    expect(app(OtpVerifier::class)->verify(User::factory()->create(), 'bind_email', '123456'))->toBeFalse();
});
```
CI juga menjalankan uji proses nyata tanpa config cache:
- `APP_ENV=production STH_OTP_TEST_MODE=true php artisan about` **harus** keluar dengan kode non-zero.
- `grep -R "STH_OTP_TEST_MODE=true"` pada file deploy/compose production harus kosong.

| Parameter | Nilai |
|-----------|-------|
| Format | 6 digit, `random_int(0, 999999)` dengan zero-pad (CSPRNG) |
| Penyimpanan | Hanya hash: `hash_hmac('sha256', $code, config('app.otp_key'))`. Bandingkan dengan `hash_equals` |
| TTL | 10 menit |
| Percobaan | Maks. 5 per kode, lalu kode hangus |
| Resend | Cooldown 60 detik; maks. 5/jam dan 10/hari per user; 30/jam per IP |
| Sekali pakai | Isi `consumed_at`; kode baru membatalkan kode lama dengan tujuan yang sama |
| Binding | `(user_id, purpose, target_email_hash)`; `purpose` ∈ `bind_email`, `change_email`, `reset_password`, `login_2fa` |
| Enumerasi | Respons selalu sama: *"Jika data cocok, kode telah dikirim ke email terdaftar."* Tanpa menampilkan email ter-masking di halaman lupa password |
| Setelah reset | Hapus semua sesi user, kirim email notifikasi, audit log |

```php
Schema::create('otp_codes', function (Blueprint $t) {
    $t->ulid('id')->primary();
    $t->foreignId('user_id')->constrained()->cascadeOnDelete();
    $t->string('purpose', 32);
    $t->char('code_hash', 64);
    $t->char('target_email_hash', 64);
    $t->unsignedTinyInteger('attempts')->default(0);
    $t->timestamp('expires_at');
    $t->timestamp('consumed_at')->nullable();
    $t->timestamps();
    $t->index(['user_id', 'purpose']);
});
```
- Kirim lewat **queue** khusus `otp` (prioritas tinggi): `Mail::to($u)->queue((new OtpMail($code))->onQueue('otp'))`. Mailable/job **WAJIB** `implements ShouldBeEncrypted`, karena payload antrean berisi kode plaintext.
- Dilarang `MAIL_MAILER=log` di production. Dilarang mencatat kode OTP di log.
- Isi email: nama depan saja, kode, masa berlaku, dan kalimat *"Jika bukan Anda, abaikan dan hubungi Kesiswaan."* Tanpa link bertoken, tanpa NIS/NISN.
- Jadwal pembersihan: hapus baris `otp_codes` yang berumur > 24 jam (`schedule`).

**Opsi SMTP:**

| Opsi | Kelebihan | Kekurangan / catatan |
|------|-----------|----------------------|
| **Google Workspace SMTP relay** (`smtp-relay.gmail.com`, bila domain .sch.id memakai Google Workspace for Education) | Gratis, deliverability bagus, DKIM dikelola Google | Butuh admin Workspace sekolah. Basic auth/LSA dimatikan Google sejak 14 Mar 2025, jadi pakai **autentikasi IP** (IP publik server tetap) atau app password (Google menyebutnya pengecualian). Limit 10.000 penerima/user/hari, 100 penerima/transaksi |
| **Mail server domain** (cPanel/Postfix hosting sekolah) | Kontrol penuh, tanpa pihak ketiga | Reputasi IP sering buruk (masuk spam), DKIM/SPF harus diurus sendiri |
| **Penyedia transaksional** (mis. Brevo, Mailgun, Amazon SES, Postmark) | Deliverability & log pengiriman terbaik, API, webhook bounce | Biaya; data (email siswa) diproses pihak ketiga, sering di luar negeri. Butuh perjanjian pemrosesan & pertimbangan transfer lintas negara UU PDP |

**Rekomendasi:** Google Workspace relay jika sekolah memakai Workspace; jika tidak, penyedia transaksional dengan DPA. Gunakan subdomain pengirim terpisah, mis. `noreply@notif.sman3-smg.sch.id`, agar reputasi domain utama terlindungi. Keputusan akhir di tangan tim deploy (D-15).

DNS (contoh untuk Google):
```
notif.sman3-smg.sch.id.          TXT "v=spf1 include:_spf.google.com -all"
google._domainkey.notif          TXT "v=DKIM1; k=rsa; p=<2048-bit key>"
_dmarc.notif.sman3-smg.sch.id.   TXT "v=DMARC1; p=none; rua=mailto:dmarc@sman3-smg.sch.id; adkim=s; aspf=s"
```
Gmail mewajibkan SPF **atau** DKIM untuk semua pengirim, dan SPF + DKIM + DMARC untuk pengirim ≥5.000/hari. Mulai dengan `p=none` selama 2–4 minggu, pantau laporan `rua`, lalu naikkan ke `p=quarantine`.

### 3c. Otorisasi / IDOR

**Matriks akses alur v2 (ringkas).** Kode: C=create, R=read, U=update, A=approve/verify. "Sendiri" = miliknya atau timnya. Istilah v1 "pengajuan" = "pendaftaran" di alur v2.

| Resource | Siswa | Guru Pembimbing | Kesiswaan | Kepala Sekolah | Tata Usaha | Humas | Admin |
|----------|-------|-----------------|-----------|----------------|------------|-------|-------|
| Katalog lomba | R | R | C/R/U, set tingkat/kategori/status ajang | R | R | R | C/R/U |
| Usulan lomba | C, R sendiri | – | R, A (jadikan katalog)/tolak | – | – | – | R, A |
| Pendaftaran individu/tim | C; R sendiri; U selama `menunggu_konfirmasi`; konfirmasi undangan **miliknya** | R binaan | R semua | R, **A/tolak** | R yang disetujui | – | R (A tidak disarankan, SoD §3m-9) |
| Surat tugas (nomor + PDF) | R PDF timnya | R surat guru miliknya + surat siswa binaan | R | R semua, cabut | C (isi nomor, generate) hanya untuk pendaftaran disetujui | – | R, cabut (maker-checker) |
| Laporan hasil & lampiran | C, R/U sendiri sebelum diverifikasi | R binaan | R, A | R | – | R terverifikasi **dan** ada persetujuan publikasi | R |
| Poin & bobot | R poin sendiri | R binaan | usul perubahan bobot | R | – | R | U bobot (versi baru + audit + notifikasi Kepsek) |
| Publikasi/e-flyer | R publik | R | R | R | – | C/U | A |
| Gambar ttd+stempel | – | – | – | R (pratinjau) | dipakai saat generate, tidak bisa diunduh | – | C/U |
| User, peran, import, arsip, jendela aktivasi | – | – | R status aktivasi, buka jendela aktivasi ulang | – | – | – | C/U, reset akun, ganti akun Kepsek |
| Semua resource saat akun **diarsipkan** | R sendiri saja (read-only) | – | – | – | – | – | – |

**Pembaruan 6 Okt 2026 (D-107):** baris "Publikasi/e-flyer" kini berarti publikasi saja. STH tidak lagi membuat e-flyer: Humas membuatnya sendiri di luar aplikasi dari blok "Data untuk flyer" (`App\Actions\Publications\FlyerData`). Hak akses publikasi tidak berubah (`PublicationPolicy`; kemampuan `generateFlyer` dihapus). Berkas flyer yang dibuat sebelum 6 Okt 2026 tetap dijaga `FilePolicy` seperti sebelumnya (Humas selama izin publikasi berlaku; Admin juga setelah izin dicabut, D-75), tetapi tidak ada layar yang menautkannya.

**Aturan implementasi (WAJIB):**
1. **Deny by default.** Setiap aksi controller memanggil `Gate::authorize('ability', $model)`, atau route memakai `->can('update', 'pengajuan')`. Base `Controller` Laravel 11+ tidak lagi menyertakan trait `AuthorizesRequests`; tambahkan trait itu kalau ingin memakai `$this->authorize()`.
2. **Scope query**, jangan hanya cek sesudah fetch. List, search, export, dashboard, dan leaderboard semuanya memakai `->visibleTo($user)`:
```php
public function scopeVisibleTo(Builder $q, User $u): Builder {
    return match ($u->role) {
        Role::Siswa          => $q->whereHas('anggota', fn ($a) => $a->whereKey($u->siswa_id)),
        Role::GuruPembimbing => $q->where('pembimbing_id', $u->guru_id),
        Role::Humas          => $q->where('status', Status::Terverifikasi),
        Role::TataUsaha      => $q->whereIn('status', Status::setelahDisetujui()),
        Role::Kesiswaan, Role::KepalaSekolah, Role::Admin => $q,
    };
}
```
3. Policy untuk resource yang bukan miliknya mengembalikan `Response::denyAsNotFound()` (404) agar keberadaan data tidak bocor.
4. **State machine:** `status`, `poin`, `tingkat_id`, `kategori`, `verified_by` **tidak ada** di `$fillable`. Perubahan hanya lewat metode aksi (`$pengajuan->approve($actor, $data)`) yang mengecek policy dan transisi yang sah.
5. **Mass assignment:** `$fillable` eksplisit; dilarang `$guarded = []`; dilarang `$request->all()` ke `create/update/fill`. Gunakan `$request->validated()` atau `$request->safe()->only([...])` dari FormRequest. Di non-produksi aktifkan `Model::shouldBeStrict()` (termasuk `preventSilentlyDiscardingAttributes`).
6. **FormRequest::authorize()** tidak boleh hanya `return true;`; panggil policy di sana atau di controller.
7. **Route model binding dengan ULID** (`use HasUlids;`, kolom `ulid` unik atau primary) untuk pengajuan, laporan, surat, dan lampiran. ULID mempersulit enumerasi tetapi **bukan** pengganti policy. Relasi bertingkat memakai `Route::scopeBindings()` (`/pengajuan/{pengajuan}/lampiran/{lampiran}`).
8. **Vertical escalation:** role hanya bisa diubah Admin, tercatat di audit log, dan perubahan role staf butuh persetujuan kedua. Role grup route memakai middleware `role:` **plus** policy (defense in depth).
9. **Livewire (jika dipakai):** public property bisa dimanipulasi klien. Pakai `#[Locked]` untuk ID, otorisasi ulang di setiap action method, dan versi ≥ 3.6.4 (CVE-2025-54068, RCE tanpa autentikasi, masuk CISA KEV).
10. **Separation of duties:** Kesiswaan tidak boleh memverifikasi pengajuan di mana ia sendiri tercatat sebagai pembimbing. Tidak ada yang boleh menyetujui perubahannya sendiri. Admin tidak menyetujui pendaftaran atas nama Kepala Sekolah (§3m-9).
11. **Akun arsip read-only** ditegakkan di `Policy::before()` untuk semua ability tulis, bukan hanya di UI (§3m-8).

**Uji wajib (Pest), matriks role × route:**
```php
dataset('forbidden', [
  'siswa lihat pengajuan siswa lain' => [Role::Siswa, 'pengajuan.show', 404],
  'siswa approve'                    => [Role::Siswa, 'pengajuan.approve', 403],
  'guru lihat non-binaan'            => [Role::GuruPembimbing, 'pengajuan.show', 404],
  'humas lihat belum terverifikasi'  => [Role::Humas, 'pengajuan.show', 404],
  'TU ubah bobot'                    => [Role::TataUsaha, 'bobot.update', 403],
]);
it('menolak akses lintas role/pemilik', function ($role, $route, $status) {
    $owner = Siswa::factory()->create();
    $p = Pengajuan::factory()->for($owner)->create(['status' => Status::Diajukan]);
    actingAs(User::factory()->role($role)->create())
        ->get(route($route, $p))->assertStatus($status);
})->with('forbidden');
```
Kasus alur v2 yang wajib ada di dataset:
- siswa mengonfirmasi undangan tim milik siswa lain → 404
- ketua mengonfirmasi atas nama anggota → 403
- TU men-generate surat untuk pendaftaran yang belum disetujui Kepsek → 403
- akun arsip membuat pendaftaran/laporan/usulan → 403
- Humas/TU/Siswa mengunduh file ttd → 403/404
- Admin menyetujui pendaftaran → 403 (kecuali sekolah memutuskan lain)
- Kesiswaan mengubah bobot → 403
- siswa mengubah roster tim setelah disetujui → 403

Uji manual pra-rilis: dua akun siswa (A, B). Di Burp/DevTools, ganti setiap ULID/ID milik A dengan milik B di **semua** endpoint (GET/POST/PATCH/DELETE, download, export, JSON), dan tambahkan field `status`, `poin`, `role`, `verified_by` ke setiap form.

### 3d. Upload file

| Jenis | Ekstensi | Maks. | Perlakuan |
|-------|----------|------|-----------|
| Juknis/brosur lomba (katalog Kesiswaan & usulan siswa) | pdf, jpg, jpeg, png, webp | 5 MB | PDF: cek magic `%PDF-` + scan konten aktif. Gambar di-re-encode. Brosur usulan tetap privat sampai disetujui Kesiswaan |
| Poster/cover lomba (opsional) | jpg, jpeg, png, webp | 3 MB | Re-encode ≤ 2048 px |
| Sertifikat | pdf, jpg, jpeg, png, webp | 5 MB | Gambar di-re-encode. **Tidak** tampil publik secara default (§3m-7) |
| Foto siswa & dokumentasi | jpg, jpeg, png, webp | 5 MB | Re-encode ke WebP/JPEG ≤ 2048 px, **EXIF/GPS dibuang** |
| Laporan penelitian | pdf | 20 MB | Cek magic, scan heuristik |
| Gambar tanda tangan + stempel (hanya Admin) | png | 1 MB | Re-encode PNG (metadata dibuang), maks. 1500×800 px, disk privat terpisah, tanpa URL publik (§3m-5) |
| PDF surat tugas | – | – | **Tidak di-upload**; dibuat sistem (§3g). SHA-256 disimpan, immutable |

**SVG, HTML, DOC(X)/XLS(M), ZIP, dan HEIC ditolak** (HEIC dari iPhone: minta siswa ekspor ke JPG, atau konversi di klien).

```php
'sertifikat' => ['required', 'extensions:pdf,jpg,jpeg,png,webp',
                 File::types(['pdf','jpg','jpeg','png','webp'])->max(5 * 1024)],
'foto'       => ['required', 'extensions:jpg,jpeg,png,webp',
                 File::image()->max(5 * 1024)->dimensions(Rule::dimensions()->maxWidth(8000)->maxHeight(8000))],
```
- `mimes`/`File::types` membaca isi file untuk menebak MIME; `extensions` memeriksa ekstensi dari user. Pakai **keduanya**. Rule `image` Laravel menolak SVG secara default; **jangan** pakai `allow_svg`.
- **Re-encode gambar** (Intervention Image v3 dengan driver GD): decode, resize, lalu encode ulang. Metadata tidak ikut sehingga EXIF (termasuk GPS) hilang. Batasi dimensi **sebelum** decode (anti bom dekompresi). Hindari Imagick/Ghostscript untuk file user.
- **PDF:** tolak bila tidak diawali `%PDF-`. Tandai untuk review (atau tolak) bila mengandung `/JavaScript`, `/JS`, `/OpenAction`, `/Launch`, `/EmbeddedFile`, `/AA`. Implementasi (D-70, `App\Domain\Files\PdfScanner`): nama-nama itu dicari di seluruh berkas dan di dalam object stream; object stream yang tidak bisa dibuka penuh (filter selain satu FlateDecode, `/DecodeParms`, gagal inflate, hasil inflate > 16 MB), kata `stream` di dalam string/komentar, dan objek di dalam badan stream membuat berkas **ditolak**. Jangan pernah merender/mengonversi PDF user di server. Bila memakai viewer pdf.js, versinya **≥ 4.2.67** dan `isEvalSupported: false` (CVE-2024-4367, eksekusi JS di origin aplikasi).
- **Penyimpanan:** `Storage::disk('local')` (root Laravel 11+: `storage/app/private`), path `lampiran/{Y}/{m}/{ulid}.{ext}` dengan ekstensi dari hasil deteksi MIME, **bukan** dari nama asli. Nama asli hanya disimpan di DB (maks. 150 karakter, disanitasi, ditampilkan via `{{ }}`). Disk `public` + `storage:link` **hanya** untuk e-flyer Humas yang sudah dipublikasi dan ada persetujuannya.
- **Download lewat controller ber-policy:**
```php
public function show(Lampiran $lampiran)
{
    Gate::authorize('view', $lampiran);
    event(new FileAccessed($lampiran, auth()->user()));        // access log
    return Storage::disk('local')->download($lampiran->path, $lampiran->download_name, [
        'Content-Type'            => $lampiran->mime,           // dari deteksi server
        'X-Content-Type-Options'  => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; sandbox",
        'Cache-Control'           => 'private, no-store',
    ]);  // attachment; preview inline hanya untuk gambar hasil re-encode
}
```

**Pembaruan 6 Okt 2026 (M3, D-105): "Buka" berkas tampil di popup.** Komentar "preview inline hanya untuk gambar" di contoh atas adalah riwayat. Lead meminta berkas bukti (sertifikat, juknis) langsung tampil tanpa di-download dulu, dengan tombol Download tetap ada. Sejak itu `FileController::show` (`files.show`, `/berkas/{file}`) boleh menjawab **inline** bila URL memuat `?tampil=1` **dan** MIME tersimpan ada di allowlist: `application/pdf`, `image/jpeg`, `image/png`, `image/webp` (dulu: semua `image/*`; SVG, GIF, HTML, xlsx tetap attachment apa pun isi query-nya). Tanpa `?tampil=1` jawabannya tetap attachment seperti contoh di atas.

Header tiap jawaban (diuji di `tests/Feature/Files/FilePreviewTest.php`):

| Jawaban | Disposition | X-Frame-Options | CSP |
|---|---|---|---|
| halaman aplikasi mana pun | – | `DENY` | CSP halaman, kini dengan `frame-src 'self'` tertulis (§3f); `frame-ancestors 'none'` dan `object-src 'none'` tidak berubah |
| download (bawaan) | attachment | `DENY` | `default-src 'none'; sandbox` (tidak berubah) |
| gambar `?tampil=1` | inline | `DENY` | `default-src 'none'; sandbox` (tidak berubah; ditampilkan dengan `<img>`, tidak pernah di-frame) |
| **PDF `?tampil=1` di `files.show`** | inline | `SAMEORIGIN` | `default-src 'none'; frame-ancestors 'self'` |
| tipe lain dengan `?tampil=1`, PDF di `files.public`, jawaban 404 | attachment / – | `DENY` | seperti download / halaman |

- **Satu-satunya pelonggaran** ada di jawaban PDF inline dari `files.show`: origin ini sendiri boleh mem-frame-nya, origin lain tidak. Tidak ada perubahan global yang melemahkan halaman: `frame-src 'self'` sama artinya dengan `default-src 'self'` yang sudah berlaku untuk frame; tidak ada `blob:`, `data:`, atau origin lain di `frame-src`.
- **Kenapa tanpa `sandbox` di jawaban itu.** Penampil PDF bawaan Chromium tidak berjalan di dokumen ber-sandbox. Dicoba di browser dengan dua cara: `sandbox` di CSP jawaban, dan atribut `sandbox` pada frame (juga dengan `allow-same-origin allow-scripts`); keduanya membuat frame tetap kosong. Dengan `default-src 'none'; frame-ancestors 'self'` dan `object-src 'none'` di halaman, PDF tampil.
- **Yang tetap menahan berkas menjalankan sesuatu di origin aplikasi:** MIME tersimpan berasal dari deteksi server saat upload, jawaban berkata `application/pdf` + `nosniff` (tidak pernah HTML), `default-src 'none'` tetap ada, PDF berisi skrip atau aksi sudah ditolak `PdfScanner` saat upload (D-70), dan **tidak ada pdf.js di origin kita**: penampil browser yang bekerja, jadi catatan versi pdf.js di atas tetap hanya berlaku bila suatu saat viewer itu dipasang.
- **Permintaan HEAD lebih dulu.** Skrip popup (`resources/js/app.js`) bertanya dengan HEAD dan baru memasang frame bila jawabannya `200` + `application/pdf` + `inline`, dan hanya untuk alamat di origin yang sama. Dengan begitu popup tidak pernah memulai download sendiri, dan sesi habis, 404, atau 429 menjadi satu kalimat di dialog. HEAD menjalankan pemeriksaan policy yang sama, tidak membaca isi berkas, dan **tidak diaudit** (*sementara, menunggu jawaban lead*). Preview sendiri tercatat sebagai `file.downloaded` dengan `inline: true`; menekan "Download" menambah satu baris biasa.
- **Yang tidak berubah:** `FilePolicy::view`, 404 untuk berkas yang bukan haknya (GET dan HEAD, dengan atau tanpa `?tampil=1`), middleware `auth` / `activated` / `staff.2fa`, `throttle:60,1` (satu preview PDF = 2 permintaan, "Download" 1 lagi), `Cache-Control: private, no-store` di semua jawaban berkas termasuk yang inline. `files.public` (tamu) **tidak pernah** mengirim PDF inline; di sana tidak ada yang perlu di-frame. Halaman tanpa dialog (`layouts::public`, `layouts::auth`) hanya men-download.
- **Produksi:** vhost Apache memakai `Header setifempty X-Frame-Options`, jadi `SAMEORIGIN` dari jawaban itu seharusnya bertahan (dibaca di `docker/apache/*.conf`, **belum dijalankan**). Belum diuji: Firefox, Safari, Edge, HP sungguhan. Bila di salah satu browser frame tetap kosong karena `default-src 'none'`, yang boleh dilepas hanya direktif itu dan hanya di jawaban PDF inline (checklist §4).
- **Surat tugas** (`FilePurpose::Letter`) belum memakai popup; rutenya sudah bisa mengirimnya inline, jadi yang menahan hanyalah link di layar. Keputusan lead (*sementara*).
- **E-flyer (D-47, D-107):** kalimat "Disk `public` + `storage:link` hanya untuk e-flyer Humas" di butir Penyimpanan adalah riwayat rancangan awal. E-flyer tidak pernah ditaruh di disk publik (D-47), dan sejak 6 Okt 2026 STH tidak membuat e-flyer sama sekali (D-107). Aturannya tetap: berkas pengguna tidak pernah ditaruh di disk `public` (`CLAUDE.md` §3 butir 4).
- Link berbagi sementara (mis. untuk TU): `URL::temporarySignedRoute('lampiran.show', now()->addMinutes(10), [...])` + middleware `signed` **dan** policy.
- **Kuota & DoS:** maks. 10 file per pendaftaran/laporan, 200 MB per siswa, rate limit upload 30/jam per user, dan maks. 5 usulan lomba/hari per siswa. Batas selaras di tiga lapis: PHP (`upload_max_filesize=20M`, `post_max_size=25M`), nginx kontainer (`client_max_body_size 25m`), dan proxy luar (`client_max_body_size`/`LimitRequestBody`).
- **Volume upload dan MySQL dipisah** (idealnya partisi/disk berbeda). Alert saat disk ≥ 80%. Disk penuh membuat MySQL crash.
- **ClamAV (opsional):** memuat signature butuh ≥ 1,2 GiB RAM (disarankan 3–4 GiB) dan dua kali lipat saat reload kecuali `ConcurrentDatabaseReload no`. Di server 8 GB yang dipakai bersama, jalankan hanya bila anggaran RAM §3j memungkinkan, dengan scan asinkron di queue (status `pending_scan`: file belum bisa diunduh role lain). Tanpa ClamAV, andalkan allowlist + re-encode + header `sandbox` + staf tidak membuka PDF di aplikasi desktop lama.

### 3e. Deployment subdomain `prestasi.sman3-smg.sch.id` di Apache 2.4.29 (host bersama)

**Keputusan (30 Sep 2026, D-18):** STH berjalan di subdomain sendiri. Situs utama `https://sman3-smg.sch.id/` adalah aplikasi terpisah di server yang sama. Apache 2.4.29 (paket Ubuntu 18.04) di host menjadi TLS reverse proxy ke kontainer. Risiko "sub-path pada origin bersama" dari v1 **tidak berlaku lagi**.

**Yang didapat dari subdomain:** origin terpisah. Akibatnya JS situs utama tidak bisa membaca respons atau DOM STH, Service Worker situs utama tidak mencakup STH, dan CSP `'self'` menjadi bermakna.

**Yang tidak didapat (risiko residual):**
- **Same-site.** `sch.id` terdaftar di Public Suffix List, jadi `prestasi.sman3-smg.sch.id` dan `sman3-smg.sch.id` berada dalam satu *site*. Akibatnya:
  - `SameSite=Lax` **tidak** memblok request dari situs utama.
  - Situs utama bisa menyetel cookie `Domain=sman3-smg.sch.id` yang ikut terkirim ke STH (cookie tossing / session fixation).
- **Same-host.** Kompromi situs utama memberi pijakan di mesin yang sama (lihat "Isolasi" di bawah).

**Laravel `.env`:**
```dotenv
APP_URL=https://prestasi.sman3-smg.sch.id
SESSION_DRIVER=database
SESSION_COOKIE=__Host-sth_session   # prefix __Host-: wajib Secure, Path=/, tanpa Domain → kebal cookie tossing dari domain induk
SESSION_DOMAIN=null                  # host-only; JANGAN .sman3-smg.sch.id
SESSION_PATH=/
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
```
- **CSRF:** middleware `PreventRequestForgery` Laravel 13 memeriksa `Sec-Fetch-Site` lebih dulu, lalu jatuh ke token CSRF.
  - **Jangan** pakai `preventRequestForgery(allowSameSite: true)`, karena opsi itu akan mempercayai situs utama.
  - `@csrf` / `X-CSRF-TOKEN` tetap wajib.
  - Cookie `XSRF-TOKEN` bisa ditimpa domain induk, jadi jangan dijadikan satu-satunya sumber token.
- Setelah mengubah `.env`: jalankan `php artisan optimize`.

**TrustProxies & TrustHosts** (`bootstrap/app.php`). Rantainya: Apache (host) → `127.0.0.1:8080` → docker-proxy → nginx kontainer → php-fpm. IP yang dilihat aplikasi adalah gateway network Docker. Karena itu subnet compose dipatok (§3j), dan hanya gateway itu yang dipercaya:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(
        at: ['172.30.10.1'],   // gateway network `edge` (subnet dipatok di compose); cek `docker network inspect`
        headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT |
                 Request::HEADER_X_FORWARDED_PROTO,   // TANPA X-Forwarded-Host / -Prefix
    );
    $middleware->trustHosts(at: ['^prestasi\.sman3-smg\.sch\.id$'], subdomains: false);
})
```
- **`X-Forwarded-Host` tidak dipercaya.** `mod_proxy_http` *menambahkan* nilainya ke header yang sudah dikirim klien, sedangkan `Request::getHost()` Symfony memakai elemen **pertama**, yaitu nilai dari penyerang. Akibatnya host header poisoning di link reset dan signed URL. Host asli diteruskan lewat `ProxyPreserveHost On`, dan `trustHosts` menolak Host lain.
- **`X-Forwarded-For` aman.** Apache menambahkan IP klien di akhir, dan Symfony membaca dari kanan dengan melewati proxy tepercaya.
- **Residual:** proses lokal di host bisa memanggil `127.0.0.1:8080` langsung dan memalsukan `X-Forwarded-For`, sehingga rate limit per-IP bisa diakali. Batas per-akun (§3a) tetap berlaku.

**Vhost Apache** (`/etc/apache2/sites-available/prestasi.conf`; aktifkan modul dengan `sudo a2enmod proxy proxy_http headers ssl`). Versi yang sudah diuji ada di `docker/apache/prestasi.sman3-smg.sch.id.conf`; pakai file itu.
```apache
<VirtualHost *:80>
    ServerName prestasi.sman3-smg.sch.id
    Redirect permanent / https://prestasi.sman3-smg.sch.id/
</VirtualHost>

<VirtualHost *:443>
    ServerName prestasi.sman3-smg.sch.id
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/prestasi.sman3-smg.sch.id/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/prestasi.sman3-smg.sch.id/privkey.pem
    # TLS 1.3 ada di apache2 bionic ≥ 2.4.29-1ubuntu4.12 (LP #1845263)
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305
    SSLHonorCipherOrder off
    SSLSessionTickets off
    # Let's Encrypt mematikan OCSP (Agu 2025)
    SSLUseStapling off

    # jangan pernah jadi forward/open proxy
    ProxyRequests Off
    ProxyPreserveHost On
    ProxyTimeout 60
    RequestHeader unset X-Forwarded-Host
    RequestHeader set X-Forwarded-Proto "https"
    RequestHeader set X-Forwarded-Port  "443"
    ProxyPass        / http://127.0.0.1:8080/ retry=0
    ProxyPassReverse / http://127.0.0.1:8080/
    # 25 MB, selaras php/nginx (§3d)
    LimitRequestBody 26214400

    # TANPA includeSubDomains/preload
    Header always set Strict-Transport-Security "max-age=31536000"
    Header always unset X-Powered-By
    ErrorLog  ${APACHE_LOG_DIR}/prestasi-error.log
    CustomLog ${APACHE_LOG_DIR}/prestasi-access.log combined
</VirtualHost>
```
- **Pengaturan global Apache** (berlaku untuk semua vhost, jadi koordinasikan dulu dengan pengelola situs utama):
  - `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`.
  - **Jangan** menambahkan `Protocols h2` di vhost STH. mod_http2 di 2.4.29 sangat tua, dan CVE-2024-27316 hanya diperbaiki di ESM.
- **Uji setelah deploy:**
  - `apache2ctl configtest && sudo systemctl reload apache2`.
  - `curl -sI https://prestasi.sman3-smg.sch.id` menampilkan HSTS dan tidak menampilkan versi di `Server`.
  - `curl -H 'Host: evil.test' http://127.0.0.1:8080/` ditolak.
  - `curl -H 'X-Forwarded-Host: evil.test' https://prestasi.sman3-smg.sch.id/lupa-password` tidak mengubah host di link.
  - `request()->ip()` menampilkan IP klien asli, dan `request()->isSecure()` bernilai `true`.

**TLS & sertifikat di 18.04:**
- Pakai **certbot snap**; paket apt bionic sudah tua dan harus dihapus dulu:
  ```bash
  sudo apt-get remove certbot python3-certbot-apache
  sudo snap install --classic certbot
  sudo ln -s /snap/bin/certbot /usr/bin/certbot
  sudo certbot --apache -d prestasi.sman3-smg.sch.id
  sudo certbot renew --dry-run
  ```
  snapcraft.io masih mencantumkan 18.04. Bila snap bermasalah di host ini, fallback-nya image `certbot/certbot` dengan `certonly --webroot` + cron + `apachectl graceful`.
- DNS: record A `prestasi` → IP publik server. SEBAIKNYA ada CAA `0 issue "letsencrypt.org"`; CAA juga berlaku untuk situs utama, jadi koordinasikan dengan pengelola DNS.
- **Pemantauan kedaluwarsa WAJIB.** Let's Encrypt **berhenti mengirim email kedaluwarsa sejak 4 Jun 2025**, dan masa berlaku sertifikat turun ke 64 hari (10 Feb 2027) lalu 45 hari (16 Feb 2028). Pasang alert bila sisa masa berlaku < 14 hari, dicek dari luar (§3l).
- **HSTS** hanya dipasang di subdomain ini, tanpa `includeSubDomains`/`preload` kecuali IT sekolah setuju (subdomain lain yang masih HTTP akan putus). Header dipasang di Apache saja; middleware Laravel tidak menduplikasinya.

**Isolasi dari situs utama di host yang sama.** Situs utama tampaknya WordPress: HTML publiknya memuat `/wp-content/` (dicek 30 Sep 2026). **Perlu konfirmasi:** apakah benar di host ini, versi WordPress/PHP, daftar plugin, dan siapa yang memperbarui. PHP bawaan 18.04 adalah 7.2 (`7.2.24-0ubuntu0.18.04.17`), sedangkan sejak WordPress 7.0 PHP minimum adalah 7.4. Bila situs utama memakai PHP bawaan, WordPress-nya kemungkinan tertahan di versi lama, dan itulah titik masuk paling mungkin ke host.

| Kontrol | Implementasi | Status |
|---------|--------------|--------|
| User Unix terpisah | STH dideploy di `/opt/sth` milik user `sth` (bukan `www-data`). Direktori `chmod 750`; `.env`, `secrets/`, dan dump backup `600`. `www-data` tidak bisa membaca apa pun milik STH | WAJIB |
| Grup `docker` | Hanya admin tepercaya. `www-data` dan user situs utama **tidak** masuk grup `docker` (setara root) | WAJIB |
| Database terpisah | MySQL STH hanya ada di kontainer (network internal, tanpa port). Tidak ada user/password DB yang sama dengan situs utama | WAJIB |
| Jaringan & volume | Port app hanya `127.0.0.1:8080`; `backend` `internal: true`. Kontainer STH tidak me-mount path situs utama, dan situs utama tidak me-mount volume STH | WAJIB |
| Vhost | Vhost STH tanpa `DocumentRoot` berisi file, `AllowOverride None`. Tidak ada direktori STH di DocumentRoot situs utama | WAJIB |
| Patch kernel & Apache | Ubuntu Pro (§3j). Tanpa itu, shell `www-data` + LPE kernel (mis. CVE-2024-1086) = root = semua kontainer | WAJIB (keputusan anggaran sekolah) |
| PHP situs utama | php-fpm dengan user sendiri, bukan `mod_php` di dalam proses Apache (worker Apache memegang kunci TLS semua vhost di memori) | SEBAIKNYA (pengelola situs utama) |
| Log & deteksi | Log vhost STH terpisah; fail2ban `sshd`; tinjau `last`/`auth.log` mingguan | SEBAIKNYA |

### 3f. Security headers, XSS, injeksi formula

Middleware `SecurityHeaders` (global, di-append di `bootstrap/app.php`):
```php
public function handle(Request $request, Closure $next)
{
    Vite::useCspNonce();                       // nonce untuk tag Vite
    $response = $next($request);
    $n = Vite::cspNonce();
    $response->headers->add([
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-$n'; ".
            "style-src 'self' 'nonce-$n'; img-src 'self' data: blob:; font-src 'self'; ".
            "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; ".
            "frame-ancestors 'none'; upgrade-insecure-requests",
        'X-Content-Type-Options'       => 'nosniff',
        'X-Frame-Options'              => 'DENY',
        'Referrer-Policy'              => 'strict-origin-when-cross-origin',
        'Permissions-Policy'           => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy'   => 'same-origin',
    ]);
    return $response;
}
```
- Mulai dengan `Content-Security-Policy-Report-Only` selama 1–2 minggu, lalu enforce. Skrip inline hanya dengan `nonce="{{ Vite::cspNonce() }}"`; lebih baik tidak ada skrip inline sama sekali, dan tidak ada handler `onclick=`.
- Alpine.js build standar butuh `'unsafe-eval'`. Pakai build `@alpinejs/csp` atau catat pengecualian ini secara tertulis. Livewire: berikan nonce ke `@livewireScripts`/`@livewireStyles`.
- Font/CDN eksternal tidak dipakai; bundel asset lokal via Vite (juga baik untuk privasi).
- **HSTS:** dipasang di vhost Apache subdomain (§3e) dengan `max-age=31536000`. **Jangan** `includeSubDomains`/`preload` tanpa persetujuan IT sekolah (bisa memutus subdomain lain yang masih HTTP). Header ini tidak diduplikasi di middleware Laravel.
- Header yang sudah ditambahkan proxy luar jangan diduplikasi. Hilangkan `X-Powered-By` (`expose_php=Off`) dan `server_tokens off`.

**Pembaruan 6 Okt 2026 (D-93, D-101; temuan QA Q2-01, Q1-07, Q2-30, Q2-03):**
- **Halaman kredensial tidak pernah disimpan browser.** Middleware global `PreventCredentialPageCaching` (di-append di `bootstrap/app.php` setelah `SecurityHeaders`) memasang `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` dan `Pragma: no-cache` pada **semua** jawaban (GET, POST, redirect, halaman error) untuk `/login`, `/aktivasi`, `/lupa-kata-sandi`, `/user/confirm-password`, `/two-factor-challenge`, dan `/akun/2fa` (`PreventCredentialPageCaching::PATHS`). Sebelumnya `/login` hanya membawa `no-cache, private` bawaan Symfony, yang masih membolehkan cache Back/Forward: setelah Keluar, Back dua kali memunculkan halaman masuk dengan NIS dan password masih terisi (komputer lab bersama). Livewire menambah `no-store` sendiri, tetapi hanya pada jawaban yang merender komponen Livewire; halaman Fortify (`/login`, konfirmasi password, tantangan 2FA) adalah Blade biasa. Halaman baru yang menampilkan atau menerima password, kode, atau rahasia 2FA **WAJIB** ditambahkan ke `PATHS`.
- **Kolom password dikosongkan** oleh `resources/js/app.js` (lapisan kedua): saat halaman dipulihkan dari cache Back/Forward (`pageshow` dengan `persisted`), dan tepat setelah form POST biasa dikirim. Berlaku juga untuk kolom yang sedang ditampilkan sebagai teks ("Tampilkan kata sandi"). Form `wire:submit` tidak disentuh saat dikirim.
- **Halaman error milik aplikasi tidak pernah mencetak teks exception.** `resources/views/errors/{401,403,404,405,419,429,500,503,4xx,5xx}.blade.php` hanya berisi kode status, judul, dan satu kalimat tetap berbahasa Indonesia; tidak ada `$exception->getMessage()`, nama peran, nama kelas, atau jejak stack. CSS lewat nonce (`layouts.partials.head`), tanpa `style`/JS inline, `noindex`. Laravel memakai view ini juga saat `APP_DEBUG=true`, jadi permintaan dengan method yang salah (mis. `GET /logout`) tidak lagi membangun halaman debug (temuan: 405 berukuran 870 kB dalam 6–63 detik). Link "Kembali" hanya memakai Referer bila itu halaman lain di aplikasi ini (skema, host, dan port sama), jadi tidak bisa dipakai untuk mengalihkan ke situs luar. Untuk 5xx halaman error tidak membuka koneksi database.
- **`GET /logout` hanya mengalihkan** (sudah masuk → beranda, tamu → halaman masuk) dan tidak pernah mengakhiri sesi: logout tetap `POST /logout` + CSRF, sehingga link atau gambar di situs lain tidak bisa mengeluarkan pengguna.
- **Halaman pertama setelah login:** GET yang menjawab 403 atau 404 tepat setelah login dialihkan ke beranda (`ErrorPageServiceProvider::afterLogin()`). Otorisasi tidak berubah: yang berubah hanya tujuan pengalihan, dan hanya untuk satu permintaan itu.
- Belum diuji ulang di lingkungan seperti produksi (`APP_DEBUG=false`); lihat checklist §4.

**Pembaruan 6 Okt 2026 (M3, D-105, D-107):**
- **`frame-src 'self'` tertulis di CSP halaman.** Contoh middleware di atas belum memuatnya; `SecurityHeaders` kini menulis `frame-src 'self'` (artinya sama dengan `default-src 'self'` yang sudah berlaku untuk frame) supaya popup preview boleh mem-frame PDF dari origin ini. Tidak ada `blob:`, `data:`, atau origin lain. Halaman aplikasi sendiri **tetap tidak bisa di-frame** siapa pun: `frame-ancestors 'none'` dan `X-Frame-Options: DENY` tidak berubah. Header yang sudah dipasang sebuah jawaban (PDF inline: `SAMEORIGIN` dan CSP-nya sendiri, §3d) tidak ditimpa middleware ini.
- **Popup preview tanpa `style`/JS inline:** satu dialog `<x-sth.preview />` di `layouts::app` dan `layouts::mobile`; link membawa hook `data-sth-preview*`; teks dialog ada di atribut `data-msg-*` komponen itu, bukan di skrip. Uji browser M3-C: 0 peristiwa `securitypolicyviolation` saat PDF dibuka (Chromium).
- **"Data untuk flyer" (D-107)** dirender dengan `{{ }}` sebagai teks polos; tombol "Salin data" (`data-sth-copy`) hanya menyalin `innerText` satu elemen di halaman itu ke clipboard, tidak mengirim apa pun ke server atau ke pihak lain. Di butir "Rich text (caption e-flyer, berita Humas)" di bawah, kata "e-flyer" tinggal riwayat: STH tidak lagi membuat e-flyer; caption publikasi tetap ada.

**XSS di Blade:**
- Output **selalu** `{{ }}`. `{!! !!}` **dilarang** kecuali untuk kolom yang sudah dipurifikasi (`*_html_clean`). CI mem-*grep* `{!!` dan setiap temuan harus ada di allowlist review.
- Data ke JS: `@js($data)` / `Js::from()`, bukan `{{ }}` di dalam `<script>`. Atribut selalu diberi tanda kutip.
- URL dari user (website resmi lomba, tautan usulan siswa, berita): validasi `url:https`. Tolak `javascript:`/`data:`, render dengan `rel="nofollow noopener noreferrer ugc"`, dan **tidak pernah** di-fetch server. Detail di §3m-1.
- **Rich text** (caption e-flyer, berita Humas): HTML Purifier (`mews/purifier` atau `stevebauman/purify`), dipurifikasi saat simpan, dan yang dirender hanya kolom bersih. Allowlist: `p, br, strong, em, ul, ol, li, h3, h4, blockquote, a[href|title]` (href https saja). Tanpa `style`, `img` eksternal, `iframe`, `script`, atau atribut `on*`.

**Export CSV/XLSX (formula injection):**
- XLSX via Laravel Excel/PhpSpreadsheet: *default value binder* memperlakukan string berawalan `=` sebagai formula. Pakai `WithCustomValueBinder` dengan `StringValueBinder` (atau `setCellValueExplicit(..., DataType::TYPE_STRING)`) untuk semua kolom teks.
- CSV: prefix `'` untuk sel teks berawalan `= + - @ \t \r \n` (OWASP):
```php
function csv_safe(?string $v): ?string {
    return ($v !== null && $v !== '' && str_contains("=+-@\t\r\n", $v[0])) ? "'".$v : $v;
}
```
- Export via queue, file hasil di private disk, unduh via temporary signed URL (15 menit) + policy + audit log. Kolom NISN/HP **tidak** ikut secara default.

**Import Excel siswa (Admin, D-12).** Kolom: Nama lengkap, NIS, NISN, Tanggal lahir, Kelas saat pendaftaran, No HP (opsional). *(Sejak D-81 kolom kelas bernama "Kelas" = kelas saat berkas di-upload; header lama masih dibaca. Aturan untuk siswa yang sudah terdaftar: lihat "Pembaruan 4 Okt 2026" di akhir bagian ini.)*
- Hanya `.xlsx`, maks. 2 MB, maks. 3.000 baris. Jalankan di queue (`ShouldQueue`, `WithChunkReading`, `WithValidation`) dan **jangan** aktifkan `WithCalculatedFormulas`.
- Header kolom harus sama persis dengan template. Validasi per baris:
  - nama ≤ 150 karakter
  - NIS numerik
  - NISN tepat 10 digit
  - tanggal lahir valid (sel tanggal Excel atau `DD-MM-YYYY`, usia 13–21 tahun)
  - kelas ∈ daftar (X/XI/XII-n)
  - No HP opsional, `^(\+62|0)8\d{7,12}$`

  **Tolak sel berawalan `= + - @`.** NIS dan NISN masing-masing unik, termasuk terhadap data lama, dan NIS tidak boleh sama dengan NISN akun lain (login memakai keduanya).
- Admin memilih tahun ajaran; sistem menghitung angkatan, tahun masuk kelas XII, dan `tanggal_arsip` (D-13). Semuanya tampil di pratinjau.
- Hash password awal dihitung di job (§3a). `birth_date`, `phone`, dan `nisn` di-cast `encrypted` (§3i).
- Mode **dry-run**: tampilkan pratinjau + laporan error sebelum commit. Commit dalam transaksi. Log import berisi pelaku, waktu, jumlah baris, dan SHA-256 file. File sumber dihapus setelah 7 hari.
- PhpSpreadsheet versi terpatch (mis. CVE-2024-45048 XXE diperbaiki di 1.29.1 / 2.2.1; ikuti `composer audit` untuk advisori berikutnya).

**Pembaruan 4 Okt 2026 (D-81): import tahunan memperbarui kelas.** Kalimat "NIS dan NISN masing-masing unik, termasuk terhadap data lama" di atas kini berarti:
- NIS **dan** NISN cocok dengan **satu** siswa terdaftar → baris itu memperbarui siswa tersebut (pindah kelas atau tidak berubah), bukan ditolak.
- Hanya satu nomor yang cocok, atau kedua nomor milik siswa berbeda → **ditolak** dengan alasannya (ini tetap mencegah satu baris menimpa akun orang lain). Nomor ganda di dalam berkas tetap ditolak.
- **Import tidak pernah menyentuh akun.** Yang ditulis untuk siswa terdaftar hanya `classroom_id`, `import_school_year`, `grade12_start_date`, `archive_at`, dan `phone` bila berkas mengisinya. Nama, tanggal lahir, password, email, status, aktivasi, dan flag persetujuan tidak pernah diubah. Karena itu import ulang tidak bisa dipakai untuk mereset password awal atau membuka kembali jendela aktivasi; perbaikan tanggal lahir yang salah tetap lewat "Reset kata sandi & email" (K10).
- Nama atau tanggal lahir yang berbeda dari data lama hanya **dilaporkan** di catatan baris. Akun arsip dilewati; akun yang dihapus (soft delete) ditolak; akun nonaktif diperbarui dan tetap nonaktif.
- Tanggal arsip dihitung ulang dari kelas di berkas + tahun ajaran. Bila hasilnya berbeda, tanggal baru ditulis (juga di atas tanggal yang pernah diubah Admin secara manual: *sementara*, D-81) dan tampil sebagai catatan baris; pratinjau memberi peringatan dengan jumlahnya (banyak = tahun ajaran salah pilih). Tanggal arsip baru yang tidak di masa depan membuat baris ditolak.
- Audit: `student.import` (dengan jumlah `moved`), dan per siswa `student.class_changed` atau `student.phone_updated`, tanpa nama, nomor, tanggal lahir, atau No HP. Apakah `student.class_changed` ikut disalin ke channel `security` belum diputuskan (backlog CLAUDE.md #49).

### 3g. PDF & Surat Tugas

**Alur (keputusan 30 Sep 2026, D-05/D-06):**
1. Kepala Sekolah menyetujui pendaftaran.
2. TU mengisi **dua nomor secara manual**:
   - surat siswa, format template Excel `400.3.8/<no>/MRD/<bulan romawi>/<tahun>`
   - surat guru pendamping, format template Word `400.3.8.6/<no>/<tahun>`
3. TU menekan Generate, dan sistem membuat PDF.

Satu gambar tanda tangan + stempel (diunggah Admin) ditempel di semua surat. Satu tim mendapat **satu** surat siswa bersama.

**Pembaruan 4 Okt 2026 (keputusan lead D-82, D-83, D-84); langkah 2–3 di atas adalah riwayat:**
- **Nomor diketik lengkap.** TU mengetik nomor lengkap tiap surat (mis. `400.3.8/313/MRD/X/2026`); sistem tidak lagi menyusun atau mencocokkan format. Aturan nomor: dirapikan (spasi di ujung dibuang, spasi beruntun jadi satu), 3–64 karakter, hanya `A–Z a–z 0–9 . / -` dan spasi, wajib memuat angka. Karena hanya karakter itu yang diterima, nomor aman dicetak di PDF, halaman verifikasi, dan email.
- **Unik per jenis surat** atas **semua** surat, termasuk yang sudah dicabut: indeks DB `(type, full_number)` menggantikan `(type, year, sequence)`. Perbandingan mengabaikan huruf besar/kecil dan spasi (`LetterNumber::key()`), jadi `400.3.8/313/MRD/X/2026` dan `400.3.8 / 313 / mrd / x / 2026` adalah nomor yang sama. Tahun dan bulan surat diambil dari tanggal surat, bukan dari nomor.
- **Satu surat satu form.** Tiap surat diterbitkan sendiri (`IssueLetters` untuk satu jenis surat, transaksi + kunci pada pendaftaran, satu event audit `letters.issued` per surat). "Kirim" ditolak server sampai semua surat yang wajib punya PDF (surat siswa; surat guru bila ada guru pembimbing).
- **Template = berkas asli sekolah, dengan lambang.** Lambang Pemprov Jawa Tengah dan lambang sekolah disimpan di repo (`resources/pdf/assets`, di dalam chroot dompdf `resources/pdf`). Keduanya lambang lembaga, bukan data pribadi dan bukan unggahan pengguna; aturan "tanpa data nyata di repo" tetap berlaku untuk nama, NIS/NISN, tanda tangan, dan foto. Gambar tanda tangan + stempel **tetap** file privat (§3m-5). QR dan kode verifikasi tetap dicetak.
- **Cetak.** `tu.surat.cetak` mengirim PDF yang sama secara inline untuk di-print: hanya route TU, diperiksa `LetterPolicy::view`, `Cache-Control: no-store, private`, CSP respons itu hanya `frame-ancestors 'none'` (PDF tidak menjalankan skrip halaman), dan tercatat `letter.downloaded` dengan `inline: true`. `tu.surat.kop` hanya menyajikan dua lambang itu untuk preview HTML (`LetterPolicy::viewQueue`).

- **Library:** `barryvdh/laravel-dompdf` dengan **dompdf ≥ 3.1.6**. Rilis 20 Jul 2026 itu memperbaiki 6 advisori, antara lain *chroot validation bypass* (GHSA-wvh6-f5jh-8gw4) dan baca file lokal lewat SVG `data:` URI (CVE-2026-56722). Browsershot/Chromium **tidak** disarankan (RAM ±300–500 MB per render, butuh `--no-sandbox`, permukaan SSRF lebih luas).
- **Riwayat:** dompdf pernah terkena RCE via `@font-face` saat remote aktif (CVE-2022-28368) dan bypass URI SVG ke `phar://` (CVE-2023-23924). Remote fetch, protokol lokal, dan SVG adalah permukaan serangan nyata.
- **Template:** file Excel/Word sekolah dikonversi **sekali** menjadi Blade di repo (disetujui TU). Aplikasi tidak mem-parse `.xlsx`/`.docx` saat runtime dan tidak memanggil LibreOffice. Data hanya di-render via `{{ }}`. **Dilarang `Blade::render($stringDariDb)`** (SSTI → RCE). Bila template bisa diedit di UI, pakai placeholder `strtr()` dengan nilai yang sudah di-`e()`.
- Data surat diambil dari DB (record yang sudah disetujui), bukan dari request.

`config/dompdf.php`:
```php
'options' => [
    'enable_remote'      => false,                                   // anti SSRF
    'enable_php'         => false,
    'enable_javascript'  => false,                                   // default dompdf = true → wajib dimatikan
    'chroot'             => [resource_path('pdf-assets'),            // dompdf menerima array path
                             storage_path('app/private/ttd'),
                             storage_path('app/pdf-tmp')],          // QR PNG per job, dihapus setelah render
    'allowed_protocols'  => ['file://' => ['rules' => []]],          // data://, http://, https:// dihapus
    'font_dir'           => storage_path('fonts'),
    'font_cache'         => storage_path('fonts'),
    'temp_dir'           => storage_path('app/pdf-tmp'),
],
```
**Nomor surat manual, race-safe** (juga §3m-4). *Riwayat: contoh di bawah adalah rancangan awal (nomor urut + regex per format). Sejak D-82 yang berlaku: tanpa regex format, validasi `LetterNumber::numberError()`, dan satu indeks unik `(type, full_number)`; kolom `sequence` boleh kosong. Prinsipnya tetap: constraint DB yang menjamin, transaksi + `lockForUpdate`, pesan duplikat yang ramah.*
```php
// migration surat_tugas
$t->string('jenis', 8);                    // 'siswa' | 'guru'
$t->unsignedSmallInteger('tahun');
$t->unsignedInteger('nomor_urut');
$t->string('nomor_lengkap', 64);
$t->foreignId('pendaftaran_id')->constrained();
$t->unsignedTinyInteger('versi')->default(1);
$t->unique(['jenis', 'tahun', 'nomor_urut']);
$t->unique(['jenis', 'nomor_lengkap']);
$t->unique(['pendaftaran_id', 'jenis', 'versi']);

// FormRequest
'nomor_siswa' => ['required', 'regex:~^400\.3\.8/(\d{1,4})/MRD/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/(20\d{2})$~'],
'nomor_guru'  => ['required', 'regex:~^400\.3\.8\.6/(\d{1,5})/(20\d{2})$~'],

// Action
DB::transaction(function () use ($pendaftaran, $data, $tu) {
    $p = Pendaftaran::whereKey($pendaftaran->id)->lockForUpdate()->firstOrFail();
    Gate::forUser($tu)->authorize('generateSurat', $p);   // status disetujui Kepsek, belum ada surat aktif
    // buat 2 baris SuratTugas (siswa + guru), lalu dispatch job render PDF
});
// QueryException 23000 (duplikat) → pesan "Nomor sudah dipakai surat lain", bukan error 500
```
- Pengecekan "nomor sudah dipakai" di UI hanya bantuan. **Constraint DB** yang menjamin keunikan, sehingga dua TU yang menyimpan bersamaan tidak bisa lolos keduanya. Tahun di nomor harus sama dengan tahun terbit, dan bulan romawi sama dengan bulan terbit *(riwayat: sejak D-82 isi nomor tidak dicocokkan dengan tanggal; itu tanggung jawab TU)*. Petunjuk "nomor terakhir" (mis. 312/1680) hanya informasi; sejak D-82 petunjuknya berbunyi "Terakhir dipakai: <nomor>" ditambah satu contoh dari format di Pengaturan.
- Nomor & PDF **immutable** setelah terbit. Koreksi = cabut (alasan, maker-checker, audit) + surat versi baru; versi lama ditandai `superseded`/`dicabut`.
- **Integritas & anti-pemalsuan.** Gambar ttd+stempel mudah disalin dari surat cetak mana pun, jadi keaslian ditentukan oleh **QR + kode verifikasi**, bukan oleh gambar.
  - Setiap PDF memuat QR ke `https://prestasi.sman3-smg.sch.id/v/<kode>` + kode yang tertulis. Kodenya acak 10 karakter Crockford Base32, bukan ID atau NIS.
  - Halaman verifikasi (tanpa login, rate limit 30/menit/IP) hanya menampilkan nomor, tanggal, nama lomba, nama siswa disingkat ("Nadia P. A."), status **Valid/Dicabut**, dan SHA-256 PDF. Tanpa NIS/NISN/kelas/HP.
- **Simpan per PDF:** `sha256`, `generated_by`, `generated_at`, `ttd_version_id`. Audit **setiap** generate dan unduh (sejak D-84 juga setiap "Cetak"). Kepala Sekolah punya daftar semua surat yang terbit atas namanya.
- **Surat tim:** satu PDF untuk semua anggota, dan setiap anggota melihat nama + kelas anggota lain. Kolom NIS/NISN hanya dicantumkan bila template resmi mewajibkannya (menunggu konfirmasi sekolah). *Terjawab: template sekolah memuat kolom NO · NAMA MURID · NIS · KELAS (D-43, D-84); NISN tidak dicetak.*
- **Render:** generate di queue (timeout 60 detik, satu job per surat), PDF di private disk, unduh lewat controller ber-policy.
- **Opsi masa depan:** tanda tangan elektronik tersertifikasi (TTE BSrE) bila sekolah/pemda sudah memakainya.

### 3h. Integritas poin & data

- **Poin hanya dihitung di server** oleh `PointCalculator` dari field yang sudah diverifikasi Kesiswaan (`tingkat_id`, `capaian_id`, `penyelenggara_id`). Klien tidak pernah mengirim poin, dan `poin` tidak ada di `$fillable`. Setiap nilai poin menyimpan `bobot_version_id`.
- **Bobot berversi:** tabel `bobot_versions` (berlaku_mulai, dibuat_oleh, disetujui_oleh) + `bobot_items`. Perubahan dilakukan Admin (D-08) dan membuat versi baru (tanpa UPDATE in-place), dengan notifikasi ke Kepala Sekolah. SEBAIKNYA versi baru baru berlaku setelah dikonfirmasi Kepala Sekolah (maker-checker). Rekalkulasi di queue menghasilkan laporan diff (siapa naik/turun berapa).
- **Audit log append-only** (`spatie/laravel-activitylog` atau `owen-it/laravel-auditing`, atau tabel sendiri): `actor_id, role, action, subject_type, subject_id, before (JSON), after (JSON), ip, user_agent, created_at`. Wajib untuk: perubahan tingkat/kategori/status ajang, verifikasi/penolakan, poin, bobot, role, surat, publikasi, reset aktivasi, dan export.
- User DB aplikasi hanya punya `INSERT, SELECT` pada tabel audit:
```sql
GRANT SELECT, INSERT ON sth.audit_logs TO 'sth_app'@'%';   -- tanpa UPDATE/DELETE
```
- `SoftDeletes` untuk pengajuan, laporan, surat, lampiran. Hard delete hanya oleh job retensi (§3i).
- **Maker-checker** (tabel `pending_changes`) untuk: bobot, pemberian role staf, pembatalan prestasi terverifikasi, pencabutan surat, reset 2FA staf.
- **Konkurensi:** optimistic locking (`version` int atau cek `updated_at`) pada verifikasi, agar dua verifikator tidak saling timpa.
- **Anti-farming:** unique `(siswa_id, lomba_id, capaian)`; flag otomatis bila hash SHA-256 file sertifikat yang sama diunggah oleh siswa berbeda atau untuk pendaftaran berbeda.
- **Poin penuh untuk tiap anggota tim (D-08)** memberi insentif menitipkan anggota. Karena itu:
  - roster tim **dikunci** setelah Kepala Sekolah menyetujui; menambah/mengganti anggota berarti persetujuan baru
  - jumlah anggota ≤ `max_anggota` lomba
  - setiap anggota mengonfirmasi sendiri
  - akun arsip tidak bisa ditambahkan
  - laporan hasil tim dikirim ketua, tetapi poin baru diberikan setelah Kesiswaan memverifikasi
- **Pengaturan yang memengaruhi poin/tenggat** (bobot, batas laporan 30 hari) disimpan berversi dan diaudit; perubahan memberi notifikasi ke Kepala Sekolah.
- Foreign key + enum/check constraint di DB, jangan hanya validasi di aplikasi.

### 3i. Privasi — UU PDP No. 27/2022 & data anak

Fakta hukum yang relevan: UU PDP berlaku penuh 17 Okt 2024 (Pasal 74). Data anak = data spesifik (Pasal 4(2)). Pemrosesan data anak diselenggarakan secara khusus dan wajib persetujuan orang tua/wali (Pasal 25). DPIA wajib untuk pemrosesan berisiko tinggi, termasuk data spesifik (Pasal 34). Pemberitahuan kegagalan pelindungan maks. 3×24 jam (Pasal 46). DPO wajib antara lain untuk pelayanan publik (Pasal 53). Sanksi administratif diatur di Pasal 57. Peraturan pelaksana PP No. 33 Tahun 2026 terbit pertengahan 2026 (sumber berita berbeda soal tanggal pasti; cek teks resmi di JDIH), dan per Sept 2026 lembaga PDP belum beroperasi (Komdigi mengawasi sementara). **Belum adanya lembaga tidak menghapus kewajiban.**

| Kontrol | Implementasi |
|---------|--------------|
| Minimisasi | Simpan hanya: nama, NIS, NISN, kelas, email, HP (opsional), tgl lahir (untuk password awal & syarat usia lomba). **Tidak** menyimpan NIK, alamat, atau nama orang tua kecuali ada kebutuhan tertulis |
| Enkripsi kolom | Cast `encrypted` untuk `phone`, `birth_date`, `nisn`; NISN juga disimpan sebagai `nisn_hash` (HMAC) untuk index unik/pencarian. Rotasi kunci via `APP_PREVIOUS_KEYS` |
| Persetujuan ortu | Tabel `persetujuan_ortu(siswa_id, jenis[publikasi_nama, publikasi_foto, publikasi_sertifikat, media_sosial], status, tanggal, bukti_file, dicabut_at)`. **Default tidak setuju (opt-in).** Halaman publik dan UI Humas hanya menampilkan data sesuai jenis persetujuan. Pencabutan memicu tugas *takedown* (web + media sosial) ≤ 3×24 jam |
| Halaman publik & leaderboard | Keputusan: publik tanpa login (D-16). Tampil hanya nama + kelas + prestasi terverifikasi. Siswa tanpa `publikasi_nama` tampil sebagai "Siswa kelas XI" (disamarkan) atau tidak dicantumkan, tetapi tetap dihitung di statistik agregat. Tanpa NIS/NISN/HP/tanggal lahir. Detail di §3m-7 |
| Masking di list | NISN `00******89`, HP `0812****789`, email `d***@gmail.com`. Data lengkap hanya di halaman detail untuk role berwenang, dan aksesnya dicatat |
| Export | Hanya Kesiswaan/Admin/Kepsek; queue + signed URL 15 menit + audit + watermark "Diekspor oleh {nama} {waktu}"; NISN/HP tidak ikut secara default |
| Hak subjek | Siswa bisa melihat datanya dan mengajukan koreksi (form ke Kesiswaan, SLA 3×24 jam kerja); ortu dapat mencabut persetujuan |
| Pemberitahuan privasi | Ditampilkan saat aktivasi. Versi dan waktu penerimaan dicatat per akun |
| Pihak ketiga | SMTP/penyimpanan backup: perjanjian pemrosesan data. Pertimbangkan lokasi server di luar Indonesia (ketentuan transfer data UU PDP) |
| Retensi (sebagian diputuskan) | OTP 24 jam; security log 1 tahun; audit log 5 tahun. Akun siswa **diarsipkan otomatis 18 bulan setelah mulai kelas XII** (read-only, D-13, §3m-8). Usulan (perlu keputusan sekolah): foto & lampiran dihapus 2 tahun setelah arsip kecuali ada persetujuan arsip; file import dihapus 7 hari; rekap prestasi (nama + capaian) disimpan sebagai arsip sekolah. Dijalankan job `schedule` dengan laporan |
| DPIA & DPO | DPIA sederhana sebelum go-live (dokumen ini sebagai lampiran teknis); tunjuk DPO/penanggung jawab PDP di sekolah |

**Pembaruan 6 Okt 2026 (M3, D-106): berkas demo realistis.** Berkas data demo (sertifikat, piagam, laporan penelitian, juknis, brosur, poster, surat tugas) kini dokumen contoh yang tampak seperti aslinya. Batas yang dijaga kode dan tesnya (`tests/Feature/Demo/*`):
- **Hanya orang fiktif.** Nama dicetak hanya bila dikenal `DemoPeople::isFictional()` (daftar `DemoSeeder::STUDENTS` / `STAFF`); selain itu dipakai siswa pengganti dari spec, atau suratnya tidak dibuat. Kepala Sekolah di dokumen demo adalah konstanta fiktif, bukan penanda tangan dari Pengaturan.
- **Tanpa tanda tangan, stempel, atau logo lembaga mana pun.** Penanda tangan sertifikat adalah nama rekaan dengan jabatan panitia ("Ketua Panitia", "Ketua Dewan Juri", dan sejenisnya), tidak pernah jabatan lembaga sungguhan; hiasan digambar sendiri (bentuk saja), stempel bertulisan CONTOH, nomor sertifikat memuat `/CONTOH/`. Dua lambang di `resources/pdf/assets` hanya muncul di surat sekolah sendiri, dengan gambar tanda tangan contoh dari repo, tidak pernah gambar yang di-upload Admin.
- **Tiap halaman** bertulisan "CONTOH · data fiktif untuk uji SMAGA Talent Hub · bukan dokumen resmi" (`DemoPdfRenderer::FOOTER`).
- **Hanya lokal.** `DemoSeeder`, `DemoFiles::realistic()`, `DemoFileRefresher`, dan perintah `sth:demo:refresh-files` menolak berjalan kecuali `APP_ENV=local`; `DemoPdfRenderer::render()` juga menolak di production. Templatnya ada di `database/seeders/demo`, di luar semua view path aplikasi.
- **Lewat pemindai upload yang sama.** Tiap dokumen contoh diuji lewat `StoreUpload::store()` (UploadRules + `PdfScanner`).
- **Tidak ada yang disalin dari internet**; isi laporan penelitian dan daftar pustakanya rekaan dan menyebut dirinya "data contoh".
- Yang belum diperiksa: nama penanda tangan rekaan belum dicari kemiripannya dengan orang sungguhan; sertifikat menyebut nama penyelenggara dari data demo (Puspresnas, universitas), seperti yang sudah tampil di layar (*sementara*, D-106).

### 3j. Infrastruktur & Docker (Ubuntu 18.04, 8 GB RAM)

**Keputusan (30 Sep 2026, D-18):** tidak ada VM baru dan tidak ada upgrade OS. Host tetap Ubuntu 18.04 + Apache 2.4.29, dengan akses sudo.

**Fakta host:**
- Dukungan standar Ubuntu 18.04 berakhir 31 Mei 2023. Sesudahnya patch hanya lewat Ubuntu Pro (ESM) sampai 2028 (Legacy add-on sampai 2033).
- Ubuntu Pro gratis hanya untuk *personal use* (≤ 5 mesin fisik; 50 untuk anggota Ubuntu Community), jadi server sekolah hampir pasti tidak memenuhi syarat. Harga daftar Ubuntu Pro $500/mesin fisik/tahun (dicek 30 Sep 2026); tanyakan harga pendidikan ke Canonical.
- Aktivasi: `sudo pro attach <token>`, lalu `pro status` harus menunjukkan `esm-infra` dan `esm-apps` enabled. Cek status CVE tertentu dengan `pro fix CVE-2024-1086`.
- Kernel: `uname -r` menunjukkan 4.15 GA atau HWE 5.4. Contoh risiko tanpa Pro: **CVE-2024-1086** (use-after-free nf_tables → root; masuk CISA KEV 30 Mei 2024, dipakai kampanye ransomware). Untuk bionic, CVE ini hanya diperbaiki di kernel ESM `4.15.0-223.235` / `5.4.0-174.193~18.04.1`.

**Apache 2.4.29 di 18.04: status patch (dicek 30 Sep 2026):**

| Fakta | Nilai |
|-------|-------|
| Paket publik terakhir (tanpa Pro) | `2.4.29-1ubuntu4.27`, terbit 9 Mar 2023 |
| Build ESM terbaru yang terlihat | `2.4.29-1ubuntu4.27+esm11` (USN-8589-1, 22 Jul 2026: mod_ldap → DoS/RCE, mod_proxy_ftp → XSS, mod_auth_digest timing) |
| Contoh fix yang hanya ada di ESM | CVE-2024-38473 (encoding mod_proxy → bypass autentikasi di backend, `+esm7`); CVE-2024-38474 (mod_rewrite, `+esm4`); CVE-2024-27316 (HTTP/2 CONTINUATION DoS, `+esm2`); USN-8396-1, 8 Jun 2026 (mod_rewrite, mod_proxy_ajp, mod_dav_lock, `+esm9`) |
| TLS 1.3 | Tersedia sejak `2.4.29-1ubuntu4.12` (backport, LP #1845263) |

**Kesimpulan:** tanpa Ubuntu Pro, pintu depan (juga pintu depan situs utama) tertinggal ≥ 11 putaran patch keamanan. Risikonya **tinggi**. Mitigasi, urut prioritas:
1. **Ubuntu Pro** (WAJIB; keputusan anggaran sekolah). Setelah attach, jalankan `sudo apt-get update && sudo apt-get install --only-upgrade apache2 openssl libssl1.1 linux-generic`, lalu reboot. `dpkg -l apache2` harus menunjukkan versi `+esm`. Langkah ini juga menutup celah kernel dan OpenSSL.
2. **Modul minimal.** Jalankan `apache2ctl -M`, lalu nonaktifkan modul yang tidak dipakai situs utama maupun STH, terutama yang muncul di USN 2026: `proxy_ajp proxy_ftp dav dav_fs dav_lock ldap authnz_ldap auth_digest lua userdir info autoindex cgi cgid http2`. Konfirmasi dulu ke pengelola situs utama. `status` hanya boleh aktif bila dibatasi ke localhost.
3. **Thin proxy.** Vhost STH hanya berisi `ProxyPass`, tanpa `mod_rewrite`, PHP, CGI, atau `.htaccess` (§3e).
4. **ModSecurity (opsional).** Paket bionic `libapache2-mod-security2` 2.9.2 (2017, komponen universe → patch hanya via esm-apps). Trustwave mengakhiri dukungan komersialnya 1 Jul 2024, dan proyeknya kini dikelola OWASP. Bila dipakai:
   - jalankan `SecRuleEngine DetectionOnly` selama 2 minggu dulu
   - enforce hanya di vhost STH
   - waspadai false positive pada upload/form

   ModSecurity **bukan** pengganti patch.
5. **Alternatif: TLS proxy di kontainer** (Caddy/nginx terbaru bind ke 443, Apache dipindah ke `127.0.0.1:8080` untuk situs utama).
   - Hanya satu proses bisa memegang 443 pada satu IP, jadi opsi ini ikut **memindahkan situs utama**. Hanya dijalankan bila pengelola situs utama setuju dan sudah diuji.
   - Keuntungannya: parser HTTP/TLS terbaru dengan update lewat image.
   - Bila tidak disetujui: tetap Apache + Ubuntu Pro + modul minimal.

**Docker: ganti apt 24.0.2 dengan static binaries (opsi B, dipakai).** Repo apt bionic hanya sampai docker-ce **24.0.2**, containerd.io **1.6.21**, compose **2.18.1**:
- runc < 1.1.12 rentan **CVE-2024-21626** (fd leak → akses filesystem host). Perbaikannya ada di Docker 24.0.9, tetapi versi itu **tidak ada di repo bionic**.
- runc < 1.2.8/1.3.3 rentan CVE-2025-31133, CVE-2025-52565, CVE-2025-52881 (container escape).

Fakta versi terkini (dicek 30 Sep 2026):
- **Docker Engine 29.8.1** (15 Sep 2026). Static binaries-nya membawa containerd **2.3.5** (29.8.1) dan runc **1.5.1** (29.8.0).
- Docker 28 tidak didukung lagi sejak 13 Mei 2026; hanya jalur 29.x yang aktif.
- containerd 2.3 berstatus LTS sampai 30 Apr 2028.
- Sejak runc 1.5.0 (19 Jun 2026), runc 1.2.z tidak didukung dan 1.3.z hanya menerima fix CVE berat. Contoh terbaru: CVE-2026-41579 (image dengan symlink `/dev` → tulis terbatas ke host; fix 1.3.6/1.4.3/1.5.0). runc 1.5.2 (25 Sep 2026) sudah rilis tetapi belum masuk Docker static; pantau.

**Kompatibilitas dengan kernel 4.15:**
- Syarat resmi static binaries: kernel ≥ 3.10, iptables ≥ 1.4, git ≥ 1.7, `ps`, XZ Utils ≥ 4.9, dan hierarki cgroupfs ter-mount. Kernel 4.15 dan iptables 1.6.1 bionic memenuhi batas **tertulis** itu, tetapi tidak ada pernyataan pengujian resmi untuk 4.15. Karena itu **uji staging WAJIB**, di VM/clone dengan kernel yang sama.
- Dokumentasi Docker sendiri menyebut binary "tidak disarankan untuk production karena tidak mendapat update keamanan otomatis". Kelemahan ini dikompensasi rutinitas update di bawah. Hasilnya tetap jauh lebih aman daripada 24.0.2, yang tidak akan pernah dipatch lagi.

**cgroup v1** (bawaan 18.04, systemd 237):
- Docker 29, containerd (sejak 2.2), dan runc men-*deprecate* cgroup v1, tetapi berjanji mendukungnya minimal sampai **Mei 2029**. Rilis terbaru saat itu belum tentu masih mendukung.
- Artinya host ini punya **tenggat keras**: migrasi OS harus dilakukan sebelum ESM 18.04 habis (2028), atau paling lambat sebelum cgroup v1 dihapus.
- Implikasi lain:
  - `mem_limit`/`pids_limit`/CPU tetap jalan.
  - Batas swap butuh `swapaccount=1` di cmdline kernel (bila `docker info` menampilkan "No swap limit support").
  - Jangan mencoba beralih ke cgroup v2 di 18.04.

**Pemasangan** (uji di staging dulu; backup dump DB + `/var/lib/docker` sebelum mulai):
```bash
V=29.8.1
sudo systemctl stop docker docker.socket containerd
sudo apt-get remove -y docker-ce docker-ce-cli containerd.io docker-compose-plugin docker-buildx-plugin  # data /var/lib/docker tetap
sudo rm -f /etc/apt/sources.list.d/docker*.list          # cegah versi apt terpasang lagi
curl -fsSLO https://download.docker.com/linux/static/stable/x86_64/docker-$V.tgz
sha256sum docker-$V.tgz | sudo tee -a /opt/docker/CHANGELOG   # direktori static tidak menyediakan checksum: catat sendiri
sudo mkdir -p /opt/docker/$V && sudo tar -xzf docker-$V.tgz -C /opt/docker/$V --strip-components=1
sudo ln -sfn /opt/docker/$V /opt/docker/current
for b in /opt/docker/current/*; do sudo ln -sfn "$b" "/usr/local/bin/$(basename "$b")"; done
# systemd: docker.service + docker.socket dari moby/contrib/init/systemd (ubah ExecStart ke /usr/local/bin/dockerd);
#          containerd.service dari repo containerd (ExecStart=/usr/local/bin/containerd)
sudo groupadd -f docker
sudo systemctl daemon-reload && sudo systemctl enable --now containerd docker
docker version && runc --version && containerd --version && docker info | grep -Ei 'cgroup|storage driver'
# compose plugin v5.5.1 (3 Sep 2026); cocokkan dengan checksum di halaman rilis GitHub
sudo mkdir -p /usr/local/lib/docker/cli-plugins
sudo curl -fsSL -o /usr/local/lib/docker/cli-plugins/docker-compose \
  https://github.com/docker/compose/releases/download/v5.5.1/docker-compose-linux-x86_64
sudo chmod 755 /usr/local/lib/docker/cli-plugins/docker-compose
```
`/etc/docker/daemon.json`:
```json
{ "no-new-privileges": true, "live-restore": true,
  "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "5" } }
```
Smoke test image: `docker run --rm php:8.4-fpm php -v`, `docker run --rm mysql:8.4 mysqld --version`, lalu test suite di kontainer.

**Rutinitas update Docker (WAJIB):**
- **Langganan notifikasi:** rilis `moby/moby`, release notes Engine 29, advisori `opencontainers/runc` dan `containerd/containerd` (GitHub Watch → Security alerts), serta USN Ubuntu.
- **Jadwal:** bulanan (minggu ke-2, di luar jam sekolah), atau **≤ 48 jam** bila ada CVE HIGH/CRITICAL di runc/containerd/dockerd.
- **Langkah:**
  1. Uji di staging.
  2. Backup DB.
  3. `docker compose stop`, lalu stop `docker` dan `containerd`.
  4. Pasang versi baru di `/opt/docker/<ver>` dan ganti symlink `current`.
  5. Start service, lalu `docker compose up -d`.
  6. Smoke test: `/up`, login, generate PDF.
  7. Catat versi di `/opt/docker/CHANGELOG`.
- **Rollback:** arahkan `current` ke versi sebelumnya, lalu restart. Simpan 2 versi terakhir.
- **Alert otomatis:** script bulanan membandingkan `docker version --format '{{.Server.Version}}'` dengan rilis terbaru, dan mengirim email bila tertinggal > 30 hari.

**Opsi yang tidak dipakai:**

| Opsi | Isi | Status |
|------|-----|--------|
| A | VM/OS baru (Ubuntu 24.04 LTS) | **Tidak tersedia** (D-18). Tetap menjadi tujuan sebelum 2028 / Mei 2029 |
| C | Tetap docker-ce 24.0.2 dari apt | **Ditolak**: runc lama rentan container escape dan tidak akan dipatch |

**Hardening compose (inti):**
```yaml
x-hardening: &hardening
  security_opt: ["no-new-privileges:true"]
  cap_drop: ["ALL"]
  restart: unless-stopped
  logging: { driver: json-file, options: { max-size: "10m", max-file: "5" } }

services:
  web:
    <<: *hardening
    image: nginxinc/nginx-unprivileged:1.28-alpine@sha256:<digest>
    ports: ["127.0.0.1:8080:8080"]           # hanya Apache host; JANGAN 0.0.0.0
    read_only: true
    tmpfs: ["/tmp", "/var/cache/nginx"]
    networks: [edge, backend]                # edge = network non-internal untuk port publish
    mem_limit: 128m
  app:
    <<: *hardening
    image: sth-app@sha256:<digest>           # php-fpm, USER www-data
    read_only: true
    tmpfs: ["/tmp:size=64m", "/var/www/html/bootstrap/cache"]
    volumes: ["sth_storage:/var/www/html/storage"]
    env_file: .env                           # chmod 600
    networks: [backend, egress]              # egress: SMTP & HIBP
    mem_limit: 1536m
    pids_limit: 256
  queue:
    <<: *hardening
    image: sth-app@sha256:<digest>
    command: php artisan queue:work --queue=otp,mail,default --tries=3 --max-time=3600 --memory=256
    networks: [backend, egress]
    mem_limit: 512m
  scheduler:
    <<: *hardening
    image: sth-app@sha256:<digest>
    command: php artisan schedule:work
    networks: [backend, egress]
    mem_limit: 256m
  db:
    <<: *hardening
    cap_add: ["CHOWN", "SETUID", "SETGID", "DAC_OVERRIDE"]  # dibutuhkan entrypoint mysql; uji & kurangi
    image: mysql:8.4@sha256:<digest>
    command: ["--innodb-buffer-pool-size=1G", "--max-connections=60", "--skip-name-resolve", "--local-infile=0"]
    environment: { MYSQL_ROOT_PASSWORD_FILE: /run/secrets/db_root, MYSQL_DATABASE: sth }
    secrets: [db_root]
    volumes: ["sth_mysql:/var/lib/mysql"]
    networks: [backend]                      # TANPA ports: 3306 tidak pernah dipublish
    mem_limit: 2g
networks:
  edge:                                      # gateway 172.30.10.1 = satu-satunya IP di TrustProxies (§3e)
    ipam: { config: [{ subnet: 172.30.10.0/24, gateway: 172.30.10.1 }] }
  backend: { internal: true }                # tanpa akses internet
  egress: {}
secrets: { db_root: { file: ./secrets/db_root.txt } }   # chmod 600
volumes: { sth_storage: {}, sth_mysql: {} }
```
- **Dilarang** mount `/var/run/docker.sock` ke kontainer mana pun, dilarang `privileged: true`, dilarang `network_mode: host`.
- User DB aplikasi `sth_app`: `SELECT, INSERT, UPDATE, DELETE` pada `sth.*` (kecuali tabel audit, lihat §3h); user migrasi terpisah; tanpa `FILE`/`GRANT`/`SUPER`.
- `disable_functions=exec,passthru,shell_exec,system,proc_open,popen` **hanya di pool FPM** (`php_admin_value[disable_functions]`), bukan di CLI, karena `schedule:work` butuh `proc_open`.
- PHP prod: `expose_php=Off`, `display_errors=Off`, `log_errors=On`, `allow_url_include=Off`, `opcache.validate_timestamps=0`, `memory_limit=256M`, `max_execution_time=30`.

**Anggaran RAM 8 GB** (server dipakai bersama, jadi ukur dulu `free -m` sebelum STH dipasang):

| Komponen | Batas | Catatan |
|----------|-------|---------|
| OS + web server lama + aplikasi lain | ~2–2,5 GB | Ukur nyata |
| MySQL 8.4 | 2 GB (`innodb_buffer_pool_size=1G`) | Naikkan ke 1,5G bila RAM tersisa |
| php-fpm | 1,5 GB (`pm=dynamic`, `pm.max_children=12`, `pm.max_requests=500`) | ±80–100 MB/proses |
| Queue worker (1–2) | 2 × 256 MB | `--memory=256` |
| Scheduler + nginx | ~300 MB | |
| Redis (opsional; cache/queue) | 128 MB (`maxmemory`, `requirepass`, network internal) | Tanpa Redis: `database` driver cukup untuk skala sekolah |
| ClamAV (opsional) | 1,5–3 GB | Kemungkinan besar **tidak muat**; lihat §3d |
| Cadangan | ≥ 1 GB | Hindari OOM-killer mengenai MySQL |

**Firewall:** `ufw default deny incoming`; allow 80/443; SSH 22 hanya dari IP admin/jaringan sekolah. **Docker mem-bypass ufw**: port yang dipublish diproses di tabel `nat` sebelum chain INPUT milik ufw. Solusi utama adalah bind ke `127.0.0.1` (lihat compose). Lapis kedua di chain `DOCKER-USER` (`/etc/ufw/after.rules`; sesuaikan nama interface; uji di staging):
```
*filter
:DOCKER-USER - [0:0]
-A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
-A DOCKER-USER -i eth0 -j DROP
COMMIT
```
Verifikasi dari luar: `nmap -Pn -p 1-65535 <ip-server>` hanya menampilkan 22/80/443.

**Secrets & konfigurasi Laravel:**
- `.env` milik user deploy, `chmod 600`, tidak ada di git (`.gitignore`) maupun di image (`.dockerignore`). `APP_KEY` dibuat di server, unik per environment.
- `APP_KEY` bocor = cookie/data terenkripsi bisa didekripsi dan berpotensi RCE via deserialisasi (riset GitGuardian × Synacktiv 2025: 600+ aplikasi Laravel rentan dari key yang bocor di GitHub). Jika bocor: rotasi dengan `APP_PREVIOUS_KEYS`, re-enkripsi kolom `encrypted`, dan hapus semua sesi.
- Production: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info`, `LOG_CHANNEL=daily` + `LOG_DAILY_DAYS=30`. Entrypoint menjalankan `php artisan optimize`. Image dibangun dengan `composer install --no-dev --classmap-authoritative`. Telescope/Debugbar/Ignition tidak ada di prod.
- nginx kontainer: hanya `public/index.php` yang dieksekusi; blok `/\.(?!well-known)`, `*.env`, `*.sql`, `*.log`, `composer.*`.

**Backup (3-2-1):**
```bash
# /opt/sth/backup.sh via cron 01:30; restic = terenkripsi + dedup
docker compose exec -T db sh -c 'mysqldump -uroot -p"$(cat /run/secrets/db_root)" \
  --single-transaction --routines --triggers sth' | gzip > /opt/sth/dump/sth-$(date +%F).sql.gz
restic -r "$RESTIC_REPO" backup /opt/sth/dump /var/lib/docker/volumes/sth_sth_storage/_data
restic -r "$RESTIC_REPO" forget --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune
```
- Target di **luar server** (NAS lain, drive sekolah via rclone, atau object storage). Password restic disimpan offline (cetak, simpan di brankas Kepsek/TU).
- **Uji restore bulanan** ke lingkungan terpisah, bukti dicatat. Alert bila backup gagal. Hapus dump lokal setelah terkirim.

**Host lain:**
- SSH: `PasswordAuthentication no`, `PermitRootLogin no`, `AllowUsers <admin> <deploy>`, key ed25519. fail2ban jail `sshd`.
- `unattended-upgrades` (termasuk ESM bila aktif). Sinkronisasi waktu (`timedatectl`/chrony), karena TTL OTP & log bergantung pada jam.
- Hanya admin tepercaya di grup `docker` (setara root).
- TLS di Apache host: TLS 1.2/1.3, certbot snap dengan renew otomatis, pemantauan kedaluwarsa, redirect HTTP→HTTPS (§3e).
- File STH milik user `sth`, bukan `www-data`; lihat tabel isolasi di §3e.

### 3k. Supply chain & CI

- **Versi (diputuskan 30 Sep 2026, D-17):**
  - Laravel 13 (security fix s/d 17 Mar 2028; rencanakan upgrade ke Laravel 14 sebelum itu)
  - PHP 8.4 (s/d 31 Des 2028)
  - MySQL 8.4 LTS (premier s/d Apr 2029). MySQL 8.0 sudah EOL April 2026 dan **tidak boleh** dipakai.
  - Node LTS aktif, **hanya di stage build**
  - Host: Docker Engine 29.x static (§3j)
- **Multi-stage Dockerfile**, build asset di kontainer Node, bukan di host 18.04 (glibc 2.27 tidak cocok dengan Node ≥ 18):
```dockerfile
FROM node:lts-alpine@sha256:<digest> AS assets
WORKDIR /src
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

FROM composer:2@sha256:<digest> AS vendor
WORKDIR /src
COPY composer.* ./
RUN composer install --no-dev --no-scripts --prefer-dist --classmap-authoritative

FROM php:8.4-fpm-alpine@sha256:<digest>
RUN apk add --no-cache libpng libjpeg-turbo libwebp icu-libs libzip \
 && docker-php-ext-install pdo_mysql opcache intl bcmath   # + gd/zip/exif via deps build
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /src/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /src/public/build ./public/build
USER www-data
```
- `.dockerignore`: `.env*`, `.git`, `node_modules`, `vendor`, `storage/logs/*`, `storage/app/*`, `tests`, `*.sql*`, `backups/`, `secrets/`, `docker-compose.override.yml`.
- CI (GitHub Actions atau lain), gagal bila ada temuan:
  - `composer audit --locked`
  - `npm audit --omit=dev --audit-level=high`
  - `vendor/bin/phpstan` (Larastan, level ≥ 6)
  - `vendor/bin/pest` (termasuk matriks otorisasi)
  - `gitleaks detect`
  - `trivy image --severity HIGH,CRITICAL --exit-code 1 sth-app:ci`
  - grep `{!!` dan `$request->all()`
  - boot guard bypass OTP (§3b): `APP_ENV=production STH_OTP_TEST_MODE=true php artisan about` harus gagal
  - grep `Http::get(`/`file_get_contents(`/`curl_` di kode yang menyentuh URL usulan/katalog (harus nol, §3m-1)
- **Dependabot/Renovate** untuk `composer`, `npm`, `docker`, `github-actions`; security update di-merge ≤ 7 hari (CRITICAL ≤ 48 jam).
- Pin **digest** image (`@sha256:`), diperbarui oleh Dependabot. Tidak memakai image pihak ketiga yang tidak resmi.
- Pantau advisori paket berisiko tinggi di stack ini: `livewire/livewire` (≥ 3.6.4), `dompdf/dompdf` (≥ 3.1.6), `barryvdh/laravel-dompdf`, library QR code, `phpoffice/phpspreadsheet`, `maatwebsite/excel`, `intervention/image`, `laravel/framework`, `laravel/fortify`. Di sisi host: Docker Engine/runc/containerd (§3j) dan USN apache2/openssl/linux (ESM).
- `composer.lock` dan `package-lock.json` di-commit. `npm ci --ignore-scripts` bila paket tidak butuh postinstall.

### 3l. Monitoring & incident response

**Yang dicatat** (channel `security`, daily 90 hari + tabel `security_events` untuk dashboard). **Jangan** mencatat password, OTP, token, session ID, atau isi file:

| Kategori | Event |
|----------|-------|
| Auth | Login sukses/gagal (NIS/NISN, IP, UA; sejak D-78 identifier yang diketik bisa berupa email siswa, seperti email staf sebelumnya); jeda & pembekuan aktivasi; aktivasi (`account.email_bound`, `account.activated` dengan `password_changed`, `activation.password_kept`, D-79). `initial_password_on_active` (K7). Bind/ganti email dan klik "Bukan saya?". Request/sukses reset; "Reset kata sandi & email" oleh Admin. OTP gagal ≥ 3. `otp_bypass_used` (harus nol di production). 2FA: `two_factor.enabled`, `two_factor.confirmed`, `two_factor.disabled`, `two_factor.recovery_codes_regenerated` (log `auth`, maks. 10 baris per akun per jenis per menit); reset oleh Admin lewat `account.staff_reset` / `account.reset` (`two_factor_removed`); `security.password_confirm_locked` (D-67); login break-glass; logout paksa |
| Admin | Import siswa, jendela aktivasi ulang, perubahan peran, **penggantian akun Kepala Sekolah**, unggah/ganti file ttd, perubahan bobot/batas laporan, unarchive/ubah tanggal arsip, maker-checker (usul/setuju/tolak) |
| Data | Persetujuan/penolakan Kepsek, verifikasi/penolakan, perubahan tingkat/kategori/status, poin, generate/unduh/cabut surat, nomor surat duplikat yang ditolak, usulan lomba, publikasi Humas, export massal, download file oleh non-pemilik, hasil job arsip (jumlah akun) |
| Anomali | Lonjakan 403/404/419/429, error 5xx, upload ditolak (tipe/ukuran), payload formula terdeteksi, scraping halaman publik atau pencarian anggota tim |

**Alert** (email/Telegram admin; cukup command `schedule` tiap 5 menit):
- > 20 login gagal untuk satu akun/jam; ≥ 20 identifier berbeda/jam dari satu IP di luar jaringan sekolah
- > 15 aktivasi/jam di luar jam sekolah; ≥ 5 pembekuan aktivasi/jam; setiap `initial_password_on_active` (masuk dashboard Kesiswaan)
- login break-glass; penggantian akun Kepala Sekolah; ganti file ttd; > 20 surat/hari; > 10 reset akun/jam oleh satu Admin
- role staf baru, perubahan bobot, export > 500 baris, `otp_bypass_used` di environment non-local
- disk ≥ 80%, backup gagal, kontainer restart berulang, `/up` gagal (uptime check dari luar)
- sisa masa sertifikat TLS < 14 hari; versi Docker Engine tertinggal > 30 hari; `pro status` menunjukkan ESM tidak aktif; USN baru untuk apache2/linux/openssl

**Checklist insiden (ringkas):**
1. **Identifikasi:** catat waktu, gejala, pelapor; tetapkan PIC (admin server) dan penanggung jawab PDP.
2. **Batasi (contain):** `php artisan down --secret=<acak>` bila perlu; nonaktifkan akun terdampak; hapus sesi (tabel `sessions`); reset password/2FA; blok IP di Apache; cabut kredensial SMTP/DB yang bocor. Bila file ttd diduga bocor: nonaktifkan versi itu, cabut surat yang terbit sejak waktu dugaan, dan beri tahu Kepala Sekolah. Bila situs utama terkompromi: anggap host terkompromi (rotasi `APP_KEY`, password DB, secret) dan periksa integritas image/volume STH.
3. **Amankan bukti:** salin `storage/logs`, `docker compose logs`, log proxy, snapshot DB **sebelum** perbaikan; catat hash file.
4. **Bersihkan:** patch/upgrade; bangun ulang image dari source bersih; rotasi `APP_KEY` (via `APP_PREVIOUS_KEYS`) bila ada indikasi bocor.
5. **Pulihkan:** restore dari backup terverifikasi bila integritas data diragukan; verifikasi poin/surat terhadap audit log.
6. **Notifikasi (UU PDP Pasal 46):** maks. **3×24 jam** secara tertulis ke subjek data (siswa & orang tua) dan otoritas (sementara Komdigi). Isi: data yang terdampak, kapan dan bagaimana terjadi, serta upaya penanganan. Laporkan juga ke Kepsek dan Dinas Pendidikan terkait.
7. **Pasca-insiden:** post-mortem ≤ 2 minggu, perbarui dokumen ini dan checklist.

Kontak (isi sebelum go-live): Admin server: `____`; Penanggung jawab PDP/DPO: `____`; Kepsek: `____`; Pengelola situs utama: `____`; Admin Google Workspace: `____`; CSIRT/instansi pelapor: `____`.

### 3m. Permukaan serangan baru di alur v2 (30 Sep 2026)

**3m-1. URL usulan siswa & website resmi di katalog**
- **Validasi:** `['nullable', 'url:https', 'max:2048']`. Tolak kredensial di URL (`user:pass@`), IP literal, dan port non-standar. Simpan URL apa adanya, lalu tampilkan hostname hasil `parse_url`. IDN ditampilkan dalam bentuk punycode bila berbeda dengan teks (anti-homograph).
- **Tidak pernah di-fetch server:** tanpa preview link, favicon, og:image, "cek URL hidup", atau screenshot. Dengan begitu **tidak ada SSRF** ke `127.0.0.1:8080`, metadata, atau jaringan sekolah. CI mem-*grep* pemanggilan HTTP di kode terkait (§3k).
- **Render:** `<a href="{{ $url }}" rel="nofollow noopener noreferrer ugc" target="_blank">{{ $host }}</a>` + label "Tautan ke situs luar". URL usulan hanya terlihat Kesiswaan/Admin sampai disetujui.
- **Verifikasi manusia:** Kesiswaan memverifikasi penyelenggara sebelum usulan menjadi katalog, karena usulan bisa dipakai untuk phishing berkedok "lomba resmi sekolah".

**3m-2. Brosur/juknis (usulan & katalog).** Aturan §3d berlaku: allowlist pdf/jpg/png/webp ≤ 5 MB, gambar di-re-encode, PDF diperiksa, private disk.
- Brosur usulan tidak pernah publik.
- Setelah menjadi katalog, brosur diunduh lewat controller (`Content-Disposition: attachment` untuk PDF, CSP `sandbox`).
- **Pembaruan 6 Okt 2026 (D-105):** download tetap seperti butir di atas. Di kartu Usulan lomba (Kesiswaan), nama juknis juga membuka popup preview: PDF-nya dikirim inline hanya lewat `files.show` dengan header khusus di §3d (boleh di-frame origin ini saja, tanpa `sandbox`), setelah pemeriksaan policy yang sama. Juknis di layar lain (form "Periksa lomba", detail lomba siswa, Guru › Lomba) masih download saja.
- Batas: 5 usulan/hari per siswa, 30 upload/jam per user.

**3m-3. Pencarian anggota tim**
- **Siapa yang dicari:** hanya siswa aktif yang bisa mencari, dan akun arsip tidak ikut dalam hasil.
- **Respons minimal:** `public_id`, nama, kelas, inisial. **Tidak pernah** NIS, NISN, HP, email, tanggal lahir, atau foto tanpa persetujuan.
- **Aturan cari:** nama minimal 3 huruf, maks. 10 hasil. NIS/NISN **hanya exact match** (bukan prefix), agar nomor berurutan tidak bisa disisir, dan nomor yang dicari tidak ditampilkan balik.
- **Rate limit:** 30/menit dan 300/hari per user. Alert bila > 100 pencarian unik/jam dari satu akun (scraping daftar siswa).
- **Undangan:** hanya penerima yang bisa mengonfirmasi/menolak (policy pada `invitee_id`); ketua tidak bisa mengonfirmasi atas nama anggota. Jumlah undangan ≤ `max_anggota`, undangan kedaluwarsa pada batas pendaftaran, dan notifikasinya tanpa data pribadi tambahan.

**3m-4. Nomor surat manual.** Detail di §3g (dua butir pertama adalah riwayat; sejak D-82: nomor lengkap diketik TU, aturan karakter `LetterNumber::numberError()`, dan satu indeks unik `(type, full_number)` atas semua surat termasuk yang dicabut):
- regex per jenis surat
- unique `(jenis, tahun, nomor_urut)` dan `(jenis, nomor_lengkap)`
- transaksi + `lockForUpdate` pada pendaftaran
- error duplikat yang ramah
- immutable setelah terbit, dengan audit

Uji konkuren wajib (§4).

**3m-5. Gambar tanda tangan + stempel**
- **Upload:** satu file, **hanya Admin** yang boleh mengunggah. PNG ≤ 1 MB, di-re-encode (metadata dibuang), disimpan di `storage/app/private/ttd/<versi>.png`. SEBAIKNYA terenkripsi at rest (`Crypt`), lalu didekripsi ke direktori temp di dalam chroot hanya saat render.
- **Tidak pernah punya URL publik** atau signed URL.
  - Pratinjau untuk Admin/TU/Kepsek lewat controller ber-policy dengan `Cache-Control: no-store` dan watermark "PRATINJAU".
  - TU memakai gambar lewat Generate dan tidak bisa mengunduh file aslinya.
- **Penggantian:** membuat versi baru (versi lama disimpan sebagai bukti), memberi notifikasi ke Kepala Sekolah, dan dicatat di audit. SEBAIKNYA Kepala Sekolah mengonfirmasi versi baru sebelum aktif (maker-checker).
- **Audit setiap generate:** pelaku, pendaftaran, nomor, versi ttd, SHA-256. Kepala Sekolah melihat daftar semua surat atas namanya; alert bila > 20 surat/hari.
- **Hanya pendaftaran berstatus Disetujui Kepala Sekolah** yang bisa di-generate. Dicek di server.
- **Keaslian surat** dibuktikan dengan QR/kode verifikasi (§3g), karena gambarnya mudah disalin. Penyelenggara lomba perlu diberi tahu: "cek QR".

**3m-6. Generate PDF.** Detail di §3g:
- dompdf ≥ 3.1.6
- remote, JS, dan PHP mati; `data://` dihapus
- chroot = aset surat + ttd + temp
- template Blade statis, queue, PDF privat

Uji payload ada di §4.

**3m-7. Halaman publik (tanpa login)**
- **Yang boleh tampil:** hanya nama + kelas + prestasi terverifikasi (lomba, bidang, tingkat, capaian, tahun). **Tidak pernah** NIS, NISN, HP, email, tanggal lahir, atau alamat. **Alumni tanpa kelas (D-80, 4 Okt 2026):** untuk alumni, data publik tidak lagi membawa kelas terakhir (`kelas` kosong, label "Alumni"), di peringkat, beranda, pemenang lomba, dan halaman siswa. Peringkat publik menampilkan satu kelompok per pilihan `?status=aktif|alumni|semua` (bawaan: siswa aktif); pilihan itu tidak mengubah aturan izin nama. Controller publik memakai API Resource khusus dengan allowlist kolom, bukan `toArray()` model, dan tidak ada endpoint JSON publik yang ikut membawa kolom lain.
- **Nama** hanya tampil bila `publikasi_nama` = ya. Selain itu tampil "Siswa kelas XI", atau tidak dicantumkan, tetapi tetap dihitung di agregat. **Foto** hanya bila `publikasi_foto` = ya; selain itu avatar inisial.
- **Sertifikat** sering memuat NISN, tempat/tanggal lahir, dan tanda tangan pejabat.
  - Default: **gambar sertifikat tidak tampil** di publik. Yang tampil badge "Terverifikasi Kesiswaan" + tanggal verifikasi.
  - Bila sekolah ingin menampilkannya: hanya dengan flag `publikasi_sertifikat`, dan hanya versi yang sudah diredaksi Kesiswaan (thumbnail ≤ 800 px + watermark), tidak pernah file asli.
- **URL siswa** memakai `public_slug` acak (bukan NIS atau ID berurutan). Slug diganti saat persetujuan dicabut.
- **Indexing:** halaman per siswa default `X-Robots-Tag: noindex` + `<meta name="robots" content="noindex">`, dengan opsi Admin untuk mengizinkan indeks. Halaman agregat boleh diindeks.
- **Anti-scraping:** rate limit 60 req/menit/IP + cache.
- **Pencabutan persetujuan** → data hilang dari halaman publik ≤ 3×24 jam, dan cache dibersihkan otomatis.
- **Pembaruan 6 Okt 2026 (D-107): "Data untuk flyer" di layar Humas** adalah data yang memang untuk dipublikasikan, jadi mengikuti aturan izin yang sama untuk siapa pun yang melihatnya: panel publikasi hanya terbuka untuk siswa yang punya izin publikasi (selain itu 404); anggota tim tanpa izin publikasi **tidak dicantumkan** dan hanya dihitung dalam satu keterangan; tidak pernah memuat NIS/NISN, tanggal lahir, HP, email, atau poin. Aplikasi tidak menyediakan foto; keterangan di luar blok yang disalin menyebut siapa yang tidak mengizinkan fotonya dipakai. Flyer buatan Humas sendiri dibuat dan disimpan di luar STH, jadi penurunannya setelah izin dicabut sepenuhnya pekerjaan manual Humas (baris "Persetujuan ortu" di §3i). Berkas flyer buatan STH sebelum 6 Okt 2026 tetap tersimpan dan dijaga `FilePolicy` (D-75; backlog `CLAUDE.md` #39).

**3m-8. Job arsip otomatis**
- **Jadwal:** `Schedule::command('sth:arsip-otomatis')->dailyAt('01:00')->timezone('Asia/Jakarta')->withoutOverlapping()->onOneServer()`.
- **Idempoten:** hanya memproses `tanggal_arsip <= today AND archived_at IS NULL`, update per chunk dalam transaksi, dan `archived_at` diisi sekali. Menjalankan ulang tidak mengubah apa pun.
- **Laporan:** hasil setiap run dikirim ke Admin, dan dashboard Admin menampilkan pratinjau "akan diarsipkan dalam 30 hari".
- **Read-only ditegakkan di server:**
  - `Policy::before()` menolak semua ability tulis bila `$user->isArchived()`.
  - Aksi daftar, lapor, usul, dan konfirmasi undangan memeriksa ulang status arsip.
  - Akun arsip dikecualikan dari pencarian anggota tim.

  Menyembunyikan tombol saja tidak cukup. Uji Pest: akun arsip → 403 di semua route tulis.
- **Saat diarsipkan:** sesi aktif dicabut. Apakah akun arsip masih boleh login read-only menunggu keputusan sekolah. Prestasinya tetap ada di histori/publik sesuai persetujuan.
- **Perubahan manual:** `tanggal_arsip` dihitung saat import. Mengubahnya (mis. siswa tinggal kelas) atau *unarchive* hanya bisa dilakukan Admin dengan alasan, tercatat di audit.

**3m-9. Kekuasaan Admin & akun berhak tinggi**
- **2FA TOTP WAJIB** untuk Admin, Kepala Sekolah, TU, dan Kesiswaan (§3a). Recovery codes dicetak dan disimpan oleh pemiliknya. Email OTP tidak boleh menjadi satu-satunya faktor (lagi pula belum aktif).
- **Jumlah akun Admin:** maks. 2 akun bernama (satu orang satu akun) + **1 akun break-glass**:
  - password acak panjang + seed TOTP dicetak, disegel dalam amplop di brankas sekolah
  - hanya dipakai bila Admin utama tidak tersedia
  - setiap login memicu alert ke Kepala Sekolah + semua Admin
  - setelah dipakai, password & seed diganti, amplop disegel ulang, dan pemakaian dicatat di buku log
- **Konfirmasi ulang password** (`password.confirm`, 15 menit) untuk: ubah peran, reset akun, **ganti akun Kepala Sekolah**, unggah ttd, ubah bobot/batas laporan, export, import, unarchive. `POST /user/confirm-password` dibatasi **5 percobaan per menit per akun** (D-67).
- **Email keluar (D-68, D-69):** akun yang dibekukan tidak menerima email apa pun dan tidak bisa meminta kode reset; alamat yang belum diverifikasi hanya menerima pemberitahuan akun (`AdminNotice`). Semua notifikasi dibuat dengan `PlainMailMessage`, sehingga teks dari pengguna (nama lomba, nama orang, catatan) tidak pernah menjadi tautan atau gambar di email. Nama lomba yang memuat alamat web ditolak saat diisi (`NoWebAddress`, D-76), karena klien email menautkan sendiri teks yang mirip alamat.
- **Ganti akun Kepala Sekolah:**
  - notifikasi dikirim ke email Kepsek lama & baru dan ke semua Admin
  - berlaku setelah **tunda 24 jam**, dan bisa dibatalkan Kepsek lama atau Admin lain
  - dicatat di audit
  - persetujuan yang masih tertunda tidak dipindahkan diam-diam
- **SoD:** Admin **tidak** menyetujui pendaftaran atas nama Kepsek dan tidak bisa *impersonate*. Bila sekolah memutuskan Admin boleh menyetujui, aksi itu diberi label "oleh Admin" di surat dan audit.
- **Audit log immutable:**
  - user DB aplikasi hanya punya `SELECT, INSERT` pada `audit_logs` (§3h)
  - setiap baris menyimpan `prev_hash` dan `hash = sha256(prev_hash || isi)` (hash chain), sehingga penghapusan atau pengubahan oleh root DB terdeteksi
  - job harian memverifikasi rantai dan menyimpan hash terakhir ke backup off-site
  - tidak ada menu hapus/edit log di UI
- **Alert:** login Admin dari luar jaringan sekolah, > 10 reset/jam, perubahan peran, penggantian ttd, penggantian akun Kepsek, export > 500 baris.

---

## 4. Checklist pra-rilis

Target verifikasi: **ASVS 5.0 Level 1 untuk semua area, Level 2 untuk V6/V7/V8/V5/V14** (data anak). Tag = OWASP Top 10 2021 (catatan: Top 10 2025 final Jan 2026 menambahkan *A03:2025 Software Supply Chain Failures*; item §3k memenuhinya).

**Pemetaan:**

| OWASP 2021 | Bagian dokumen | ASVS 5.0 |
|------------|----------------|----------|
| A01 Broken Access Control | 3c, 3d, 3e, 3m | V8 Authorization, V5 File Handling |
| A02 Cryptographic Failures | 3a, 3b, 3i, 3j | V11 Cryptography, V12 Secure Communication, V14 Data Protection |
| A03 Injection (termasuk XSS, formula, SSTI) | 3f, 3g | V1 Encoding & Sanitization, V2 Validation & Business Logic |
| A04 Insecure Design | 3a, 3h, 3i | V2, V15 Secure Coding & Architecture |
| A05 Security Misconfiguration | 3e, 3f, 3j | V13 Configuration, V3 Web Frontend Security |
| A06 Vulnerable & Outdated Components | 3j, 3k | V15 |
| A07 Identification & Authentication Failures | 3a, 3b | V6 Authentication, V7 Session Management |
| A08 Software & Data Integrity Failures | 3g, 3h, 3k | V15, V2 |
| A09 Security Logging & Monitoring Failures | 3l | V16 Security Logging & Error Handling |
| A10 SSRF | 3g, 3m-1 | V1, V15 |

**Autentikasi & sesi** (A07 · V6/V7)
- [ ] **Formulir penerimaan risiko login (D-14) ditandatangani Kepala Sekolah.**
- [ ] Login menerima NIS **atau** NISN; keduanya berbagi satu bucket rate limit. Pesan & waktu respons seragam (user ada vs tidak, NIS vs NISN). Input yang cocok dengan dua akun ditolak.
- [ ] **Login email siswa (D-78):** email hanya diterima bila aktivasi sudah selesai; email akun yang belum selesai aktivasi, nonaktif, arsip, atau tidak dikenal mendapat pesan dan waktu respons yang sama dengan akun yang tidak ada; email yang dikenali berbagi bucket dengan NIS/NISN akun itu; login email tidak pernah melewati atau memicu TOTP untuk siswa (D-66).
- [ ] Password awal hanya membuka sesi aktivasi (route lain ditolak sampai password diganti) dan kedaluwarsa sesuai jendela (default 14 hari). 10 kali gagal → akun belum aktif dibekukan (feature test). *Sejak D-79:* route lain ditolak sampai **aktivasi selesai** (email terverifikasi + langkah kata sandi: ganti, atau lewati untuk siswa); staf tidak bisa melewati; `keepPassword` untuk akun staf → 403.
- [ ] Setelah aktivasi, `initial_password_hash` null. Tidak ada kode autentikasi yang membangun pola dari `birth_date` (grep `sm4g4`). *Sejak D-79 butir pertama tidak berlaku untuk siswa yang memilih tetap memakai password awal; butir kedua tetap wajib.*
- [ ] **Penyeimbang D-79:** tanda "masih pakai kata sandi dari sekolah" di Status aktivasi + pengingat ganti password tersedia (belum dibangun, backlog CLAUDE.md #45), **atau** risikonya tertulis di formulir penerimaan risiko yang ditandatangani.
- [ ] **Reset Admin memaksa password baru (D-92):** akun siswa yang direset tidak punya tombol "Lewati" di langkah kata sandi, dan `keepPassword` untuk akun itu → 403; siswa dengan password awal dari import tetap boleh melewati.
- [ ] Password baru yang sama dengan/mengandung tanggal lahir (semua format), `sm4g4`/`smaga`, NIS, NISN, atau nama ditolak. *Sejak D-94:* juga username, bagian email sebelum "@", dan password umum (`password123`, `Qwerty!2026`), untuk siswa dan staf.
- [ ] **"Buka ulang" (D-95)** tidak pernah menghasilkan batas yang lebih dekat dari batas sebelumnya; alasan di bawah 10 karakter ditolak.
- [ ] K7: login gagal memakai pola awal pada akun aktif memunculkan event di dashboard Admin/Kesiswaan.
- [ ] Fitur-fitur berikut berfungsi dan tercatat di audit:
  - laporan status aktivasi per kelas
  - jendela aktivasi ulang 72 jam
  - "Reset kata sandi & email" (password sementara acak, sesi & remember token dicabut, email lama diblokir)
  - tautan "Bukan saya?"
- [ ] Rate limit login/OTP diuji **dari balik proxy**: IP klien asli terbaca, dan satu akun terkunci tanpa mengunci seluruh sekolah.
- [ ] `session()->regenerate()` di login/aktivasi/ganti password/perubahan peran; sesi lain dicabut setelah ganti password. Remember-me siswa ≤ 30 hari, dan tidak ada untuk staf. *Sejak D-93:* di aktivasi, Keamanan akun, dan pemasangan 2FA dipakai `regenerate(true)`; baris sesi lama hilang dari tabel `sessions`, dan percobaan yang ditolak tidak memperbarui sesi (feature test).
- [ ] **Halaman kredensial `no-store` (D-93):** `/login`, `/aktivasi`, `/lupa-kata-sandi`, `/user/confirm-password`, `/two-factor-challenge`, `/akun/2fa` menjawab `Cache-Control: no-store` untuk GET, POST, dan redirect. Uji tangan di browser: masuk → Keluar → Back dua kali tidak memunculkan NIS atau password, dan akun lain yang masuk sesudahnya mendarat di berandanya sendiri (D-102; lulus di Chrome dan browser Chromium bawaan pada 6 Okt 2026; Firefox dan Safari belum dicoba).
- [ ] 2FA TOTP aktif untuk semua akun Admin, Kepala Sekolah, TU, dan Kesiswaan. Amplop break-glass tersegel, dan login uji break-glass memicu alert.
- [ ] **Bypass OTP:** `STH_OTP_TEST_MODE` tidak ada/false di production. `APP_ENV=production STH_OTP_TEST_MODE=true php artisan about` gagal. Test Pest bypass hijau.
- [ ] **SMTP produksi** terkonfigurasi (SPF/DKIM/DMARC) dan OTP nyata diuji end-to-end. `MAIL_MAILER` bukan `log`/`array`. Akun yang mengikat email saat bypass diminta verifikasi ulang.
- [ ] OTP: hashed, TTL 10 menit, 5 percobaan, cooldown 60 detik, sekali pakai, terikat purpose; job `ShouldBeEncrypted`.
- [ ] Lupa password tidak membocorkan keberadaan akun atau email.

**Otorisasi** (A01 · V8)
- [ ] Matriks role × route (Pest) hijau untuk semua route, termasuk download, export, dan JSON.
- [ ] Uji manual IDOR dengan 2 akun siswa + 1 guru non-pembimbing: tidak ada data bocor.
- [ ] Semua model publik memakai ULID + policy; relasi bertingkat memakai `scopeBindings()`.
- [ ] Grep bersih: tidak ada `$guarded = []`, `$request->all()` ke create/update, atau `FormRequest::authorize()` yang hanya `return true` tanpa policy.
- [ ] Field `status/poin/role/verified_by` yang disisipkan ke form diabaikan (test).
- [ ] Kasus alur v2 (§3c) hijau: undangan tim milik orang lain, TU generate sebelum disetujui, akun arsip menulis, unduh file ttd oleh non-Admin, Admin menyetujui atas nama Kepsek, ubah roster setelah disetujui.
- [ ] Pencarian anggota tim hanya mengembalikan `public_id`/nama/kelas; NIS/NISN prefix tidak menghasilkan apa pun; rate limit aktif.

**Input/Output** (A03 · V1/V2/V3)
- [ ] `{!!` hanya ada di allowlist (kolom yang sudah dipurifikasi).
- [ ] CSP enforce (bukan report-only), tanpa pelanggaran di console; header dicek dengan `curl -sI`.
- [ ] Payload `<script>`, `"><img src=x onerror=alert(1)>`, `javascript:` di semua field teks tidak tereksekusi (termasuk e-flyer, PDF, email). *Sejak D-107:* STH tidak membuat e-flyer; uji blok "Data untuk flyer" dan teks hasil "Salin data" sebagai gantinya (nama lomba, penyelenggara, cabang, dan judul penelitian berisi payload tampil dan tersalin sebagai teks biasa).
- [ ] Export XLSX/CSV dengan nilai `=HYPERLINK("http://x","klik")` dan `@SUM(1+1)` tampil sebagai teks.
- [ ] Import Excel: header salah, sel formula, NIS/NISN duplikat, dan NIS = NISN akun lain ditolak dengan laporan. Import berjalan di queue, dry-run tersedia, `tanggal_arsip` terhitung benar di pratinjau. *Sejak D-81:* baris yang NIS **dan** NISN-nya milik satu siswa terdaftar memperbarui kelas siswa itu (bukan ditolak); baris dengan hanya satu nomor cocok, atau dua nomor milik siswa berbeda, ditolak; import ulang tidak mengubah nama, tanggal lahir, password, email, status, aktivasi, atau izin (test).
- [ ] URL usulan/katalog: `javascript:`, `http://`, `https://user:pass@…`, dan IP literal ditolak. Tidak ada request keluar saat usulan disimpan/ditampilkan (cek log egress). Link ber-`rel="nofollow noopener noreferrer ugc"` dan menampilkan host.

**File & PDF** (A01/A03/A10 · V5)
- [ ] Upload `.php`, `.phtml`, `.svg`, `.html`, polyglot `GIF89a<?php`, dan PDF yang diganti ekstensinya ditolak.
- [ ] Foto hasil upload tidak lagi punya EXIF GPS (`exiftool` kosong).
- [ ] File privat tidak bisa diakses via URL langsung; download non-pemilik mendapat 404/403; header `nosniff` + `sandbox` ada. *Sejak D-105:* `sandbox` ada di semua jawaban berkas **kecuali** PDF inline dari `files.show` (butir berikut).
- [ ] **Preview berkas di popup (D-105), dicek dengan `curl -sI` dan di browser:**
  - `GET /berkas/{file}` tanpa `?tampil=1` → `attachment`, `X-Frame-Options: DENY`, CSP `default-src 'none'; sandbox`
  - PDF dengan `?tampil=1` → `inline`, `X-Frame-Options: SAMEORIGIN`, CSP `default-src 'none'; frame-ancestors 'self'`, `nosniff`, `private, no-store`; **di belakang Apache produksi header ini masih `SAMEORIGIN`** (bukan ditimpa `DENY`) dan PDF tampil di popup
  - gambar dengan `?tampil=1` → inline tetapi tetap `DENY` + `sandbox`; SVG/GIF/HTML/xlsx dengan `?tampil=1` → tetap attachment
  - `/berkas/publik/{file}?tampil=1` untuk PDF → tetap attachment
  - berkas milik orang lain → 404 untuk GET dan HEAD, dengan dan tanpa `?tampil=1`; halaman dari origin lain tidak bisa mem-frame PDF itu
  - halaman aplikasi sendiri tetap `X-Frame-Options: DENY` + `frame-ancestors 'none'`; tidak ada pelanggaran CSP di console saat popup dibuka
  - preview tercatat `file.downloaded` dengan `inline: true`; HEAD tidak menulis baris audit (disengaja, *sementara*)
  - dicoba di Chrome/Edge, Firefox, Safari, dan satu HP: PDF tampil, atau dialog berisi satu kalimat + tombol "Download" (lulus di Chromium pada 6 Okt 2026; browser lain belum dicoba)
- [ ] Batas ukuran konsisten di PHP, nginx kontainer, dan proxy luar; kuota per siswa berfungsi.
- [ ] dompdf ≥ 3.1.6 dengan `enable_remote=false`, `enable_javascript=false`, `data://` tidak diizinkan, dan `chroot` = aset surat + ttd + temp. Field siswa berisi payload berikut tidak memicu request atau pembacaan file:
  - `<img src="http://<host-uji>/x">`
  - `<img src="data:image/svg+xml;base64,…">`
  - `<link href="file:///etc/passwd">`
- [ ] Nomor surat manual diuji konkuren (20 request paralel dengan nomor sama): tepat satu sukses, sisanya mendapat pesan duplikat. Format salah ditolak, dan nomor tidak bisa diubah setelah terbit. *Sejak D-82:* "format salah" = nomor kosong, di luar 3–64 karakter, memuat karakter selain huruf/angka/titik/garis miring/tanda hubung/spasi, atau tanpa angka; nomor yang hanya berbeda huruf besar/kecil atau spasi dihitung sama; nomor surat yang sudah dicabut tidak bisa dipakai lagi untuk jenis surat itu.
- [ ] **Cetak surat (D-84):** `tu.surat.cetak` hanya untuk TU dan surat yang boleh ia lihat (peran lain dan tamu → 403/404); respons `no-store`; setiap cetak tercatat. Lambang di `resources/pdf/assets` ikut di image produksi; folder itu hanya berisi aset statis dari repo (dua lambang dan gambar kurung kurawal), tidak pernah unggahan pengguna.
- [ ] File ttd: tidak ada URL publik; akses sebagai Siswa/Humas/tamu → 403/404; TU tidak bisa mengunduh file asli; setiap generate tercatat (pelaku, nomor, versi ttd, SHA-256).
- [ ] QR verifikasi: surat valid tampil Valid, surat dicabut tampil Dicabut, tanpa PII sensitif; kode tidak bisa ditebak berurutan.

**Integritas data** (A04/A08 · V2)
- [ ] Poin tidak bisa dikirim klien; rekalkulasi setelah perubahan bobot menghasilkan versi & diff.
- [ ] Audit log mencatat before/after; user DB aplikasi gagal `UPDATE/DELETE` di `audit_logs`.
- [ ] Maker-checker berlaku untuk bobot, role staf, pencabutan surat, dan pembatalan verifikasi.
- [ ] Audit log hash-chain terverifikasi oleh job harian. Mengubah satu baris secara manual di DB uji terdeteksi.
- [ ] Roster tim terkunci setelah persetujuan Kepsek; poin tim hanya diberikan setelah verifikasi Kesiswaan.
- [ ] Ganti akun Kepala Sekolah: notifikasi terkirim, tunda 24 jam berlaku, dan bisa dibatalkan.

**Privasi** (A04 · V14)
- [ ] DPIA disetujui Kepsek; DPO/penanggung jawab PDP ditunjuk.
- [ ] Pemberitahuan privasi tampil saat aktivasi dan penerimaannya tercatat.
- [ ] Persetujuan orang tua terisi; Humas tidak bisa mempublikasikan siswa tanpa persetujuan (test).
- [ ] **"Data untuk flyer" (D-107):** tidak pernah memuat siswa tanpa izin publikasi (anggota tim tanpa izin tidak dicantumkan), NIS/NISN, atau poin; panel publikasi siswa yang izinnya dicabut → 404 (test `FlyerDataTest`, `ConsentWithdrawnTest`).
- [ ] **Berkas demo (D-106):** tidak ada dokumen contoh di server produksi: `php artisan sth:demo:refresh-files` di luar `APP_ENV=local` menolak ("Perintah ini hanya untuk APP_ENV=local. Tidak ada yang diubah."), dan seeder demo tidak berjalan di production.
- [ ] Halaman publik (ringkasan, drill-down bidang, peringkat):
  - hanya menampilkan nama + kelas + prestasi siswa yang punya `publikasi_nama`
  - tidak ada NIS/NISN/HP/tanggal lahir di HTML/JSON (cek view-source dan respons jaringan)
  - foto hanya dengan `publikasi_foto`
  - sertifikat tampil sebagai badge
  - slug acak, `noindex` pada halaman siswa, rate limit aktif
- [ ] Masking NISN/HP/email di semua list staf.
- [ ] Job arsip idempoten (jalankan 2× → run kedua tidak mengubah apa pun); akun arsip ditolak di semua route tulis (test); sesi dicabut saat arsip.
- [ ] Job retensi terjadwal; export tercatat dan ber-watermark.

**Infrastruktur** (A05/A06 · V13/V12)
- [ ] **Ubuntu Pro ter-attach:**
  - `pro status` menunjukkan `esm-infra` + `esm-apps` enabled
  - `dpkg -l apache2` menunjukkan build `+esm` terbaru
  - kernel ESM terpasang dan host sudah reboot (`pro fix CVE-2024-1086` → fixed)

  **Atau**: risiko tanpa Pro ditandatangani Kepala Sekolah, lengkap dengan tenggat.
- [ ] Docker Engine 29.x terbaru (≥ 29.8.1), `runc --version` ≥ 1.5.1, containerd ≥ 2.3.5. Paket apt docker-ce 24.0.2 terhapus dan repo apt Docker dilepas. Unit systemd aktif. Rutinitas update + rollback terdokumentasi dan sudah diuji sekali di staging.
- [ ] Apache:
  - `apache2ctl -M` hanya berisi modul yang disepakati
  - `ServerTokens Prod`, `ProxyRequests Off`
  - vhost STH tanpa `h2`
  - `certbot renew --dry-run` sukses
  - alert kedaluwarsa sertifikat aktif (email LE sudah tidak ada)
- [ ] Isolasi host: `sudo -u www-data cat /opt/sth/.env` gagal; `www-data` tidak di grup `docker`; tidak ada user DB bersama dengan situs utama.
- [ ] Semua kontainer non-root (`docker compose exec app id`), `no-new-privileges`, tanpa docker.sock, tanpa `privileged`.
- [ ] `nmap` dari luar: hanya 22/80/443; 3306/6379/8080 tertutup; `DOCKER-USER` terpasang.
- [ ] Halaman error production tidak menampilkan stack trace (`APP_DEBUG=false`); `php artisan about` menunjukkan config/route/view cached. *Sejak D-101:* halaman 401/403/404/405/419/429/500/503 adalah halaman aplikasi sendiri tanpa teks exception; ulangi uji QA Q2-03 di lingkungan seperti produksi (`GET /logout` → pengalihan; method yang salah di route lain → 405 kecil dan cepat).
- [ ] `/.env`, `/.git/HEAD`, `/storage/logs/laravel.log`, `/composer.lock` menghasilkan 404.
- [ ] `.env` = 600 dan tidak ada di image (`docker run --rm --entrypoint ls sth-app -la /var/www/html/.env` gagal).
- [ ] Subdomain:
  - cookie `__Host-sth_session; Secure; HttpOnly; SameSite=Lax; Path=/` tanpa `Domain`
  - HSTS tanpa `includeSubDomains`
  - Host asing ditolak (`curl -H 'Host: evil.test' http://127.0.0.1:8080/`)
  - `X-Forwarded-Host` palsu tidak mengubah URL reset/signed URL
  - `PreventRequestForgery` tanpa `allowSameSite`
  - `request()->ip()` = IP klien asli
- [ ] Backup harian terenkripsi di luar server; **restore test berhasil** (tanggal & bukti dicatat).
- [ ] Rotasi log Docker & Laravel aktif; SSH key-only; fail2ban aktif; waktu tersinkron.

**Supply chain** (A06/A08 · V15)
- [ ] `composer audit` & `npm audit --omit=dev` tanpa temuan HIGH/CRITICAL; Trivy image lulus.
- [ ] Digest image di-pin; Dependabot aktif; `gitleaks` bersih; lockfile di-commit.
- [ ] Versi: Laravel 13, PHP 8.4, MySQL 8.4 LTS, dompdf ≥ 3.1.6, Livewire ≥ 3.6.4 bila dipakai. Jadwal upgrade ke Laravel 14 sebelum 17-03-2028 tercatat.

**Monitoring & IR** (A09 · V16)
- [ ] Event §3l tercatat; log tidak memuat password/OTP/token (grep log setelah uji).
- [ ] Alert diuji (login gagal massal, disk, backup gagal) dan diterima admin.
- [ ] Kontak insiden terisi; tim tahu prosedur notifikasi 3×24 jam.
- [ ] OWASP ZAP baseline scan terhadap staging tanpa temuan High.

Tanda tangan go-live: Developer `____` · Reviewer keamanan `____` · Admin server (I/deploy) `____` · Kepsek `____` · Tanggal `____`

---

## 5. Pertanyaan terbuka untuk pihak sekolah

**Sudah terjawab (30 Sep 2026):**
- subdomain `prestasi.sman3-smg.sch.id`
- web server Apache 2.4.29 dengan akses sudo
- tidak ada VM baru / upgrade OS
- Kode Aktivasi ditolak (Admin membantu bila akun dibajak)
- prestasi & peringkat publik tanpa login
- OTP ditunda

Lihat `DECISIONS.md`.

1. **Situs utama `sman3-smg.sch.id`:** benarkah WordPress dan benar di server yang sama?
   - Versi WordPress & PHP berapa, plugin apa saja, dan siapa yang memperbarui?
   - Apakah memakai `mod_php`?
   - Apakah ada backup/WAF?
2. **Ubuntu Pro:** bersediakah sekolah membeli (harga daftar $500/server fisik/tahun), atau meminta harga pendidikan ke Canonical? Tanpa Ubuntu Pro, Apache dan kernel tidak menerima patch. Siapa yang akan memegang token?
3. **Pengelola server:** siapa yang mengelola server, dan siapa yang punya akses root/SSH/sudo? Apakah ada akun bersama? Bolehkah kami mengubah konfigurasi global Apache (modul, `ServerTokens`), mengingat dampaknya juga ke situs utama?
4. **DNS:** siapa pengelola zona `sman3-smg.sch.id` (untuk record A `prestasi` dan CAA)? Apakah IP publik server tetap?
5. **Persetujuan orang tua** untuk nama, foto, dan sertifikat di halaman publik: bagaimana prosesnya saat ini (formulir daftar ulang?), dan bagaimana mengumpulkannya untuk siswa yang sudah terdaftar? Bolehkah Humas memakai foto di media sosial?
6. **Tanda tangan + stempel:** apakah Kepala Sekolah menyetujui pemakaian gambar tanda tangannya di surat hasil sistem? Siapa yang boleh mengganti file itu? Apakah perlu TTE (BSrE)?
7. ~~**Template surat:** kolom apa saja yang ada di surat siswa (NIS/NISN? kelas?)? Apa arti "MRD"? Apakah nomor urut direset per tahun?~~ **Diputuskan lead 4 Okt 2026:** template = berkas asli sekolah (D-84; surat siswa memuat NIS dan kelas), dan TU mengetik nomor lengkap (D-82), jadi arti "MRD" dan aturan reset tidak lagi dibutuhkan aplikasi.
8. **Pemulihan akun dibajak:**
   - Siapa Admin yang bertugas?
   - Bagaimana verifikasi identitas tatap muka dilakukan?
   - Berapa target waktu pemulihan?
   - Bersediakah Kepala Sekolah menandatangani formulir penerimaan risiko login? (Sejak D-79 formulir itu juga memuat: siswa boleh tetap memakai password awal setelah aktivasi.)
9. **Jadwal aktivasi:** bisakah aktivasi dilakukan serentak per kelas (jam TIK/wali kelas) agar jendelanya pendek? Berapa jumlah siswa? Di dokumen mana saja NIS/NISN tercetak?
10. **2FA staf:** bisakah Admin, Kepala Sekolah, TU, dan Kesiswaan memakai aplikasi authenticator di HP? Apakah staf memakai PC bersama? Di brankas mana amplop break-glass disimpan?
11. **Email noreply (nanti):** apakah domain `.sch.id` memakai Google Workspace for Education, dan siapa admin-nya?
12. **Backup:** lokasi di luar server mana yang tersedia (NAS lain, Google Drive sekolah, cloud)? Siapa yang menyimpan kunci enkripsinya?
13. **PDP:** siapa DPO/penanggung jawab PDP sekolah, dan apakah ada kebijakan retensi arsip prestasi dari Dinas?
14. **Akun arsip:** apakah masih boleh login (read-only) untuk melihat/mengunduh portofolio?
15. **Pelaporan insiden:** kepada siapa insiden dilaporkan secara hierarkis (Kepsek, Dinas Pendidikan Provinsi, CSIRT daerah), dan siapa juru bicara bila ada kebocoran?
16. **Anggaran:** apakah ada dana untuk penyedia email transaksional, Ubuntu Pro, atau penetration test eksternal sebelum go-live?

---

## Referensi

- Laravel 12 Support Policy (bug fix s/d 13 Agu 2026, security s/d 24 Feb 2027): https://laravel.com/docs/12.x/releases
- Laravel 13 Support Policy (rilis 17 Mar 2026, PHP 8.3–8.5, security s/d 17 Mar 2028): https://laravel.com/docs/13.x/releases · https://laravel-news.com/laravel-13-released
- Laravel 13 CSRF `PreventRequestForgery` (`Sec-Fetch-Site`, `allowSameSite`): https://laravel.com/docs/13.x/csrf
- Laravel 13 Trusted Proxies & Trusted Hosts (regex, `subdomains: false`): https://laravel.com/docs/13.x/requests
- Laravel 13 Hashing (argon2id, `HASH_VERIFY`): https://laravel.com/docs/13.x/hashing
- Image PHP resmi dengan `--with-password-argon2`: https://github.com/docker-library/php/blob/master/8.4/bookworm/fpm/Dockerfile
- Symfony `Request::getHost()` memakai elemen pertama `X-Forwarded-Host`: https://github.com/symfony/symfony/blob/7.3/src/Symfony/Component/HttpFoundation/Request.php
- Apache mod_proxy: header `X-Forwarded-*` ditambahkan (bukan ditimpa); peringatan `ProxyRequests`: https://httpd.apache.org/docs/2.4/mod/mod_proxy.html
- apache2 bionic: rilis publik terakhir `2.4.29-1ubuntu4.27` (9 Mar 2023): https://launchpad.net/ubuntu/+source/apache2
- USN-8589-1 (22 Jul 2026, apache2 `+esm11`, Ubuntu Pro): https://ubuntu.com/security/notices/USN-8589-1 · USN-8396-1 (8 Jun 2026, `+esm9`): https://ubuntu.com/security/notices/USN-8396-1
- Status bionic per CVE: CVE-2024-38473 (`+esm7`): https://ubuntu.com/security/CVE-2024-38473 · CVE-2024-38474 (`+esm4`): https://ubuntu.com/security/CVE-2024-38474 · CVE-2024-27316 (`+esm2`): https://ubuntu.com/security/CVE-2024-27316
- TLS 1.3 untuk apache2 bionic (`2.4.29-1ubuntu4.12`, LP #1845263): https://bugs.launchpad.net/bugs/1845263
- Konfigurasi TLS Mozilla (kini TLSRef Configurator): https://ssl-config.mozilla.org/
- CVE-2024-1086 di bionic (hanya kernel ESM): https://ubuntu.com/security/CVE-2024-1086 · CISA KEV 30 Mei 2024: https://www.cisa.gov/news-events/alerts/2024/05/30/cisa-adds-two-known-exploited-vulnerabilities-catalog · dipakai ransomware (Okt 2025): https://www.bleepingcomputer.com/news/security/cisa-linux-privilege-escalation-flaw-now-exploited-in-ransomware-attacks/
- Harga Ubuntu Pro ($500/server fisik/tahun): https://ubuntu.com/pricing/pro
- modsecurity-apache bionic 2.9.2 (universe): https://launchpad.net/ubuntu/bionic/+source/modsecurity-apache · ModSecurity pindah ke OWASP: https://owasp.org/blog/2024/01/09/ModSecurity.html · EOL Trustwave 1 Jul 2024: https://www.trustwave.com/en-us/resources/blogs/spiderlabs-blog/fare-thee-well-modsecurity-end-of-life-and-last-commercial-rules-update-for-june-2024/
- PHP bawaan bionic 7.2 (`7.2.24-0ubuntu0.18.04.17`): https://launchpad.net/ubuntu/bionic/+source/php7.2 · WordPress 7.0 minimum PHP 7.4: https://make.wordpress.org/core/2026/01/09/dropping-support-for-php-7-2-and-7-3/ · https://make.wordpress.org/core/2026/05/22/php-support-clarification-2026/
- Public Suffix List (`sch.id`): https://publicsuffix.org/list/public_suffix_list.dat
- certbot snap untuk Apache: https://certbot.eff.org/instructions?ws=apache&os=snap · snap certbot di Ubuntu: https://snapcraft.io/install/certbot/ubuntu
- Let's Encrypt: akhir OCSP: https://letsencrypt.org/2024/12/05/ending-ocsp · https://letsencrypt.org/2025/08/06/ocsp-service-has-reached-end-of-life · email kedaluwarsa dihentikan 4 Jun 2025: https://letsencrypt.org/docs/expiration-emails/ · 64 hari (10 Feb 2027) → 45 hari (16 Feb 2028): https://letsencrypt.org/2025/12/02/from-90-to-45
- Laravel Validation (`mimes` membaca isi file, `extensions`, `image` menolak SVG): https://laravel.com/docs/12.x/validation
- Laravel TrustProxies (default termasuk `HEADER_X_FORWARDED_HOST`/`PREFIX`; STH sengaja tidak mempercayai keduanya, §3e): https://github.com/laravel/framework/blob/12.x/src/Illuminate/Http/Middleware/TrustProxies.php
- PHP Supported Versions (8.3 security s/d 31 Des 2027; 8.4 s/d 31 Des 2028; 8.5 s/d 31 Des 2029): https://www.php.net/supported-versions.php
- MySQL EOL (8.4 LTS premier s/d Apr 2029, extended s/d Apr 2032; 8.0 EOL Apr 2026): https://endoflife.date/mysql
- Ubuntu release cycle (18.04 ESM s/d 2028, Legacy s/d 2033): https://ubuntu.com/about/release-cycle
- Ubuntu Pro (gratis untuk personal use ≤5 mesin fisik): https://ubuntu.com/pro
- Ubuntu 18.04 end of standard support: https://ubuntu.com/blog/18-04-end-of-standard-support
- Repo Docker bionic (docker-ce 24.0.2, containerd.io 1.6.21, compose 2.18.1): https://download.docker.com/linux/ubuntu/dists/bionic/pool/stable/amd64/
- Docker Engine 24.0 release notes (24.0.9: CVE-2024-21626, CVE-2024-24557, runc 1.1.12): https://docs.docker.com/engine/release-notes/24.0/
- Docker Engine 29 release notes (29.8.1 tgl 15 Sep 2026; containerd static 2.3.5; runc static 1.5.1; cgroup v1 deprecated, didukung s/d minimal Mei 2029): https://docs.docker.com/engine/release-notes/29/
- Docker static binaries (kernel ≥ 3.10, iptables ≥ 1.4, cgroupfs; tidak disarankan untuk production karena tanpa update otomatis): https://docs.docker.com/engine/install/binaries/ · unduhan: https://download.docker.com/linux/static/stable/x86_64/
- Status dukungan Docker Engine (28 berakhir 13 Mei 2026): https://endoflife.date/docker-engine
- Unit systemd: https://github.com/moby/moby/tree/master/contrib/init/systemd · https://github.com/containerd/containerd/blob/main/containerd.service
- Docker Compose v5.5.1 (3 Sep 2026): https://github.com/docker/compose/releases
- runc releases (1.5.0: 1.2.z tidak didukung, 1.3.z hanya CVE berat; 1.5.2 tgl 25 Sep 2026): https://github.com/opencontainers/runc/releases · CVE-2026-41579: https://nvd.nist.gov/vuln/detail/CVE-2026-41579
- cgroup v1 dipertahankan s/d minimal Mei 2029: https://github.com/opencontainers/runc/issues/4955 · https://github.com/containerd/containerd/issues/12443 · status rilis containerd (2.3 LTS s/d 30 Apr 2028): https://containerd.io/releases/
- Docker & ufw / packet filtering: https://docs.docker.com/engine/network/packet-filtering-firewalls/
- CVE-2024-21626 (runc ≤ 1.1.11): https://www.tenable.com/cve/CVE-2024-21626
- runc CVE-2025-31133 / 52565 / 52881 (fix 1.2.8, 1.3.3): https://www.sysdig.com/blog/runc-container-escape-vulnerabilities · https://nvd.nist.gov/vuln/detail/CVE-2025-52565
- Moby clone3 seccomp fix (20.10.10): https://github.com/moby/moby/pull/42681
- Alpine 3.14 / faccessat2 & runc ≥ 1.0.0-rc93: https://wiki.alpinelinux.org/wiki/Release_Notes_for_Alpine_3.14.0
- Livewire CVE-2025-54068 (fix 3.6.4, CISA KEV): https://www.synacktiv.com/en/advisories/livewire-remote-command-execution-through-unmarshaling · https://www.wiz.io/vulnerability-database/cve/cve-2025-54068
- dompdf CVE-2022-28368 (< 1.2.1): https://github.com/advisories/GHSA-x752-qjv4-c4hc
- dompdf CVE-2023-23924 (+ bypass, tuntas 2.0.3): https://github.com/dompdf/dompdf/security/advisories/GHSA-3cw5-7cxw-v5qg · https://github.com/dompdf/dompdf/security/advisories/GHSA-56gj-mvh6-rp75
- dompdf 3.1.6 (20 Jul 2026; 6 advisori, termasuk CVE-2026-56722 baca file via SVG `data:` dan GHSA-wvh6-f5jh-8gw4 chroot bypass): https://github.com/dompdf/dompdf/security/advisories/GHSA-cx96-42px-69fm · https://github.com/dompdf/dompdf/releases
- dompdf Options (`isJavascriptEnabled` default true; `chroot` menerima array; protokol default termasuk `data://`): https://github.com/dompdf/dompdf/blob/master/src/Options.php
- PhpSpreadsheet CVE-2024-45048 (XXE; fix 1.29.1 / 2.2.1): https://nvd.nist.gov/vuln/detail/cve-2024-45048
- pdf.js CVE-2024-4367 (fix 4.2.67 / `isEvalSupported:false`): https://codeanlabs.com/2024/05/cve-2024-4367-arbitrary-js-execution-in-pdf-js/
- Laravel APP_KEY leakage → RCE (GitGuardian × Synacktiv, 2025): https://blog.gitguardian.com/exploiting-public-app_key-leaks/ · https://www.synacktiv.com/en/publications/laravel-appkey-leakage-analysis
- OWASP CSV Injection: https://community.owasp.org/attacks/CSV_Injection
- OWASP ASVS 5.0 (bab V1–V17): https://github.com/OWASP/ASVS/tree/master/5.0/en
- OWASP Top 10:2025: https://owasp.org/Top10/2025/
- ClamAV Docker (RAM ≥ 1,2 GiB signature; disarankan 3–4 GiB): https://docs.clamav.net/manual/Installing/Docker.html
- Google Workspace SMTP relay: https://support.google.com/a/answer/176600 · limit: https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace
- Google: transisi dari less secure apps ke OAuth (14 Mar 2025): https://support.google.com/a/answer/14114704
- Gmail email sender guidelines (SPF/DKIM/DMARC): https://support.google.com/mail/answer/81126
- UU No. 27 Tahun 2022 (JDIH BPK): https://peraturan.bpk.go.id/Details/229798/uu-no-27-tahun-2022 · teks per pasal: https://pasal.id/peraturan/uu/uu-no-27-tahun-2022
- UU PDP berlaku penuh 17 Okt 2024: https://www.antaranews.com/berita/4404977/dirjen-aptika-pastikan-uu-pdp-sudah-berlaku-sepenuhnya
- Status lembaga PDP & PP 33/2026 (Sept 2026): https://www.cnbcindonesia.com/tech/20260916115609-37-768328/lembaga-perlindungan-data-belum-ada-di-ri-padahal-aturannya-sudah-ada
