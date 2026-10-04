# Catatan perubahan · 4–5 Oktober 2026 (gelombang M1)

Catatan ini untuk lead dan tim (S, I). Isinya: apa saja yang diubah setelah uji manual lead tanggal
4 Oktober 2026, hasil ukur kecepatan untuk semua peran, dan apa yang belum diuji.
Rincian teknis (bahasa Inggris) ada di `docs/dev-notes/M1.md` dan `M1-A.md` … `M1-E.md`.
Keputusan baru tercatat di `docs/DECISIONS.md` sebagai D-78 … D-91.

## 1. Delapan belas permintaan lead dan hasilnya

| No | Permintaan | Yang diubah |
|---|---|---|
| 1 | Siswa bisa masuk pakai email | Setelah aktivasi selesai, siswa boleh mengetik NIS, NISN, atau email. Staf tetap username atau email. Pesan gagal tetap sama untuk semua kasus. |
| 2 | Filter peringkat harus bekerja; alumni; kelas ikut diperbarui | Peringkat publik punya tiga pilihan: Siswa aktif, Alumni, Semua. Alumni tampil sebagai "Alumni", bukan kelas terakhir. Import siswa tahunan memperbarui kelas siswa yang sudah terdaftar (kolom "Kelas"). |
| 3 | Aktivasi: email dulu, ganti kata sandi opsional | Urutan baru: hubungkan email → kode verifikasi → kata sandi. Siswa boleh "Lewati". Staf tetap wajib mengganti. |
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
  pakai kata sandi sekolah" di Status aktivasi belum dibuat.
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
- Cetak ke printer sungguhan dan pembandingan dengan kertas surat sekolah.
- Kecepatan di server produksi.
