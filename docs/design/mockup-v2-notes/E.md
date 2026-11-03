# Agent E notes (Kesiswaan + Kepala Sekolah): DONE

## Files (project/, board sizes unchanged, all 1440x1000, all is_interactive: true)
| File | Interactive state |
|---|---|
| Kesiswaan-Kelola-Lomba.dc.html | `drawer` (default OPEN, NSIF prefilled), `mode` add/edit/usulan, `form` (all fields controlled), `tab` katalog/usulan, `usulan` status per card (baru/draf/jadi/tolak), `toast` |
| Kesiswaan-Verifikasi-Hasil.dc.html | `sel` (queue, default Olimpiade Kimia Nasional Undip 2026), per-laporan `cap` (capaian pick), `chk` (4 checkboxes), `note`, `dec` (''/ok/rev/tolak), `err` |
| Kepsek-Persetujuan.dc.html | `fb` bidang filter, per-row `checked`, `dec` (''/ok/tolak), `open` (inline tolak note), `note`, `err` |

## Behaviour
- Kelola: "Tambah lomba" opens the drawer with NSIF values. Row edit icon opens "Ubah lomba" with that row's values (primary becomes "Simpan perubahan"). "Jadikan lomba" on a usulan opens the drawer prefilled from the proposal (chip "Dari usulan <nama>"). "Publikasikan" / "Simpan draf" close the drawer, show a toast and mark the source usulan "Dijadikan lomba" / "Draf dibuat". "Tolak" marks it red "Ditolak" (with Urungkan). Live rumus: "Jika Juara I: <tingkat> × 1,00 × <bobot> = N poin" (NSIF 80 × 1,00 × 0,40 = 32, same as C's detail). Usulan count (tab, stat tile, sidebar badge) is computed.
- Verifikasi: "Verifikasi dan beri poin" is disabled until all 4 checks are ticked (the default report starts with 4/4). "Minta revisi" and "Tolak" need a catatan (inline red error). Decisions turn the queue chip green/orange/red, show a result banner with "Urungkan", and lower the sidebar badge/"N menunggu". Team logic kept: if a report has n > 1 members the gold tile reads "Poin tiap anggota" (all 4 current reports are individu).
- Kepsek: "Setujui terpilih (n)" counts checked pending rows in the current filter (default 2: LPB + OSN). Per-row "Setujui" → green row + chip "Disetujui · masuk antrean TU" + Urungkan. "Tolak" opens an inline required "Alasan penolakan (wajib)" field → "Kirim penolakan" → red row with the reason. Ringkasan updates live: Menunggu 5 · Disetujui minggu ini 7 (+approved) · Ditolak 1 (+rejected). Sidebar badge = Menunggu.

## Kelola lomba form fields (what Kesiswaan enters = what C shows)
Nama lomba · Bidang (4 picks) · Penyelenggara · Jenis penyelenggara (10 options) · Kategori bobot A–E · Status ajang (5 options) · Pelaksanaan Daring/Luring/Hybrid · Tingkat (5, helper "Sesuai cakupan peserta nyata.") · Daftar dibuka · Batas daftar · Tanggal mulai · Tanggal selesai · Tempat · Peserta Individu/Tim + Maks anggota · Guru pembimbing default · Website resmi · Juknis/brosur upload · Sampul (opsional, "Kosongkan untuk sampul warna bidang.").
NSIF prefill: penyelenggara "[nama penyelenggara swasta]" (matches F; C shows "Penyelenggara swasta"), Perusahaan/swasta, D, Non-pemerintah, Nasional, Daring, dibuka 15 Sep 2026, batas 20 Okt 2026, lomba 7 Nov 2026 (mulai = selesai), tempat Daring, Tim maks 3, Pak Hendra Kusuma, S.Pd., M.Si., juknis-nsif-2026.pdf 1,2 MB, website "[situs resmi penyelenggara]".

## Links used
- Kesiswaan sidebar: Beranda → # · Kelola lomba → Kesiswaan-Kelola-Lomba.dc.html · Usulan siswa → Kesiswaan-Kelola-Lomba.dc.html (inside Kelola it is `#usulan` + onClick that switches to the Usulan tab) · Verifikasi hasil → Kesiswaan-Verifikasi-Hasil.dc.html · Database prestasi / Talenta siswa / Apresiasi → #
- Kelola table "Lihat" icon → Detail-Lomba.dc.html. Usulan tautan/brosur, pengumuman link → #.
- Kepsek sidebar: Dashboard → Dashboard-Sekolah.dc.html · Persetujuan → Kepsek-Persetujuan.dc.html · Prestasi per bidang → Prestasi-Bidang.dc.html · Laporan → #. "Lomba terdekat" header link "Dashboard" → Dashboard-Sekolah.dc.html.

## CSS appended (end of helmet style, screen-local)
- All 3: `.label{line-height:16px}` override, `.ellip`, `.cv`
- Kelola: `.fld .dr .input .seg .seg-btn .seg-on .bpick .kpick .kl .kw .upload .tbl .tabp .tabp-on .cnt`
- Verifikasi: `.q-row .q-on .cpick .tile .tile-l .tile-v .op .check .wrow .dec .dec-ok .dec-rev .dec-tolak`, `.btn[disabled]`
- Kepsek: `.cbwrap .check .row .row-sel .row-ok .row-tolak .mlabel .mval`, `.btn[disabled]`

## Data I invented (keep consistent)
- Stat tiles: Lomba aktif 6 "pendaftaran dibuka" · Pendaftar bulan ini 39 "siswa di 7 lomba" (= 12+5+7+4+3+6+2) · Usulan siswa 3 · Ditutup minggu ini 1 "FLS3N Kota · 2 Okt" (6 + 1 + KRENOVA segera dibuka = 8).
- Table order = nearest batas daftar: FLS3N (2 Okt, orange "2 hari lagi"), OSN (8 Okt), O2SN (9 Okt), LPB (10 Okt), LDBI (12 Okt), Pencak Silat (15 Okt), NSIF (20 Okt), KRENOVA ("Dibuka 1 Des 2026", 0 siswa). Statuses: 7 Dipublikasikan (green) + KRENOVA "Segera dibuka" (blue, per my brief; C's student card uses gray). Draf/Ditutup chips appear only through interaction (Simpan draf on a usulan).
- Daftar dibuka dates (edit mode only): FLS3N 10 Sep · OSN 8 Sep · O2SN 9 Sep · LPB 1 Sep · LDBI 12 Sep · Silat 15 Sep · NSIF 15 Sep · KRENOVA 1 Des 2026. Juknis files: juknis-osn-kota-2026.pdf 820 KB, juknis-lpb-2026.pdf 1,4 MB, juknis-fls3n-kota-2026.pdf 1,1 MB, juknis-o2sn-kota-2026.pdf 960 KB, juknis-ldbi-kota-2026.pdf 780 KB, juknis-silat-pelajar-jateng-2026.pdf 1,2 MB.
- Usulan cards: ISPO 2027 · Raka Pradipta XII-2 (RP) · "Panitia ISPO" · tautan ispo.or.id · brosur-ispo-2027.pdf 1,8 MB · 28 Sep 2026. Lomba Film Pendek Pelajar Semarang · Salsabila Kirana XI-5 (SK) · "Komunitas Film Pelajar Semarang" · no link · poster-film-pendek.jpg 640 KB · 27 Sep 2026. Kompetisi Robotik Pelajar Jateng 2026 · Bima Satria Wicaksono XII-7 (BW) · "Lembaga Robotika Pelajar Jateng" · instagram.com/robotikpelajar.jateng · juknis-robotik-jateng.pdf 1,2 MB · 21 Sep 2026 (the one older than 7 days that G's dashboard mentions).
- Verifikasi laporan (coordinator decision: 4 NEW pending reports, shown nowhere else; FIKSI / O2SN Provinsi / OSEBI / FLS3N Provinsi are treated as already verified by A and F):
  1. Olimpiade Kimia Nasional Universitas Diponegoro 2026 · Sains · Nasional · kategori C (Perguruan tinggi) · Rizky Maulana XI-1 (RM) · klaim Juara III · Pak Hendra Kusuma · Kemarin 19.20 / 29 Sep 2026 · sertifikat-okn-undip-2026.pdf 1,1 MB · 80 × 0,70 × 0,60 = 33,6 (SELECTED, 4/4 checks)
  2. Lomba Karya Tulis Ilmiah Remaja Jateng 2026 · Riset · Provinsi · kategori B (Pemerintah daerah) · Keisha Larasati XI-6 (KL) · klaim Finalis · Bu Retno · 28 Sep 2026 · sertifikat-lkti-remaja-jateng-2026.pdf 860 KB · 60 × 0,50 × 0,80 = 24
  3. Popda Kota Semarang 2026 · Atletik · Olahraga · Kota/Kab · kategori B · Bima Satria Wicaksono XII-7 (BW) · klaim Medali perak · Pak Agus · 27 Sep 2026 · piagam-popda-atletik-2026.pdf 720 KB · 40 × 0,85 × 0,80 = 27,2
  4. Festival Tari Pelajar Semarang 2026 · Seni Budaya · Kota/Kab · kategori E (Umum) · Citra Ayu Lestari XI-2 (CL) · klaim Juara I · Bu Maya · 26 Sep 2026 · sertifikat-festival-tari-2026.pdf 940 KB · 40 × 1,00 × 0,20 = 8
  Points keep one decimal with comma when not whole (33,6 · 27,2).
- Kepsek rows: LPB (Nadia NA + Dimas DN, Bu Retno, 17 Okt Daring) · OSN Kota (Hana HS, Pak Hendra, 14 Okt) · O2SN Kota Bulu tangkis (Fajar FR, Pak Agus, 20 Okt) · LDBI Kota (Salsabila SK ketua, Citra CL, Aurelia AM, Bu Maya, 24 Okt) · Pencak Silat (Yoga YP, Pak Agus, 1 Nov, Jawa Tengah). All "Didaftarkan 29 Sep". Lomba terdekat: 14 Okt OSN (12) · 17 Okt LPB (5) · 20 Okt O2SN (4) · 24 Okt LDBI (6) · 27 Okt FLS3N (7).

## Cross-check
- Initials follow C's first+last rule. NSIF penyelenggara unnamed (C/F). Pencak Silat place "Jawa Tengah" (C).
- Resolved by coordinator: the verification queue now holds 4 new reports (above), so there is no overlap with F Humas or A public home.

## Deviations
- Date row labels shortened to fit 4 columns in the 520px drawer: "Daftar dibuka" (new field, needed for C's "Pendaftaran dibuka" and KRENOVA "Segera dibuka"), "Batas daftar" (= Batas pendaftaran), "Tanggal mulai", "Tanggal selesai".
- "Tambah lomba" sits in the page header next to search (CrioEd top-right).
