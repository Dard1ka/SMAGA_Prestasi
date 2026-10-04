# M1-B — Accounts: student login by email, activation order email → code → password  ·  status: done

Lead request (hand test of 2026-10-04): "siswa juga bisa masuk menggunakan email jika sudah selesai aktivasi" and
"saat aktivasi harusnya hubungkan email dulu lalu minta kode verifikasi baru. Untuk ubah password nya optional".
These are decisions; the DECISIONS entries are written by the coordinator (proposals under "Docs to edit").

## Files (changed by me)
- `app/Actions/Fortify/LoginIdentifier.php` — an `@` identifier now also resolves a student, but only when the
  activation is finished; `isStudentAccount()`; constructor takes `DetermineActivationStep`.
- `app/Actions/Accounts/DetermineActivationStep.php` — new order: `EMAIL` first, then `PASSWORD`; `emailVerified()`,
  `finished()`.
- `app/Actions/Accounts/CompleteActivation.php` — rewritten: `bindEmail()`, `changePassword()`, `keepPassword()`,
  `whenPasswordAlreadyChosen()`. The old `handle(User, OtpCode)` is gone.
- `app/Actions/Accounts/ResetPasswordWithCode.php` — closes a half-finished activation after an OTP reset.
- `app/Livewire/Auth/Activation.php` + `resources/views/livewire/auth/activation.blade.php` — steps 1 email, 2 code,
  3 password (student: "Ganti kata sandi" or "Lewati, pakai kata sandi sekarang"; staff: change only), done.
- `resources/views/auth/login.blade.php`, `resources/views/livewire/auth/forgot-password.blade.php`,
  `resources/views/siswa/bantuan.blade.php` — copy.
- Comments only: `AuthenticateUser`, `RequestPasswordResetCode`, `FortifyServiceProvider`, `config/fortify.php`,
  `EnsureActivated`, `Livewire/Auth/ForgotPassword`, `routes/web.php`.
- Tests: `tests/Feature/Auth/{ActivationTest (rewritten), LoginTest, ForgotPasswordTest, AuthPagesTest}.php`,
  `tests/Feature/Aktivasi/AccountControlsTest.php`; E2E `tests/E2E/specs/smoke.spec.js` (login label),
  `tests/E2E/support/prepare-student.php` (comment). No migration, no new column, no new route.

## Behaviour
**Login.** Student identifier = NIS, NISN, or the bound email. The email resolves to the student only when
`DetermineActivationStep::finished()` (status `active`, email verified, no forced change). In every other case
(email typed but not verified, password step still open, disabled, archived, unknown address) it resolves to nobody:
same generic error, the dummy-hash path, and the HMAC rate-limit bucket of an unknown identifier. A resolved email
shares the account bucket `u:<id>` with NIS/NISN (throttle + progressive lockout). Frozen accounts, K1 and K3 behave
as with the NIS. A student never logs in by username; staff are unchanged (username or email, verified or not).
D-66 holds: a student logging in by email skips the TOTP challenge. "Lupa kata sandi" uses the same resolver.
In local OTP test mode an address bound there counts as verified (same rule as `DetermineActivationStep` before).

**Activation.** Step = `EMAIL` while there is no verified email; `PASSWORD` while `must_change_password` or the
status is still `pending_activation`; else done. `bindEmail()` stores email + `email_verified_at`, audits
`account.email_bound` (masked address), sends the K8 "Bukan saya?" notice, and leaves the account **pending**.
`changePassword()` (rules of `SetNewPassword`, must differ from the current password, other sessions revoked) or
`keepPassword()` then sets `active`, `activated_at`, clears `initial_password_expires_at` and
`failed_activation_attempts`, audits `account.activated` with `password_changed` true/false.
`keepPassword()`: students only (`mayKeepPassword()` = student record and no staff role, else 403), sets
`must_change_password = false`, leaves the hash and `password_changed_at` (null) untouched, audits
`activation.password_kept` with no properties. "Who still uses the password from school" = active student with
`password_changed_at IS NULL` (or the audit event).
Resume: a pending code → code step; bound email → password step. An Admin-reset account and a restored account use
the same screen; a reset student may also keep the temporary password. Accounts caught half-way in the old order
(password already chosen, no email) finish at the code step. An active account whose address is unverified
(bound in test mode) only re-verifies: no second `account.activated`, `activated_at` kept.
Staff do use this screen (`CreateStaffAccount` / `ResetStaffAccount` leave them pending + must change): same order,
password change required, no skip.
Local OTP test mode (the dev `.env` has it on): a bound address stays unverified as before. For a pending account
"email step done" is then read from the audit trail (newest of `account.email_bound` / `account.reset` /
`account.staff_reset` is `account.email_bound`), so an address an Admin typed for a staff account still goes through
steps 1–2, and a student who left after the code resumes at the password step. Active accounts need no lookup.

## Contracts other areas can use
- `CompleteActivation::bindEmail(User, OtpCode): void` · `::changePassword(User, string $password, string $confirmation,
  ?string $keepSessionId = null, array $messages = []): void` · `::keepPassword(User): void` ·
  `::whenPasswordAlreadyChosen(User): bool` · `::mayKeepPassword(User): bool`; event names as class constants.
- `DetermineActivationStep::finished(User): bool`, `::emailVerified(User): bool` (static).
- `LoginIdentifier::isStudentAccount(User): bool` (static).
- `Livewire\Auth\Activation`: `STEP_EMAIL` 1, `STEP_CODE` 2, `STEP_PASSWORD` 3, `STEP_DONE` 4; actions `sendCode`,
  `resendCode`, `changeEmail`, `verifyCode`, `savePassword`, `keepPassword`, `start`.

## Commands verified (Docker, in-memory SQLite, other agents' suites running at the same time)
- Before my change: `tests/Feature/Auth` + `AccountAdminActionsTest` + `AccountControlsTest` + `StudentTwoFactorTest`
  → 129 passed (888 assertions).
- Final code: `php artisan test tests/Feature/Auth/ActivationTest.php tests/Feature/Auth/AuthPagesTest.php`
  → **36 passed (440 assertions)** (ActivationTest 26, AuthPagesTest 10).
- Final code: `… Auth/LoginTest.php Auth/ForgotPasswordTest.php Aktivasi/AccountControlsTest.php`
  → **59 passed (598 assertions)**.
- Neighbours (`Auth/{OtpTestModeGuard,StaffTwoFactor,SecurityHeaders,TrustedHost}Test`, `Accounts/AccountAdminActionsTest`,
  `Security/{StudentTwoFactor,CspSafe}Test`, `Akun/KeamananTest`, `Siswa/Lomba/SiswaScreensTest`,
  `Aktivasi/StatusAktivasiTest`, `Admin/{UsersActions,LangId}Test`, `Admin/Arsip/ArsipOtomatisTest`)
  → **186 passed (967 assertions)**. This run started before the last edit of `DetermineActivationStep` (the test-mode
  lookup, which only matters for pending accounts in local test mode); it was not repeated.
- No existing test outside my list failed because of the change.
- `./vendor/bin/pint --test <my 18 PHP files>` → pass.
- Browser: `/login` on the dev stack at ~375 and ~420 px (label, hint, note wrap cleanly). Read-only GET.

## Not verified
- The activation screen in a real browser (it needs a login that changes dev data): render-tested with Pest only.
- Playwright smoke and the email scenario were not run (E2E stack). The smoke test only needed the new field label.
- The full suite was not run (other agents were running theirs).

## Assumptions / deviations
- The generic login error text is unchanged ("NIS/NISN atau kata sandi salah."): it is mirrored in `lang/id/auth.php`,
  `lang/id.json` and `LangIdTest` (shared). It now also answers an email. A wider text is a coordinator call.
- The password step must be passed with a password that differs from the stored one (a re-typed current password
  would be a hidden "keep", which staff may not do). The old flow did not check this.
- The K8 notice goes out when the email is bound (step 2), while the account is still pending; closing the
  activation at step 3 sends no mail.
- A staff account that has no role yet (pending privileged role, D-61) gets the student password rules in
  `SetNewPassword::rules()` (`isStaff()` is role based). Existing behaviour, not changed here.
- `security` log: a failed login records the typed identifier; that can now be a student's email (it already
  recorded staff emails).

## Requests for other owners
- @coordinator `tests/E2E/support/email-scenario.php`: `$startActivation` must only issue the OTP (drop the
  `Validator` + `SetNewPassword::handle` part); `$finishActivation` must call
  `CompleteActivation::bindEmail($user, $otp)` instead of `handle($user, $otp)`, then
  `$password = $newPassword($user); CompleteActivation::changePassword($user, $password, $password);
  $passwords[$key] = $password;`. Mail expectations stay the same (1b: OTP mail; 1c: "Email kamu terhubung …").
  Reword the labels of steps 1b/1c.
- @coordinator `app/Livewire/Admin/AuditLog.php` `EVENT_LABELS`: `account.email_bound` → "Email dihubungkan",
  `activation.password_kept` → "Kata sandi dari sekolah tetap dipakai", `account.initial_password_changed` →
  "Kata sandi awal diganti" (the last one had no label before either).
- @coordinator / agent E (`ActivationReport`, `StatusAktivasi`): a flag or filter "Masih pakai kata sandi dari sekolah"
  (active student, `password_changed_at` null); a pending row can now show a masked email (bound, password step open).
- @coordinator shared views with the old note text ("mengganti kata sandi dan menghubungkan email"):
  `resources/views/dev/auth.blade.php`, `resources/views/dev/components.blade.php`, the example in
  `resources/views/components/sth/alert.blade.php`. `DemoSeeder`: nothing to change.

## Docs to edit (coordinator)
- DECISIONS: new entry "student may log in with the verified email after activation" (replaces part of D-42); new
  entry "first login = email → code → password; password change optional for students, required for staff" (replaces
  the order in D-14 and K4), with the risk note below.
- SECURITY §3a: K4 (order, optional change), K1 (window ends when the student keeps the password), K7 (cannot fire
  for an account that kept the password), K8 (notice at step 2), K11 (email identifier for activated students),
  risk analysis + risk-acceptance form.
- ARCHITECTURE §3 line "First login: force password change → bind email → OTP" + audit events
  `account.email_bound`, `activation.password_kept`, `account.activated.password_changed`.
- UI-MAP rows 4 and 5; PANDUAN-PENGGUNA §1 (Masuk, Masuk pertama kali, "Bukan saya?"); README line 90; TEAM line 25;
  CLAUDE.md §1, §5 "Accounts", §8. The mockup board `Aktivasi.dc.html` still shows the old order.

## Risk note for the lead
A student who skips keeps a password that classmates can work out from the birth date. Before, that only worked
until the student activated (at most the 14-day window); for a student who skips it now works for as long as the
password is kept, and K7 cannot detect it. A takeover can also stay unnoticed: whoever activates first can bind an
own email and skip, and the owner still gets in with the school password. The K9 report (each student checks the
masked email of the own row) and a reminder to change the password are the counterweights.
Email login adds no new way in: the email is not a secret, the password is.
