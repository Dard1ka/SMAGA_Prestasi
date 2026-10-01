# Agent A notes (public pages): DONE

## Files (project/, board sizes unchanged)
| File | Root size | Interactive |
|---|---|---|
| Main.dc.html | 1440x1100 | links only (no state). Set `is_interactive: true` if you want Play navigation. |
| Prestasi-Bidang.dc.html | 1440x1000 | yes: `bidang` (default Riset), `siswa` (default `raka`, so the canvas preview shows all 3 levels; picking another bidang resets it to null = empty state), `tahun`, `tingkat` selects |
| Peringkat.dc.html | 1440x1000 | yes: `mode` siswa/kelas (segmented, aria-pressed), `bidang` + `angkatan` selects, `alumni` checkbox (siswa mode only) |

## Public header (same in all 3)
72px, `.pub-head` grid (brand left, nav center, `btn btn-primary btn-sm` Masuk right). Current link = `pub-link pub-on` + aria-current.

## Links used
- Header: Beranda -> Main · Prestasi -> Prestasi-Bidang · Peringkat -> Peringkat · Masuk -> Masuk
- Main: hero "Lihat prestasi" -> Prestasi-Bidang, "Masuk" -> Masuk · 4 bidang tiles + "Lihat siswa per bidang" + "Lihat semua" (Prestasi terbaru) -> Prestasi-Bidang · "Lihat peringkat" -> Peringkat · footer "Situs sekolah" -> #
- Prestasi-Bidang: "Lihat semua siswa <bidang>" -> # · "Lihat sertifikat" -> #
- Peringkat: no outgoing links besides header

## Behaviour
- Prestasi-Bidang: level 1 = 4 bidang buttons (count + share bar), selected uses the bidang color border/tint; below it a 3-bar "Perkembangan per tahun" for the selected bidang (selected tahun full opacity, others 35%). Level 2 = students with at least one prestasi in that bidang matching the filters, sorted by total poin, row = avatar, nama, Alumni chip, "n prestasi", kelas, best capaian (gold) + tingkat (gray). Level 3 = student header (Total poin gold box, Peringkat #n "siswa aktif" or "termasuk alumni") + ALL the student's verified prestasi sorted by poin: tinted sertifikat thumb, lomba, "cabang · penyelenggara (kategori) · tahun ajaran", tingkat/capaian/bidang chips, gold poin, rumus "T × C × P = poin", "Lihat sertifikat" (#). Tahun/tingkat filters change level 1 counts, the chart, and which students are listed.
- Peringkat: top 3 cards (#1 gold circle) + table rows #4–#9, then "…" row "Peringkat 10–13" and #14 Nadia (only when she is beyond #9 in the filtered list). With "Tampilkan alumni": alumni appear with chip "Alumni", Nadia becomes #15. Kelas mode: top 3 class cards + class table (Peringkat · Kelas · Angkatan · Siswa berprestasi · Prestasi · Poin). Table rows are fixed `<tr>` slots hidden with `.row-hide` (no sc-for inside tbody).
- Right card "Cara poin dihitung": rumus tiles 80 × 1,00 × 1,00 = 80 poin (Juara I Nasional Puspresnas) + tables Capaian (7 rows incl. Semifinalis 0,30 and Penghargaan khusus 0,30), Tingkat, Penyelenggara (Kategori A–E) + note "Anggota tim masing-masing mendapat poin penuh."

## CSS appended (end of helmet style)
All 3: `.pub-head .pub-nav .pub-link .pub-on`. Main: `.lnk .bd-tile .bd-tile-ic .rk .rk-1 .bar .bar-col .bar-val .lg .lg-dot`. Prestasi-Bidang: `.lnk .sel .sel-ic .bd-btn .bd-on-sains/-riset/-seni/-olahraga .bd-ic .meter .meter-fill .st-row .st-on .ach .cert .pt .bar-v`. Peringkat: `.seg .seg-btn .seg-on .sel .sel-ic .cek .rk3 .rk3-1 .tb-av .tb-kl .lb .row-hide .rt .rt-l .rt-v .rt-gold .op .wt`.

## Numbers (match G's dashboard)
- Per tahun × bidang: G's matrix exactly (2024/2025 10/8/7/6 = 31 · 2025/2026 13/11/10/10 = 44 · 2026/2027 6/5/4/3 = 18). I did NOT use the brief's example Riset 7/11/6.
- Per bidang × tingkat (my invention, rows = bidang totals, columns = spec tingkat totals): Sains Int 3 / Nas 6 / Prov 9 / Kota 11 · Riset 4/7/4/9 · Seni Budaya 1/5/7/8 · Olahraga 1/3/5/10.
- Main stat strip: 93 "Sejak 2024/2025" · Nasional & internasional 30 "21 nasional · 9 internasional" · Siswa berprestasi 61 "Minimal 1 prestasi terverifikasi" · Lomba diikuti 2026/2027 46.
- Main "Peringkat teratas" uses G's main bidang: Raka Riset · Salsabila Seni Budaya · Bima Riset · Aurelia Sains · Fajar Olahraga.

## Verified prestasi per student (drill-down data; totals = spec leaderboard)
Categories: Puspresnas A · Pemda / pemerintah pusat B · CYS, federasi, perguruan tinggi, organisasi profesi C (E rates Undip as C) · swasta, IYSA, Eduversal D. NSIF penyelenggara = "Penyelenggara swasta". KRENOVA = "Pemerintah Kota Semarang".
- Raka Pradipta XII-2 312: FIKSI 2026 Nas J1 A 80 · OPSI 2025 Nas J1 A 80 · Asian Young Researchers Contest 2025 Int Medali emas C 60 · OSN Provinsi Jateng 2024 Informatika Prov J1 A 60 · KRENOVA Kota Semarang 2025 J1 B 32
- Salsabila Kirana XI-5 268: FLS3N Nasional 2025 Monolog J1 A 80 · Festival Film Pendek Pelajar 2026 (Kementerian Kebudayaan) Nas J1 B 64 · FLS3N Provinsi Jateng 2026 Baca puisi J3 A 42 · LDBI Provinsi Jateng 2025 J3 A 42 · LDBI Kota Semarang 2025 J1 A 40
- Bima Satria Wicaksono XII-7 240: Kompetisi Robotik Nasional 2025 (Kementerian Perindustrian) J1 B 64 · O2SN Nasional 2025 Karate Medali perunggu A 56 · Kejurnas Karate Pelajar 2026 (PB FORKI) Medali emas C 48 · WSEEC 2025 (IYSA) Int Medali emas D 40 · KRENOVA 2025 J1 B 32
- Aurelia Maharani X-3 196: Kompetisi Biologi Nasional 2026 (BRIN) J1 B 64 · Olimpiade Sains Pelajar Jateng 2026 Biologi (Disdikbud Jateng) J1 B 48 · Olimpiade Biologi Jateng 2026 (Universitas Diponegoro) J1 C 36 · Lomba Inovasi Pelajar Kota Semarang 2026 J1 B 32 · OSEBI 2026 (Eduversal) Nas Finalis D 16
- Fajar Ramadhan XII-1 180: O2SN Provinsi Jateng 2026 Pencak silat J1 A 60 · O2SN Kota Semarang 2025 Pencak silat J1 A 40 · Kejuaraan Pencak Silat Pelajar Jateng 2025 (IPSI Jawa Tengah) J1 C 36 · Popda Kota Semarang 2025 Pencak silat (Dispora Semarang) J1 B 32 · O2SN Kota Semarang 2024 Bulu tangkis Semifinalis A 12
- Citra Ayu Lestari XI-2 164: Lomba Musikalisasi Puisi Nasional 2026 (Badan Bahasa) J1 B 64 · FLS3N Provinsi Jateng 2025 Baca puisi J1 A 60 · FLS3N Kota Semarang 2025 Baca puisi J1 A 40
- Yoga Pratama XII-5 150: LPB 2025 J1 C 48 · O2SN Kota Semarang 2024 Pencak silat J1 A 40 · NSIF 2025 Medali emas D 32 · O2SN Provinsi Jateng 2024 Pencak silat Finalis A 30
- Hana Safira X-8 132: Kompetisi Matematika Nasional UNNES 2026 J1 C 48 · Olimpiade Sains Pelajar Jateng 2026 Kimia J1 B 48 · Olimpiade Kimia Jateng 2026 (Undip) J1 C 36
- Rizky Maulana XI-1 121: OSN Provinsi Jateng 2025 Fisika J2 A 51 · Olimpiade Fisika Jateng 2026 (Himpunan Fisika Indonesia) J1 C 36 · O2SN Kota Semarang 2026 Atletik J2 A 34 (= F's Humas item)
- Keisha Larasati XI-6 118 (#10): OPSI 2025 J2 A 68 · NSIF 2025 Medali emas D 32 · Asian Young Researchers Contest 2025 Semifinalis C 18
- Dimas Arya Nugroho XI-4 112 (#11): LPB 2025 J1 C 48 · NSIF 2025 Medali emas D 32 · Lomba Inovasi Pelajar Kota Semarang 2025 J1 B 32
- Farhan Aditya XI-3 110 (#12, new): OSN Nasional 2025 Matematika Medali perak A 68 · OSN Provinsi Jateng 2025 Matematika J3 A 42
- Laras Wening Pangestu XI-7 108 (#13, new): FLS3N Provinsi Jateng 2025 Tari J1 A 60 · Festival Tari Pelajar Jateng 2026 (Disdikbud Jateng) J1 B 48
- Nadia Putri Anindya XI-4 104 (#14): OPSI 2026 J2 A 68 (2026/2027) · KRENOVA Kota Semarang 2026 J1 B 32 (2025/2026) · O2SN Kota Semarang 2026 Atletik Peserta A 4 (2026/2027), matches D
- Galuh Prameswari XII-4 96 (#15, new): FLS3N Nasional 2024 Kriya J3 A 56 · FLS3N Kota Semarang 2024 Kriya J1 A 40
- Bayu Anggara XII-6 88 (#16, new): LDBI Kota Semarang 2024 J1 A 40 · LDBI Provinsi Jateng 2024 Finalis A 30 · FLS3N Provinsi Jateng 2025 Teater Semifinalis A 18
- Alumni (G's archived names, angkatan 2022, lulus 2025): Ardiansyah Nur Hakim, last kelas XII-3, AH, 236 poin, 5 prestasi, all Sains 2024/2025 (OSN Nasional/Provinsi/Kota 2024 Kimia, LCT MIPA Kota Semarang 2024, Olimpiade Kimia Nasional Undip 2024 Finalis C 24) · Tiara Anjani Lestari, last kelas XII-8, TL, 94 poin, 2 prestasi, Olahraga (O2SN Provinsi Jateng 2024 Atletik Medali emas 60, O2SN Kota Semarang 2024 Atletik Medali perak 34). With alumni shown: Ardiansyah is #4.
- Peringkat Kelas (invented): XII-2 452 · XI-5 398 · XII-7 351 · XI-4 330 · X-3 284 · XII-1 262 · XI-2 239 · XII-5 214 · X-8 176 · XI-1 158 · XI-6 150 · XI-3 142 · XI-7 131 · XII-4 120 · XII-6 112. Bidang filter in Kelas mode uses per-bidang class points, and the counts are estimated from the class totals.

## Cross-check / conflicts
- Initials follow C (first + last word). G's Dashboard shows Bima as "BS". It should be "BW" (C, D, E and A all use BW).
- FIKSI 2026 (Raka) and O2SN Provinsi Jateng 2026 (Fajar) are "Prestasi terbaru" on Main. They count as verified, per the coordinator decision recorded in E.md. OSEBI 2026 (Aurelia) and FLS3N Provinsi 2026 (Salsabila) are also included as verified, and all totals still match the spec.
- Public pages show only nama, kelas and achievements, with initials avatars (photo consent is not needed).

## Deviations
- Prestasi-Bidang opens with a student already selected (Raka) instead of the empty state, so the static canvas view shows the full drill-down. The empty state appears after switching bidang.
- Main hero line under the headline is 1 line: "Capaian lomba siswa yang sudah diverifikasi sekolah."
