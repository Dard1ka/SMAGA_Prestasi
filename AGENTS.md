# AGENTS.md — SMAGA Talent Hub (STH)

**Read `CLAUDE.md` first.** It is the single source of project context for every AI agent
(Claude, Codex, Cursor, Copilot, …). This file only repeats the rules that must never be missed.

## Critical rules
1. **Docker only.** Never install PHP, Composer or Node on the host and ignore any "install PHP"
   bootstrap snippet. Run commands as `docker compose run --rm --no-deps app <cmd>`
   (e.g. `... app php artisan test`, `... app ./vendor/bin/pint`, `... app composer require x/y`);
   use `docker compose exec app <cmd>` for DB commands while the stack is up.
2. **No real personal data.** Real school documents live outside the repo
   (`D:\real_project\SMAGA_Prestasi\SMAGA\`); never copy names, NIS/NISN, birth dates, signatures or
   photos from them. Seeds, tests and screenshots use the fictional set in
   `docs/design/DESIGN-SPEC-v2.md` §5 only.
3. **Never display or log a student's initial password.** *`smaga_server`, 2026-11-02 (D-173):* a new student's
   initial password is "SMAGA_" + the birth date of the import row as ddmmyyyy, built only by
   `App\Domain\Accounts\InitialPassword::forBirthDate()`, only to hash it (import, seeders, `sth:siswa:kata-sandi-awal`)
   or compare it (K7, `PasswordPolicy`); the import screen states the rule with the fictional example
   01-02-2010 → SMAGA_01022010 only, never a student's own value. The D-138 config value (`sth.initial_student_password`,
   env `STH_INITIAL_STUDENT_PASSWORD`, `InitialPassword::make()`) is never hashed for a new account any more; it is only
   compared (K7, `PasswordPolicy`, the security-log fingerprint) and moved away by that command.
4. **Uploads only via `App\Domain\Files`** onto the private disk; downloads only through the
   policy-checked controller; never fetch user-supplied URLs.
5. **A Policy for every model**, deny by default, `visibleTo()` scopes, 404 for other people's data;
   Livewire IDs `#[Locked]` and re-authorized in every action.
6. State changes (status, points, verification) only in `app/Actions/*`, never mass-assignable.
7. **OTP test mode only with `APP_ENV=local` and `STH_OTP_TEST_MODE=true`.** Never in production.
8. `migrate:fresh` / `db:wipe` / demo seeders only against the local Docker DB.
9. UI copy in short Bahasa Indonesia, sentence case, "kamu" for students, no emoji, decimal comma.
   Code and comments in English.
10. Tests (Pest) for every rule, Action, Policy and route; Pint clean before hand-off.
11. **No `git commit`/`push`/branch changes unless the user asks.** Edit only files your area owns
    (`CLAUDE.md` §11); write your hand-off note to `docs/dev-notes/<ID>.md`.
12. Never reintroduce superseded decisions: login by NIS, "Lewati" at activation, the `sm4g4_` birth-date pattern of
    D-24 (D-137, D-138; *`smaga_server`: "SMAGA_" + ddmmyyyy is the initial password since D-173, keep it*), report deadline 45 days
    after the final (D-25), all lomba uploads verified by Kesiswaan (D-27), alumni cannot log in
    (D-29), Admin separate from Kepala Sekolah (D-01), each team member full points and
    Semifinalis/Penghargaan khusus 0,30 (D-08). Log: `docs/DECISIONS.md`.
