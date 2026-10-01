# Form "Penjaringan Peserta Program Pembinaan Talenta SMAN 3 Semarang Tahun 2026"

Sumber: Google Form milik SMAN 3 Semarang (https://forms.gle/c2YAyKteWL2PsPRt9). Ditelusuri 30 Sep 2026 dengan data uji di akun user, **tidak dikirim**, draf sudah dikosongkan.
Tujuan dokumen: acuan modul **Talent Scouting / Pembinaan Talenta** di STH.

## Tujuan program (dari deskripsi form, diringkas)
Mengidentifikasi & memetakan talenta siswa, menentukan peserta per bidang pembinaan, menyusun program pembinaan, pendampingan prestasi, dan persiapan lomba (utama: ajang PUSPRESNAS 2027).
Tahapan setelah penjaringan: **penjaringan → pemetaan → seleksi dan/atau asesmen lanjutan** sesuai bidang talenta.
Catatan form: email, nama, dan foto akun Google direkam saat upload file/kirim.

## Struktur form
Halaman 1 (semua wajib):
| Field | Tipe | Pilihan |
|---|---|---|
| Nama lengkap | teks | – |
| Kelas | pilihan tunggal | X-1 … X-10, X-TAHFIDZ, X-OLIMP, XI-1 … XI-12 (tidak ada kelas XII) |
| Pilihan jenis talenta | pilihan tunggal (bercabang) | OSN · Riset · FLS3N · O2SN · OLPAIN · Debat |

Halaman 2 bergantung pada jenis talenta (lalu Kirim). Semua cabang punya **"Kirimkan bukti dukung (sertifikat), jika memiliki"**: upload opsional, 1 file, maks 10 MB.
| Jenis talenta | Pertanyaan wajib | Pilihan |
|---|---|---|
| OSN | Pilihan bidang olimpiade | Fisika, Kimia, Biologi, Matematika, Komputer, Koding dan AI, Astronomi, Kebumian, Geografi, Ekonomi |
| Riset | Pilihan cabang lomba | OPSI, FIKSI |
| FLS3N | Pilihan cabang lomba | Baca Puisi, Cipta Lagu, Cipta Puisi, Desain Poster, Fotografi, Instrumen Solo Gitar, Menyanyi Solo Putra, Menyanyi Solo Putri, Jurnalistik, Kriya, Komik Digital, Menulis Cerita Pendek, Monolog, Tari Kreasi (2 peserta/tim), Film Pendek (3 peserta/tim), Kreativitas Musik Tradisional (5 peserta/tim) |
| O2SN | Pilihan cabang lomba | Atletik, Renang, Bulu Tangkis, Pencak Silat, Karate |
| OLPAIN | – (hanya upload bukti) | – |
| Debat | Lomba Debat Indonesia (LDI) | Debat Bahasa Indonesia (LDBI), Debat Bahasa Inggris (NSDC) |

## Implikasi untuk STH
- Jadikan master data: `jenis_talenta` (6) → `cabang_talenta` (daftar di atas, bisa ditambah Admin/Kesiswaan), flag `is_tim` + `ukuran_tim` untuk cabang tim FLS3N.
- Di STH, nama & kelas otomatis dari akun siswa (tidak diketik ulang). Siswa memilih jenis talenta + cabang, upload bukti opsional (aturan upload sama dengan modul lain: PDF/JPG/PNG, maks 10 MB).
- Status penjaringan: Terdaftar → Pemetaan → Seleksi/Asesmen → Peserta pembinaan / Tidak lolos (dikelola Kesiswaan/guru pembina).
- Daftar kelas di form = validasi kolom "Kelas saat pendaftaran" pada import Excel (termasuk X-TAHFIDZ, X-OLIMP).
- Mapping ke 4 bidang STH (perlu konfirmasi): OSN→Sains · Riset→Riset · FLS3N→Seni Budaya · O2SN→Olahraga · Debat→Seni Budaya (sesuai dokumen konsep) · OLPAIN→**belum jelas**.

## Keputusan user (30 Sep 2026)
1. Tetap 4 bidang: OSN → Sains · **OLPAIN → Sains** · Riset → Riset · FLS3N → Seni Budaya · **Debat → Seni Budaya** · O2SN → Olahraga.
2. **STH menggantikan Google Form ini**: penjaringan talenta dilakukan langsung di STH (menu Profil talenta siswa; Kesiswaan memantau di menu Talenta siswa).
3. Siswa **boleh memilih lebih dari satu** jenis talenta; setiap pilihan punya status penjaringan sendiri.
4. **Kelas XII tetap boleh ikut** penjaringan (semua kelas aktif; akun yang sudah diarsipkan tidak bisa).
