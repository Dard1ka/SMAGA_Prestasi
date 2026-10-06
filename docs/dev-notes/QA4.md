# QA4 — fixes after the retest of PR 9 / PR 11 (2026-10-06)  ·  status: done (branch `fix/ui-uji-ulang-qa4`)

Retest in Chrome on `main` @ `f082e7a`; five findings fixed test-first (red → green). Fictional data only.

## Files
- `database/migrations/2026_10_06_120000_qa_add_printed_to_letters.php` — `letters.printed` json nullable
- `app/Models/Letter.php` — cast `printed` array; kept out of the activity log (names, NIS, NIP)
- `app/Actions/Letters/{LetterContent,LetterPdf,IssueLetters,RevokeLetter}.php`, `app/Actions/Templates/LetterTemplate.php`
- `app/Livewire/TataUsaha/SuratTugas.php`, `resources/views/livewire/tata-usaha/surat-tugas.blade.php`
- `resources/js/app.js`, `resources/views/components/sth/toast.blade.php` — toast `key` + `sth-toast-clear`
- `app/Livewire/Kesiswaan/Talenta.php` — export marked like Database prestasi
- `app/Livewire/Akun/ImportSiswa.php`, `app/Actions/Accounts/ImportStudents.php` — template text columns
- `app/Livewire/Admin/Concerns/RequiresRecentPassword.php`, `resources/views/livewire/partials/resume-download.blade.php`,
  the views of Talenta, Database prestasi, Log audit, Status aktivasi, Laporan; `Laporan.php`, `AuditLog.php`,
  `StatusAktivasi.php`, `DatabasePrestasi.php` — download resumes after the password page
- `app/Providers/FortifyServiceProvider.php`, `resources/views/auth/confirm-password.blade.php` — "Batal"
- `docs/ARCHITECTURE.md` §4 letters — `printed`

## What changed (root cause → fix)
1. **TU preview ≠ PDF.** The preview of an issued letter ("Isi PDF yang sudah dibuat") was drawn again from today's
   template, signer, classes and guru data. `IssueLetters`/`RevokeLetter` now read the template once, render the
   PDF with it and store `printed = LetterPdf::printed($content, $template)`; `SuratTugas` draws an issued letter
   from `LetterContent::fromArray()` + `LetterTemplate::fromArray()`. Letters without `printed` (made before) say
   "Menurut data sekarang, bisa beda dari PDF. Lihat isi persisnya lewat Cetak." `LetterPdf::render()` takes the
   `LetterTemplate` as a parameter (no more `LetterTemplate::current()` inside).
2. **Red toast stayed after the problem was gone.** `$failed` was reset on success / tab change, the error toast
   (JS, never times out) was not. Toasts take an optional `key`; `sth-toast-clear {key}` removes them.
   `SuratTugas::clearFailure()` does both, wherever `$failed` used to be reset.
3. **Talenta Excel plain.** Now: bold title, "Diekspor oleh {nama} · {waktu} WIB" (SECURITY §3m watermark,
   missing here), "Filter: …", bold header, explicit string cells via `ExportAchievements::cell()` (adds `\n`).
   Dates stay text and columns default width, as in every other export.
4. **Import template (backlog #66, Q1-21).** NIS, NISN **and No HP** were General (Excel drops leading zeros;
   No HP then fails `^(\+62|0)8…`). Rows 2…3001 carry an empty Text ("@") cell in those columns
   (`ImportStudents::TEMPLATE_TEXT_COLUMNS`); the import skips them as blank rows.
5. **Download after the password page (backlog #63, Q2-18).** `passwordRecentlyConfirmed('export')` keeps
   `{component, call, until}` in the session; `mountRequiresRecentPassword()` gives it back once to that screen
   when the password is fresh, and the view runs it with `wire:init`. Downloads only (5 calls); data-changing
   actions still need a click. The password page has "Batal" → `url.intended` when it is our own URL, else `home`.

## Commands verified
- New/changed tests: `SuratTugasTest` (+3), `KesiswaanScreensTest` (+1, 1 adapted to the new sheet layout),
  `ImportStudentsTest` (+1), `Auth/PasswordConfirmResumeTest` (new, 11) — each seen failing first, then passing.
- `pint --test` → 630 files pass. `npm run build` OK. Local MySQL: `php artisan migrate` (the new migration).
- Full suite `--parallel --processes=4` → **2027 passed (10926 assertions)**, exit 0 (1417 s).
- Chrome: auto-download after "Lanjut" on the password page; "Batal" back to Talenta; toast gone after switching
  the letter tab; legacy letter label; Talenta xlsx (title/watermark/bold) and template (B/C/F = "@") checked.

## Requests for the lead
- `letters.printed` is a schema change and stores names/NIS/NIP next to the PDF: please record it in DECISIONS.
- Not done here (still open): the alumni rows offered in Apresiasi › Tambah prestasi (question for the lead);
  the "rows to check" file of the import still lacks NISN and birth date (rest of #66).
