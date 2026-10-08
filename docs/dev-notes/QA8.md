# QA8 — explore round after PR 17 (2026-10-08)  ·  status: done, not committed (on `main`)

Exploration of `main` at `3ad2d94` (PR 16 = UI3 + QA7 and PR 17 = the slimmer CI, both merged), at the lead's request
"Skrng explore dan fix lagi". QA7 covered layout; this round looked at what had not been touched: dates and deadlines,
the report → points flow, Humas / appreciation, the student import, the letter PDFs with long words, and the dialogs at
phone width. Two read-only code reviews by agents (reports and points; Humas, import, activation), a PDF probe and a
page probe on the local demo data. Every fix test-first (red seen, then green). Fictional data only; no migration; no
new decision (each fix follows a rule that already exists: D-25, D-109, SECURITY §3d, the settings of §5).

## How it was checked
- **Letters**: a scratch script (not in the repo, deleted afterwards) rendered both letters of one demo registration
  three times inside a rolled-back transaction: as stored, with a realistic long lomba name / penyelenggara / place /
  subject, and with 55-letter words. The text layout of the PDFs was read back (positions of every text run).
- **Screens and dialogs**: the QA7 method (render 25 signed-in screens of all seven roles from the local demo data with
  long words inside a rolled-back transaction, headless Chromium), this time opening every `<dialog>` at 360 and 390 px.
- **Code**: two agents read the report / verification / points Actions and the Humas, import and activation Actions
  and screens; every finding they rated ≥ 70 was checked by hand before a test was written.

## Files
- `resources/pdf/surat-perintah-tugas-guru.blade.php`, `resources/pdf/surat-tugas-siswa.blade.php` — the end of the
  letter stays with the signature (`.utuh` of `pdf/layout.blade.php`)
- `app/Domain/Files/UploadRules.php`, `app/Domain/Files/StoreUpload.php` — a stored file is named after its content
- `app/Actions/Publications/{PublishPublication,RecordAppreciation,DeleteAppreciationEvent}.php` — public
  `notifiesStudent()` / `studentsToTell()`: the Action that sends the notice also answers who gets it
- `app/Livewire/Humas/{Publikasi,Apresiasi}.php` — the toasts ask the Actions (D-109)
- `app/Livewire/Kesiswaan/VerifikasiHasil.php` — a report decided meanwhile; the queue card counts the team
- `app/Actions/Reports/SubmitReport.php` — "terlambat" belongs to the first report
- `app/Livewire/Siswa/LombaSaya/Concerns/ReportHelpers.php`, `resources/views/livewire/siswa/lomba-saya/lapor-hasil.blade.php`
  — the deadline days from the setting
- `app/Livewire/Akun/ImportSiswa.php`, `resources/views/livewire/akun/import-siswa.blade.php`,
  `app/Livewire/Admin/ArsipOtomatis.php` — the archive months from the setting
- tests: `Letters/LetterLayoutTest` (+1), `Unit/Domain/Files/UploadRulesTest` (+12 incl. datasets),
  `Security/UploadLimitsTest` (+1), `Humas/PublikasiScreenTest` (+1), `Humas/HumasScreensTest` (+1),
  `Kesiswaan/KesiswaanScreensTest` (+4 incl. dataset), `Siswa/Hasil/LaporHasilTest` (+1),
  `Siswa/Hasil/SubmitReportTest` (+2), `Accounts/ImportStudentsTest` (+1), `Admin/Arsip/ArsipOtomatisTest` (+1);
  all named "… (QA8)", so `--filter=QA8` runs them

## What changed (finding → root cause → fix)
1. **Surat Perintah Tugas (guru): the signature could stand alone on page 2.** With a realistic long lomba name,
   penyelenggara and place the letter grew to three pages and page 2 began with nothing but the signature block (city
   and date, title, signature, name, rank, NIP: 12 text runs), the text it signs on page 1. The signature block was
   already unbreakable (`.utuh`), so dompdf moved it alone. The "Untuk" block and the signature now sit in one
   `.utuh` box: when they do not fit, both start the next page (checked: page 2 now begins with "Untuk : 1. …", 30 text
   runs). The student letter got the same box around its closing sentence and signature (a large team pushes it to a
   page end the same way). Long words themselves stay inside the margins (furthest text of the student letter at 546
   of 595 pt).
2. **A PNG named "brosur.pdf" was accepted and then downloaded as a PDF nobody can open** (Q1-20, backlog #62). Both
   checks passed on their own: `.pdf` is an allowed extension, PNG an allowed content. The content already decided how
   the file is stored, served and previewed (`mime` is sniffed); only the stored display name kept the wrong ending,
   and the download takes its name from it. `UploadRules::nameForContent()` gives the stored name the extension of the
   content ("brosur.png"; ".jpeg" / ".JPG" stay as typed for a JPEG), and `StoreUpload` stores that. A first version
   refused such a file with "ganti akhiran namanya", but Windows hides extensions by default, so a school laptop user
   would hardly know how; accepting the allowed content and naming it right costs the user nothing. Spreadsheets
   (`storeSpreadsheet()`) are unchanged: they are not in the extension map.
3. **Humas: "Siswa sudah diberi tahu" for an alumnus or a disabled account** ("Tandai dipublikasikan", "Masukkan ke
   apresiasi", Apresiasi › Tambah prestasi, "Hapus acara"). `PublishPublication`, `RecordAppreciation` and
   `DeleteAppreciationEvent` tell only a student who can log in, the toasts claimed it always: the kind of toast D-109
   forbids. The Actions now answer it (`notifiesStudent()`, `studentsToTell()`, the same rule their `notify()` uses)
   and the toasts say "Siswa sudah diberi tahu" / "siswanya diberi tahu" only when someone was told.
4. **Kesiswaan › Verifikasi hasil: a second tab (or a second Kesiswaan account) got a full-screen 403** when it decided
   a report the first had already decided (`ReportPolicy::verify` refuses anything not `dikirim`). The screen now checks
   first, says "Laporan ini sudah diputuskan. Antrean sudah diperbarui." (warning toast) and moves to the next report in
   the queue, or clears the panel when the queue is empty. Nothing is written twice (one achievement).
5. **Verifikasi hasil, queue card: "+2" for a team of two** when a member had declined. The card counted every
   `registration_members` row; the detail and `RecordAchievements` (points) count only members who did not decline. The
   card now counts the same way.
6. **Siswa › Laporkan hasil: "(45 hari setelah final)" was fixed text.** With another `report_deadline_days` the date
   was right and the number wrong. The days come from `ReportDeadline::days()` now.
7. **A report sent on time became "terlambat" when the student answered a revision request after the deadline.**
   `SubmitReport` computed `late` from today on every send, so Kesiswaan asking for a revision late made an on-time
   report late. "Terlambat" belongs to the first report (D-25): the answer to a revision request keeps the flag the
   report had (a late report stays late); a new report or one sent again after it was withdrawn is computed as before.
8. **Import siswa and Admin › Arsip otomatis said "18 bulan"** whatever Admin › Pengaturan says
   (`archive_months_after_grade12`). Both take the months from `GraduationCalculator::archiveMonths()` now (the Arsip
   otomatis subtitle moved from the `#[Layout]` attribute to `layoutData()`, because an attribute cannot hold it).

## Checked and found sound
- Dates: the registration deadline typed as a date is stored as the end of that day (23:59:59), so registration does
  not close a day early.
- Dialogs: every `<dialog>` on the 25 rendered screens fits 360 and 390 px (one drawer reported 7 px wider: its own
  scrollbar, not content).
- Letters with 55-letter words: the student letter keeps its text inside the margins and stays one page; the guru
  letter stays two pages (letter + Lampiran), like the letter as stored. Its first page was too large to read back
  whole, so its margins were not measured.
- The student side of finding 4: only the ketua may report (`ReportPolicy::create`), so a teammate cannot decide a
  report under another student's open tab; the same ketua in two tabs still gets the 403 on the second "Laporkan
  hasil" (left as is; `SubmitReport` itself answers with a normal form error).

## Commands verified
- RED: every new test failed for the missing behaviour before the fix (missing toast / text, "+2", `late` true, the
  refusal message or the missing `nameForContent()`); "a late report stays late" passed before and after as a guard.
- GREEN: `--filter=QA8` on the touched files → 11 passed (42 assertions); every touched test area (Accounts import,
  Admin › Arsip, Humas, Kesiswaan, Siswa › Hasil, Letters, Unit Files) → **705 passed (3734 assertions)**, exit 0
  (1458 s, one process); after the change from "refuse" to "rename" (finding 2): `UploadRulesTest` + `UploadLimitsTest`
  → 51 passed (88 assertions); an earlier run of every upload area with the first version (Files, Security, Usulan,
  laporan hasil, Demo) → 746 passed, 1 skipped; `pint --dirty` → PASS (22 files), `pint --test` → PASS (665 files).

## TEST-RESULTS
- Before any change: `main` at `3ad2d94` = the code of QA7, whose full run was 2341 passed, 1 skipped (14038 assertions).
- After QA8: `php artisan test --parallel --processes=4` → **2366 passed, 1 skipped (14098 assertions)**, exit 0
  (1194 s; +25 = the QA8 tests); `pint --test` → PASS (665 files). No front-end change, so no `npm run build`.
- Not run: the email scenario and the Playwright smoke (backlog #46); a click-through in a signed-in browser (the
  browser pane had no session and a demo login is not possible from this session); a printed letter.

## Requests for the lead
Lower-confidence points from the two reviews, read in the code but not reproduced and not changed (each needs a
decision or a closer look):
- Admin › Pengaturan › Batas arsip: lowering the months recalculates every archive date (`UpdateLimits`); students whose
  new date already passed are archived that night, many at once, after one password confirmation. A count in the
  confirmation ("N akun akan diarsipkan malam ini") would make that visible.
- Consent: a stale Admin › Pengguna form can write an old consent value over a newer one (no version check); a
  publication still `terjadwal` from before a withdrawn publish consent comes back to life when the consent is given
  again.
- Accounts: `email_notices` ("Bukan saya?") are not voided by an Admin reset; binding an email that belongs to a
  deleted account answers a 500 instead of "Email ini sudah dipakai".
- Reports: "Tolak" in Verifikasi hasil has no confirmation (the report ends without points); the evidence files of the
  first report stay stored after a revision replaced them; a disabled ketua leaves the team without anyone who may
  report; `perlu_revisi` reports are counted as open on the Kesiswaan dashboard; the student tracker shows no own state
  for `ditolak` / `dibatalkan`.
- From QA7, still open: Portofolio shows the drawing, not the poster, for a lomba Kesiswaan closed (backlog #76).
