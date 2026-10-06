# Catatan perubahan · 4–6 Oktober 2026 (gelombang M1 dan M2)

Catatan ini untuk lead dan tim (S, I). Isinya: apa saja yang diubah setelah uji manual lead tanggal
4 Oktober 2026, hasil ukur kecepatan untuk semua peran, dan apa yang belum diuji.
Rincian teknis (bahasa Inggris) ada di `docs/dev-notes/M1.md` dan `M1-A.md` … `M1-E.md`.
Keputusan baru tercatat di `docs/DECISIONS.md` sebagai D-78 … D-91.
Bagian 6 (ditambahkan 6 Oktober 2026, gelombang M2) berisi perbaikan 12 temuan terpenting dari uji 5 Oktober 2026;
rincian teknisnya di `docs/dev-notes/M2.md` dan `M2-A.md` … `M2-C.md`, keputusannya D-92 … D-101.

## 1. Delapan belas permintaan lead dan hasilnya

| No | Permintaan | Yang diubah |
|---|---|---|
| 1 | Siswa bisa masuk pakai email | Setelah aktivasi selesai, siswa boleh mengetik NIS, NISN, atau email. Staf tetap username atau email. Pesan gagal tetap sama untuk semua kasus. |
| 2 | Filter peringkat harus bekerja; alumni; kelas ikut diperbarui | Peringkat publik punya tiga pilihan: Siswa aktif, Alumni, Semua. Alumni tampil sebagai "Alumni", bukan kelas terakhir. Import siswa tahunan memperbarui kelas siswa yang sudah terdaftar (kolom "Kelas"). |
| 3 | Aktivasi: email dulu, ganti kata sandi opsional | Urutan baru: hubungkan email → kode verifikasi → kata sandi. Siswa boleh "Lewati". Staf tetap wajib mengganti. Sejak 6 Oktober 2026 "Lewati" **tidak berlaku setelah akun direset Admin**: siswa itu wajib membuat kata sandi baru (bagian 6 no. 1). |
| 4 | Cari lomba tanpa Enter | Kotak cari di atas langsung menyaring saat diketik dan kembali ke daftar penuh saat dikosongkan. Berlaku di semua halaman daftar yang punya pencarian. |
| 5 | Kotak cari jangan dua | Di layar lebar hanya kotak atas yang tampil. Di HP hanya kotak di dalam halaman (kotak atas tidak ada di HP). |
| 6 | Surat guru pendamping sama persis dengan contoh | PDF dibuat ulang dari berkas Word sekolah: bagian "Kepada" bertumpuk dengan "Terlampir", empat dasar hukum, halaman lampiran daftar pendamping. |
| 7 | Angka menu turun tanpa refresh | Angka di menu samping berubah sendiri setelah aksi. Berlaku untuk Antrean surat, Persetujuan, Usulan lomba, Verifikasi hasil, Antrean publikasi. |
| 8 | Nomor surat diketik penuh; PDF bisa dicetak | TU mengetik nomor lengkap untuk tiap surat. Nomor tidak boleh sama dalam satu jenis surat. Surat siswa mengikuti berkas Excel sekolah. Ada tombol "Cetak". |
| 9 | Data surat diisi per surat | Tab "Surat siswa" dan "Surat guru pendamping" masing-masing punya form, preview, dan tombol "Buat PDF" sendiri. "Kirim" tetap satu kali. |
| 10 | Tunjukkan letak kesalahan | Tombol tidak lagi abu-abu tanpa penjelasan. Kolom yang salah ditandai, ada ringkasan di atas form, dan layar lompat ke sana. Berlaku juga saat koreksi (cabut) surat. |
| 11, 13, 18 | Pindah menu terlalu lama | Lihat bagian 2. |
| 12 | Acara apresiasi bisa dihapus dan dikonfirmasi | "Tandai sudah dilaksanakan" dan "Hapus acara". Siswa yang sudah dijadwalkan diberi tahu kalau acaranya dihapus. Acara yang sudah dikonfirmasi tidak bisa diubah lagi. |
| 14 | Catatan talenta jelas untuk siapa | Dua catatan terpisah: untuk guru pembina (siswa tidak melihat) dan untuk siswa. Keduanya opsional, termasuk saat tidak lolos. |
| 15 | Tidak lolos bisa dipulihkan | Ikon recycle "Pulihkan" di tab Tidak lolos. Siswa punya tombol "Minta ditinjau ulang" dengan pesan singkat. |
| 16 | Status aktivasi per kelas jadi popup | "Lihat" membuka popup berisi siswa kelas itu, lengkap dengan aksinya. |
| 17 | Kata sehari-hari | unggah → upload, unduh → download, tautan → link, pratinjau → preview, di layar, email, dan pesan kesalahan (156 tempat). |

Jawaban pertanyaan di no. 7: angka di "Antrean surat" adalah jumlah pendaftaran yang sudah disetujui
Kepala Sekolah tetapi suratnya belum dikirim. Angka turun saat "Kirim", bukan saat PDF dibuat.

## 2. Keringanan aplikasi (waktu buka halaman)

### Cara mengukur
Semua menu di sidebar dibuka untuk ketujuh peran plus halaman publik, di laptop lead (Docker di Windows),
dengan akun demo. Tiap halaman diukur tiga kali: kunjungan pertama dan dua kunjungan berikutnya.
Yang diukur adalah waktu server sampai halaman selesai dikirim, bukan waktu gambar di layar.

### Hasil akhir (5 Oktober 2026, setelah `reload.bat` dan pemanasan selesai)

| Peran | Jumlah menu | Masuk (login) | Kunjungan pertama tiap menu | Kunjungan berikutnya | Nilai tengah |
|---|---|---|---|---|---|
| Publik (tanpa login) | 4 | – | 191–486 ms | 173–334 ms | 264 ms |
| Siswa | 9 | 1.852 ms | 170–1.069 ms | 149–408 ms | 271 ms |
| Guru | 2 | 898 ms | 239–360 ms | 265–339 ms | 326 ms |
| Kesiswaan | 9 | 748 ms | 219–439 ms | 245–538 ms | 373 ms |
| Kepala Sekolah | 5 | 794 ms | 246–357 ms | 253–386 ms | 282 ms |
| Tata Usaha | 3 | 928 ms | 288–308 ms | 291–392 ms | 351 ms |
| Humas | 4 | 820 ms | 266–316 ms | 203–396 ms | 246 ms |
| Admin | 9 | 744 ms | 240–460 ms | 201–464 ms | 292 ms |

Pembanding:
- Tangkapan layar lead sebelum perubahan: Humas 1.070–1.180 ms, Kesiswaan 1.220 ms per pindah menu.
- Sebelum pemanasan dibuat, halaman pertama setelah `reload.bat` butuh 7.294 ms dan login pertama 5.000–6.000 ms.
- Aksi di dalam halaman (contoh: mengetik di pencarian Talenta) 330–410 ms per aksi.

Catatan angka:
- "Masuk" terdiri dari tiga permintaan berurutan (kirim form, pengalihan, halaman awal) dan pengecekan kata
  sandi, jadi wajar lebih lama dari satu halaman. Angka siswa lebih besar karena itu login pertama setelah
  aplikasi dimuat ulang.
- Halaman terberat: Kelola lomba (172 kB), Database prestasi, Status aktivasi, Log audit.

### Penyebab yang ditemukan (diukur, bukan ditebak)
Repo berada di drive Windows dan dibaca container Linux. Tiap pengecekan file lewat batas itu ±6 ms.
1. Satu halaman memicu ±400 pengecekan file: ±165 dari file tampilan (Blade) dan ±140 dari file konfigurasi.
2. Setelah `reload.bat`, PHP harus membaca ulang ±1.000 file dari drive Windows pada permintaan pertama.
3. Tiap proses pekerja PHP punya ingatan lokasi file sendiri yang kosong saat baru hidup dan kedaluwarsa
   tiap 10 menit, jadi halaman bisa lambat lagi setelah ditinggal sebentar.

### Yang diubah (hanya untuk lingkungan lokal; produksi tidak berubah)
| File | Perubahan |
|---|---|
| `docker/php/fpm.dev.conf` (baru) | Cache konfigurasi dan hasil kompilasi tampilan disimpan di dalam container, khusus untuk PHP yang melayani browser. Tiga pekerja tetap. |
| `config/view.php` (baru) | Tampilan tidak lagi dibandingkan dengan sumbernya di setiap permintaan (lokal saja). |
| `docker/php/dev-cache.sh` (baru) | Dijalankan `start.bat` dan `reload.bat`: membuang tampilan yang sumbernya berubah, memperbarui cache konfigurasi bila perlu, memuat ulang PHP, lalu memanaskan di latar belakang. |
| `app/Support/Dev/OpcacheWarmup.php`, `routes/dev.php` | Pemanasan: mengingat file PHP yang dipakai sebelum dimuat ulang dan menyiapkannya lagi sesudahnya. Hanya ada di lokal. |
| `docker/php/php.dev.ini` | Ingatan lokasi file tidak lagi kedaluwarsa tiap 10 menit. |
| `compose.yaml`, `start.bat`, `reload.bat` | Memasang file di atas. |
| `tests/TestCase.php` | Tes menolak jalan kalau konfigurasi ter-cache atau lingkungan bukan `testing`, supaya database dev tidak pernah terhapus oleh tes. |
| `resources/js/app.js`, sidebar, topbar | Angka menu dan pencarian langsung (no. 4, 5, 7). |

Proses baris perintah (tes, antrean, penjadwal) tidak pernah melihat cache konfigurasi itu, jadi tes tetap
memakai database SQLite sementara.

### Yang tidak bisa dihilangkan dari sisi kode
- Angka di atas adalah batas wajar selama repo berada di drive Windows. Obat sebenarnya adalah memindahkan
  repo ke filesystem Linux (WSL2); itu masih terbuka dari catatan PERF1.
- Pemanasan setelah `reload.bat` berjalan ±28 detik di latar belakang. Selama itu halaman tetap bisa
  dibuka, hanya belum secepat tabel di atas.
- Kecepatan di server produksi belum pernah diukur, karena staging belum ada (backlog #21).

## 3. Yang perlu diketahui tim
- Setelah mengubah file `.php`, `.blade.php`, `config/*.php`, atau `.env`, jalankan `reload.bat`.
  CSS dan JS cukup `npm run build` seperti biasa.
- Jangan menjalankan `php artisan config:cache` atau `php artisan optimize` di repo. Tes akan menolak jalan.
- Setelah mengubah directive Blade atau kelas komponen, jalankan
  `docker compose exec -T app sh docker/php/dev-cache.sh --force`.
- Lambang Pemprov Jawa Tengah dan lambang sekolah sekarang ada di `resources/pdf/assets/` untuk kop surat.
- Di database lokal lead ada dua surat uji yang belum dikirim: `400.3.8/901/MRD/X/2026` dan
  `400.3.8.6/1901/2026` (pendaftaran NSIF).
- `panduan-uji-manual-sth.html` (di luar repo) belum disesuaikan. Langkah aktivasi, surat TU, peringkat,
  talenta, apresiasi, import, dan nama tombol di sana sudah tidak cocok.

## 4. Yang diputuskan pengerja, perlu dikonfirmasi lead
- **No. 3 (risiko):** siswa yang memilih "Lewati" tetap memakai kata sandi dari sekolah, yang bisa ditebak
  dari tanggal lahir. Pilihan itu tercatat di log audit (`activation.password_kept`), tetapi penanda "masih
  pakai kata sandi sekolah" di Status aktivasi belum dibuat. Sejak 6 Oktober 2026 "Lewati" hanya ada untuk
  kata sandi dari sekolah (hasil import). Setelah direset Admin, siswa wajib membuat kata sandi baru, jadi
  kata sandi sementara yang pernah tampil di layar Admin tidak bisa dipakai terus (D-92).
- **No. 2:** bila nama atau tanggal lahir di file berbeda dari data lama, kelas tetap diperbarui tetapi nama
  dan tanggal lahir tidak ditimpa. Siswa terdaftar yang tidak ada di file dibiarkan. File tahunan menang
  atas tanggal arsip yang diubah manual.
- **No. 8:** tabel lampiran daftar pendamping tanpa garis, mengikuti berkas Word.
- **No. 14:** diartikan untuk Talenta, bukan untuk persetujuan lomba oleh Kepala Sekolah.
- **No. 15:** Kesiswaan boleh memulihkan dengan atau tanpa permintaan siswa.
- **No. 17:** hanya empat kata itu; "daring" dan "luring" belum diganti.

## 5. Bukti dan yang belum diuji
Sudah dijalankan pada 5 Oktober 2026:
- Seluruh tes: **1767 lulus** (8945 pengecekan), 0 gagal. Pint bersih (610 file). `npm run build` berhasil.
- Empat migrasi baru berjalan di MySQL lokal tanpa error.
- Kedua PDF surat dibuat di data demo dan dilihat hasilnya.
- Di browser: pencarian langsung, angka menu, popup Status aktivasi, layar Talenta, layar Apresiasi,
  filter alumni, teks halaman masuk, lompat ke error di form surat.

Belum diuji:
- Klik aksi satu per satu di browser: tiga langkah aktivasi, simpan catatan dan pulihkan talenta, hapus dan
  konfirmasi acara, Buat PDF/Cetak/Kirim lewat tombol, import tahunan. Semuanya baru dibuktikan oleh tes.
- Skenario email (`tests/E2E/support/email-scenario.php`) dan tes Playwright belum dijalankan ulang.
  (Pembaruan: keduanya sudah dijalankan pada 5 Oktober 2026, hasilnya di bagian 6.)
- Cetak ke printer sungguhan dan pembandingan dengan kertas surat sekolah.
- Kecepatan di server produksi.

## 6. Perbaikan 12 temuan uji (6 Oktober 2026)

Pada 5 Oktober 2026 aplikasi diuji lewat browser lead dan lewat permintaan langsung ke server: 64 temuan
(`hasil-uji/ringkasan-temuan.md`, di luar repo, di sebelah folder repo). Jawaban lead: "Dari ke 12 temuan yang
paling penting, lakukan sesuai saranmu". Tabel di bawah mengikuti nomor di ringkasan itu.

| No | Temuan singkat | Yang diubah |
|---|---|---|
| 1 | Setelah direset Admin, siswa bisa menekan "Lewati" dan memakai kata sandi sementara terus | Siswa yang direset **wajib membuat kata sandi baru**; tombol "Lewati" tidak tampil. Siswa dengan kata sandi dari sekolah tetap boleh "Lewati". |
| 2 | Setelah Keluar, tombol Back memunculkan halaman masuk dengan NIS dan kata sandi masih terisi | Halaman masuk, aktivasi, lupa kata sandi, konfirmasi kata sandi, dan 2FA tidak disimpan browser. Kolom kata sandi dikosongkan setelah form dikirim. |
| 3 | "Perangkat yang masuk" berisi perangkat hantu; sesi lama masih hidup | Sesi lama dihapus tiap kali sesi diperbarui (aktivasi, ganti kata sandi, pasang 2FA). Gagal ganti kata sandi tidak lagi menyentuh sesi. |
| 4 | "Buka ulang" memendekkan batas aktivasi; alasan 2 huruf diterima | "Buka ulang" tidak pernah memendekkan: batas baru = yang lebih jauh antara batas sekarang dan 72 jam dari sekarang. Tombolnya hanya muncul bila perlu, dan dialognya menyebut hasilnya dulu. Alasan minimal 10 karakter, juga alasan menolak lomba. |
| 5 | Siswa melihat tombol download surat guru yang berujung 404 | Kartu "Surat tugas" siswa hanya memuat surat yang sudah dikirim. Surat guru tampil sebagai keterangan saja, tanpa tombol. Guru dan staf tetap bisa men-download. |
| 6 | Angka menu samping hilang di sebagian halaman | Angka dihitung di satu tempat dan tampil di semua halaman (juga Arsip surat, Template surat, Status aktivasi, Notifikasi, Keamanan akun). |
| 7 | Talenta: catatan untuk siswa hilang saat "Simpan"; "Tidak lolos" jalan dengan satu klik | "Simpan" menyimpan catatan untuk siswa sebagai draf ("Draf tersimpan, belum terkirim."). "Tidak lolos" bertanya dulu, di panel dan di aksi massal. |
| 8 | Satu prestasi bisa masuk dua acara apresiasi; tidak bisa dilepas | Satu prestasi hanya di satu acara. Tombol baru "Lepas dari acara"; siswanya diberi tahu. |
| 9 | Lomba terbit dengan tanggal mulai sebelum batas daftar, dan semua tanggal sudah lewat | Tanggal mulai sebelum batas daftar ditolak. Tanggal yang sudah lewat perlu konfirmasi "Tetap publikasikan?". |
| 10 | Halaman 403/404 polos dan berbahasa Inggris; `/logout` menjawab halaman debug besar dan lambat | Halaman error milik aplikasi (401, 403, 404, 405, 419, 429, 500, 503) dengan "Kembali ke beranda". Mengetik `/logout` hanya mengalihkan, tidak mengeluarkan akun. Halaman pertama setelah masuk yang tidak boleh dibuka → beranda. |
| 11 | `password123` diterima; staf boleh memakai kata sandi berisi username | Kata sandi umum, nama pengguna, dan bagian depan email ditolak, untuk siswa dan staf. Kalimat petunjuk di bawah kolom mengikuti aturan akun itu. |
| 12 | CI PR 9 merah di pemeriksaan image (Trivy) | Paket `libpcre2-8-0` dan empat paket perl di image produksi di-upgrade (`docker/Dockerfile.prod`). Pemeriksaan Trivy lokal dengan perintah yang sama seperti CI: 0 temuan di image `sth-app` dan `sth-web`. |

### Yang perlu diketahui tim
- **Dua migrasi baru.** `2026_10_06_100000_m2a_…` menambah kolom `users.temporary_password_issued_at`;
  `2026_10_06_110000_m2b_…` menambah kolom `talent_applications.student_note_draft`. Setelah menarik branch ini
  jalankan `docker compose exec app php artisan migrate`. Sebelum kolom kedua ada, layar Kesiswaan › Talenta error.
  Keduanya sudah dijalankan di MySQL lokal tanpa error (6 Oktober 2026).
- **Setelah migrasi jalankan `reload.bat`** (ada dua provider, satu middleware, dan satu route baru), lalu
  `npm run build` (ada perubahan di `resources/js/app.js`).
- **Perbaikan Trivy.** Di `docker/Dockerfile.prod` stage `app` ada baris `apt-get install --only-upgrade …`
  dengan komentar bertanggal. Kalau Trivy merah lagi karena paket image dasar, tambahkan paketnya di baris itu
  (stage `web`: baris `apk upgrade`), jalankan perintah Trivy yang sama di komputer sendiri, lalu hapus lagi
  paketnya bila image dasar yang baru sudah memuat perbaikannya (langkah: `docs/DEPLOY.md` §18).
- **Sudah diuji sebelum perbaikan ini (5 Oktober 2026, kode M1):** skenario email 122 email, 0 hilang, 0 tak
  terduga, 0 bocor, 0 job gagal; tes Playwright lulus di CI PR 9; uji keamanan lewat HTTP lulus semua.
- **Diputuskan pengerja, bisa diubah lead:** (a) aksi cepat di Talenta (ikon centang di baris, "Pindahkan ke …")
  ikut mengirim draf catatan yang tersimpan; (b) siswa tidak boleh membuka surat guru: ringkasan uji meminta lead
  memutuskan, dan yang dibangun mengikuti aturan yang sudah ada; (c) kolom alasan lain (tolak pendaftaran, tolak
  atau revisi laporan, buka pembekuan) belum diberi batas 10 karakter.
- **Sudah dicoba di browser pada 6 Oktober 2026** (bagian 7): urutan Keluar → Back, aktivasi setelah reset Admin,
  dialog Tidak lolos dan Lepas dari acara, halaman 403 dan 404, dan `/logout`. **Belum:** konfirmasi "Tetap
  publikasikan?" untuk tanggal yang sudah lewat, "Tambah prestasi" yang sama di dua acara, dan halaman error lain.
  Uji dengan `APP_DEBUG=false` (seperti produksi) juga belum. Daftarnya di `docs/dev-notes/M2.md`.
- **Temuan lain dari uji 5 Oktober** (prioritas sedang dan kecil) belum dikerjakan. Daftar per nomor temuan ada di
  `docs/dev-notes/M2.md`; di backlog `CLAUDE.md` §10 menjadi nomor 57–68.

HASIL TES (6 Oktober 2026): seluruh tes 2005 lulus, 1 gagal. Yang gagal adalah tes pembatas pencarian anggota tim; penyebabnya di tesnya sendiri (masa berlaku 1 detik, kalah cepat dengan render pertama di laptop ini), sudah diperbaiki dan file tesnya lulus 19/19 saat dijalankan ulang. Setelah perbaikan satu baris itu seluruh tes dijalankan ulang: **2006 lulus, 0 gagal**; CI di GitHub untuk commit `e45a560` hijau di keenam pekerjaan. Pint bersih (627 file), `npm run build` berhasil, dua migrasi baru jalan di MySQL lokal, dan pindaian Trivy untuk kedua image produksi 0 temuan. Hasil lihat di browser sungguhan ada di bagian 7.

## 7. Lanjutan 6 Oktober 2026: cek di browser, dua perbaikan kecil, data uji

**PR 9 sudah digabung ke `main`** (commit `b4b6f77`). Perbaikan di bawah ada di branch `fix/auth-back-setelah-keluar`.

Perbaikan 12 temuan dicek lagi di Chrome (`hasil-uji/qa-3-cek-perbaikan.md`, di luar repo). Semuanya lulus kecuali
dua hal. Jawaban lead: "Ya perbaiki".

| Yang terlihat | Penyebab | Yang diubah |
|---|---|---|
| Masuk → Keluar → Back dua kali: kata sandi sudah kosong, tetapi nama pengguna muncul lagi | Browser mengisi ulang kolom teks saat memuat lagi halaman untuk Back; skrip kita baru mengosongkan kolom kata sandi | Kolom nama pengguna ikut dikosongkan: setelah form dikirim dan saat halaman dimunculkan lagi oleh Back/Forward. Kalau login gagal, nama pengguna tetap dikembalikan seperti biasa |
| Setelah itu `operator` masuk dan mendarat di `/kesiswaan`, bukan `/admin` | Back setelah Keluar membuat server mencatat halaman lama sebagai "alamat tujuan" untuk siapa pun yang masuk berikutnya | Server mengingat akun mana yang baru Keluar di browser itu. Akun **lain** yang masuk berikutnya langsung ke berandanya. Akun yang **sama** tetap dibawa ke halamannya |

- Bukti: 5 tes baru; file tes login, keluar, halaman kredensial, dan 2FA staf: **64 lulus**. Dicoba di Chrome dan di
  browser bawaan aplikasi (Chromium, diketik dengan keyboard): dua kolom kosong pada Back pertama dan kedua, lalu
  `operator` mendarat di `/admin`. Kolom teks biasa di halaman lain tetap diisi ulang browser seperti sebelumnya.
- Seluruh tes dijalankan ulang setelah perubahan ini: **2011 lulus, 0 gagal** (10.846 pemeriksaan); Pint bersih
  (628 file); `npm run build` berhasil.
- Belum dicoba: Firefox, Safari, dan HP.
- Yang tidak berubah dengan sengaja: kalau sesi habis sendiri (tidak ada yang menekan Keluar), alamat tujuan tetap
  dipakai seperti biasa.
- Keputusan ini dicatat sebagai **D-102** di `docs/DECISIONS.md`.
- Setelah menarik perubahan ini: `reload.bat`, lalu `npm run build` (ada perubahan di `resources/js/app.js`).

**Data uji di database lokal lead tidak dihapus** (keputusan lead). Daftarnya ada di `dokumentasi.md`.
