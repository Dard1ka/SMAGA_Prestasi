# Agent F notes (TU + Humas) — DONE

## Files
- `TU-Surat-Tugas.dc.html` — 1440×1100, INTERACTIVE (is_interactive: true). Root `.page` 1440×1100.
- `Humas-Publikasi.dc.html` — 1440×1000, light interactivity (kanal checkboxes, apresiasi toggle, jadwal date/time, "Jadwalkan" → "Terjadwal"). Fine as static; can be is_interactive: true.
- Helmet copied verbatim; sidebar/top bar follow C.md shell (no "Cari lomba" button for staff).

## Interactive parts (TU)
- Queue strip: 3 `pick` buttons (aria-pressed): FLS3N Kota 2026 · Baca puisi · 1 siswa / NSIF 2026 · Tim 3 siswa (default selected) / OSN Kota 2026 · 1 siswa. Each "Disetujui Kepala Sekolah · 30 Sep". Selecting switches form + preview data (per-item form state kept).
- Step 1 form: composed nomor inputs `400.3.8/` [313] `/MRD/X/2026` (hint "Terakhir dipakai: 312") and `400.3.8.6/` [1681] `/2026` (hint "Terakhir dipakai: 1680"); typing 312 / 1680 shows red "Nomor ini sudah dipakai." Tanggal surat, Dasar (nomor + tanggal undangan), Pelaksanaan (hari/tanggal, pukul, tempat) all bound to preview.
- Signature row: thumbnail placeholder + `ttd-stempel-kepsek.png` + "Dipasang otomatis di semua surat" + link "Ganti di Pengaturan" → `#`.
- Step 2 preview tabs (`tab`): "Surat siswa (1 surat · N siswa)" | "Surat guru pendamping". A4 paper 560×792, "PRATINJAU" watermark, number slot shows typed number or orange blank.
- Step 3: "Buat 2 PDF" disabled until both numbers valid → `done`: 2 file rows (`surat-tugas-siswa-nsif-2026.pdf`, `surat-perintah-tugas-guru-nsif-2026.pdf`) with Unduh + "Kirim ke siswa dan guru" → `sent` (chip "Terkirim ke 3 siswa dan 1 guru", queue card becomes "Surat terkirim · 30 Sep", sidebar badge 3 → 2, inputs lock).

## Links used
- TU sidebar: Antrean surat → `TU-Surat-Tugas.dc.html` (current, badge 3) · Arsip surat → `#` · Template surat → `#`. "Ganti di Pengaturan" → `#` (TU has no access to Admin-Pengaturan).
- Humas sidebar: Antrean publikasi → `Humas-Publikasi.dc.html` (current, badge 3) · Jadwal / Apresiasi / Arsip → `#`.

## Letter templates (structure only, placeholders)
- Kop: "Logo Pemprov" / "Logo sekolah" dashed boxes, PEMERINTAH PROVINSI JAWA TENGAH / DINAS PENDIDIKAN / SEKOLAH MENENGAH ATAS NEGERI 3 SEMARANG / Jalan Pemuda Nomor 149, Kota Semarang, Jawa Tengah 50132 + double rule.
- Signature block: "Semarang, 30 September 2026" / "Kepala Sekolah" / dashed box "Tanda tangan + stempel" (= `ttd-stempel-kepsek.png`, owned by G) / "[Nama Kepala Sekolah]" / "[Pangkat]" / "NIP [NIP]".
- Guru letter Lampiran: Hendra Kusuma, S.Pd., M.Si. · [Pangkat] · [NIP] · Guru (shown after a "Halaman 2" divider).

## Fictional data I invented (keep consistent if you reuse)
- NIS: Citra Ayu Lestari XI-2 = 225226219; Aurelia Maharani X-3 = 226227031 (matches G import) (Raka 225226108, Bima 225226131, Yoga 225226147 from brief).
- Lomba schedule: NSIF 2026 Sabtu, 7 November 2026 · 07.00 WIB s.d. selesai · Daring; FLS3N Kota Semarang 2026 Selasa, 27 Oktober 2026 · 07.30 WIB · Kota Semarang; OSN Kota Semarang 2026 Rabu, 14 Oktober 2026 · 07.00 WIB · Kota Semarang.
- Surat undangan: NSIF 017/PAN-NSIF/IX/2026 (15 Sep 2026, from "[nama penyelenggara swasta]"); FLS3N 421.5/0917/2026 (10 Sep, Puspresnas); OSN 421.5/0884/2026 (8 Sep, Puspresnas).
- Kepala Sekolah approval date for all 3 TU queue items: 30 Sep 2026 (Agent E: if you show an "already approved" list, use 30 Sep).
- Humas cards: FIKSI 2026 Raka Pradipta XII-2 Juara I Nasional (pembimbing Bu Retno Wulandari, izin foto ada, 4 foto, SELECTED) · O2SN Provinsi Jateng 2026 · Pencak silat Fajar Ramadhan XII-1 Juara I Provinsi (Pak Agus Setiawan, TANPA FOTO) · OSEBI 2026 Aurelia Maharani X-3 Finalis Nasional (Pak Hendra Kusuma, izin ada, 3 foto) · Terjadwal: OPSI 2026 Nadia Putri Anindya XI-4 Juara II Nasional "2 Okt · Instagram, Website" (izin ada) · FLS3N Provinsi 2026 · Baca puisi Salsabila Kirana XI-5 Juara III Provinsi "5 Okt · Instagram" (TANPA FOTO) · Dipublikasikan (6): KRENOVA Kota 2026 Nadia Juara I "Terbit 22 Sep", O2SN Kota 2026 · Atletik Rizky Maulana (XI-1) Juara II "Terbit 18 Sep", + "Lihat 4 lainnya".
- Consent flags: photo consent OFF for Fajar Ramadhan and Salsabila Kirana (use initials on public pages); ON for Raka, Aurelia, Nadia.
- Humas default schedule: 1 Okt 2026 09.00; apresiasi "Upacara Senin, 5 Oktober 2026" (alt 12 Oktober).

## Screen-local CSS appended (end of <style>)
TU: `.qcard .qtile .fld .in42 .compose .compose-err .affix .help .help-err .grp .grp-h .seg .seg-on .frow .paper .wm .kop .logo-ph .rule2 .kv .kv2 .ol .slot .slot-empty .lt .lt-row .lt-row5 .lt-head .ttd`, disabled `.btn[disabled]`.
Humas: `.lane .lane-h .ldot .cnt .pc .pc-sel .pc-cover .pc-body .pc-title .pc-name .pc-meta .pc-when .pc-foot .pc-foto .pub .chk`, disabled `.btn[disabled]`, `.input:disabled`.

## Cross-check with other notes
- NSIF penyelenggara kept unnamed ("[nama penyelenggara swasta]", C uses "Penyelenggara swasta · Kategori D"); NSIF undangan date 15 Sep = C's "Pendaftaran dibuka".
- Letter signature uses placeholders "[Nama Kepala Sekolah]" / "[Pangkat]" / "NIP [NIP]" per the brief (G's Pengaturan shows the fictional Drs. Bambang Hartono as the configured signer; the real PDF would fill from there).

## Deviations
- TU step "3. Buat PDF" sits at the bottom of the left column (anchored, grows upward when done) instead of a full-width bar: the 560×792 A4 preview needs the full column height inside 1100.
- Letter preview uses Times New Roman (document replica), 11–12.5px.
- Humas "Tanpa foto" cards show "Sertifikat" instead of "N foto" to avoid a contradictory "3 foto · Tanpa foto" pair.
