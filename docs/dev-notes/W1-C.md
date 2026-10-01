# W1-C — authentication & accounts  ·  status: done

## Files (owned by me)
- `app/Providers/{AppServiceProvider,FortifyServiceProvider}.php` — boot guards, PasswordPolicy(10) binding, Password::defaults, strict models (non-prod), rate limiters; Fortify wiring
- `app/Actions/Fortify/{AuthenticateUser,LoginIdentifier,LoginLockout,LoginResponse}.php` (+ untouched, unused Fortify stubs)
- `app/Actions/Accounts/*` — `ImportStudents`(+`StudentImportReport`), `ResetStudentAccount`, `DisableAccount`, `ArchiveAccount`, `RevokeUserSessions`, `DetermineActivationStep`, `SetNewPassword`, `IssueOtpCode`, `VerifyOtpCode`, `CompleteActivation`, `RequestPasswordResetCode`, `ResetPasswordWithCode`
- `app/Http/Middleware/{SecurityHeaders,EnsureAccountActive,EnsureActivated,EnsureStaffTwoFactor}.php`, `bootstrap/app.php` (middleware section only)
- `app/Livewire/Auth/{Activation,ForgotPassword,TwoFactorSetup}.php` + `resources/views/livewire/auth/*`
- `resources/views/auth/{login,two-factor-challenge,confirm-password,placeholder}.blade.php`, `auth/mail/otp-code(-text)`
- `app/Mail/OtpCodeMail.php` (queued + ShouldBeEncrypted), `app/Notifications/Auth/PasswordWasReset.php`
- `config/fortify.php` (+ `sth.*` keys), `config/session.php` (prod defaults), `routes/web.php`
- `tests/Feature/Auth/*` (10 files + `AuthTestHelpers`), `tests/Feature/Accounts/*` (2 files)

## Contracts other areas can use
- Routes: `login`/`login.store`, `logout`, `password.confirm(.store)`, `two-factor.login(.store)` (Fortify) ·
  `password.request` GET /lupa-kata-sandi (guest) · `aktivasi` GET /aktivasi · `two-factor.setup` GET /akun/2fa (password.confirm) ·
  `home` GET /beranda → redirects to `<role>.beranda` (admin/kepsek/kesiswaan/tu/humas/guru/siswa) once that route exists, placeholder until then ·
  `siswa.tour` GET /siswa/tur (**placeholder**, wave 2 replaces with the Tur overlay; delete my route then).
- Middleware aliases: `active` (disabled/archived → logout + sessions revoked; also appended to `web`), `activated`
  (unfinished first login → `aktivasi`), `staff.2fa` (non-siswa without confirmed TOTP → `two-factor.setup`), `role`,
  `permission`, `role_or_permission` (spatie). Groups: **`area.siswa`** = auth, active, activated, role:siswa ·
  **`area.staff`** = auth, active, activated, staff.2fa (add `role:…` per area, admin: `['area.staff','role:admin']`).
- Global: `SecurityHeaders` (CSP nonce via `Vite::useCspNonce()`, frame-ancestors none, XFO DENY, Referrer/Permissions/COOP);
  a response that sets its own CSP keeps it. TrustProxies `env('STH_TRUSTED_PROXY','172.30.10.1')` (XFF/Port/Proto only),
  TrustHosts = APP_URL host, no subdomains (skipped by Laravel in local/testing).
- Actions (all authorize via spatie permission, audit via activitylog):
  `ImportStudents::handle(User $actor, string $path, string $ext /*xlsx|csv*/, string $schoolYear, bool $dryRun = true): StudentImportReport`
  (`rows[]` = row, name, nis, classroom, errors[], grade12_start_date, archive_at; `validRows()`, `invalidRows()`, `created`) — `student.import`
  · `ResetStudentAccount::handle(User $actor, User $student, string $reason)` — `user.reset_password`
  · `DisableAccount::handle(User $actor, User $target, string $reason, UserStatus $status = Disabled)` / `ArchiveAccount::handle(...)` — `user.archive`
  · `RevokeUserSessions::handle(User $user, ?string $exceptSessionId = null)` (DB sessions + remember token) — use it in the archive job
  · `DetermineActivationStep` (`'password'|'email'|null`) · `SetNewPassword::rules(User)` (for a future "ganti kata sandi" screen)
- OTP: `IssueOtpCode::handle(User, purpose, email): bool` (false = 60 s cooldown) · `VerifyOtpCode::handle(User, purpose, code, consume = true): ?OtpCode`
  · `VerifyOtpCode::testModeActive()` (local AND flag). Codes: HMAC(APP_KEY) bound to user|purpose|email, 10 min, 5 attempts, single use.
- Config/env: `fortify.sth.otp_test_mode` ← `STH_OTP_TEST_MODE` · `fortify.sth.require_staff_2fa` ← `STH_REQUIRE_STAFF_2FA` (default: on unless APP_ENV=local) ·
  `fortify.sth.otp.queue` ← `STH_OTP_QUEUE` (default `otp`) · `fortify.sth.mail_queue` ← `STH_MAIL_QUEUE` (`mail`) · `STH_TRUSTED_PROXY` ·
  session prod defaults: cookie `__Host-sth_session`, secure, encrypt (overridable by SESSION_*).
- Login: field `identifier` = NIS/NISN (digits; NIS+NISN share one rate-limit bucket) or staff username/email; one message
  `AuthenticateUser::GENERIC_ERROR` "NIS/NISN atau kata sandi salah."; limits 5/min per account+IP, 20/h per account, 60/min per IP;
  progressive lockout 5→1 min, 10→15 min, ≥20→1 h; remember-me ignored; last_login_at on `Login` event.

## Commands verified
- `docker compose run --rm --no-deps app ./vendor/bin/pest tests/Feature/Auth tests/Feature/Accounts` → 90 passed (421 assertions)
- `docker compose run --rm --no-deps app ./vendor/bin/pest` (whole suite, with other agents' tests) → all passed (392 at last full run)
- `./vendor/bin/pint` on all files above → clean
- Browser (dev stack, 127.0.0.1:8080): /login generic error; demo student NIS login → /aktivasi → policy error shown → new password →
  email → "Mode uji" → any 6 digits → done → /siswa/tur; no CSP console errors. The demo student was then reset back to
  pending through `ResetStudentAccount` (audit rows `account.*` remain; one stale job sits in the `otp` queue).

## Assumptions / deviations from the spec
- Permission names from W1-A's `App\Enums\Permission`: `user.reset_password` / `user.archive` (brief said `account.reset/disable`).
- Password min **10** (lead decision, SECURITY K5) via container binding of `PasswordPolicy`; staff use `Password::defaults()` (prod 12 + mixed + symbols + HIBP).
- Boot guard throws when the OTP flag is on in any env except `local`/`testing` (testing boots, but the flag is ignored there); also
  throws in production for MAIL_MAILER log/array, APP_DEBUG=true, or a `__Host-` cookie without Secure/Path=/ or with Domain.
- ResetStudentAccount returns the account to the **initial password** (brief) — SECURITY K10 prefers a random 72 h temporary password.
- Import strips leading `= + - @` (brief) instead of rejecting (SECURITY §3f); formulas are read as text (`keepFormulas()`), names must be
  letters only; runs synchronously — wrap in a queued job for 1 000+ rows (wave 2 UI). NIS/NISN duplicates flag every row involved.
- CSP: `script-src 'self' 'nonce-…' 'unsafe-eval'` while `livewire.csp_safe` is false (Alpine); style-src nonce only (no `style=""`).
- Test-mode emails are stored with `email_verified_at = null` (SECURITY §3b.4); `activated` accepts that only while test mode is on.
- URL `/lupa-kata-sandi` and classes `App\Livewire\Auth\*` (brief) differ from docs/UI-MAP.md (`/forgot-password`, `Akun\*`).
- Fortify registration, link-based reset, profile/password endpoints and passkeys are disabled.

## Requests for other owners
- @lead/I: dev `compose.yaml` worker only drains `default`, so OTP/notification mail never reaches Mailpit locally. Use
  `queue:work --queue=otp,mail,default …` (as prod) or set `STH_OTP_QUEUE=default STH_MAIL_QUEUE=default` in `.env.example`.
- @lead: before your phpunit/compose fix my first test run used the dev MySQL `sth` (RefreshDatabase = migrate:fresh); W1-A has re-seeded since.
- @config owner: `livewire.csp_safe = true` would let me drop `'unsafe-eval'`; `lang/id` for validation + Fortify 2FA error texts (English now).
- @W1-A: consider nulls for `two_factor_*`/`last_login_at` in `UserFactory` (strict mode + `actingAs` throws MissingAttributeException).
- @W1-D: `layouts::auth` split variant: at ~420 px width the top brand bar overlaps the page heading.
- @wave 2: area routes should use `area.siswa` / `['area.staff','role:…']`; archive job → `RevokeUserSessions` (or `ArchiveAccount`).

## Follow-ups (not done)
- K1 activation window (14 days) + K2 reopen + K3 freeze after 10 failures need columns (`initial_password_expires_at`, counter).
- K8 "email bound" notice + "Bukan saya?" link; K9 activation report; K10 random temp password; alert on >10 resets/hour.
- Security log channel (`security.*` events go to the default log now); OTP rows cleanup job (>24 h).
- First-Admin CLI (`sth:create-admin`, W1-A request) — app/Console is not in my area; lead/wave 2.
- Seen once in a full-suite run: `DataLayer\SeedersTest` "dummy signature image" failed, passes alone (shared storage, parallel runs?).
