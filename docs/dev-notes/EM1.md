# EM1 — real mail from a local stack (Brevo, allowlist)  ·  status: built (branch `feat/email-brevo-dev`); the manual mail test by the lead is still open

The lead's request of 2026-10-08: try Brevo for development, with a temporary sender because the school address
cannot be used yet, and prepare the ground for work on the notification mails. Decision **D-121** (DECISIONS §D12,
*sementara*). This note covers the safety step only: no notification, queue or production file changed. No migration.

## Why a guard came first
- A fresh local stack mails by itself: within minutes of the first start the scheduler sent two "Waktunya upload
  publikasi" mails to the demo Humas account (`…@sth.test`). In local OTP test mode an unverified address gets mail
  too (`User::routeNotificationForMail()`, D-68).
- With a real provider under `MAIL_MAILER=smtp` each of those is a hard bounce on a new account and one of 300 mails a day.

## Files
- `app/Mail/Transport/RecipientAllowlist.php` — `fromString(string): self`, `allows(string): bool`, `isEmpty()`,
  `patterns()`, `rejected()`. An entry = a whole address or a `*` in the name part; a whole domain, a `*` in the
  domain or a non-address is dropped (and listed by `rejected()`), so a typo can only send less.
- `app/Mail/Transport/AllowlistTransport.php` — Symfony transport `allowlist`: all recipients (To, Cc, Bcc) listed →
  mailer `deliver`, else mailer `catch`. `fromConfig(MailManager, array)` refuses outside `local` / `testing` and
  refuses a nested allowlist mailer. `mailerFor(array $config, bool $listed): string`.
- `config/mail.php` — mailers `allowlist` (`deliver` = `smtp`, `catch` = `mailpit`, `recipients` =
  `env('STH_MAIL_ALLOWLIST')`) and `mailpit` (host `mailpit`, port 1025, fixed).
- `app/Providers/AppServiceProvider.php` — registers the transport when the mail manager is first resolved.
  `guardUnsafeConfiguration()` has two new refusals: `MAIL_MAILER=allowlist` outside `local` / `testing`, and, in
  `APP_ENV=local`, a default mailer that would deliver every mail (smtp with a login or `MAIL_URL`, ses, postmark,
  resend, sendmail, failover). Allowed in local: `allowlist`, smtp without a login (Mailpit), `log`, `array`.
- `app/Console/Commands/MailTest.php` — with the allowlist mailer it prints whether the address really goes out or
  only reaches Mailpit, the entries that were ignored, and the server of the mailer it took.
- `.env.example` — commented block with the Brevo lines (no values).
- tests: `tests/Unit/Mail/RecipientAllowlistTest.php` (23), `tests/Feature/Mail/AllowlistMailerTest.php` (14),
  `tests/Feature/Mail/LocalMailGuardTest.php` (17, one boots real processes).

## How a developer switches it on (local `.env`, never committed)
`MAIL_MAILER=allowlist`, `MAIL_HOST=smtp-relay.brevo.com`, `MAIL_PORT=587`, `MAIL_USERNAME` = "Login" of Brevo ›
Settings › SMTP & API, `MAIL_PASSWORD` = SMTP key, `MAIL_FROM_ADDRESS` = the sender verified in Brevo,
`STH_MAIL_ALLOWLIST=you@gmail.com,you+*@gmail.com`. Then `reload.bat` and `docker compose restart queue scheduler`
(both read `.env` only when they start). One Gmail inbox can play several accounts through plus-addresses. For a
real code instead of "any six digits": `STH_OTP_TEST_MODE=false`. Switch while the `jobs` table is empty: a queued
mailable (the OTP mail) remembers the mailer name it was queued with.
A one-off test without touching `.env`: `docker compose run --rm --no-deps --env-from-file <file outside the repo>
-e MAIL_MAILER=allowlist -e STH_MAIL_ALLOWLIST=<address> app php artisan sth:mail-test <address>` (`.gitignore`
only covers `.env`, `.env.backup`, `.env.production`).

## Commands verified
- RED first, twice: the allowlist tests failed on the missing classes; the seven refusal cases and the real-process
  case of `LocalMailGuardTest` failed before the local guard existed. GREEN: `tests/Unit/Mail tests/Feature/Mail
  tests/Feature/Deploy tests/Feature/Auth/OtpTestModeGuardTest.php` → 69 passed (166 assertions).
- Running dev stack, `.env` untouched, a provider host that does not exist on purpose (`-e MAIL_HOST=smtp.invalid`):
  with `MAIL_MAILER=allowlist` an unlisted address → exit 0, mail in Mailpit; a listed address → "Gagal mengirim" (it
  took the real route); a login under `MAIL_MAILER=smtp` → the app refuses to boot with the D-121 message. The stack
  itself (smtp, Mailpit, no login) boots and serves pages. No real mail was sent by the agent.
- The lead's own first test (before this code, values passed to one command only): `sth:mail-test` through Brevo →
  accepted and received in Gmail.

## What the first real mail showed (Brevo free plan, Gmail sender)
- From address rewritten to `<name>@<id>.brevosend.com`; the sender name stays. No Brevo logo in a plain-text mail
  (an HTML notification mail is not tried yet). Transactional sending worked without an activation ticket.
- Gmail shows "Berhenti berlangganan": Brevo adds a list-unsubscribe header to transactional mail (help article
  19100260472850; replacing it needs an Enterprise plan). A recipient who uses it is blocked by Brevo, OTP included.
- Link rewriting confirmed by the lead on the first HTML notification (hover over "Bukan saya, bekukan akun"): the
  button points at a `…sendibt3.com/…` address, while the fallback line under it still prints the app's own URL.
  The OTP mail and the notification carry no Brevo logo.
- Not seen yet: inbox or spam on other providers.

## Assumptions / deviations
- A mail with one unlisted recipient stays in Mailpit as a whole. `mailpit` host and port are fixed in config.
- The local guard looks at the default mailer only; no code names another mailer today.

## Requests for other owners
- @I `tests/E2E/compose.e2e.yaml`: the E2E project reads the repo's `.env` (bind mount). The local guard already
  keeps it from a real provider (it runs with `APP_ENV=local`); pinning `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit` on
  its `app` service would make the scenario independent of a developer's allowlist. Not changed by me.
- @I `docs/DEPLOY.md` §9: no Brevo path yet, and path A (Google Workspace) is not an option for the school (lead).
- @lead: before a production provider is chosen, decide on the unsubscribe header, the tracking links and the stored
  mail previews (DECISIONS D-121, "Yang diketahui tentang Brevo").

## Follow-ups (not done)
- Real flows through the allowlist (activation with a real code, one registration end to end), then the notification
  work: wording, which notices stay mail, a daily quota guard that keeps room for OTP, failed mails visible to Admin.
- `diskusi-email-sth.md` (the team paper, outside git, in the previous checkout) still describes Resend.

## TEST-RESULTS
- Before EM1: `main` at `53614e1` = the code of QA8, whose full run was 2372 passed, 1 skipped (14125 assertions).
- After the allowlist (before the local guard): `php artisan test --parallel --processes=4` → **2409 passed, 1 skipped
  (14232 assertions)**, exit 0 (575 s; +37); `pint --test` → PASS (669 files).
- After the local guard (final code): `php artisan test --parallel --processes=4` → **2426 passed, 1 skipped (14259
  assertions)**, exit 0 (554 s; +17 = `LocalMailGuardTest`); `pint --test` → PASS (670 files).
- Not run: the email scenario and the Playwright smoke (backlog #46); `npm run build` (no front-end change).
