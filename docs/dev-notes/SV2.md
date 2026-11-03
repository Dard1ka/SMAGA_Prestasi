# SV2: initial student password from the birth date (D-173) · status: built 2026-11-02, not committed

The lead's request of 2026-11-02, after trying the buttons and the Excel downloads on the server: the import file has six
columns (Nama lengkap, NIS, NISN, Tanggal lahir, Kelas, No HP opsional), and the initial password of a student who has not
activated is no longer the one value of `.env` (D-138) but "SMAGA_" + the birth date of the file as ddmmyyyy
(01-02-2010 → SMAGA_01022010); students born on one day share it. **`smaga_server` only** ("smaga4 gausah dulu"): Smaga4
is untouched and keeps D-138. Decision: DECISIONS §D25, D-173. Everything else of D-138 stays (new password required at
the activation, no "Lewati", never shown or logged).

## Files
- `app/Domain/Accounts/InitialPassword.php` — `PREFIX = 'SMAGA_'`, `static forBirthDate(DateTimeInterface|string): string`
  (`Dates::toDate()`: the calendar day of the value in its own timezone, never converted; strings `Y-m-d` /
  `Y-m-d H:i:s`, else `InvalidArgumentException`); `matches()` (K7) also knows the pattern; `make()` (the configured
  value) is never hashed for a new account and keeps four readers: K7, `PasswordPolicy` (equality), the security-log
  fingerprint of a 10-digit identifier (`SecurityEventSubscriber::identifier()`) and the move command. Docblock D-173.
- `app/Actions/Accounts/ImportStudents.php` — `createStudent()` hashes `forBirthDate()` of the row's `Y-m-d` (the value it
  stores as `birth_date`); the `InitialPassword` constructor dependency is gone. The queued path
  (`ImportStudentsJob` → `importSlice()` → `createStudent()`) is the same method. A re-import never touches a password.
- `app/Domain/Accounts/PasswordPolicy.php` — `MSG_INITIAL_PATTERN` also for the own "SMAGA_" + birth date (contained);
  "smaga" and the birth date were already refused on their own.
- `app/Actions/Accounts/ApplyBirthDateInitialPasswords.php` (new) — `preview()` / `handle()`, see below.
- `app/Console/Commands/ApplyInitialPasswordRule.php` (new) — `sth:siswa:kata-sandi-awal {--terapkan} {--force}`.
- `app/Livewire/Akun/ImportSiswa.php` + `resources/views/livewire/akun/import-siswa.blade.php` — `INITIAL_PASSWORD_NOTE`
  replaces "sama untuk semua siswa baru": the rule with the fictional example only (Kesiswaan, Admin).
- `app/Livewire/Admin/AuditLog.php` — label of `student.initial_password_rule_applied`.
- `database/seeders/DataUjiSekolahSeeder.php` (pending trial students 3, 6, 9), `DemoSeeder.php` (local demo students).
- Comments: `AppServiceProvider`, `SecurityEventSubscriber::identifier()` (the pattern is fingerprinted: neither 10
  digits nor an email), `AuthenticateUser` (K7), `config/sth.php`, `.env.example`, `docker/env.production.example`.
- Docs: `docs/DECISIONS.md` (§D25, D-173; pointers on D-138), `CLAUDE.md` (§2, rule 3, §5 Accounts, §8, §9, §10 status
  and backlog #84), `docs/SECURITY.md` (dated notes: top, §0 item 4, K5, K6, K7, K12), `docs/ARCHITECTURE.md` §7,
  `docs/PANDUAN-PENGGUNA.md` (Import siswa, Admin reset), `README.md` (demo row, activation steps), `AKUN-UJI.md`,
  `AGENTS.md` (rules 3 and 12), `CONTRIBUTING.md` (PR checklist exception).

## Review fixes (2026-11-02, after the independent review of this change set)
- **NISN-only guessing risk** (major, docs): D-173 "Risiko", SECURITY §0 item 4 and the top note now say that the
  NISN alone is enough: its first three digits are usually the birth year, so about 366 guesses remain; 29 failures a
  day stay below the 30-failure alert of `LoginLockout`, about 400 in the 14-day window; one address can try many
  pending accounts; K7 does not cover pending accounts; the attacker binds an own email. K7 and "OTP to the bound
  email" were removed from the mitigations (they do not protect a pending account). Options for the lead, not built:
  lower cap + earlier alert for `pending_activation`, an alert when one address fails across many accounts, short
  supervised per-class windows, a small `activation_window_days`. **The lead must hear this before real students are
  imported** (backlog #84).
- **Template date column** (minor): `ImportStudents::TEMPLATE_TEXT_COLUMNS` includes "Tanggal lahir", so the template
  cell is "@" and a typed 01-02-2010 reaches `parseDate()` as text (day first) whatever the PC's date setting; date
  cells and serials of other files are still read. The hint under the template says so; PANDUAN says to type
  DD-MM-YYYY.
- **Audit of a stopped run** (minor): the command writes and audits per chunk in one transaction (above); it prints the
  account count first and a progress line per chunk.
- **AGENTS.md / CONTRIBUTING.md** (major): rule 3 names `forBirthDate()` and the import screen's fictional example;
  rule 12 forbids the `sm4g4_` pattern of D-24 only and says SMAGA_ + ddmmyyyy is the initial password here;
  CONTRIBUTING's checklist item has the D-173 exception.
- **Readers of the configured value** (minor, docs/comments): config, both env templates, CLAUDE.md rule 3, D-173,
  SECURITY K6 and top note, `InitialPassword`, `AppServiceProvider`, ARCHITECTURE now name all four readers.
- **Nits**: deploy steps with `cd /opt/sth/app`, a `users` backup and the run time (above and `AKUN-UJI.md`); a test
  of the lock re-check (`changed_meanwhile`; it fails when the re-check is removed, tried once and restored); CLAUDE.md
  §9 names the D-173 risk for the D-14 form and backlog #84 lists the follow-ups; PANDUAN says what to do when
  "SMAGA_ + tanggal lahir" is refused (check the date in the imported file, or the Admin reset); README's activation
  line no longer says a student may keep the password; the email scenario's leak-scan texts name the birth-date
  pattern.

## The command `sth:siswa:kata-sandi-awal`
- Moves only rows where: role siswa, status `pending_activation`, `must_change_password` true,
  `temporary_password_issued_at` null (never an Admin's temporary password), a student row with a birth date, and
  `Hash::check(configured value, users.password)`. Writes `users.password` only (`forceFill`), never
  `initial_password_expires_at`, the email or the status. Idempotent: a second run finds 0.
- Per chunk of 100 (`ApplyBirthDateInitialPasswords::CHUNK`, review fix): the new hashes are made first (bcrypt, outside
  any transaction), then one transaction locks each row, compares it again with what was checked (same hash, still
  pending, still forced, no temporary password; else `changed_meanwhile`), writes the moved ones and adds one audit row
  `student.initial_password_rule_applied` (log `account`, so also the security log; no causer; `via` cli, `run` = a ULID
  of the run, `moved`, `changed_meanwhile` of that chunk). A chunk that moved nobody writes no row. So a run stopped
  part-way (Ctrl+C, a restart, an exception) has audited everything it wrote, and the next run moves the rest.
- Default = dry run: counts per class, plus "Tidak diubah (siswa belum aktivasi): N tanpa tanggal lahir, N dengan kata sandi
  lain[, N berubah saat perintah berjalan]". Never a name, a NISN or a password. It prints "Memeriksa N akun siswa belum
  aktivasi." first and, above one chunk, "  100/N akun diperiksa" after every chunk. `--terapkan` writes; outside
  `APP_ENV=local` also `--force` (no command of the app had a confirmation flag; `--force` is Laravel's own word for it).

## Deploy on the school's server (`smaga_server`, APP_ENV=local)
Every command runs as root **from `/opt/sth/app`** (`cd /opt/sth/app` first): Docker Compose reads that folder's `.env`
(project `sth`, `COMPOSE_FILE=compose.yaml:compose.server.yaml`), so a `docker compose` run from another folder addresses
the wrong project.
1. Copy/extract the changed files over the server folder (no migration, no new package, no asset change).
2. `docker compose exec -T app sh docker/php/dev-cache.sh --force` (php-fpm reads the new code and config).
3. `docker compose restart queue scheduler` (dev-cache.sh asks the worker to restart too; the explicit restart is safe).
4. Keep `STH_INITIAL_STUDENT_PASSWORD` as it is until step 7.
5. Back up the `users` table (there is no reverse command; the file holds hashes and emails, so root-only, deleted once
   all is well):
   `docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction sth users' > /opt/sth/users-sebelum-sv2.sql && chmod 600 /opt/sth/users-sebelum-sv2.sql`
   (checked on the laptop stack piped to a count, nothing stored: the dump has the table and its rows; mysqldump warns
   about the password on its command line, which is the container's own variable, never typed).
6. `docker compose exec app php artisan sth:siswa:kata-sandi-awal` → read the counts per class.
7. `docker compose exec app php artisan sth:siswa:kata-sandi-awal --terapkan` → the same counts; a second run says
   "Tidak ada siswa yang perlu diganti."
   Time: about 0.2 s per pending student for step 6 (one bcrypt check, cost 12 as `ImportStudentsJob` measured) and
   about 0.4 s for step 7 (a check and a new hash): a few hundred pending students take a few minutes. The count comes
   first and a progress line every 100 accounts, so it does not look hung; do not stop it (if it stops anyway, what it
   wrote is kept and audited, and running step 7 again finishes the rest).
8. Kesiswaan tells the students the rule outside the app. New imports need a correct "Tanggal lahir" column (the
   template formats it as Text since the review; type DD-MM-YYYY).

Done on the lead's laptop stack (2026-11-02): step 2, `restart scheduler`, dry run (3 students: X-A, XI-B, XII-C = trial
students 3, 6, 9), `--terapkan` (3 moved), a second run (0, "3 dengan kata sandi lain"); a tinker check printed per student
only "pola-tgl-lahir=ya/tidak" (the 3 pending ones yes, the configured value no), 1 audit row; a curl login of trial
student 6 with NISN + the birth-date password answered 302 → /aktivasi (200), then logged out. Not done: the server itself.

## Assumptions / deviations
- The calendar day is the date part of the value (no timezone conversion). The import already parses a DateTime cell in
  Asia/Jakarta and stores that day; the password is built from the stored `Y-m-d`, so the two always agree.
- An xlsx written by SimpleExcelWriter keeps a DateTime as a plain serial unless the cell has a date format; the test
  writes one date-formatted cell (read back as DateTime) and one plain serial (read back as int), so both parser paths run.
- `ImportSiswa::NEW_STUDENTS_LOGIN` ("… kata sandi awal dari sekolah.") is still true and was left alone.
- `security.initial_password_on_active` (K7) recognises the pattern of the account's own birth date only, exactly.

## Follow-ups (not done)
- Smaga4: nothing changed; if the lead wants D-173 there too, the same files apply (DataUjiSekolahSeeder and AKUN-UJI.md
  exist only here).
- **Tell the lead before real students are imported**: the NISN-only guessing risk of D-173 (about 366 guesses, 29 a day
  without an alert) and the four options above; none is built (CLAUDE.md backlog #84).
- The D-14 risk-acceptance form should name the D-173 risk (with the NISN point) instead of the D-138 "one value for
  everyone" risk.
- The email scenario was edited (leak needle for the pattern) but not run (not in CI; isolated project).
- The server steps (backup, dry run, `--terapkan`) are not run on the school's server yet. A package made before the
  review fixes (`kirim-server/smaga_server-update-20261102.tar.gz` next to the repo, 21:30) does not contain them:
  make it again before sending.

## TEST-RESULTS
- New/changed tests: `tests/Unit/Domain/Accounts/InitialPasswordTest.php` (builder, six timezone cases, bad strings, K7
  matches), `PasswordPolicyTest.php` (own pattern refused, only with the birth date), `tests/Feature/Accounts/
  InitialPasswordRuleTest.php` (new: dry run writes nothing and prints counts only; `--terapkan` moves only the right rows,
  keeps window/email/status, one audit row with counts, no name/NISN/password in output or audit; idempotent; refused
  outside local without `--force`; local without `--force`; nobody waiting; audit label; `DataUjiSekolahSeeder`),
  `ImportStudentsTest.php` (every CSV date format, a date-formatted xlsx cell and a plain serial, one birth date = one
  password, a re-import leaves a password alone, the queued job, nothing leaks; the login part of the old test: the
  configured value refused, the pattern reaches the activation), `InitialPasswordConfigTest.php`, `Fs1QaCopyLayoutTest.php`
  (the screen sentence, the row's own value not shown), `LoginTest.php` (K7 dataset + another day not flagged + login),
  `ActivationTest.php` (own pattern refused as the new password), `AccountControlsTest.php`, `SeedersTest.php`.
- Focused: the unit files + `InitialPasswordRuleTest` + `InitialPasswordConfigTest` 100 passed (after one fix in the test:
  the output may name the rule "SMAGA_", not a student's value); the eight affected feature files 186 passed (1914
  assertions); the D-173 import tests 7 passed.
- Full suite (`--parallel --processes=6`): **4320 passed, 1 skipped (26412 assertions)**, exit 0, 1259 s.
- `pint --test`: clean (905 files). `php -l tests/E2E/support/email-scenario.php`: no syntax errors (scenario not run).
- After the review fixes (2026-11-02/03): `InitialPasswordRuleTest` 11 passed (171 assertions; new: the lock re-check
  `changed_meanwhile`, a run stopped part-way keeps and audits its first chunk and the next run moves the rest, progress
  per chunk; the re-check test was tried once with the re-check removed: it fails, file restored byte for byte);
  `ImportStudentsTest` 44 passed (456 assertions; the template's D column is Text, the hint sentence, a typed
  01-02-2010 in an "@" cell reads day first). Full suite (`--parallel --processes=6`): **4324 passed, 1 skipped (26468
  assertions)**, exit 0, 973 s. `pint --test`: clean (905 files). Laptop stack: `dev-cache.sh --force`, then the dry run
  of the command printed "Memeriksa 3 akun siswa belum aktivasi." and "3 dengan kata sandi lain" (the trial students
  moved earlier); the `users` dump command of the deploy steps ran there piped to a count (nothing stored).
- Smaga4: `git status` clean (not touched).
