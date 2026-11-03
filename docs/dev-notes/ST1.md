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

## D-157: the invitation back on the student letter, optional, as Dasar 2 · status: built, not committed
The lead after D-154: "Tetap bisa input seperti gambar 1 tpi dibuat opsional, jika user TU input sesuaii di gambar satu
maka tambahkan dasar baru dibawah gambar ketiga yang berasal dari surat undangannya siapa yang diinput oleh TU." Asked
before any change, the lead answered: numbered "1." and "2."; "Surat dari" stays prefilled with the penyelenggara; the
sentence has no "Hal"; "Jangan di apa apakan surat gurunya dibuat default saja" (guru letter unchanged, so the invitation
is not shared between the two forms either); emptied, the letter keeps the one unnumbered Dasar of D-154.

- `app/Actions/Letters/LetterContent.php`: `siswaInvitation()` = "Surat dari X[ Nomor Y][ Tanggal Z]", null when Surat dari
  is empty.
- `app/Actions/Letters/LetterDraft.php`: `requiresInvitation(LetterType)` (guru only) replaces `usesInvitation()`;
  `fromLetter()` keeps Surat dari empty for a letter whose `printed` record exists and has none (a correction of a
  student letter printed without the invitation no longer gets the penyelenggara back; letters without a printed record,
  the demo ones included, still fall back to the penyelenggara).
- `app/Actions/Letters/IssueLetters.php`: `newLetter()` stores the invitation for both types (`basis_from` null when
  empty, never `basis_subject` on a student letter); `draftErrors()`: required for the guru letter as before; on the
  student letter only the length checks, the date check and `MSG_INVITATION_FROM` ("Isi asal surat undangan, atau
  kosongkan nomor dan tanggalnya.") for a number or date without Surat dari (coordinator choice).
- `app/Livewire/TataUsaha/SuratTugas.php`: `draft()` passes the invitation of every form again (the unreadable-date error
  too); `SHARED_FIELDS` stays hari, tanggal, pukul, tempat.
- `resources/pdf/surat-tugas-siswa.blade.php` + `layout.blade.php` (`.s-daftar`, number column 16 pt): with an invitation
  the Dasar cell holds a two-row table "1. regulation" / "2. invitation"; without, the one row of D-154.
- `resources/views/livewire/tata-usaha/partials/pratinjau-siswa.blade.php`: the same numbered list in the preview (15 px
  number column, the sheet's scale); `surat-tugas.blade.php`: student tab "Dasar 1 · Dari Template surat" + fieldset
  "Dasar 2 · Surat undangan lomba" (Surat dari, Nomor, Tanggal; all optional; no Hal); the summary of an issued student
  letter is an ordered list when it printed an invitation.
- `database/seeders/DemoSeeder.php`, `DemoFileRefresher.php`: invitation number and date on both demo letters again
  (`--lengkapi` fills both); the demo student letters print it as Dasar 2 (their Surat dari is the penyelenggara through
  `fromLetter()`).
- Tests: `SuratTugasTest` (the student tab with Dasar 1 + 2 and the live numbered preview, emptied back to one Dasar, the
  guru tab still with Hal; issuing with and without the invitation, the refusal, the correction start; the validation
  case of a student draft), `LetterLayoutTest` (numbered rows in the PDF, the one-row case with an empty invitation, the
  `.s-daftar` CSS), `RefreshDemoFilesTest` (both demo letters carry the invitation).
- Docs: DECISIONS D-157 (§D19, summary row, dated pointer on D-154), CLAUDE.md §2, §5 letters, §8, §10.

Interpretations (*sementara* in D-157): the refusal sentence for a number or date without Surat dari; the number column
width (16 pt / 15 px), not compared on paper; TU › Template surat's sample letter keeps its invitation, so its student
preview shows Dasar 1 and 2.

TEST-RESULTS (2026-10-19, on this machine; the laptop had restarted, the lead started Docker, then at the lead's choice
`queue` + `scheduler` were stopped before `mysql`, `app`, `web` came up, so no mail could go out):
- `tests/Feature/TataUsaha` 108 passed (698 assertions); `tests/Feature/Letters` 26 passed (267);
  `Kepsek/RevocationNoticeTest` 5 passed; `Demo/RefreshDemoFilesTest` 9 passed, 1 skipped (the slow full render).
- Full suite `--parallel --processes=8` → **3783 passed, 1 skipped (22751 assertions)**, 946 s; `pint --test` clean
  (830 files); `npm run build` OK; `dev-cache.sh --force` run; `sth:demo:refresh-files --berkas=surat-tugas-siswa-*
  --force` redrew the 5 demo student letters.
- Browser (the app's pane at its own width, about 800 px, demo TU account, nothing issued): the NSIF student tab shows
  Dasar 1 from the template and Dasar 2 with Surat dari "Penyelenggara swasta", Nomor and Tanggal optional, no Hal; a
  typed invitation number appears at once in the preview as "2. Surat dari Penyelenggara swasta Nomor …" under "1.
  Peraturan …" (the typed number was not saved). The redrawn demo WICE student letter (PDF opened from disk) prints
  "1. Peraturan …" and "2. Surat dari IYSA Nomor IYSA/WICE/INV/118/VIII/2026 Tanggal 28 Agustus 2026".
- Not checked: a phone width, the guru tab in the browser (tests only), a printed letter, a real issue and "Koreksi" in
  the browser (tests only).
