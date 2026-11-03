# Agent D notes (FINAL)

## Files (project/, board sizes unchanged)
| File | Root size | Interactive |
|---|---|---|
| Beranda-Mobile.dc.html | 390x844 | no (static links only) |
| Lomba-Saya.dc.html | 1440x1000 | yes: `tab` (berjalan/selesai), `sel` (wice/lpb/opsi/krenova/o2sn), `lapor` (inline form), `hasil` (10 picks), `asli` checkbox, `err`, `terkirim` |
| Portofolio.dc.html | 1440x1000 | no (tabs are static role=tab) |
| Lapor-Hasil-Mobile.dc.html | 390x1980 | yes: `hasil` (default Medali emas), `asli`, `err`, `terkirim`, controlled `judul` + `abstrak` |
Coordinator: set `is_interactive: true` for Lomba-Saya and Lapor-Hasil-Mobile.

## Beranda-Mobile layout (B mirrored this verbatim in Tur-Menu-Mobile; I will not change it)
`<main>` absolute top 0 / bottom 80px, padding 22px 20px 0, gap 14. y: header 22-72 (h1 "Halo, Nadia" + date, bell 44x44 with dot) · blue points banner 86-162 ("Poin kamu" / 104 / "poin · #14" + `.bn-link` "Peringkat") · orange card 176-304 ("Laporan WICE 2026 ditunggu", "Batas 24 Okt · 24 hari lagi", left-aligned primary "Laporkan hasil") · full-width primary "Cari lomba" 318-370 · "Lombaku" header 384-410 + cards WICE 420-576 and LPB 586-742 · `.tabbar` 764-844 (Beranda current).

## Interactive behaviour
- Lomba-Saya: segmented "Berjalan 2 | Selesai 3" (aria-pressed) swaps the list; switching tab selects the first item. List cards are `<button aria-pressed>`. Detail pane (right, `overflow-y:auto`) = shared header card (cover tile, title, subtitle, chips, date, place, full tracker v2) + per-type block:
  - WICE: "Laporkan hasil" card (orange chip "Batas 24 Okt · 24 hari lagi", team note). Button opens inline form: 10 pick buttons, 2 dropzones (sertifikat, foto), live rumus 100 × capaian × 0,40 = poin, checkbox, "Kirim laporan". Kirim without checkbox shows red alert; with checkbox, the card becomes "Laporan terkirim" + chip c-blue "Laporan dikirim", tracker phase 5 turns blue current ("Laporan dikirim"), list chip turns c-blue. Below: "Surat tugas" (2 PDF rows, download icon-buttons) + "Tim" (3 members, all "Sudah konfirmasi", pembimbing Bu Retno).
  - LPB: "Persetujuan" card + chip c-gray "Menunggu Kepala Sekolah" + `btn-danger btn-sm` "Batalkan pendaftaran" (no handler); "Surat tugas" empty state with "Surat tugas dibuat setelah Kepala Sekolah menyetujui."; Tim (Nadia ketua, Dimas "Sudah konfirmasi") + Bu Retno.
  - Selesai: "Capaian" card (gold chip, green "Poin diberikan", rumus tiles) + "Sertifikat" card (placeholder art + quiet "Unduh sertifikat" -> #).
- Lapor-Hasil-Mobile: 2-column pick grid (Juara 1, 2, 3, Medali emas, perak, perunggu, Finalis, Semifinalis, Penghargaan khusus, Peserta), live rumus (tiles row + gold "= Perkiraan poin kamu"). "Kirim laporan" needs the checkbox (else red alert). Sent state swaps the bottom bar for "Laporan terkirim" + quiet "Kembali ke beranda".

## Links used
- Beranda-Mobile: "Peringkat" -> Peringkat · "Laporkan hasil" -> Lapor-Hasil-Mobile · "Cari lomba" -> Katalog-Lomba · "Lihat semua" + both cards -> Lomba-Saya · tab bar per spec (Beranda-Mobile / Katalog-Lomba / Lomba-Saya / Peringkat / Portofolio).
- Lomba-Saya + Portofolio: C's canonical sidebar + top bar verbatim (Lomba saya / Portofolio current). PDF downloads, "Unduh sertifikat", "Unduh portofolio (PDF)", "Ubah" (profil talenta), sertifikat rows -> #.
- Lapor-Hasil-Mobile: back icon-link + "Kembali ke beranda" -> Beranda-Mobile.

## CSS appended (screen-local, end of helmet)
- Beranda-Mobile: `.tab:hover`, `.m-card`, `.m-link`, `.bn-link`, `.sg/.sg-done/.sg-cur/.sg-act`
- Lomba-Saya: `.seg/.seg-on/.seg-n` (segmented), `.reg/.reg-on/.reg-chev` (list card buttons), `.sg*`, `.pane`, tracker `.trk .trk-step .trk-line .trk-dot .trk-lbl .trk-date` with state classes `.s-done .s-cur .s-act .s-up .trk-last`, rumus `.rt .rt-l .rt-v .rt-gold .rt-op`, `.pk` (desktop pick size), `.drop`
- Portofolio: `.ptab/.ptab-on/.ptab-n` (underline tabs), `.sert` (sertifikat row link), `.mini-cert`
- Lapor-Hasil-Mobile: `.mpk`, `.rt*`, `.flabel`, `.drop-m`, `.up-row`

## Data I used or invented (keep consistent)
- Initials follow C's rule: Dimas Arya Nugroho DN, Keisha Larasati KL, Retno Wulandari RW.
- WICE: sub "World Invention Competition and Exhibition · IYSA · Kategori D", 21–24 Sep 2026, SEGi University, Kuala Lumpur. Tracker dates 2 Sep / 5 Sep / 8 Sep / 21–24 Sep / Batas 24 Okt. Surat siswa 400.3.8/312/MRD/IX/2026 ("1 surat untuk 3 anggota"); SPT guru shown without a number ("PDF · Bu Retno Wulandari, S.Pd."). Members: Nadia XI-4 Ketua, Dimas XI-4, Keisha XI-6.
- LPB: "Lomba Peneliti Belia · Center for Young Scientists · Kategori C", 17 Okt 2026, Daring, didaftarkan 29 Sep 2026.
- OPSI 2026: "Olimpiade Penelitian Siswa Indonesia · Puspresnas · Kategori A", Nasional, Riset, Individu, lomba 4–7 Agu 2026 (Jakarta), tracker 6 Jul / 8 Jul / 10 Jul / 4–7 Agu / 12 Agu / 15 Agu, diverifikasi 15 Agu 2026, pembimbing Bu Retno, file sertifikat-opsi-2026.pdf (1,2 MB). 80 × 0,85 × 1,00 = 68.
- KRENOVA Kota Semarang 2026: "Kreativitas dan Inovasi Kota Semarang · Pemerintah Kota Semarang · Kategori B" (Portofolio card: "Pemkot Semarang · Kategori B"), Kota/Kab, Riset, Individu, lomba 24 Feb 2026, tracker 2 Feb … 6 Mar, diverifikasi 6 Mar 2026, pembimbing Bu Retno, sertifikat-krenova-2026.pdf (0,9 MB). 40 × 1,00 × 0,80 = 32.
- O2SN Kota Semarang 2026 · Atletik (same event as F's Rizky Maulana Juara II, published 18 Sep): Kota/Kab, Olahraga, Individu, Peserta, lomba 29 Agu 2026, tracker 3 Agu … 10 Sep, diverifikasi 10 Sep 2026, pembimbing Pak Agus Setiawan, S.Or., piagam-o2sn-2026.pdf (0,8 MB). 40 × 0,10 × 1,00 = 4.
- Portofolio: stats 104 poin kumulatif · #14 peringkat · 3 prestasi · 5 lomba diikuti. Bidang minat Riset + Sains. Profil talenta sample: Minat "Penelitian ilmiah, sains terapan" · Bidang yang dikuasai "Biologi, metodologi penelitian, presentasi ilmiah" · Organisasi/ekskul "KIR SMAGA (anggota inti), OSIS bidang Iptek" · Prestasi SMP "Juara 2 LKIR SMP tingkat Kota Semarang (2024)". Gray chip "Form pembinaan talenta menyusul".
- Lapor-Hasil-Mobile: uploaded sertifikat-wice-2026.pdf (1,4 MB), 2 photo placeholders, judul "Biofilter sabut kelapa untuk air sumur payau", abstract about a coconut-fibre biofilter (12 sampel, salinitas turun rata-rata 38%).

## Deviations
- Lomba-Saya detail pane scrolls inside the board (`overflow-y:auto`). The default WICE view fits with no scroll. With the inline form open, Surat tugas and Tim move below the fold. That is the only way to fit the form and still keep every card.
- LPB: Nadia (ketua) has no status chip, and only Dimas has "Sudah konfirmasi", as in the brief. For WICE, all 3 members have the chip.
