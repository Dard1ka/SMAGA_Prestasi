# ST1: surat tugas siswa mengikuti surat cetak sekolah (D-154) · status: built, committed (PR 30)

The lead's request of 2026-10-16, with two pictures: the TU preview of the student letter and a photo of the school's
printed student letter ("ubah template surat siswa yang ada di gambar pertama untuk di web nya menjadi menggunakan
template gambar kedua"). This is FS1 item 14 (perbaikan template surat) for the part the photo shows: kop, title, Nomor,
Dasar and the intro sentence. Built on the demo copy checkout (`feat/fs1-feedback-sekolah` at `c1bad7d`).

## What differed
- **Dasar.** The school prints one regulation text ("Peraturan Menteri Pendidikan Dasar dan Menengah Republik Indonesia
  Nomor 8 Tahun 2026 Hal : Penggunaan Dana BOS Kinerja bagi sekolah yang memiliki prestasi"), wrapping under itself; STH
  printed "Surat dari <penyelenggara>" with the invitation's Nomor and Tanggal rows.
- **The web preview** used side margins of 37 px on a 560 px sheet; the PDF has 61.14 pt = 57.5 px at the sheet's scale
  (0.94 px per pt). So the kop looked stretched, with small emblems and small address lines. The PDF's own kop already
  matched the photo within a few per cent (font widths measured with dompdf's Helvetica metrics: address line 344.6 pt
  at 8.2 pt = 0.729 of the kop; the photo 0.718).
- **Measures from the photo** (ratios, a perspective photo): emblems about 7 % taller than the text block and starting
  above it; the title about half a row under the rule (STH: more than a row); Dasar about a quarter row under the Nomor
  (STH: one empty row); two empty rows between Dasar and "Kepala … memberikan tugas kepada :" (STH: one); the Dasar colon
  at about 0.226 of the kop width (STH: 0.211).

## Files
- `app/Actions/Templates/LetterTemplate.php`: field `siswa_dasar` (replaces `siswa_basis`; 600, required, `{sekolah}`,
  `{tahun_ajaran}`), `siswaDasar($schoolYear)`, `oldSiswaBasis($from)` + property `$oldSiswaBasis` (only `fromArray()`
  sets it, for letters printed with `siswa_basis`), `label($field)` for the version history; `{penyelenggara}` is gone
  from `PLACEHOLDERS`.
- `app/Actions/Letters/LetterDraft.php`: `usesInvitation(LetterType)` (guru only).
- `app/Actions/Letters/IssueLetters.php`: `draftErrors()` checks `basis_from` / `basis_number` / `basis_date` for the guru
  letter only; `newLetter()` keeps no invitation on a student letter row.
- `app/Livewire/TataUsaha/SuratTugas.php`: `SHARED_FIELDS` = hari, tanggal, pukul, tempat; `draft()` leaves the
  invitation out of a student draft (the hidden keys of the student form never reach the Action).
- `app/Livewire/TataUsaha/TemplateSurat.php`: group "Surat tugas siswa" lists `siswa_dasar`; history labels via `label()`.
- `resources/pdf/surat-tugas-siswa.blade.php`, `resources/pdf/layout.blade.php`: one Dasar row; `.kop-siswa` emblems
  75 pt (columns 70 / 56.5 pt, padding-top 3 pt), `.s-jarak-kop` 9.5 pt, `.s-jarak-dasar` 4.5 pt, `.s-dasar .k` 103 pt,
  two `.s-baris` before the intro.
- `resources/views/livewire/tata-usaha/partials/kop.blade.php` (`$kop = 'siswa'`: the PDF's kop at the sheet's scale from
  sm up; the guru preview keeps its sizes), `partials/pratinjau-siswa.blade.php` (margins 57.5 / 53 px, one Dasar row,
  the three old rows for a letter printed before D-154), `surat-tugas.blade.php` (student tab: the Dasar of the template
  + "Ubah di Template surat" instead of the invitation fields; the summary of an issued letter likewise).
- `resources/css/app.css`: `.kertas-siswa` (a container) / `.lembar-siswa` (the sheet): from sm up the sheet is drawn at
  560 px and shrunk as a whole with `zoom` in 2 % steps when its box is narrower (at 1366 px the box is 518 px: zoom
  0.92), so the kop and the Dasar break exactly as on the PDF; without `zoom` support the sheet stays fluid. First
  tried without it: at 518 px the true-scale kop wrapped "SEMARANG" and the address line.
- `database/seeders/DemoSeeder.php`, `database/seeders/Support/Demo/DemoFileRefresher.php`: invitation number and date
  only on demo guru letters (`--lengkapi` fills guru letters only).
- Tests: `TemplateSuratTest` (old saved template, old printed template, label, the three new validation cases),
  `LetterLayoutTest` (one Dasar row, the spacing, the kop CSS, the longest kop line fits between the emblems),
  `SuratTugasTest` (student tab without invitation fields, a student letter with tampered hidden keys, the old printed
  letter's preview; carry tests on the event fields), `RefreshDemoFilesTest` (guru letters only).
- Docs: DECISIONS D-154 (§D17, rows D-152…D-154 in the summary, pointers on D-84 and D-116), CLAUDE.md, FS1.md item 14.

## Interpretations (coordinator choices, *sementara* in D-154)
- The Dasar is a template field TU replaces every budget year (like the guru letter's regulation texts), not typed per
  letter.
- The invitation is no longer asked on the student tab and no longer shared between the two forms.
- Spacing and emblem size follow the photo's proportions; nothing was compared on paper.
- The lower part of the letter (table, Pelaksanaan, signature) is not in the photo and is unchanged; the NIS column stays.

## TEST-RESULTS (2026-10-16, on this machine)
- `pint` on the 11 changed PHP files: clean.
- `php artisan test tests/Feature/TataUsaha tests/Feature/Letters tests/Feature/Demo/RefreshDemoFilesTest.php` →
  **129 passed, 1 skipped (1165 assertions)**, 554 s.
- Full suite `--parallel --processes=8` → **3760 passed, 1 skipped (22561 assertions)**, 1051 s. It started before the
  preview wrapper (`.kertas-siswa`) and the CSS assertions were added; those came after and their three D-154 tests of
  `SuratTugasTest` were run alone: 3 passed (34 assertions).
- `npm run build` OK; `dev-cache.sh` (reload) run on the demo stack; `pint --test` clean (825 files);
  `sth:demo:refresh-files --berkas="surat-tugas-siswa-*" --force` redrew the 5 demo student letters on the demo copy.
- Browser (the app's pane, demo TU account `tu`, nothing issued or saved): the NSIF registration's student tab at
  1366 px (box 518 px, zoom 0.92, every kop line on one line, Dasar in 3 lines) and at 1024 px (box 631 px, zoom 1):
  the Dasar of the template with "Ubah di Template surat", no invitation fields; the guru tab still asks the
  invitation; the FLS3N student letter issued before D-154 still previews "Surat dari Puspresnas / Nomor : - /
  Tanggal : -" and its summary "Surat dari Puspresnas". The sample letter (TU › Template surat's fictional NSIF data)
  rendered to a PDF and opened in the pane: one page, kop lines unbroken, Dasar in 3 lines like the preview.
- Line break that differs from the photo: the school's Dasar breaks "… bagi sekolah yang / memiliki prestasi", STH
  "… bagi sekolah / yang memiliki prestasi" (the photo gives no absolute scale; compare on paper).
- Not checked: a phone width, a printed letter, TU › Template surat in the browser (render-tested only).
