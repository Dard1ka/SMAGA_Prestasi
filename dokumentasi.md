# Dokumentasi: data uji yang masih ada di database lokal

Berkas ini hanya tentang **database lokal di laptop lead** (MySQL di Docker, database `sth`).
Bukan tentang repo, bukan tentang server sekolah. Laptop anggota tim lain tidak punya data ini.

- Data demo dibuat seeder pada 1 Oktober 2026.
- Semua yang tertulis di bawah ditambahkan atau diubah sesudahnya: uji tangan lead (1 sampai 4 Oktober) dan
  tiga putaran QA (5 sampai 6 Oktober).
- Keputusan lead, 6 Oktober 2026: data ini **tidak dihapus**, cukup dicatat di sini.
- Semua nama di sini fiktif. Tidak ada kata sandi di berkas ini.
- Dibaca langsung dari database pada 6 Oktober 2026.
- Bagian 12 ditambahkan 6 Oktober 2026 (gelombang M3): isi berkas demo diganti dokumen contoh. Bagian 1 sampai 11
  tidak berubah karenanya.

## 1. Kenapa perlu tahu

Saat mengikuti `panduan-uji-manual-sth.html`, beberapa layar menampilkan baris lebih banyak daripada data demo
asli. Itu bukan bug, itu sisa uji. Angka saat ini: 24 siswa, 34 pengguna, 72 lomba, 17 surat.

## 2. Akun yang ditambahkan

| Nama | Masuk dengan | Peran | Kelas | Status | Catatan |
|---|---|---|---|---|---|
| Arga Uji Satu | NIS 227226901 | Siswa | X-1 | Aktif | Punya 6 pendaftaran uji (lihat bagian 5) |
| Bela Uji Dua | NIS 227226902 | Siswa | XI-3 | Aktif | Anggota tim Arga di LPB 2026 |
| Candra Uji Tiga | NIS 227226903 | Siswa | X-4 | Aktif | Di-reset Admin lalu aktivasi lagi saat QA-3 |
| Uji QA Dua | username `uji.qa2` | Humas | – | Aktif | Akun staf uji |

Kata sandi akun-akun ini dibuat saat uji dan tidak dicatat. Kalau perlu masuk: Admin › Pengguna › reset.

## 3. Akun demo yang berubah

| Nama (NIS) | Sekarang | Apa yang berubah |
|---|---|---|
| Nadia Putri Anindya (225226201) | Aktif | Aktivasi dijalankan saat uji tangan. Kata sandi di panduan lama tidak berlaku lagi |
| Keisha Larasati (225226166) | Aktif | Aktivasi 4 Oktober saat uji tangan. Mendaftar 3 pilihan talenta baru |
| Rizky Maulana (225226118) | Aktif | Di-reset Admin, lalu aktivasi lagi 5 Oktober dengan email uji |
| Galang Putra Mahendra (226227004) | Belum aktivasi | Di-reset Admin: memakai kata sandi sementara, wajib membuat kata sandi baru. Batas 15 Oktober 2026 |
| Fajar Ramadhan (224225012) | Belum aktivasi | Batas aktivasi dipendekkan saat uji menjadi **8 Oktober 2026**. Sesudah itu perlu "Buka ulang" di Status aktivasi |
| Salsabila Kirana (225226157) | Belum aktivasi | Batas 15 Oktober 2026. Publikasi prestasinya diubah (bagian 9) |

## 4. Lomba uji

| Nama lomba | Status | Catatan |
|---|---|---|
| Lomba Uji Coba QA Dua 2026 | Dipublikasikan | Terlihat di katalog siswa. Penyelenggara ditulis `Panitia <b>Uji</b> QA` untuk mengecek bahwa tag HTML tampil sebagai teks biasa |
| QA2 Lomba Uji Guru | Menunggu verifikasi | Masih di antrean "Usulan lomba" Kesiswaan |
| Lomba Uji Coba QA Satu 2026 | Ditolak | – |
| Lomba Uji Tambah QA Satu 2026 | Ditolak | – |

## 5. Pendaftaran uji

Baru, semuanya atas nama Arga Uji Satu:

| Lomba | Jenis | Status |
|---|---|---|
| O2SN Kota Semarang 2026 · Bulu Tangkis | Individu | Surat terbit |
| Lomba Peneliti Belia (LPB) 2026 | Tim (dengan Bela) | Surat terbit |
| Kejuaraan Pencak Silat Pelajar Jawa Tengah 2026 | Individu | Ditolak Kepala Sekolah |
| Lomba Debat Bahasa Indonesia (LDBI) Kota Semarang 2026 | Tim | Dibatalkan |
| OSN Tingkat Kota Semarang 2026 | Individu | Dibatalkan |
| Lomba Uji Coba QA Dua 2026 | Individu | Dibatalkan |

Pendaftaran demo yang berubah:

| Lomba | Ketua | Sekarang |
|---|---|---|
| Festival Tari Pelajar Semarang 2026 | Citra Ayu Lestari | Selesai (laporannya diverifikasi, bagian 7) |
| Popda Kota Semarang 2026 | Bima Satria Wicaksono | Menunggu laporan (laporannya diminta revisi) |

## 6. Surat uji

Sembilan dari 17 surat di Arsip surat TU berasal dari uji:

| Nomor | Jenis | Lomba | Keadaan |
|---|---|---|---|
| 400.3.8/901/MRD/X/2026 | Siswa | NSIF 2026 | Dibuat, **belum dikirim** |
| 400.3.8.6/1901/2026 | Guru | NSIF 2026 | Dibuat, **belum dikirim** |
| 400.3.8/905/MRD/X/2026 | Siswa | O2SN Bulu Tangkis | Dicabut, diganti 906 |
| 400.3.8/906/MRD/X/2026 | Siswa | O2SN Bulu Tangkis | Terkirim (versi 2) |
| 400.3.8.6/1905/2026 | Guru | O2SN Bulu Tangkis | Dicabut, diganti 1906 |
| 400.3.8.6/1906/2026 | Guru | O2SN Bulu Tangkis | Terkirim (versi 2) |
| 400.3.8/907/MRD/X/2026 | Siswa | LPB 2026 | Terkirim |
| 400.3.8.6/1907/2026 | Guru | LPB 2026 | Dicabut, diganti 1908 |
| 400.3.8.6/1908/2026 | Guru | LPB 2026 | Terkirim (versi 2) |

Nomor-nomor ini sudah terpakai. Nomor surat harus unik per jenis surat, jadi saat uji pakai nomor lain
(misalnya 910 ke atas untuk siswa, 1910 ke atas untuk guru).

## 7. Laporan hasil, prestasi, dan poin

- Laporan Citra Ayu Lestari untuk Festival Tari Pelajar Semarang 2026 diverifikasi sebagai Juara 1:
  satu prestasi baru, **8 poin**. Peringkat Citra ikut naik dibanding data demo asli.
- Laporan Bima Satria Wicaksono untuk Popda Kota Semarang 2026 (medali perak) berstatus **perlu revisi**.

## 8. Talenta

| Siswa | Pilihan | Sekarang | Catatan |
|---|---|---|---|
| Nadia Putri Anindya | OSN · Kimia | Seleksi | Ada **draf** catatan untuk siswa yang belum terkirim |
| Nadia Putri Anindya | FLS3N · Tari Kreasi | Terdaftar | Pilihan baru dari uji tangan |
| Keisha Larasati | OSN · Fisika, Riset · OPSI, Debat · LDBI | Terdaftar | Tiga pilihan baru |
| Galang Putra Mahendra | OLPAIN | Tidak lolos | Ada catatan untuk siswa. Bisa dipakai menguji "Pulihkan" |
| Hana Safira | OSN · Biologi | Pembinaan | Ada catatan untuk guru pembina |
| Citra Ayu Lestari | FLS3N · Tari Kreasi | Pemetaan | Ada catatan untuk siswa |
| Rizky Maulana | OSN · Kimia | Pembinaan | Tahapnya dipindah |

## 9. Humas: publikasi dan acara apresiasi

Publikasi yang berubah:

| Prestasi | Sekarang |
|---|---|
| Nadia Putri Anindya · OPSI 2026 | Dipublikasikan (2 Oktober) |
| Raka Pradipta · FIKSI 2026 | Dipublikasikan (5 Oktober) |
| Salsabila Kirana · FLS3N Provinsi Jawa Tengah 2026 | Dipublikasikan (5 Oktober) |
| Aurelia Maharani · OSEBI 2026 | Terjadwal 20 Oktober 2026 |
| Citra Ayu Lestari · Festival Tari Pelajar Semarang 2026 | Siap (prestasi baru dari bagian 7) |

Acara apresiasi:

| Acara | Tanggal | Keadaan |
|---|---|---|
| QA2 acara hari ini | 5 Oktober 2026 | **Sudah dikonfirmasi**, jadi terkunci dan tidak bisa dihapus. Isi: 1 prestasi |
| Satu acara dari uji tangan lead (nama bebas, tidak ditulis di sini) | 5 Oktober 2026 | Belum dikonfirmasi, 0 prestasi. Masih bisa dihapus |
| Upacara Senin, 5 Oktober 2026 | 5 Oktober 2026 | Acara demo, belum dikonfirmasi, 2 prestasi |

Satu keanehan yang sengaja dibiarkan: prestasi **Raka Pradipta · FIKSI 2026 ada di dua acara** ("Dijadwalkan" di
Upacara Senin dan "Sudah diapresiasi" di QA2 acara hari ini). Itu dibuat sebelum aturan "satu prestasi di satu
acara" (D-97). Aplikasi sekarang menolak membuat keadaan seperti ini.

## 10. Lain-lain

- 84 notifikasi baru dan 248 baris baru di Log audit. Log audit memang tidak bisa dihapus dari aplikasi.
- Salah satu notifikasi Kesiswaan (permintaan tinjau ulang dari Keisha) berisi teks
  `<b>tes</b> [klik](http://jahat.test)`: sengaja, untuk mengecek bahwa teks itu tampil apa adanya.
- Mungkin ada berkas hasil download uji (CSV, PDF surat, flyer) di folder Downloads laptop. Itu di luar aplikasi.

## 11. Kalau suatu saat ingin data demo yang bersih lagi

Tidak dilakukan sekarang. Kalau lead nanti memutuskan lain, perintahnya (hanya untuk database lokal Docker,
menghapus **semua** isi database lokal lalu mengisi ulang data demo):

```
docker compose exec app php artisan migrate:fresh --seed
```

Sejak 6 Oktober 2026 perintah itu juga membuat dokumen contoh untuk semua berkas demo (bagian 12), jadi kira-kira
30 detik lebih lama.

## 12. Berkas demo diganti dokumen contoh (6 Oktober 2026)

Sebelumnya semua berkas demo (sertifikat, piagam, juknis, brosur, poster, surat tugas) adalah satu halaman
placeholder berukuran 1 kB yang hanya berisi beberapa baris teks. Lead meminta contoh yang tampak seperti dokumen
sungguhan. Pada 6 Oktober 2026 dua perintah
dijalankan di database ini:

**1. `docker compose exec -T app php artisan sth:demo:refresh-files`**

- **97 berkas** di folder `demo/` diganti isinya: 60 sertifikat, 19 piagam penghargaan, 8 juknis lomba, 1 brosur,
  1 poster, 4 surat tugas siswa, dan 4 surat perintah tugas guru (delapan surat demo; sembilan surat uji di bagian 6
  tidak termasuk dan tidak berubah).
- **Barisnya tetap sama.** Tiap berkas ditulis ulang di tempat yang sama; di tabel `files` hanya ukuran dan sidik
  berkasnya (`size`, `sha256`) yang diperbarui. Tidak ada baris yang ditambah atau dihapus.
- **Yang lain tidak berubah.** Dicek sebelum dan sesudah: 13 tabel lain dan 14 berkas di luar folder `demo/` (berkas
  yang di-upload saat uji) sama persis.
- Ukuran folder demo naik dari sekitar 74 kB menjadi sekitar 2,1 MB.

**2. `docker compose exec -T app php artisan sth:demo:refresh-files --lengkapi`**

- Menambah **satu berkas laporan penelitian** pada laporan LKTI milik Keisha Larasati
  (`laporan-penelitian-bioplastik-dari-limbah-kulit-pisang.pdf`, 15 halaman). Data demo yang baru di-seed memang
  punya berkas ini; database ini belum, karena dibuat sebelum perubahannya.
- Ini satu-satunya yang **menambah baris**: satu baris di `files` dan satu baris di `report_files`.
- Hasil perintah: "Laporan penelitian ditambahkan pada 1 laporan riset demo.", "Berkas demo di folder demo/: 98",
  "dibuat ulang · Laporan penelitian: 1", "sudah terbaru (tidak diubah): 97", "ukuran seluruh berkas: 2109 kB".
- Jadi sekarang ada **98 berkas demo**.

**Apa yang dikerjakan perintah ini**

- Hanya jalan di komputer pengembang (`APP_ENV=local`); di tempat lain ia menolak dan tidak mengubah apa pun.
- Hanya menyentuh berkas PDF di folder `demo/`. Berkas yang di-upload dengan tangan dan semua tabel lain dibiarkan.
- Jenis dokumennya ditentukan dari tempat berkas itu terpasang: bukti laporan lomba menjadi sertifikat atau piagam,
  juknis lomba menjadi juknis, surat menjadi surat tugas dengan template sekolah.
- Aman diulang: berkas yang sudah terbaru dilewati. `--force` membuat ulang semuanya, `--berkas="piagam-*"` hanya
  berkas dengan nama seperti itu, `-v` menampilkan satu baris per berkas.

**Yang perlu diingat saat melihat berkasnya**

- Isinya mengikuti data demo: nama siswa, lomba, capaian, tanggal. Semua nama fiktif.
- Penanda tangan sertifikat adalah nama rekaan ("Ketua Panitia", "Ketua Dewan Juri"); tidak ada logo, stempel, atau
  tanda tangan lembaga mana pun. Stempelnya bertulisan CONTOH.
- Tiap halaman bertulisan "CONTOH · data fiktif untuk uji SMAGA Talent Hub · bukan dokumen resmi".
- Tombol **Buka** pada bukti sekarang menampilkan berkas ini di jendela preview, jadi contoh inilah yang terlihat
  saat menguji Verifikasi hasil, Database prestasi, Talenta, dan Portofolio.
- Berkas yang di-upload saat uji tetap seperti saat di-upload.
