# M1-A — Letters / Tata Usaha (lead's manual test of 2026-10-04, items 6–10)  ·  status: done

Lead's wishes: the complete letter number is typed by TU; data entry per letter; both PDFs exactly like the school's own files
("ST PENGUKUHAN KEG OPSI.docx" = guru, "ST_LOMBA … TIM.xlsx" = siswa) and printable; no greyed-out buttons, jump to the error;
sidebar badge without refresh. These override older entries where they conflict (D-06 numbering, B3 "one draft for both letters").

## Files (owned by me)
- `database/migrations/2026_10_04_100000_m1a_letters_free_number.php` (new): `letters.sequence` nullable, unique (type, year, sequence)
  → unique **(type, full_number)**, new nullable columns `basis_from` (200), `basis_subject` (150), `schedule_date` (150). Stops with a
  readable error when existing rows already share (type, full_number). `down()` only drops the new index + columns.
- `app/Domain/Letters/LetterNumber.php`: `normalize()`, `numberError()`, `isValidNumber()`, `parseNumber()`, `key()`, `example()`.
- `app/Models/Letter.php` (fillable + `lastNumber()`, `numberTaken()`; `lastSequence()` kept for old rows / demo data),
  `app/Policies/LetterPolicy.php` (`create(User, Registration, ?LetterType)`, `send` needs every required letter, `requiredTypes()`).
- `app/Actions/Letters/`: `LetterDraft` (one letter), `LetterContent`, `IssueLetters` (one type per call), `RevokeLetter`, `SendLetters`
  (`missing()`), `LetterPdf` (messages), `StreamLetter` (new: download or inline). `app/Actions/Templates/LetterTemplate.php`
  (`kop_6`, `guru_basis_1…3` replace `guru_basis`). `app/Actions/Settings/UpdateLetterFormats.php` (doc comment only).
- `app/Http/Controllers/LetterPrintController.php` (new) + two routes in the `tu` group of `routes/staff.php` (+ its `use` line).
- `app/Livewire/TataUsaha/{SuratTugas,ArsipSurat,TemplateSurat}.php`, `app/Livewire/Admin/Pengaturan/Surat.php` and their views;
  `resources/views/livewire/tata-usaha/partials/{kop,ttd,nomor,pratinjau-siswa,pratinjau-guru}.blade.php` (rebuilt).
- `resources/pdf/{layout,surat-tugas-siswa,surat-perintah-tugas-guru}.blade.php`, `partials/{kop,ttd}.blade.php` (rebuilt),
  `resources/pdf/assets/{logo-pemprov.png,logo-sekolah.jpg,kurung-kurawal.svg}` (new). `laporan-prestasi` is untouched (own kop;
  it now prints three address lines because `kopDetailLines()` includes `kop_6`).
- `database/factories/LetterFactory.php` (new columns as null).
- Tests: `tests/Unit/Domain/Letters/LetterNumberTest.php`, `tests/Feature/TataUsaha/{SuratTugasTest,Template/TemplateSuratTest}.php`,
  `tests/Feature/Letters/{RevokeLetterTest,LetterLayoutTest (new)}.php`; outside my folders, changed only for the new signatures:
  `tests/Feature/Kepsek/RevocationNoticeTest.php`.

## Contracts other areas can use
- `new LetterDraft(string $number, CarbonImmutable $issuedOn, string $basisFrom, ?string $basisNumber, ?CarbonImmutable $basisDate,
  string $scheduleDay, string $scheduleDate, string $scheduleTime, string $place, string $basisSubject = 'Undangan')`;
  `LetterDraft::defaults(Registration, ?today): array` / `fromLetter(Letter, Registration): array` (form keys `nomor, tanggalSurat,
  dasarDari, dasarNomor, dasarTanggal, dasarHal, hari, tanggal, pukul, tempat`); `DEFAULT_TIME = '07.00 WIB s.d Selesai'`.
- `IssueLetters::handle(User $tu, Registration, LetterType $type, LetterDraft): Letter` (ONE letter; error keys `number, issued_on,
  basis_from, basis_number, basis_date, basis_subject, schedule_day, schedule_date, schedule_time, place, registration, signature`,
  all reported at once). `IssueLetters::isTaken(LetterType, string $number): bool`, `draftErrors(LetterDraft, LetterType): array`,
  `example(LetterType, CarbonImmutable, ?string $last): ?string`. Audit `letters.issued` now once per letter (`letters` = one item).
- `RevokeLetter::handle(User $tu, Letter $current, string $reason, LetterDraft $draftWithNewNumber): Letter` (keys `reason, letter` + the above).
- `SendLetters::handle(User, Registration): Registration` unchanged; refuses until `LetterPolicy::requiredTypes()` all have a PDF
  (siswa, + guru when `mentor_id` is set); `SendLetters::missing(Registration, Collection): list<LetterType>`.
- Number rule: trimmed, runs of spaces → one, 3–64 characters, only `A–Z a–z 0–9 . / -` and space, at least one digit; unique per
  letter type over ALL letters (revoked too), compared without case and spaces (`LetterNumber::key()`); `year`/`month` from the date.
- `StreamLetter::handle(User, Letter, bool $inline = false): StreamedResponse` (LetterPolicy::view, audit `letter.downloaded`, `inline: true`
  in the properties for print). `SuratTugas::streamLetter(User, Letter)` still exists (ArsipSurat, Kepsek\SuratSaya use it).
- Routes: `tu.surat.cetak` → GET /tu/surat/cetak/{letter} (inline PDF, `Cache-Control: no-store, private`, CSP `frame-ancestors 'none'`);
  `tu.surat.kop` → GET /tu/surat/kop/{pemprov|sekolah} (emblems for the HTML preview, `LetterPolicy::viewQueue`).
- Browser events from `SuratTugas`: `sth-form-invalid` with `form: 'surat-form-siswa' | 'surat-form-guru' | 'surat-kirim'`;
  `sth-badges` with `badges: ['tu.surat' => <Registration::awaitingLetters() count>]` after generate / revise / send.
- `LetterTemplate::guruBasisItems(string $schoolYear): list<string>` (empty texts skipped; the invitation is printed after them).

## Commands verified (2026-10-04, in-memory SQLite)
- `php artisan test tests/Unit/Domain/Letters tests/Feature/Letters tests/Feature/TataUsaha tests/Feature/Kepsek/RevocationNoticeTest.php
  tests/Feature/Security/CspSafeTest.php tests/Feature/Security/PdfUploadScanTest.php` → **170 passed (907 assertions)**, exit 0
- `php artisan test tests/Feature/Security/{CspSafeTest,PdfUploadScanTest}.php tests/Feature/DataLayer tests/Feature/Kepsek
  tests/Feature/Siswa/Hasil/LombaSayaTest.php tests/Feature/Guru/GuruAreaTest.php tests/Feature/Siswa/Lomba/FileDownloadTest.php
  tests/Feature/Admin tests/Feature/DesignSystem/NavigationTest.php` → 358 passed, 1 failed: `Admin\SettingsActionsTest` "uploads the
  signature from the Surat tab" (`UnableToRetrieveMetadata`, Livewire temp upload while other agents' runs were active); the file alone → 17 passed.
- `./vendor/bin/pint <my 25 PHP files>` → clean; `pint --test routes/staff.php` → pass.
- Visual check of the PDFs: both templates rendered with fictional data (team of 3, one student, guru letter + Lampiran), rasterized and
  compared with the docx/xlsx structure (kop, stacked "Kepada" with brace, column widths, signature blocks, QR bottom-left). Temp files deleted.
- Visual check of the TU screen: component HTML of four states (failed submit, guru tab, issued, correction) with a scratch CSS build
  (`vite build --outDir storage/framework/testing/…`, deleted afterwards; `public/build` untouched).

## Assumptions / deviations from the brief
- **Lampiran table has no borders**: in the Word file all four tables have `tblBorders = none` (the dump's "borders" flag was misleading).
- Per the Word file: title and "Nomor …" of the guru letter are not bold; "Untuk" point 1 has no full stop; signature lines are
  left-aligned at 8.75 cm, name not bold. Per the sheet: student signature block centred, name bold + underlined.
- The student sheet is 469.5 pt wide on a 516 pt printable width and not centred, so the PDF keeps the wider right margin.
- The sheet's kop is one picture (473 pt); it is rebuilt as text + emblems at 11/13/13/8.2 pt. `logo-pemprov.png` is a 400 px,
  white-background copy of the emblem (no alpha: dompdf embeds it without a per-pixel pass); `logo-sekolah.jpg` is the original file.
- Signature + stamp image: 58 pt high on the guru letter (the Word file leaves three blank lines, 45 pt), 61 pt on the student letter.
- "Hari" and "Tanggal" are two typed fields now (hence `schedule_date`); old rows keep "Hari, tanggal" in `schedule_day` and are split on read.
- "Nomor"/"Tanggal" of the invitation stay optional; the student letter then prints "-", the guru letter leaves the words out.
- Guru pembimbing "Jabatan" comes from `staff_profiles.position` (fallback "Guru"); it was fixed "Guru".
- Print response carries only `frame-ancestors 'none'` as CSP (the app CSP has `object-src 'none'`, the file controller `sandbox`).
- A template saved before M1-A keeps its `kop_4`/`kop_5`; new fields use the built-in texts. TU can press "Kembalikan bawaan".
- New/changed copy uses "Download", "Preview", "upload" (lead, 2026-10-04); untouched text was left alone.

## Requests for other owners
- @coordinator `tests/E2E/support/email-scenario.php` (steps 5a–5f): `$draft` must build the new `LetterDraft` (number first);
  `IssueLetters` is called once per `LetterType` and returns a `Letter` (not a Collection); `RevokeLetter` has no sequence argument.
- @coordinator `database/seeders/DemoSeeder.php` keeps working as is (writes `sequence` + `full_number`; SeedersTest green). Optional:
  fill `basis_from`, `schedule_day`, `schedule_date`, `schedule_time`, `place` on the demo letters.
- @coordinator `resources/js/app.js` + sidebar: the `sth-badges` hook; `npm run build` (new Tailwind classes in my views).
- @I: `resources/pdf/assets/**` must be in the production image; MySQL: run the migration, then `grants.sh` is not needed (no new table).
- Docs: ARCHITECTURE §4 (letters columns, unique (type, full_number), sequence nullable) + letters flow; CLAUDE.md §5 "Letters";
  UI-MAP #21 (tabs per letter, `tu.surat.cetak`, `tu.surat.kop`); PANDUAN-PENGGUNA (TU: nomor lengkap, satu surat satu form, Cetak,
  Koreksi; Admin: "Contoh nomor surat"); SECURITY §3g (typed number rule, inline print response, emblems in the repo), §3d;
  DECISIONS: new entries for the full typed number, per-letter issue, templates = school files (emblems in the repo), Cetak.

## Follow-ups (not done)
- Migration not run on MySQL 8.4 (not run); Cetak not tried in a real browser / on paper (not run); the screen was not opened logged in.
- Print and download are TU-only routes; Kepala Sekolah "Surat atas nama saya" still only downloads.
- A printer icon does not exist in `<x-sth.icon>` ("Cetak" uses `external`).
