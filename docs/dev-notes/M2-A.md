# M2-A — Account and session security (QA of 2026-10-05, findings 1, 2, 3, 11)  ·  status: done

Lead, after the QA pass: "Dari ke 12 temuan yang paling penting, lakukan sesuai saranmu". Covered here: Q2-02 (no
"Lewati" after an Admin reset), Q2-01 (no credentials after Back), Q1-03 / Q2-07 (old sessions die), Q1-02 / Q2-05
(weak passwords). Details of each finding: `hasil-uji/` next to the repo.

## What changed and why
**1. Q2-02, a reset student must choose a password.** New column `users.temporary_password_issued_at` (nullable
timestamp; migration `2026_10_06_100000_m2a_add_temporary_password_issued_at_to_users.php`; cast in `User`). No
existing column could tell an import password from a reset password: both set `must_change_password` and
`initial_password_expires_at`. `ResetStudentAccount` sets it, `SetNewPassword::handle()` clears it (activation,
Keamanan, Lupa kata sandi). `CompleteActivation::mayKeepPassword()` is false while it is set; `keepPassword()`
checks again on the locked row and throws `AuthorizationException`, as for staff. The screen then shows "Buat kata
sandi baru" without the skip button. The migration backfills accounts reset earlier that still have
`must_change_password` (from `activity_log`, event `account.reset`). Two Admin texts in `pengguna.blade.php` now
describe the real rule (temporary-password dialog, "Tambah pengguna").

**2. Q2-01, credential pages are never stored.** `/login` only had Symfony's default `no-cache, private`. Livewire
adds `no-store` itself, but only to a response that rendered a Livewire component; `/login`,
`/user/confirm-password` and `/two-factor-challenge` are plain Fortify Blade views. New global middleware
`App\Http\Middleware\PreventCredentialPageCaching` (appended in `bootstrap/app.php`) sets
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` and `Pragma: no-cache` on every answer
(GET, POST, redirect, error) for login, aktivasi, lupa-kata-sandi, user/confirm-password, two-factor-challenge,
akun/2fa. `resources/js/app.js`: password fields (also one that "Tampilkan kata sandi" turned into text) are
emptied on `pageshow` with `event.persisted`, and right after a plain POST form was submitted (a `setTimeout(0)` in
the submit handler: the browser has read the fields by then). A `wire:submit` form is not touched on submit; a
cancelled submit keeps what was typed.

**3. Q1-03 / Q2-07, old sessions die.** `session()->regenerate(true)` in `Activation` (after the code, after
`changePassword`, after `keepPassword`), `Keamanan::changePassword` and `TwoFactorSetup::confirm`, always **after**
the Action succeeded: a wrong current password or a refused new one no longer touches the session.
`ListUserSessions::handle()` lists the current device from the request when its row is not written yet, so
"Perangkat ini" is there right after "Kata sandi diganti".

**4. Q1-02 / Q2-05, weak passwords.** `PasswordPolicy` refuses the account's username and the part of its email
before the "@" (whole, and pieces of 4+ characters with a letter), and common passwords: a built-in list of 67
words (password, sandi, rahasia, admin, indonesia, semarang, sekolah, merdeka, bismillah, iloveyou, …, spellings
such as passw0rd; "@" and "$" read as a and s), alphabet and keyboard runs of 4+ letters, digit runs of 5+, one
character 4+ times. Refused when something from those lists is in the password and fewer than 5 other letters
remain; digits and symbols never count. `password123`, `Qwerty!2026`, `P@ssw0rd-99`, `kode123456` fail,
`sandiwara malam 77` passes. Existing rules and messages are unchanged. `SetNewPassword::hint()` builds the line
under the field from the rules that apply (staff: `Password::default()` + "nama, nama pengguna, email"; student or
guest: "tanggal lahir, NIS/NISN, nama"); the three views print `$passwordHint`.

## Contracts
- `CompleteActivation::hasTemporaryPassword(User): bool` · `::mayKeepPassword(User): bool` · `::MSG_MUST_CHANGE`.
- `SetNewPassword::hint(?User $user = null): string`.
- `PasswordPolicy::validate(…, ?string $username = null, ?string $email = null)` (same two on `passes()`);
  `::isTooCommon(string): bool`; messages `MSG_USERNAME` "Jangan memakai nama pengguna.", `MSG_EMAIL` "Jangan
  memakai email sendiri.", `MSG_TOO_COMMON` "Kata sandi ini terlalu mudah ditebak.".
- `ListUserSessions::handle(User, ?string $currentSessionId, ?string $currentUserAgent = null, ?string $currentIp = null)`.
- `PreventCredentialPageCaching::PATHS`, `::CACHE_CONTROL`.
- Test helper `AuthTestHelpers::userBehindSession(string $sessionId): ?int` (needs `session.driver = database`).

## Files
New: `app/Http/Middleware/PreventCredentialPageCaching.php`, the migration, `tests/Feature/Auth/{CredentialPageCachingTest,
PasswordHintTest}.php`. Changed: `app/Domain/Accounts/PasswordPolicy.php`, `app/Actions/Accounts/{SetNewPassword,
CompleteActivation,ResetStudentAccount,ListUserSessions,DetermineActivationStep}.php`, `app/Livewire/Auth/{Activation,
ForgotPassword,TwoFactorSetup}.php`, `app/Livewire/Akun/Keamanan.php`, `app/Models/User.php` (one cast),
`bootstrap/app.php` (one `append`), `resources/js/app.js`, views `livewire/auth/{activation,forgot-password}`,
`livewire/akun/keamanan`, `livewire/admin/pengaturan/pengguna` (two texts); tests `Unit/Domain/Accounts/PasswordPolicyTest`,
`Feature/Auth/{ActivationTest,AuthPagesTest,StaffTwoFactorTest,AuthTestHelpers}`, `Feature/Akun/KeamananTest`,
`Feature/Accounts/AccountAdminActionsTest`.
Existing tests rewritten because of the change: ActivationTest "serves an account reset by an Admin … may be kept"
(now "makes a student replace the temporary password …"); PasswordPolicyTest samples `kopi12345` and `ayu12345`
(a few letters around the run 12345 are refused now) and the expected lists for `abcdefghij` / `1234567890`.

## Commands verified (Docker, in-memory SQLite, other agents' suites running at the same time)
- Final code: `php artisan test tests/Feature/Auth tests/Feature/Akun tests/Feature/Accounts/AccountAdminActionsTest.php
  tests/Unit/Domain/Accounts` → **229 passed (1358 assertions)**.
- Neighbours: `… tests/Feature/Auth/PasswordHintTest.php tests/Feature/Auth/CredentialPageCachingTest.php
  tests/Feature/Security tests/Feature/Aktivasi tests/Feature/Admin/UsersActionsTest.php tests/Feature/Admin/RoleChange`
  → **309 passed (2154 assertions)**. No test outside my list failed because of the change.
- `./vendor/bin/pint --test <my 23 PHP files>` → pass. `node --check resources/js/app.js` → ok.
- Browser (Chromium pane, dev `/login`): the new JS block, pasted into the page, on throwaway forms: a POST form
  sends the password and has an empty field afterwards (also when the field was shown as text); a cancelled
  submit, a `wire:submit` form and a GET form keep theirs; `pageshow` with `persisted` empties every field.

## Not verified
- The dev site was not reloaded and the migration was not run (coordinator), so no PHP change was seen in a browser:
  Pest only. The Back scenario of Q2-01 itself (login, Keluar, Back twice) was not repeated.
- `npm run build` was not run; the JS was tried pasted, not as the built file. Firefox and Safari not tried.
- The full suite, the email scenario and the Playwright smoke were not run.

## Assumptions / deviations
- **D-79 is narrowed.** D-79 (final) says a reset student may also keep the temporary password. Finding 1, which
  the lead approved, takes that sentence back: it needs a new DECISIONS entry ("menggantikan sebagian D-79").
- An account that already kept a temporary password (dev DB: the QA account Rizky) stays as it is; the migration
  forces nothing behind the owner's back. Production has no such account.
- The mark is set for student resets only. Staff never may keep a password, so `CreateStaffAccount` /
  `ResetStaffAccount` are unchanged; the temporary password an Admin types is not checked against the common list.
- `regenerate(true)` deletes the old row at once. A second request of the same browser still on its way with the
  old cookie would arrive signed out. Livewire sends one component's requests one after the other and these
  screens have no second polling component, so I do not expect it in practice.
- "Too common" counts letters only: `admin` followed by ten random digits is refused too. A username is refused
  whole from 3 characters on; a bare number such as the "2026" of `dwi.2026` is not treated as the account name.
- After a plain form is submitted the password field is empty while the request is on its way.

## Requests for other owners (docs I did not edit)
- DECISIONS: (a) a reset student must replace the temporary password (replaces that part of D-79); (b) `no-store`
  on credential pages + emptied password fields; (c) session renewed with the old one destroyed, never on a refused
  attempt; (d) common-password list and username/email check.
- CLAUDE.md §5 "Accounts" and the middleware line, §8, §10; ARCHITECTURE §4 `users.temporary_password_issued_at`;
  SECURITY §3a K4, K5, K10 and §3f; PANDUAN-PENGGUNA line 289 ("sebaiknya mengganti" → wajib) and the password
  rules; UI-MAP row "Aktivasi (#5)"; `catatan.md` row 3.
- After the merge: run the migration, `reload.bat`, `npm run build`.

## Follow-ups (not done)
- Backlog #45 can use `temporary_password_issued_at` to tell "school password kept" from a reset in Status aktivasi.
- Fortify's own login and 2FA challenge renew the id without deleting the guest row from before the login (no
  `user_id`: never listed as a device, never signed in).
