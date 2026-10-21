# UX3: kelas dengan huruf (D-165) dan salinan smaga_server · status: committed 2026-10-21

The lead's requests of 2026-10-21 on the Smaga4 checkout of `feat/fs1-feedback-sekolah` (on top of the uncommitted UX1 and
UX2). Decision: DECISIONS §D22.

## 1. The copy `smaga_server` (no decision)
A sibling folder `E:\Project LLM\smaga_server` for the school's trial and a later deploy: the working tree of Smaga4
(tracked + untracked files, no `.git`, no ignored local demo files such as `salinan-demo/`), plus `vendor`, `public/build`
and the developer `.env` copied without reading it. In the copy only: its own compose ports (8090 / 3317 / 8035 / 5183,
so it runs next to Smaga4), `start.bat` with those addresses, `.env` with a new `APP_KEY`, `APP_URL=http://localhost:8090`,
`SESSION_COOKIE=smaga_server_session` and `STH_MAIL_ALLOWLIST=*` (the lead: every real address gets mail, `@sth.test`
stays in Mailpit; D-130 behaviour, local mode only). Empty database plus trial data from
`database/seeders/DataUjiSekolahSeeder.php` (copy only, because it holds the lead's real test mailbox): 7 staff accounts
(one per role, two guru), 9 students in X-A (3), XI-A (1), XI-B (2), XII-A/B/C (1 each), per grade 2 activated and 1 not;
the list for the school is `AKUN-UJI.md` in the copy. The three students not activated get the old D-24 pattern
`sm4g4_ddmmyy` on the lead's request (a real import still gives the configured initial password, D-138). Checked: a
read-only script (every login identifier, password hash, activation step, mail route) and the login page in the browser
as Admin, an activated and a pending student.

## 2. Class names with a letter (D-165)
- `ClassroomSeeder`: X-A … X-J, X-TAHFIDZ, X-OLIMP, XI-A … XI-L, XII-A … XII-L (`letters()`); still 36 rows.
- Migration `2026_10_21_100000_rename_classrooms_to_letters` (data only): renames X-1 → X-A … XII-12 → XII-L in place,
  so `classroom_id` of every student stays; a row whose lettered name already exists is left alone; `down()` goes back;
  flushes the public cache when it renamed something. Run on the lead's local MySQL and on the copy.
- `ClassroomFactory`: random four-letter suffix instead of a number.
- Order is unchanged: `Classroom::ordered()` (level, name length, name) and the natural sorts in `AppreciationList` /
  `ExportPrestasiData` keep the old order because 1 = A … 12 = L follows the numbers. `levelFromName()` and
  `LetterContent::classLabel()` ("XI - D") needed no change.
- Mechanical part (one script, every tracked and untracked file, 707 replacements): demo data (`DemoSeeder`,
  `DemoPeople`, `DemoSamples`; Nadia is XI-D), the Template surat sample, comments, the dev showcase views, the email
  scenario, about 125 test files, `ARCHITECTURE`, `DESIGN-SPEC-v2` §5, `dokumentasi.md`. By hand: two tests that built
  names (`sprintf('XII-%02d')`, `'XI-'.($i + 1)`), the ordering comments, `TALENT-FORM` (dated note: the school's own form
  uses numbers), `PANDUAN-PENGGUNA` (the Kelas column of the import file), `CLAUDE.md`.
- Left as they were on purpose: issued letters (`letters.printed` keeps "XI - 4"), old audit rows, the mockups and older
  dev-notes (history), DECISIONS entries before D-165.
- New test: `tests/Feature/DataLayer/KelasHurufTest.php` (seeded names, rename in place with a student, a second run plus
  the seeder without duplicates, a taken name left alone, `down()`).
- The same files were copied into `smaga_server` (140 files; its `compose.yaml` and `start.bat` kept), its trial seeder and
  `AKUN-UJI.md` now name X-A, XI-A, XI-B, XII-A/B/C directly; a fresh seed of it was tried on a throwaway SQLite file.

## For the lead
- A school import file with "X-1" now answers "Kelas tidak ditemukan." per row; the school must write X-A and so on.
- The copy runs in local mode (no staff 2FA, `/_dev` on, the allowlist mailer). In production the allowlist mailer is
  refused at boot and mail to `@sth.test` would bounce: staff need real addresses there, or a production rule for fictional
  addresses (not built; it would change D-121).
- `STH_INITIAL_STUDENT_PASSWORD` is not set in either `.env`, so the config default applies until the school picks one.

## TEST-RESULTS
- Focused before the full run: `tests/Feature/DataLayer`, `ImportStudentsTest`, `StatusAktivasiTest`, `LetterLayoutTest`,
  `LombaChangeNoticeTest` → 188 passed (1454 assertions).
- Full suite (`--parallel --processes=6`): **3814 passed, 1 skipped, 0 failed (22851 assertions)**, 777 s. `pint --test`
  clean (837 files; the copy's trial seeder too).
- Migration run on the lead's local MySQL (36 classes, none with a digit; Nadia XI-D) and on `smaga_server` (trial
  students in X-A, XI-A, XI-B, XII-A/B/C). Browser, read from the page: Status aktivasi as Kesiswaan on both stacks shows
  only lettered classes. `sth:demo:refresh-files --force` redrew the local demo documents (24 s). php-fpm reloaded on both.
