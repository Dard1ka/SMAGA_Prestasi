# Agent G notes: Admin row (Import siswa, Pengaturan, Dashboard sekolah)

## Files (all in project/, board sizes unchanged)
| File | Root size | Interactive |
|---|---|---|
| Admin-Import-Siswa.dc.html | 1440×1000 | yes: state `step` (1–3, default 3), `imported`, `filter` (all/bad) |
| Admin-Pengaturan.dc.html | 1440×1000 | yes: state `tab` (bobot default · batas · surat · user · email) + live inputs |
| Dashboard-Sekolah.dc.html | 1440×1080 | static (CSS hover tooltips on bars only) |

## Interactive parts
- Import: stepper buttons set `step`. Step 1 "Unduh template Excel" moves 1 → 2. Step 2 "Pilih file" → step 3 (file row appears). "Ganti file" → step 2. Step 3 filter "Semua baris" / "Perlu diperbaiki · 2" switches between two static tables. "Impor 248 siswa" swaps the summary tiles for the green banner "248 akun siswa dibuat". "Impor file lain" resets to step 2.
- Pengaturan: vertical tabs (role=tab, aria-selected). Bobot poin: 17 text inputs, decimal comma. The "Contoh langsung" rumus tile recomputes live from Nasional × Juara II × A (default 80 × 0,85 × 1,00 = 68 poin, same as Nadia's OPSI). Switch "Anggota tim mendapat poin penuh" (role=switch). "Simpan bobot" shows chip "Bobot tersimpan". Batas & arsip: number inputs 30 and 18. The example "kelas XII mulai Juli 2027 → arsip Januari 2029" and "Arsip berikutnya · angkatan 2023: Januari 2027" recompute live. Surat: "Hapus" swaps to an empty upload state and "Unggah file" restores it. "Ganti pejabat" jumps to the Pengguna tab. Each format chip has an edit button that opens an inline input with Simpan/Batal. Pengguna: "Reset kata sandi & email" shows chip "Akun direset". Email: switch "Mode uji OTP" (on) toggles the orange chip.

## Link targets used
- Admin sidebar (both admin files): Dashboard → Dashboard-Sekolah.dc.html · Kelola lomba → Kesiswaan-Kelola-Lomba.dc.html · Import siswa → Admin-Import-Siswa.dc.html · Pengguna & peran → # · Arsip otomatis → # · Pengaturan → Admin-Pengaturan.dc.html
- Dashboard (Kepala Sekolah sidebar): Dashboard → Dashboard-Sekolah.dc.html · Persetujuan [5] → Kepsek-Persetujuan.dc.html · Prestasi per bidang → Prestasi-Bidang.dc.html · Laporan → #
- Dashboard body: KPI tile "Menunggu persetujuan 5" → Kepsek-Persetujuan.dc.html · the 4 bidang bar rows → Prestasi-Bidang.dc.html · "Lihat peringkat" → Peringkat.dc.html · "5 pendaftaran menunggu persetujuan" → Kepsek-Persetujuan.dc.html

## Dashboard numbers (A: public page must match)
- Per tahun ajaran × bidang (Sains / Riset / Seni Budaya / Olahraga = total):
  2024/2025: 10 / 8 / 7 / 6 = 31 · 2025/2026: 13 / 11 / 10 / 10 = 44 · 2026/2027 (berjalan): 6 / 5 / 4 / 3 = 18
  Bidang totals: 29 / 24 / 21 / 19 = 93.
- Per tingkat: Kota/Kab 38 · Provinsi 25 · Nasional 21 · Internasional 9 (93).
- KPI: Prestasi terverifikasi 93 ("18 di tahun ajaran ini") · Siswa berprestasi 61 · Menunggu persetujuan 5 · Laporan lewat batas 3.
- Siswa teratas: Raka Pradipta XII-2 · Riset 312 · Salsabila Kirana XI-5 · Seni Budaya 268 · Bima Satria Wicaksono XII-7 · Riset 240 · Aurelia Maharani X-3 · Sains 196 · Fajar Ramadhan XII-1 · Olahraga 180. The bidang is each student's main bidang (my choice).
- Chart colors follow spec §2. The grouped bars are ordered Sains, Seni Budaya, Riset, Olahraga because the dataviz validator fails Sains↔Riset as neighbours (deutan ΔE 2.1). This order passes every check; the legend uses the same order. Tingkat bars use a validated single-hue ordinal indigo ramp: Kota #7F90EA, Provinsi #5A6EE0, Nasional #3A50D2, Internasional #26359E.

## New CSS classes (screen-local, appended at end of helmet)
- Dashboard: .tile-link .vcol .vval .vbar .vtip .brow .hbar .hval .tbl .att
- Import: .step-btn .sd .sd-done .sd-cur .sd-up .sl .sl-done .st-lbl .st-lbl-cur .snum .scard-cur .drop .seg .seg-btn .seg-on .tbl .row-bad .tile .tile-ic
- Pengaturan: .vtab .vtab-on .wbox .wtbl .wt .rtile .rtile-l .rtile-v .op .sw .sw-on .knob .setbox .utbl .mono .link-btn .sig .input:disabled

## Fictional data I invented
- Import rows (file data-siswa-2026-2027.xlsx, 250 baris, 248 siap / 2 perlu diperbaiki / 0 duplikat): Aurelia Maharani 226227031 · 0101••••57 · X-3 · 2026 · Jul 2028 · Jan 2030 · Siap. Hana Safira 226227088 · 0103••••14 · X-8 · Siap. Galang Putra Mahendra 226227004 · 0102••••86 · X-1 · "Tanggal lahir tidak valid". Nabila Zahra Ramadhani 226227142 · 0098••••32 · X-5 · "NISN sudah dipakai" (it collides with Nadia's NISN). Rafi Aditya Pratama 225226245 · 0097••••61 · XI-6 · 2025 · Jul 2027 · Jan 2029 · Siap (a transfer student).
- Recently archived alumni (angkatan 2022, XII Jul 2024, diarsipkan 1 Jan 2026): Ardiansyah Nur Hakim 5 prestasi · Putri Maharani Kusuma 3 · Bagas Wirayudha 4 · Tiara Anjani Lestari 2. Chip "Alumni · hanya baca". Angkatan 2023 has 342 akun (only shown if the archive month lands on Okt 2026).
- Staff logins: kepsek, kesiswaan, tu, humas, retno.w, hendra.k, maya.a, agus.s.
- Penandatangan placeholders: Nama "Drs. Bambang Hartono, M.Pd.", Pangkat/Golongan "Pembina Tk. I, IV/b", NIP "18 digit".
- Format examples: 400.3.8/312/MRD/IX/2026 and 400.3.8.6/1680/2026 (last used numbers 312 / 1680 from spec §5).
- Kategori penyelenggara hints: A Puspresnas · B Pemda · C Federasi · D Swasta · E Lainnya.
- Signature file: ttd-stempel-kepsek.png, "PNG transparan, maks 1 MB", chip "Aktif", note "Dipakai otomatis di semua surat tugas PDF." (F: same filename)
- SMTP placeholders (disabled): smtp.contoh.sch.id · 587 · noreply@contoh.sch.id · SMAGA Talent Hub.
- Perlu perhatian: "Terdekat: OSN Kota, 14 Okt" · "Siswa belum melaporkan hasil." · "1 usulan lomba belum diperiksa · Di Kesiswaan lebih dari 7 hari." (1 of Kesiswaan's 3 usulan).
