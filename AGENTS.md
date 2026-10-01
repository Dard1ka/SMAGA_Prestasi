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
3. **Never display or log the initial password pattern.** Only `App\Domain\Accounts\InitialPassword`
   builds it, only to hash it at import or Admin reset.
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
12. Never reintroduce superseded decisions: password `sm4g4_DDMMYY` (D-24), report deadline 45 days
    after the final (D-25), all lomba uploads verified by Kesiswaan (D-27), alumni cannot log in
    (D-29), Admin separate from Kepala Sekolah (D-01), each team member full points and
    Semifinalis/Penghargaan khusus 0,30 (D-08). Log: `docs/DECISIONS.md`.
