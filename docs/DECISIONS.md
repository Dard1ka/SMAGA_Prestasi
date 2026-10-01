# DECISIONS.md — Log keputusan SMAGA Talent Hub (STH)

> Log keputusan produk & teknis STH untuk SMA Negeri 3 Semarang. Dirujuk dari `CLAUDE.md` proyek.
> Diperbarui: **1 Oktober 2026** (D-32…D-44; backlog D-45…D-60; integrasi W4 D-61…D-65) · Sumber: keputusan pengguna 30 Sep 2026, keputusan lead 1 Okt 2026, `DESIGN-SPEC-v2.md` §1–§5, `docs/SECURITY.md`.
> **Status:** `final` = dipakai untuk implementasi · `sementara` = dipakai sekarang, akan ditinjau · `menunggu jawaban sekolah` = butuh konfirmasi sebelum dibangun.
> **Aturan log:** entri tidak dihapus atau ditimpa. Keputusan yang berubah → entri baru dengan catatan "menggantikan D-xx", lalu status entri lama diubah menjadi `digantikan`.

## Ringkasan

| ID | Topik | Sumber | Status |
|----|-------|--------|--------|
| D-01 | Tujuh peran; Kepala Sekolah dan Admin terpisah | Pengguna | final |
| D-02 | Sekolah menginput lomba lebih dulu (katalog) | Pengguna / spec v2 | final |
| D-03 | Pendaftaran individu/tim dari akun yang ada + konfirmasi anggota | Pengguna / spec v2 | final |
| D-04 | Siswa boleh mengusulkan lomba | Pengguna | final |
| D-05 | Kepala Sekolah menyetujui setiap pendaftaran | Pengguna | final |
| D-06 | Surat tugas: nomor manual TU, PDF, satu gambar ttd+stempel, satu surat per tim | Pengguna | sementara |
| D-07 | Batas laporan hasil 30 hari setelah lomba (bisa diubah Admin) | Pengguna | final |
| D-08 | Rumus poin, bobot, poin penuh untuk tiap anggota tim | Pengguna / spec v2 | final |
| D-09 | Peringkat kumulatif semua tahun | Pengguna | final |
| D-10 | Tracker Jejak Prestasi 6 fase | Spec v2 | final |
| D-11 | Empat bidang | Spec v2 | final |
| D-12 | Kolom import Excel siswa | Pengguna | final |
| D-13 | Arsip otomatis 18 bulan setelah mulai kelas XII | Pengguna | final |
| D-14 | Login NIS/NISN + password berpola, tanpa Kode Aktivasi | Pengguna | final (risiko diterima) |
| D-15 | OTP email ditunda; dev menerima 6 digit apa pun | Pengguna | sementara |
| D-16 | Halaman publik tanpa login | Pengguna / spec v2 | final (syarat persetujuan: menunggu jawaban sekolah) |
| D-17 | Stack Laravel 13 + PHP 8.4 + MySQL 8.4 LTS di Docker | Pengguna | final |
| D-18 | Subdomain `prestasi.sman3-smg.sch.id` di belakang Apache 2.4.29 host | Pengguna | final |
| D-19 | Arah visual v2 dan gaya bahasa | Spec v2 | final (fase desain) |
| D-20 | Mockup hanya memakai data fiktif | Spec v2 | final |
| D-21 | Penjaringan talenta pindah dari Google Form ke STH (detail: `TALENT-FORM.md`) | Pengguna | final |
| D-22 | 6 jenis talenta dipetakan ke 4 bidang: OSN & OLPAIN → Sains, Riset → Riset, FLS3N & Debat → Seni Budaya, O2SN → Olahraga | Pengguna | final |
| D-23 | Siswa boleh memilih lebih dari satu jenis talenta; semua kelas aktif (termasuk XII) boleh ikut penjaringan | Pengguna | final |
| D-24 | Password awal = `sm4g4_DDMMYY` dari tanggal lahir (contoh 2 Maret 2007 → `sm4g4_020307`); pola tidak pernah ditampilkan di UI | Pengguna (workflow kasar.svg) | final (menggantikan format DD-MM-YYYY) |
| D-25 | Batas laporan hasil default **45 hari (1,5 bulan) setelah tanggal final lomba**, bisa diubah Admin | Pengguna (workflow kasar.svg) | final (menggantikan 30 hari) |
| D-26 | Import data siswa boleh dilakukan **Kesiswaan dan Admin** | Pengguna | final |
| D-27 | **Semua** upload info lomba (siswa, guru, staf, termasuk Kesiswaan sendiri) masuk antrean verifikasi Kesiswaan dulu; baru tampil di katalog siswa setelah diverifikasi; verifikator & waktu tercatat di audit log | Pengguna | final |
| D-28 | Data lomba punya field **link registrasi** resmi penyelenggara (selain juknis/brosur/poster/website) | workflow kasar.svg | final |
| D-29 | Alumni (diarsipkan 18 bulan setelah mulai kelas XII) **tidak bisa login**; nama & prestasinya tetap tampil di ranking/halaman publik | workflow kasar.svg | final (menggantikan "read-only") |
| D-30 | Admin bisa menonaktifkan/mengarsipkan akun manual (pindah sekolah, meninggal, dsb.) selain arsip otomatis | workflow kasar.svg | final |
| D-31 | Halaman publik juga punya tampilan **pemenang per lomba** (klik lomba → daftar pemenang & capaian) | workflow kasar.svg | final |
| D-32 | Password baru siswa **minimal 10 karakter** (SECURITY K5) | Lead (1 Okt 2026) | final |
| D-33 | Ketua boleh membatalkan pendaftaran selama `menunggu_konfirmasi` **atau** `menunggu_kepsek` | Lead (1 Okt 2026) | final |
| D-34 | Laporan hasil yang **ditolak** mengakhiri pendaftaran sebagai `selesai` **tanpa poin** | Lead (1 Okt 2026) | final |
| D-35 | **Guru pembimbing wajib** dipilih saat mendaftar | Lead (1 Okt 2026) | final |
| D-36 | Anggota menolak undangan: tim lanjut bila tersisa **≥ 2** anggota yang sudah konfirmasi; selain itu ketua membatalkan lalu mendaftar ulang | Lead (1 Okt 2026) | final |
| D-37 | Peringkat pribadi ("peringkatmu") dihitung di antara **siswa aktif**; peringkat publik memuat alumni (ditandai "Alumni") | Lead (1 Okt 2026) | final |
| D-38 | Halaman publik menulis nama siswa hanya dengan `publish_consent` (selain itu "Siswa kelas X"); halaman publik `noindex` sampai sekolah memastikan proses izin orang tua | Lead (1 Okt 2026) | final (izin indeks: menunggu jawaban sekolah, U-04) |
| D-39 | Tautan sertifikat di halaman publik hanya dengan `publish_consent` **dan** saklar Admin | Lead (1 Okt 2026) | final |
| D-40 | Penggantian akun Kepala Sekolah berlaku **24 jam** kemudian (bisa dibatalkan), gambar ttd lama dilepas, data penandatangan dari akun baru | Lead (1 Okt 2026) | final |
| D-41 | Maksimal **3 akun Admin**; Admin pertama dibuat dengan `php artisan sth:create-admin` | Lead (1 Okt 2026) | final |
| D-42 | Staf login dengan **username atau email**, siswa dengan **NIS atau NISN** | Lead (1 Okt 2026) | final |
| D-43 | Surat tugas: **Terbitkan** (nomor + PDF) dan **Kirim** adalah dua langkah; surat siswa mencetak NIS seperti template sekolah | Lead (1 Okt 2026) | final |
| D-44 | `users.id` tetap bigint; tabel domain memakai ULID | Lead (1 Okt 2026) | final |
| D-45 | Reset akun siswa oleh Admin memberi **kata sandi sementara acak, berlaku 72 jam** (K10), bukan pola kata sandi awal | Lead / B1 (1 Okt 2026) | sementara — bisa diubah user |
| D-46 | Pemberian peran staf penting (Admin, Kepala Sekolah, Kesiswaan, Tata Usaha) butuh **persetujuan Admin kedua**, atau berlaku **24 jam** kemudian (bisa dibatalkan) bila hanya ada satu Admin; penurunan peran langsung berlaku | Lead / B5 (1 Okt 2026) | sementara — bisa diubah user |
| D-47 | E-flyer Humas **tetap di disk privat**; publikasi terjadwal hanya mengubah status pada waktunya, memberi tahu siswa, dan mengingatkan Humas untuk mengunggah | Lead / B3 (1 Okt 2026) | sementara — bisa diubah user |
| D-48 | Import siswa tetap **membersihkan** awalan `=`, `+`, `-`, `@` di sel dan **menandai** baris itu (tidak menolak barisnya) | Lead / B2 (1 Okt 2026) | sementara — bisa diubah user |
| D-49 | Koreksi surat tugas = **satu langkah** (cabut + terbitkan versi baru dengan nomor baru; nomor lama tidak dipakai lagi), boleh di tahap mana pun, tanpa persetujuan kedua, **Kepala Sekolah selalu diberi tahu**; alasan hanya di log audit dan riwayat TU | Lead / B3 (1 Okt 2026) | sementara — bisa diubah user |
| D-50 | Selama `menunggu_konfirmasi`, ketua boleh mengundang anggota tambahan/pengganti; siswa yang menolak tidak bisa diundang lagi ke tim yang sama; undangan kedaluwarsa di batas pendaftaran | Lead / B3 (1 Okt 2026) | sementara — bisa diubah user |
| D-51 | User DB runtime `sth_app` memakai **hak per tabel**: `activity_log` dan `audit_seals` hanya `SELECT/INSERT`, `migrations` hanya `SELECT` | B6 (SECURITY §3h) (1 Okt 2026) | sementara — bisa diubah user |
| D-52 | Peringkat di dalam aplikasi (`siswa.peringkat`) hanya **siswa aktif**; alumni hanya di peringkat publik | Lead / B4 (1 Okt 2026) | sementara — bisa diubah user |
| D-53 | Ekspor Database prestasi = **unduhan langsung** setelah konfirmasi kata sandi (≤ 10 000 baris, bertanda air, teraudit); ekspor antre + tautan bertanda tangan menyusul bila perlu | Lead / B4 (1 Okt 2026) | sementara — bisa diubah user |
| D-54 | Kontrol akun siswa: jendela aktivasi **14 hari** (Admin bisa ubah 1–30), salah kata sandi ke-**10** membekukan akun belum aktif, tautan "Bukan saya?" berlaku **7 hari** sekali pakai | B1 (1 Okt 2026) | sementara — bisa diubah user |
| D-55 | Import siswa **> 50 baris** valid berjalan di antrean (potongan 100 baris); berkas import dihapus saat job selesai | B2 (1 Okt 2026) | sementara — bisa diubah user (penghapusan berkas: final di D-64) |
| D-56 | Hash chain audit di tabel terpisah `audit_seals` (disegel setelah commit, SHA-256 biasa); `'unsafe-eval'` dihapus dari CSP | B2 (1 Okt 2026) | digantikan D-63 (hash chain) dan D-65 (CSP), keduanya final |
| D-57 | Kepala Sekolah boleh mengekspor laporan prestasi (PDF/XLSX, bertanda air, teraudit, nama siswa tanpa NIS/NISN) | Lead / B5 (1 Okt 2026) | sementara — bisa diubah user |
| D-58 | Ubah tanggal arsip siswa ikut menggeser `grade12_start_date`; memulihkan akun arsip wajib memakai tanggal arsip baru di masa depan | B5 (1 Okt 2026) | sementara — bisa diubah user |
| D-59 | "Surat atas nama saya" (Kepala Sekolah) = surat yang terbit sejak penggantian Kepala Sekolah terakhir ke akun itu | B5 (1 Okt 2026) | sementara — bisa diubah user |
| D-60 | Template surat TU = teks tetap + penanda `{sekolah}`, `{penyelenggara}`, `{tahun_ajaran}`; format nomor surat tetap hanya Admin | B5 (1 Okt 2026) | sementara — bisa diubah user |
| D-61 | Akun staf baru dengan peran Admin, Kesiswaan, atau Tata Usaha dibuat **tanpa peran** + permintaan peran: **persetujuan Admin kedua**, atau **24 jam** (bisa dibatalkan) bila hanya ada satu Admin; Kepala Sekolah hanya lewat "Ganti Kepala Sekolah" | Lead / W4 (1 Okt 2026) | sementara — bisa diubah user |
| D-62 | Mode uji OTP **hanya dari environment** (`APP_ENV=local` **dan** `STH_OTP_TEST_MODE=true`); setting `otp_test_mode` dihapus | W4 (1 Okt 2026) | final |
| D-63 | Segel hash chain audit di tabel samping `audit_seals` + `audit_chain_heads`, ditulis **setelah commit** (SHA-256 biasa) | B2 / W4 (1 Okt 2026) | final (menggantikan bagian hash chain D-56) |
| D-64 | Berkas spreadsheet import antre **dihapus saat job selesai** (SHA-256 tetap tercatat), bukan disimpan 7 hari | B2 / W4 (1 Okt 2026) | final (menetapkan bagian berkas D-55) |
| D-65 | `'unsafe-eval'` **dihapus permanen** dari CSP (`livewire.csp_safe = true`) | B2 / W4 (1 Okt 2026) | final (menggantikan bagian CSP D-56) |
| U-01…U-06 | Usulan keamanan yang belum diputuskan | Agent H (keamanan) | menunggu jawaban sekolah / sementara |

---

## A. Produk & alur

### D-01 · Peran pengguna · 30 Sep 2026
- **Keputusan:** Ada 7 peran: Siswa, Guru Pembimbing, Kesiswaan, Kepala Sekolah, Tata Usaha, Humas, Admin. Kepala Sekolah dan Admin adalah akun terpisah. Admin mengendalikan semuanya, termasuk mengganti akun Kepala Sekolah.
- **Alasan:** Persetujuan adalah wewenang jabatan Kepala Sekolah, sedangkan Admin (operator) mengurus data dan sistem. Penggantian kepala sekolah tidak boleh bergantung pada akun kepala sekolah lama.
- **Dampak:** Policy per peran, tanpa gabungan "Kepsek/Admin". Karena kekuasaan Admin total, akun ini butuh 2FA, akun break-glass, konfirmasi ulang password, dan audit log immutable (SECURITY §3m-9). Mengganti akun Kepala Sekolah memicu notifikasi dan tercatat di audit.
- **Status:** final.

### D-02 · Katalog lomba diinput sekolah · 30 Sep 2026
- **Keputusan:** Kesiswaan dan Admin membuat setiap lomba di katalog: nama, bidang, penyelenggara, jenis penyelenggara, kategori bobot A–E, status ajang, tingkat (diverifikasi Kesiswaan), pelaksanaan, tanggal lomba, batas pendaftaran, tempat, individu/tim (+ maks. anggota), guru pembimbing default, juknis/brosur PDF, website resmi, dan poster (opsional).
- **Alasan:** Data tingkat dan kategori yang menentukan poin harus berasal dari sekolah, bukan dari siswa.
- **Dampak:** Siswa hanya memilih dari katalog. Field bobot (`tingkat`, `kategori`, `status_ajang`) hanya bisa diubah Kesiswaan/Admin dan tercatat di audit. URL website resmi divalidasi `https`, tidak pernah di-fetch server.
- **Status:** final.

### D-03 · Pendaftaran individu/tim · 30 Sep 2026
- **Keputusan:** Siswa mendaftar sebagai individu atau tim. Ketua memilih anggota dari akun siswa yang sudah ada (cari nama/NIS/NISN). Nama diambil dari akun masing-masing, dan setiap anggota harus mengonfirmasi ("Konfirmasi ikut"). Guru pembimbing dipilih siswa, dengan default dari lomba.
- **Alasan:** Mencegah nama anggota diketik bebas atau salah, dan memastikan setiap anggota benar-benar setuju ikut.
- **Dampak:** Endpoint pencarian anggota hanya mengembalikan nama + kelas, dengan rate limit (SECURITY §3m-3). Undangan hanya bisa dikonfirmasi oleh penerimanya. Akun arsip tidak bisa diundang. Roster tim dikunci setelah disetujui Kepala Sekolah.
- **Status:** final.

### D-04 · Usulan lomba oleh siswa · 30 Sep 2026
- **Keputusan:** Siswa bisa mengusulkan lomba yang belum ada di katalog (nama, penyelenggara, tautan/URL, unggah brosur). Kesiswaan meninjau usulan dan mengubahnya menjadi entri katalog.
- **Alasan:** Siswa sering menemukan lomba lebih dulu daripada sekolah.
- **Dampak:** Ada permukaan serangan baru: URL (phishing, bukan SSRF karena tidak pernah di-fetch) dan unggahan brosur (aturan upload §3d). Usulan bersifat privat sampai disetujui. Rate limit 5 usulan/hari per siswa.
- **Status:** final.

### D-05 · Persetujuan Kepala Sekolah · 30 Sep 2026
- **Keputusan:** Kepala Sekolah menyetujui setiap pendaftaran (individu atau seluruh tim). TU baru bisa membuat surat tugas setelah pendaftaran disetujui.
- **Alasan:** Surat tugas adalah dokumen resmi atas nama Kepala Sekolah.
- **Dampak:** State machine pendaftaran: menunggu konfirmasi anggota → menunggu Kepala Sekolah → disetujui/ditolak. Generate surat diperiksa di server (`status = disetujui`), bukan hanya disembunyikan di UI.
- **Status:** final.

### D-06 · Surat tugas · 30 Sep 2026
- **Keputusan:** TU mengisi **dua nomor surat secara manual**: nomor surat siswa (format template Excel `400.3.8/<no>/MRD/<bulan romawi>/<tahun>`) dan nomor surat guru pendamping (format template Word `400.3.8.6/<no>/<tahun>`). Setelah itu TU menekan Generate, dan sistem menghasilkan PDF. Satu file gambar tanda tangan + stempel (diunggah Admin di Pengaturan) ditempel di semua surat. Satu tim mendapat **satu** surat siswa bersama, dan pembimbing mendapat Surat Perintah Tugas.
- **Alasan:** Penomoran mengikuti buku agenda TU. Satu gambar ttd lebih sederhana daripada tanda tangan per surat.
- **Dampak:** Constraint unik nomor per jenis surat + tahun, race-safe, dan immutable setelah terbit. Template dikonversi sekali ke Blade dan dirender dengan dompdf ≥ 3.1.6 (tanpa remote/JS). File ttd disimpan privat, hanya Admin yang boleh mengganti. Setiap surat memuat QR/kode verifikasi (gambar ttd mudah disalin). Detail di SECURITY §3g dan §3m-5.
- **Status:** sementara. Template surat tugas masih dikonfirmasi sekolah, dan arti "MRD" belum diketahui.

### D-07 · Batas laporan hasil · 30 Sep 2026
- **Keputusan:** Siswa melaporkan hasil paling lambat **30 hari setelah lomba selesai** (nilai bisa diubah Admin), mengunggah sertifikat/dokumentasi, lalu Kesiswaan memverifikasi dan poin diberikan.
- **Alasan:** Memberi waktu sertifikat terbit, tanpa membiarkan laporan terbengkalai.
- **Dampak:** Pengaturan `batas_laporan_hari` (default 30) disimpan berversi dan diaudit. Pengingat dikirim sebelum batas. Poin hanya dihitung dari field yang sudah diverifikasi.
- **Status:** final.

### D-08 · Poin · 30 Sep 2026
- **Keputusan:** Poin = Tingkat × Capaian × Penyelenggara.
  - Tingkat: Internasional 100 · Nasional 80 · Provinsi 60 · Kota/Kabupaten 40 · Sekolah 20.
  - Capaian: Juara I / emas 1,00 · Juara II / perak 0,85 · Juara III / perunggu 0,70 · Finalis 0,50 · Semifinalis 0,30 · Penghargaan khusus 0,30 · Peserta 0,10.
  - Penyelenggara: A 1,00 · B 0,80 · C 0,60 · D 0,40 · E 0,20.
  - Setiap anggota tim mendapat poin **penuh**. Bobot bisa diubah Admin.
- **Alasan:** Menghargai tingkat, capaian, dan kredibilitas penyelenggara secara transparan. Poin penuh untuk tim mendorong kerja tim.
- **Dampak:** Poin dihitung hanya di server. Bobot disimpan berversi (setiap nilai poin menyimpan versi bobotnya), dan perubahan memicu rekalkulasi dengan laporan diff. Poin penuh per anggota memberi insentif menitipkan anggota, jadi roster dikunci setelah persetujuan dan setiap anggota wajib konfirmasi sendiri. Angka ditampilkan dengan koma desimal.
- **Status:** final.

### D-09 · Peringkat kumulatif · 30 Sep 2026
- **Keputusan:** Peringkat menjumlahkan semua tahun. Siswa arsip tetap tampil dengan chip "Alumni".
- **Alasan:** Menghargai konsistensi prestasi selama bersekolah.
- **Dampak:** Query peringkat mencakup akun arsip. Di halaman publik, siswa hanya tampil sesuai flag persetujuan (D-16).
- **Status:** final.

### D-10 · Jejak Prestasi 6 fase · 30 Sep 2026
- **Keputusan:** Tracker punya 6 fase: 1 Daftar · 2 Persetujuan · 3 Surat tugas · 4 Lomba · 5 Laporan hasil · 6 Poin & apresiasi, masing-masing dengan sub-status (spec v2 §4).
- **Alasan:** Siswa bisa melihat posisi dan tindakan berikutnya dalam satu pandangan.
- **Dampak:** Status di DB berupa enum yang sama dengan fase ini, dan transisinya hanya lewat metode aksi ber-policy.
- **Status:** final.

### D-11 · Empat bidang · 30 Sep 2026
- **Keputusan:** Ada empat bidang: Sains · Riset · Seni Budaya · Olahraga.
- **Alasan:** Mengikuti pembinaan talenta sekolah dan menjaga navigasi tetap sederhana.
- **Dampak:** Enum/tabel referensi `bidang`, warna dan ikon per bidang (spec v2 §2, §6), serta drill-down publik per bidang.
- **Status:** final.

## B. Data & akun

### D-12 · Import siswa dari Excel · 30 Sep 2026
- **Keputusan:** Admin mengimpor Excel dengan kolom Nama lengkap, NIS, NISN, Tanggal lahir, Kelas saat pendaftaran, No HP (opsional). Admin memilih tahun ajaran data. Sistem menghitung angkatan, tahun masuk kelas XII, dan tanggal arsip otomatis (contoh: kelas X 2026/2027 → kelas XII Juli 2028 → arsip Januari 2030).
- **Alasan:** Data bersumber dari tata usaha sekolah dan cukup untuk login serta pengarsipan.
- **Dampak:** Import berjalan di queue dengan dry-run, validasi per baris, penolakan sel formula, dan keunikan NIS/NISN. Hash password awal dibuat di job. `birth_date`, `phone`, dan `nisn` dienkripsi per kolom (SECURITY §3f, §3i).
- **Status:** final.

### D-13 · Arsip otomatis · 30 Sep 2026
- **Keputusan:** Akun diarsipkan otomatis **18 bulan (1,5 tahun) setelah siswa mulai kelas XII**. Akun arsip read-only dan tidak menerima input baru, tetapi prestasinya tetap ada di histori dan halaman publik.
- **Alasan:** Sekitar 6–7 bulan setelah lulus, akun tidak lagi dibutuhkan untuk input, tetapi rekam prestasi tetap bernilai bagi sekolah.
- **Dampak:** Job terjadwal harus idempoten (`withoutOverlapping`, zona Asia/Jakarta). Read-only ditegakkan di policy/server, bukan hanya di UI. Sesi dicabut saat akun diarsipkan. Unarchive dan perubahan tanggal hanya bisa dilakukan Admin dengan alasan (SECURITY §3m-8).
- **Status:** final. (Apakah akun arsip masih boleh login untuk melihat portofolio: menunggu jawaban sekolah.)

### D-14 · Login & password awal · 30 Sep 2026
- **Keputusan:** Siswa login dengan **NIS atau NISN** + password. Password awal memakai pola sekolah `sm4g4_DD-MM-YYYY` (tanggal lahir) dan tidak pernah ditampilkan di UI. **Tidak ada Kode Aktivasi.** Login pertama: ganti password → hubungkan email → kode 6 digit. Bila akun dibajak, Admin membantu memulihkan.
- **Alasan:** Pengguna menolak kode aktivasi acak karena menambah beban distribusi. Pemulihan lewat Admin dianggap cukup.
- **Dampak:** Ini **penerimaan risiko tertulis**. Akun yang belum diaktivasi bisa diambil alih oleh siapa pun yang tahu NIS/NISN + tanggal lahir. Kontrol kompensasi WAJIB (SECURITY §3a, K1–K12):
  - jendela aktivasi 14 hari, jendela aktivasi ulang 72 jam, dan pembekuan setelah 10 kali gagal
  - rate limit per akun (NIS dan NISN satu bucket) + per IP (longgar karena NAT sekolah)
  - aturan password baru yang menolak tanggal lahir/pola/NIS/NISN
  - deteksi "password awal dicoba di akun aktif"
  - notifikasi email + "Bukan saya?"
  - laporan status aktivasi per kelas
  - "Reset kata sandi & email" oleh Admin (password sementara acak, audit)
  - pesan error generik, argon2id/bcrypt
- **Status:** final (risiko diterima). Formulir penerimaan risiko **menunggu tanda tangan Kepala Sekolah**. Pemicu tinjau ulang: ≥ 3 kasus pembajakan dalam satu semester.

### D-15 · OTP email ditunda · 30 Sep 2026
- **Keputusan:** Pengiriman OTP email ditunda. Selama pengembangan, kode 6 digit apa pun diterima. Tim deploy mengonfigurasi email noreply nyata nanti.
- **Alasan:** Mail server/relay sekolah belum tersedia, dan pengembangan tidak boleh terblokir.
- **Dampak:** Bypass aktif **hanya** bila `APP_ENV=local` **dan** `STH_OTP_DEV_BYPASS=true`. Aplikasi menolak boot bila flag menyala di environment lain, dan juga bila `MAIL_MAILER=log/array` di production. CI menguji keduanya. Email yang diikat saat bypass aktif ditandai belum terverifikasi dan diverifikasi ulang setelah OTP nyata aktif (SECURITY §3b).
- **Status:** sementara (sampai SMTP produksi siap; itu syarat go-live).

### D-16 · Halaman publik tanpa login · 30 Sep 2026
- **Keputusan:** Halaman publik terdiri dari ringkasan prestasi, grafik per bidang dengan drill-down (bidang → daftar siswa + capaian → prestasi seorang siswa beserta lomba, peringkat, dan sertifikat), dan peringkat kumulatif. Halaman hanya menampilkan nama + kelas + prestasi, tidak pernah NIS/NISN/HP/tanggal lahir. Foto siswa hanya tampil bila flag persetujuan menyala (selain itu avatar inisial).
- **Alasan:** Apresiasi dan promosi prestasi sekolah.
- **Dampak:** Data anak adalah data pribadi spesifik (UU PDP Pasal 4(2)) dan butuh persetujuan orang tua (Pasal 25). Flag persetujuan per jenis (nama, foto, sertifikat) default tidak. Output publik lewat resource dengan allowlist kolom, slug acak per siswa, `noindex` per siswa sebagai default, dan rate limit (SECURITY §3m-7).
- **Status:** final untuk lingkup halaman. Proses persetujuan orang tua dan penampilan gambar sertifikat: **menunggu jawaban sekolah** (lihat U-04).

## C. Teknologi & infrastruktur

### D-17 · Stack · 30 Sep 2026
- **Keputusan:** Laravel 13 + PHP 8.4, MySQL 8.4 LTS, semuanya di Docker pada host. Apache di host menjadi TLS reverse proxy ke kontainer.
- **Alasan:** Security fix Laravel 12 berakhir 24 Feb 2027. Laravel 13 (rilis 17 Mar 2026) mendapat security fix sampai 17 Mar 2028, PHP 8.4 sampai 31 Des 2028, dan MySQL 8.4 LTS premier sampai Apr 2029 (terverifikasi di situs resmi).
- **Dampak:** Upgrade ke Laravel 14 dijadwalkan sebelum Mar 2028. Image PHP resmi mendukung argon2id. Asset dibangun di stage Node pada Dockerfile, bukan di host 18.04.
- **Status:** final.

### D-18 · Subdomain & host bersama · 30 Sep 2026
- **Keputusan:** Aplikasi berjalan di `https://prestasi.sman3-smg.sch.id`. Situs utama `https://sman3-smg.sch.id/` adalah aplikasi terpisah di server yang sama. Web server: Apache 2.4.29 (bawaan Ubuntu 18.04). Kami punya sudo. **Tidak ada VM baru dan tidak ada upgrade OS.**
- **Alasan:** Subdomain memberi origin terpisah (lebih aman daripada sub-path). Infrastruktur sekolah tidak bisa diganti.
- **Dampak:**
  - Vhost Apache: `mod_proxy`/`mod_proxy_http`/`mod_headers`/`mod_ssl`, certbot, HSTS hanya di subdomain.
  - Cookie `__Host-` host-only, TrustProxies/TrustHosts.
  - Risiko residual: same-site dengan situs utama (`sch.id` ada di Public Suffix List), dan situs utama di host yang sama (tampaknya WordPress) bisa menjadi pivot.
  - Apache/kernel 18.04 hanya dipatch lewat Ubuntu Pro (U-01).
  - cgroup v1 memberi tenggat migrasi OS ≤ Mei 2029.
- **Status:** final (sebagai batasan).

## D. Desain & mockup

### D-19 · Arah visual & bahasa · 30 Sep 2026
- **Keputusan:** Arah visual v2 "Behance edu-soft": kartu katalog bercover per bidang, nav pill terisi, font Urbanist + Plus Jakarta Sans, warna AA dengan satu warna untuk satu makna, tanpa emoji dan tanpa tema gelap. Teks berbahasa Indonesia, sentence case, singkat, dan siswa disapa "kamu".
- **Alasan:** Referensi Behance dari pengguna (CORSTA, Scholarship Discovery, CrioEd). Pengguna tidak suka teks panjang.
- **Dampak:** Helmet CSS, komponen, dan ikon bersama di `DESIGN-SPEC-v2.md` §3–§6. Aksesibilitas: target sentuh ≥ 44 px di mobile, label pada semua input.
- **Status:** final (fase desain).

### D-20 · Data fiktif di mockup · 30 Sep 2026
- **Keputusan:** Mockup **tidak pernah** memakai nama, NIS, atau NISN siswa/guru yang nyata. Logo memakai placeholder (bukan lambang sekolah), tanpa foto nyata (cover bidang + avatar inisial), dan dashboard diberi chip "Data contoh".
- **Alasan:** Melindungi data anak (UU PDP) dan mencegah mockup dikira data resmi.
- **Dampak:** Semua agen memakai data bersama dari spec v2 §5 (mis. Nadia Putri Anindya, NIS 225226201). Seed/fixture pengembangan juga harus sintetis. Dilarang memakai dump data sekolah di lingkungan dev/CI.
- **Status:** final.

## D2. Keputusan lead 1 Okt 2026 (gelombang 1–2)

Dicatat oleh W3-A dari keputusan lead (CLAUDE.md §8, catatan `docs/dev-notes/`). Tidak menggantikan entri lama kecuali disebut.

### D-32 · Panjang password siswa · 1 Okt 2026
- **Keputusan:** Password baru siswa minimal **10 karakter** (SECURITY K5). `PasswordPolicy` juga menolak tanggal lahir, `sm4g4`, NIS, NISN, dan nama.
- **Dampak:** `AppServiceProvider::MIN_PASSWORD_LENGTH = 10`; staf produksi memakai `Password::defaults()` (12 + campuran + HIBP).
- **Status:** final.

### D-33 · Batalkan pendaftaran · 1 Okt 2026
- **Keputusan:** Ketua boleh membatalkan pendaftaran saat `menunggu_konfirmasi` atau `menunggu_kepsek` (→ `dibatalkan`). Setelah Kepala Sekolah memutuskan, tidak bisa dibatalkan dari aplikasi.
- **Dampak:** `RegistrationStateMachine` + `CancelRegistration`.
- **Status:** final.

### D-34 · Laporan hasil ditolak · 1 Okt 2026
- **Keputusan:** Laporan yang ditolak Kesiswaan (catatan wajib) mengakhiri pendaftaran sebagai `selesai` tanpa poin. "Perlu revisi" tetap kembali ke `menunggu_laporan`.
- **Dampak:** `RejectReport`; tracker fase 6 tanpa poin.
- **Status:** final.

### D-35 · Guru pembimbing wajib · 1 Okt 2026
- **Keputusan:** Pendaftaran wajib memilih guru pembimbing (default: pembimbing lomba). Dibutuhkan untuk Surat Perintah Tugas guru.
- **Status:** final.

### D-36 · Anggota menolak undangan · 1 Okt 2026
- **Keputusan:** Bila anggota menolak, tim lanjut ke `menunggu_kepsek` saat semua anggota tersisa sudah konfirmasi **dan** tim masih ≥ 2 orang. Bila hanya ketua yang tersisa, pendaftaran tetap `menunggu_konfirmasi`; ketua membatalkan lalu mendaftar ulang (fitur ganti anggota belum ada).
- **Status:** final.

### D-37 · Peringkat pribadi vs publik · 1 Okt 2026
- **Keputusan:** Peringkat pribadi siswa (Beranda, Portofolio, halaman publik siswa) dihitung di antara **siswa aktif** saja (akun aktif/menunggu aktivasi). Peringkat publik memuat alumni dengan chip "Alumni" lewat tombol "Tampilkan alumni" (D-09 tetap berlaku untuk papan peringkat).
- **Dampak:** Satu sumber `App\Support\Leaderboard\PersonalRank`. Data demo: Nadia #14 di semua halaman pribadi, #15 di peringkat publik dengan alumni.
- **Status:** final.

### D-38 · Nama di halaman publik & indeks mesin pencari · 1 Okt 2026
- **Keputusan:** Nama siswa tampil hanya bila `publish_consent` menyala; selain itu tertulis "Siswa kelas X/XI/XII" (atau "Alumni") dan tetap dihitung. Halaman publik `noindex` (setting `public_indexing` = mati) sampai sekolah memastikan proses izin orang tua; halaman per siswa selalu `noindex`.
- **Dampak:** Persetujuan diubah hanya lewat `App\Actions\Students\UpdateConsent` (Admin › Pengguna, teraudit); mencabut izin publikasi mengganti `public_slug`.
- **Status:** final (menyalakan indeks: menunggu jawaban sekolah, U-04).

### D-39 · Tautan sertifikat publik · 1 Okt 2026
- **Keputusan:** Tautan "Lihat sertifikat" di halaman publik hanya bila **semua** anggota tim punya `publish_consent` **dan** Admin menyalakan `public_certificates`. Selain itu hanya badge "Terverifikasi"; berkas lain tidak pernah publik (404).
- **Dampak:** `FilePolicy::viewPublic`, route `files.public`.
- **Status:** final.

### D-40 · Ganti akun Kepala Sekolah · 1 Okt 2026
- **Keputusan:** Penggantian berlaku 24 jam setelah disimpan (bisa dibatalkan Admin atau Kepala Sekolah yang diganti; langsung bila belum ada Kepala Sekolah). Saat berlaku, gambar ttd lama dilepas dan nama/pangkat/NIP penandatangan diambil dari akun baru.
- **Status:** final.

### D-41 · Akun Admin · 1 Okt 2026
- **Keputusan:** Maksimal 3 akun Admin. Admin pertama di produksi dibuat dengan `php artisan sth:create-admin` (tidak ada akun bawaan di seeder produksi).
- **Status:** final.

### D-42 · Identitas login · 1 Okt 2026
- **Keputusan:** Staf login dengan `username` atau email; siswa dengan NIS atau NISN (10 digit = NISN).
- **Status:** final.

### D-43 · Surat tugas dua langkah · 1 Okt 2026
- **Keputusan:** TU **menerbitkan** surat (nomor + PDF, tidak bisa diubah) lalu **mengirim** dalam langkah terpisah; status `surat_terbit` saat dikirim. Surat siswa mencetak NIS sesuai template sekolah (menjawab §F-2 untuk kolom NIS).
- **Status:** final.

### D-44 · Kunci tabel · 1 Okt 2026
- **Keputusan:** `users.id` tetap bigint (Fortify, sesi, spatie); tabel domain memakai ULID dan hanya ULID yang muncul di URL.
- **Status:** final.

## D3. Keputusan backlog B1–B6 (1 Okt 2026)

Dicatat oleh B6 dari arahan lead dan catatan `docs/dev-notes/B1.md`…`B6.md`. Semua berstatus **sementara — bisa diubah
user**: dipakai sekarang, lead/sekolah boleh menggantinya dengan entri baru. Tidak menggantikan entri lama kecuali disebut.

### D-45 · Reset akun siswa oleh Admin · 1 Okt 2026
- **Keputusan:** "Reset kata sandi & email" membuat **kata sandi sementara acak** 12 karakter (tanpa 0/O/1/l/I) yang berlaku **72 jam**, ditampilkan satu kali kepada Admin untuk diberikan langsung ke siswa (setelah cek identitas tatap muka). Email dilepas, semua sesi dicabut, pembekuan dicabut, siswa mengulang aktivasi. Pola kata sandi awal (D-24) tidak dipakai lagi untuk reset. Lebih dari 10 reset oleh satu Admin dalam 1 jam → peringatan ke semua Admin.
- **Alasan:** Pola awal mudah ditebak oleh siapa pun yang tahu tanggal lahir; reset biasanya dipakai justru saat akun diambil alih (SECURITY §3a K10).
- **Dampak:** `ResetStudentAccount` + `GenerateTemporaryPassword`; `users.initial_password_expires_at` (B1). Kata sandi sementara tidak pernah dicatat di log.
- **Status:** sementara — bisa diubah user.

### D-46 · Perubahan peran staf penting · 1 Okt 2026
- **Keputusan:** Memberi peran Admin, Kepala Sekolah, Kesiswaan, atau Tata Usaha butuh **persetujuan Admin lain** (tidak pernah berlaku otomatis selama ada Admin lain). Bila peminta adalah satu-satunya Admin, perubahan berlaku **24 jam** setelah disimpan, Kepala Sekolah diberi tahu, dan Admin mana pun bisa membatalkan. Permintaan peran Admin yang menunggu ikut dihitung dalam batas 3 Admin (D-41). Menurunkan/mencabut peran langsung berlaku.
- **Alasan:** Peran ini bisa menerbitkan surat atas nama Kepala Sekolah, mengubah poin, atau mengelola akun (SECURITY §3h maker-checker, §3m-9).
- **Dampak:** Tabel `role_change_requests`, Actions `app/Actions/Roles/*` (`RequestRoleChange` satu-satunya pintu), layar persetujuan di Pengguna & peran (B5). Semua langkah teraudit. Terbuka: apakah **akun baru** berperan penting (`CreateStaffAccount`) juga perlu Admin kedua (dijawab di D-61: ya).
- **Status:** sementara — bisa diubah user.

### D-47 · E-flyer dan publikasi terjadwal · 1 Okt 2026
- **Keputusan:** E-flyer tetap di disk privat (tidak ada URL publik). Saat `scheduled_at` tiba, sistem hanya mengubah status ke `dipublikasikan` (`published_at` = waktu jadwal), memberi tahu siswa, dan mengingatkan semua akun Humas untuk mengunggah ke kanal. Publikasi dilewati bila izin publikasi siswa dicabut.
- **Alasan:** Aplikasi tidak memposting ke media sosial; menaruh flyer di disk publik membuka data anak tanpa kontrol (SECURITY §3d, D-16).
- **Dampak:** `AutoPublishPublication`, job `AutoPublishPublications` (tiap 5 menit), notifikasi `PublicationDue` (B3).
- **Status:** sementara — bisa diubah user.

### D-48 · Sel formula saat import siswa · 1 Okt 2026
- **Keputusan:** Import tetap **membersihkan** awalan `=`, `+`, `-`, `@` dari sel (bukan menolak baris) dan **menandai** baris itu ("Sel dibersihkan") di pratinjau dan laporan import.
- **Alasan:** Data TU sering berisi nomor HP berawalan `+` atau tanda hubung; menolak baris akan menghambat import. Formula tidak pernah dievaluasi (OpenSpout), dan penandaan membuat perubahan terlihat (SECURITY §3f).
- **Dampak:** `ImportStudents` / `StudentImportReport` (flag `sanitized`), tabel `student_imports.sanitized_rows` (B2).
- **Status:** sementara — bisa diubah user.

### D-49 · Koreksi surat tugas · 1 Okt 2026
- **Keputusan:** Koreksi adalah **satu langkah**: surat lama dicabut dan versi baru terbit dengan **nomor baru** (nomor lama tidak pernah dipakai ulang). Boleh di tahap mana pun, tanpa persetujuan kedua, tetapi **Kepala Sekolah selalu diberi tahu**. Alasan (5–500 karakter) hanya tersimpan di log audit dan riwayat TU, tidak di email ke siswa/guru. Halaman verifikasi menampilkan "Dicabut" dan nomor penggantinya.
- **Alasan:** Kesalahan surat perlu cepat dibetulkan; pemberitahuan ke Kepala Sekolah memenuhi SECURITY §3m-5 tanpa memperlambat TU.
- **Dampak:** Kolom versi/pencabutan di `letters`, `RevokeLetter`, notifikasi `LetterRevised` (B3); pemberitahuan ke Kepala Sekolah dan daftar "Surat atas nama saya" (B5).
- **Status:** sementara — bisa diubah user.

### D-50 · Undangan anggota tim · 1 Okt 2026
- **Keputusan:** Selama `menunggu_konfirmasi` dan pendaftaran lomba masih buka, ketua boleh mengundang anggota tambahan atau pengganti (≤ maks. anggota). Siswa yang menolak tidak bisa diundang lagi ke tim yang sama. Undangan yang belum dijawab kedaluwarsa di `registration_closes_at` (tercatat sebagai menolak); tim yang tersisa < 2 orang tetap menunggu sampai ketua membatalkan.
- **Alasan:** Melengkapi D-36 tanpa membuka jalan "menitipkan" anggota setelah disetujui.
- **Dampak:** `InviteMembers`, `ExpirePendingInvitations`, job `ExpireInvitations`, notifikasi `InvitationsExpired` (B3).
- **Status:** sementara — bisa diubah user.

### D-51 · Hak akses database per tabel · 1 Okt 2026
- **Keputusan:** User DB runtime `sth_app` tidak punya hak tingkat database. `scripts/deploy/grants.sh` (dijalankan setelah setiap migrasi) memberi `SELECT/INSERT/UPDATE/DELETE` per tabel, kecuali `activity_log` dan `audit_seals` (hanya `SELECT/INSERT`) dan `migrations` (hanya `SELECT`).
- **Alasan:** Audit log harus append-only bagi aplikasi (SECURITY §3h); hash chain (`audit_seals`) mendeteksi perubahan oleh akun DB lain.
- **Dampak:** Kode aplikasi tidak boleh `UPDATE/DELETE` kedua tabel itu atau `TRUNCATE` apa pun; retensi audit (bila diputuskan) dijalankan operator. Detail: DEPLOY §8.2.
- **Status:** sementara — bisa diubah user.

### D-52 · Peringkat di dalam aplikasi · 1 Okt 2026
- **Keputusan:** Layar **Peringkat** siswa (`siswa.peringkat`) hanya memuat **siswa aktif**, tanpa tombol "Tampilkan alumni", sehingga baris "Kamu" sama dengan peringkat di Beranda/Portofolio. Alumni tetap di peringkat publik (ditautkan dari layar itu). Nama siswa lain mengikuti aturan izin publikasi (D-38).
- **Alasan:** Satu angka peringkat pribadi di semua layar (D-37).
- **Dampak:** `Siswa\Peringkat` memakai `Ranker` yang sama dengan `PersonalRank` (B4).
- **Status:** sementara — bisa diubah user.

### D-53 · Ekspor Database prestasi · 1 Okt 2026
- **Keputusan:** Ekspor Excel Database prestasi langsung diunduh setelah kata sandi dikonfirmasi dalam 15 menit terakhir; maks. 10 000 baris, setiap baris bertanda air "Diekspor oleh … waktu", sel aman dari formula, tanpa NISN/HP/email/tanggal lahir, tercatat `achievement.exported`.
- **Alasan:** Data sekolah kecil; antrean + tautan bertanda tangan 15 menit (SECURITY §3m) menambah kerumitan tanpa manfaat besar sekarang.
- **Dampak:** `ExportAchievements` (B4). Ekspor antre + signed URL menjadi tindak lanjut bila ekspor membesar (backlog).
- **Status:** sementara — bisa diubah user.

### D-54 · Parameter kontrol akun siswa · 1 Okt 2026
- **Keputusan:** Kata sandi awal hanya berlaku dalam **jendela aktivasi 14 hari** sejak import (Admin bisa ubah 1–30 hari di Status aktivasi; berlaku untuk import berikutnya). Salah kata sandi ke-**10** pada akun siswa yang belum aktif membekukan akun dan memberi tahu Admin + Kesiswaan; Kesiswaan/Admin bisa membuka ulang 72 jam (K2). Email "Bukan saya?" dikirim saat aktivasi dan saat ganti email; tautannya sekali pakai, berlaku **7 hari**, dan pembekuan karena "Bukan saya?" hanya bisa dibuka Admin. Laporan aktivasi CSV memakai `;` + BOM UTF-8; tanda "di luar jam sekolah" = Senin–Jumat 06.30–15.30 WIB (asumsi).
- **Alasan:** Kontrol kompensasi wajib untuk penerimaan risiko D-14 (SECURITY §3a K1–K3, K8, K9).
- **Dampak:** Kolom kontrol di `users`, tabel `email_notices`, Actions `app/Actions/Accounts/*` (B1).
- **Status:** sementara — bisa diubah user (jam sekolah dan jendela aktivasi perlu konfirmasi sekolah).

### D-55 · Import siswa di antrean · 1 Okt 2026
- **Keputusan:** Pratinjau (dry-run) tetap langsung untuk semua ukuran (maks. 3 000 baris). Import dengan **> 50 baris valid** berjalan di antrean dalam potongan 100 baris (satu transaksi per potongan; unggah ulang berkas yang sama melanjutkan). Berkas spreadsheet import antre dihapus saat job selesai (SHA-256 tetap tercatat), bukan disimpan 7 hari.
- **Alasan:** Hash kata sandi ±180 ms/baris; 1 000 baris sinkron melewati batas waktu PHP/nginx production. Antrean tunggal tetap bisa mengirim OTP.
- **Dampak:** `ImportStudentsJob`, tabel `student_imports` (B2).
- **Status:** sementara — bisa diubah user (batas 50 baris dan potongan 100 baris). Penghapusan berkas ditetapkan final di D-64.

### D-56 · Desain hash chain audit dan CSP · 1 Okt 2026
- **Keputusan:** Hash chain audit disimpan di tabel terpisah `audit_seals` (SHA-256 biasa seperti SECURITY §3m-9, disegel setelah commit dalam transaksi pendek), bukan kolom di `activity_log`; verifikasi harian `sth:audit:verify`. `'unsafe-eval'` dihapus dari CSP untuk semua halaman (Livewire build CSP-safe).
- **Alasan:** Tanpa kunci global di transaksi bisnis, `activity_log` tetap INSERT/SELECT saja (D-51). HMAC dengan kunci terpisah bisa jadi peningkatan berikutnya.
- **Dampak:** `App\Support\Audit\AuditChain`, `config/livewire.php` (B2). Jangan menjadwalkan `activitylog:clean` tanpa memangkas `audit_seals` dengan cara yang sama.
- **Status:** digantikan oleh D-63 (hash chain) dan D-65 (CSP); isinya sama, statusnya kini final.

### D-57 · Laporan prestasi untuk Kepala Sekolah · 1 Okt 2026
- **Keputusan:** Kepala Sekolah mendapat izin `achievement.export` untuk layar **Laporan** (PDF/XLSX per periode/bidang). Laporan internal memuat nama semua siswa, tidak pernah NIS/NISN; setiap berkas bertanda air "Diunduh oleh … · waktu · dokumen internal", butuh konfirmasi kata sandi, dan tercatat `school_report.exported`.
- **Dampak:** `ExportPrestasiReport`, `RoleName::defaultPermissions()` (B5). Database prestasi tetap hanya Kesiswaan/Admin.
- **Status:** sementara — bisa diubah user.

### D-58 · Ubah tanggal arsip dan pulihkan akun · 1 Okt 2026
- **Keputusan:** Mengubah tanggal arsip seorang siswa (alasan wajib) juga menggeser `grade12_start_date` (= tanggal − N bulan), agar perubahan pengaturan bulan arsip nanti tetap menghormati pergeseran itu. Memulihkan akun arsip (D-29) wajib memakai tanggal arsip baru setelah hari ini.
- **Dampak:** `ChangeArchiveDate`, `UnarchiveStudent`, layar Arsip otomatis (B5).
- **Status:** sementara — bisa diubah user.

### D-59 · Daftar surat atas nama Kepala Sekolah · 1 Okt 2026
- **Keputusan:** Layar "Surat atas nama saya" menampilkan semua surat tugas yang terbit sejak penggantian Kepala Sekolah terakhir yang diterapkan ke akun itu (semua surat bila belum pernah ada penggantian), termasuk yang dicabut (chip "Dicabut"). Setiap pencabutan surat memberi tahu Kepala Sekolah (D-49).
- **Dampak:** `Kepsek\SuratSaya`, `LetterRevokedNotice` (B5); SECURITY §3m-5.
- **Status:** sementara — bisa diubah user.

### D-60 · Template surat oleh Tata Usaha · 1 Okt 2026
- **Keputusan:** TU mengubah teks tetap surat (dasar, pembuka, penutup, kop, jabatan penanda tangan, kota) di layar **Template surat** dengan penanda `{sekolah}`, `{penyelenggara}`, `{tahun_ajaran}`; disimpan sebagai JSON di setting `letter_template`, berversi di log audit, Admin diberi tahu. Format nomor surat tetap hanya bisa diubah Admin (D-06).
- **Dampak:** `LetterTemplate`, `UpdateLetterTemplate`, `TataUsaha\TemplateSurat` (B5). Template resmi sekolah tetap pertanyaan terbuka (§F-2).
- **Status:** sementara — bisa diubah user.

## D4. Keputusan integrasi W4 (1 Okt 2026)

Dicatat oleh D1 dari `docs/dev-notes/W4.md` dan `B2.md`. Pilihan produk berstatus **sementara — bisa diubah user**;
pengerasan keamanan berstatus **final** (mengubahnya butuh entri baru dan alasan keamanan).

### D-61 · Akun staf baru berperan penting · 1 Okt 2026
- **Keputusan:** "Tambah pengguna" dengan peran **Admin, Kesiswaan, atau Tata Usaha** membuat akun **tanpa peran** dan, dalam transaksi yang sama, permintaan perubahan peran (alasan "Peran awal akun staf baru."). Aturannya sama dengan D-46: Admin kedua menyetujui; bila pembuat adalah satu-satunya Admin, peran berlaku **24 jam** kemudian (Kepala Sekolah diberi tahu, Admin mana pun bisa membatalkan). Permintaan Admin yang menunggu ikut dihitung dalam batas 3 Admin (D-41); bila permintaan ditolak sistem (mis. batas tercapai), akun juga tidak jadi dibuat. Guru dan Humas langsung mendapat perannya. Kepala Sekolah tetap hanya lewat "Ganti Kepala Sekolah" (D-40).
- **Alasan:** Tanpa aturan ini, membuat akun baru adalah jalan pintas melewati persetujuan Admin kedua untuk peran penting (SECURITY §3h maker-checker).
- **Dampak:** `CreateStaffAccount` memanggil `RequestRoleChange::open()`; satu pemberitahuan per penerima (`RoleChangeNotice`, teks akun baru). Selama menunggu, akun hanya bisa aktivasi dan membuka halaman akunnya; `/beranda` menulis "Akunmu belum punya peran. …". Daftar Pengguna & peran menampilkan "Tanpa peran" + "Menunggu: <peran>". Audit `account.staff_created`: `role` (null untuk peran penting), `requested_role`, `username`. Peran dijalankan oleh jadwal `sth:peran-tertunda` (tiap 5 menit).
- **Status:** sementara — bisa diubah user.

### D-62 · Mode uji OTP hanya dari environment · 1 Okt 2026
- **Keputusan:** Mode uji OTP (kode 6 digit apa pun diterima) hanya aktif bila `APP_ENV=local` **dan** `STH_OTP_TEST_MODE=true`. Tidak ada setting untuk itu: `SettingKey::OtpTestMode` dihapus dan migrasi W4 menghapus baris `otp_test_mode` yang tidak pernah dibaca. Admin › Pengaturan › Email dan Status sistem hanya menampilkan statusnya.
- **Alasan:** Saklar di database bisa dinyalakan dari aplikasi; flag env tidak, dan aplikasi menolak boot bila flag menyala di luar `local` (D-15, SECURITY §3b). Nama flag yang berlaku adalah `STH_OTP_TEST_MODE` (D-15 masih menulis `STH_OTP_DEV_BYPASS`).
- **Dampak:** `VerifyOtpCode::testModeActive()`, migrasi `2026_10_01_600000_w4_remove_otp_test_mode_setting.php`.
- **Status:** final.

### D-63 · Segel hash chain audit · 1 Okt 2026
- **Keputusan:** Hash chain audit disimpan di tabel samping `audit_seals` (satu segel per baris `activity_log`, SHA-256 biasa atas hash sebelumnya + isi baris) dan `audit_chain_heads` (satu baris kepala rantai). Penyegelan berjalan **setelah commit** dalam transaksi pendek sendiri, jadi tidak ada kunci global di transaksi bisnis dan baris yang di-rollback tidak pernah disegel. `sth:audit:verify` harian; `sth:audit:seal` tiap 10 menit sebagai jaring pengaman.
- **Alasan:** Deteksi perubahan sama kuatnya dengan kolom di `activity_log`, sementara `activity_log` tetap INSERT/SELECT saja (D-51).
- **Dampak:** `App\Support\Audit\AuditChain` (B2). Menggantikan bagian hash chain D-56 (isi sama, status final). HMAC dengan kunci terpisah tetap peningkatan yang mungkin (backlog #34).
- **Status:** final.

### D-64 · Berkas import antre dihapus · 1 Okt 2026
- **Keputusan:** Berkas spreadsheet import antre (berisi tanggal lahir) dihapus saat job selesai, berhasil atau gagal. SHA-256 berkas tetap tercatat di `student_imports` dan log audit. SECURITY §3f ("hapus setelah 7 hari") tidak berlaku lagi untuk import ini.
- **Alasan:** Minimisasi data anak (UU PDP); setelah import selesai berkas tidak dibutuhkan lagi.
- **Dampak:** `ImportStudentsJob` (B2). Menetapkan bagian berkas D-55 sebagai final; batas 50 baris dan potongan 100 baris tetap sementara (D-55).
- **Status:** final.

### D-65 · CSP tanpa `'unsafe-eval'` · 1 Okt 2026
- **Keputusan:** `'unsafe-eval'` dihapus permanen dari Content-Security-Policy semua halaman, tidak bergantung pada konfigurasi lain. Livewire memakai build CSP-safe (`config/livewire.php` `csp_safe => true`).
- **Alasan:** `'unsafe-eval'` membuka jalan eksekusi skrip bila ada celah XSS; semua ekspresi `wire:*` yang ada sudah CSP-safe.
- **Dampak:** `SecurityHeaders` tidak pernah mengirim `'unsafe-eval'`; `tests/Feature/Security/CspSafeTest.php` memindai setiap view. Layar baru wajib memakai ekspresi sederhana (panggilan method dengan argumen literal). Menggantikan bagian CSP D-56.
- **Status:** final.

## E. Usulan keamanan yang belum diputuskan

| ID | Usulan | Alasan singkat | Status |
|----|--------|----------------|--------|
| U-01 | **Ubuntu Pro (ESM)** di host (harga daftar $500/server fisik/tahun; versi gratis hanya untuk personal use ≤ 5 mesin) | Sejak 9 Mar 2023, apache2 bionic hanya dipatch di build `+esm` (terbaru `+esm11`, USN-8589-1, Jul 2026). Kernel 4.15 sama (CVE-2024-1086, CISA KEV) | menunggu jawaban sekolah (anggaran) |
| U-02 | Ganti Docker apt 24.0.2 dengan **static binaries Docker Engine 29.8.1** (containerd 2.3.5, runc 1.5.1) + unit systemd + update bulanan | runc di 24.0.2 rentan container escape (CVE-2024-21626, trio 2025) dan tidak akan dipatch | sementara (perlu uji staging + persetujuan petugas deploy) |
| U-03 | **2FA TOTP wajib** untuk Admin, Kepala Sekolah, TU, Kesiswaan + satu akun break-glass tersegel | Akun berhak tinggi bisa menerbitkan surat bertanda tangan Kepsek dan mengganti akun Kepsek | menunggu jawaban sekolah (HP staf, tempat brankas) |
| U-04 | Nama/foto/sertifikat publik hanya dengan flag persetujuan orang tua. Gambar sertifikat publik diganti badge "Terverifikasi" | Sertifikat sering memuat NISN/TTL. UU PDP Pasal 25 | menunggu jawaban sekolah |
| U-05 | QR + kode verifikasi di setiap surat tugas | Gambar ttd+stempel mudah disalin, jadi keaslian harus bisa dicek | sementara (menunggu template final) |
| U-06 | Isolasi dari situs utama: user Unix terpisah, izin 750/600, tanpa grup docker untuk `www-data`, DB terpisah, php-fpm untuk WordPress | Kompromi situs utama di host yang sama adalah jalur pivot paling mungkin | menunggu jawaban sekolah (pengelola situs utama) |

## F. Pertanyaan terbuka

1. ~~Google Form "Pembinaan Talenta"~~ **Terjawab 30 Sep 2026**: struktur lengkap di `TALENT-FORM.md`, keputusan D-21 sampai D-23.
2. **Template surat tugas** masih dikonfirmasi sekolah: kolom apa saja yang ada di surat siswa (NIS/NISN? kelas?), dan apakah surat tim memuat semua anggota dalam satu tabel?
3. **Format nomor surat:** apa arti "MRD" pada `400.3.8/<no>/MRD/<bulan romawi>/<tahun>`? Apakah nomor urut direset per tahun, dan apakah buku agenda siswa dan guru terpisah?
4. **Foto siswa di halaman publik:** bolehkah ditampilkan, dan bagaimana proses persetujuan orang tua (formulir daftar ulang? untuk siswa yang sudah ada?). Hal yang sama berlaku untuk nama dan gambar sertifikat (U-04).
5. **Tenggat proyek & ketersediaan tim:** kapan target go-live, siapa saja anggota tim (developer, petugas deploy, Admin sekolah), dan berapa jam per minggu masing-masing tersedia?
6. Pertanyaan keamanan lanjutan (situs utama WordPress, Ubuntu Pro, akses server, DNS, 2FA staf, backup, DPO, akun arsip) ada di `SECURITY.md` §5.
