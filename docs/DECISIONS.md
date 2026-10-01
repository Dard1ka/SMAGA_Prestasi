# DECISIONS.md — Log keputusan SMAGA Talent Hub (STH)

> Log keputusan produk & teknis STH untuk SMA Negeri 3 Semarang. Dirujuk dari `CLAUDE.md` proyek.
> Diperbarui: **1 Oktober 2026** (D-32…D-44) · Sumber: keputusan pengguna 30 Sep 2026, keputusan lead 1 Okt 2026, `DESIGN-SPEC-v2.md` §1–§5, `docs/SECURITY.md`.
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
