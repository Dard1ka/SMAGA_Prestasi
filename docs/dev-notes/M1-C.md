# M1-C — public ranking filters + student import keeps classes up to date  ·  status: done

Lead request (2026-10-04, hand test of `/peringkat`): the filters must do what they say ("Alumni" = alumni with
achievements only), an alumnus shows "Alumni" where the class stands, and the yearly student file (all years, with
a class column) must move registered students to their current class.

## What was wrong with the filters (dev stack, browser, before the change)
No control was dead: Siswa/Kelas, bidang, angkatan and the checkbox all sent a Livewire request, changed the list
and survived a reload and a `wire:navigate` page swap. What the lead saw as "not working":
1. "Tampilkan alumni" **added** alumni to the active students (16 → 20 rows, mixed). There was no way to see
   alumni only.
2. With bidang = Riset the tick changed nothing (7 → 7 rows): no demo alumnus has a Riset result.
3. The angkatan list always offered the alumni year ("Angkatan 2022"), also while alumni were hidden or in Kelas
   mode: choosing it gave "Belum ada data untuk filter ini".
4. Switching to Kelas silently dropped the tick (`?alumni=true` left the URL); back on Siswa the alumni were gone.
5. An alumnus showed the last class ("XII-3") next to the chip; the student page said "Kelas terakhir XII-3".

## Files
- `app/Domain/Leaderboard/RankingScope.php` (new) — enum `aktif | alumni | semua`, `includes()`, `hasClasses()`,
  `fromValue()` (unknown → `aktif`), `options()`.
- `app/Livewire/Publik/Data/PublicAchievements.php` — `ranking(..., RankingScope)`, `angkatanOptions(scope, mode)`,
  alumni without a class, `kelas_label`, cache key part `SHAPE`.
- `app/Livewire/Publik/Peringkat.php` + `resources/views/livewire/publik/peringkat.blade.php` — three-way filter.
- `resources/views/livewire/publik/{beranda,pemenang-lomba}.blade.php`, `partials/{student-row,siswa-detail}.blade.php`
  — class slot of alumni.
- `app/Livewire/Siswa/Peringkat.php`, `app/Support/Leaderboard/PersonalRank.php` (comment) — active scope, link.
- `app/Actions/Accounts/{ImportStudents,StudentImportReport}.php`, `app/Jobs/ImportStudentsJob.php`,
  `app/Models/StudentImport.php`, `app/Livewire/Akun/ImportSiswa.php`,
  `resources/views/livewire/akun/import-siswa.blade.php`.
- `database/migrations/2026_10_04_120000_m1c_add_updated_count_to_student_imports.php` — `updated_count`
  (unsigned int, default 0). **Run `migrate` before the import screen is used** (the job and the screen read it).
- Tests: `tests/Feature/Publik/{PublikPeringkatTest,PublikPrivacyTest}.php`, `tests/Feature/Accounts/ImportStudentsTest.php`,
  `tests/Feature/Siswa/Peringkat/SiswaPeringkatTest.php`, `tests/Feature/Integration/RankConsistencyTest.php`,
  `tests/Unit/Domain/Leaderboard/RankingScopeTest.php` (new); one line in
  `tests/Feature/Kesiswaan/KesiswaanScreensTest.php` ("Siap diimpor" → "Siswa baru").

## Public ranking: behaviour and contracts
- URL `/peringkat?mode=siswa|kelas&bidang=&angkatan=&status=aktif|alumni|semua&limit=`. `status` replaces
  `alumni=1`, which is now ignored (default list). Default `aktif` (D-09: alumni hidden until asked).
- "Alumni" = archived accounts **with a verified achievement** only; ranks are computed within the shown group
  (Ranker over the filtered rows). "Semua" = both, mixed.
- Kelas mode ranks active students by current class. Alumni have no class: with `status=alumni` the Kelas button
  is disabled (`pilihMode('kelas')` refuses, `?mode=kelas&status=alumni` → siswa) and a note explains it. In Kelas
  mode the status control is hidden but its value stays in the URL, so "Semua" is back after returning to Siswa.
- `PublicAchievements::angkatanOptions(RankingScope $scope = Active, string $mode = 'siswa')` only lists years that
  have someone in the shown group; a selected year that drops out is reset to "Semua angkatan". The "(kelas XI)"
  suffix appears only when the year has active students.
- `PublicAchievements::ranking(string $mode, ?Bidang, ?int $angkatan, RankingScope $scope = Active)` (was
  `bool $alumni`). `ranking('kelas', scope: Alumni)` = `[]`.
- Person arrays: `kelas` is null for every alumnus (the last class never leaves the data class), new key
  `kelas_label` = class, or `Alumni` for a named alumnus, or null for an anonymous row (its label already says
  "Siswa kelas XI" / "Alumni"). Views: chip "Alumni" in the class slot/column; "Kelas terakhir …" removed.
- `PublicAchievements::SHAPE` (2) is part of every cache key: arrays cached by the previous release are not handed
  to the new views after a deploy. Raise it when a returned array gains or loses a key.
- D-37 unchanged: `PersonalRank` and `siswa.peringkat` stay active-only; the siswa screen links to
  `/peringkat?status=alumni` and offers only the years of active students.

## Import: behaviour and contracts
- Columns: Nama lengkap, NIS, NISN, Tanggal lahir, **Kelas**, No HP (optional). `ImportStudents::TEMPLATE_HEADER`;
  the old header "Kelas saat pendaftaran" is still read (`HEADERS`).
- Row actions (`StudentImportReport::NEW|MOVED|UNCHANGED|SKIPPED|REJECTED`, report key `action`):
  - NIS and NISN unknown → **new**, exactly as before (`InitialPassword`, hashed, never shown).
  - NIS and NISN of the same registered student → **moved** (other class) or **unchanged**. Written: `classroom_id`,
    `import_school_year`, `grade12_start_date`, `archive_at`, and `phone` when the file has one. Never: name, birth
    date, password, email, status, activation, consent flags.
  - only one number matches, or the two belong to different students → **rejected** with the reason.
  - name / birth date differ (case and spaces ignored, empty cells ignored) → row still applies, note
    "Nama/Tanggal lahir berbeda dari data lama, tidak diubah."; no age or format check for registered students.
  - archived account → **skipped** with a note; soft-deleted → rejected; disabled → updated, stays disabled (note).
  - unknown/inactive class, bad phone, duplicate numbers in the file → rejected (as before).
- Report rows gained `action`, `previous_classroom`, `notes`, `changes`; `StudentImportReport` gained `moved`,
  `summary()`, `changeCount()`, static `summarize()` / `countChanges()`. `importSlice()` also returns `moved`;
  `recordImport(..., int $moved = 0)`.
- **Archive dates.** `GraduationCalculator` gives kelas XII start = 1 July of (school year start + 12 − level),
  archive = + N months. An update recomputes both from the class in the file and the chosen school year and
  compares with the stored dates: X in 2026/2027 → XI in 2027/2028 gives the same 2028-07-01 / 2030-01-01, so
  nothing is written; a repeated year (X again in 2027/2028) or a wrong class gives other dates, which are written
  with a row note "Tanggal arsip dikoreksi: Jan 2030 → Jan 2031." `import_school_year` follows, so (class level,
  school year) always explains the stored dates. A corrected archive date that is not after today refuses the
  row. No helper was missing in `App\Domain\Archive`.
- Preview (dry run): tiles Siswa baru · Pindah kelas · Tidak berubah · Ditolak, filters Semua / Ditolak / Ada
  catatan, a warning with the number of archive dates that would change (many = wrong school year chosen),
  download of the rows to check. Button "Impor N siswa", N = rows that write something; the same N decides the
  queue (> 50 → `ImportStudentsJob`), so a re-upload with nothing to do never queues.
- Audit: `student.import` (log `import`) gained `moved`; per updated student `student.class_changed` (log
  `student`, subject = student, from/to class name, school year, dates, `phone_updated`, `file_sha256`) or
  `student.phone_updated`. No name, number, birth date or phone in them. `PublicAchievements::flush()` after an update.

## Commands verified (Docker, SQLite)
- One pass over the whole area, `php artisan test --filter="PublikPeringkatTest|PublikPrivacyTest|PublikPagesTest|PublikPrestasiBidangTest|PublikHeadersTest|SiswaPeringkat|RankConsistencyTest|RankerTest|RankingScopeTest|ImportStudentsTest|ImportQueueTest|CspSafeTest|imports students in three steps|serves the admin import route|gives imported students the activation window"`
  → 170 passed, 1 failed (1319 assertions). The failure was a test I rewrote while that run was going (it called a
  method I had just made private).
- After that fix, `--filter="ImportStudentsTest|ImportQueueTest|imports students in three steps"` → 41 passed
  (469 assertions). The other 14 test files of the first pass were not run again (no change since).
- Earlier: `--filter="…import…|PublikPrivacyTest|UploadLimitsTest|ModelFactoriesTest"` → 60 passed (308 assertions).
- `pint --test` on the 18 PHP files above → clean. Full suite: not run (other agents were running theirs).
- Browser (dev stack, guest): the three groups, bidang, angkatan, Kelas, reload, `wire:navigate` from Beranda and
  back; alumni page, bidang list and Beranda without a last class. Mobile screenshots only (small pane).

## Assumptions / deviations
- The yearly file wins over a manual archive date (`ChangeArchiveDate`, `UnarchiveStudent`) when class + school
  year give other dates. Reason: this is what repairs a wrong class after an Admin restored the account. Needs a
  DECISIONS entry; the alternative (keep manual dates) leaves a wrongly archived class X student on the Admin's date.
- New rows are not checked for an archive date in the past (unchanged behaviour); updates are.
- A phone number in the file replaces the stored one (the import is the only writer of `students.phone`); an
  empty cell keeps it.
- A registered student who is **not** in the file is left alone: last year's kelas XII keep their class until the
  archive date (about six months after graduation), so they still count in the class ranking of "XII-n" next to the
  new kelas XII. Not asked for; a question for the lead (list or flag "siswa aktif yang tidak ada di file"?).
- A birth date that differs is only reported. If the stored one is wrong the initial password is too: the fix is
  Admin › reset akun, not the import (rule 3).
- `?alumni=1` links are not redirected (page not live yet).

## Not verified
- The import screen in a browser (needs a staff login); covered by Livewire tests only.
- Hitting the real 60/min page limit and 120/min Livewire limit in a browser (shared IP with the other agents).
  Covered by a test: a filter call through the update endpoint, 429, then the same call after the window.
- MySQL run of the migration; desktop-width screenshots.

## Requests for other owners
- After `migrate`: restart the dev `queue` container so the worker loads the new `ImportStudentsJob` / `ImportStudents`.
- `Admin\AuditLog::EVENT_LABELS`: add `student.class_changed` => "Kelas siswa diperbarui", `student.phone_updated`
  => "No HP siswa diperbarui" (they show as codes until then). Decide whether `student.class_changed` belongs in
  `SecurityEventSubscriber::AUDITED_EVENT_PREFIXES` (about 750 lines per yearly import) and in the history of
  `Admin\ArsipOtomatis::EVENTS` (it can move an archive date).
- `tests/E2E/support/email-scenario.php:613`: header may become "Kelas" (the old one still works).
- Console on every public page (also before this work): "Applying inline style violates … style-src" (CSP).
- Docs: DECISIONS (new entry replacing the toggle part of D-09/D-37 and the "NIS already registered = refused"
  rule of D-12/D-26; manual archive date vs import); ARCHITECTURE §4 `student_imports.updated_count`, import
  contract, audit events; UI-MAP #3 (`status=`), #23 and `kesiswaan.import`; PANDUAN-PENGGUNA §4 Import siswa
  (yearly upload, summary, notes) and the public ranking; SECURITY §3f (updates never touch accounts) and §3m-7
  (alumni without class); CLAUDE.md §5 "Ranking" and "Accounts".
