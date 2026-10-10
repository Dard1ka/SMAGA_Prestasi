# DECISIONS.md — Log keputusan SMAGA Talent Hub (STH)

> Log keputusan produk & teknis STH untuk SMA Negeri 3 Semarang. Dirujuk dari `CLAUDE.md` proyek.
> Diperbarui: **10 Oktober 2026** (D-32…D-44; backlog D-45…D-60; integrasi W4 D-61…D-65; uji menyeluruh Q1 D-66…D-77; uji manual lead D-78…D-91; perbaikan dari uji QA D-92…D-102; permintaan lead 6 Okt dan QA4 D-103…D-108; uji ulang M3 / QA5 D-109…D-112; eksplorasi ulang QA6 D-113…D-116; arah visual v3 dan lambang D-117…D-120; email nyata saat pengembangan D-121; uji email dan notifikasi lead 9 Okt D-122…D-129) · Sumber: keputusan pengguna 30 Sep 2026, keputusan lead 1, 2, 4, 6, 7, 8, dan 10 Okt 2026, laporan lead "Hasil uji email dan notifikasi STH" (9 Okt 2026), dokumen klien "SMAGA TALENT HUB (STH)", `DESIGN-SPEC-v2.md` §1–§5, `DESIGN-SPEC-v3.md`, `docs/SECURITY.md`.
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
| D-06 | Surat tugas: nomor manual TU, PDF, satu gambar ttd+stempel, satu surat per tim | Pengguna | sementara (format nomor: diganti D-82; template: D-84) |
| D-07 | Batas laporan hasil 30 hari setelah lomba (bisa diubah Admin) | Pengguna | final |
| D-08 | Rumus poin, bobot, poin penuh untuk tiap anggota tim | Pengguna / spec v2 | final |
| D-09 | Peringkat kumulatif semua tahun | Pengguna | final (cara menampilkan alumni: diganti D-80) |
| D-10 | Tracker Jejak Prestasi 6 fase | Spec v2 | final |
| D-11 | Empat bidang | Spec v2 | final |
| D-12 | Kolom import Excel siswa | Pengguna | final (siswa yang sudah terdaftar: diganti D-81) |
| D-13 | Arsip otomatis 18 bulan setelah mulai kelas XII | Pengguna | final |
| D-14 | Login NIS/NISN + password berpola, tanpa Kode Aktivasi | Pengguna | final (risiko diterima; urutan aktivasi: diganti D-79) |
| D-15 | OTP email ditunda; dev menerima 6 digit apa pun | Pengguna | sementara |
| D-16 | Halaman publik tanpa login | Pengguna / spec v2 | final (syarat persetujuan: menunggu jawaban sekolah) |
| D-17 | Stack Laravel 13 + PHP 8.4 + MySQL 8.4 LTS di Docker | Pengguna | final |
| D-18 | Subdomain `prestasi.sman3-smg.sch.id` di belakang Apache 2.4.29 host | Pengguna | final |
| D-19 | Arah visual v2 dan gaya bahasa | Spec v2 | digantikan untuk tampilan (D-117); gaya bahasa tetap (pilihan kata: D-89; sapaan staf: D-119) |
| D-20 | Mockup hanya memakai data fiktif | Spec v2 | final |
| D-21 | Penjaringan talenta pindah dari Google Form ke STH (detail: `TALENT-FORM.md`) | Pengguna | final |
| D-22 | 6 jenis talenta dipetakan ke 4 bidang: OSN & OLPAIN → Sains, Riset → Riset, FLS3N & Debat → Seni Budaya, O2SN → Olahraga | Pengguna | final |
| D-23 | Siswa boleh memilih lebih dari satu jenis talenta; semua kelas aktif (termasuk XII) boleh ikut penjaringan | Pengguna | final |
| D-24 | Password awal = `sm4g4_DDMMYY` dari tanggal lahir (contoh 2 Maret 2007 → `sm4g4_020307`); pola tidak pernah ditampilkan di UI | Pengguna (workflow kasar.svg) | final (menggantikan format DD-MM-YYYY) |
| D-25 | Batas laporan hasil default **45 hari (1,5 bulan) setelah tanggal final lomba**, bisa diubah Admin | Pengguna (workflow kasar.svg) | final (menggantikan 30 hari) |
| D-26 | Import data siswa boleh dilakukan **Kesiswaan dan Admin** | Pengguna | final (import ulang tahunan: lihat D-81) |
| D-27 | **Semua** upload info lomba (siswa, guru, staf, termasuk Kesiswaan sendiri) masuk antrean verifikasi Kesiswaan dulu; baru tampil di katalog siswa setelah diverifikasi; verifikator & waktu tercatat di audit log | Pengguna | final (6 Okt 2026: alasan menolak lomba kini opsional, lihat D-103) |
| D-28 | Data lomba punya field **link registrasi** resmi penyelenggara (selain juknis/brosur/poster/website) | workflow kasar.svg | final |
| D-29 | Alumni (diarsipkan 18 bulan setelah mulai kelas XII) **tidak bisa login**; nama & prestasinya tetap tampil di ranking/halaman publik | workflow kasar.svg | final (menggantikan "read-only") |
| D-30 | Admin bisa menonaktifkan/mengarsipkan akun manual (pindah sekolah, meninggal, dsb.) selain arsip otomatis | workflow kasar.svg | final |
| D-31 | Halaman publik juga punya tampilan **pemenang per lomba** (klik lomba → daftar pemenang & capaian) | workflow kasar.svg | final |
| D-32 | Password baru siswa **minimal 10 karakter** (SECURITY K5) | Lead (1 Okt 2026) | final (tambahan aturan kata sandi: D-94) |
| D-33 | Ketua boleh membatalkan pendaftaran selama `menunggu_konfirmasi` **atau** `menunggu_kepsek` | Lead (1 Okt 2026) | final |
| D-34 | Laporan hasil yang **ditolak** mengakhiri pendaftaran sebagai `selesai` **tanpa poin** | Lead (1 Okt 2026) | final |
| D-35 | **Guru pembimbing wajib** dipilih saat mendaftar | Lead (1 Okt 2026) | final |
| D-36 | Anggota menolak undangan: tim lanjut bila tersisa **≥ 2** anggota yang sudah konfirmasi; selain itu ketua membatalkan lalu mendaftar ulang | Lead (1 Okt 2026) | final |
| D-37 | Peringkat pribadi ("peringkatmu") dihitung di antara **siswa aktif**; peringkat publik memuat alumni (ditandai "Alumni") | Lead (1 Okt 2026) | final (tombol "Tampilkan alumni": diganti D-80) |
| D-38 | Halaman publik menulis nama siswa hanya dengan `publish_consent` (selain itu "Siswa kelas X"); halaman publik `noindex` sampai sekolah memastikan proses izin orang tua | Lead (1 Okt 2026) | final (izin indeks: menunggu jawaban sekolah, U-04) |
| D-39 | Tautan sertifikat di halaman publik hanya dengan `publish_consent` **dan** saklar Admin | Lead (1 Okt 2026) | final |
| D-40 | Penggantian akun Kepala Sekolah berlaku **24 jam** kemudian (bisa dibatalkan), gambar ttd lama dilepas, data penandatangan dari akun baru | Lead (1 Okt 2026) | final |
| D-41 | Maksimal **3 akun Admin**; Admin pertama dibuat dengan `php artisan sth:create-admin` | Lead (1 Okt 2026) | final |
| D-42 | Staf login dengan **username atau email**, siswa dengan **NIS atau NISN** | Lead (1 Okt 2026) | final (siswa juga boleh email: D-78) |
| D-43 | Surat tugas: **Terbitkan** (nomor + PDF) dan **Kirim** adalah dua langkah; surat siswa mencetak NIS seperti template sekolah | Lead (1 Okt 2026) | final (terbit per surat: D-83; nomor: D-82) |
| D-44 | `users.id` tetap bigint; tabel domain memakai ULID | Lead (1 Okt 2026) | final |
| D-45 | Reset akun siswa oleh Admin memberi **kata sandi sementara acak, berlaku 72 jam** (K10), bukan pola kata sandi awal | Lead / B1 (1 Okt 2026) | sementara — bisa diubah user (siswa wajib mengganti kata sandi sementara itu: D-92) |
| D-46 | Pemberian peran staf penting (Admin, Kepala Sekolah, Kesiswaan, Tata Usaha) butuh **persetujuan Admin kedua**, atau berlaku **24 jam** kemudian (bisa dibatalkan) bila hanya ada satu Admin; penurunan peran langsung berlaku | Lead / B5 (1 Okt 2026) | sementara — bisa diubah user |
| D-47 | E-flyer Humas **tetap di disk privat**; publikasi terjadwal hanya mengubah status pada waktunya, memberi tahu siswa, dan mengingatkan Humas untuk mengunggah | Lead / B3 (1 Okt 2026) | sementara — bisa diubah user (e-flyer tidak lagi dibuat STH, tinggal berkas lama: D-107; jadwal kini hanya pengingat untuk Humas, status dan kabar ke siswa hanya lewat "Tandai dipublikasikan": lihat D-122, 10 Okt 2026) |
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
| D-60 | Template surat TU = teks tetap + penanda `{sekolah}`, `{penyelenggara}`, `{tahun_ajaran}`; format nomor surat tetap hanya Admin | B5 (1 Okt 2026) | sementara — bisa diubah user (format nomor kini hanya contoh: D-82) |
| D-61 | Akun staf baru dengan peran Admin, Kesiswaan, atau Tata Usaha dibuat **tanpa peran** + permintaan peran: **persetujuan Admin kedua**, atau **24 jam** (bisa dibatalkan) bila hanya ada satu Admin; Kepala Sekolah hanya lewat "Ganti Kepala Sekolah" | Lead / W4 (1 Okt 2026) | sementara — bisa diubah user |
| D-62 | Mode uji OTP **hanya dari environment** (`APP_ENV=local` **dan** `STH_OTP_TEST_MODE=true`); setting `otp_test_mode` dihapus | W4 (1 Okt 2026) | final |
| D-63 | Segel hash chain audit di tabel samping `audit_seals` + `audit_chain_heads`, ditulis **setelah commit** (SHA-256 biasa) | B2 / W4 (1 Okt 2026) | final (menggantikan bagian hash chain D-56) |
| D-64 | Berkas spreadsheet import antre **dihapus saat job selesai** (SHA-256 tetap tercatat), bukan disimpan 7 hari | B2 / W4 (1 Okt 2026) | final (menetapkan bagian berkas D-55) |
| D-65 | `'unsafe-eval'` **dihapus permanen** dari CSP (`livewire.csp_safe = true`) | B2 / W4 (1 Okt 2026) | final (menggantikan bagian CSP D-56) |
| D-66 | Verifikasi dua langkah (TOTP) **hanya untuk staf**; reset akun siswa ikut menghapus TOTP | Q1 (2 Okt 2026) | final |
| D-67 | Konfirmasi kata sandi dibatasi **5 percobaan per menit per akun**; peristiwa 2FA masuk log audit | Q1 (2 Okt 2026) | final |
| D-68 | Akun beku tidak menerima email dan kode reset; alamat yang belum diverifikasi hanya menerima pemberitahuan akun | Q1 (2 Okt 2026) | final |
| D-69 | Teks dari pengguna di email selalu teks polos (`PlainMailMessage`) | Q1 (2 Okt 2026) | final |
| D-70 | Pemindai PDF menolak berkas yang tidak bisa dibaca penuh | Q1 (2 Okt 2026) | final |
| D-71 | Izin publikasi yang dicabut berlaku juga di layar Humas (nama netral, e-flyer tertutup) | Q1 (2 Okt 2026) | final (bagian Admin: diganti D-75; bagian e-flyer tinggal untuk berkas lama: D-107) |
| D-72 | Daftar panjang ditampilkan bertahap ("Tampilkan lebih banyak") | Q1 (2 Okt 2026) | sementara — bisa diubah user |
| D-73 | "Tambah lomba" memberi tahu verifikator lain | Q1 (2 Okt 2026) | sementara — bisa diubah user |
| D-74 | Gzip untuk skrip Livewire di nginx produksi; baris cache kedaluwarsa dihapus harian | Q1 (2 Okt 2026) | final |
| D-75 | Admin tetap bisa mengunduh e-flyer setelah izin publikasi dicabut; Humas tidak | Lead (2 Okt 2026) | final (menggantikan bagian Admin D-71; tinggal untuk berkas flyer lama: D-107) |
| D-76 | Nama lomba tidak boleh berisi alamat web | Lead (2 Okt 2026) | final |
| D-77 | Guru pembimbing dan Kepala Sekolah dikabari saat pendaftaran ditolak atau dibatalkan | Lead (2 Okt 2026) | final |
| D-78 | Siswa boleh masuk dengan **email** setelah aktivasi selesai (selain NIS/NISN) | Lead (4 Okt 2026) | final (menggantikan bagian siswa D-42) |
| D-79 | Urutan aktivasi **email → kode → kata sandi**; ganti kata sandi **opsional untuk siswa**, wajib untuk staf | Lead (4 Okt 2026) | final (risiko diterima; menggantikan urutan D-14 dan K4; setelah reset Admin siswa wajib ganti: D-92) |
| D-80 | Peringkat publik: pilihan **Siswa aktif / Alumni / Semua**; alumni ditulis "Alumni" tanpa kelas terakhir | Lead (4 Okt 2026) | final (menggantikan tombol "Tampilkan alumni" D-09/D-37) |
| D-81 | Import siswa tahunan **memperbarui kelas** siswa yang sudah terdaftar | Lead (4 Okt 2026) | final (menggantikan "NIS terdaftar = ditolak" D-12/D-26); berkas tahunan vs tanggal arsip manual dan siswa yang tidak ada di berkas: sementara — perlu dikonfirmasi user |
| D-82 | Nomor surat **diketik lengkap oleh TU**, unik per jenis surat; format di Admin hanya contoh | Lead (4 Okt 2026) | final (menggantikan format `{seq}` D-06/D-43) |
| D-83 | Satu surat satu form dan satu "Buat PDF"; "Kirim" sekali setelah semua surat yang wajib ada | Lead (4 Okt 2026) | final |
| D-84 | Template surat = berkas asli sekolah; lambang Pemprov dan sekolah di repo; QR tetap; tombol "Cetak" | Lead (4 Okt 2026) | final |
| D-85 | Talenta: dua catatan opsional (untuk guru pembina, untuk siswa); "tidak lolos" tidak wajib bercatatan | Lead (4 Okt 2026) | final ("Simpan" kini menyimpan draf catatan untuk siswa: D-96) |
| D-86 | Talenta "tidak lolos" bisa **dipulihkan**; siswa bisa "Minta ditinjau ulang"; tidak ada pengajuan kedua | Lead (4 Okt 2026) | final (pulihkan tanpa permintaan siswa: sementara — bisa diubah user; tahap tujuan kini dipilih Kesiswaan: D-104) |
| D-87 | Acara apresiasi: konfirmasi di acara, hapus acara (siswa diberi tahu), acara terkonfirmasi terkunci | Lead (4 Okt 2026) | final (satu prestasi satu acara, "Lepas dari acara": D-97) |
| D-88 | Status aktivasi: "Lihat" membuka popup kelas | Lead (4 Okt 2026) | final |
| D-89 | Kata sehari-hari di UI: upload, download, link, preview | Lead (4 Okt 2026) | final (menyesuaikan gaya bahasa D-19) |
| D-90 | Angka menu berubah tanpa muat ulang; kotak cari atas menyaring saat diketik; satu kotak cari per layar | Lead (4 Okt 2026) | final (angka tampil di semua halaman: D-100) |
| D-91 | Kecepatan lokal: cache config + view khusus php-fpm di filesystem container; tes menolak jalan bila config ter-cache | Lead / M1 (4 Okt 2026) | final |
| D-92 | Setelah reset Admin, siswa **wajib membuat kata sandi baru** (tanpa "Lewati"); kata sandi sekolah dari import tetap boleh dipertahankan | Lead / QA (6 Okt 2026) | final (menggantikan kalimat terakhir D-79) |
| D-93 | Halaman kredensial **tidak disimpan browser** (`no-store`), kolom kata sandi dikosongkan; **sesi lama dihapus** saat sesi diperbarui | Lead / QA (6 Okt 2026) | final |
| D-94 | Kata sandi baru menolak **kata sandi umum**, nama pengguna, dan nama email | Lead / QA (6 Okt 2026) | final (menambah K5 / D-32) |
| D-95 | "Buka ulang" **tidak pernah memendekkan** batas aktivasi; alasan "Buka ulang" dan alasan menolak lomba minimal **10 karakter** | Lead / QA (6 Okt 2026) | final (menyesuaikan K2; alasan menolak lomba kini opsional: D-103) |
| D-96 | Talenta: catatan untuk siswa tersimpan sebagai **draf** sampai ada keputusan; "Tidak lolos" minta konfirmasi | Lead / QA (6 Okt 2026) | final (menyesuaikan D-85; aksi cepat ikut mengirim draf: sementara — bisa diubah user) |
| D-97 | Satu prestasi hanya di **satu acara apresiasi**; tombol "Lepas dari acara" | Lead / QA (6 Okt 2026) | final (melengkapi D-87) |
| D-98 | Tanggal lomba: mulai tidak boleh sebelum batas daftar; tanggal yang sudah lewat perlu **konfirmasi** untuk dipublikasikan | Lead / QA (6 Okt 2026) | final |
| D-99 | Siswa tidak men-download surat guru (hanya keterangan); kartu surat siswa hanya memuat surat yang **sudah dikirim** | Lead / QA (6 Okt 2026) | final (siswa tidak boleh membuka surat guru: sementara — bisa diubah user) |
| D-100 | Angka menu samping dihitung di **satu tempat** dan tampil di semua halaman | Lead / QA (6 Okt 2026) | final (melengkapi D-90) |
| D-101 | Halaman error milik aplikasi; `GET /logout` dialihkan; halaman pertama setelah login yang 403/404 → beranda | Lead / QA (6 Okt 2026) | final |
| D-102 | Setelah Keluar: kolom **nama pengguna ikut dikosongkan** saat Back, dan akun **lain** yang masuk berikutnya tidak diarahkan ke halaman akun sebelumnya | Lead / QA (6 Okt 2026) | final (melengkapi D-93 dan D-101) |
| D-103 | Alasan penolakan lomba **opsional**, tanpa panjang minimal | Lead (6 Okt 2026) | final (menggantikan "catatan wajib" untuk penolakan lomba dan bagian lomba dari D-95) |
| D-104 | Talenta: "Pulihkan" ke **tahap yang dipilih**; "Download data" dengan **pilihan status** | Lead (6 Okt 2026) | final (menyesuaikan D-86; beberapa rincian sementara) |
| D-105 | "Buka" berkas tampil di **popup** (PDF/gambar), tombol Download tetap ada | Lead (6 Okt 2026) | final (menyesuaikan aturan "selalu attachment"; tiga pertanyaan terbuka) |
| D-106 | Berkas demo = **dokumen contoh yang realistis** (lokal saja, bertanda CONTOH); perintah `sth:demo:refresh-files` | Lead (6 Okt 2026) | final |
| D-107 | STH **tidak membuat e-flyer**; Humas mendapat "Data untuk flyer" + "Salin data" | Lead (6 Okt 2026) | final (mempersempit D-47 dan bagian flyer D-71 / D-75 ke berkas lama) |
| D-108 | `letters.printed`: preview surat yang sudah terbit = isi PDF-nya | S / QA4 (6 Okt 2026) | final (dikerjakan S di PR 12) |
| D-109 | "Pulihkan" ke Peserta pembinaan wajib memilih guru pembina | Lead (7 Okt 2026) | final |
| D-110 | Guru pembimbing diberi tahu keputusan laporan dan jadwal apresiasi binaannya | Lead + dokumen klien (7 Okt 2026) | final (notifikasi "dijadwalkan" kini satu per sekali "Masukkan": lihat D-124, 10 Okt 2026) |
| D-111 | "Download data" Talenta mengikuti filter layar; status kosong tidak bisa dipilih | Lead (7 Okt 2026) | final |
| D-112 | Surat tugas di Guru › Siswa binaan terbuka di popup | Dokumen klien, UX diserahkan lead (7 Okt 2026) | final (layar guru) |
| D-113 | Surat yang sudah terbit sah di halaman verifikasi QR, juga sebelum "Kirim" | Dokumen klien, temuan QA Q1-23 (7 Okt 2026) | sementara (lead bisa memilih "tanpa Cetak sebelum Kirim") |
| D-114 | Jadwal asesmen yang baru diisi atau diubah tidak boleh sebelum hari ini | Temuan QA Q2-13, UX diserahkan lead (7 Okt 2026) | final |
| D-115 | Ketua tim bisa membatalkan undangan yang belum dijawab | Temuan QA Q1-22, UX diserahkan lead (7 Okt 2026) | final |
| D-116 | Surat yang belum dibuat mengambil isian undangan dan pelaksanaan dari surat yang lain, kecuali isian yang diketik TU sendiri | Temuan QA Q1-15, UX diserahkan lead (7 Okt 2026) | final |
| D-117 | Arah visual v3 **"Tinta dan Sampul"**: kerangka tinta dongker, satu huruf (Public Sans), skala huruf tetap, gambar yang punya arti | Audit UI 6 Okt 2026 + pilihan lead (6 dan 7 Okt 2026) | final (menggantikan tampilan D-19 / spec v2) |
| D-118 | Sampul lomba = poster asli atau gambar bidang; rute `files.cover` untuk menayangkan poster | Lead (6 Okt 2026) + audit UI | final untuk urutan sampul; sementara untuk aturan rute (tanpa catatan audit, boleh disimpan browser) dan "poster hanya setelah login" |
| D-119 | Staf tidak disapa "kamu"; satu nama untuk tiap menu; tanpa kode internal di layar | Audit UI + lead (6 Okt 2026: "sapaan ikut saranmu") | final (melengkapi D-19 dan D-89) |
| D-120 | Lambang SMA Negeri 3 Semarang menjadi tanda merek STH di layar aplikasi (menggantikan ikon perisai) | Lead, 7 Okt 2026 | final (menyesuaikan D-20 dan kalimat terakhir D-84 untuk layar aplikasi) |
| D-121 | Email nyata saat pengembangan lewat Brevo dengan pengirim sementara; stack lokal hanya mengirim keluar ke alamat di `STH_MAIL_ALLOWLIST`, sisanya tetap di Mailpit | Lead, 8 Okt 2026 | sementara (hanya pengembangan; penyedia produksi masih terbuka) |
| D-122 | Jadwal publikasi **hanya pengingat untuk Humas**; status menjadi dipublikasikan dan siswa diberi tahu hanya saat Humas menekan "Tandai dipublikasikan" | Lead, uji email 9 Okt 2026 (langkah 5-6) | final (menggantikan bagian "dipublikasikan otomatis + siswa diberi tahu" dari D-47) |
| D-123 | Acara apresiasi punya **jam mulai**; jam ikut tertulis di notifikasi siswa dan guru | Lead, uji email 9 Okt 2026 (langkah 6-1) | final (melengkapi D-87) |
| D-124 | "Tambah prestasi": **cari dan centang beberapa**; satu "Masukkan" mengirim guru pembimbing satu notifikasi | Lead, uji email 9 Okt 2026 (langkah 6-1: pencarian); centang beberapa dan satu notifikasi guru: pilihan koordinator | final untuk pencarian dan pilih beberapa; sementara untuk "satu notifikasi guru per sekali Masukkan" (menyesuaikan D-110) |
| D-125 | "Jenis penyelenggara: Lainnya" punya **isian manual** | Lead, uji email 9 Okt 2026 (langkah 2-2) | final (wajib sebelum publikasi: disetujui lead 10 Okt 2026; format "Lainnya (…)": sementara, pilihan koordinator) |
| D-126 | Perubahan data lomba yang sudah dipublikasikan diberitahukan ke **siswa yang terdaftar dan guru pembimbingnya**, tidak ke semua siswa | Pertanyaan lead di uji email 9 Okt 2026 (langkah 10-7); aturannya pilihan koordinator | tidak ke semua siswa: final (lead 10 Okt 2026); daftar data, cakupan status, batas 50: sementara |
| D-127 | Lonceng dan angka menu samping **mengikuti keadaan tanpa memuat ulang halaman** | Lead, uji email 9 Okt 2026 (langkah 1-3) | final (jeda 60 detik dan saklar `STH_COUNTS_POLL_SECONDS`: sementara; melengkapi D-90 dan D-100) |
| D-128 | "Tolak" laporan hasil **bertanya dulu**; pertanyaan sebelum sebuah aksi selalu dialog aplikasi, tidak pernah kotak konfirmasi browser, dan aksi satu klik serta aksi massal yang disisir di EM2 mendapat dialog itu | Lead, uji email 9 Okt 2026 (langkah 5-7 dan 10-6); dialog lain: pilihan koordinator | final ("Tolak" laporan: permintaan lead; dialog lain yang ikut disapu: disetujui lead 10 Okt 2026; yang sengaja tanpa pertanyaan tercantum di entrinya) |
| D-129 | Templat email STH: bagian teks tanpa sisa Markdown, satu aturan sapaan, tombol sebagai satu-satunya link, tampilan "Tinta dan Sampul" | Uji email lead 9 Okt 2026 (email mentah di langkah 11-2); rinciannya pilihan koordinator | disetujui lead 10 Okt 2026 dari uraiannya (email nyata belum dilihat, rincian tampilan masih bisa disesuaikan; melengkapi D-117 dan D-119; D-69 dan D-120 tetap) |
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
- **Status:** sementara. Template surat tugas masih dikonfirmasi sekolah, dan arti "MRD" belum diketahui. *(4 Okt 2026: format nomor dan keunikan per tahun digantikan D-82; pengisian per surat D-83; template D-84.)*

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
- **Status:** final. *(4 Okt 2026: cara menampilkan alumni di peringkat publik digantikan D-80.)*

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
- **Status:** final. *(4 Okt 2026: siswa yang sudah terdaftar tidak lagi ditolak, kelasnya diperbarui: D-81. Kolom "Kelas saat pendaftaran" kini bernama "Kelas".)*

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
- **Status:** final (risiko diterima). Formulir penerimaan risiko **menunggu tanda tangan Kepala Sekolah**. Pemicu tinjau ulang: ≥ 3 kasus pembajakan dalam satu semester. *(4 Okt 2026: urutan login pertama digantikan D-79, dan siswa juga boleh masuk dengan email: D-78. Formulir penerimaan risiko perlu memuat risiko tambahan D-79.)*

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
- **Status:** final (fase desain). *(4 Okt 2026: pilihan kata disesuaikan D-89: upload, download, link, preview.)*

### D-20 · Data fiktif di mockup · 30 Sep 2026
- **Keputusan:** Mockup **tidak pernah** memakai nama, NIS, atau NISN siswa/guru yang nyata. Logo memakai placeholder (bukan lambang sekolah; untuk tanda merek di layar aplikasi diganti D-120, 7 Okt 2026), tanpa foto nyata (cover bidang + avatar inisial), dan dashboard diberi chip "Data contoh".
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
- **Status:** final. *(4 Okt 2026: tombol "Tampilkan alumni" digantikan pilihan Siswa aktif / Alumni / Semua, D-80; peringkat pribadi tidak berubah.)*

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
- **Status:** final. *(4 Okt 2026: siswa yang sudah selesai aktivasi juga boleh masuk dengan email, D-78.)*

### D-43 · Surat tugas dua langkah · 1 Okt 2026
- **Keputusan:** TU **menerbitkan** surat (nomor + PDF, tidak bisa diubah) lalu **mengirim** dalam langkah terpisah; status `surat_terbit` saat dikirim. Surat siswa mencetak NIS sesuai template sekolah (menjawab §F-2 untuk kolom NIS).
- **Status:** final. *(4 Okt 2026: tiap surat diterbitkan sendiri, D-83; nomor diketik lengkap, D-82. Dua langkah Terbitkan → Kirim tetap.)*

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
- **Status:** sementara — bisa diubah user. (6 Okt 2026: STH tidak lagi membuat e-flyer, jadi kalimat "e-flyer tetap di disk privat" kini hanya menyangkut berkas flyer yang dibuat sebelum 6 Okt 2026, lihat D-107. Publikasi terjadwal dan pengingat untuk Humas tidak berubah.) (10 Okt 2026: bagian "mengubah status ke `dipublikasikan` (`published_at` = waktu jadwal), memberi tahu siswa" diganti D-122: jadwal hanya pengingat untuk Humas, dan hanya "Tandai dipublikasikan" yang mengubah status dan memberi tahu siswa. `AutoPublishPublication` dan job `AutoPublishPublications` di Dampak sudah dihapus; penggantinya `RemindDuePublication` dan `RemindDuePublications`. Pengingat untuk Humas dan "dilewati bila izin dicabut" tetap.)

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
- **Status:** sementara — bisa diubah user. *(4 Okt 2026: format nomor di Admin kini hanya contoh, D-82; template resmi sekolah sudah diputuskan, D-84.)*

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

## D5. Keputusan uji menyeluruh Q1 (2 Okt 2026)

Dicatat dari `docs/dev-notes/Q1.md` (skenario email end-to-end, pemeriksaan kerentanan, uji performa dengan data
seukuran sekolah). Pengerasan keamanan berstatus **final** (mengubahnya butuh entri baru dan alasan keamanan); pilihan
produk berstatus **sementara — bisa diubah user**.

### D-66 · Verifikasi dua langkah (TOTP) hanya untuk staf · 2 Okt 2026
- **Keputusan:** Akun siswa tidak pernah punya TOTP. Semua route pengelolaan 2FA Fortify (`two-factor.*`) dan `/akun/2fa` menjawab 404 untuk akun siswa; login siswa tidak pernah diarahkan ke `/two-factor-challenge`; "Reset kata sandi & email" (K10) juga menghapus TOTP. Akun staf baru yang perannya masih menunggu persetujuan (D-61) tetap boleh menyiapkan TOTP.
- **Alasan:** Route Fortify hanya meminta login + konfirmasi kata sandi. Orang yang tahu password awal siswa bisa memasang TOTP sebelum pemiliknya aktivasi, lalu pemilik terkunci di layar tantangan dan reset Admin tidak bisa membukanya (temuan HIGH-1).
- **Dampak:** `App\Http\Middleware\RestrictTwoFactorToStaff` (grup `web`), `FortifyServiceProvider` (pipeline login), `ResetStudentAccount` (audit `two_factor_removed`), `TwoFactorSetup`. Tes: `tests/Feature/Security/StudentTwoFactorTest.php`.
- **Status:** final.

### D-67 · Batas konfirmasi kata sandi dan audit 2FA · 2 Okt 2026
- **Keputusan:** `POST /user/confirm-password` dibatasi **5 percobaan per menit per akun** (dihitung sebelum kata sandi dicek; kata sandi benar menghapus hitungan). Saat batas tercapai tercatat `security.password_confirm_locked`. Peristiwa 2FA Fortify masuk log audit: `two_factor.enabled`, `two_factor.confirmed`, `two_factor.disabled`, `two_factor.recovery_codes_regenerated` (maks. 10 baris per akun per jenis per menit).
- **Alasan:** Konfirmasi kata sandi adalah gerbang semua aksi sensitif Admin dan pengelolaan 2FA, tetapi tidak punya pembatas; sesi staf yang dicuri bisa menebak kata sandi tanpa batas (temuan MEDIUM-3).
- **Dampak:** `FortifyServiceProvider::CONFIRM_PASSWORD_ATTEMPTS`, `App\Support\Audit\TwoFactorAuditSubscriber`, label di Admin › Log audit. Batas per jam belum ada (backlog #42).
- **Status:** final.

### D-68 · Email untuk akun beku dan alamat yang belum diverifikasi · 2 Okt 2026
- **Keputusan:** Akun yang dibekukan (K3, "Bukan saya?" K8) tidak menerima email apa pun dan tidak bisa meminta atau memakai kode reset kata sandi (jawaban tetap generik). Alamat email yang belum pernah diverifikasi (akun staf baru atau yang baru direset, diketik Admin) hanya menerima pemberitahuan akun (`AdminNotice`), bukan email kerja yang memuat nama siswa. Mode uji OTP lokal dianggap terverifikasi. Notifikasi dalam aplikasi tetap tersimpan.
- **Alasan:** Setelah "Bukan saya?", alamat yang terikat mungkin milik pembajak, tetapi masih menerima kode reset dan notifikasi prestasi (temuan skenario email). Salah ketik email staf oleh Admin mengirim nama siswa ke orang lain sampai akun diaktivasi.
- **Dampak:** `User::routeNotificationForMail()`, `RequestPasswordResetCode::eligibleUser()`. Tes: `tests/Feature/Security/MailRoutingTest.php`. Menjawab sebagian backlog #33 (K10 butir 4).
- **Status:** final.

### D-69 · Teks dari pengguna di email selalu teks polos · 2 Okt 2026
- **Keputusan:** Semua notifikasi email dibuat dengan `App\Notifications\Support\PlainMailMessage`: salam dan baris tidak pernah dibaca sebagai Markdown. `Markdown::withSecuredEncoding()` menyala sebagai lapisan kedua. Markup yang disengaja hanya lewat `HtmlString` yang tidak dibangun dari teks pengguna.
- **Alasan:** Nama lomba, nama orang, dan catatan staf masuk ke email. Nama lomba berisi sintaks tautan/gambar Markdown menghasilkan tautan dan gambar jarak jauh di email verifikator (temuan MEDIUM-2, SECURITY §3m-1). Saklar bawaan Laravel saja bisa dilewati dengan garis miring terbalik.
- **Dampak:** 22 notifikasi; `tests/Feature/Security/MailInjectionTest.php` menolak `new MailMessage` di `app/`. URL yang diketik sebagai teks tetap tampil sebagai teks (klien email bisa menautkannya sendiri): validasi nama lomba masih terbuka (backlog #38).
- **Status:** final. (10 Okt 2026: sejak D-129 email dirender dari templat milik aplikasi, yang mencetak sapaan dan kalimat sekali lagi lewat `PlainMailMessage::plain()`. Lapisan kedua `Markdown::withSecuredEncoding()` tidak bekerja pada view yang sudah dikompilasi lebih dulu oleh `view:cache`, jadi yang menjaga adalah `PlainMailMessage` dan templat itu; lihat D-129.)

### D-70 · Pemindai PDF menolak yang tidak bisa dibaca penuh · 2 Okt 2026
- **Keputusan:** Unggahan PDF dipindai oleh `App\Domain\Files\PdfScanner`: nama aksi aktif dicari di seluruh berkas dan di dalam object stream; object stream yang tidak bisa dibuka penuh (filter selain satu FlateDecode, `/DecodeParms`, gagal inflate, lebih dari 16 MB hasil inflate) membuat berkas **ditolak**; kata `stream` di dalam string/komentar dan objek di dalam badan stream juga ditolak.
- **Alasan:** Pemindai lama bisa dilewati dengan nama ber-escape `#xx`, kamus bersarang, tanpa `/Type`, filter ganda, dan lain-lain (temuan MEDIUM-1 dan perluasannya).
- **Dampak:** `StoreUpload::pdfHasActiveContent()` mendelegasikan ke `PdfScanner`. PDF buatan aplikasi (dompdf) dan 137 PDF contoh tetap diterima. Perlu dicoba dengan PDF asli sekolah sebelum rilis (backlog #43).
- **Status:** final.

### D-71 · Izin publikasi yang dicabut berlaku juga di layar Humas · 2 Okt 2026
- **Keputusan:** Setelah `publish_consent` dicabut, pengguna tanpa izin `student.view` (Humas) melihat label netral ("Siswa kelas XI", "Alumni") tanpa kelas di Arsip, Apresiasi, dan Jadwal, dengan chip "Izin dicabut"; pencarian nama tidak menemukan siswa itu; e-flyer tidak bisa diunduh lagi oleh siapa pun (404), termasuk pembuatnya dan Admin. Kesiswaan dan Admin tetap melihat nama.
- **Alasan:** Data yang sudah dipublikasikan harus hilang setelah izin dicabut (SECURITY §3i, §3m-7, UU PDP); sebelumnya Humas masih melihat nama dan bisa mengunduh flyer (temuan MEDIUM-4).
- **Dampak:** `AchievementFacts::visibleTo()`, `FilePolicy::viaPublication()`, `Humas\{Arsip,Apresiasi,Jadwal}`. Berkas flyer dan caption lama belum dihapus otomatis (backlog #39). Pemblokiran untuk Admin berstatus sementara.
- **Status:** final (bagian Admin: sementara — bisa diubah user). (6 Okt 2026: bagian e-flyer kini hanya menyangkut berkas flyer yang dibuat sebelum 6 Okt 2026; STH tidak lagi membuat e-flyer dan tidak ada layar yang menautkannya, lihat D-107. Aturan nama netral tidak berubah.)

### D-72 · Daftar panjang ditampilkan bertahap · 2 Okt 2026
- **Keputusan:** Daftar yang bisa tumbuh ditampilkan 20 baris per langkah dengan tombol "Tampilkan lebih banyak" (maks. 200): siswa di Publik › Prestasi per bidang, Kepala Sekolah › Prestasi per bidang, antrean Kepala Sekolah › Persetujuan ("Pilih semua" dan setujui massal hanya untuk baris yang tampil), dan tiap lajur papan Humas. Status aktivasi 40 baris per langkah. Kelola lomba maks. 250 dan Talenta maks. 300 baris; ekspor Talenta meminta konfirmasi kata sandi dan dibatasi 10.000 baris.
- **Alasan:** Dengan data seukuran sekolah (1.171 siswa, 1.420 prestasi) halaman-halaman itu menjadi 300–600 kB HTML; HTML sengaja tidak dikompresi (BREACH).
- **Dampak:** properti `$limit` yang dijepit di tiap komponen; `tests/Feature/Performance/ListLimitsTest.php`.
- **Status:** sementara — bisa diubah user (angka per langkah dan batas).

### D-73 · "Tambah lomba" memberi tahu verifikator lain · 2 Okt 2026
- **Keputusan:** Lomba yang dibuat lewat formulir lengkap (Kesiswaan/Admin) mengirim "Usulan lomba baru" ke semua pemegang `competition.verify` selain pembuatnya, sama seperti usulan siswa/guru.
- **Alasan:** Lomba itu tetap menunggu di antrean verifikasi (D-27), tetapi Kesiswaan tidak diberi tahu saat Admin yang menambahkannya (temuan skenario email; sketsa alur: unggah lomba → Kesiswaan diberi tahu).
- **Dampak:** `CreateCompetition`. Tes: `tests/Feature/Kesiswaan/CreateCompetitionNotifyTest.php`.
- **Status:** sementara — bisa diubah user.

### D-74 · Kompresi skrip Livewire dan pembersihan cache · 2 Okt 2026
- **Keputusan:** nginx produksi meng-gzip skrip Livewire (`/livewire-<hash>/livewire.min.js`, sekitar 300 kB → 100 kB); HTML dinamis tetap tidak dikompresi. Baris cache database yang kedaluwarsa dihapus harian oleh `App\Jobs\PruneExpiredCache`.
- **Alasan:** Skrip itu statis tanpa rahasia, jadi aman dikompresi, dan merupakan aset terbesar pada kunjungan pertama. Kunci cache halaman publik berversi tidak pernah dibaca lagi setelah di-flush, sehingga barisnya menumpuk.
- **Dampak:** `docker/nginx/prod.conf`, `routes/console.php` (03.10 WIB).
- **Status:** final.

### D-75 · Admin tetap bisa mengunduh e-flyer setelah izin dicabut · 2 Okt 2026
- **Keputusan:** Setelah `publish_consent` dicabut, file e-flyer tetap tersimpan (tidak dihapus otomatis). Humas tidak bisa mengunduhnya lagi; pemegang `student.view` yang juga punya `publication.manage` (Admin) tetap bisa, dan tautan flyer tetap tampil untuknya di Humas › Arsip. Menggantikan bagian Admin di D-71.
- **Alasan:** Pilihan user (2 Okt 2026): Admin perlu melihat apa yang sudah beredar untuk mengurus penurunan publikasi.
- **Dampak:** `FilePolicy::viaPublication()`, `Humas\Arsip`. Tes: `tests/Feature/Humas/ConsentWithdrawnTest.php`. Penghapusan file dalam 3×24 jam (SECURITY §3i) tetap pekerjaan manual (backlog #39).
- **Status:** final (keputusan user). (6 Okt 2026: kini hanya menyangkut berkas flyer yang dibuat sebelum 6 Okt 2026. Link flyer di Humas › Arsip sudah tidak ada; aturan `FilePolicy` tidak berubah, jadi berkas lama masih bisa dibuka lewat alamatnya oleh peran yang dulu boleh, lihat D-107.)

### D-76 · Nama lomba tidak boleh berisi alamat web · 2 Okt 2026
- **Keputusan:** Nama lomba yang memuat alamat web ditolak saat diisi, di usulan siswa/guru dan di formulir lengkap (buat dan ubah): skema (`https://…`), `www.…`, dan host dengan domain umum (`situs.com`, `lomba.sch.id`, `bit.ly/x`). Pesan: "Nama lomba tidak boleh berisi alamat web. Tulis alamatnya di kolom situs web." Singkatan bertitik ("Tk. Prov.", "Vol.2") tetap boleh.
- **Alasan:** Nama lomba tampil di email dari alamat sekolah. Aplikasi tidak menjadikannya tautan (D-69), tetapi klien email menautkan sendiri teks yang mirip alamat, sehingga usulan bisa dipakai untuk phishing (SECURITY §3m-1). Alamat yang sah punya kolom sendiri yang divalidasi `ExternalUrl`.
- **Dampak:** `App\Rules\NoWebAddress`, `CreateCompetition::rules()`, `SubmitCompetition`. Tes: `tests/Feature/Kesiswaan/LombaNameWebAddressTest.php`. Menutup backlog #38.
- **Status:** final (keputusan user). (10 Okt 2026: pesannya kini berakhir "Tulis alamatnya di kolom link." (`NoWebAddress::MESSAGE`); tidak ada formulir yang punya kolom bernama "situs web". Aturannya tidak berubah.)

### D-77 · Guru pembimbing dan Kepala Sekolah dikabari saat pendaftaran ditolak atau dibatalkan · 2 Okt 2026
- **Keputusan:** Saat Kepala Sekolah menolak pendaftaran, guru pembimbingnya ikut diberi tahu (dengan alasan). Saat ketua membatalkan pendaftaran, guru pembimbing diberi tahu; Kepala Sekolah diberi tahu hanya bila pendaftaran itu sudah berstatus `menunggu_kepsek` (ia sudah pernah diminta memutuskan). Pelaku tidak pernah dikabari. Tombol email guru "Pendaftaran binaan disetujui" kini membuka pendaftaran itu.
- **Alasan:** Permintaan user (2 Okt 2026); temuan skenario email: guru hanya mendengar persetujuan, dan Kepala Sekolah tidak tahu antreannya berubah.
- **Dampak:** `RejectRegistration`, `CancelRegistration`, `RegistrationRejected`, `RegistrationCancelled`, `RegistrationApproved`. Tes: `tests/Feature/Registrations/DecisionNotificationsTest.php`. Pengajuan talenta masih tidak memberi tahu siapa pun (backlog #40).
- **Status:** final (keputusan user).

## D6. Keputusan lead dari uji manual (4 Okt 2026)

Dicatat dari 18 permintaan lead setelah mencoba sendiri aplikasinya pada 4 Okt 2026 (ringkasan: `docs/dev-notes/M1.md`;
rincian: `M1-A.md`…`M1-E.md`). Semua berstatus **final (keputusan user)**, kecuali butir yang ditandai *sementara*.
Tiap entri menyebut keputusan lama yang digantinya; entri lama tidak dihapus.

### D-78 · Siswa boleh masuk pakai email setelah aktivasi · 4 Okt 2026
- **Keputusan:** Siswa yang **sudah selesai aktivasi** boleh masuk dengan **NIS, NISN, atau email** yang sudah dihubungkan. Sebelum aktivasi selesai, email siswa tidak dikenali (jawabannya sama dengan akun yang tidak ada). Staf tidak berubah: username atau email. Siswa tidak pernah masuk dengan username. "Lupa kata sandi" memakai aturan yang sama.
- **Alasan:** Permintaan user: "siswa juga bisa masuk menggunakan email jika sudah selesai aktivasi". Email lebih mudah diingat daripada NIS. Ini tidak membuka jalan masuk baru: email bukan rahasia, yang rahasia tetap kata sandi.
- **Dampak:** `App\Actions\Fortify\LoginIdentifier`: email dicocokkan ke siswa hanya bila `DetermineActivationStep::finished()` (status aktif, email terverifikasi, tidak wajib ganti kata sandi). Email yang cocok memakai jatah percobaan yang sama dengan NIS/NISN akun itu (`u:<id>`); email yang tidak cocok diperlakukan seperti akun tidak dikenal (pesan generik, hash dummy, K11). Siswa yang masuk dengan email tetap tidak lewat TOTP (D-66). Label kolom login: "NIS, NISN, atau email"; pesan salah tetap "NIS/NISN atau kata sandi salah."
- **Status:** final (keputusan user). Menggantikan bagian siswa di D-42.

### D-79 · Urutan aktivasi: email → kode → kata sandi; ganti kata sandi opsional untuk siswa · 4 Okt 2026
- **Keputusan:** Masuk pertama kali kini tiga langkah: **1 hubungkan email → 2 kode verifikasi 6 angka → 3 kata sandi**. Di langkah 3 **siswa boleh memilih**: "Ganti kata sandi" atau "Lewati, pakai kata sandi sekarang". **Staf wajib** mengganti kata sandi (tidak ada tombol lewati). Akun baru berstatus aktif setelah langkah 3. Akun yang direset Admin memakai layar yang sama; siswa yang direset juga boleh tetap memakai kata sandi sementaranya.
- **Alasan:** Permintaan user: "saat aktivasi harusnya hubungkan email dulu lalu minta kode verifikasi baru. Untuk ubah password nya optional jadi siswa bisa milih mau ganti password atau gak".
- **Dampak:** `DetermineActivationStep` (langkah `EMAIL` lalu `PASSWORD`), `CompleteActivation::bindEmail()` / `changePassword()` / `keepPassword()`, `Livewire\Auth\Activation`. Email "Bukan saya?" (K8) dikirim saat email terhubung (langkah 2), ketika akun masih menunggu aktivasi; langkah 3 tidak mengirim email. Kata sandi baru harus berbeda dari kata sandi yang sedang dipakai. Audit: `account.email_bound`, `account.activated` (`password_changed` ya/tidak), `activation.password_kept`.
  - **Risiko yang diterima:** siswa yang memilih "Lewati" tetap memakai kata sandi dari sekolah. Kata sandi itu bisa ditebak dari tanggal lahir **selama masih dipakai**; dulu celah ini tertutup saat aktivasi (paling lama jendela 14 hari). Deteksi K7 tidak bisa melihatnya, karena K7 hanya menyala bila pola awal *gagal* di akun aktif. Pengambilalihan juga bisa tidak ketahuan: orang yang aktivasi lebih dulu bisa menghubungkan emailnya sendiri lalu melewati langkah 3, dan pemilik asli tetap bisa masuk dengan kata sandi sekolah.
  - **Penyeimbang yang ada:** laporan Status aktivasi (K9: tiap siswa memeriksa email tersamar di barisnya) dan catatan audit `activation.password_kept`.
  - **Penyeimbang yang belum ada (backlog):** tanda "masih pakai kata sandi dari sekolah" di Status aktivasi (siswa aktif dengan `password_changed_at` kosong) dan pengingat untuk mengganti kata sandi.
- **Status:** final (keputusan user; risiko diterima). Menggantikan urutan login pertama di D-14 dan K4 (SECURITY §3a). Formulir penerimaan risiko D-14 perlu memuat risiko ini. (6 Okt 2026: kalimat "siswa yang direset juga boleh tetap memakai kata sandi sementaranya" tidak berlaku lagi; setelah reset Admin siswa wajib membuat kata sandi baru, lihat D-92. Kata sandi sekolah dari import tetap boleh dipertahankan.)

### D-80 · Peringkat publik: Siswa aktif / Alumni / Semua · 4 Okt 2026
- **Keputusan:** Peringkat publik punya tiga pilihan: **Siswa aktif** (bawaan), **Alumni**, **Semua**. "Alumni" hanya menampilkan alumni yang punya prestasi terverifikasi. Peringkat dihitung di dalam kelompok yang sedang tampil. Alumni ditulis **"Alumni"** di tempat kelas; kelas terakhirnya tidak ditampilkan lagi di halaman publik mana pun. Mode Kelas hanya untuk siswa aktif, karena alumni tidak punya kelas.
- **Alasan:** Permintaan user: filter harus bekerja sesuai namanya ("jika filternya hanya alumni maka akan menampilkan siswa alumni yang berprestasi saja") dan alumni tampil sebagai alumni. Tombol lama "Tampilkan alumni" hanya *menambahkan* alumni ke daftar siswa aktif, jadi tidak ada cara melihat alumni saja.
- **Dampak:** `App\Domain\Leaderboard\RankingScope`; URL `/peringkat?status=aktif|alumni|semua` (`?alumni=1` yang lama diabaikan); `PublicAchievements::ranking()` dan `angkatanOptions()`; daftar angkatan hanya memuat angkatan yang ada di kelompok yang tampil; kunci cache baru (`PublicAchievements::SHAPE`).
- **Status:** final (keputusan user). Menggantikan tombol "Tampilkan alumni" (D-09, D-37). D-37 selebihnya tidak berubah: peringkat pribadi dan layar Peringkat siswa tetap hanya siswa aktif (D-52).

### D-81 · Import siswa tahunan memperbarui kelas · 4 Okt 2026
- **Keputusan:** Berkas siswa tahunan (semua angkatan, kolom **Kelas** = kelas saat berkas diupload) boleh diupload lagi tiap tahun ajaran.
  - NIS dan NISN belum dikenal → **siswa baru**, sama seperti sebelumnya.
  - NIS dan NISN cocok dengan **satu** siswa terdaftar → siswa itu **pindah kelas** (atau "tidak berubah"). Yang ditulis hanya kelas, tahun ajaran data, tanggal mulai kelas XII, tanggal arsip, dan No HP bila diisi. Nama, tanggal lahir, kata sandi, email, status, aktivasi, dan izin publikasi **tidak pernah** diubah oleh import.
  - Hanya satu nomor yang cocok, atau dua nomor milik siswa berbeda → baris **ditolak**.
  - Akun arsip dilewati; akun nonaktif diperbarui dan tetap nonaktif.
- **Alasan:** Permintaan user: "Untuk kelasnya pastikan diupdate terus … ketika ada data baru dientry maka kelas murid yang lama juga akan berubah". Kelas di peringkat dan surat harus kelas yang sekarang.
- **Dampak:** `ImportStudents`, `StudentImportReport` (aksi baru / pindah / tidak berubah / dilewati / ditolak), `student_imports.updated_count`. Pratinjau: Siswa baru · Pindah kelas · Tidak berubah · Ditolak, plus peringatan jumlah tanggal arsip yang akan berubah. Audit per siswa: `student.class_changed` atau `student.phone_updated` (tanpa nama, nomor, tanggal lahir, atau No HP).
  - *Sementara — perlu dikonfirmasi user:* (a) bila kelas + tahun ajaran di berkas menghasilkan tanggal arsip yang lain, **berkas tahunan menang** atas tanggal arsip yang pernah diubah Admin secara manual (D-58); perubahannya tampil sebagai catatan baris, dan tanggal arsip baru yang tidak di masa depan membuat baris ditolak. (b) Siswa terdaftar yang **tidak ada** di berkas dibiarkan apa adanya (kelas XII tahun lalu tetap di kelasnya sampai tanggal arsip).
- **Status:** final (keputusan user); dua butir di atas sementara. Menggantikan aturan "NIS/NISN yang sudah terdaftar = ditolak" (D-12, D-26).

### D-82 · Nomor surat diketik lengkap oleh Tata Usaha · 4 Okt 2026
- **Keputusan:** TU mengetik **nomor lengkap** tiap surat, mis. `400.3.8/313/MRD/X/2026`, untuk surat siswa maupun surat guru pendamping. Sistem tidak lagi menyusun nomor dari nomor urut. Aturan: 3–64 karakter; hanya huruf, angka, titik, garis miring, tanda hubung, dan spasi; wajib memuat angka. Satu nomor tidak boleh dipakai dua kali **untuk jenis surat yang sama**, termasuk nomor surat yang sudah dicabut; huruf besar/kecil dan spasi diabaikan saat membandingkan. Format di Admin › Pengaturan kini hanya **contoh** yang tampil di bawah kolom nomor.
- **Alasan:** Permintaan user: "Input nomor suratnya biarkan dari mereka semua jadi mereka semua input full". Penomoran mengikuti buku agenda TU; aplikasi tidak perlu tahu arti "MRD" atau kapan nomor urut direset.
- **Dampak:** `LetterNumber::normalize()` / `numberError()` / `key()` / `example()`; `letters.sequence` boleh kosong; indeks unik `(type, year, sequence)` diganti `(type, full_number)`; tahun dan bulan diambil dari tanggal surat. Nomor tetap tidak bisa diubah setelah PDF dibuat (koreksi = D-49, dengan nomor baru).
- **Status:** final (keputusan user). Menggantikan bagian format `{seq}` dan keunikan nomor urut per tahun di D-06/D-43; "format nomor hanya Admin" di D-60 kini berarti contoh nomor. Menjawab §F-3.

### D-83 · Satu surat satu form; "Kirim" sekali · 4 Okt 2026
- **Keputusan:** Surat siswa dan surat guru pendamping masing-masing punya **form sendiri** (tab sendiri): nomor, tanggal surat, dasar, dan pelaksanaan diisi per surat, lalu **"Buat PDF"** per surat. **"Kirim"** tetap satu kali, dan baru bisa setelah semua surat yang wajib ada (surat siswa; ditambah surat guru bila ada guru pembimbing). Tombol tidak dibuat abu-abu: bila ada yang kurang, layar langsung menuju daftar kesalahan yang menyebut kolomnya.
- **Alasan:** Permintaan user: "Isi entry data surat itu per surat, jangan dijadikan satu" dan, untuk koreksi, layar harus menunjukkan bagian yang perlu diisi dan yang salah.
- **Dampak:** `LetterDraft` (satu surat), `IssueLetters::handle(User, Registration, LetterType, LetterDraft)`, `RevokeLetter`, `SendLetters::missing()`, `LetterPolicy::create()` / `send()` / `requiredTypes()`; kolom baru `letters.basis_from`, `basis_subject`, `schedule_date` ("Hari" dan "Tanggal" kini dua kolom); event browser `sth-form-invalid`; audit `letters.issued` sekali per surat.
- **Status:** final (keputusan user). Menggantikan "satu draft untuk dua surat" dan tombol "Buat 2 PDF"; dua langkah Terbitkan → Kirim (D-43) tetap.

### D-84 · Template surat = berkas asli sekolah, bisa dicetak · 4 Okt 2026
- **Keputusan:** Kedua PDF mengikuti berkas sekolah sendiri. Surat guru = "ST PENGUKUHAN KEG OPSI.docx" (Surat Perintah Tugas + halaman Lampiran; tabel Lampiran **tanpa garis** seperti di berkas Word). Surat siswa = lembar Excel "ST_LOMBA … TIM" (satu surat untuk seluruh tim, tabel NO · NAMA MURID · NIS · KELAS). Kop memuat **lambang Pemprov Jawa Tengah dan lambang sekolah**; berkas lambang disimpan di repo (`resources/pdf/assets`). QR dan kode verifikasi tetap dicetak. Selain "Download" ada tombol **"Cetak"**: PDF dibuka di tab baru untuk di-print.
- **Alasan:** Permintaan user: "samakan template nya persis" dan "kedua nya harus dalam bentuk pdf dan bisa di print". Lambang adalah lambang lembaga, bukan data pribadi; tanpa lambang, kop tidak sama dengan surat sekolah.
- **Dampak:** `resources/pdf/*` dan preview TU dibangun ulang; `LetterTemplate` (`kop_6`, `guru_basis_1…3`); route `tu.surat.cetak` (PDF inline; diperiksa `LetterPolicy::view`; `Cache-Control: no-store, private`; CSP `frame-ancestors 'none'`; tercatat `letter.downloaded` dengan `inline: true`) dan `tu.surat.kop` (lambang untuk preview). Lambang ada di dalam chroot dompdf dan harus ikut di image produksi. Tanda tangan + stempel tetap satu gambar privat (D-06). "Cetak" hanya untuk TU; Kepala Sekolah tetap hanya download.
- **Status:** final (keputusan user). Menyesuaikan D-20 (logo placeholder, bukan lambang sekolah) khusus untuk kop surat; D-20 tetap berlaku untuk mockup dan layar aplikasi (sejak 7 Okt 2026 tanda merek di layar aplikasi memakai lambang: D-120). Menjawab §F-2. Belum dicoba di printer asli dan belum dicocokkan dengan kertas sekolah (backlog).

### D-85 · Talenta: dua catatan opsional dengan pembaca berbeda · 4 Okt 2026
- **Keputusan:** Tiap pilihan talenta punya dua catatan, keduanya **opsional**. **Catatan untuk guru pembina**: internal, hanya dibaca guru pembina dan Kesiswaan. **Catatan untuk siswa**: ikut di notifikasi keputusan (lolos, tidak lolos, dipulihkan) dan tampil di Profil talenta. Keputusan baru mengganti catatan siswa yang lama (dikosongkan = dihapus). "Tidak lolos" **tidak lagi wajib** bercatatan.
- **Alasan:** Permintaan user: "catatan dibuat lebih jelas kepada siapa … Tambahkan catatan kepada siswa jika dia ditolak maupun di acc … semua catatan ini bersifat opsional".
- **Dampak:** Kolom baru `talent_applications.student_note`; kolom `note` kini hanya catatan untuk guru pembina. Data lama: catatan baris `tidak_lolos` (dulu dikirim ke siswa) dipindah ke `student_note`. `MoveTalentStage`, `RejectTalentApplication`, `TalentNote`. Tombol "Simpan" tidak pernah menyimpan catatan untuk siswa. Ekspor Excel punya dua kolom catatan.
- **Status:** final (keputusan user). Tidak menggantikan entri lama (aturan "penolakan wajib bercatatan" dulu hanya ada di kode). (6 Okt 2026: kalimat "Tombol 'Simpan' tidak pernah menyimpan catatan untuk siswa" tidak berlaku lagi; "Simpan" kini menyimpannya sebagai draf yang belum terkirim, lihat D-96.)

### D-86 · Talenta "tidak lolos" bisa dipulihkan; siswa bisa minta ditinjau ulang · 4 Okt 2026
- **Keputusan:** Kesiswaan bisa **Pulihkan** pilihan yang tidak lolos: pilihan kembali ke tahap sebelum ditolak (Terdaftar bila tidak diketahui), siswa diberi tahu, catatan untuk siswa opsional. Siswa bisa **Minta ditinjau ulang**: pesan opsional (maks. 500 karakter), satu permintaan terbuka per penolakan, maks. 10 per jam; statusnya tidak berubah sampai Kesiswaan memutuskan, dan semua akun Kesiswaan diberi tahu. Siswa **tidak bisa mendaftar ulang** jenis/cabang yang sudah ditolak; ia diarahkan ke "Minta ditinjau ulang".
- **Alasan:** Permintaan user: centang di tab tidak lolos diganti ikon recycle, karena Kesiswaan bisa berubah pikiran.
- **Dampak:** Kolom baru `rejected_from`, `review_requested_at`, `review_message`; `RestoreTalentApplication` (izin `talent.manage`, audit `talent.restored`), `RequestTalentReview` (audit `talent.review_requested`); chip "Minta ditinjau ulang" di daftar Kesiswaan. Belum ada: pulihkan massal, dan kabar untuk guru pembina saat pilihan ditolak atau dipulihkan.
  - *Sementara — bisa diubah user:* user menulis "bergantung dari feedback/request siswa". Yang dibangun: Kesiswaan boleh memulihkan **dengan atau tanpa** permintaan siswa.
- **Status:** final (keputusan user); butir tafsir di atas sementara. (6 Okt 2026: "kembali ke tahap sebelum ditolak" kini hanya pilihan bawaan; Kesiswaan memilih tahap tujuannya, yaitu Terdaftar, Pemetaan, Seleksi, atau Peserta pembinaan, lihat D-104. Sisanya tidak berubah.)

### D-87 · Acara apresiasi: dikonfirmasi dan dihapus di tingkat acara · 4 Okt 2026
- **Keputusan:** Yang dikonfirmasi adalah **acaranya**. "Tandai sudah dilaksanakan" menandai acara (waktu dan siapa) beserta semua prestasi terjadwal di dalamnya; tidak bisa sebelum tanggal acara, tidak bisa untuk acara tanpa prestasi, tidak bisa dua kali. Acara yang belum dikonfirmasi bisa **dihapus**: prestasinya dilepas (bisa dimasukkan ke acara lain) dan tiap siswa yang sudah dijadwalkan diberi tahu. Acara yang sudah dikonfirmasi adalah catatan: tidak bisa dihapus dan tidak bisa ditambah prestasi.
- **Alasan:** Permintaan user: "Acara seharusnya bisa dihapus dan dikonfirmasi jika sudah dilakukan oleh Humas". Sebelumnya "selesai" hanya ada di baris prestasi, sehingga acara tanpa prestasi tidak bisa dikonfirmasi maupun dihapus.
- **Dampak:** `appreciation_events.completed_at` / `completed_by`; `CompleteAppreciationEvent`, `DeleteAppreciationEvent` (audit `appreciation.event_deleted`, tanpa nama), notifikasi `AppreciationCancelled`, `RecordAppreciation` menolak acara terkonfirmasi. Jadwal dan pilihan "Masukkan" di Humas hanya memuat acara yang belum dikonfirmasi. Hak tetap `appreciation.manage` (Humas, Kesiswaan, Admin). Mengonfirmasi acara tidak mengirim notifikasi.
- **Status:** final (keputusan user).

### D-88 · Status aktivasi: "Lihat" membuka popup kelas · 4 Okt 2026
- **Keputusan:** "Lihat" di tabel per kelas membuka **popup** berisi siswa kelas itu (status, email tersamar, aksi Buka ulang / Buka pembekuan), bukan lagi menyaring "Daftar siswa" di bawah halaman. Alamat `?kelas=<id>` membuka halaman langsung dengan popup itu; filter kelas "Daftar siswa" pindah ke `?kelas_daftar=`.
- **Alasan:** Permintaan user: "Daftar siswa yang ingin dilihat status nya dibuat popup saja".
- **Dampak:** `Akun\StatusAktivasi` (dialog `aktivasi-kelas`, 40 baris per langkah, tanpa query tambahan). Izin tidak berubah. "Daftar siswa" tetap ada untuk pencarian lintas kelas dan CSV.
- **Status:** final (keputusan user).

### D-89 · Kata sehari-hari di UI · 4 Okt 2026
- **Keputusan:** Teks untuk pengguna memakai kata yang biasa dipakai orang awam: **upload** (bukan unggah), **download** (bukan unduh), **link** (bukan tautan), **preview** (bukan pratinjau), termasuk bentuk berimbuhan (di-upload, meng-upload, men-download, di-download). Berlaku di layar, email, pesan validasi, dan tes. Nama di kode tidak diubah (mis. kunci tab `unggahan`, partial `pratinjau-*`).
- **Alasan:** Permintaan user: "kata kata yang normal keseharian dipakai oleh orang awam".
- **Dampak:** Semua view, email, dan pesan. Entri lama di log ini, mockup, dan spec desain masih memakai kata lama; itu bukan alasan mengubah aplikasi kembali.
- **Status:** final (keputusan user). Menyesuaikan gaya bahasa D-19; selebihnya D-19 tetap (Bahasa Indonesia, singkat, sentence case, siswa disapa "kamu").

### D-90 · Angka menu tanpa muat ulang; kotak cari menyaring saat diketik · 4 Okt 2026
- **Keputusan:** Angka di menu samping (Antrean surat, Persetujuan, Usulan lomba, Verifikasi hasil, Antrean publikasi) berubah langsung setelah aksi di halaman, tanpa refresh. Angka "Antrean surat" = pendaftaran berstatus `disetujui` (surat belum dikirim) dan turun saat "Kirim". Kotak cari di bar atas menyaring daftar **saat diketik** (jeda 350 ms) bila pengguna sedang di halaman daftarnya; mengosongkannya mengembalikan daftar penuh; di halaman lain, Enter tetap membuka halaman daftar. Di Siswa › Cari lomba hanya ada **satu** kotak cari pada lebar layar apa pun.
- **Alasan:** Permintaan user: cari tanpa Enter dan kembali otomatis saat dihapus; "kenapa search bar nya ada 2"; angka Antrean surat turun tanpa refresh.
- **Dampak:** `Navigation::item()` (`badgeKey`), atribut `data-sth-badge` di sidebar, event browser `sth-badges`; atribut `data-sth-live-search` di form bar atas; `resources/js/app.js`. Tetap tanpa `style=""` dan tanpa JS inline (CSP).
- **Status:** final (keputusan user).

### D-91 · Kecepatan di lingkungan lokal; tes menolak jalan bila config ter-cache · 4 Okt 2026
- **Keputusan:** Di stack dev, php-fpm membaca **cache config** dan menyimpan **view terkompilasi** di filesystem container sendiri (`/tmp/sth-cache`), dan tidak lagi membandingkan view dengan sumbernya. Pengaturannya hanya lewat `env[...]` pool php-fpm (`docker/php/fpm.dev.conf`), **bukan** `environment:` di compose atau `.env`, sehingga baris perintah (artisan, tes, queue, scheduler) tidak pernah melihat config ter-cache. `tests/TestCase::createApplication()` **menolak jalan** bila config ter-cache atau `APP_ENV` bukan `testing`. Aturan developer: setelah mengubah `.php`, `.blade.php`, `config/*.php`, atau `.env`, jalankan `reload.bat`; jangan pernah menjalankan `php artisan config:cache` atau `optimize` sendiri di repo.
- **Alasan:** Permintaan user: pindah menu harus secepat mungkin untuk semua peran. Hasil ukur di satu halaman Humas: sekitar 400 pemeriksaan berkas per request di bind mount Windows (±6 ms tiap pemeriksaan), ±165 dari Blade dan ±140 dari config. Sesudahnya 139 per request; halaman Humas 480–720 ms → 260–430 ms. Sisi keamanan: config ter-cache membawa `APP_ENV=local` dan koneksi MySQL dev ke proses tes, `phpunit.xml` tidak bisa menimpanya lagi, lalu `RefreshDatabase` akan mengosongkan database dev.
- **Dampak:** `docker/php/fpm.dev.conf`, `docker/php/dev-cache.sh`, `config/view.php`, `compose.yaml`, `start.bat`, `reload.bat`, `tests/TestCase.php`. Hanya untuk dev; produksi tidak membaca berkas-berkas itu. Melengkapi PERF1 (`docs/dev-notes/PERF1.md`).
- **Status:** final (menyangkut keamanan: penjaga tes terhadap database dev; mengubahnya butuh entri baru).

## D7. Perbaikan dari uji QA 5 Okt 2026 (6 Okt 2026)

Pada 5 Okt 2026 aplikasi diuji lewat browser dan permintaan HTTP langsung: 64 temuan, 12 di antaranya prioritas tinggi
(`hasil-uji/ringkasan-temuan.md` §1, di luar repo, di sebelah folder repo). Pada 6 Okt 2026 lead menjawab: "Dari ke 12
temuan yang paling penting, lakukan sesuai saranmu". Entri di bawah mencatat aturan yang berubah karena perbaikan itu
(ringkasan: `docs/dev-notes/M2.md`; rincian: `M2-A.md`…`M2-C.md`). Temuan 12 (paket di image produksi) bukan aturan
aplikasi, jadi tidak punya entri. Semua berstatus **final (keputusan user: "lakukan sesuai saranmu")**, kecuali butir
yang ditandai *sementara*. Tiap entri menyebut keputusan lama yang disesuaikannya; entri lama tidak dihapus.

### D-92 · Setelah reset Admin, siswa wajib membuat kata sandi baru · 6 Okt 2026
- **Keputusan:** Siswa yang akunnya direset Admin ("Reset kata sandi & email", K10) **wajib membuat kata sandi baru** di langkah 3 aktivasi. Tombol "Lewati, pakai kata sandi sekarang" tidak tampil untuknya, sama seperti staf. Tombol itu tetap ada untuk siswa yang masih memakai **kata sandi dari sekolah** (hasil import): bagian D-79 itu tidak berubah.
- **Alasan:** Temuan Q2-02. Kata sandi sementara pernah tampil di layar Admin, jadi bukan rahasia pemilik akun saja. Dialog Admin sendiri sudah menulis "wajib menggantinya", tetapi siswa masih bisa menekan "Lewati" dan akunnya aktif dengan kata sandi itu.
- **Dampak:** Kolom baru `users.temporary_password_issued_at`: diisi `ResetStudentAccount`, dikosongkan `SetNewPassword` setiap kali pemilik memilih kata sandinya sendiri (aktivasi, Keamanan akun, Lupa kata sandi). Selama kolom itu terisi, `CompleteActivation::mayKeepPassword()` salah; `keepPassword()` memeriksa lagi pada baris yang dikunci dan menolak dengan 403 ("Kata sandi akun ini wajib diganti."). Layar langkah 3 berjudul "Buat kata sandi baru" tanpa tombol lewati. Migrasi mengisi kolom itu untuk akun siswa yang direset sebelumnya dan belum memilih kata sandi (dari log audit `account.reset`). Akun yang **sudah terlanjur** mempertahankan kata sandi sementara tidak dipaksa mengganti; aplikasi belum dipakai sekolah, jadi akun seperti itu hanya ada di data uji lokal. Dua teks di Admin › Pengguna & peran disesuaikan. Reset akun staf tidak berubah (staf memang selalu wajib mengganti).
- **Status:** final (keputusan user: "lakukan sesuai saranmu"). Menggantikan kalimat terakhir D-79 ("siswa yang direset juga boleh tetap memakai kata sandi sementaranya"); D-79 selebihnya tetap, termasuk risiko yang diterima untuk kata sandi sekolah. Melengkapi D-45.

### D-93 · Halaman kredensial tidak disimpan browser; sesi lama dihapus · 6 Okt 2026
- **Keputusan:**
  - Semua jawaban untuk halaman yang menampilkan atau menerima kata sandi, kode, atau rahasia 2FA (`/login`, `/aktivasi`, `/lupa-kata-sandi`, `/user/confirm-password`, `/two-factor-challenge`, `/akun/2fa`) membawa `Cache-Control: no-store`. Browser tidak menyimpan halamannya, jadi tombol Back tidak memunculkannya lagi.
  - Kolom kata sandi **dikosongkan** begitu form biasa dikirim, dan saat browser memulihkan halaman dari cache Back/Forward.
  - Setiap kali ID sesi diperbarui (kode aktivasi diterima, ganti atau lewati kata sandi di aktivasi, ganti kata sandi di Keamanan akun, 2FA dikonfirmasi), **sesi lama dihapus**. Pembaruan itu hanya terjadi **setelah** aksinya berhasil: kata sandi sekarang yang salah atau kata sandi baru yang ditolak tidak lagi menyentuh sesi.
- **Alasan:** Q2-01: setelah Keluar, Back dua kali memunculkan halaman masuk lama dengan NIS dan kata sandi masih terisi, dan "Masuk" langsung login lagi; berbahaya di komputer lab bersama. Q1-03 / Q2-07: sesi lama tidak dihapus saat sesi diperbarui, sehingga "Perangkat yang masuk" berisi perangkat hantu (sampai 7) dan nomor sesi lama masih hidup.
- **Dampak:** Middleware global `App\Http\Middleware\PreventCredentialPageCaching` (berlaku untuk GET, POST, redirect, dan halaman error di alamat itu; juga `Pragma: no-cache`). `resources/js/app.js`: form `wire:submit` tidak disentuh saat dikirim, dan submit yang dibatalkan tidak mengosongkan isian. `session()->regenerate(true)` di `Auth\Activation`, `Akun\Keamanan`, `Auth\TwoFactorSetup`. `ListUserSessions` menampilkan "Perangkat ini" dari request bila baris sesinya belum tertulis. Login Fortify sendiri tidak diubah: baris sesi tamu dari sebelum login tidak punya pemilik, jadi tidak pernah tampil sebagai perangkat dan tidak pernah berstatus masuk. Skenario Back itu sendiri belum diulang di browser (`docs/dev-notes/M2.md`, "Not verified").
- **Status:** final (keputusan user: "lakukan sesuai saranmu"; menyangkut keamanan, mengubahnya butuh entri baru).

### D-94 · Kata sandi umum, nama pengguna, dan nama email ditolak · 6 Okt 2026
- **Keputusan:** Kata sandi baru ditolak bila:
  - memuat **nama pengguna** (username) akun itu atau **bagian email sebelum "@"**, utuh atau potongan 4 karakter ke atas yang berisi huruf;
  - **terlalu umum**: memuat kata dari daftar bawaan (67 kata, mis. password, sandi, rahasia, admin, sekolah, semarang, indonesia, merdeka, bismillah, iloveyou, juga ejaan seperti passw0rd; "@" dan "$" dibaca a dan s), urutan huruf abjad atau keyboard 4 huruf ke atas, urutan angka 5 angka ke atas, atau satu karakter diulang 4 kali ke atas, **dan** huruf lain yang tersisa kurang dari 5. Angka dan simbol tidak dihitung.
  - Contoh ditolak: `password123`, `Qwerty!2026`, `P@ssw0rd-99`, `kode123456`. Contoh diterima: `sandiwara malam 77`.
  - Berlaku untuk siswa dan staf, di aktivasi, Keamanan akun, dan Lupa kata sandi. Aturan lama (K5, D-32) dan pesannya tidak berubah.
- **Alasan:** Q1-02: `password123` diterima. Q2-05: staf boleh memakai kata sandi yang berisi username-nya; staf tidak punya NIS, jadi sebelumnya tidak ada pemeriksaan identitas untuk akun staf.
- **Dampak:** `App\Domain\Accounts\PasswordPolicy` (pesan "Jangan memakai nama pengguna.", "Jangan memakai email sendiri.", "Kata sandi ini terlalu mudah ditebak."). `SetNewPassword::hint()` menyusun kalimat petunjuk di bawah kolom dari aturan yang berlaku untuk akun itu (staf: nama, nama pengguna, email; siswa atau tamu: tanggal lahir, NIS/NISN, nama). Daftar itu pendek, bukan kamus: pemeriksaan `uncompromised()` (HIBP) di produksi menutup sisanya. Yang dihitung hanya huruf, jadi `admin` diikuti sepuluh angka acak juga ditolak. Kata sandi sementara yang diketik Admin untuk akun staf **tidak** diperiksa dengan daftar ini.
- **Status:** final (keputusan user: "lakukan sesuai saranmu"). Menambah K5 / D-32.

### D-95 · "Buka ulang" tidak pernah memendekkan batas; alasan minimal 10 karakter · 6 Okt 2026
- **Keputusan:** "Buka ulang" aktivasi (K2) memberi batas baru = **yang lebih jauh** antara batas yang sekarang dan sekarang + 72 jam. Tombolnya hanya tampil bila ada yang berubah: batas sudah lewat, akun dibekukan karena salah kata sandi, atau sisa waktunya kurang dari 72 jam. Dialognya menyebut hasilnya sebelum ditekan ("Dibuka lagi sampai …", atau "Pembekuan dibuka. Batas aktivasi tetap …"). Alasan "Buka ulang" dan alasan **menolak lomba** minimal **10 karakter**.
- **Alasan:** Q2-09: "Buka ulang" pada siswa yang batasnya masih 15 Okt memendekkannya menjadi 8 Okt, dan alasan dua huruf diterima. Q1-18: alasan penolakan "ab" terkirim ke pengusul lomba lewat email.
- **Dampak:** `ReopenActivationWindow::deadlineAfter()` / `wouldChange()` / `notNeededMessage()` / `MIN_REASON`. Permintaan basi atau buatan dijawab "Batas aktivasi siswa ini masih sampai … Belum perlu dibuka ulang." Audit `account.activation_reopened` menambah `previous_expires_at`. Tombol kini bertulisan "Buka ulang" (dulu "Buka ulang 72 jam"). Akun menunggu yang tidak punya batas sama sekali (data sebelum B1) tidak punya tombol. `VerifyCompetition::MIN_REJECT_NOTE` ("Alasan penolakan minimal 10 karakter."). Kolom alasan lain **belum** diberi batas yang sama: tolak pendaftaran (Kepala Sekolah), tolak atau minta revisi laporan hasil, dan "Buka pembekuan" (backlog; perlu keputusan user). Koreksi surat tetap minimal 5 karakter (D-49).
- **Status:** final (keputusan user: "lakukan sesuai saranmu"). Menyesuaikan K2 (SECURITY §3a): "72 jam" kini berarti paling sedikit 72 jam dari sekarang, tidak pernah lebih pendek dari batas yang sudah ada. (6 Okt 2026: bagian "alasan menolak lomba minimal 10 karakter" tidak berlaku lagi; alasan penolakan lomba kini opsional dan `VerifyCompetition::MIN_REJECT_NOTE` dihapus, lihat D-103. Alasan "Buka ulang" tetap minimal 10 karakter.)

### D-96 · Talenta: catatan untuk siswa tersimpan sebagai draf; "Tidak lolos" minta konfirmasi · 6 Okt 2026
- **Keputusan:** "Simpan" di panel Talenta kini juga menyimpan **draf** catatan untuk siswa. Draf hanya dilihat Kesiswaan. Siswa baru menerimanya saat ada keputusan (Lolos ke …, Tidak lolos, Pulihkan); keputusan mengirim catatannya lalu mengosongkan draf. "Tidak lolos" (di panel dan di aksi massal) membuka dialog konfirmasi dulu.
  - Catatan mana yang terkirim: bila Kesiswaan mengisi kolom catatan untuk keputusan itu (panel, dialog Pulihkan, kolom catatan di dialog massal), teks itu yang terkirim; kolom yang dikosongkan berarti tanpa catatan.
  - *Sementara — bisa diubah user:* aksi cepat yang tidak punya kolom catatan (ikon centang di baris, massal "Pindahkan ke …", massal "Tidak lolos" dengan catatan kosong) **ikut mengirim draf yang tersimpan**. Toast-nya menulis "… catatan untuk siswa yang tersimpan ikut terkirim.", dan dialog "Tidak lolos" massal menyebut berapa pilihan yang punya draf.
- **Alasan:** Q2-14: "Simpan" menulis "tersimpan", tetapi catatan untuk siswa hilang tanpa pesan. Q2-15: "Tidak lolos" langsung jalan dengan satu klik, padahal siswa langsung diberi tahu. Untuk aksi cepat, tafsir lain ("hanya panel yang mengirim catatan") akan membuang atau menahan draf tanpa kabar, yaitu temuan itu sendiri.
- **Dampak:** Kolom baru `talent_applications.student_note_draft`; `UpdateTalentAssessment` (kunci `student_note_draft`), `TalentNote::forDecision()`, `MoveTalentStage`, `RejectTalentApplication`, `RestoreTalentApplication`; dialog `tidak-lolos-talenta`; petunjuk "Draf tersimpan, belum terkirim." di panel; audit `talent.stage_changed` dan `talent.rejected` menambah `student_note_from_draft`. `student_note` tetap hanya ditulis oleh keputusan, jadi Profil talenta, tab guru, ekspor, dan notifikasi tidak pernah memuat draf.
- **Status:** final (keputusan user: "lakukan sesuai saranmu"); butir aksi cepat di atas sementara. Menyesuaikan kalimat D-85 "Tombol 'Simpan' tidak pernah menyimpan catatan untuk siswa"; D-85 selebihnya tetap (dua catatan opsional, "tidak lolos" tidak wajib bercatatan).

### D-97 · Satu prestasi hanya di satu acara apresiasi; "Lepas dari acara" · 6 Okt 2026
- **Keputusan:** Satu prestasi hanya boleh ada di **satu** acara apresiasi. Prestasi yang masih dijadwalkan di acara lain yang belum dikonfirmasi ditolak ("Prestasi ini sudah dijadwalkan di acara X (5 Okt 2026). Lepas dulu dari acara itu."); prestasi yang sudah diapresiasi di acara terkonfirmasi juga ditolak ("Prestasi ini sudah diapresiasi di acara X (…)."). Tombol baru **"Lepas dari acara"** mengeluarkan satu prestasi terjadwal dari acara yang belum dikonfirmasi: acaranya tetap ada, prestasinya bisa dimasukkan ke acara lain, dan siswanya diberi tahu ("Jadwal apresiasi berubah").
- **Alasan:** Q2-10: satu prestasi bisa masuk dua acara, dan satu-satunya cara melepasnya adalah menghapus seluruh acara (D-87).
- **Dampak:** `RecordAppreciation` (mengunci baris prestasi; `blocking()`, `blockedMessage()`), `ReleaseAppreciation` (hak `AppreciationPolicy::delete`: pemegang `appreciation.manage`, hanya baris `dijadwalkan` di acara yang belum dikonfirmasi; audit `appreciation.released` tanpa nama), notifikasi `AppreciationReleased` (jenis `appreciation.released`, hanya untuk akun yang bisa masuk, dikirim setelah commit). "Tambah prestasi" hanya memuat prestasi yang belum ada di acara mana pun; Publikasi › "Masukkan ke acara" tidak menawarkan acara bila prestasinya sudah ada di satu acara. Tidak ada migrasi data untuk prestasi yang terlanjur ada di dua acara (hanya data uji lokal): lepas dengan tombol baru.
- **Status:** final (keputusan user: "lakukan sesuai saranmu"). Melengkapi D-87: konfirmasi dan hapus tetap di tingkat acara, dan acara terkonfirmasi tetap terkunci.

### D-98 · Tanggal lomba: urutan dan konfirmasi untuk tanggal yang sudah lewat · 6 Okt 2026
- **Keputusan:** "Tanggal mulai" lomba tidak boleh sebelum "Batas daftar" (pesan "Tanggal mulai tidak boleh sebelum batas daftar."): diperiksa saat membuat, saat mengubah, dan sekali lagi saat mempublikasikan. Mempublikasikan lomba yang batas daftar atau tanggal finalnya **sudah lewat** tetap boleh (lomba lama kadang dicatat belakangan), tetapi hanya setelah konfirmasi yang jelas ("Tanggal lomba ini sudah lewat (…). Tetap publikasikan?"). Membuat atau menyimpan tanpa mempublikasikan tidak ditanya.
- **Alasan:** Q1-04: lomba terbit dengan tanggal mulai 1 Agu, batas daftar 1 Sep, dan semua tanggal sudah lewat. Yang diperiksa dulu hanya "batas daftar ≥ dibuka" dan "final ≥ mulai".
- **Dampak:** `App\Actions\Competitions\CompetitionDates`; `CreateCompetition`; `UpdateCompetition` (lomba yang sudah terbit hanya diperiksa bila salah satu dari dua tanggal itu diubah, supaya data lama tetap bisa diedit); `VerifyCompetition::handle(..., bool $confirmPastDates = false)` (menolak dengan kunci `CompetitionDates::PAST_KEY`; audit `competition.published` menambah `past_dates`). Layar: kartu antrean menulis "Tanggal sudah lewat: …" dan "Publikasikan" membuka dialog `lomba-tanggal-lewat`; urutan yang salah menampilkan "Perlu diperbaiki: …" sebagai ganti tombol; di drawer "Periksa lomba" ada centang "Tanggal lomba ini sudah lewat. Tetap publikasikan.", yang hanya ditanya oleh "Simpan & publikasikan".
- **Status:** final (keputusan user: "lakukan sesuai saranmu").

### D-99 · Siswa tidak men-download surat guru; kartu surat hanya memuat surat yang sudah dikirim · 6 Okt 2026
- **Keputusan:** Di Siswa › Lomba saya, kartu "Surat tugas" hanya memuat surat yang **sudah dikirim** Tata Usaha. Surat siswa punya tombol download. Surat perintah tugas guru pendamping tampil sebagai **baris keterangan saja** ("Surat perintah tugas untuk guru pendamping sudah terbit" + "Untuk Bu/Pak …"), tanpa link dan tanpa tombol. Selama surat belum dikirim, kartu menulis "Tata Usaha sedang menyiapkan surat tugas." Guru pembimbing dan staf tetap bisa men-download suratnya.
- **Alasan:** Q1-01: siswa melihat tombol download surat guru yang berujung 404. `LetterPolicy` memang tidak memberi berkas itu ke siswa (surat guru dan Lampirannya memuat NIP dan pangkat guru); yang salah adalah tombolnya. Surat yang sudah dibuat tetapi belum dikirim juga menampilkan tombol yang menjawab 404.
- **Dampak:** `Siswa\LombaSaya\Index` (`detail()`, `letterRows()`), partial `letters-card`. `LetterPolicy` tidak berubah; link surat siswa mengikuti `Gate::allows('view', $letter)`.
- **Status:** final (keputusan user: "lakukan sesuai saranmu"). *Sementara — bisa diubah user:* ringkasan QA meminta lead memutuskan apakah siswa boleh melihat surat guru; yang dibangun mempertahankan aturan policy yang sudah ada (tidak boleh). Bila siswa harus boleh membukanya, itu butuh entri baru dan perubahan `LetterPolicy`.

### D-100 · Angka menu samping dihitung di satu tempat untuk semua halaman · 6 Okt 2026
- **Keputusan:** Angka di menu samping (Antrean surat, Persetujuan, Usulan lomba, Verifikasi hasil, Antrean publikasi) tampil di **setiap** halaman ber-layout aplikasi, termasuk Arsip surat, Template surat, Status aktivasi, Notifikasi, dan Keamanan akun. Satu penghitung menghitungnya saat layout dirender; halaman tidak perlu lagi mengirim angkanya sendiri (halaman yang mengirim tetap menang).
- **Alasan:** Q1-05: angka hanya muncul di halaman yang komponennya mengirim `badges` ke layout, jadi hilang di halaman lain.
- **Dampak:** `App\Providers\NavigationServiceProvider::badges(object $user, string $role, array $except = [])`, didaftarkan lewat `Navigation::resolveBadgesUsing()`; `Navigation::badgeKeys()`. Aturannya: hanya kunci angka dari menu yang sedang dirender; hanya bila pengguna boleh membuka antrean itu (izin atau policy yang sama dengan layarnya, bukan nama peran); query yang sama dengan layarnya, satu `count(*)` per kunci, tanpa memuat baris; tidak ada untuk tamu. Atribut `data-sth-badge` dan event `sth-badges` (D-90) tidak berubah. Menu Admin tidak punya butir berangka, jadi akun Admin tetap tidak melihat angka di layar Kesiswaan, seperti sebelumnya.
- **Status:** final (keputusan user: "lakukan sesuai saranmu"). Melengkapi D-90.

### D-101 · Halaman error sendiri; `GET /logout` dialihkan; halaman pertama setelah login · 6 Okt 2026
- **Keputusan:**
  - Aplikasi punya halaman error sendiri untuk 401, 403, 404, 405, 419, 429, 500, dan 503 (ditambah cadangan untuk 4xx dan 5xx lain): berbahasa Indonesia, bergaya aplikasi, dengan "Kembali ke beranda" dan, bila pengunjung datang dari halaman lain di aplikasi ini, "Kembali". Halaman error **tidak pernah** menampilkan teks exception, dan tetap dipakai saat `APP_DEBUG=true`.
  - `GET /logout` tidak lagi menjawab 405: yang sudah masuk dialihkan ke beranda, tamu ke halaman masuk. Alamat itu **tidak pernah** mengeluarkan pengguna; keluar tetap lewat `POST /logout` dengan token CSRF.
  - Bila halaman pertama setelah login (alamat yang dibuka sebelum login) menjawab 403 atau 404 untuk akun itu, pengguna dialihkan ke berandanya. Hanya untuk permintaan GET tepat setelah login; permintaan berikutnya dan permintaan JSON mendapat halaman error biasa.
- **Alasan:** Q1-07 / Q2-30: halaman 403/404 bawaan tampil polos (gayanya diblokir CSP), 403 berbahasa Inggris, tanpa tombol kembali. Q2-03: `GET /logout` menjawab 405 dengan halaman debug 870 kB dalam 6–63 detik. Q2-17: setelah login pengguna mendarat di alamat lama yang tidak boleh dibukanya.
- **Dampak:** `resources/views/errors/*` (kerangka `errors.layout`: tanpa layout peran, tanpa `style`/JS inline, `noindex`), `App\Providers\ErrorPageServiceProvider` (`links()`, `afterLogin()`, flash `sth.after_login` pada event `Login`; untuk 5xx tidak membuka koneksi database), satu baris `$exceptions->render()` di `bootstrap/app.php`, route `logout.get`. `/verifikasi-surat/{code}` kini menerima kode satu segmen apa pun, sehingga kode yang salah ketik mendapat halaman verifikasi sendiri ("Tidak ditemukan · Surat dengan kode ini tidak terdaftar."). Alamat yang tidak dikenal tidak punya sesi, jadi di sana "Kembali ke beranda" menuju `/` juga untuk pengguna yang sudah masuk (sengaja tanpa `Route::fallback()`, supaya permintaan pemindai tidak menulis baris sesi). Belum diuji ulang dengan `APP_DEBUG=false`.
- **Status:** final (keputusan user: "lakukan sesuai saranmu").

### D-102 · Setelah Keluar: nama pengguna dikosongkan, akun berikutnya tidak mendarat di halaman akun lama · 6 Okt 2026
- **Keputusan:**
  - Di halaman masuk, kolom nama pengguna (NIS, NISN, username, atau email; kolom dengan `autocomplete="username"`) dikosongkan bersama kolom kata sandi: setelah form dikirim, dan saat halaman dimunculkan lagi oleh Back/Forward. Kalau login gagal, nama pengguna tetap dikembalikan server seperti biasa.
  - Setelah satu akun Keluar di sebuah browser, akun **lain** yang masuk berikutnya di browser itu langsung ke berandanya, bukan ke "alamat tujuan" yang tersimpan. Akun yang **sama** yang masuk lagi tetap dibawa ke halaman yang dimintanya. Browser yang tidak pernah dipakai Keluar tidak berubah (link dari email tetap membawa ke halamannya setelah login).
- **Alasan:** uji browser 6 Okt 2026 terhadap perbaikan M2 (`hasil-uji/qa-3-cek-perbaikan.md`): Back kedua setelah Keluar masih menampilkan nama pengguna (kata sandi sudah kosong), karena browser mengisi ulang kolom teks saat memuat lagi halaman untuk Back. Back pertama meminta halaman lama sebagai tamu, sehingga alamat itu tersimpan sebagai tujuan; `operator` yang masuk berikutnya mendarat di `/kesiswaan`. Di komputer lab yang dipakai bergantian, itu membocorkan NIS siswa sebelumnya dan membingungkan pengguna berikutnya.
- **Dampak:** `resources/js/app.js` (`credentialFields()`, `openedFromHistory()`); middleware baru `App\Http\Middleware\RememberLoggedOutAccount` di grup `web` (sebelum `EnsureAccountActive`) dengan kunci sesi `sth.logged_out_user`; dua listener (`Logout`, `Login`) di `FortifyServiceProvider`. Berlaku juga untuk akun yang dikeluarkan aplikasi (dinonaktifkan, dibekukan, "Bukan saya?") dan untuk login yang berakhir di tantangan 2FA. Yang sengaja tidak berubah: sesi yang habis sendiri (tidak ada yang menekan Keluar) tetap mengikuti alamat tujuan, dan D-101 tetap mengalihkan 403/404 ke beranda.
- **Status:** final (keputusan user: "Ya perbaiki"). Melengkapi D-93 dan D-101. Diuji di Chrome dan Chromium; Firefox dan Safari belum.

## D8. Permintaan lead 6 Okt 2026 (gelombang M3) dan QA4

Enam permintaan lead setelah menarik PR 12 milik S, ditambah satu perubahan skema dari PR itu. Catatan pengerja: `docs/dev-notes/M3.md`, `M3-A.md`, `M3-C.md`, `M3-D.md`, `M3-E.md`, `QA4.md`.

### D-103 · Alasan penolakan lomba opsional, tanpa panjang minimal · 6 Okt 2026
- **Keputusan:** Kesiswaan boleh menolak lomba di antrean tanpa menulis alasan, dan alasan pendek pun diterima. Kalau diisi (maksimal 1.000 karakter) alasan itu dikirim ke pengirim. Kalau kosong, pengirim tetap diberi tahu dengan kalimat "Kesiswaan tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu."
- **Alasan:** permintaan lead setelah uji tangan: "Gambar 1 minimal nya hilangkan dan alasan penolakannya dibuat opsional" (Gambar 1 = kartu di Kesiswaan › Usulan lomba).
- **Dampak:** `VerifyCompetition` (konstanta `MIN_REJECT_NOTE` dihapus; `verification_note` boleh kosong), `CompetitionRejected::for(Competition, ?string)` + `NO_REASON`, `Kesiswaan\Lomba\Index` (toast "Lomba ditolak. Pengirim diberi tahu." / "… Pengirim menerima alasannya."), kartu antrean (label "Alasan penolakan (opsional)"), `Guru\Lomba` ("Kesiswaan tidak menulis alasan."). **Tidak berubah:** alasan "Buka ulang" tetap minimal 10 karakter (D-95); alasan menolak pendaftaran (Kepala Sekolah) dan menolak / meminta revisi laporan tetap wajib.
- **Status:** final (keputusan user). Menggantikan aturan "catatan wajib" untuk penolakan lomba (D-27) dan bagian lomba dari D-95.

### D-104 · Talenta: "Pulihkan" ke tahap yang dipilih; "Download data" dengan pilihan status · 6 Okt 2026
- **Keputusan:**
  - "Pulihkan" (ikon daur ulang pada pilihan talenta yang Tidak lolos) menanyakan tahap tujuan: **Terdaftar, Pemetaan, Seleksi, atau Peserta pembinaan**. Tahap sebelum ditolak terpilih lebih dulu dan ditandai "Tahap sebelumnya". Tetap hanya dari `tidak_lolos`; permintaan tinjau ulang yang masih terbuka ditutup; catatan untuk siswa tetap opsional; notifikasi siswa menyebut tahap tujuannya.
  - "Download data (Excel)" membuka dialog pilihan status: lima status (Terdaftar, Pemetaan, Seleksi, Peserta pembinaan, Tidak lolos), boleh beberapa, ada "Pilih semua status". Status tab yang sedang dibuka terpilih lebih dulu. Baris "Filter:" di file menyebut pilihannya. Konfirmasi kata sandi dan download otomatis sesudahnya (QA4) membawa pilihan yang sama.
  - Tab "Semua" sudah memuat baris Tidak lolos beserta chip statusnya sejak M1 (urutannya paling bawah): tidak ada yang diubah di sana, hanya ditambah tes.
- **Alasan:** permintaan lead: "kesiswaan, profil talenta, fitur tidak lolos bisa dipulihkan itu tolong bisa diarahkan kembali ke pemetaan , seleksi atau perserta pembinaan atau terdaftar, dan bisa muncul jg di semua lengkap dengan statusnya" dan "Gambar 2 download data nya bisa memilih mau download data yang mana, semua, atau yang terdaftar saja, atau yang status lainnya (dikasih filter)".
- **Dampak:** `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null, ?TalentStatus $to = null)` dengan `TARGETS` (tanpa `$to` = tahap sebelumnya, seperti dulu); audit `talent.restored` mencatat `from`, `to`, dan `rejected_from`; `TalentApplicationRestored`; `Kesiswaan\Talenta` (dialog pulihkan, dialog `download-talenta`, `export()` menerima pilihan: kosong = isi tab, `semua`, atau daftar status dipisah koma; nilai lain 404). Dialog massal "Tidak lolos" kini hanya menghitung baris yang benar-benar ditolaknya.
- **Status:** final (keputusan user). Menyesuaikan D-86 (tahap sebelumnya kini hanya pilihan bawaan). *Sementara — bisa diubah user:* pulih langsung ke Peserta pembinaan tidak mewajibkan guru pembina atau tanggal asesmen (sama seperti "Lolos ke …" hari ini; Kesiswaan mengisinya di panel sesudahnya) — *7 Okt 2026: guru pembina kini wajib, lihat D-109; tanggal asesmen tetap tidak wajib*; baris Tidak lolos tetap di urutan terakhir tab Semua; belum ada "Pulihkan" untuk banyak baris sekaligus; dialog menulis "Status" sedangkan file menulis "Tahap".

### D-105 · "Buka" berkas tampil di popup; download tetap ada · 6 Okt 2026
- **Keputusan:** Di layar yang menampilkan berkas bukti, "Buka" (atau nama berkasnya) membuka dialog yang langsung menampilkan PDF atau gambarnya, dengan tombol "Download" dan "Tutup". Tidak ada yang ter-download kecuali tombol itu ditekan. Berlaku di Kesiswaan › Verifikasi hasil, Database prestasi, Talenta (bukti dukung), kartu Usulan lomba (juknis), Guru › Siswa binaan (bukti), Siswa › Portofolio dan Profil talenta. Tanpa JavaScript, atau untuk tipe yang tidak bisa ditampilkan, link tetap men-download. Browser yang tidak bisa menampilkan PDF (banyak HP) mendapat satu kalimat dan tombol Download.
- **Alasan:** permintaan lead: "ketika klik buka sertifikat tidak perlu langsung unduh, jdi bisa langsung muncul view popout nya tanpa harus didownload terlebih dahulu tpi tetap menyertakan tombol download".
- **Dampak:** `FileController::show` boleh mengirim **PDF** inline (`?tampil=1`) untuk popup. Hanya jawaban itu yang dilonggarkan: `X-Frame-Options: SAMEORIGIN` dan CSP `default-src 'none'; frame-ancestors 'self'`; CSP halaman mendapat `frame-src 'self'`. Download, gambar inline (ditampilkan dengan `<img>`; hanya jpeg/png/webp), tipe lain, jawaban 404, dan `files.public` tetap `DENY` / `sandbox` seperti sebelumnya. PDF inline tidak diberi `sandbox` karena penampil PDF Chromium tidak berjalan di dokumen ber-sandbox (dicoba dua cara). Popup mengirim permintaan HEAD dulu (cek policy yang sama, tidak membaca isi berkas, tidak diaudit) dan baru memasang frame bila jawabannya 200 + `application/pdf` + `inline`; sesi habis, 404, dan 429 menjadi satu kalimat di dialog. Preview dicatat sebagai `file.downloaded` dengan `inline: true`. Tetap: `nosniff`, `private, no-store`, `FilePolicy::view`, 404 untuk berkas yang bukan haknya, throttle 60/menit (satu preview PDF = 2 permintaan). Komponen `<x-sth.preview />` (satu dialog di `layouts::app` dan `layouts::mobile`), hook `data-sth-preview` / `data-sth-preview-type` / `data-sth-preview-name`, `FileController::previewUrl()` / `previewType()`. Hook kedua `data-sth-copy` (dipakai D-107).
- **Status:** final (keputusan user). Menyesuaikan aturan "selalu attachment" di SECURITY §3d. *Sementara — menunggu jawaban lead:* surat tugas belum memakai popup (rutenya sudah bisa; *7 Okt 2026: layar guru memakainya, D-112*); permintaan HEAD tidak diaudit; halaman publik tetap hanya download. Belum diuji di Firefox, Safari, HP sungguhan, dan di belakang Apache produksi. Keterbatasan yang diketahui: Esc tidak menutup dialog selagi fokus keyboard berada di dalam penampil PDF (X, "Tutup", dan klik di luar tetap bisa).

### D-106 · Berkas demo adalah dokumen contoh yang realistis (lokal saja) · 6 Okt 2026
- **Keputusan:** Berkas pada data demo (sertifikat, piagam, laporan penelitian, juknis, brosur, poster, surat tugas) bukan lagi satu halaman kosong 1 kB, melainkan dokumen contoh yang tampak seperti aslinya dan isinya mengikuti data demo (nama siswa, lomba, capaian, tanggal). Batas yang tidak boleh dilanggar: hanya orang fiktif dari data demo; penanda tangan sertifikat adalah nama rekaan dengan jabatan panitia ("Ketua Panitia", "Ketua Dewan Juri"), tidak pernah jabatan lembaga sungguhan; tanpa logo, stempel, atau tanda tangan lembaga mana pun (semua hiasan digambar sendiri, stempel bertulisan CONTOH, nomor sertifikat memuat `/CONTOH/`); tiap halaman bertulisan "CONTOH · data fiktif untuk uji SMAGA Talent Hub · bukan dokumen resmi"; tidak ada yang disalin dari internet; hanya berjalan bila `APP_ENV=local`.
- **Alasan:** permintaan lead: "Lalu untuk sertifikat olimpiade, laporan penelitian, piagam, sertifikat juara, sertifikat krenova, dan yang lainnya berikan contoh yang real yang bisa seperti dipakai di lomba real jangan gada isinya".
- **Dampak:** `database/seeders/Support/Demo/*` (penentu jenis dokumen, penggambar, perender dompdf sendiri), templat `database/seeders/demo/*.blade.php`, `DemoFiles::realistic()` (proses tes tetap memakai placeholder supaya tes tidak melambat; `STH_DEMO_FILES=placeholder` melewati perenderan). Perintah lokal `php artisan sth:demo:refresh-files` mengganti isi berkas demo di database yang sudah ada tanpa seed ulang: hanya baris `files` di folder `demo/` (isi di path yang sama, `size`, `sha256`), aman diulang; `--lengkapi` satu-satunya pilihan yang menambah baris (berkas laporan penelitian untuk laporan riset demo). Seed demo lokal bertambah sekitar 30 detik (98 PDF). Dijalankan di database lokal lead pada 6 Okt 2026: 97 berkas diganti, 14 berkas di luar `demo/` dan tabel lain tidak berubah.
- **Status:** final (keputusan user). *Sementara — bisa diubah user:* sertifikat menyebut nama penyelenggara dari data demo (Puspresnas, Universitas Diponegoro, dan seterusnya) seperti yang tampil di layar aplikasi; surat demo = templat sekolah + gambar tanda tangan contoh + footer CONTOH, jadi tidak sama byte demi byte dengan hasil "Buat PDF".

### D-107 · STH tidak membuat e-flyer; Humas mendapat "Data untuk flyer" · 6 Okt 2026
- **Keputusan:** Generator e-flyer dihapus: gambar flyer di panel, pilihan "Template e-flyer", dan "Download PNG". Humas merancang flyer sendiri di alatnya; aplikasi hanya menyediakan datanya. Panel "Siapkan publikasi" menampilkan **"Data untuk flyer"**: Nama, Kelas, Capaian, Lomba, Cabang, Tingkat, Penyelenggara, Bidang, Tanggal lomba, Guru pembimbing, dan Judul penelitian bila ada (untuk tim: daftar anggota, ketua lebih dulu), tanpa poin dan tanpa NIS/NISN. Tombol **"Salin data"** menyalin semuanya sebagai teks, satu fakta per baris. Caption, kanal, tanggal/jam, "Jadwalkan", "Tandai dipublikasikan", perubahan status otomatis berikut pengingatnya, dan Apresiasi tidak berubah. Humas › Arsip tidak lagi punya kolom Flyer; sel terakhirnya "Lihat" (membuka panel publikasi itu).
- **Alasan:** permintaan lead: "Untuk bagian ini (Humas kemudian membuat e-flyer, caption, berita website sekolah, dan menentukan jadwal publikasi media sosial) Itu mereka membuat flyer manual bukan di generate dari app ini, jdi web ini hanya menyediakan data data yang bisa digunakan oleh humas untuk membuat flyer nya".
- **Dampak:** `GenerateFlyer`, `FlyerRenderer`, dan kemampuan policy `generateFlyer` dihapus; kelas baru `FlyerData` di atas `AchievementFacts` (aturan izin tidak berubah: D-16, D-71; siswa tanpa izin publikasi tidak pernah disebut); `PublicationDue` tidak lagi menjanjikan flyer. **Tanpa migrasi dan tanpa menghapus data:** kolom `publications.flyer_file_id`, `FilePurpose::Flyer`, dan aturan `FilePolicy` untuk berkas flyer tetap ada, jadi berkas yang dibuat sebelum hari ini tetap sah dan masih bisa dibuka oleh peran yang dulu boleh (D-75), tetapi tidak ada layar yang menautkannya.
- **Status:** final (keputusan user). Mempersempit D-47 serta bagian flyer dari D-71 dan D-75 ke berkas yang dibuat sebelum 6 Okt 2026. *Sementara — bisa diubah user:* tidak ada upload untuk flyer yang sudah jadi (kolom dan policy-nya masih ada, jadi bisa ditambahkan tanpa migrasi); berkas flyer lama disimpan, tidak dihapus; anggota tim tanpa izin publikasi tidak dicantumkan dan hanya dihitung dalam satu keterangan; kalimat izin foto berada di luar blok yang disalin; "tanggal hasil" tidak ditampilkan karena STH hanya menyimpan tanggal mulai dan tanggal final.

### D-108 · `letters.printed`: preview surat yang sudah terbit sama dengan isi PDF-nya · 6 Okt 2026
- **Keputusan:** Saat PDF surat dibuat, isi yang dicetak (isi surat dan templat yang dipakai) disimpan di kolom baru `letters.printed`. Preview TU untuk surat yang sudah terbit digambar dari simpanan itu, bukan dari data hari ini. Surat lama tanpa simpanan diberi keterangan "Menurut data sekarang, bisa beda dari PDF. Lihat isi persisnya lewat Cetak."
- **Alasan:** temuan uji ulang QA4 oleh S: preview surat terbit digambar ulang dari templat, penanda tangan, kelas, dan data guru hari ini, sehingga bisa berbeda dari PDF yang sudah beredar.
- **Dampak:** migrasi `2026_10_06_120000_qa_add_printed_to_letters` (json, boleh kosong), `IssueLetters` / `RevokeLetter` (menulis sekali), `LetterPdf::printed()`, `LetterContent::fromArray()`, `LetterTemplate::fromArray()`, `TataUsaha\SuratTugas`. Kolom ini memuat nama, NIS, dan NIP di samping PDF-nya, jadi tidak ikut dicatat di log aktivitas.
- **Status:** final (dikerjakan S di PR 12, dicatat atas permintaan S di `docs/dev-notes/QA4.md`).

## D9. Uji ulang M3 dan keputusan lead 7 Okt 2026 (QA5)

Hasil uji ulang sistem setelah PR 13 (M3) di browser, ditambah jawaban lead atas pertanyaan uji dan isi dokumen klien "SMAGA TALENT HUB (STH)" untuk hal yang tidak ditanyakan (lead: "baca smaga talent hub docxnya itu dari klien, kalo tidak ada yg mention ini, jadi tentukan sendiri uxnya yg enak"). Catatan pengerja: `docs/dev-notes/QA5.md`.

### D-109 · "Pulihkan" ke Peserta pembinaan wajib memilih guru pembina · 7 Okt 2026
- **Keputusan:** Pilihan talenta yang dipulihkan ke **Peserta pembinaan** harus punya guru pembina. Dialog "Pulihkan" kini punya pilihan "Guru pembina" (berisi guru pembina pilihan itu bila sudah ada, keterangan "Wajib kalau dipulihkan ke Peserta pembinaan."). Tanpa guru pembina, pemulihan ke Peserta pembinaan ditolak dengan "Peserta pembinaan perlu guru pembina. Pilih guru pembinanya." Tahap lain tetap tanpa syarat. Pilihan tahap menulis nomor tahapnya ("Tahap 2 · tahap sebelumnya") supaya urutannya terbaca.
- **Alasan:** jawaban lead atas pertanyaan uji ("Peserta pembinaan tanpa pembina nanti dibina siapa?"): "Wajib pilih guru pembina".
- **Dampak:** `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null, ?TalentStatus $to = null, int|string|null $mentorId = null)`; guru yang dipilih harus akun guru yang aktif (`mentor_id`: "Pilih guru pembina dari daftar."); tanpa `$mentorId` dipakai guru pembina yang sudah tercatat; `MENTOR_MESSAGE`; guru pembina tercatat yang sudah tidak aktif hanya menahan pemulihan ke Peserta pembinaan ("Guru pembina yang tercatat tidak aktif lagi. Pilih guru pembina lain.") dan tidak dipilihkan lebih dulu di dialog; audit `talent.restored` mencatat `mentor_id`. `Kesiswaan\Talenta` (`restoreMentor`, galat di bawah pilihan). Tanggal asesmen tetap tidak wajib.
- **Status:** final (keputusan user). Menggantikan bagian *sementara* D-104 "pulih langsung ke Peserta pembinaan tidak mewajibkan guru pembina".

### D-110 · Guru pembimbing diberi tahu keputusan laporan dan jadwal apresiasi binaannya · 7 Okt 2026
- **Keputusan:** Guru pembimbing sebuah pendaftaran menerima notifikasi (lonceng + email) ketika Kesiswaan **memverifikasi, meminta revisi, atau menolak** laporan hasil binaannya, dan ketika Humas **menjadwalkan** prestasi binaannya di acara apresiasi, **melepasnya** dari acara, atau **menghapus** acaranya (satu notifikasi per guru untuk semua binaannya di acara itu). Isinya nama siswa dan capaian, catatan/alasan Kesiswaan, nama dan tanggal acara; sapaan "Yth. <nama lengkap>,"; link ke halaman binaan (Guru › Siswa binaan). Tidak dikirim bila guru tidak bisa masuk (dibekukan, diarsipkan) atau bukan guru lagi.
- **Alasan:** laporan: jawaban lead "Ya, beri notifikasi". Apresiasi: dokumen klien, bagian Penjadwalan Apresiasi: "Siswa dan guru pembimbing menerima notifikasi". Peran guru menurut dokumen klien: memantau siswa binaannya.
- **Dampak:** notifikasi baru `App\Notifications\Verification\BinaanReportDecided` (`report.binaan_verified|binaan_revision|binaan_rejected`) dari `VerifyReport`, `RequestReportRevision`, `RejectReport`; `App\Notifications\Publications\BinaanAppreciationNotice` (`appreciation.binaan_scheduled|binaan_released|binaan_cancelled`) dari `RecordAppreciation`, `ReleaseAppreciation`, `DeleteAppreciationEvent`. Penerima: `RequestReportRevision::mentorUser(Report)` dan `RecordAppreciation::mentorUser(Achievement)` (`User::gurus()->canLogIn()`). Siswa tetap menerima notifikasinya sendiri seperti sebelumnya. Tidak ada kolom baru.
- **Status:** final (laporan: keputusan user; apresiasi: dokumen klien). Guru pembina talenta (bukan guru pembimbing lomba) tetap tidak diberi tahu soal tahap talenta (backlog #56). (10 Okt 2026: untuk notifikasi "dijadwalkan", guru kini menerima satu notifikasi per sekali "Masukkan" yang menyebut semua binaannya dalam klik itu, lihat D-124. Kalimat ketiga jenis notifikasi apresiasi kini memuat jam acara, lihat D-123; pengelompokan untuk "dilepas" dan "acara dihapus" tidak berubah.)

### D-111 · "Download data" Talenta mengikuti filter layar; status kosong tidak bisa dipilih · 7 Okt 2026
- **Keputusan:** File Excel Talenta berisi baris yang cocok dengan filter yang sedang dipakai di layar (jenis, cabang, kelas, pencarian) dan status yang dipilih di dialog (sudah begitu sejak D-104; kalimat di dialog kini menyebutnya: "File mengikuti filter di layar."). Di dialog, status yang tidak punya baris dengan filter itu bertanda "Kosong" dan tidak bisa dicentang; tab yang kosong tidak mencentang apa pun lebih dulu; "Pilih semua status" mencentang yang berisi saja. Kalau pilihan tetap tidak menghasilkan baris, tidak ada file kosong: dialog menulis "Status yang dipilih belum punya data. Pilih status lain atau ubah filter." dan alamat langsung menjawab dengan toast "Tidak ada pilihan talenta dengan status dan filter ini. Tidak ada yang di-download."
- **Alasan:** pertanyaan uji "Excel kosong kalau ganti tab yang tidak ada siswanya, itu memang ngikuti filter?"; jawaban lead: "Ikut filter layar". File kosong tidak pernah berguna, jadi pilihan yang menghasilkannya ditutup di dialog.
- **Dampak:** `Kesiswaan\Talenta` (`filledStatuses()`, `EXPORT_EMPTY_CHOICE`, `EXPORT_NOTHING`, opsi dialog dengan `disabled`); semua status berisi yang dicentang dikirim sebagai `semua` (baris "Filter:" di file tidak lagi mendaftar semua tahap). `<x-sth.radio-pick>` sudah mendukung opsi `disabled`.
- **Status:** final (keputusan user).

### D-112 · Surat tugas di Guru › Siswa binaan terbuka di popup · 7 Okt 2026
- **Keputusan:** Di halaman detail binaan, surat tugas siswa dan surat perintah tugas guru yang sudah dikirim TU punya tombol "Buka" yang menampilkan PDF-nya di popup preview (D-105); link-nya tetap men-download. Aturan siapa boleh membuka surat (`LetterPolicy`, D-99) tidak berubah.
- **Alasan:** dokumen klien bagian Surat Tugas: siswa dan guru dapat melihat dan men-download surat tugas; lead menyerahkan UX-nya ("tentukan sendiri uxnya yg enak"). Di layar ini bukti laporan sudah memakai popup, sedangkan surat masih ikon download saja.
- **Dampak:** `Guru\BinaanDetail::letterRows()` (`preview`, `previewType`, `name` dari `FileController::previewUrl()` / `previewType()`), `livewire/guru/binaan-detail.blade.php`. Tanpa perubahan header berkas: jawaban PDF inline tetap aturan D-105.
- **Status:** final untuk layar guru. Menjawab sebagian pertanyaan *sementara* D-105 "surat tugas belum memakai popup"; layar siswa (Lomba saya) dan TU masih download saja (backlog #69).

## D10. Eksplorasi ulang setelah PR 14 (QA6) · 7 Okt 2026

Uji ulang alur ujung ke ujung dan isian ekstrem setelah PR 14 di-merge. Untuk hal yang tidak disebut dokumen klien, UX diserahkan lead ("tentukan sendiri uxnya yg enak"). Catatan pengerja: `docs/dev-notes/QA6.md`.

### D-113 · Surat yang sudah terbit sah di halaman verifikasi QR, juga sebelum "Kirim" · 7 Okt 2026
- **Keputusan:** `/verifikasi-surat/{kode}` menganggap setiap surat yang sudah dibuat PDF-nya ("Buat PDF") sebagai surat yang ada: "Sah" bila belum dicabut, "Dicabut" bila sudah dicabut (dengan nomor penggantinya bila sudah ada), baik sudah maupun belum dikirim. Sebelumnya hanya surat yang sudah dikirim yang dikenali, sehingga surat yang dicetak TU sebelum "Kirim" (tombol "Cetak" ada sejak D-84) menjawab "Tidak ditemukan" dan terlihat seperti surat palsu.
- **Alasan:** temuan QA Q1-23 (backlog #61) menyebut dua jalan: jawab "Dicabut"/"Sah", atau tidak ada "Cetak" sebelum "Kirim". Dokumen klien menyebut surat "terbit" setelah ditandatangani dan diterbitkan; pengiriman ke siswa adalah langkah distribusi sesudahnya. Kode verifikasi acak dan hanya tercetak di PDF-nya, jadi tidak ada data yang bocor.
- **Dampak:** `LetterVerificationController` (tanpa syarat `sent_at`, juga untuk nomor pengganti). Halaman dan isinya tidak berubah (tanpa data pribadi).
- **Status:** sementara. Kalau lead lebih memilih "tidak ada Cetak/Download sebelum Kirim", entri baru menggantikan ini.

### D-114 · Jadwal asesmen yang baru diisi atau diubah tidak boleh sebelum hari ini · 7 Okt 2026
- **Keputusan:** Di Kesiswaan › Talenta, tanggal "Jadwal asesmen" yang baru diisi atau diubah harus hari ini atau sesudahnya ("Jadwal asesmen tidak boleh sebelum hari ini."). Tanggal yang sudah tersimpan tetap sah walaupun sudah lewat, supaya panel tetap bisa disimpan setelah asesmen berlangsung (misalnya hanya catatan yang diubah).
- **Alasan:** temuan QA Q2-13 (backlog #62): tahun 2020 diterima dan dikirim ke siswa sebagai "Jadwal asesmen". Isian ini jadwal, bukan catatan riwayat.
- **Dampak:** `UpdateTalentAssessment::validated(array $details, ?TalentApplication $current = null)` (dipanggil juga oleh `MoveTalentStage`, `RejectTalentApplication` dan pemeriksaan "Tidak lolos" di `Kesiswaan\Talenta`).
- **Status:** final (UX diserahkan lead).

### D-115 · Ketua tim bisa membatalkan undangan yang belum dijawab · 7 Okt 2026
- **Keputusan:** Selama tim menunggu konfirmasi dan pendaftaran lomba masih dibuka, ketua bisa membatalkan undangan anggota yang belum menjawab (tombol X "Batalkan undangan …" di panel Daftar lomba). Barisnya dihapus, jadi siswa itu boleh diundang lagi. Undangan yang sudah dikonfirmasi atau ditolak tidak bisa dibatalkan. Setelah pembatalan berlaku aturan D-36 seperti setelah penolakan: semua sisa anggota sudah konfirmasi dan tim minimal 2 orang → lanjut ke Kepala Sekolah. Siswa yang undangannya dibatalkan mendapat notifikasi. Di hasil pencarian anggota, siswa yang belum aktivasi akun ditandai "belum aktivasi akun"; undangannya baru terlihat setelah ia aktivasi.
- **Alasan:** temuan QA Q1-22 (backlog #66): undangan yang tidak dijawab (misalnya teman yang belum aktivasi) menahan tempat di tim sampai batas pendaftaran, dan tim tidak bisa diteruskan lebih cepat.
- **Dampak:** Action baru `App\Actions\Registrations\WithdrawInvitation` (izin `RegistrationPolicy::invite`, kunci baris, audit `registration.invitation_withdrawn`), notifikasi `InvitationWithdrawn` (mail + database), `Siswa\Pendaftaran\Form::batalkanUndangan()`, tanda `pending` di hasil pencarian.
- **Status:** final (UX diserahkan lead).

### D-116 · Surat yang belum dibuat mengambil isian undangan dan pelaksanaan dari surat yang lain, kecuali isian yang diketik TU sendiri · 7 Okt 2026
- **Keputusan:** Di TU › Surat tugas, surat yang belum dibuat PDF-nya mengambil "Surat dari", nomor dan tanggal surat undangan, hari, tanggal, pukul dan tempat dari surat yang lain (biasanya surat guru dari surat siswa) bila TU mengetiknya di surat yang lain atau surat itu sudah dibuat PDF-nya. Isian yang sudah diketik TU untuk surat itu sendiri tidak pernah ditimpa, juga bila TU sengaja mengosongkannya atau mengembalikannya ke isian awal dari data lomba. Nomor surat, tanggal surat dan "Hal" tetap milik surat masing-masing.
- **Alasan:** temuan QA Q1-15 (backlog #61): TU mengetik nomor dan tanggal undangan dua kali. Ini bukan satu draf bersama (D-83 tetap: dua form, dua "Buat PDF"); hanya isian awal yang lebih tepat.
- **Dampak:** `TataUsaha\SuratTugas` (`SHARED_FIELDS`, `$typed` = isian yang diketik TU per surat selama kunjungan itu, `carrySharedFields()` saat halaman dibuka, saat pindah tab dan setelah "Buat PDF").
- **Status:** final (UX diserahkan lead). Melengkapi D-83.

## D11. Arah visual v3 "Tinta dan Sampul" · 7 Okt 2026

Hasil audit UI 6 Okt 2026 (semua layar tiap peran pada lebar 1440 px dan 390 px) dan pilihan lead sesudahnya. Lead memilih palet dan huruf, mengoreksi usulan pertama ("banyak gambar yang berkurang … monoton"), lalu pada 7 Okt 2026 meminta semua paket dikerjakan dengan pilihan yang masih terbuka mengikuti saran pengerja. Spesifikasi: `docs/design/DESIGN-SPEC-v3.md`. Catatan pengerja: `docs/dev-notes/UI3.md`.

### D-117 · Arah visual v3 "Tinta dan Sampul" menggantikan tampilan spec v2 · 7 Okt 2026
- **Keputusan:** Tampilan STH memakai kerangka tinta dongker `#1B2A4E` di atas netral dingin `#F3F5F8`, satu keluarga huruf (Public Sans), dan skala huruf tetap 13 · 14 · 15 · 16 · 18 · 20 · 24 · 28 · 36 · 44 px. Tidak ada teks di bawah 13 px dan tidak ada ukuran yang ditulis tangan di layar (kecuali preview surat TU yang meniru kertas). Sudut 6 px (tombol, kolom isian), 8 px (ubin), 10 px (kartu), 4 px (tag). Warna hanya membawa arti: status, emas untuk poin, dan empat warna bidang. Gradasi, bayangan berpendar, gumpalan buram, kartu miring, dan semburat latar dihapus. Gambar tetap ada di tempat orang menjelajah dan tiap gambar punya arti: sampul lomba selalu terisi (D-118), spanduk beranda siswa, hero beranda publik, dan panel halaman masuk memakai motif lintasan enam titik (enam tahap Jejak Prestasi) dan tidak memuat kartu yang melayang di atas atau di sampingnya, kotak ikon ubin angka dan ubin bidang tetap berwarna. Tabel, form, dan antrean kerja staf sengaja tenang. Setelah lead mengecek di localhost (7 Okt 2026): satu jalan ke tiap tujuan (butir menu tidak diulang sebagai tombol atau link di layar yang sama: "Cari lomba" di spanduk siswa, "Masuk" di hero publik, "Pintasan" di Status sistem dibuang), kotak dalam satu grid selalu sama tinggi, dan tiga teratas peringkat memakai medali emas, perak, perunggu. Kontrak komponen `<x-sth.*>` dan nama kelas helm tidak berubah; yang baru: `<x-sth.sampul>`, kelas `.spanduk`, prop `zero` di ubin angka, `table-class` di tabel.
- **Alasan:** tampilan v2 ("Behance edu-soft") terbaca sebagai templat dashboard dan terlalu ringan untuk Kepala Sekolah, Tata Usaha, dan Admin. Terukur pada layar lama: 32 ukuran huruf, 53% teks di bawah 14 px, garis kolom isian 1,3 : 1 (pedoman 3 : 1), cincin fokus 2,0 : 1, lima tata letak rusak (empat di HP). Tinta dipilih supaya tidak mirip situs profil sekolah yang biru dan kuning, dan supaya logo sekolah nanti jadi satu-satunya yang ramai di kerangka. Usulan pertama yang membuang sampul dan spanduk ditolak lead karena melelahkan dilihat lama.
- **Dampak:** `resources/css/app.css` (token, skala `--text-*`, kelas helm, `.sampul*`, `.spanduk*`), `package.json` (`@fontsource/public-sans` menggantikan Plus Jakarta Sans dan Urbanist), `resources/images/{sampul,motif}`, 79 berkas tampilan (461 ukuran tulis-tangan → kelas skala), komponen `stat-tile`, `sidebar`, `lomba-card`, `facts`, `avatar`, `table`, `topbar`, tata letak `auth`, layar beranda siswa dan publik, favicon, warna email OTP dan PDF portofolio. Lima tata letak rusak diperbaiki: Persetujuan Kepala Sekolah di HP, tabel Talenta dan Kelola lomba di HP, kepala halaman siswa publik di HP, baris berkas surat terbit TU, antrean surat TU di HP. Tur menu siswa: butir pertama menunjuk butir menu "Cari lomba" karena tombol di atas menu dihapus. Mockup v2 tidak digambar ulang (riwayat desain).
- **Status:** final. Menggantikan bagian tampilan D-19 dan `DESIGN-SPEC-v2.md` §2–§3. Saat keputusan ini dibuat logo sekolah belum dipakai dan tanda merek masih ikon perisai; pada hari yang sama lead meminta lambang dipasang (D-120).

### D-118 · Sampul lomba: poster asli atau gambar bidang; rute `files.cover` · 7 Okt 2026
- **Keputusan:** Tiap lomba selalu punya sampul. Kalau Kesiswaan meng-upload poster (isian "Sampul" di Kelola lomba, sudah ada sejak W2-B: `competitions.cover_file_id`), poster itu yang tampil. Sesudah lead melihat hasil pertama di localhost (7 Okt 2026: "poster di kiri, poster portrait, lalu di kanannya keterangannya", juga di halaman detail), poster berdiri tegak di kiri: di kartu lomba mengisi kotak 3:4 (sedikit tepinya boleh terpotong), di kepala halaman Detail lomba dan Undangan tim tampil utuh dan bisa diperbesar; nama, status, dan lima info utama (batas daftar, tanggal lomba, tingkat, pelaksanaan, peserta) ada di kanannya, sisanya tetap di bawah. Kartu prestasi di Portofolio mengikuti bentuk yang sama (lead: "iya dong di porto juga"). Pita lebar tinggal di kartu publik "Prestasi terbaru" dan antrean Humas, yang hanya memakai gambar bidang. Kalau tidak, tampil gambar templat sesuai bidang lomba: tiga variasi per bidang, dipilih dari ID lomba sehingga satu lomba selalu tampil sama. Gambar terang untuk kartu, versi pekat untuk halaman detail. Di HP kotak poster kartu selebar 104 px. Poster ditayangkan lewat rute baru `GET /berkas/sampul/{file}` (`files.cover`): hanya untuk pengguna yang sudah login dan lolos `FilePolicy::view` (izin lihat lombanya), dikirim sebagai JPEG yang diperkecil (sisi terpanjang paling besar 640 px), boleh disimpan browser pengguna itu selama sehari (`Cache-Control: private, max-age=86400`), dan **tidak dicatat di log audit**. Halaman publik dan antrean Humas hanya memakai gambar bidang.
- **Alasan:** lead (6 Okt 2026): "kartu lomba tetap pakai sampul … dapat diisi poster asli jika ada, jika tidak … sampul template berdasarkan kategori cabang ilmu tiap lomba". "Cabang ilmu" diartikan empat bidang yang ada (D-11); kategori yang lebih rinci butuh isian baru di data lomba. `files.show` tidak cocok untuk `<img>` di katalog: tiap permintaan menulis satu catatan audit, jawabannya `no-store`, dan berkasnya sampai 2048 px, sehingga katalog berisi sembilan poster menulis sembilan catatan dan mengunduh ulang semuanya tiap dibuka. Poster lomba bukan data pribadi dan memang dilihat semua pengguna yang boleh melihat lombanya.
- **Dampak:** `FileController::cover()` + `coverUrl()`, `App\Domain\Files\CoverThumbnail` (varian kecil dibuat saat pertama diminta dan disimpan di disk privat, `sampul/<id berkas>.jpg`; dihapus bersama berkas aslinya lewat `StoreUpload::discard()`), rute `files.cover` (throttle sendiri 240/menit; tamu diarahkan ke halaman masuk, dan alamat gambar sampul tidak pernah jadi halaman tujuan setelah login: `FortifyServiceProvider`), `App\Support\Sampul` (alamat poster hanya dibuat bila pengguna memang boleh melihat lombanya, jadi lomba yang sudah ditutup di riwayat siswa langsung memakai gambar bidang), `<x-sth.sampul>`, `LombaPresenter::card()` (`poster`), layar Cari lomba, Beranda, Detail lomba, Undangan tim, Portofolio, Guru › Lomba, form Kelola lomba (preview sampul sekarang). Data demo lokal: tujuh lomba yang dipublikasikan mendapat poster contoh yang digambar saat seeding dan bertanda CONTOH (aturan D-106 berlaku; `sth:demo:refresh-files --lengkapi` menambahkannya ke database yang sudah ada). `docs/SECURITY.md` §3d mendapat catatan bertanggal.
- **Status:** final untuk urutan sampul dan gaya (pilihan lead 7 Okt 2026: "sesuai saranmu"). **Sementara** dan perlu ditinjau pemilik backend: jawaban `files.cover` tidak diaudit dan boleh disimpan di browser pengguna (menyesuaikan SECURITY §3d "setiap download dicatat" khusus untuk gambar sampul); poster tidak tampil di halaman publik; "cabang ilmu" = empat bidang.

### D-119 · Staf tidak disapa "kamu"; satu nama untuk tiap menu; tanpa kode internal di layar · 7 Okt 2026
- **Keputusan:** Siswa tetap disapa "kamu". Layar, notifikasi, dan email untuk guru, Kesiswaan, Kepala Sekolah, Tata Usaha, Humas, dan Admin memakai kalimat tanpa kata ganti; "Anda" hanya bila kalimatnya tidak bisa tanpa kata ganti. Layar yang dipakai siswa dan staf sekaligus (masuk, aktivasi, keamanan akun, notifikasi, halaman galat) memakai kalimat netral bila bisa. Nama menu sama di semua tempat: bilah tab HP memakai nama menu samping ("Lomba saya", "Portofolio", bukan "Lombaku", "Profil"), dan dialog yang dibuka tombol "Upload info lomba" milik guru berjudul sama dengan tombolnya. Kode catatan internal seperti "(U-04)" tidak tampil di layar.
- **Alasan:** temuan audit UI (T6) dan backlog #67: guru, Kepala Sekolah, dan Admin ikut disapa "kamu", sementara satu layar Kepala Sekolah memakai "Anda". Di sekolah, menyapa guru dengan "kamu" terasa janggal. Nama menu yang berbeda antara desktop dan HP membuat siswa mencari dua kali.
- **Dampak:** teks di `resources/views/livewire/{guru,kesiswaan,kepsek,sekolah,tata-usaha,humas,admin}`, kelas Livewire yang sepadan, `App\Support\Navigation::tabbar()`, `Siswa\TurMenu::mobileTips()`, notifikasi untuk staf, dan tes yang memeriksa teks itu. Daftar per kalimat: `docs/dev-notes/UI3.md`. CLAUDE.md §3.9 diperbarui.
- **Status:** final (lead 6 Okt 2026: "sapaan ikut saranmu"). Melengkapi D-19 dan D-89; menutup bagian "Anda / kamu untuk staf" dan "kode internal" backlog #67.

### D-120 · Lambang sekolah menjadi tanda merek STH · 7 Okt 2026
- **Keputusan:** Tanda merek STH di layar aplikasi (menu samping, kepala halaman publik, halaman masuk dan akun, halaman galat) dan ikon tab browser memakai lambang SMA Negeri 3 Semarang, menggantikan ikon perisai bercentang yang sejak awal hanya pengganti sementara. Tulisan "SMAGA Talent Hub" dan "SMA Negeri 3 Semarang" di sampingnya tetap. Di atas latar tinta (panel halaman masuk) lambang berdiri di ubin putih, supaya birunya tidak tenggelam. Lambang tidak digambar ulang dan warnanya tidak diubah: berkas dari lead (`smaga.jpeg`, 233 × 320 px) hanya dibuang latar putihnya.
- **Alasan:** permintaan lead setelah melihat hasil di localhost (7 Okt 2026): "pasanglah menggantikan logo centang yg selama ini menjadi logo smaga talent hub". Pada 6 Okt 2026 lead masih meminta lambang hanya jadi acuan warna dan belum dipakai; ini pilihannya yang baru. Arah visual v3 memang disiapkan untuk ini: kerangka berwarna tinta supaya lambang jadi satu-satunya yang ramai (D-117). Lambang adalah lambang lembaga, bukan data pribadi (alasan yang sama dengan D-84).
- **Dampak:** `resources/images/brand/lambang-sma3.png` (119 × 160, lewat kelas CSS `.brand-mark`, di-hash Vite), `<x-sth.brand>` (tidak lagi memuat ikon `shield`), `public/favicon.ico` (16/32/48), `public/icon-192.png`, `public/apple-touch-icon.png`, `layouts/partials/head.blade.php`; `public/favicon.svg` dihapus (tidak ada berkas vektor lambang). Ikon `shield` tetap dipakai sebagai ikon biasa (2FA, Log audit). Email dan PDF tidak berubah (tidak pernah memuat tanda merek; kop surat sudah memakai lambang sejak D-84). Mockup tetap dengan logo pengganti (riwayat desain).
- **Status:** final (permintaan lead). Menyesuaikan D-20 ("logo memakai placeholder") dan kalimat terakhir D-84 ("D-20 tetap berlaku untuk … layar aplikasi") khusus untuk tanda merek; bagian lain D-20 (nama, NIS, foto, data contoh) tidak berubah. **Perlu dari sekolah:** berkas lambang yang lebih besar atau vektor; berkas sekarang hanya layak sampai tinggi sekitar 56 px.

## D12. Email nyata saat pengembangan · 8 Okt 2026

Sampai 8 Okt 2026 semua email STH hanya masuk Mailpit. Lead meminta jalur kirim nyata disiapkan untuk pengembangan lebih dulu; penyedia untuk produksi diputuskan terpisah. Catatan pengerja: `docs/dev-notes/EM1.md`.

### D-121 · Email nyata saat pengembangan lewat Brevo, hanya ke alamat yang didaftarkan · 8 Okt 2026
- **Keputusan:** Selama pengembangan, email nyata STH dikirim lewat Brevo (SMTP relay, paket gratis) dengan pengirim sementara berupa alamat Gmail tim, karena `noreply@sman3-smg.sch.id` belum bisa dipakai (DNS sekolah belum bisa diubah). Stack lokal hanya mengirim keluar email yang **semua** penerimanya ada di `STH_MAIL_ALLOWLIST`; email lain tetap masuk Mailpit. Caranya: `MAIL_MAILER=allowlist`, dengan `MAIL_HOST` dan seterusnya berisi data Brevo. Mailer ini hanya boleh dipakai di `APP_ENV=local`: di environment lain aplikasi menolak boot. Sebaliknya, di `APP_ENV=local` aplikasi menolak boot bila mailer utamanya akan mengirim semua email ke layanan nyata (SMTP dengan login atau `MAIL_URL`, `ses`, `postmark`, `resend`, `sendmail`): yang boleh hanya `allowlist`, SMTP tanpa login (Mailpit), `log`, dan `array`. Jadi dari stack lokal, termasuk proyek E2E yang membaca `.env` yang sama, email nyata hanya bisa keluar lewat daftar alamat. Satu entri daftar adalah satu alamat utuh, atau alamat dengan tanda `*` di bagian nama sebelum `@` (mis. `nama+*@gmail.com`); entri yang membuka satu domain penuh diabaikan. Tanpa pengaturan ini semua email tetap ke Mailpit seperti sebelumnya, dan itu tetap cara kerja biasa.
- **Alasan:** lead (8 Okt 2026): "kita coba pakai brevo buat dev dan email sendernya sementara blm bisa pakai yg @sman itu". Kode OTP aktivasi hanya dikirim lewat email, jadi alur nyata harus bisa diuji jauh sebelum go-live. Data demo, scheduler, dan factory menulis ke alamat fiktif (`nama@sth.test`): lewat penyedia nyata itu menjadi email gagal permanen, merusak reputasi akun baru, dan menghabiskan batas 300 email per hari. Stack lokal mengirim email sendiri tanpa diklik (dua email ke Humas demo dalam beberapa menit pertama), dan di mode uji OTP lokal alamat yang belum diverifikasi pun mendapat email (D-68). Brevo dipilih tim setelah dibandingkan dengan Resend: batas harian 300 (Resend 100), pengirim Gmail bisa menjangkau penerima mana pun, dan cukup SMTP biasa tanpa paket baru.
- **Dampak:** `App\Mail\Transport\AllowlistTransport` dan `RecipientAllowlist`, mailer `allowlist` dan `mailpit` di `config/mail.php`, `AppServiceProvider` (pendaftaran transport dan dua penjaga boot di `guardUnsafeConfiguration()`), `sth:mail-test` (menyebut apakah alamat uji benar-benar dikirim atau hanya masuk Mailpit), `.env.example`. Tes: `tests/Unit/Mail`, `tests/Feature/Mail`. Tanpa migrasi; production tidak berubah (SMTP dengan login tetap cara biasa di sana). Yang berubah untuk pengembang: `MAIL_MAILER=smtp` dengan login penyedia di `.env` lokal tidak lagi bisa dipakai, termasuk untuk `sth:mail-test`; pakai `allowlist`.
- **Yang diketahui tentang Brevo (dicek 8 Okt 2026, uji pertama terkirim):** alamat pengirim Gmail diganti Brevo menjadi `nama@<id>.brevosend.com` sampai domain sekolah diautentikasi; Brevo menambahkan header berhenti berlangganan ke email transaksional, sehingga Gmail menampilkan "Berhenti berlangganan" di samping pengirim, dan alamat yang berhenti berlangganan diblokir Brevo, termasuk untuk kode OTP (menggantinya hanya di paket Enterprise); link di bagian HTML dibungkus domain pelacak Brevo (mematikannya hanya di paket Enterprise); log dan preview email, termasuk kode OTP, disimpan tanpa batas waktu kecuali masa simpannya diatur; di atas 300 email per hari, sampai 1.000 email ditahan dan dikirim belakangan (tidak berguna untuk kode OTP yang berlaku 10 menit) dan lebihnya tidak terkirim.
- **Status:** sementara, hanya untuk pengembangan. Masih terbuka dan harus diputuskan sebelum go-live: penyedia untuk produksi (empat hal di butir sebelumnya ikut menentukan), alamat pengirim `noreply@…`, dan persetujuan sekolah atas pihak ketiga yang memproses alamat email siswa (UU PDP; §F, DEPLOY §9, backlog #26). Melengkapi D-15.

## D13. Uji email dan notifikasi lead 9 Okt 2026 (EM2) · 10 Okt 2026

Pada 9 Okt 2026 lead menguji semua email dan notifikasi STH lewat Brevo: 67 langkah dalam 11 cerita (laporan "Hasil uji email dan notifikasi STH", diekspor 10 Okt 2026: 67 dari 67 selesai, 4 bermasalah, 19 bercatatan). Kode yang diuji adalah EM1 (`4766b56`), sebelum QA9 dan QA10. Tiap catatan diperiksa dulu di kode, lalu tujuh pengerja memperbaikinya paralel. Yang diminta lead di laporan itu ditulis "lead"; yang tidak disebut lead dan dipilih koordinator ditulis begitu dan berstatus sementara sampai lead melihatnya. Catatan pengerja: `docs/dev-notes/EM2.md`.

### D-122 · Jadwal publikasi hanya pengingat untuk Humas · 10 Okt 2026
- **Keputusan:** Saat `scheduled_at` sebuah publikasi tiba, sistem hanya mengingatkan semua akun Humas yang aktif ("Waktunya upload publikasi", notifikasi dan email), satu kali per jadwal (`publications.reminded_at`; menjadwalkan ulang mengosongkannya, sehingga waktu baru mendapat pengingatnya sendiri). Status tetap `terjadwal` dan tampil sebagai "Lewat jadwal" (kartu, panel, kotak "Lewat jadwal, belum ditandai terbit" di menu Jadwal, angka di butir menu Jadwal) sampai Humas menekan **Tandai dipublikasikan**. Baru saat itu status menjadi `dipublikasikan`, `published_at` = saat tombol ditekan, dan siswa diberi tahu. Tidak ada publikasi yang menjadi dipublikasikan dengan sendirinya. Tombol itu bertanya dulu di dialog aplikasi "Tandai sudah terbit?" (aturan D-128); dialognya menulis "Siswa langsung diberi tahu." hanya bila memang ada yang diberi tahu, selain itu "Akun siswa tidak aktif, jadi tidak ada notifikasi." Aturan yang sama (D-109) berlaku untuk tiga teks lain yang menyebut kabar ke siswa: pengingat untuk Humas (email dan lonceng), peringatan "Jadwal sudah lewat" di panel, dan keterangan di bawah tanggal. Untuk alumni (akun diarsipkan, masih berizin publikasi, jadi tetap di antrean) ketiganya tidak menjanjikan kabar itu: pengingatnya berbunyi "… supaya statusnya menjadi Dipublikasikan.", peringatannya "Sudah di-upload ke kanal? Tekan Tandai dipublikasikan. Belum sempat? Pilih waktu baru, lalu Jadwalkan ulang.", keterangannya "Status baru menjadi Dipublikasikan setelah tombol Tandai dipublikasikan ditekan." Publikasi tanpa kanal tetap diingatkan (tombol tetap meminta minimal satu kanal). Aturan nama (QA10, `NamedStudent`) tidak berubah: tidak ada pengingat untuk siswa tanpa izin publikasi, akun nonaktif, atau data yang dihapus; alumni dengan izin tetap diingatkan.
- **Alasan:** catatan lead di langkah 5-6 uji email 9 Okt 2026: fungsinya pengingat untuk Humas, dan email "Prestasimu sudah dipublikasikan" seharusnya baru terkirim saat Humas sudah ingat dan menekan tombol tandai dipublikasikan. Sebelumnya siswa menerima email itu pada waktu jadwal padahal belum ada yang di-upload, dan tombol "Tandai dipublikasikan" hilang karena barisnya sudah `dipublikasikan`. Aplikasi tidak memposting ke kanal mana pun, jadi hanya Humas yang tahu kapan publikasi benar-benar terbit.
- **Dampak:** `App\Actions\Publications\RemindDuePublication` dan job `App\Jobs\RemindDuePublications` (tiap 5 menit, menggantikan `AutoPublishPublication` dan `AutoPublishPublications`, yang dihapus); kolom `publications.reminded_at` (migrasi `2026_10_10_100100_em2_add_reminded_at_to_publications`, tanpa backfill, tidak bisa diisi massal); audit `publication.reminded` (`by` = scheduler; label "Pengingat publikasi dikirim ke Humas (otomatis)"); teks `PublicationDue` (argumen baru `$notifiesStudent`, diisi dari `PublishPublication::notifiesStudent()` saat pengingat dikirim: teks pengingat yang sudah tersimpan tidak ikut berubah bila akun siswanya kemudian diarsipkan atau diaktifkan lagi; panel dan dialog dihitung saat dibuka); `Publication::isOverdue()` / `scopeOverdue()`; `HumasQueries::overduePublications()`; angka `humas.jadwal` di menu Jadwal (`Navigation::BADGES`, `NavigationServiceProvider::count()`); `Humas\Publikasi` (`askPublish()` memeriksa caption dan kanal lalu membuka dialog `tandai-terbit`, `publish()` adalah langkah konfirmasi dan tidak berbuat apa-apa tanpa pertanyaan itu); panel publikasi (keterangan "Jadwal ini hanya pengingat. …", untuk baris lewat jadwal: "Tandai dipublikasikan" jadi tombol utama dan isian tanggal diisi waktu berikutnya, bukan waktu yang sudah lewat); kalimat di Humas › Jadwal. Data demo: dua jadwal demo yang sudah lewat dicap sudah diingatkan, supaya stack lokal yang baru tidak mengirim email sendiri. Baris yang terlanjur dipublikasikan otomatis sebelum keputusan ini dibiarkan (tidak ada migrasi data). Bila saat jadwal tiba tidak ada akun Humas yang bisa masuk, barisnya tetap dicap sudah diingatkan (sekali per jadwal) dan tetap tampil "Lewat jadwal". Skenario email (`tests/E2E/support/email-scenario.php`) disesuaikan di langkah 8c, 8d, dan 11a, belum dijalankan ulang.
- **Status:** final (lead). Menggantikan bagian "mengubah status ke `dipublikasikan` (`published_at` = waktu jadwal), memberi tahu siswa" dari D-47; "mengingatkan Humas" dan "dilewati bila izin dicabut" dari D-47 tetap. Belum ditanyakan ke lead (bukan bagian keputusan): pengingat harian selama baris masih lewat jadwal, dan nama tombol "Jadwalkan" (apakah menjadi "Jadwalkan pengingat").

### D-123 · Acara apresiasi punya jam mulai · 10 Okt 2026
- **Keputusan:** "Buat acara" di Apresiasi meminta nama, tanggal, dan **jam mulai** (wajib untuk acara baru; pesan "Isi jam acara."). Jam disimpan di kolom baru `appreciation_events.held_time` di samping `held_on`. Jam yang sudah lewat tetap diterima bila tanggalnya hari ini (upacara pagi ini bisa dicatat dengan jam sebenarnya); tanggal yang sudah lewat tetap ditolak. Acara yang dibuat sebelum kolom ini ada tidak punya jam dan hanya menampilkan tanggal; tidak ada "00.00" yang dikarang. Semua teks waktu acara dibuat di satu tempat, `App\Actions\Publications\EventWhen`: kartu dan dialog "Senin, 12 Oktober 2026, 07.00 WIB", kalimat notifikasi "… pada Senin, 12 Oktober 2026 pukul 07.00 WIB", pilihan acara "12 Okt 2026, 07.00". Acara pada hari yang sama diurutkan menurut jam (Apresiasi, Humas › Jadwal, pilihan acara di panel publikasi).
- **Alasan:** catatan lead di langkah 6-1 uji email 9 Okt 2026: "jangan tanggal tok, kasi jam". Siswa dan guru pembimbing tidak punya layar yang menampilkan acara; notifikasi adalah satu-satunya tempat mereka tahu kapan harus hadir, dan isinya hanya tanggal.
- **Dampak:** migrasi `2026_10_10_100200_em2_add_held_time_to_appreciation_events` (TIME, boleh kosong, tanpa backfill); `AppreciationEvent::heldTime()` / `startsAt()`; `CreateAppreciationEvent::handle(User, string $name, DateTimeInterface|string $heldOn, string $heldTime)` (galat `held_time`); `eventDate` di `AppreciationScheduled`, `AppreciationReleased`, `AppreciationCancelled`, dan `BinaanAppreciationNotice` diisi `EventWhen::sentence()` (bentuk konstruktor tetap); properti audit `held_time` di `appreciation.event_created`, `.recorded`, `.released`, `.event_completed`, `.event_deleted`; isian "Jam mulai" (keterangan "Waktu Indonesia Barat (WIB).") dan contoh "Nama acara" menjadi "Upacara Senin" (tanggal yang diketik di nama terulang di setiap notifikasi). Data demo: acara demo mendapat jam 07.00 pada seed baru; acara yang sudah ada di database lokal tetap tanpa jam.
- **Status:** final (permintaan lead). Melengkapi D-87: aturannya tetap per hari ("Tandai sudah dilaksanakan" mulai hari acara, "Tambah prestasi" sepanjang hari acara). Belum dibuat: "Ubah acara" (jam atau tanggal yang salah hanya bisa diperbaiki dengan menghapus acara lalu membuat yang baru), jam untuk "Jadwal asesmen" Talenta, baris jadwal apresiasi di layar siswa dan guru.

### D-124 · "Tambah prestasi": cari dan centang beberapa; guru pembimbing mendapat satu notifikasi per sekali "Masukkan" · 10 Okt 2026
- **Keputusan:** "Tambah prestasi" di Apresiasi (Humas dan Kesiswaan) bukan lagi satu pilihan panjang. Ada kotak "Cari prestasi" (nama siswa atau nama lomba), daftar bercentang yang tampil 10 per langkah ("Tampilkan lebih banyak", paling banyak 100 baris, lalu "Masih ada prestasi lain. Persempit dengan pencarian."), dan satu tombol "Masukkan" untuk semua yang dicentang, paling banyak 30 sekali masuk. Satu kali "Masukkan" berlaku semua atau tidak sama sekali. Tiap siswa tetap mendapat satu notifikasi per prestasi; tiap **guru pembimbing mendapat satu notifikasi** untuk semua binaannya yang dimasukkan dalam klik itu ("2 prestasi binaan akan diapresiasi di … pada … pukul 07.00 WIB: A (…), B (…)."; satu binaan memakai kalimat lama). Siapa yang bisa dipilih tidak berubah: prestasi terverifikasi, siswa berizin publikasi, belum masuk acara mana pun. Batas diam-diam "100 prestasi terbaru" di daftar lama hilang.
- **Alasan:** catatan lead di langkah 6-1 uji email 9 Okt 2026: "bagian pilih prestasi terverifikasi tambahi search bar jadi ga nyari scroll manual". Pada langkah yang sama, untuk tim dua siswa guru pembimbing menerima "Binaan masuk daftar apresiasi" dua kali, karena tiap anggota adalah satu baris prestasi dan satu klik. Centang beberapa dan satu notifikasi guru per klik adalah pilihan koordinator untuk menutup itu; lead hanya meminta pencarian.
- **Dampak:** Action baru `App\Actions\Publications\RecordAppreciations::handle(User, AppreciationEvent, iterable $achievements)` (satu transaksi, acara dikunci dulu, baris prestasi dikunci menurut id, `MAX` = 30, satu audit `appreciation.recorded` per prestasi tanpa nama); `RecordAppreciation` (panel Publikasi, satu prestasi) meneruskan ke Action itu dengan aturan dan pesan yang sama; `BinaanAppreciationNotice::scheduledMany()`; `Humas\Apresiasi` (`$search`, `$picked`, `$pickLimit` terkunci, `addAchievements()`, `PICK_STEP` 10, `PICK_MAX` 100); `HumasQueries::searchAchievements()`; kotak "Cari prestasi" menyimpan teks persis seperti diketik (`updatedSearch()` tidak menulis balik teks yang sudah dipangkas, sebab Livewire akan menaruhnya ke kotak yang sedang diketik dan menghapus spasi sebelum kata berikutnya; yang memangkas hanya kueri pencariannya); kotak cari bilah atas disembunyikan di Apresiasi (D-90). Centang datang dari browser, jadi tiap id dimuat ulang lewat kueri kelayakan; centang yang sudah tidak berlaku atau dipalsukan tidak menulis apa pun dan dijawab "Sebagian prestasi yang dipilih sudah tidak bisa dimasukkan. Daftar sudah diperbarui, pilih lagi." Toast hanya menyebut "siswa sudah diberi tahu" bila memang ada yang diberi tahu (D-109).
- **Status:** final untuk pencarian dan pilih beberapa. **Sementara** untuk "satu notifikasi guru per sekali Masukkan": anggota tim yang dimasukkan lewat klik terpisah, atau satu per satu dari panel Publikasi, tetap satu notifikasi guru per klik. Melengkapi D-97 (kelayakan tidak berubah) dan menyesuaikan D-110 untuk notifikasi "dijadwalkan". Belum dibuat: kelayakan belum mengikuti `NamedStudent` (akun siswa yang dinonaktifkan atau dihapus masih ditawarkan, backlog #80), dan pilihan guru di layar lain belum punya pencarian.

### D-125 · "Jenis penyelenggara: Lainnya" punya isian manual · 10 Okt 2026
- **Keputusan:** Di Kesiswaan › Kelola lomba (Tambah, Ubah, Periksa lomba), pilihan "Lainnya" pada "Jenis penyelenggara" memunculkan isian "Jenis penyelenggara lainnya" (maksimal 60 karakter). Isian boleh kosong selama lomba masih di antrean dan wajib diisi sebelum lomba dipublikasikan; lomba yang sudah tampil di katalog tidak bisa disimpan dengan "Lainnya" tanpa isian itu. Jenis selain "Lainnya" menghapus isian itu saat disimpan. Di tempat jenis penyelenggara ditampilkan (Guru › Lomba, Kesiswaan › Database prestasi dan kolom "Jenis penyelenggara" di Excel-nya) tertulis "Lainnya (Sanggar seni)". Isian ini bukan field bobot: poin tetap dari tingkat × capaian × kategori bobot. "Kategori bobot" E tidak mendapat isian manual.
- **Alasan:** catatan lead di langkah 2-2 uji email 9 Okt 2026: "di penyelenggara kalau pilih lainnya, kasi kolom untuk isi manual". "Lainnya" tanpa keterangan tidak menjelaskan penyelenggaranya, sehingga jenis lain yang tidak cocok dipilih.
- **Dampak:** kolom `competitions.organizer_type_other` (string 60, boleh kosong, tanpa backfill; migrasi `2026_10_10_100000_em2_add_organizer_type_other_to_competitions`); `Competition::organizerTypeLabel()`; `CreateCompetition::rules()` / `normalize()` (`ORGANIZER_TYPE_OTHER_MAX`); `VerifyCompetition::missingFields()` + `OTHER_TYPE_LABEL` (kartu antrean, "Publikasikan", "Simpan & publikasikan", dan simpan lomba terbit menyebutnya); `Kesiswaan\Lomba\Form`; `CompetitionFactory`; `DemoSeeder` (penyelenggara demo berjenis "Lainnya" = "Sanggar seni"). Filter "Jenis penyelenggara: Lainnya" tetap per enum. Siswa, halaman publik, surat, dan "Data untuk flyer" tidak menampilkan jenis penyelenggara, jadi juga tidak menampilkan isian ini.
- **Status:** final untuk isian manualnya (permintaan lead). **Sementara** (pilihan koordinator): wajib sebelum publikasi, dan format "Lainnya (…)" termasuk di kolom Excel. Baris "Lainnya" yang sudah ada tetap tampil "Lainnya" sampai diisi. **Jawaban lead, 10 Okt 2026:** wajib diisi sebelum publikasi disetujui ("Iya"), jadi bagian itu final; format "Lainnya (…)" tetap sementara.

### D-126 · Perubahan lomba yang sudah dipublikasikan diberitahukan ke siswa yang terdaftar dan guru pembimbingnya · 10 Okt 2026
- **Keputusan:** Saat Kesiswaan atau Admin menyimpan "Ubah lomba" pada lomba berstatus `dipublikasikan` dan ada data yang diandalkan peserta berubah, orang yang terikat pada lomba itu diberi tahu: satu notifikasi per simpan per orang, lewat email dan lonceng. Data yang dimaksud: batas daftar, tanggal mulai / final, pelaksanaan, tempat, peserta + maks anggota, link registrasi, website resmi, juknis. Perubahan lain tidak mengirim apa pun: nama, deskripsi, penyelenggara dan jenisnya (termasuk isian "Lainnya"), bidang, sampul, guru pembimbing default, "Daftar dibuka", serta tingkat / kategori / status ajang (tetap hanya audit `competition.weights_changed`). Yang dibandingkan adalah yang dilihat orang: tanggal per hari, tempat tanpa membedakan huruf besar dan spasi. Penerima: batas daftar dan peserta → tim yang masih `menunggu_konfirmasi`, siswa saja; data lain → pendaftaran `menunggu_konfirmasi` sampai `berlangsung`, siswa dan guru pembimbing; tanggal final berubah → juga pendaftaran `menunggu_laporan`, dengan batas laporan yang baru. Siswa = anggota yang tidak menolak (ketua, sudah konfirmasi, masih diundang) yang akunnya bisa masuk; guru = akun aktif yang masih berperan guru, satu notifikasi untuk semua binaannya di lomba itu; akun yang mengubah tidak diberi tahu. Lebih dari 50 penerima dalam satu simpan → hanya lonceng, tanpa email. Tidak pernah ke semua siswa, tidak ada centang "simpan tanpa memberi tahu", dan kalimatnya tidak pernah memuat alamat web ("Link registrasi diganti."). Tempat ditulis seperti diketik; tempat yang memuat alamat web hanya diumumkan sebagai "Tempat diganti.", isinya tidak dicetak (isian Tempat sendiri tidak punya aturan alamat web, jadi menyimpannya tidak pernah ditolak). Lomba yang masih `menunggu_verifikasi` tidak mengirim apa pun, juga lewat "Simpan & publikasikan".
- **Alasan:** catatan lead di langkah 10-7 uji email 9 Okt 2026: "bisa ga ya kasi notif gitu kalau ada update misal ke semua siswa". Di langkah itu batas daftar lomba yang sudah tampil diubah saat satu tim masih menunggu jawaban undangan, dan tidak ada yang tahu. Jawaban atas "ke semua siswa" adalah pilihan koordinator: email ke semua siswa (sekitar 1.100 per simpan) menghabiskan kuota harian 300 email dan menahan email kode OTP (D-121), dan lonceng ke semua siswa menambah sekitar 1.100 baris per simpan. Yang terikat pada satu lomba biasanya belasan orang.
- **Dampak:** `App\Actions\Competitions\CompetitionChange` (`FACTS`, `snapshot()`, `changed()`, `statusesFor()`, `line()`, `audience()`, `MAIL_LIMIT` = 50); `UpdateCompetition` (audit `competition.facts_changed` berisi daftar data yang berubah dan jumlah penerima saja, label "Info lomba diubah"; kirim setelah commit; tanda tangan `handle()` tetap); notifikasi `CompetitionChanged` (`competition.changed`, "Info lomba berubah: …", link ke Lomba saya) dan `BinaanCompetitionChanged` (`competition.binaan_changed`, "Info lomba binaan berubah: …", link ke detail binaan atau, bila guru itu punya beberapa pendaftaran di lomba itu, ke daftar binaannya `guru.beranda`); laci "Ubah lomba" Kesiswaan (keterangan di atas formulir bila ada siswa terdaftar: "N siswa sedang terdaftar di lomba ini. Perubahan tanggal, tempat, pelaksanaan, link, atau juknis diberitahukan ke siswa itu dan guru pembimbingnya. Perubahan batas daftar atau peserta hanya diberitahukan ke tim yang masih menunggu konfirmasi anggota."; N = anggota yang tidak menolak dari pendaftaran sampai `berlangsung`, `CompetitionChange::attachedStudents()`; toast menyebut siapa yang diberi tahu hanya bila memang ada, dan "… lewat notifikasi di aplikasi, tanpa email." di atas batas 50). Laci "Periksa lomba" yang masih terbuka saat akun lain mempublikasikan lombanya tidak langsung menyimpan: "Simpan" pertama hanya memberi peringatan "Lomba ini sudah dipublikasikan. Perubahan belum tersimpan, periksa lalu simpan." dan laci berubah menjadi "Ubah lomba" dengan keterangan tadi; "Simpan perubahan" berikutnya menyimpan dan memberi tahu (penghentian yang sama dengan yang QA9 berikan untuk "Simpan & publikasikan"). Tanpa migrasi.
- **Status:** sementara (aturannya pilihan koordinator): daftar datanya, cakupan status per data, dan batas 50 penerima. Belum dikerjakan (menunggu lead): notifikasi ke Tata Usaha bila surat sudah terbit, notifikasi saat lomba ditutup, pertanyaan sebelum menyimpan batas daftar yang sudah lewat, penolakan mengubah ke "tim" selama ada pendaftaran individu, tanda "Baru" / "Diperbarui" di katalog. "Simpan perubahan" tidak bertanya dulu walau bisa mengirim sampai 50 email; apakah perlu dialog: pertanyaan untuk lead di D-128. Melengkapi D-73, D-77, dan D-110 (siapa yang dikabari soal lomba dan pendaftaran). **Jawaban lead, 10 Okt 2026:** "Oke jangan ke semua siswa": penerima hanya orang yang terkait dengan lomba itu, final. Daftar data, cakupan status per data, dan batas 50 penerima tetap sementara.

### D-127 · Lonceng dan angka menu mengikuti keadaan tanpa memuat ulang halaman · 10 Okt 2026
- **Keputusan:** Lonceng di bilah atas dan angka antrean di menu samping berubah sendiri selama halaman terbuka. (1) Layar Notifikasi memberi tahu lonceng jumlah belum dibaca di setiap render: "Tandai dibaca" langsung menurunkan angkanya, dan "Tandai semua dibaca" tidak lagi memuat ulang halaman (pesannya tetap "Semua notifikasi ditandai sudah dibaca."). (2) Halaman dengan layout aplikasi bertanya ke satu alamat, `GET /notifikasi/jumlah` (`notifikasi.jumlah`), yang menjawab angka saja: jumlah notifikasi belum dibaca milik akun itu dan angka antrean menu milik perannya (`{"badges": {"akun.notifikasi": 1, "kesiswaan.usulan": 2}}`). Halaman bertanya 2,5 dan 8 detik setelah aksi di halaman, kira-kira tiap 60 detik (± 10 detik) selama tab terlihat dan dipakai dalam 5 menit terakhir, sesaat setelah Kembali / Maju, dan saat tab dipakai lagi setelah beristirahat. Halaman tidak bertanya di tab tersembunyi, saat offline, saat permintaan Livewire sedang berjalan, dan setelah dua jawaban ditolak berturut-turut (401 / 403: sesi berakhir atau akun tidak boleh) sampai halaman berikutnya. Jawaban 429 atau 5xx (misalnya 503 saat deploy), jaringan putus, atau tidak ada jawaban dalam 15 detik hanya menahan pengecekan paling sedikit satu menit (atau selama diminta server lewat Retry-After), lalu pengecekan berjalan lagi sendiri. (3) Notifikasi baru tidak memunculkan toast: angkanya berubah dan pembaca layar mendengar "Ada notifikasi baru."; daftar di layar Notifikasi ikut menampilkan baris barunya. (4) `STH_COUNTS_POLL_SECONDS` mengatur selangnya (bawaan 60; 1 sampai 29 dibaca 30, di atas 3600 dibaca 3600; 0 = tidak ada halaman yang bertanya sendiri, dan itu satu-satunya cara mematikan). Halaman masuk, aktivasi, kata sandi, 2FA, halaman publik, dan layout HP "Laporkan hasil" tidak punya lonceng dan tidak pernah bertanya.
- **Alasan:** catatan lead di langkah 1-3 uji email 9 Okt 2026: setelah mengganti kata sandi, angka lonceng baru muncul setelah lonceng diklik, dan setelah "Tandai dibaca" angkanya tetap sampai pindah halaman ("kayaknya ini eror berlaku ke semua yg berhubungan dengan notifikasi lonceng"). Lonceng adalah bagian layout yang hanya dihitung saat halaman dimuat, sedangkan baris notifikasi ditulis queue worker 2 sampai 3 detik setelah aksinya. Backlog #63 mencatat Q2-26 sudah beres, padahal yang diuji ulang di QA5 adalah "Tandai semua dibaca" (yang memuat ulang halaman), bukan "Tandai dibaca". Alamat JSON kecil yang ditanya `app.js` dipilih, bukan `wire:poll`: permintaan Livewire yang gagal di latar memunculkan dialog "halaman kedaluwarsa" atau toast 429, memakai jatah `livewire-update`, dan tidak bisa dicegah berjalan bersamaan dengan aksi pengguna.
- **Dampak:** `App\Http\Controllers\NotificationCountController` (`pollUrl()`, `pollSeconds()`, `MIN_SECONDS` = 30, `MAX_SECONDS` = 3600: jeda timer browser hanya 32 bit milidetik, angka yang lebih besar bisa membuat tab bertanya terus-menerus); rute `notifikasi.jumlah` (middleware halaman Notifikasi + `throttle:30,1,lonceng`, `Cache-Control: no-store`, permintaan yang tidak meminta JSON dialihkan ke `notifikasi.index`, `reflash()`, tidak diaudit); `Navigation::BELL` (`akun.notifikasi`, bukan butir menu); `NavigationServiceProvider::liveCounts()`; `config/sth.php` (baru); `<x-sth.topbar>` (gelembung selalu dicetak, tersembunyi di 0, "99+" di atas 99; kait `data-sth-badge`, `data-sth-badge-max`, `data-sth-count`, `data-sth-badge-label`, `data-sth-counts-url`, `data-sth-counts-every`); `layouts::app`; `Akun\Notifikasi` (`data-sth-refresh-on`); `resources/js/app.js` (bagian "live counts"); `.env.example`, `docker/env.production.example`. Tes: `tests/Feature/Notifikasi/LiveCountsTest.php`, `NotifikasiTest.php`, `tests/Feature/Support/NavigationBadgesTest.php`. Tanpa migrasi. Setiap pengecekan adalah satu permintaan penuh ke php-fpm (1 sampai beberapa `COUNT`) dan ikut memperpanjang sesi, jadi sesi hidup paling lama sekitar 5 menit lebih lama dari pemakaian terakhir. Sisi browser diuji lewat simulasi `app.js`; di browser, pada 10 Okt 2026, baru dilihat bahwa "Tandai dibaca" langsung menurunkan lonceng dan dua pengecekan menyusul setelah aksi itu. Pengecekan berkala, Kembali / Maju, tab tersembunyi, dan Keluar belum dilihat di browser (backlog #81).
- **Status:** final (lead: lonceng harus mengikuti tanpa pindah halaman). **Sementara** (pilihan koordinator): jeda 60 detik, dua pengecekan 2,5 dan 8 detik setelah aksi, dan saklar `STH_COUNTS_POLL_SECONDS`. Melengkapi D-90 dan D-100 untuk lonceng dan untuk perubahan yang dibuat orang lain; membetulkan status Q2-26 di backlog #63. Tidak dipilih: menulis baris notifikasi seketika (saluran `database` di koneksi `sync`), lonceng di layout HP, daftar antrean yang memuat ulang sendiri, pesan "sesi berakhir" di tab yang sesinya habis. Batas diam sesi staf (SECURITY §3a, belum dibangun) kelak tidak boleh menghitung rute ini sebagai aktivitas.

### D-128 · "Tolak" laporan hasil bertanya dulu; pertanyaan sebelum aksi selalu dialog STH, bukan kotak browser · 10 Okt 2026
- **Keputusan:** Kesiswaan › Verifikasi hasil › **Tolak** tidak lagi menolak dengan satu klik. Tombolnya memeriksa alasan (wajib, maksimal 1.000 karakter), lalu membuka dialog "Tolak laporan ini?" yang menampilkan nama lomba, siswa atau timnya, kalimat "Pendaftaran ini selesai tanpa poin. Keputusan ini tidak bisa diubah.", alasan yang akan dibaca siswa, dan siapa yang diberi tahu. Laporan baru ditolak setelah **Tolak laporan** ditekan. Aturan umumnya: pertanyaan sebelum sebuah aksi selalu dialog STH (`<x-sth.modal>`), tidak pernah kotak `confirm()` browser (`wire:confirm`). Aksi satu klik dan aksi massal yang disisir di EM2 mendapat dialog itu: Siswa › Daftar lomba › **Batalkan undangan** (ikon ×), Kesiswaan › Talenta › **Pindahkan ke …** untuk beberapa baris, Kesiswaan › Kelola lomba › **Tutup**, Import siswa › **Impor N siswa**, dan Humas › **Tandai dipublikasikan** (D-122); tiga yang terakhir sebelumnya memakai kotak browser. Ini bukan aturan "setiap aksi yang memberi tahu orang harus bertanya": aksi yang sengaja dibiarkan tanpa pertanyaan tercantum di Status. Dialog "Batalkan undangan?" menulis "<Nama> langsung diberi tahu." hanya bila temannya memang diberi tahu; selain itu kalimat netral "Undangan ini belum dijawab.", karena layar siswa tidak pernah menyebut status akun siswa lain (SECURITY §3m-3; kalimat "Akun … tidak aktif, jadi tidak ada notifikasi." hanya untuk layar staf). Pembatalan yang ditolak (tim sudah diteruskan ke Kepala Sekolah, batas daftar lewat) juga tampil sebagai toast peringatan, dan pesan sesudah pembatalan menambah "Pendaftaran diteruskan ke Kepala Sekolah." bila pembatalan itu melengkapi tim. Banner hasil "Tolak" dan "Minta revisi" hanya menyebut pihak yang benar-benar diberi tahu (D-109), dan "Ketua / Siswa bisa mengirim ulang laporan." hanya tertulis bila akun ketua bisa masuk. Guru › detail binaan menulis "Tidak menjawab" untuk undangan yang habis di batas pendaftaran, "Menolak" hanya untuk jawaban siswa.
- **Alasan:** catatan lead di langkah 5-7 ("bisa ditambahin second verif apakah yakin di tolak") dan 10-6 ("tambahi verifikasi kalo tolak biar yakin mau nolak") uji email 9 Okt 2026. Laporan yang ditolak mengakhiri pendaftaran tanpa poin dan tidak ada layar yang mengembalikannya; tombolnya bersebelahan dengan "Minta revisi" dan memakai kolom catatan yang sama. Layar lain disisir atas pilihan koordinator. Kotak browser tidak bisa menampilkan alasan atau penerima notifikasi, dan menjawab "tidak" tanpa pesan begitu "jangan tampilkan dialog lagi" dicentang.
- **Dampak:** pola dua langkah: metode `ask…()` memeriksa hak dan isian, mengisi properti `#[Locked]`, membuka dialog; metode konfirmasi tidak berbuat apa-apa tanpa pertanyaan itu (diam, bukan halaman 404, supaya klik ganda tidak menampilkan galat). `RejectReport::reason()`, `RejectReport::NOTE_REQUIRED`, `RequestReportRevision::audience()` (`told`, `of`, `mentor`) dan `RequestReportRevision::leaderCanResend()` (akun ketua bisa masuk; hanya ketua yang boleh mengirim ulang laporan); `Kesiswaan\VerifikasiHasil` (`askReject()`, `REJECT_DIALOG` = `tolak-laporan`, `$rejectingId`); `Siswa\Pendaftaran\Form` (`askBatalkanUndangan()`, `WITHDRAW_DIALOG` = `batal-undangan`, `$withdrawingId`; `batalkanUndangan()` tanpa id, pesan hasilnya dibaca dari status yang ditulis `WithdrawInvitation`; `refuseWithdrawal()` untuk penolakan di kedua langkah); `Kesiswaan\Talenta` (`askBulkAdvance()`, `ADVANCE_DIALOG` = `pindahkan-talenta`, `$advanceAsk` menyimpan baris yang pindah beserta tahap asalnya); `Kesiswaan\Lomba\Index` (`askClose()`, `CLOSE_DIALOG` = `tutup-lomba`, `$closingId`; `close()` tanpa id); `Akun\ImportSiswa` (`askImport()`, `IMPORT_DIALOG` = `impor-siswa`, `$importAsked`; kata sandi diminta sebelum dialog); `Humas\Publikasi` (`askPublish()`, `PUBLISH_DIALOG` = `tandai-terbit`, `$publishingId`); `Guru\BinaanDetail`. Tes `tests/Feature/Support/NoNativeConfirmTest.php` gagal bila ada view yang memakai `wire:confirm`. Tanpa migrasi, rute, izin, atau event audit baru.
- **Status:** final untuk "Tolak" laporan (permintaan lead). **Sementara** untuk dialog lain yang ikut disapu (pilihan koordinator). Sengaja tidak diubah: "Minta revisi", "Setujui" per baris Kepala Sekolah, ikon centang baris Talenta, "Cabut dan terbitkan ulang" TU, dialog Admin (Batas & arsip, Halaman publik, Perubahan peran, persetujuan). Juga tanpa pertanyaan, padahal satu klik bisa memberi tahu banyak orang: **"Masukkan"** di Apresiasi (D-124: langkah terakhir setelah mencari dan mencentang, sampai 30 prestasi sekali klik, tiap baris bisa dilepas lagi dengan "Lepas dari acara"; hanya toast sesudahnya yang menyebut siapa yang diberi tahu) dan **"Simpan perubahan"** pada lomba yang sudah dipublikasikan (D-126: sampai 50 email, `CompetitionChange::MAIL_LIMIT`; keterangan di atas formulir menyebut siapa yang diberi tahu). Keduanya belum ditanyakan ke lead: apakah perlu dialog juga. **Jawaban lead, 10 Okt 2026:** dialog lain yang ikut disapu (Batalkan undangan, Pindahkan talenta, Tutup lomba, Impor siswa, Tandai dipublikasikan) disetujui ("Boleh"), jadi final. Pertanyaan soal "Masukkan" dan "Simpan perubahan" masih terbuka. Langkah konfirmasi yang lebih lama (`Talenta::reject()`, `Lomba\Index::publishPast()`) masih menjawab 404 tanpa pertanyaannya. Melengkapi D-96 dan konfirmasi QA6; menerapkan D-109 / D-110 pada dialog dan banner.

### D-129 · Templat email STH: satu sapaan, satu link, satu kalimat kaki · 10 Okt 2026
- **Keputusan:** Semua email notifikasi dirender dari templat milik aplikasi (`resources/views/vendor/notifications/email.blade.php`, komponen di `resources/views/vendor/mail/{html,text}`, tema `html/themes/sth.css`), bukan templat bawaan Laravel. (1) Sapaan satu aturan (`App\Notifications\Support\MailGreeting`): siswa "Halo <nama depan>,", staf dan akun yang belum punya peran "Yth. <nama lengkap>,", penerima tidak dikenal "Halo,". Nama depan = kata pertama yang bukan singkatan (`App\Support\FirstName`: "Drs. Bambang Hartono, M.Pd." menjadi Bambang, "M. Rizky Pratama" menjadi Rizky). (2) Tidak ada salam penutup; kaki email satu kalimat: "Email otomatis dari SMAGA Talent Hub, SMA Negeri 3 Semarang. Jangan balas email ini." (`PlainMailMessage::FOOTER`). (3) Satu link per email, yaitu tombol aksi. Alamat di bawah tombol ditulis sebagai teks; kepala email adalah teks tanpa link. Email tanpa aksi tidak punya link sama sekali. (4) Bagian teks berisi sapaan, kalimat, "Label: alamat", "--", kalimat kaki; tanpa "#", tanpa "[alamat](alamat)", tanpa baris hak cipta. (5) Tampilan HTML memakai token "Tinta dan Sampul": halaman #F3F5F8, kartu putih bergaris 1px dengan sudut 10, tombol #1B2A4E, tumpukan font Public Sans tanpa font jarak jauh, tanpa gambar, tanpa bayangan, tanpa gradasi, tidak ada teks di bawah 13px, satu baris pratinjau kotak masuk yang tersembunyi. Lambang sekolah tidak dipasang di email. (6) Email kode (OTP) dan pemberitahuan email terhubung mengikuti aturan sapaan, kartu, dan kalimat kaki yang sama; `sth:mail-test` memakai kalimat kaki yang sama.
- **Alasan:** email mentah yang ditempel lead di langkah 11-2 uji email 9 Okt 2026 (dan alamat tombol serta alamat cadangan di langkah 11-3 dan 11-4): bagian teks menampilkan "# Halo Nadia," dan "[http://…](http://…)", baris pertama "SMAGA Talent Hub: <alamat>", kaki "© 2026 … Hak cipta dilindungi." 12px abu-abu; sapaan ada empat bentuk ("Halo!", "Halo Drs.,", "Halo,", "Yth. …"); tampilan bawaan Laravel berbeda dari email kode; alamat cadangan adalah link kedua yang ikut ditulis ulang oleh penyedia email. Lead tidak menulis permintaan untuk hal-hal ini; rinciannya pilihan koordinator.
- **Dampak:** `config/mail.php` punya `markdown.theme` = `sth`. Semua `->salutation()` dihapus; templat tidak mencetak salam penutup. Notifikasi baru wajib memanggil `MailGreeting` (tes `MailGreetingTest` memindai dan mencocokkan setiap `toMail()`). Templat mencetak sapaan dan kalimat lewat `PlainMailMessage::plain()`, jadi teks tetap teks juga saat view dikompilasi lebih dulu dengan `view:cache` (tes `MailTemplateTest` menguji tiga urutan kompilasi). `OtpCodeMail` dan `EmailBindingNotice` mendapat properti `greeting` dengan nilai bawaan, sehingga job yang sudah antre sebelum deploy tetap bisa dijalankan. Empat kalimat email bawaan dihapus dari `lang/id.json`. "Halo, …" di Beranda siswa dan tur menu memakai `FirstName::of()`. Setelah deploy atau di stack dev: muat ulang php-fpm dan jalankan ulang worker antrean.
- **Status:** sementara. Tampilan menunggu dilihat lead di satu email nyata (Mailpit dan HP; koordinator melihat dua contoh email di Mailpit pada 10 Okt 2026, kartu HTML dan bagian teksnya; mode gelap Gmail dan Outlook desktop belum dicoba); aturan sapaan, bagian teks, satu link, dan kalimat kaki ikut ditinjau saat itu. Melengkapi D-117 (tampilan) dan D-119 (sapaan staf) untuk email. D-69 tetap (`PlainMailMessage` satu-satunya pembuat email). D-120 tetap: email tidak memuat tanda merek. Akibat yang perlu diketahui: email kode dan pemberitahuan email terhubung kini menyapa akun staf dengan nama lengkap, sedangkan SECURITY §3b menulis "nama depan saja" (pertanyaan untuk lead). Belum diputuskan (bukan bagian keputusan ini): label tombol yang lebih spesifik, header Auto-Submitted, kata formal di email staf (backlog #76), penyedia email (D-121). **Jawaban lead, 10 Okt 2026:** tampilan dan sapaan baru, termasuk staf disapa "Yth. <nama lengkap>," di email kode, disetujui ("Boleh kalo mau tambahi gitu"). Lead belum melihat email nyata, jadi rincian tampilan masih bisa disesuaikan setelah itu.

### Tambahan untuk D-121 · yang diketahui tentang Brevo · 10 Okt 2026
Bukan keputusan baru; D-121 tidak diubah. Hasil riset setelah catatan lead di langkah 11-5 dan 11-13 ("gimana caranya biar tulisan berhenti berlangganan itu hilang soalnya bahaya kalau kepencet"), hanya butir yang terbaca di halaman resmi Brevo pada 10 Okt 2026:
- Header berhenti berlangganan wajib dan ditambahkan Brevo ke semua email, termasuk email transaksional. Di paket Free, Starter, Standard, dan Professional header itu tidak bisa dihapus atau diganti; hanya paket Enterprise yang bisa meminta lewat tiket agar diganti header List-Help, dengan syarat. Tidak ada pengaturan akun untuk ini.
- Alamat yang berhenti berlangganan diblokir untuk pengirim email itu. STH mengirim semuanya dari satu pengirim, jadi blokirnya juga mengenai email kode (cocok dengan uji lead di langkah 11-6). Membuka blokir dilakukan manual per kontak di akun Brevo atau lewat API; tidak ada pembukaan otomatis.
- Log email transaksional bisa disimpan 1 sampai 24 bulan (bawaan: tanpa batas), dan isi email (preview) bisa diatur tidak disimpan sama sekali ("Never store previews") atau disimpan selama masa yang sama. Pengaturannya di akun Brevo (Settings › Transactional emails › Retention rules), bukan di repo; lead mencatat pilihan 1 bulan di langkah 11-9.
- Pelacakan buka dan klik tidak bisa dimatikan untuk email transaksional lewat SMTP: tidak ada pengaturan atau header yang terdokumentasi.
- Penyedia untuk produksi masih terbuka. Kandidat yang menurut halaman resminya tidak menambahkan header berhenti berlangganan maupun pelacakan ke email transaksional kecuali diminta: Amazon SES (USD 0,10 per 1.000 email tanpa biaya bulanan; penyiapannya paling banyak dan akun baru harus meminta akses produksi dulu), Postmark (USD 15 per bulan untuk 10.000 email, tanpa batas harian), Resend (paket gratisnya hanya 100 email per hari; berbayar USD 20 per bulan). Semuanya butuh domain pengirim yang diverifikasi lewat DNS sekolah. Bila DNS sekolah tidak bisa diubah sama sekali: kotak surat `noreply` di hosting sekolah lewat SMTP, yang meneruskan email apa adanya (belum dipastikan untuk hosting sekolah; batas kirim per jamnya harus ditanyakan dulu). Belum layak dipilih sebelum diuji, karena perilakunya pada email transaksional tidak tertulis jelas: MailerSend, Mailjet, Mailgun.

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
2. ~~**Template surat tugas** masih dikonfirmasi sekolah: kolom apa saja yang ada di surat siswa (NIS/NISN? kelas?), dan apakah surat tim memuat semua anggota dalam satu tabel?~~ **Terjawab 4 Okt 2026 (lead)**: template = berkas asli sekolah (D-84). Surat siswa: satu surat untuk seluruh tim dengan tabel NO · NAMA MURID · NIS · KELAS; surat guru: Surat Perintah Tugas + halaman Lampiran.
3. ~~**Format nomor surat:** apa arti "MRD" pada `400.3.8/<no>/MRD/<bulan romawi>/<tahun>`? Apakah nomor urut direset per tahun, dan apakah buku agenda siswa dan guru terpisah?~~ **Tidak lagi menghambat sejak 4 Okt 2026 (lead)**: TU mengetik nomor lengkap tiap surat, dan sistem hanya menjaga nomor tidak dipakai dua kali per jenis surat (D-82). Arti "MRD" dan aturan reset tetap urusan buku agenda TU; aplikasi tidak perlu tahu.
4. **Foto siswa di halaman publik:** bolehkah ditampilkan, dan bagaimana proses persetujuan orang tua (formulir daftar ulang? untuk siswa yang sudah ada?). Hal yang sama berlaku untuk nama dan gambar sertifikat (U-04).
5. **Tenggat proyek & ketersediaan tim:** kapan target go-live, siapa saja anggota tim (developer, petugas deploy, Admin sekolah), dan berapa jam per minggu masing-masing tersedia?
6. Pertanyaan keamanan lanjutan (situs utama WordPress, Ubuntu Pro, akses server, DNS, 2FA staf, backup, DPO, akun arsip) ada di `SECURITY.md` §5.
