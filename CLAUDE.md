# CLAUDE.md — SMAGA Talent Hub (STH)

Context and rules for everyone working in this repo, human or AI agent. Read this first, then the
files in §2. Keep §10 Status current. Non-Claude agents: `AGENTS.md` points here.

## 1. What STH is
STH is the web app of **SMA Negeri 3 Semarang (SMAGA)** for talent scouting (*penjaringan talenta*) and
the whole competition flow: the school publishes a verified **lomba** catalog → students register
(*individu/tim*) → **Kepala Sekolah** approves → **Tata Usaha** issues the *surat tugas* PDFs → students
report results (*laporan hasil*) → **Kesiswaan** verifies → points, cumulative ranking, portfolio,
**Humas** publication, public achievement pages.
Production: `https://prestasi.sman3-smg.sch.id`, Docker behind the school's Apache 2.4.29 on Ubuntu 18.04
(no OS upgrade possible, main school site on the same host). Stack: Laravel 13 · PHP 8.4 · MySQL 8.4.

Roles (spatie/laravel-permission, guard `web`). Kepala Sekolah and Admin are separate accounts (D-01).

| role key | who | main jobs |
|---|---|---|
| `siswa` | student | catalog, *usulan lomba*, register, confirm team invites, report results, portfolio, talent profile |
| `guru` | guru pembimbing / pembina | sees own *binaan*, uploads lomba info |
| `kesiswaan` | student affairs | manage + verify lomba uploads, verify results, talent pipeline, student import |
| `kepala_sekolah` | principal | approve/reject every registration, dashboard |
| `tata_usaha` | school admin office (TU) | type each letter's full number, generate, print and send the surat tugas PDFs |
| `humas` | public relations | publication queue with the data for the flyer Humas designs itself (D-107), *apresiasi* |
| `admin` | operator | everything: users, import, settings, signature image, replace the Kepsek account (all audited) |

Students log in with their NISN and, once their activation is finished, also with the email they bound
(D-78, D-137); the NIS is stored only (import matching, the student letter); staff with `username` or email. Public pages (no login): prestasi overview, per-*bidang*
drill-down, winners per lomba, cumulative ranking; only name + class + verified achievements, gated by
consent flags (D-16, SECURITY §3m-7).

## 2. Sources of truth (read before changing behaviour)
| file | content |
|---|---|
| `docs/DECISIONS.md` | decision log D-01…D-138 (D-135…D-138 = §D15, the school's feedback of 2026-10-12 (FS1): package A = D-135…D-136: three new names, the Admin menu item Database prestasi, the Excel without Kategori, oldest-first pending lomba, red count badges; package D = D-137…D-138: students log in with the NISN only and see the NISN instead of the NIS, one initial password for every student from config, replaced at the first login, no "Lewati"; D-130…D-134 = §D14, the demo and the joint review of 2026-10-11 (RV1): open mode of the local mail allowlist, NIS in full for Kesiswaan and Admin, the Kepala Sekolah may replace the guru pembimbing with the approval and the approval notice names the guru, student letter centred, the replaced guru is told nothing; D-122…D-129 = §D13, the lead's mail and notification test of 2026-10-09 (EM2): publication schedule = reminder only, appreciation start time and picker, "Lainnya" field, lomba change notice, live bell and counts, confirm dialogs, mail templates; §D13 also carries a dated addendum of Brevo facts for D-121; D-121 = §D12, real mail from a local stack: Brevo + allowlist, *sementara*; D-45…D-61 = backlog wave + W4 product choices, *sementara*; D-62…D-65 = security hardening, *final*; D-66…D-77 = Q1 security/performance pass and the lead's follow-up choices; D-78…D-91 = §D6, the lead's choices after the hand test of 2026-10-04; D-92…D-102 = §D7, the rules changed by the fixes after the QA pass of 2026-10-05; D-103…D-108 = §D8, the lead's six requests of 2026-10-06 (wave M3) and the schema change of QA4; D-109…D-112 = §D9, the lead's answers after the retest of M3 on 2026-10-07 (QA5); D-113…D-116 = §D10, the explore round after PR 14 (QA6); D-117…D-120 = §D11, the visual direction v3 "Tinta dan Sampul", lomba covers, the staff copy rule and the school crest as the brand mark (UI3)), U-01…U-06, open questions §F. **Wins over everything.** |
| `docs/ARCHITECTURE.md` | binding contract: data model, enums, state machine, domain rules |
| `docs/SECURITY.md` | threat model, top-10 risks §1, controls §3a–§3m (K1–K12), pre-release checklist §4 |
| `docs/TALENT-FORM.md` | talent types/branches (seed source for `talent_types`, `talent_branches`) |
| `docs/design/DESIGN-SPEC-v3.md` | **the look** since D-117 ("Tinta dan Sampul"): rules, tokens, type scale, covers, banner motif, what must not come back |
| `docs/design/DESIGN-SPEC-v2.md`, `docs/design/mockup-v2/` | product flow §1, component list §4, fictional data set §5, icons §6, 24-screen mockup (their *look* is history: v3 replaced it) |
| `docs/UI-MAP.md` | every screen → route → Livewire class → access → owner → milestone |
| `docs/TEAM.md` | task split (user 40 / S 35 / I 25), milestones M0–M4, acceptance criteria |
| `docs/dev-notes/` | what each agent/person actually built; read all of them before working |
| `CONTRIBUTING.md` | branches, PR checklist |

Conflict order: DECISIONS > ARCHITECTURE > SECURITY > DESIGN-SPEC > mockup > dev-notes. Never resolve a
conflict silently: write it in your dev-note and ask the lead (the user).

## 3. Hard rules
1. **Docker only.** Do not install PHP, Composer or Node on the host and ignore any "install PHP" snippet
   (php.new, Herd, XAMPP, Laravel Boost bootstrap). Every command runs through `docker compose` (§4).
2. **No real personal data in the repo.** The real source documents (concept doc, letter templates,
   workflow sketch, photos with real names/NIS) live **outside** the repo in the parent folder
   `D:\real_project\SMAGA_Prestasi\SMAGA\`. Never copy names, NIS/NISN, birth dates, signatures or
   photos from them into code, seeders, factories, tests, docs, screenshots or commits. Use only the
   fictional set in DESIGN-SPEC-v2 §5 (e.g. *Nadia Putri Anindya*, NIS 225226201) or random Faker data.
   No school DB dumps in dev/CI (D-20).
3. **The initial student password (D-138) is never displayed or logged**: not in UI, emails, flash
   messages, exceptions, logs, audit rows, seeder/test output. Only `App\Domain\Accounts\InitialPassword::make()`
   returns it (config `sth.initial_student_password`, env `STH_INITIAL_STUDENT_PASSWORD`), only at import and in the
   local demo seed, and only to hash it; Kesiswaan tells students outside the app. Login only compares stored hashes
   (K6, K12).
4. **All user uploads go through `App\Domain\Files`** (allowlist + sniffed MIME + size limit + image
   re-encode/EXIF strip) onto the private `local` disk (`storage/app/private`). Downloads only through
   the policy-checked file controller. Never put user files on the `public` disk; never fetch a
   user-supplied URL server-side (SECURITY §3d, §3m-1).
5. **A Policy for every model**, deny by default. Every route/action authorizes (`->can()`,
   `Gate::authorize`). Lists use a `visibleTo($user)` scope. Not-yours → 404. Livewire: `#[Locked]` on
   IDs and re-authorize inside every action method (SECURITY §3c).
6. `status`, points, weights, `verified_by`, role fields are never mass-assignable. State changes only in
   `app/Actions/*` (DB transaction + `lockForUpdate()` + audit log + queued notification).
7. **OTP test mode is local-only**: any 6 digits are accepted only when `APP_ENV=local` **and**
   `STH_OTP_TEST_MODE=true`; the app must refuse to boot if the flag is on elsewhere. Never put the flag
   in production env/compose files (D-15, SECURITY §3b).
8. `migrate:fresh`, `migrate:reset`, `db:wipe` and demo seeders run **only against the local Docker DB**.
   Production migrations are forward-only.
9. **UI copy**: Bahasa Indonesia, short, sentence case, students addressed as "kamu", **staff never** (guru,
   Kesiswaan, Kepala Sekolah, TU, Humas, Admin: a sentence without a pronoun, "Anda" only where it breaks without
   one; D-119), buttons are verbs, no emoji, no dark theme, decimal comma (`1,00`), no internal codes such as
   "(D-08)" on a screen. Everyday words, not formal ones: **upload, download,
   link, preview** (with prefixes: di-upload, men-download), never unggah, unduh, tautan, pratinjau
   (D-89; also in mails, validation messages and tests). Code, identifiers, comments, commits: English
   (Indonesian domain terms are fine; existing identifiers such as the tab key `unggahan` stay).
10. **Tests are required**: every domain rule, Action, Policy and route gets Pest tests, including the
    forbidden cases (role × route matrix). Red tests or failing Pint = not done.
11. **AI agents never `git commit`/`push`/switch branches unless the user asks in chat.** Only edit files
    your area owns (§11); request other changes in your dev-note.
12. Never reintroduce a superseded decision (§8).

## 4. How to run (Docker only)
Dev stack (`compose.yaml`, every port bound to 127.0.0.1):

| service | what | URL / port |
|---|---|---|
| `app` | php-fpm 8.4, `docker/php/Dockerfile` target `dev` (root, dev only); reads the bind-mounted `.env` (no `env_file`); dev-only `docker/php/php.dev.ini` + pool env `docker/php/fpm.dev.conf` (see "Page speed" below) | – |
| `web` | nginx 1.27 → `app:9000` | http://127.0.0.1:8080 |
| `mysql` | MySQL 8.4, DB `sth` (dev credentials in `compose.yaml`) | 127.0.0.1:3307 |
| `queue` | `php artisan queue:work --queue=otp,mail,default --tries=3 --backoff=10` | – |
| `scheduler` | `php artisan schedule:work` | – |
| `mailpit` | catches all dev mail | http://127.0.0.1:8025 |
| `node` | profile `frontend`: `npm install && npm run dev` (Vite HMR) | 127.0.0.1:5173 |

First run (PowerShell or Git Bash, repo root):
```sh
cp .env.example .env                                   # PowerShell: Copy-Item .env.example .env
docker compose build app
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d                                   # mysql, app, web, queue, scheduler, mailpit
docker compose exec app php artisan migrate --seed     # local DB only; demo data only when APP_ENV=local
docker compose run --rm --no-deps node sh -c "npm ci && npm run build"
```
One-off commands use **`docker compose run --rm --no-deps app <cmd>`**. `--no-deps` skips starting
MySQL: fine for composer, Pint and the test suite. For DB commands start MySQL first
(`docker compose up -d mysql`) or use `docker compose exec app <cmd>` while the stack runs.
**Demo files (D-106):** a local demo seed (`APP_ENV=local`) now renders about 98 realistic sample PDFs and draws 7
cover posters (D-118; `--lengkapi` also adds the posters to an already seeded database) for the demo files, about 30 s more on the lead's laptop; `STH_DEMO_FILES=placeholder` in the command's process env skips
that and keeps the 1 kB placeholders. The test suite always keeps the placeholders.
**Test safety:** compose does not inject `.env` as process env and every `<env>` in `phpunit.xml` has
`force="true"`, so `php artisan test` always runs on in-memory SQLite (`APP_ENV=testing`) and can never
touch or wipe the dev MySQL DB. Keep it that way: never add `env_file: .env` to `compose.yaml` and never
drop `force="true"`. Second guard (D-91): `Tests\TestCase::createApplication()` refuses to run when the
config is cached or `APP_ENV` is not `testing`. **Never run `php artisan config:cache` or `optimize` by
hand in the repo**: a cached config in `bootstrap/cache/` carries `APP_ENV=local` and the dev MySQL
connection into the test process, where `phpunit.xml` can no longer override it.
**Page speed on a Windows drive (PERF1, D-91):** the repo is bind-mounted from Windows, where every file
check costs about 6 ms. So in dev opcache does not re-check PHP files (`docker/php/php.dev.ini`), and the
php-fpm workers read a cached config and keep compiled Blade views in the container's own filesystem
(`/tmp/sth-cache`; pool `env[...]` in `docker/php/fpm.dev.conf`, which only php-fpm sees, never the command
line, the tests, `queue` or `scheduler`). The price: **after editing a `.php`, `.blade.php` or
`config/*.php` file or `.env`, run `reload.bat`** (= `docker compose exec -T app sh
docker/php/dev-cache.sh`; `start.bat` runs it with `--force`). It empties the compiled views, rebuilds
the config cache when `.env` or `config/` is newer, and reloads php-fpm. CSS and JS do not need it.

| task | command |
|---|---|
| all tests | `docker compose run --rm --no-deps app php artisan test` |
| filtered | `docker compose run --rm --no-deps app php artisan test --filter=PointCalculator` |
| format / check | `docker compose run --rm --no-deps app ./vendor/bin/pint` · `... ./vendor/bin/pint --test` |
| add a package | `docker compose run --rm --no-deps app composer require vendor/package` |
| security audit | `docker compose run --rm --no-deps app composer audit` |
| generators | `docker compose run --rm --no-deps app php artisan make:model Foo -mf` (then add `declare(strict_types=1);`) |
| migrate | `docker compose exec app php artisan migrate` |
| reset local DB | `docker compose exec app php artisan migrate:fresh --seed` (local only) |
| demo documents (local only, refuses unless `APP_ENV=local`) | `docker compose exec -T app php artisan sth:demo:refresh-files` (replaces the bytes of the `files` rows under `demo/` in the existing DB, safe to repeat; `--force` all again · `--berkas="piagam-*"` by name · `-v` one line per file · `--lengkapi` also adds the missing research report row) |
| routes | `docker compose run --rm --no-deps app php artisan route:list --except-vendor` |
| assets | `docker compose run --rm --no-deps node sh -c "npm ci && npm run build"` · HMR: `docker compose --profile frontend up -d node` |
| reload PHP after an edit (dev) | `reload.bat` · `docker compose exec -T app sh docker/php/dev-cache.sh` (config cache for php-fpm only; never `config:cache` by hand) |
| logs | `docker compose logs -f app queue scheduler` |
| validate compose | `docker compose config -q` · prod: `docker compose -f docker/compose.prod.yaml config -q` |
| prod image | `docker build -f docker/Dockerfile.prod -t sth-app:local .` (`--target web` for nginx) |
| E2E smoke (Playwright, isolated project `sth-e2e`) | `docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml up -d --wait mysql app web` → `… exec -T app sh tests/E2E/support/setup-app.sh` → `… run --rm e2e` → `… down -v` (DEPLOY §18) |
| mail check | `docker compose exec app php artisan sth:mail-test you@example.test` (dev: Mailpit; prod: DEPLOY §9; with `MAIL_MAILER=allowlist` it says whether that address really goes out) |
| real mail from a local stack (optional, D-121) | in the local `.env`: `MAIL_MAILER=allowlist`, the provider's `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` = the verified sender, `STH_MAIL_ALLOWLIST=you@gmail.com,you+*@gmail.com` → `reload.bat` → `docker compose restart queue scheduler` (steps: `docs/dev-notes/EM1.md`) |
| email scenario (every role's mails end to end, isolated project `sth-e2e`, ~3 min, not in CI) | E2E `up … mysql app web mailpit` → `… exec -T app sh tests/E2E/support/setup-app.sh` → `… exec -T app php tests/E2E/support/email-scenario.php` → `… down -v` (DEPLOY §18) |

Notes: tests stay SQLite-compatible (MySQL-only behaviour gets its own marked test). Git Bash rewrites
paths like `/var/www/html`: prefix `MSYS_NO_PATHCONV=1` or use PowerShell. On Linux, container-created
files are root-owned: `sudo chown -R "$USER": .`. Keep LF line endings.
CI must be green before a merge: `.github/workflows/ci.yml` (every PR, by hand, weekly: PHP = validate, Pint, Composer +
npm audit, Pest, boot guard; gitleaks; E2E) and `.github/workflows/docker.yml` (production images + Trivy: on a PR that
touches `docker/`, the lock files or the Trivy settings, weekly and by hand; run it by hand before a release). Since
2026-10-08 neither runs again on the push to `main` after a merge (the PR run already tested the merge result; `main` has
no branch protection on this plan, so nothing is a "required check"). Read a failed run with `gh run view <id> --log-failed`. Dependabot (`.github/dependabot.yml`) only brings
digest/patch updates for the `php`, `node` and `mysql` images, and nginx stays on the stable (even) line. **Never merge a
Dependabot PR with red checks.** A PHP minor, Node major or MySQL upgrade is a planned task that changes the dev + prod
image, CI `PHP_VERSION`/`NODE_VERSION` and the composer platform together, with a full test run (PHP 8.5 has OPcache
built in, so `docker-php-ext-install opcache` fails; MySQL 9.x are short-lived Innovation releases).
When the `docker` job fails on Trivy because a base image has a new fixable CVE that upstream has not rebuilt yet, add
the package to the `apk upgrade` line in the `web` stage of `docker/Dockerfile.prod`, or to the `apt-get install
--only-upgrade` line in the `app` stage (Debian; added 2026-10-06 for `libpcre2-8-0` and the perl packages), or bump
the digest, and run the same Trivy command locally before pushing (steps: DEPLOY §18).
Production (image, `docker/compose.prod.yaml`, Apache vhost, deploy/backup/restore, CI) is documented in
**`docs/DEPLOY.md`** (owner I). Prod facts that affect code: queues `otp`, `mail`, `default`; the runtime
DB user `sth_app` has per-table DML only (no DDL, no `TRUNCATE`: use `delete()`; `activity_log` and `audit_seals` are
INSERT/SELECT only, `migrations` SELECT; `scripts/deploy/grants.sh` grants new tables after each migrate); web on `127.0.0.1:8080`.

## 5. Architecture map (details: ARCHITECTURE.md)
Installed (composer.lock): Laravel 13.34, PHP 8.4.26, Livewire **4.4**, Fortify 1.40, spatie/permission
8.3, spatie/activitylog 5.1, spatie/simple-excel 3.10, dompdf **3.1.6** (barryvdh 3.1), Intervention
Image **4.3**, Pest 4.7, Tailwind 4 + Vite 8. Queue/cache/sessions: `database`. Locale `id`, `Asia/Jakarta`.

```
app/Enums/           27 string-backed enums, label() in Bahasa Indonesia, HasOptions (values/options)
app/Models/          23 models, HasUlids on domain tables, explicit $fillable, scopes (published(), …)
app/Domain/<Area>/   pure PHP, no HTTP: Points, Deadlines, Archive, Accounts, Letters, Files,
                     Registrations (state machine), Leaderboard (Ranker), Support (Decimal, Dates)
app/Actions/<Area>/  one use case per class (Accounts, Competitions, Registrations, Reports, Letters, Students, …)
app/Policies/        one per model, deny by default     app/Http/  thin controllers, middleware
app/Livewire/<Role>/ full-page screens per role, mirror the mockup (docs/UI-MAP.md); Publik/Data = public data
app/Providers/       AppServiceProvider, FortifyServiceProvider, NavigationServiceProvider (the one resolver of the
                     sidebar counts, D-100), ErrorPageServiceProvider (links of the error pages, redirect after login, D-101)
app/Support/Navigation.php        sidebar menus per role; badge keys, counts come from the resolver (§7); `BELL` = the
                                  key of the top bar bell (not a menu key, D-127)
app/Support/Sampul.php            cover of a lomba: poster address or template drawing class (§7, D-118)
app/Support/FirstName.php         the name a person is called by (mail greeting, "Halo, …" on the student home; D-129)
resources/views/vendor/{notifications,mail}   the mail templates + theme sth.css (§6, D-129)
app/Support/Leaderboard/PersonalRank.php   a student's own rank among active students (D-37)
resources/views/components/sth/   design-system components <x-sth.*>; layouts in resources/views/layouts
routes/web.php       public pages + auth       routes/siswa.php  /siswa, names siswa.*
routes/staff.php     guru, kesiswaan, kepsek, tu, humas (own prefix each)
routes/admin.php     /admin, names admin.*     routes/dev.php    /_dev, loaded only when APP_ENV=local
routes/console.php   scheduled commands (archive job, cleanup)
tests/Unit/Domain    tests/Feature/<Area> (HTTP, Livewire, policies, DataLayer, Accounts)
docker/, scripts/deploy/, .github/workflows/ci.yml   dev + prod images, deploy/backup, CI (owner I)
```
Area route files are loaded in `bootstrap/app.php` (`withRouting(then: …)`) so teams don't edit the
same file. Middleware (bootstrap/app.php): aliases `active` (disabled/archived users lose the session),
`activated` (first-login activation done), `staff.2fa`, `role`, `permission`; groups
**`area.siswa`** = auth, active, activated, role:siswa and **`area.staff`** = auth, active, activated,
staff.2fa (add `role:…` per area). Every route also authorizes through a policy. `staff.2fa` is
enforced everywhere except `APP_ENV=local` (`STH_REQUIRE_STAFF_2FA`, `config('fortify.sth.*')`).
TOTP is staff only (D-66): `RestrictTwoFactorToStaff` (in the `web` group) answers 404 for a student account on
every `two-factor.*` management route, and a student login never goes to `/two-factor-challenge`.
`POST /user/confirm-password` allows 5 tries per minute per account (D-67).
Global middleware (appended in `bootstrap/app.php`): `SecurityHeaders`, and `PreventCredentialPageCaching`, which puts
`Cache-Control: no-store` on every answer for `/login`, `/aktivasi`, `/lupa-kata-sandi`, `/user/confirm-password`,
`/two-factor-challenge` and `/akun/2fa` (`PreventCredentialPageCaching::PATHS`; add a new page that shows or takes a
password, a code or a 2FA secret there, D-93). Every throttled route has its own counter: write
`throttle:N,M,<prefix>` (QA5); a bare `throttle:N,M` is keyed by user / IP only and shares its counter with every other
such route (the public pages once used up the QR letter check this way).
`GET /notifikasi/jumlah` (`notifikasi.jumlah`, `NotificationCountController`, D-127) is the one address an open page
asks for fresh counts: JSON `{"badges": {key: count}}` = the bell (`Navigation::BELL`) + the sidebar counts of the
user's own menus (`NavigationServiceProvider::liveCounts()`, same policy checks and queries as the sidebar), numbers
only. Middleware of the Notifikasi page (auth, active, activated, staff.2fa) + `throttle:30,1,lonceng`; `no-store`; not
audited; `reflash()` (not a page view); a request that does not ask for JSON is redirected to `notifikasi.index`, so it
is never a page to land on. `config('sth.counts.poll_seconds')` / `STH_COUNTS_POLL_SECONDS` (`config/sth.php`: 60;
1–29 read as 30, above 3600 read as 3600, `MIN_SECONDS` / `MAX_SECONDS`; 0 = no page asks by itself and is the only
way to switch the checks off), read through `NotificationCountController::pollSeconds()` / `pollUrl()`.
`RememberLoggedOutAccount` (first in the `web` group, so it also sees a logout done by a later middleware) keeps the id
of the account that logged out in the new guest session (`sth.logged_out_user`); when a **different** account logs in
next on that browser, the intended address is dropped and it goes to its beranda (listeners for `Logout` / `Login` in
`FortifyServiceProvider`, D-102). A test that logs one account out and another in must not expect the intended URL.
Auth routes (routes/web.php, Fortify + Livewire `App\Livewire\Auth\*`): `/login`, `POST /logout` (`logout`),
`/two-factor-challenge`, `/user/confirm-password`, `/aktivasi` (`aktivasi`), `/lupa-kata-sandi`
(`password.request`), `/akun/2fa` (`two-factor.setup`). `GET /logout` (`logout.get`) only redirects (signed in →
`home`, guest → `login`) and never ends a session (D-101). Fortify `home` = `/beranda`, which redirects to
the first existing `<area>.beranda` route of the user's role (`siswa`, `kesiswaan`, `kepsek`, `tu`,
`humas`, `guru`, `admin`): every area must define its `.beranda` route. A GET that answers 403 or 404 on the very
first request after a login is redirected to `home` (`ErrorPageServiceProvider::afterLogin()`, D-101): a test that
logs in through `POST /login` and expects a 403/404 on the next GET would see that redirect.

- **Keys**: domain tables use ULIDs (`$table->ulid('id')->primary()`, `HasUlids`); `users.id` stays
  bigint (Fortify, sessions, spatie). Only ULIDs appear in URLs.
- **Enums** (values exactly as ARCHITECTURE §5): `Bidang` sains/riset/seni_budaya/olahraga · `Level` ·
  `WeightCategory` A–E · `ResultType` · `UserStatus` pending_activation/active/disabled/archived ·
  `CompetitionStatus` · `RegistrationStatus` (`phase()`, `tone()`) · `ReportStatus` · `TalentStatus` · …
- **Competition**: every create, by any role (siswa *usulan*, guru, staff, even Kesiswaan), starts at
  `menunggu_verifikasi`; only Kesiswaan/Admin publish it, verifier + time audited (D-27). Has
  `registration_url` (D-28). URLs (`website_url`, `registration_url`; the usulan sheets and the Kesiswaan drawer use
  the same rule): cleaned first (`ExternalUrl::normalize()`: whitespace of any kind at both ends, zero-width
  characters and text-direction marks anywhere; nothing is added, no scheme), then validated `https` with one
  sentence per refusal reason (`ExternalUrl::problem()`, `passes()` = no problem; the accepted set must not change,
  `ExternalUrlTest` compares it with a copy of the old rule), never fetched (lead's mail test of 2026-10-09, no
  decision number).
  `organizer_type_other` (D-125) = the kind typed for "Jenis penyelenggara: Lainnya": max 60, kept only for that
  type (`CreateCompetition::normalize()`), may be empty in the queue, required to publish
  (`VerifyCompetition::missingFields()`), shown through `Competition::organizerTypeLabel()` ("Lainnya (Sanggar
  seni)"), never a weight field. Date rules in one place,
  `App\Actions\Competitions\CompetitionDates` (D-98): `starts_on` is never before `registration_closes_at` (create,
  update, publish); publishing a lomba whose registration deadline or final date has passed needs an explicit yes
  (`VerifyCompetition::handle(…, bool $confirmPastDates = false)`, error key `CompetitionDates::PAST_KEY`); saving
  without publishing asks nothing. The rejection reason is **optional** (D-103; no minimum, at most 1.000
  characters, an empty one is stored as null): the submitter is told either way, with the reason or with
  `CompetitionRejected::NO_REASON` (`CompetitionRejected::for(Competition, ?string)`). `MIN_REJECT_NOTE` is gone.
  A **published** lomba whose facts change on "Ubah lomba" (batas daftar, tanggal, pelaksanaan, tempat, peserta, the
  two links, juknis; `App\Actions\Competitions\CompetitionChange::FACTS`, compared as a reader sees them) tells the
  people attached to it, one notice per save per person (D-126, *sementara*): batas daftar and peserta → the
  students of teams in `menunggu_konfirmasi`; the other facts → students and guru pembimbing of registrations up to
  `berlangsung`, a new final date also `menunggu_laporan` (`CompetitionChanged`, `BinaanCompetitionChanged`; never the
  actor, never a declined member, never all students, nothing for `menunggu_verifikasi`). Audit
  `competition.facts_changed` with the changed fact keys and counts only; above `MAIL_LIMIT` = 50 recipients the
  notice is bell only; no sentence prints a web address ("Link registrasi diganti."; "Tempat" is printed as typed,
  but with a web address in it, `NoWebAddress::found()`, only as "Tempat diganti."; the field itself has no address
  rule and its save is never refused). A new lomba field is a fact only when it is added to `FACTS`,
  `snapshot()`, `statusesFor()` and `line()`. The info alert at the top of "Ubah lomba" counts the registered students
  (`attachedStudents()`: members nobody declined, registrations up to `berlangsung`) and says in two sentences which
  facts go to them and their guru and which only to teams still waiting for answers; which of them hear a fact is
  `statusesFor()`. A "Periksa lomba" drawer whose lomba a colleague published meanwhile does not save on the first
  "Simpan" (it would notify without that alert): `Kesiswaan\Lomba\Form::changedElsewhere($competition, bool
  $expectsQueued)` (`save()` passes `formMode === 'verify'`, `saveAndPublish()` true, `open()` false) turns it into
  "Ubah lomba" with a warning toast, and the second click saves and notifies.
- **Registration state machine** (`RegistrationStatus`, 6-phase tracker *Jejak Prestasi*):
  `menunggu_konfirmasi` (1 Daftar) → `menunggu_kepsek` (2 Persetujuan) → `disetujui` | `ditolak`
  (note required) → `surat_terbit` (3 Surat tugas) → `berlangsung` (4 Lomba, scheduler on `starts_on`)
  → `menunggu_laporan` (5, after `final_on`) → `laporan_dikirim` → `selesai` (6 Poin & apresiasi) or back
  to `menunggu_laporan` on *perlu revisi*; `dibatalkan` = leader cancels from `menunggu_konfirmasi` or
  `menunggu_kepsek` (lead, 2026-10-01). Transitions are defined once in
  `App\Domain\Registrations\RegistrationStateMachine` (`assertTransition()`, `phaseFor()`). Team
  members pick from existing accounts, each confirms personally; roster locks after approval. The ketua may withdraw an
  invitation nobody answered while the team waits (`WithdrawInvitation`, D-115; on the screen behind the dialog
  `batal-undangan`, D-128); the member search marks a student who has not activated the account.
  **Guru pembimbing** (D-35, D-132): the student must choose one, and with the approval the Kepala Sekolah may put
  another in that place: `ApproveRegistration::handle(User, Registration, ?string $note = null, int|string|null
  $mentorId = null)` (null, '' or the guru the registration has = the choice stays; `RegistrationPolicy::changeMentor`
  = `decide`; a choice that is not a guru who can log in → `ValidationException` key `mentor`, nothing decided; audit
  `registration.mentor_changed` with user ids only). It is one decision in one transaction: there is no way to change
  the guru before the approval, apart from it, or after it (the letters name that guru). Who may be a guru pembimbing
  is one rule, `App\Actions\Registrations\MentorChoice` (`query()`, `find()`, `options()`, `name()`, `closing()`),
  shared by the student's form, `CreateRegistration` and the approval. Notices of an approval
  (`RegistrationApproved::for(Registration, audience, bool $mentorChanged = false, ?string $replacedMentorName =
  null)`): the members always read their guru ("Guru pembimbing: …", `mentorLine()`), with "(ditetapkan Kepala
  Sekolah, menggantikan …)" after a change; the guru the registration has NOW gets the notice (after a change worded
  as an appointment); the guru who was replaced is told **nothing**, neither the approval notice nor a notice of the
  change (D-134: that account never heard that a student had chosen it, a new registration is told to the Kepala
  Sekolah and the team only; `MentorReplaced` and `replacedMentorToTell()` of the first version are gone); a
  rejection still goes to the guru the student chose (D-77). Nobody is told about their own decision: an account
  that is Kepala Sekolah and guru at once (`ApplyPrincipalReplacement` keeps a guru's role) gets no guru notice of an
  approval it made. Screen: `Kepsek\Persetujuan::askMentor()` → dialog `ganti-pembimbing` (offers
  `MentorChoice::options(...)` without the guru the row has and without the account itself; two plain sentences say
  who is told: "Siswa diberi tahu bahwa pendaftarannya disetujui dan siapa guru pembimbingnya." and "Guru pengganti
  diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.", nothing about the guru the student chose) →
  `approveWithMentor()` (D-128 pattern); "Setujui" and "Setujui terpilih" pass no guru.
- **Points** (`App\Domain\Points`): Tingkat × Capaian × Penyelenggara. Tingkat: internasional 100,
  nasional 80, provinsi 60, kota 40, sekolah 20. Capaian: juara 1 / emas 1,00 · juara 2 / perak 0,85 ·
  juara 3 / perunggu 0,70 · finalis 0,50 · **semifinalis 0,30 · penghargaan khusus 0,30** · peserta 0,10.
  Penyelenggara: A 1,00 · B 0,80 · C 0,60 · D 0,40 · E 0,20. **Every team member gets full points.**
  Server-side only, from the active weight version (snapshotted on the achievement), half-up to 2
  decimals; weight changes create a new version + audited recalculation job.
- **Ranking**: cumulative SUM over all years (D-09). Public leaderboard (`/peringkat?status=aktif|alumni|semua`,
  `App\Domain\Leaderboard\RankingScope`, D-80): **Siswa aktif** by default, **Alumni** (archived accounts
  with a verified achievement) or **Semua**; ranks are computed within the shown group; an alumnus shows
  "Alumni" where the class stands, never the last class; Kelas mode is for active students only. A
  student's own rank ("peringkatmu": Beranda, Portofolio, public student page) is among **active** students
  only, always via `App\Support\Leaderboard\PersonalRank` (D-37; demo Nadia = #14).
- **Report deadline** (`App\Domain\Deadlines`): `final_on` + **45 days** (setting
  `report_deadline_days`, Admin-editable, D-25). `reports.late` belongs to the first report: the answer to a revision
  request keeps it (`SubmitReport::marksLate()`, QA8; a screen asks it before it says "ditandai terlambat"). Screens print
  the days and months of the settings, never a fixed 45 / 18.
- **Archive** (`App\Domain\Archive`): grade-12 start = 1 July of the year the student enters kelas XII;
  `archive_at` = +18 months (e.g. kelas X 2026/2027 → XII 2028-07-01 → archive 2030-01-01). Daily job
  01:00 Asia/Jakarta, idempotent; sessions revoked; **archived users cannot log in** (D-29); their
  achievements stay in ranking/public pages. Admin can also disable/archive manually with a reason (D-30). The job
  archives active and pending accounts only (`Student::scopeDueForArchive`): a disabled account stays disabled and
  anonymous on the public pages (QA9; before, it came back as a named alumnus).
- **Letters** (`App\Domain\Letters\LetterNumber`): TU types the **complete number** of each letter, e.g.
  `400.3.8/313/MRD/X/2026` (3–64 characters; letters, digits, `.` `/` `-`, spaces; at least one digit);
  unique per letter type over all letters incl. revoked ones (index (type, full_number), compared without
  case and spaces), immutable, only for `disetujui` registrations (server check). The settings formats
  are only the example under the field (D-82). One form and one "Buat PDF" per letter
  (`IssueLetters::handle(User, Registration, LetterType, LetterDraft)`), then one "Kirim" once every
  required letter exists (`LetterPolicy::requiredTypes()`, D-83). One siswa letter per registration lists
  all members + one *Surat Perintah Tugas* with a Lampiran page for the pembimbing, both laid out like the
  school's own files, with the Pemprov and school emblems from `resources/pdf/assets` (D-84); one private
  signature+stamp image (Admin) on every letter; dompdf remote/JS off; QR code → public
  `/verifikasi-surat/{code}` (every issued letter, sent or not: "Sah" or "Dicabut", D-113 *sementara*); "Cetak" = the PDF inline via `tu.surat.cetak` (`StreamLetter`, policy-checked,
  audited) (SECURITY §3g, §3m-5). The student's "Surat tugas" card (Lomba saya) lists only letters TU already
  **sent**; the siswa letter has a download, the guru letter is an information row without a link (`LetterPolicy`
  keeps that file for the guru and staff: it carries NIP and rank; D-99, the "students may not open it" part is
  *sementara*). `letters.printed` (json, QA4, D-108) keeps what the PDF printed (`LetterContent` + the letter
  template), written once by `IssueLetters` / `RevokeLetter` (`LetterPdf::printed()`); the TU preview of an issued
  letter is drawn from it (`LetterContent::fromArray()`, `LetterTemplate::fromArray()`), never again from today's
  data. It holds names, NIS and NIP, so it stays out of the activity log; letters made before it say so in the preview.
  A letter that is not issued yet takes the invitation and schedule fields of the other letter, never over a field TU
  typed for it (`SuratTugas::SHARED_FIELDS`, `$typed`, D-116); "Koreksi" on Arsip surat opens
  `tu.surat.show?koreksi=<letter>`; "Kirim" asks first once every letter exists (QA6). The student letter stands in
  the middle of the page (D-133: equal left and right margins of 61.14 pt, every block 473 pt wide, in
  `resources/pdf/layout.blade.php`); the guru letter keeps the measures of the school's Word file.
- **Accounts**: students log in by their **NISN** (10 digits) and, once activation is finished, by the bound
  email (`LoginIdentifier::resolveStudent()`, D-78, D-137); the NIS is only stored (import matching D-81, the
  student letter). A number that matches only a NIS resolves to nobody: the same answer as an unknown account
  (`AuthenticateUser::GENERIC_ERROR` "NISN atau kata sandi salah.", dummy hash, HMAC bucket). Staff by
  `users.username` or email. First login (D-79): bind email → 6-digit OTP → a **new password, required for every
  account** (D-138: `CompleteActivation::keepPassword()` / `mayKeepPassword()` and the "Lewati" button are deleted,
  not made always false; `users.temporary_password_issued_at` (D-92) still marks an Admin's temporary password for
  the wording of step 3 and is cleared by `SetNewPassword`; `activation.password_kept` exists only on rows from
  before D-138). The initial password of an imported student is the config value `sth.initial_student_password`
  (D-138), the same for everyone, hashed at import (`InitialPassword::make()`); `matches()` serves K7 detection only
  and also knows the old birth-date pattern, which survives only in accounts imported before 2026-10-12 (no mass
  reset, an Admin reset still gives a random temporary password).
  No activation code (D-14). New student
  passwords: **min 10 characters** and `App\Domain\Accounts\PasswordPolicy` rejects birth date / `sm4g4` /
  NIS / NISN / name (K5, lead 2026-10-01), the configured initial password (D-138, equality) and, for every account, the username, the email's local part and common
  passwords (built-in word list + alphabet/keyboard/digit runs, refused when fewer than 5 other letters remain:
  `password123` fails; D-94). The hint under a new-password field comes from `SetNewPassword::hint($user)`.
  Sessions (D-93): renew the id with `session()->regenerate(true)` (the old row is deleted) and only **after** the
  Action succeeded. "Buka ulang" (`ReopenActivationWindow`, K2) never shortens a deadline: the later one of the
  current deadline and now + 72 h, reason at least 10 characters (D-95). Login locks (QA10, SECURITY §3a/§3b): per account
  **and** client address (IPv6 by /64) **and** device kind (a known device has its own, `|k`), the hourly limit counts
  failures only; 30 failures of an account in 24 h close it to
  devices that never signed in to it (`KnownDevices`) and alert the Admins (`LoginAttackAlert`); every refusal names one
  time, `LoginLockout::secondsUntilAllowed()`, and a refused try counts only towards the per-address limit; a code reset, an Admin reset,
  "Buka ulang" and an unfreeze clear every lock and limit of the account (`LoginLockout::clear()`, a new generation); a
  "Lupa kata sandi" code belongs to the browser that asked (`OtpRequester`), screen limits per account + address
  (`ResetCodeLimits`), at most 20 reset codes a day per account (alert at 20 wrong ones); at most 5 attack alerts an hour; the staff TOTP challenge is per account, ends after 10
  minutes, 10 wrong codes or a password change. Status aktivasi, Kesiswaan › Talenta and Kesiswaan › Database
  prestasi show the **NISN in full** (D-131, since D-137 the NISN instead of the NIS: these screens are Kesiswaan and
  Admin only; the Status aktivasi CSV carries it too, with a hint line; the email stays masked; Profil talenta and the
  import rows to check show the NISN as well; searches by name or NISN everywhere). Import (Excel/CSV, dry-run first) by Kesiswaan and Admin
  (D-12, D-26). The yearly file may be uploaded again (D-81): a registered student (NIS **and** NISN
  match) moves to the class in the file; only class, school year, archive dates and phone are written,
  never name, birth date, password, email, status or consent. Account Actions live in
  `app/Actions/Accounts` (activation, OTP, reset, disable, archive, import).
- **Models & permissions**: status/verification/points columns are not in `$fillable`; Actions set them
  with `forceFill()`. Permissions = `App\Enums\Permission` (`competition.verify`, `registration.approve`,
  `letter.generate`, `report.verify`, `student.import`, `weight.manage`, …), matrix in
  `RoleName::defaultPermissions()`; Admin has everything **except** `registration.approve`.
- **Talent**: 6 types mapped to 4 bidang (OSN, OLPAIN → sains; Riset → riset; FLS3N, Debat →
  seni_budaya; O2SN → olahraga, D-22); a student may pick several; all active classes incl. XII (D-23).
  Two optional notes per application: `note` = for the guru pembina (internal), `student_note` = for the
  student, sent with the decision (D-85). "Simpan" keeps what was typed for the student as a draft
  (`student_note_draft`, staff only); a decision sends its note and clears the draft
  (`TalentNote::forDecision()`): a text the caller passed wins, an action without a note field (row icon, bulk
  move) sends the saved draft (*sementara*); "Tidak lolos" asks for confirmation first (D-96). A `tidak_lolos`
  application can be restored to the stage Kesiswaan chooses (D-104): `RestoreTalentApplication::handle(User,
  TalentApplication, ?string $studentNote = null, ?TalentStatus $to = null, int|string|null $mentorId = null)`, `$to`
  one of `TARGETS` (Terdaftar, Pemetaan, Seleksi, Peserta pembinaan), null = the stage before the rejection, which the
  dialog preselects; a restore into Peserta pembinaan needs a guru pembina (the given active guru, else the one on the
  row; D-109), no stage needs a date. The student may ask once per rejection (`RequestTalentReview`) and
  cannot apply again for a rejected choice (D-86). "Download data (Excel)" on Kesiswaan › Talenta first asks which
  statuses go into the file (D-104; dialog `Talenta::EXPORT_DIALOG`; `export(AdminRecipients, string $status = '')`:
  `''` = what the open tab lists, `Talenta::EXPORT_ALL`, or `TalentStatus` values joined by commas, else 404). The file
  follows the screen filters; statuses without rows are "Kosong" and cannot be ticked, and no empty file is made
  (D-111). A new or changed "Jadwal asesmen" may not lie before today; the saved date stays valid
  (`UpdateTalentAssessment::validated($details, $current)`, D-114).
- **Appreciation events**: confirmed as a whole (`appreciation_events.completed_at` / `completed_by`,
  `CompleteAppreciationEvent`); deletable only while not confirmed (`DeleteAppreciationEvent`, the
  scheduled students are told); a confirmed event takes no more prestasi (D-87). Scopes
  `AppreciationEvent::open()` / `completed()`. An event has a day (`held_on`) and a start time (`held_time`, D-123;
  null = made before 2026-10-10): the rules stay day-level and read `held_on` only, every text of "when" comes from
  `App\Actions\Publications\EventWhen` (`long` "Senin, 12 Oktober 2026, 07.00 WIB" · `sentence` "… pukul 07.00 WIB" ·
  `short` "12 Okt 2026, 07.00"; `parseTime`; one leftover: `RecordAppreciation::blockedMessage()` still prints the date
  alone, backlog #81), and `CreateAppreciationEvent::handle(User, string $name,
  DateTimeInterface|string $heldOn, string $heldTime)` needs the time ("Isi jam acara."; a passed time of today is
  accepted, a past date is not). One achievement is on at most one event (`RecordAppreciation`
  refuses it while it is scheduled elsewhere or once it was appreciated); "Lepas dari acara"
  (`ReleaseAppreciation`, `AppreciationPolicy::delete`) takes one scheduled row off an unconfirmed event and
  tells the student (D-97). "Tambah prestasi" on Apresiasi is a picker (D-124): search by student or lomba name, ticks,
  `$limit` steps (`PICK_STEP` 10, `PICK_MAX` 100), one "Masukkan" = `RecordAppreciations::handle(User,
  AppreciationEvent, iterable $achievements)`: at most 30, all or nothing in one transaction, one audit row and one
  student notice per achievement, **one** `BinaanAppreciationNotice::scheduledMany()` per guru pembimbing for all
  binaan of that call (*sementara*). `RecordAppreciation` (Publikasi panel) is its one-row entry with the same rules
  and messages. The ticks are browser input: every id is loaded again through the eligibility query, and a stale or
  forged tick writes nothing (`Apresiasi::PICK_STALE_MESSAGE`). The search box keeps the text as typed:
  `Apresiasi::updatedSearch()` never writes a trimmed value back into `$search` (Livewire would put the server's value
  into the focused input and eat the space before the next word); only `picker()` / `HumasQueries::searchAchievements()`
  trim the term. "Masukkan" asks no question (D-128 lists it).
- **Humas publication** (D-107): **STH draws no flyer.** Humas designs it in its own tools; the panel "Siapkan
  publikasi" shows "Data untuk flyer" from `App\Actions\Publications\FlyerData::of(Achievement)` (`rows` = one fact
  per line, `notes` = consent sentences outside the copied block; never points, NIS/NISN, or a student without
  publish consent) and "Salin data" (`data-sth-copy`, §7). **The schedule is only a reminder for Humas** (D-122):
  at `scheduled_at` `RemindDuePublication` (job `RemindDuePublications`, every 5 minutes) notifies every active Humas
  account once (`PublicationDue`), stamps `publications.reminded_at` (not fillable; emptied by every schedule and
  re-schedule) and audits `publication.reminded` (by = scheduler). The row stays `terjadwal`, `published_at` stays
  empty and the student is told nothing; the screens show it as "Lewat jadwal" (`Publication::isOverdue()` /
  `scopeOverdue()`, `HumasQueries::overduePublications()`, sidebar count `humas.jadwal`). Only `PublishPublication`
  ("Tandai dipublikasikan") sets `dipublikasikan` (`published_at` = the click) and tells the student; on the screen it
  sits behind the dialog `Publikasi::PUBLISH_DIALOG` (`askPublish()` validates caption + channels, `publish()`
  confirms; D-128). Every text that speaks of a student notice (the reminder `PublicationDue`, the alert "Jadwal sudah
  lewat", the hint under the date, the dialog line, the toast) promises it only when
  `PublishPublication::notifiesStudent()` is true; an alumnus with consent stays in the queue and gets none, so those
  texts leave it out (`Publikasi::panel()['notifies']`, `PublicationDue::$notifiesStudent`; D-109). Who may be named in
  the Humas queues and by the reminder:
  `App\Actions\Publications\NamedStudent` (consent, not deleted, not disabled; alumni allowed; QA10). `GenerateFlyer`, `FlyerRenderer` and `PublicationPolicy::generateFlyer` are
  deleted; `publications.flyer_file_id`, `FilePurpose::Flyer` and the flyer rule of `FilePolicy` stay for files
  made before 2026-10-06 (nothing writes or links them now; D-47, D-71 and D-75 only concern those files).
- **Files** (`App\Http\Controllers\FileController`, D-105): `files.show` answers `attachment` by default. With
  `?tampil=1` (`INLINE_PARAMETER`) it answers inline for `application/pdf` and `image/jpeg|png|webp` only, for the
  preview popup (§7). Only the inline **PDF** answer of `files.show` is relaxed (`X-Frame-Options: SAMEORIGIN`, CSP
  `default-src 'none'; frame-ancestors 'self'`, no `sandbox`); every other answer and `files.public` keep `DENY` /
  `sandbox`. HEAD = same policy check, no bytes, not audited; a preview is `file.downloaded` with `inline: true`.
  A screen builds the link with `FileController::previewUrl($file)` and `previewType($file)` (null = download only).
  The content decides a file's type: `StoreUpload` stores `original_name` with the extension of the sniffed content
  (`UploadRules::nameForContent()`, QA8: a PNG uploaded as "brosur.pdf" is "brosur.png"). `files.public` (a verified
  certificate on the public pages) needs every member of the registration to appear by name there (consent, not
  disabled, not deleted; `FilePolicy::viewPublic`, QA9) and answers `X-Robots-Tag: noindex, nofollow`.
  **Cover pictures** (D-118, *sementara*): `files.cover` (`GET /berkas/sampul/{file}`, signed-in users, `FilePolicy::view`,
  own throttle) sends only a small progressive JPEG of a `purpose = cover` file (`App\Domain\Files\CoverThumbnail`, long
  side ≤ 640 px, kept under `sampul/` on the private disk as derived data), `Cache-Control: private, max-age=86400` + ETag,
  and is **not audited**; everything else about files is unchanged. Views never call it directly: `App\Support\Sampul::poster()`
  asks `CompetitionPolicy::view` first. An intended address under `/berkas/sampul/` is dropped at login (`FortifyServiceProvider`).
- **Settings**: `Setting::get(SettingKey::…)` / `Setting::put()` (defaults in `SettingKey::defaultValue()`,
  seeded by `SettingSeeder`), exactly these keys: `report_deadline_days` (45), `archive_months_after_grade12` (18),
  `signature_file_id`, `signer_name` / `signer_rank` / `signer_nip`, `letter_format_siswa` / `letter_format_guru`
  (since D-82 only the example shown under the number field, "Contoh nomor surat" in Admin › Pengaturan),
  `public_indexing` / `public_certificates` (both false, D-38/D-39), `activation_window_days` (14, 1–30, D-54),
  `letter_template` (null = built-in texts, D-60). The password pattern and the OTP test mode are never settings
  (env flag only, D-62). Resolve `app(ReportDeadline::class)` / `app(GraduationCalculator::class)`
  (bound to the settings in `AppServiceProvider`); never `new ReportDeadline(…)` outside previews.
- **Staff roles**: Admin, Kesiswaan, Tata Usaha are granted only through `App\Actions\Roles\RequestRoleChange`
  (second Admin approves, or +24 h when the requester is the only Admin, D-46); a new account with one of these
  roles is created without a role + a pending request (D-61); Kepala Sekolah only via "Ganti Kepala Sekolah" (D-40).
- **Consent**: `students.photo_consent` / `publish_consent` change only through
  `App\Actions\Students\UpdateConsent` (Admin › Pengguna, audited; revoking publish consent rotates
  `public_slug`). Call `App\Livewire\Publik\Data\PublicAchievements::flush()` after anything that changes
  public data.
- **Seeders** (`DatabaseSeeder`): roles + permissions, 36 classrooms, talent types/branches, default
  weight version, settings, sample signature (not in production), and `DemoSeeder` **only when
  `APP_ENV=local`** (fictional data matching the mockup queues and leaderboard). The demo files are realistic
  sample documents (D-106: certificate, piagam, research report, juknis, brochure, poster, surat tugas), rendered
  after seeding by `Database\Seeders\Support\Demo\DemoFileRefresher` from `database/seeders/demo/*.blade.php` when
  `Database\Seeders\Support\DemoFiles::realistic()` says so (local, not the test process, not with
  `STH_DEMO_FILES=placeholder`). Limits that must hold: only fictional people of the demo set, invented signers
  with committee roles, no real logo, stamp or signature of any body (drawn shapes; only the school's own demo
  letters carry the two emblems of `resources/pdf/assets` and the repo's sample signature), "CONTOH" on every page,
  nothing outside `APP_ENV=local`. Tests keep the 1 kB placeholder of `DemoFiles::pdf()`.
- **Audit & jobs**: activitylog on every state change/Admin action, sealed after commit in a hash chain
  (`audit_seals` + `audit_chain_heads`, `sth:audit:verify` daily, D-63; app code only INSERTs into `activity_log`/`audit_seals`, D-51);
  notifications = queued mail + database; queues `otp`, `mail`, `default`. A bell row keeps the **path** of its target,
  not the absolute address (RV1): `App\Notifications\Channels\BellChannel` (the `database` channel, bound in
  `AppServiceProvider::register()`) cuts `data['url']` down when it starts with the app's own root, so "Buka" on Akun ›
  Notifikasi still works when the app answers under another host (a new tunnel address, a copy of the database on a
  second machine). `toArray()` keeps passing `route()`; the mail keeps the absolute address; never read a stored
  `data['url']` as absolute, and `Notifikasi::internalTarget()` keeps its host check.
- **Mail in development** (D-121, *sementara*): by default every mail stays in Mailpit. A developer who needs real
  mail sets `MAIL_MAILER=allowlist` (`App\Mail\Transport\AllowlistTransport`, mailers `allowlist` + `mailpit` in
  `config/mail.php`): a mail whose recipients are **all** on `STH_MAIL_ALLOWLIST` goes out through the `smtp` mailer
  (Brevo's SMTP relay for now), every other mail is handed to Mailpit. The scheduler and the demo data mail fictional
  `@sth.test` addresses by themselves, so `AppServiceProvider::guardUnsafeConfiguration()` refuses to boot in
  `APP_ENV=local` (the E2E project too) when the default mailer would deliver every mail: smtp with a login or
  `MAIL_URL`, ses, postmark, resend, sendmail. Allowed there: `allowlist`, smtp without a login (Mailpit), `log`,
  `array`. It also refuses `MAIL_MAILER=allowlist` outside `APP_ENV=local`. `STH_MAIL_ALLOWLIST=*` (a single star,
  D-130, *sementara*) opens the list: every address that can exist goes out, a fictional one
  (`RecipientAllowlist::isFictional()`: `.test`, `.example`, `.invalid`, `.local`, `example.com|net|org`, a host
  written as numbers) still stays in Mailpit; an entry that opens one domain is still dropped, and the star does not
  count next to an entry that was dropped (`openRefused()`: "* @gmail.com" stays closed, so a typing mistake never
  sends more mail out). Provider keys live only in the
  developer's `.env`. A mail sent BY NAME through such a mailer (a job queued before the switch, `Mail::mailer('smtp')`)
  is cancelled in `APP_ENV=local` and logged as `mail.local_mailer_blocked` (`App\Mail\LocalMailGuard`, the same predicate
  as the boot guard, QA9).

## 6. Coding conventions
- `declare(strict_types=1);` in every new PHP file; typed properties, parameters and returns; `final`
  classes for Actions and Domain services; readonly value objects; `CarbonImmutable` for dates.
- Validation: FormRequests for controllers (their `authorize()` calls a policy, never a bare
  `return true`); Livewire uses `#[Validate]`/`$this->validate()`. Use `validated()`/`safe()->only()`,
  never `$request->all()`.
- Actions: `App\Actions\<Area>\<Verb><Noun>`, one public `handle(User $actor, …)` method, authorize,
  run in `DB::transaction`, dispatch notifications after commit. Livewire components and controllers
  call Actions; they hold no business rules. **No business logic in Blade**: views only format data.
- Stale screens (QA8, QA9): a Livewire action on a record another tab, account or scheduled job may have changed
  meanwhile checks the state the screen SHOWED before it calls the policy or Action (a `#[Locked]` property filled in
  `render()`, the record's state, or the audit row of the change) and answers with a warning `sth-toast` + a refreshed
  list, never a full-screen 403/404; a record the user may never see still answers 403/404. Examples:
  `Kesiswaan\VerifikasiHasil::decidedElsewhere()`, `Kesiswaan\Talenta` (`$shownStages`), `Kepsek\Persetujuan` (`$shown`).
  A toast claims a notice only when the Action says one went out (`notifiesStudent()`, D-109).
- **A Livewire view starts with its root element** (RV1): no `@if` / `@foreach` around or before it. Livewire puts
  `wire:id` on the first tag that starts a line of the rendered view, and a directive before the root prints a marker
  in front of that tag: the id then lands on an inner element and every `wire:click` outside it does nothing, without
  a request and without an error (that is how "Ikut tim" died). Put the root first and the condition inside it. Three
  tests guard this and its relatives: `tests/Feature/Support/LivewireRootTest.php` (sources),
  `PageWiringTest.php` (opens every page each role reaches with the demo data: status 200, every `wire:` control
  inside a component, its method or property exists, every opened dialog and referenced form is on the page) and
  `ViewWiringTest.php` (the same names read from every Blade source, `data-sth-*` hooks known to `app.js`,
  `route('…')` / `@include` / `<x-sth.*>` exist, every view compiles). A new screen is covered by them without a
  change; a new menu item is crawled because the crawl starts from `Navigation`.
- Models: explicit `$fillable` (never `$guarded = []`), enum casts, `phone` encrypted;
  `Model::shouldBeStrict()` outside production (missing/discarded attributes throw).
- Numbers: points are decimal strings (`App\Domain\Support\Decimal`, bcmath), never floats; UI shows them
  with `PointFormatter` (`68`, `33,6`).
- Names: DB columns English snake_case as in ARCHITECTURE §4; enum values Indonesian as defined;
  route names `<area>.<resource>.<action>`; URL segments Indonesian (`/siswa/lomba-saya`).
- Livewire 4: full-page components registered with `Route::livewire('/uri', Component::class)`; class
  in `app/Livewire/<Role>/…`, view in `resources/views/livewire/<role>/…`.
- Lists that can grow never render every row: paginate, or use the `$limit` pattern (`STEP`/`MAX` constants,
  clamp the public `$limit` in `render()`, button "Tampilkan lebih banyak"; see `Publik\Peringkat`, D-72).
  HTML is not gzipped in production (BREACH), so page weight is real.
- Notification mails: build them with `App\Notifications\Support\PlainMailMessage`, never `new MailMessage`
  (user text must not become Markdown links/images, D-69; `MailInjectionTest` checks it). Templates (D-129, look
  *sementara*): the app's own `resources/views/vendor/notifications/email.blade.php` + components in
  `resources/views/vendor/mail/{html,text}` + theme `html/themes/sth.css` (`config('mail.markdown')`: theme `sth`,
  fixed). Every `toMail()` sets `->greeting(MailGreeting::for($notifiable))`, or `::student()` / `::staff()` when the
  audience is fixed (`App\Notifications\Support\MailGreeting`): student "Halo <nama depan>," (`App\Support\FirstName`
  = the first word that is not an abbreviation: "Drs. Bambang Hartono, M.Pd." → Bambang), staff and an account without
  a role "Yth. <nama lengkap>,", unknown "Halo,". Never a greeting built by hand, never `->salutation()` (the template
  prints no sign-off), at most one `->action()`: the button is the only link of a mail (the address under it and the
  header are text; a mail without an action has no link), no image, no remote font, one footer sentence
  (`PlainMailMessage::FOOTER`). The text part is plain text: greeting, lines, "Label: address", "--", footer; the
  template prints greeting and lines through `PlainMailMessage::plain()`, so they stay text in every compile order
  (`view:cache` included; `MailTemplateTest`). A notification with its own `toMail()` goes into `mailGreetingCases()`
  of `MailGreetingTest` (a reflection check fails otherwise). The views are read as Markdown: keep every line flush
  left and no blank line inside an HTML block. The OTP mail (`resources/views/auth/mail/otp-code*.blade.php`, plain
  Blade) and `EmailBindingNotice` follow the same greeting, card and footer; the OTP view repeats the theme values by
  hand, so change both. After editing a mail view or `config/mail.php`: `reload.bat` and restart `queue` +
  `scheduler`. Recipients: a frozen
  account gets no mail and an unverified address only gets `AdminNotice` mails (`User::routeNotificationForMail()`, D-68).
- Tests: Pest, `tests/Unit/Domain/<Area>`, `tests/Feature/<Area>`; factories produce fictional data
  only. Format with Pint (Laravel preset) before every hand-off. `Tests\TestCase` gives every Feature
  test its own empty `local` + `public` disk and PDF sandbox (`Tests\Support\TestDisks`): never use
  `Storage::fake()` (one shared folder, wiped on start, breaks concurrent runs).

## 7. Design system
- Spec: **`docs/design/DESIGN-SPEC-v3.md`** ("Tinta dan Sampul", D-117; it replaced the look of v2 on 2026-10-07):
  a calm ink frame and pictures where people browse. Ink navy `#1B2A4E` on cool neutral `#F3F5F8`; **one**
  typeface (Public Sans 400–700) on a fixed scale `text-xs 13 · sm 14 · md 15 · base 16 · lg 18 · xl 20 · 2xl 24 ·
  3xl 28 · 4xl 36 · 5xl 44`, **nothing smaller than 13px and no `text-[Npx]`** (only the scaled paper preview of a
  letter in `tata-usaha/partials` is exempt); radius 6 controls · 8 tiles · 10 cards · 4 tags; one colour = one
  meaning (status green/orange/red/gray/blue, gold only for points/medals, bidang colours, count badges red (D-136)), the frame itself is
  ink and gray; no gradients, glow shadows, blur, tilted cards or page wash; AA contrast (input border 3.6:1,
  focus ring 4.7:1); ≥ 44px touch targets on touch devices; every input has a label; status tags always carry text.
- **Covers** (D-118): `<x-sth.sampul :bidang :seed :src bentuk="lebar|tegak|utuh" tone="terang|pekat">`. A lomba
  shows its own poster (`competitions.cover_file_id`, address from `App\Support\Sampul::poster($competition)` →
  route `files.cover`; `<img data-sth-sampul>`, `app.js` drops it when the file fails to load), else one of three
  template drawings of its bidang (`.sampul-<bidang>-<1..3>`, variant from the lomba id via `Sampul::variant()`; SVG
  files in `resources/images/sampul`, hashed by Vite). **The cover stands on the left, portrait, the text beside
  it**: `<x-sth.lomba-card>` is a capsule (`bentuk="tegak"`, also the prestasi cards of Portofolio: one 3:4 box the poster fills; `LombaPresenter::card()`
  passes `id` + `poster`, give the card `:seed` and `:poster`; its grid takes the columns from its own width:
  `grid-cols-1 @min-[900px]:grid-cols-2 @min-[1360px]:grid-cols-3` inside an `@container`), and
  `<x-sth.lomba-head :bidang :seed :poster :facts>` heads Detail lomba and Undangan tim (`bentuk="utuh"`: the
  poster whole, "Perbesar" opens it in the preview popup; name and key facts beside it, the rest of the page
  below). `bentuk="lebar"` (a band; a poster whole in the middle over a blurred copy) is left for small cards
  (public "Prestasi terbaru", Humas). Public pages and Humas use the drawing only. `.spanduk` = the ink banner with the "lintasan" motif (six dots = the six steps of Jejak Prestasi) on the
  student home, the hero of the public home and the login panel (`.spanduk-tinggi`); nothing floats on or beside it
  (no sample cards).
- **Brand mark** (D-120): `<x-sth.brand>` shows the crest of SMA Negeri 3 Semarang (CSS class `.brand-mark`,
  `resources/images/brand/lambang-sma3.png`; on ink it stands on a white tile), and the tab icons in `public/` are
  the same crest. A small raster file: not above about 56px until the school gives a vector file; never redrawn or
  recoloured. The `shield` icon is an ordinary icon now (2FA, Log audit).
- Component changes of v3: tags are squared (`.chip`: tinted = status/gold, `tone="gray"` outlined, a bidang tag =
  dot + name, `.chip-wrap` may take two lines); `<x-sth.stat-tile zero="quiet|hide|show">` (quiet at 0 by default,
  `hide` for warning tiles) in `grid-cols-2 … xl:grid-cols-4`; the sidebar has no button above the menu, and no
  screen repeats a menu item as a button or link (the student's "Cari lomba" is the menu item and the search box;
  "Masuk" only in the public header; no page button repeated in its empty state); boxes of one grid are the same
  height (`auto-rows-fr`: stat tiles, bidang tiles, `<x-sth.radio-pick columns>`); the top three of a ranking carry
  gold / silver / bronze medals (`bg-medal`, `bg-medal-2`, `bg-medal-3`); pick tiles centre their text and a grid
  of long labels shows two per row on phones (`phone-columns="2"`); a box whose width depends on a side panel
  takes its columns from a container query (`@container` + `@min-[…]:grid-cols-n`: `<x-sth.facts>`, the talent
  type tiles), so no text leaves its box at 1280 / 1366 px or on a 360 px phone. Long words (QA7): `body` has
  `overflow-wrap: break-word` (a word wider than its line breaks; sizes stay as they were); a text column that shows
  text typed by people (lomba name, penyelenggara, place) and sizes itself to its content (a flex column with
  `items-start`, a flex item without `min-w-0`, an implicit grid track) also carries `[overflow-wrap:anywhere]`
  (inherited; it lowers the min-content); a one-column grid is `grid-cols-1` (= `minmax(0, 1fr)`), never an implicit
  track; `sr-only` goes on a box around a `<table>`, never on the table (a table does not shrink to 1px); a `fixed` `<x-sth.table>` gets a floor
  (`table-class="min-w-[720px]"`) so it scrolls on phones instead of squeezing a column; the tab bar uses the
  menu names ("Lomba saya", "Portofolio"). `Siswa\TurMenu::desktopTips()` / `mobileTips()` hold pixel positions
  of the sidebar and tab bar: measure again when `.nav`, `.nav-sec`, the sidebar gaps or `.tabbar` change.
- Mockup: `docs/design/mockup-v2/*.dc.html` (24 boards, `canvas.json`; layout and flow reference, **old look**), notes in
  `docs/design/mockup-v2-notes/`. Live canvas: https://claude.ai/artifact/7m2XGmACRwHFfYQ9EaeV2m.
  The `.dc.html` files are design references with inline styles: rebuild them with components and
  Tailwind, don't paste their markup.
- Tokens (Tailwind v4 `@theme`, spec colours only; `--text-*` and `--radius-*` hold the scale above) + helmet
  classes in `resources/css/app.css`; font self-hosted via `@fontsource/public-sans`. Layouts
  `<x-layouts::app|public|auth|mobile>` (Livewire: `#[Layout('layouts::app')]`), menus from
  `App\Support\Navigation`. 41 components `<x-sth.*>` (button, chip, card, stat-tile, form fields that forward
  `wire:model` + `$errors`, table, tabs, tracker, rumus-poin, lomba-card, lomba-head, sampul, modal, toast, preview, …), each documented at the top of its file; contracts in
  `docs/dev-notes/W1-D.md`. **No `style=""` and no inline JS** (CSP nonce): use tokens/`w-pct-*`,
  `data-sth-*` hooks and Livewire events `sth-modal-open|close`, `sth-toast`. Hooks added by M1 (all in
  `resources/js/app.js`): event `sth-badges {badges: {key: count}}` updates `[data-sth-badge="<key>"]` without a
  reload: the sidebar counts (keys from `Navigation::BADGES`, e.g. `tu.surat`, `humas.jadwal`) and the bell of the top
  bar (key `Navigation::BELL` = `akun.notifikasi`, D-127; its bubble is always in the DOM, hidden at 0;
  `data-sth-badge-max="99"` prints "99+", `data-sth-count` keeps the number, `[data-sth-badge-label]` gets its
  aria-label from `data-label-none` / `data-label-some`, ":n" = the number); dispatch it after an action that changes
  a queue (Akun › Notifikasi sends the bell count on every render, so no "Tandai dibaca" needs a page load); event
  `sth-form-invalid {form}` brings the error summary (`data-sth-error-summary`) or the first
  invalid field into view; `data-sth-live-search` on the top bar search form filters the list while typing
  when the form's action is the current page (sets the Livewire property named like the input, `q`; D-90).
  Since M2 the counts themselves come from **one resolver** on every page of `layouts::app`
  (`NavigationServiceProvider::badges()`, registered with `Navigation::resolveBadgesUsing()`, D-100): it counts only
  the badge keys of the rendered menu, only for a user who may open that queue, with the query the screen uses. A
  page may still pass its own `badges` (they win); a new count = a key in `Navigation` + one line in
  `NavigationServiceProvider::count()`. `app.js` also empties password fields and the account name field
  (`autocomplete="username"`: keep that attribute on a login field) after a plain form submit and on a page that Back
  or Forward brings back (D-93, D-102).
  **Live counts** (D-127): the bell of `layouts::app` carries `data-sth-counts-url` / `data-sth-counts-every` (from
  `NotificationCountController::pollUrl()` / `pollSeconds()`; absent for a guest, a preview user and when the switch is
  0; `layouts::mobile`, `auth` and `public` have no bell and never ask). `app.js` asks that address 2.5 s and 8 s after a
  Livewire action of the person (not after `wire:poll`, `$refresh`, `$set`), about every 60 s ± 10 s while the tab is
  visible and was used in the last 5 minutes, and 0.3 s after Back / Forward; the answer goes through `sth-badges`.
  Never next to a Livewire request; a running check is cancelled on a Livewire send, `livewire:navigate`, a plain form
  submit and `pagehide`; two refused answers in a row (401 / 403: session over, account not allowed) stop it until
  the next page; what can pass only holds it for at least a minute and the chain goes on: a 429 or a 5xx (as long as
  `Retry-After` asks, so a deploy's `artisan down --retry=60` is waited out), no network, no answer within 15 s;
  nothing is shown when it fails. A higher bell number is announced to screen readers ("Ada notifikasi baru."), never a toast.
  `data-sth-refresh-on="akun.notifikasi"` on an element inside a Livewire component refreshes that component when the
  bell number changed (Akun › Notifikasi). A new sidebar count is still a key in `Navigation` + one line in
  `NavigationServiceProvider::count()`; `liveCounts()` answers it by itself.
  **Confirm dialogs** (D-128): a question before an action is always an `<x-sth.modal>`, never `wire:confirm` / a
  native `confirm()` (`tests/Feature/Support/NoNativeConfirmTest.php` scans every view). The one-click and bulk
  actions swept in EM2 got one; not every action that notifies people asks (exceptions: D-128, among them Apresiasi
  "Masukkan" and "Simpan perubahan" on a published lomba, an open question for the lead, §9). Two steps: the ask method (`ask…()`) authorizes, validates the fields the Action will check, stores
  what is asked in a `#[Locked]` property and dispatches `sth-modal-open`; the confirm method acts only on that stored
  question and is a **silent no-op** without it (no 404, so a double click never puts an error page over an action
  that went through). The dialog names what happens and who is told, and claims a notice only when one goes out
  (D-109); on a student screen it never states another student's account status ("Batalkan undangan?": "<Nama>
  langsung diberi tahu." or the neutral "Undangan ini belum dijawab."; the line must stay a non-empty sentence). A
  refusal the screen could not show under a field is also a warning toast (`Form::refuseWithdrawal()`), and a result
  message is read from what the Action wrote, not from what the dialog predicted (the toast after a withdrawal adds
  "Pendaftaran diteruskan ke Kepala Sekolah."; `RequestReportRevision::leaderCanResend()` decides whether the revision
  banner may say "… bisa mengirim ulang laporan."). Built this way: `tolak-laporan` (Verifikasi hasil), `batal-undangan` (Pendaftaran lomba), `pindahkan-talenta`
  (bulk move), `tutup-lomba` (Kelola lomba), `impor-siswa` (Import siswa; the password page comes before the dialog),
  `tandai-terbit` (Humas). Older confirm steps (`Talenta::reject()`, `Lomba\Index::publishPast()`) still answer 404
  without their ask.
  **File preview popup** (M3, D-105): one dialog per page, `<x-sth.preview />` (`#sth-preview`), rendered once by
  `layouts::app` and `layouts::mobile` (not by `public` / `auth`: there a link simply downloads). Any link opens it
  with three attributes and keeps the download as its `href`: `data-sth-preview` (the inline address from
  `FileController::previewUrl()`; null = Blade drops it and the link is a plain download),
  `data-sth-preview-type` (`pdf` | `image`, from `previewType()`), `data-sth-preview-name` (dialog title); on a
  `<button>` give the download address in `data-sth-preview-download`. The script asks with HEAD first, frames only
  a same-origin `200` + `application/pdf` + `inline` answer, and shows one sentence + "Download" for anything else
  (no PDF viewer, 404, 429, expired session). Copy-paste example: top of `components/sth/preview.blade.php`.
  **Copy hook** (M3): `<button type="button" data-sth-copy="#target-id">` copies the `innerText` of that element
  (toast "Data disalin."; without the Clipboard API the text is selected and the toast says "Tekan Ctrl+C untuk
  menyalin."), so the target holds only the text to copy. **Address hook** (QA8): `sth-address {url}` replaces the address
  (same origin, no new history entry) when a screen moves between items without a page load; Kesiswaan › Verifikasi
  hasil sends it on every change of the selected report. **Toasts** (QA4): `sth-toast` takes an optional `key`;
  the event `sth-toast-clear {key}` removes the toasts raised with it, for an error toast whose cause is over
  (`TataUsaha\SuratTugas::clearFailure()`).
  **Error pages** (D-101): `resources/views/errors/{401,403,404,405,419,429,500,503,4xx,5xx}.blade.php`, each
  including `errors.layout` (brand, "Kode <status>", title, one sentence, "Kembali ke beranda", "Kembali" when the
  visitor came from another page of the app; links from `ErrorPageServiceProvider::links()`). No role layout (no
  menu, no counts), no inline style/JS, nothing that opens the database for a 5xx, and never
  `$exception->getMessage()`; they are used with `APP_DEBUG=true` too. Another status gets its own file there.
  On Siswa › Cari lomba the in-page box is `lg:hidden`, so exactly one search box shows at any width; any other page
  with a search box of its own passes `'search' => false` to the layout (D-90, QA5; a test lists them in
  `tests/Feature/Support/SearchBoxTest.php`). QA5 hooks: `<x-sth.modal on-close="method">` calls that Livewire method
  whenever the dialog closes (X, Esc, click outside; `data-sth-on-close`), for state kept in the address such as
  `?kelas=`; `data-sth-countdown="<seconds>"` with `[data-sth-countdown-text]` ticks m:ss every second between polls. The CSP never allows
  `'unsafe-eval'` (D-65): keep `wire:*` expressions simple (method calls with literal arguments; `CspSafeTest` scans every view).
- Showcase (local only): `/_dev/components` (`?role=kesiswaan`), `/_dev/components/{public,auth,mobile}`.
  A screen PR includes screenshots next to its mockup board (desktop 1440, mobile 390 where drawn).

## 8. Decisions you must not undo
Full log: `docs/DECISIONS.md` (append-only; a changed decision gets a new entry that "menggantikan D-xx").
- Initial student password = the configured value (D-138, `sth.initial_student_password`), the same for every student and replaced at the first login; the birth-date pattern `sm4g4_DDMMYY` (D-24) only survives in accounts imported before 2026-10-12 and in K7 detection. Never shown in the UI.
- Report deadline is **45 days after the final date** (D-25), not 30 days after the lomba.
- **All** competition uploads, from any role, need Kesiswaan verification before they appear (D-27).
- **Alumni (archived) cannot log in** (D-29); their achievements stay public/ranked.
- **Admin is separate from Kepala Sekolah** (D-01); Admin does not approve registrations for the Kepsek.
- **Each team member gets full points**; Semifinalis and Penghargaan khusus are **0,30** (D-08).
- No activation code (D-14); import by Kesiswaan + Admin (D-26); four bidang only (D-11); winners per
  lomba on public pages (D-31); talent via STH, not Google Form (D-21).

Lead decisions of 2026-10-01, logged as **D-32…D-44** in DECISIONS.md: new student passwords min **10**
characters (K5); leader cancels while `menunggu_konfirmasi` or `menunggu_kepsek`; rejected report →
`selesai` without points; guru pembimbing required; decline rule (≥ 2 confirmed remain); personal rank among
active students, public ranking with alumni (how alumni are shown: D-80); names only with `publish_consent`,
`noindex` by default; certificate links only with consent + Admin switch; Kepsek replacement after 24 h; max
3 Admins + `sth:create-admin`; staff login username/email, students NISN (D-137; plus the bound email after
activation, D-78); letters Issue then Send (issue is per letter since D-83), student letter prints NIS;
`users.id` bigint, domain tables ULID. Also binding: W1-A's schema additions
(`docs/dev-notes/W1-A.md`) accepted; prod web port **8080**; the OTP flag is **`STH_OTP_TEST_MODE`** (read
via `config('fortify.sth.otp_test_mode')`).
Backlog/W4 decisions D-45…D-65 (DECISIONS §D3–§D4): D-61 new Admin/Kesiswaan/TU accounts start without a role until a
second Admin approves (or 24 h); **final** security hardening D-62 OTP test mode env-only, D-63 audit seals after commit,
D-64 queued-import spreadsheet deleted when the job ends, D-65 no `'unsafe-eval'`.
Q1 pass D-66…D-74 (DECISIONS §D5), **final** unless noted: D-66 TOTP is staff only and a student reset clears it;
D-67 confirm-password limit + 2FA audit events; D-68 no mail and no reset code for a frozen account, unverified address
gets account notices only; D-69 user text in mails is plain text (`PlainMailMessage`); D-70 `PdfScanner` refuses PDFs it
cannot read completely; D-71 a withdrawn publish consent also hides the name and the flyer from Humas (flyer parts of
D-71 and D-75: since D-107 only for flyer files made before 2026-10-06); D-72 long lists
come in steps (*sementara*); D-73 "Tambah lomba" notifies the other verifiers (*sementara*); D-74 gzip for the
Livewire script in the prod nginx + daily prune of expired cache rows. Lead's choices of 2026-10-02, **final**: D-75 the
flyer file stays after a withdrawn consent, Humas cannot download it, Admin (`student.view`) still can; D-76 a lomba
name must not contain a web address (`App\Rules\NoWebAddress`); D-77 the guru pembimbing is told about a rejected or
cancelled registration, the Kepala Sekolah about a cancel once it had reached `menunggu_kepsek`.
Lead's choices after the hand test of 2026-10-04, **D-78…D-91** (DECISIONS §D6, `docs/dev-notes/M1.md`), **final**:
D-78 a student may log in with the bound email once activation is finished; D-79 first login = email → code → password,
and a student may keep the school password (accepted risk: it stays guessable from the birth date for as long as it is
kept, K7 cannot see it; staff must change theirs; *since D-138 nobody may keep it*); D-80 public ranking filter Siswa aktif / Alumni / Semua, alumni show
"Alumni" instead of the last class; D-81 the yearly student file updates the class of registered students (the file
winning over a manual archive date, and leaving students who are missing from the file alone, are *sementara*); D-82 TU
types the complete letter number, unique per letter type, the Admin format is only an example; D-83 one form and one
"Buat PDF" per letter, one "Kirim"; D-84 letters look like the school's own files, the Pemprov and school emblems live
in `resources/pdf/assets`, "Cetak" opens the PDF inline; D-85 two optional talent notes (guru pembina / student), a
rejection needs no note; D-86 a rejected talent choice can be restored and the student can ask for a review, never a
second application (restoring without a request is *sementara*); D-87 appreciation events are confirmed and deleted as
events, a confirmed one is locked; D-88 Status aktivasi "Lihat" opens a class popup; D-89 everyday words in UI copy
(upload, download, link, preview); D-90 sidebar counts update without a reload, the top bar search filters while typing,
one search box per screen on Cari lomba; D-91 dev-only php-fpm config/view cache, and the tests refuse to run on a
cached config. Do not bring back: "change password first", "NIS already registered = refused", `{seq}` number
validation, one draft for both letters, the "Tampilkan alumni" tick, a required rejection note in Talenta.
Fixes after the QA pass of 2026-10-05, **D-92…D-101** (DECISIONS §D7, `docs/dev-notes/M2.md`; the lead: "lakukan sesuai
saranmu"), **final** unless noted: D-92 after an Admin reset a student must choose a new password (takes back the last
sentence of D-79; the school password of the import may still be kept; *since D-138 for every account*); D-93 credential pages are `no-store`, password
fields are emptied, and a renewed session destroys the old one, never on a refused attempt; D-94 new passwords refuse
the username, the email's local part and common passwords; D-95 "Buka ulang" never shortens a deadline, its reason and
a lomba rejection need 10 characters (the lomba half was taken back by D-103); D-96 the talent note for the
student is a draft until a decision and "Tidak lolos" asks first (quick actions and bulk moves also send a saved
draft: *sementara*); D-97 one achievement on one
appreciation event, "Lepas dari acara"; D-98 a lomba cannot start before its registration deadline, and past dates
need an explicit yes to publish; D-99 the student card lists only sent letters and the guru letter is text only
(students may not open it: *sementara*); D-100 sidebar counts come from one resolver on every page; D-101 own error
pages, `GET /logout` redirects, a 403/404 on the first page after login goes to the beranda; D-102 (the lead: "Ya
perbaiki") after Keluar the account name is emptied on Back too, and a different account that logs in next never lands
on the previous account's page. Do not bring back:
"Lewati" for a temporary password, `session()->regenerate()` without `true` on these screens or before the Action
succeeded, a "Buka ulang" that sets now + 72 h unconditionally, a download button for the guru letter on the student
card, counts that depend on each page passing `badges`, Laravel's default error views.
The lead's six requests of 2026-10-06 and QA4, **D-103…D-108** (DECISIONS §D8, `docs/dev-notes/M3.md`), **final**
unless noted: D-103 the reason for rejecting a lomba is optional and has no minimum length (replaces the lomba half of
D-95 and the old "note required"; "Buka ulang" keeps its 10 characters); D-104 "Pulihkan" sends a rejected talent
choice to the stage Kesiswaan chooses (Terdaftar, Pemetaan, Seleksi, Peserta pembinaan; the earlier stage is only
the preselected one, which adjusts D-86), and "Download data" in Talenta asks which statuses go into the file
(*sementara*: no pembina or date required for a restore into Peserta pembinaan, tidak-lolos rows last in "Semua", no
bulk restore, "Status" in the dialog vs "Tahap" in the file); D-105 "Buka" shows an evidence file in a popup and the
download stays: only the inline PDF answer of `files.show` may be framed by this origin and has no `sandbox`,
everything else keeps `DENY` / `sandbox` (*sementara*: letters do not use the popup yet, HEAD is not audited, public
pages only download); D-106 the local demo files are realistic sample documents with fictional people, invented
signers and "CONTOH" on every page, never outside `APP_ENV=local` (`sth:demo:refresh-files`); D-107 STH draws no
e-flyer: Humas gets "Data untuk flyer" + "Salin data" and designs the flyer itself, which narrows D-47 and the flyer
parts of D-71 / D-75 to files made before 2026-10-06 (*sementara*: no upload for the finished flyer, old flyer files
are kept); D-108 `letters.printed` keeps what an issued letter's PDF printed and the TU preview reads it (S, PR 12).
Do not bring back: the flyer generator (`GenerateFlyer`, `FlyerRenderer`, a template choice, "Download PNG"), a
required or 10-character lomba rejection reason, a restore that can only go to the earlier stage, 1 kB placeholder
demo files in local seeding, a `sandbox` on the inline PDF answer (the viewer stays empty) or any wider relaxation
of the file headers than that one answer, a preview of an issued letter drawn from today's data.
The lead's answers after the retest of M3 on 2026-10-07 (QA5), **D-109…D-112** (DECISIONS §D9,
`docs/dev-notes/QA5.md`; where nobody asked, the client document "SMAGA TALENT HUB (STH)" decided), **final**: D-109 a
restore into Peserta pembinaan needs a guru pembina (replaces that *sementara* point of D-104); D-110 the guru
pembimbing is told about every decision on a binaan's report and about the binaan's appreciation (scheduled,
released, event deleted); D-111 the Talenta export follows the screen filters, empty statuses cannot be chosen, no
empty file; D-112 the sent letters open in the preview popup on Guru › detail binaan. Do not bring back: a restore
into Peserta pembinaan without a guru pembina, an empty Talenta Excel, a reject toast that claims a notice nobody got.
The explore round after PR 14 on 2026-10-07 (QA6), **D-113…D-116** (DECISIONS §D10, `docs/dev-notes/QA6.md`; the lead
left the UX to us where the client document is silent): D-113 an issued letter verifies on the QR page before "Kirim"
too (*sementara*); D-114 a new or changed assessment date may not lie before today; D-115 the ketua may withdraw an
unanswered team invitation; D-116 a letter not issued yet takes the invitation and schedule fields of the other letter,
never over a field TU typed for it (D-83 stays: two forms). Do not bring back: "Setujui terpilih" or TU "Kirim" without a
confirmation, "Tidak ditemukan" for a letter TU could print, a past assessment date for a new schedule.

The visual direction v3 and what came with it on 2026-10-07 (UI3), **D-117…D-119** (DECISIONS §D11, `docs/dev-notes/UI3.md`
+ `UI3-A`, `UI3-B`; the lead chose palette and typeface and left the open points to the worker): D-117 the look is "Tinta
dan Sampul" (`docs/design/DESIGN-SPEC-v3.md`: ink navy frame, Public Sans only, fixed type scale with nothing under 13px,
squared tags, no gradients / glows / tilts, covers always filled, the lintasan banner), **final**; D-118 a lomba's cover is its
own poster or a template drawing of its bidang, posters are served by `files.cover` (final for the order of covers,
*sementara* for "not audited, private cache for a day" and "posters only after login"); D-119 staff are never addressed as
"kamu", the tab bar uses the menu names, no internal codes on a screen, **final**; D-120 the brand mark is the crest
of SMA Negeri 3 Semarang (the lead's request of the same day; it adjusts D-20 and the last sentence of D-84 for app
screens), **final**. Do not bring back: the v2 look (indigo
`#3148D6`, the lavender page wash, gradient covers, pills, Urbanist / Plus Jakarta Sans, `shadow-primary` glows, tilted or
blurred decoration), `text-[Npx]` sizes or anything under 13px outside the letter preview, an empty cover, a portrait poster
shown small in the middle of a wide band on a lomba card or a lomba page, the shield icon as the brand mark, a card floating beside a hero headline, the "Cari lomba" button above the student menu or on the home banner, a menu item
repeated as a button on the same screen, "Lombaku" / "Profil" as tab names, "kamu" on a staff screen, a cover `<img>`
that points at `files.show`.

Real mail in development on 2026-10-08 (EM1), **D-121** (DECISIONS §D12, `docs/dev-notes/EM1.md`), *sementara*: Brevo
with a temporary Gmail sender, and a local stack only sends out to the addresses on `STH_MAIL_ALLOWLIST`. The provider
for production is **not** decided. Do not bring back: a local stack that can reach a real provider without the
allowlist (the boot guard for `APP_ENV=local`), an allowlist entry that opens a whole domain, the allowlist mailer
outside `APP_ENV=local`. Since 2026-10-11 (D-130, lead's request, *sementara*) the single entry `*` opens the list to
every address that can exist; do not bring back: a fictional address (`@sth.test`) that leaves the machine in any mode.

The lead's mail test of 2026-10-09 (EM2), **D-122…D-129** (DECISIONS §D13, `docs/dev-notes/EM2.md`; "lead" = asked for
in the test report, the rest was chosen by the coordinator and waits for the lead's look): D-122 the schedule of a
publication is only a reminder for Humas, and only "Tandai dipublikasikan" sets `dipublikasikan` and tells the student
(**final**; replaces the auto-publish half of D-47); D-123 an appreciation event has a start time, printed through
`EventWhen` (**final**; completes D-87, whose rules stay day-level); D-124 "Tambah prestasi" is a search box with
ticks and one "Masukkan" (**final**), and that one click sends each guru pembimbing one notice for all binaan in it
(*sementara*; adjusts D-110); D-125 "Jenis penyelenggara: Lainnya" has a typed field (**final**; required to publish: confirmed by the lead on 2026-10-10; the format "Lainnya (…)" is *sementara*); D-126 a changed fact of a published lomba is told to the students
registered for it and their guru pembimbing, never to all students (that audience: **final**, lead 2026-10-10; the fact list, the status scope and the limit of 50 are *sementara*); D-127 the bell and the sidebar
counts follow the state without a page load (**final**; the 60 s interval and the switch `STH_COUNTS_POLL_SECONDS`
are *sementara*; completes D-90 and D-100); D-128 "Tolak" on a laporan hasil asks first (**final**), and a question
before an action is always the app's own dialog, never a native confirm: the one-click and bulk actions swept in EM2
got one (those five dialogs: confirmed by the lead on 2026-10-10), and the senders left without a question on purpose are listed in D-128,
among them Apresiasi "Masukkan" (up to 30 ticks) and "Simpan perubahan" on a published lomba (up to
`CompetitionChange::MAIL_LIMIT` mails), both an open question for the lead (§9); D-129 mails come from the app's own templates: a text part without Markdown,
one greeting rule, the button as the only link, the "Tinta dan Sampul" look (accepted by the lead on 2026-10-10 from the description, also "Yth. <nama lengkap>," for staff in the OTP mail; a real mail has not been seen yet, so details of the look may still change; completes D-117 and D-119; D-69 and D-120 stay). Without a number: link fields are cleaned before they are
checked and every refusal has its own sentence. Do not bring back: a job that sets `dipublikasikan` or mails the
student at the scheduled time, or `published_at` = the scheduled time; `wire:confirm` or a native confirm in any view,
or a confirm step of these dialogs that answers 404 without its question; a one-click "Tolak" on a report, or a result
banner that claims a notice nobody got (also "Ketua bisa mengirim ulang laporan." when the ketua cannot log in); a
dialog on a student screen that states another student's account status, or a refusal of "Batalkan undangan" that
only lands in an error block the screen no longer draws; a reminder, alert or hint that promises a student notice
for a publication whose student gets none (an alumnus); a date-only appreciation event for new events, or a new
"when" text that does not come from `EventWhen`; the single long select for "Tambah prestasi" or its silent cap of
100 rows, or ticks that are dropped silently, or an `updatedSearch()` that writes a trimmed value back into "Cari
prestasi" while it is typed in (three older `updatedQ` hooks still do that on their boxes: backlog #81); one guru notice per achievement inside one "Masukkan"; a bare "Lainnya" on a published lomba; one sentence
for every refused link, or a link checked before it is cleaned, or `https://` added for the user; "Halo!", a greeting
taken from the first word of a staff name ("Halo Drs.,"), a sign-off line, an `<img>` or a second link in a mail, or
Markdown leftovers in the text part of a mail; a bell that only changes on a page load, a page reload after "Tandai
semua dibaca", `wire:poll` for the counts, a background check next to a Livewire request, an interval set under 30 s
or above 3600 s (a browser timer delay is 32 bits of milliseconds), a 5xx, a network error or a timeout that counts
towards the two refusals that end the checks of a page, or a toast for a new notification; a mail or a bell row to
all students for a lomba change, a web address in a notice (a lomba link, or one typed into "Tempat"), a "Periksa
lomba" drawer that saves a lomba published meanwhile on the first click (it would notify students without the
alert), or a toast that claims people were told when nobody was.

The demo and the joint review of 2026-10-11 (RV1), **D-130…D-134** (DECISIONS §D14, `docs/dev-notes/RV1.md`): D-130
the single entry `*` opens the local mail allowlist to every address that can exist, never to a fictional one
(*sementara*, local only; adjusts D-121); D-131 the NIS is shown in full on the three screens only Kesiswaan and Admin
open (Status aktivasi and its CSV, Talenta, Database prestasi), the email stays masked (**final**; whether the NISN or
a full email should be added is not decided); D-132 with the approval the Kepala Sekolah may put another guru
pembimbing in the place of the student's choice, the students' approval notice always names their guru, the new guru
is told as an appointment (**final** for the right and for the guru in the notice; *sementara*, chosen by the
coordinator: only together with the approval, not for "Setujui terpilih"); D-133 the student letter stands in the
middle of the page (**final**; adjusts D-84 for that one point); D-134 the guru who was replaced is told nothing, and
the dialog says in plain sentences who is told (**final**, the lead after seeing the dialog: that guru never heard of
being chosen; replaces the "replaced guru gets a notice of their own" part of D-132; D-77 is unchanged, so a
rejection or a cancel still reaches the guru the student chose). Do not bring back: a notice, mail or bell row to
the guru who was replaced, or the dialog sentences "Notifikasi persetujuan untuk siswa menyebut guru
pembimbingnya." / "… diberi tahu bahwa guru pembimbing diganti."; a masked NIS on those three screens or in that
CSV; an approval notice for students that does not name the guru pembimbing; a way to change the guru of a
registration that is not the approval itself (a separate save while it waits, or anything after the decision); the
approval notice of a registration sent to the guru who was replaced, or a rejection sent to a guru the student never
chose; a guru's name or any other name in the audit row `registration.mentor_changed`; a "Ganti guru" confirm step
that answers 404 without its question, or a dialog that promises a notice to somebody who gets none; a student letter
that stands at the left margin; a Livewire view whose root element sits inside or behind a directive.

The school's feedback of 2026-10-12 (FS1), packages A and D, **D-135…D-138** (DECISIONS §D15, `docs/dev-notes/FS1.md`); package A: the panel
on Detail lomba is "Pendaftaran lomba"; the lomba proposal queue is "Verifikasi usulan lomba" everywhere (Kesiswaan menu, its
tab, the Beranda tile and card, the stat tile, the proposal mail subject and button, the Kepala Sekolah dashboard sentence); the
talent page is "Database talenta siswa" (menu, `#[Title]`, Excel title row, notification action); Admin has "Database prestasi"
in its Data section (→ `kesiswaan.prestasi.index`, allowed by `role:kesiswaan|admin`); the Database prestasi sheet has no
Kategori column (Bobot penyelenggara stays; the drawer may still show the category); the Kelola lomba catalog lists
`menunggu_verifikasi` lomba oldest first (`created_at` ascending, the queue stays `oldest()`); count badges (sidebar and bell)
are red `#B42318` with white text, while `.cnt` stays gray and the unread row marker in Notifikasi stays navy; a long sidebar
label wraps to two lines. Routes, URLs and menu / badge keys did not change. Do not bring back: "Daftar lomba" as the panel
title, "Usulan lomba" / "Antrean verifikasi" / "Upload lomba" for the queue, "Talenta siswa" as the page name, a Kategori
column in the Database prestasi sheet, navy count badges, and a `truncate`d sidebar label.
Package D, **D-137…D-138**: students log in with the NISN (or the bound email after activation); the NIS is only stored; the
login field is "NISN atau email" and the one failure answer is "NISN atau kata sandi salah."; the NISN replaces the NIS on
Status aktivasi + CSV (with a hint line), Database talenta siswa, Database prestasi + Excel, Profil talenta and the import rows
to check; searches take a name or a NISN; every student gets the same initial password (config `sth.initial_student_password`,
env `STH_INITIAL_STUDENT_PASSWORD`), refused as a new password, and must replace it at the first login (no "Lewati"). Do not
bring back: login by NIS, "Lewati" or any other way to keep a password at activation, an initial password built from the birth
date or shown anywhere, the NIS instead of the NISN on Status aktivasi + CSV, Database talenta siswa, Database prestasi + Excel,
Profil talenta and the import rows to check, the import error "NIS sudah dipakai sebagai NISN siswa lain", a digit identifier
other than a 10-digit NISN written to the security log as typed.

Known stale text (do not "fix" code to match it): `docs/DEPLOY.md` §9 path A (Google Workspace relay) is not an option
for the school (lead, 2026-10-08) and §9 has no Brevo path yet;
DESIGN-SPEC-v2 §1 and the Admin-Pengaturan mockup still show 30 days (and the mockup's WICE deadline
24 Okt is now **8 Nov 2026**); SECURITY/DECISIONS call the OTP flag `STH_OTP_DEV_BYPASS`; SECURITY says
port 8081; SECURITY §3e's vhost sample has inline `#` comments Apache rejects (use `docker/apache/*.conf`);
SECURITY §3h's grant sample names `audit_logs` (the table is `activity_log`, DEPLOY §8.2); SECURITY §3f keeps import
files 7 days (queued-import files are deleted when the job ends, D-64). ARCHITECTURE §1–§8 were
brought up to date on 2026-10-01 (backlog #20; §4 settings/staff accounts after W4) and again for M1 (2026-10-04).
Stale since M1 (D-78…D-91): the mockup boards `Aktivasi` (password first), `Peringkat` ("Tampilkan alumni"),
`TU-Surat-Tugas` (two numbers in one form, "Buat 2 PDF") and every board that says unggah / unduh / pratinjau; the
code samples in SECURITY §3g (regex per number format, unique `(jenis, tahun, nomor_urut)`) and the old texts of K4 and
the import rule in §3f, which SECURITY keeps as history next to a dated M1 note; DECISIONS entries before D-78 that
still say unggah / unduh / tautan / pratinjau.
Stale since M2 (D-92…D-101): the last sentence of D-79's Keputusan and the "Simpan never stores" sentence of D-85
(both carry a dated pointer to D-92 / D-96); `docs/dev-notes/M1-B.md` ("a reset student may also keep the temporary
password") and `M1.md` / `catatan.md` §5 ("email scenario not re-run": it was, on 2026-10-05); `docs/dev-notes/W1-D.md`
(badge resolver `fn ($user, $role)`: it now also gets `$except`); the old texts of K2, K4 and K10 in SECURITY §3a,
kept as history next to a dated M2 note.
Stale since UI3 (D-117…D-119): every picture of the old look: the mockup boards, the canvas, `DESIGN-SPEC-v2.md` §2–§3 (its §1,
§4 contracts, §5 and §6 stay valid), screenshots in older PRs and in `panduan-uji-manual-sth.html`; `docs/dev-notes/W1-D.md`
(tokens, fonts, the `cta` of `Navigation::for()`, 38 components); comments in views that still say "DESIGN-SPEC-v2 §4" point
at the component contract, not at the look; SECURITY §3d "setiap download dicatat" (one exception: `files.cover`, dated note).
Stale since M3 (D-103…D-108): the mockup board `Humas-Publikasi` (flyer mock-up, template select, "flyer otomatis
memakai inisial"; mockups are design history and are not edited); the lomba half of D-95 ("alasan menolak lomba
minimal 10 karakter"), D-86's "kembali ke tahap sebelum ditolak" and the flyer sentences of D-47, D-71 and D-75 (each
carries a dated pointer to D-103 / D-104 / D-107); `catatan.md` §6 no. 4 ("juga alasan menolak lomba", with a
pointer to its §8); `docs/dev-notes/M2-B.md` and `M2.md` (`VerifyCompetition::MIN_REJECT_NOTE`), `M1-D.md` (the
signature of `RestoreTalentApplication::handle()` without `$to`) and the older notes that describe the flyer
generator (`W2-C.md`, `W2-A1.md`, `Q1.md`; list in `M3-E.md`); in SECURITY the matrix row "Publikasi/e-flyer"
(§3c), "disk `public` hanya untuk e-flyer" and "preview inline hanya untuk gambar" (§3d) and "attachment untuk
PDF" (§3m-2), kept as history next to a dated M3 note;
`resources/fonts/LICENSE-DejaVu.txt` (names `GenerateFlyer`; backlog #72); outside the repo
`hasil-uji/qa-2-peran-lain.md` (Q2-19) and `hasil-uji/cek-backlog-dan-panduan.md`.
Stale since RV1 (D-130…D-133): the mockup board `Kepsek-Persetujuan` (no "Ganti guru") and every board that shows a
masked NIS (`Kesiswaan-Talenta`; mockups are design history and are not edited); `docs/dev-notes/M1-A.md` ("the
student sheet … not centred, so the PDF keeps the wider right margin") and the other notes that describe a masked NIS
(`B1.md`, `B4.md`, `W2-B.md`) or `ApproveRegistration::handle()` with three parameters; in SECURITY the K9 row, the
row "Masking di list" (§3i) and the Kepala Sekolah cell of the matrix row "Pendaftaran individu/tim" (§3c), each kept
as history next to a dated RV1 note; D-35's and D-84's old status lines (each carries a dated pointer to D-132 /
D-133); notification rows and mails of approvals from before 2026-10-11 (no guru line); letter PDFs issued before that
day (the student letter at the left margin; the file is kept as issued).
Stale since FS1-D (D-137…D-138): the mockup boards `Masuk` ("Pakai NIS atau NISN", the field "Ketik NIS atau NISN"), `Aktivasi`
(a skip choice), `Admin-Import-Siswa`, `Admin-Pengaturan` and `Detail-Lomba` ("Cari nama, NIS, atau NISN" and "Cari NIS atau NISN"; mockups are design
history and are not edited), `DESIGN-SPEC-v2.md` §1 ("search by nama/NIS/NISN"); the dev-notes that describe `keepPassword()` /
`mayKeepPassword()` or the skip button (`M1-B.md`, `M2-A.md`, `M2.md`, `M1.md`); the old texts of D-14, D-24, D-42, D-78,
D-79, D-92 and D-131 in DECISIONS and of K1, K4, K5, K6, K7, K11 and K12 in SECURITY §3a, each kept as history next to a dated 12 Okt 2026 note;
students imported before 2026-10-12 keep the old birth-date hash until an Admin resets them.
Stale since EM2 (D-122…D-129): the mockup boards `Humas-Publikasi`, `Kesiswaan-Verifikasi-Hasil` and
`Kesiswaan-Kelola-Lomba` know none of it (no "Lewat jadwal", no start time, no picker, no confirm dialog, no
"Lainnya" field; mockups are design history and are not edited); D-47's sentence "mengubah status ke `dipublikasikan`
… memberi tahu siswa" and its Dampak line with `AutoPublishPublication(s)`, D-110 for the "dijadwalkan" notice,
D-69's "lapisan kedua" and the message D-76 quotes ("… di kolom situs web."; `NoWebAddress::MESSAGE` now ends "… di
kolom link.") (each carries a dated pointer to D-122 / D-124 / D-129 / 10 Okt 2026); `docs/dev-notes/B3.md` (the
auto-publish Action, job and test, "demo `terjadwal` publications … flip to published") and the later notes that
mention the auto-publish job (`QA9.md`, `QA10.md`); `B1.md` ("Bell count updates on page load only"), `M2.md` (Q2-26
open) and `QA5.md` ("the bell after 'Tandai semua dibaca' updates (#63)": QA5 had retested the button that reloaded
the page, the single-row "Tandai dibaca" was only fixed by D-127, so backlog row 63 called Q2-26 done too early);
`W2-C.md` (`CreateAppreciationEvent::handle($actor, $name, $heldOn)` without the time; "nothing auto-publishes" was
true then and is true again); in SECURITY the old texts of §3b
("Isi email: nama depan saja": since D-129 the OTP mail and the binding notice greet a staff account "Yth. <nama
lengkap>,", students keep the first name; a question for the lead in §9) and of §3m-1 ("Validasi": `url:https`,
`max:2048`), kept as history next to a dated EM2 note; notification rows stored before 2026-10-10
keep their old sentences (Humas: "Statusnya kini Dipublikasikan.", appreciation notices with a date only); outside
the repo the lead's report `hasil-uji-email-sth-2026-10-09.html` (mails in the old template) and the mail test guide
(step 3-6 names another path to "Koreksi" than the app has; the lead's note: Arsip surat › siswa › Koreksi).

## 9. Open questions (answers come from the school; don't guess in code)
Decided by the lead on 2026-10-04, no longer open: the letter template (= the school's own files: one team
letter with the columns NO, NAMA MURID, NIS, KELAS; guru letter + Lampiran; D-84) and the number format
(TU types the complete number, so the meaning of "MRD", the yearly reset and separate books for siswa and
guru numbers stay with TU's agenda book and no longer block code; D-82).
From DECISIONS §F and SECURITY §5: parental consent for names, photos,
certificates on public pages (U-04); go-live date + team hours; Ubuntu Pro (U-01); Docker static
binaries (U-02); mandatory staff TOTP + break-glass (U-03); letter QR (U-05); WordPress isolation (U-06);
server/DNS admins; Kepsek consent for the signature image, TTE?; hijack recovery owner + risk-acceptance
signature (D-14, which must now also name the D-79 and D-138 risks); per-class activation schedule; SMTP provider;
off-site backups + key holder; DPO.
Questions for the lead from M1 (marked *sementara* in DECISIONS §D6; backlog #45, #49–#51): does the yearly
student file win over a manual archive date, and what happens to a registered student who is missing from
the file (D-81); may Kesiswaan restore a rejected talent choice without a student's request (D-86); the
flag and reminder for students who kept the school password (D-79; moot for new activations since D-138).
Questions for the lead from M2 (marked *sementara* in DECISIONS §D7; backlog #57, #58): should a saved draft
note go out with the quick actions and bulk moves in Talenta (D-96); may students open the guru letter, which
the QA summary asked the lead to decide (D-99: today they may not); should the other reason fields (reject a
registration, reject / revise a report, "Buka pembekuan") also need 10 characters (D-95).
Questions for the lead from M3 and QA4 (marked *sementara* in DECISIONS §D8; backlog #71): Talenta (D-104): ~~may a
restore go straight into Peserta pembinaan without a guru pembina~~ (no, D-109; the assessment date stays optional),
should tidak-lolos rows stay last in "Semua", is a bulk "Pulihkan" wanted, one word for "Status" (dialog) and "Tahap"
(file); preview (D-105): should the surat tugas links of the student and TU screens open in the popup too (the guru
screen does, D-112), should the HEAD question before a preview be audited, should
public pages get the popup; demo documents (D-106): may the sample certificates name the organizers of the demo data,
and is it fine that demo letters are not byte-equal to "Buat PDF"; Humas (D-107): is an upload for the finished flyer
wanted (column and policy exist), should flyer files made before 2026-10-06 be deleted (today kept, still served to
the roles that could open them), is it right to leave a team member without publish consent out of "Data untuk flyer",
should the photo sentence be copied too, keep or drop M3-E's additions ("Lihat" on Arsip, the row "Cabang"); QA4:
should alumni rows be offered in Apresiasi › Tambah prestasi.
Question for the lead from QA6 (marked *sementara* in DECISIONS §D10): D-113 lets an issued letter verify before "Kirim";
the other choice was "no Cetak or Download before Kirim".
Questions for the lead from EM2 (the *sementara* parts of DECISIONS §D13; full list in `docs/dev-notes/EM2.md`,
backlog #81). Bell (D-127): the first check after an action comes at 2.5 s and the queue writes the row after 2–3 s, so
the bell often changes only at 8 s (write the bell row at once, or check at about 3.5 s?); a read-only session for
the counts route; a message in a tab whose session ended; the sidebar key `siswa.lomba-saya` that nothing counts
(give it a meaning or remove it). Lomba form (D-125, links): "Lainnya (…)" in the Excel column (a pivot splits
"Lainnya"), the typed kind on the queue and catalog cards, one word for "Link resmi" / "Website resmi", no blocklist
of domain endings (`.test` is accepted, `http://` refused), a name field for the capaian "Penghargaan khusus". Lomba
change notice (D-126; "never to all students" is settled, lead 2026-10-10): the fact list, the status scope and the limit of 50; "ditambahkan" for a first link or juknis
(today "diganti"); "Kesiswaan mengubah …" also when an Admin did; no merge window (three saves = three notices, a
one-letter fix of "Tempat" notifies); should the info alert also show, or count, when only `menunggu_laporan`
registrations exist (a moved final date is told to them); an input rule against a web address in "Tempat" (today
only the notice leaves the value out); wanted or not: a notice to TU once letters exist, a notice when a lomba is
closed, a question before saving a past deadline, refusing "tim" while individu registrations run, "Baru" /
"Diperbarui" tags in the catalog. Humas (D-122): a daily nudge while a row stays "Lewat jadwal" (today one reminder
per schedule, stamped even when no Humas account could log in); rename "Jadwalkan" to "Jadwalkan pengingat"; the
dialog line for an alumnus. Apresiasi (D-123, D-124): "Ubah acara" (a wrong time or date needs delete + create);
members of one team added in separate clicks still give the guru one notice per click; merge a student's own notices
in one click; the caps 30 / 100; the general sentence for a single stale tick; the named rule (`NamedStudent`) for eligibility (#80). Confirm dialogs
(D-128; the five swept dialogs stay, lead 2026-10-10): silent no-op also for `Talenta::reject()` and `Lomba\Index::publishPast()`
(today 404 without the ask); "Siswa bisa …" vs "Ketua bisa mengirim ulang laporan."; a sentence for Kesiswaan when the
ketua cannot log in (today the resend sentence is only left out); **should Apresiasi "Masukkan" ask above a few
ticks, and "Simpan perubahan" ask on a published lomba with registered students?** Today neither asks: one "Masukkan"
can queue up to 30 student mails plus the guru mails and each "Lepas dari acara" afterwards sends one more; one
"Simpan perubahan" can send up to 50 mails (`CompetitionChange::MAIL_LIMIT`). Mails (D-129; look and greeting accepted by the lead on 2026-10-10, also "Yth. <nama lengkap>," for staff in the OTP mail and the binding notice, although a real mail has not been seen yet): the hidden inbox preview line in the OTP mail; button
labels; an Auto-Submitted header. Provider: the production provider (DECISIONS §D13 addendum: Amazon SES, Postmark,
Resend, or a mailbox on the school's hosting); on Brevo meanwhile: retention of logs and previews, a second sender for
the OTP mails (a block is per sender), and one look at "Show original" of an OTP mail for the unsubscribe header.
Questions for the lead from RV1 (the *sementara* parts of DECISIONS §D14; `docs/dev-notes/RV1.md`, backlog #82).
Guru pembimbing (D-132): should the Kepala Sekolah also be able to change the guru without approving in the same
click, or for several rows at once ("Setujui terpilih"), or after the approval (today none of these: a change after
the letters exist would also need new letters); ~~should the guru who was replaced be told at all~~ (no, D-134);
should the guru be told when a student chooses them, before any decision (today a guru first hears of a
registration when it is decided or cancelled, while a waiting registration already stands in that guru's Siswa
binaan list); should a rejection or a cancel still reach the guru the student chose (D-77: today yes, although that
guru was never told of being chosen, the reason the lead gave for D-134); the wording of the two notices. NIS (D-131; the NISN question is settled by D-137, which shows the NISN instead of the NIS): should the NISN be
shown or added to the CSV, and should the CSV carry the full email (the lead named the student number only); a NIS
with a leading zero would lose it when the CSV is opened in Excel (none exists in the school's numbering as far as
the demo data shows). Demo (D-130): keep the open mode once a real provider and sender exist, and a daily quota guard
(backlog #78). Lomba dates in the demo data are fixed (first days of October 2026), so more of its deadlines pass
every day: reseed with dates relative to today, or leave it.
Public DNS check of 2026-10-01 (not yet confirmed with the school): the domain's DNS is on Cloudflare (`www`, `mail`,
`webmail` proxied); the main website and the school mail are on Desnet shared hosting (SPF, DKIM and DMARC `p=none`
exist), so apparently **not** on the Ubuntu server (§1 assumes the same host: confirm). No `prestasi` record exists
yet: add an A record to the Ubuntu server as "DNS only" (behind the Cloudflare proxy the app would only see Cloudflare
IPs, which breaks the per-IP login/OTP limits). The noreply mailbox would then be DEPLOY §9 path B on that hosting; its
SMTP host must not be `mail.<domain>` (proxied) and its hourly send limit needs asking.

## 10. Status
Last updated: 2026-10-13 (waves 1–2 done, W3-A integration pass, backlog wave B1–B6, W4 integration, D1 docs sync, CI green on GitHub, Q1 end-to-end/security/performance pass, PERF1 by S, M1 = the lead's 18 hand-test changes, M2 = fixes after the QA pass, QA4 by S, M3 = the lead's six requests of 2026-10-06, QA5 = fixes after the retest of M3, QA6 = explore round after PR 14, UI3 = visual direction v3 and the lead's review of it, QA7 = explore round after UI3, QA8 = explore round after PR 17, EM1 = real mail from a local stack, QA9 = explore round after EM1, QA10 = explore round after QA9, EM2 = fixes after the lead's mail and notification test of 2026-10-09, RV1 = the demo and the joint review of 2026-10-11, FS1-A = school feedback package A, FS1-D = school feedback package D). Update this section whenever a milestone moves.
EM1 2026-10-08 (`docs/dev-notes/EM1.md`, D-121, commit `4766b56` on `feat/email-brevo-dev`; merged into `main` together with QA9 and QA10 by PR 19, merge commit `a2d21e1` of 2026-10-09): the lead chose Brevo for development with a temporary Gmail sender. Built: the mailer `allowlist` (listed addresses → the real provider, everything else → Mailpit, local only), two boot guards (no allowlist outside local; in local no default mailer that delivers every mail), `sth:mail-test` says which way an address goes, a commented block in `.env.example`. The lead's first real mail through Brevo arrived (sender shown as `…@<id>.brevosend.com`, Gmail shows "Berhenti berlangganan"). No migration, no notification changed yet. Suite numbers: TEST-RESULTS in `docs/dev-notes/EM1.md`. Open: backlog #78.
M1 2026-10-05 (`docs/dev-notes/M1.md`, plain summary for the team in `catatan.md`; branch `feat/ui-uji-manual-m1`): full suite `--parallel --processes=4` → **1767 passed (8945 assertions)**, exit 0 (680 s); `pint --test` clean (610 files); `npm run build` OK; the four M1 migrations ran on the local MySQL. Dev page times after `reload.bat` + warm-up, all seven roles: 150–540 ms per menu, login 0.75–0.93 s (`catatan.md` §2). Not re-run after M1: the email scenario and the Playwright smoke (backlog).
M2 2026-10-06 (`docs/dev-notes/M2.md` + `M2-A`…`M2-C`, D-92…D-101, plain summary in `catatan.md` §6; same branch): fixes for the 12 most important findings of the QA pass of 2026-10-05 (64 findings; the rest is backlog #57–#68) and the Trivy fix in `docker/Dockerfile.prod`; two migrations run on the local MySQL; suite numbers: TEST-RESULTS in `docs/dev-notes/M2.md`. PR 9 (M1 + M2) merged into `main` on 2026-10-06 (`b4b6f77`).
Follow-up 2026-10-06 (D-102, `docs/dev-notes/M2.md` "Follow-up", branch `fix/auth-back-setelah-keluar`): the two leftovers of the browser check (account name back on the second Back; next login on the previous account's page) fixed, tested and checked in Chrome + Chromium. `dokumentasi.md` (repo root) lists the test data left in the lead's local database, which the lead chose not to wipe.
QA4 2026-10-06 (`docs/dev-notes/QA4.md`, branch `fix/ui-uji-ulang-qa4`): the five findings of the Chrome retest of PR 9 / PR 11 fixed: the TU preview of an issued letter now shows what its PDF printed (new column `letters.printed`, migration run on the local MySQL), the letter screen's error toast goes away once the failure is over (toast `key` + `sth-toast-clear`), the Talenta export carries title, watermark, filter and bold header, the import template's NIS/NISN/No HP are Text, a download resumes after the password page and that page has "Batal". Full suite `--parallel --processes=4` → **2027 passed (10926 assertions)**, exit 0; `pint --test` clean (630 files); `composer audit` clean; `npm run build` OK.
M3 2026-10-06 (`docs/dev-notes/M3.md` + `M3-A`, `M3-C`, `M3-D`, `M3-E`, D-103…D-108, plain summary in `catatan.md` §8; branch `feat/ui-uji-manual-m3`): the lead's six requests after pulling PR 12: the lomba rejection reason is optional; Talenta "Pulihkan" to a chosen stage and "Download data" with a status choice; "Buka" shows an evidence file in a preview popup (inline PDF / image, download stays); realistic demo documents + the local command `sth:demo:refresh-files` (run on the lead's database: 98 demo files); Humas gets "Data untuk flyer" + "Salin data" instead of the flyer generator. No migration in M3. Suite numbers: TEST-RESULTS in docs/dev-notes/M3.md. What stays open: backlog #69–#74.
QA5 2026-10-07 (`docs/dev-notes/QA5.md`, D-109…D-112, branch `fix/qa5-bug-fixing`, one bug-fixing PR): the retest of PR 13 in the app's browser and of every demo document; 5 bugs (Admin › Pengguna search hid the Kepala Sekolah, "Batal" on the password page looped, a reject toast claimed a notice nobody got, the guru saw "belum dikirim" for a report under revision, Talenta names cut at 1366 px), the lead's answers (guru pembina for a restore into Peserta pembinaan, export follows the screen filters without empty files, guru pembimbing notices for reports and appreciation), small UX items ("(Alumni)" in Apresiasi, "Detail publikasi", "Keluar" on /aktivasi, "Daring" once, letters in the popup on the guru screen) and the demo data (juknis per kind incl. `inovasi`, invitation number and date on the demo letters, `--lengkapi` hint; run on the lead's database). No migration. Suite numbers: TEST-RESULTS in `docs/dev-notes/QA5.md`. Then a second full sweep of every role at 1366 and 390 px ("Retest 2" in the note): 13 more fixes (one search box per screen and working TU / Kesiswaan top bar searches, audit labels, "Staf aktif", decision codes off the screens, `?kelas=` reset however the popup closes, forms that named their errors in rounds, a per-second resend countdown, the tour no longer back on reload, a separate rate-limit counter per route, "browser" instead of "peramban").
QA6 2026-10-07 (`docs/dev-notes/QA6.md`, D-113…D-116, after PR 14 was merged; not committed): end-to-end flows and edge
inputs in the app's browser. Ten fixes: confirmations before Kepala Sekolah "Setujui terpilih" and TU "Kirim", named
Persetujuan checkboxes, the guru letter takes the invitation typed for the student letter, Arsip surat › Koreksi opens
the chosen letter, the QR page knows an issued letter before "Kirim", no past assessment date, long student words wrap
in the Talenta panel, the ketua can withdraw an unanswered invitation (and sees who has not activated), the registrant
count on Detail lomba updates. No migration. Suite numbers: TEST-RESULTS in `docs/dev-notes/QA6.md`.

UI3 2026-10-07 (`docs/dev-notes/UI3.md` + `UI3-A`, `UI3-B`, D-117…D-119; on top of PR 15, branch `feat/ui-tinta-dan-sampul`): the lead's request to apply the UI audit of 2026-10-06 in full: new tokens and one typeface, the type scale in 78 files, covers (`<x-sth.sampul>`, 24 template drawings, posters through `files.cover`), the ink banner, calmer components, five broken phone layouts fixed, staff copy without "kamu". No migration. Full suite `--parallel --processes=4` → **2315 passed, 1 skipped (13923 assertions)**, exit 0 (559 s); `pint --test` clean (664 files); `composer audit` clean; `npm run build` OK. Browser: 77 pages at 1440 and 390 px plus 50 dialogs / popups / tour steps / special states (204 screenshots), every page measured (no sideways scroll, one font family, nothing under 13px, no gradient). Review of the look by the lead on localhost, 2026-10-07/08 (same note, D-120; branch `feat/ui-kapsul-dan-lambang`): no floating cards, one way to each place, same-height boxes, gold / silver / bronze medals, the lomba card as a capsule (poster portrait on the left) with `<x-sth.lomba-head>` for Detail lomba and Undangan tim and the same capsule in Portofolio, the school crest as the brand mark and tab icon, the student banner counting the lomba that student can still register for, and text that left its box at 1366 / 1280 / 360 px. No migration. Full suite → **2320 passed, 1 skipped (13992 assertions)**, exit 0; `pint` clean; `npm run build` OK; text scan on 76 pages at 1440, 1366, 1280, 390 and 360 px.
QA7 2026-10-08 (`docs/dev-notes/QA7.md`, on the lead's checkout of `feat/ui-kapsul-dan-lambang`, branch
`fix/qa7-explore-ui3`, one PR together with UI3): the checkout was clean and complete (main + PR 15 + UI3, suite 2320
passed before any change; the demo posters were added to the local database with `--lengkapi`; `node_modules` was
reinstalled in the container). Nine fixes: long words no longer push cards or pages sideways (a `body` rule plus the
columns that size themselves to a word, on the UI3 cards and on many older screens), the dot on the "Lomba saya" card,
the dashboard chart on a phone (a screen-reader table pushed it 111 px, year labels, bars), the TU letter tabs and the
Lomba saya letter card on a phone, catalog paging at 360 px, the penyelenggara tag in Verifikasi hasil, the phone tab
on the account pages, the WEBP cover message. Checked: 37 signed-in screens of every role and 5 public pages with
worst-case words at 360, 390, 1280 and 1366 px (rendered from the local demo data inside a rolled-back transaction).
No migration. Suite numbers: TEST-RESULTS in `docs/dev-notes/QA7.md`.
QA8 2026-10-08 (`docs/dev-notes/QA8.md`, branch `fix/qa8-explore` from `main` after PR 17): dates, the report → points flow, Humas,
the import and the letter PDFs, read by two agents and probed on the local demo data. Eight fixes: the signature of the
guru letter no longer stands alone on a page (and the student letter's closing stays with its signature), a file is
stored under a name that matches its content (Q1-20), Humas toasts claim a notice only when the student got one
(D-109), a report decided in another tab gives a toast instead of a 403, the queue card counts only the members still in
the team, "terlambat" belongs to the first report (and the screens say so), and the report days and archive months on
the screens follow the settings. A review agent read the commit; the two-tab case was checked in the browser as
Kesiswaan, which also found that Verifikasi hasil kept the address of the first report (now it follows the shown
report, hook `sth-address`). No migration. Suite numbers: TEST-RESULTS in `docs/dev-notes/QA8.md`.
QA9 2026-10-09 (`docs/dev-notes/QA9.md`, commit `e80c9d2` after EM1; merged into `main` by PR 19, merge commit `a2d21e1`
of 2026-10-09): five read-only agents explored (the allowlist mailer, the stale-screen pattern on every screen, Admin,
Talenta and Guru, public pages and consent), four implementer agents fixed in parallel, test-first, each on its own
files. 32 fixes, among them: the nightly archive no longer turns a disabled student into a named public alumnus, a stale
Talenta tab no longer moves a row twice, the student's review text stays out of the audit log, a mail sent by name can
no longer bypass the allowlist, a disabled teammate's certificate is no longer public, and about fifteen screens answer a
record changed elsewhere with a toast instead of a 403/404 page. No migration. Suite numbers: TEST-RESULTS in
`docs/dev-notes/QA9.md`. Open: backlog #79.
QA10 2026-10-09 (`docs/dev-notes/QA10.md`, commit `8b9762d` after QA9; merged into `main` by PR 19, merge commit `a2d21e1` of 2026-10-09): five read-only agents explored
registrations and letters, login / reset / 2FA, the student screens, lomba / points / exports, notifications and scheduled
jobs; four implementers fixed in parallel, test-first, plus four leftovers by the coordinator. About 30 fixes, among them: a
classmate can no longer keep a student's account locked (K3) while 30 failures a day still close it to unknown devices,
the login page names one retry time, reset codes belong to the browser that asked, the staff TOTP step can no longer be
guessed through other addresses, auto-publish no longer names a disabled student, two large personal-data exports
alert the Admins, letter mails no longer 404 after a correction, a lomba closed early no longer strands team invitations,
the Kepala Sekolah PDF report has a row limit. No migration. Suite numbers: TEST-RESULTS in `docs/dev-notes/QA10.md`. Open:
backlog #80.
EM2 2026-10-10 (`docs/dev-notes/EM2.md`, D-122…D-129, branch `fix/em2-uji-email` from `main` at `a2d21e1` = `origin/main`, the merge of PR 19 with EM1, QA9 and QA10; PR 20, not merged yet):
fixes for the lead's mail and notification test of 2026-10-09 (67 steps in 11 stories, 4 marked "bermasalah", 19 notes;
the tested code was EM1, before QA9 and QA10). Every note was diagnosed in the code first, then seven implementer
agents fixed in parallel, test-first, each on its own files. What changed: the bell and the sidebar counts follow the
state without a page load (new JSON address `notifikasi.jumlah`, asked by `app.js`); link fields are cleaned before the
check and every refusal names its reason; "Jenis penyelenggara: Lainnya" has a typed field; a changed fact of a
published lomba is told to the students registered for it and their guru pembimbing; the publication schedule only
reminds Humas (the auto-publish job is gone, "Lewat jadwal" on the screens, a count on the menu item Jadwal);
appreciation events have a start time and "Tambah prestasi" is a search with ticks and one "Masukkan"; "Tolak" on a
report and five more actions ask in the app's own dialog (no `wire:confirm` is left); notification and OTP mails come
from the app's own templates with one greeting rule and one link. Three migrations (`competitions.organizer_type_other`,
`publications.reminded_at`, `appreciation_events.held_time`). No code needed: 1-6 (the jumping lock times, fixed by
QA10), 3-6 (the test guide named the wrong path), 5-4 (the third team member has no address yet), and the Brevo notes
(provider limits: addendum in DECISIONS §D13). The three settings the test had changed on the lead's local database
(weights, letter template, signature image) were put back. Review after the build: one independent reviewer per track
and one for the seams between the tracks, every finding challenged by a skeptic before a fixer touched it: 13
confirmed, all minor (12 fixed in code with tests, 1 in the docs), 1 rejected, the nits left are listed in the note.
On the lead's dev stack on 2026-10-10: the three migrations ran on the local MySQL, the assets were rebuilt, php-fpm
was reloaded, `queue` and `scheduler` were restarted. Browser check by the coordinator the same day, as the demo
Kesiswaan account: the bell after "Tandai dibaca", the dialogs "Tolak laporan ini?" and "Tutup lomba ini?", the field
"Jenis penyelenggara lainnya", the info alert of "Ubah lomba", Apresiasi (time field, picker, search), two sample
mails in Mailpit. The dialog "Tandai sudah terbit?" was opened as the demo Humas account. Not checked in a browser: an overdue publication card and the Jadwal count, the Talenta, Import and "Batalkan undangan" dialogs,
phone widths, the bell's background tick and Back / Forward, a real mail in Gmail on a phone. The email scenario was run on the
isolated project (123 mails, 0 missing, 0 unexpected, 0 leaks, 0 failed jobs with the OTP test mode it expects).
On PR 20 the PHP job, gitleaks and the Playwright smoke passed; the Docker job first failed on Trivy for the nginx base of the web image (`tiff`, CVE-2026-4775), fixed on the `apk upgrade` line of `docker/Dockerfile.prod`. Suite numbers: TEST-RESULTS in docs/dev-notes/EM2.md.
The lead's answers of 2026-10-10 (chat): no notice to all students (D-126), the new mail look and greeting (D-129), the five swept dialogs (D-128) and "required before publishing" for the typed organizer kind (D-125) are accepted. Open: backlog #81.
RV1 2026-10-11 (`docs/dev-notes/RV1.md`, D-130…D-134; on the branch of PR 20, `fix/em2-uji-email`; committed and pushed
there on the evening of 2026-10-11 at the lead's request, PR 20 not merged): one working day with the lead, who showed the app to testers through a
Cloudflare Quick Tunnel from his laptop (three local files, not in git: `compose.demo.yaml`, `demo-start.bat`,
`demo-stop.bat`). During the demo: the open mode of the local mail allowlist (D-130), and the dead "Ikut tim" /
"Tolak undangan" buttons of Siswa › Undangan tim (the view's root had stood inside an `@if` since QA9; fixed, checked
in the browser). After the joint review, the lead's four requests: the student letter centred on the page (D-133);
the NIS in full on Status aktivasi and its CSV, Talenta and Database prestasi (D-131); the Kepala Sekolah may replace
the guru pembimbing with the approval, and the students' approval notice names their guru (D-132: `MentorChoice`,
`ApproveRegistration` with `$mentorId`, dialog `ganti-pembimbing`; the guru who was replaced is told nothing, D-134,
the lead's correction after he saw the dialog); and "make sure everything runs,
no simple errors like that button": three guard tests (`LivewireRootTest`, `PageWiringTest`, `ViewWiringTest`) that
open every page each role reaches with the demo data and read every Blade source; they found no other dead control,
no missing dialog, method, property, route or view, and the lead's stack had logged no server error during the review.
No migration. One independent review after the build: no blocking defect, four minor findings, all settled (an
account that is Kepala Sekolah and guru at once is never told about its own decision; a star next to a broken
allowlist entry no longer opens the list; the guard tests tightened). Checked in the app's browser on the isolated
project `sth-e2e` (fresh demo data, its own Mailpit): a team registration, the dialog "Setujui dengan guru pembimbing
lain?" with its validation and its result, and the seven mails of that flow through the real queue, each to the
right person; the email scenario passed there too (123 mails, 0 missing). On the lead's stack: php-fpm reloaded, `queue`
and `scheduler` restarted, the demo files of the student letters redrawn (`sth:demo:refresh-files`). Suite numbers
and what was not checked: TEST-RESULTS in `docs/dev-notes/RV1.md`. Later the same day, for the lead's question whether
a ZIP of the folder gives a friend the same setup: no, the database sits in a Docker volume; two local scripts (not in
git, like the demo files) make and install a copy, `demo-ekspor.bat` and `demo-impor.bat`, and a copy never sends real
mail until its owner opens it (the data holds the testers' real addresses). Found on the way and fixed in the app: a
bell row now keeps the path of its target (`BellChannel`, §5), because "Buka" had vanished from rows written under
another address (106 of 120 rows on the lead's laptop, rewritten once). And the lead's correction after he saw the
"Ganti guru" dialog (D-134): the guru who was replaced is told nothing, the dialog says who is told in two plain
sentences. Final state of the day: full suite **3439 passed, 1 skipped (21342 assertions)**, `pint --test` clean
(757 files), php-fpm reloaded and `queue` + `scheduler` restarted on the lead's stack. Open: backlog #82.
FS1-A 2026-10-12 (`docs/dev-notes/FS1.md`, D-135…D-136, branch `feat/fs1-feedback-sekolah`, not pushed): package A of the school's
feedback of 2026-10-12 (five packages, order A → D → C → E → B; design `2026-10-12-feedback-sekolah-fs1-design.md` in the lead's
workspace). Built: the panel "Pendaftaran lomba", the queue "Verifikasi usulan lomba" everywhere (menu, tab, Beranda, stat tile, mail
subject and button, Kepala Sekolah dashboard), "Database talenta siswa", the Database prestasi Excel without Kategori, the Admin menu item
Database prestasi, red count badges (sidebar and bell), pending lomba oldest first in the Kelola lomba catalog, a sidebar label that
wraps instead of being cut. No migration. Full suite `--parallel --processes=4` → **3466 passed, 1 skipped (21422 assertions)**, exit 0
(1889 s); `pint --test` clean (763 files); `npm run build` OK. Browser check (read from the page, the pane was hidden): Kesiswaan at 1366 and 390 px, Kepala Sekolah and Admin at 1366 px only.
Not checked or not run: the Siswa screens (panel title, Bantuan), the menu-tour positions (`TurMenu`), the Playwright smoke and the composer audit.
FS1-D 2026-10-12 (`docs/dev-notes/FS1.md`, D-137…D-138, branch `feat/fs1-feedback-sekolah`, not pushed): package D of the school's feedback (accounts). Built: students log in with the NISN only (or the bound email after activation; the NIS is stored only, a NIS gets the generic answer, now "NISN atau kata sandi salah."); the NISN replaces the NIS on Status aktivasi + CSV (with a hint line), Database talenta siswa, Database prestasi + Excel, Profil talenta and the import rows to check; searches take a name or a NISN; the import no longer cross-checks NIS against NISN; every student gets one initial password from config (`sth.initial_student_password`, env `STH_INITIAL_STUDENT_PASSWORD`), kept out of logs, refused as a new password, and replaced at the first login (activation has no "Lewati" any more). No migration. Full suite `--parallel --processes=4` → **3486 passed, 1 skipped, 1 failed (21371 assertions)**, 1832 s: the one failure was a test cleanup of this package (a constant from another test file), fixed in `9439eaf` and green alone; the suite was not run a second time. `pint --test` clean (765 files); `npm run build` OK. Browser check as Kesiswaan and Admin at the pane's own width (read from the page): login page, forgot-password page, the generic error, Status aktivasi, Database talenta siswa, Database prestasi, the Admin search placeholder. Not browser-checked (feature tests only): every student screen, a phone width, the downloaded Excel/CSV, the import dialog. Not run: the email scenario, the Playwright smoke, `composer audit`. Open: the lead confirms the new generic error text, the Kepala Sekolah signs the D-138 risk, no tool moves old accounts to the new initial password, a trim of `STH_INITIAL_STUDENT_PASSWORD` (FS1.md).

| area | owner | state (details in `docs/dev-notes/<ID>.md`) |
|---|---|---|
| Design: spec v2, 24-screen mockup, decisions, security baseline | user | done |
| Laravel 13 skeleton, packages, dev Docker stack (test-safe: no `env_file`, phpunit `force="true"`) | user | done |
| W1-A data layer: 27 enums, 23 models, migrations, factories, seeders (demo = local only) | → user | done |
| W1-B domain services: Points, Deadlines, Archive, Accounts, Letters, Registrations, Leaderboard, Files | → user | done |
| W1-C auth: NIS/NISN + username login, rate limits + lockout, activation, OTP, staff TOTP, middleware, security headers/CSP, account Actions, student import | → user | done |
| W1-D UI kit: tokens, fonts, 38 `<x-sth.*>` components, 4 layouts, Navigation, `/_dev/components` | → S | done |
| W1-E devops: `Dockerfile.prod`, `compose.prod.yaml`, Apache vhost sample, CI, deploy/backup/restore, `docs/DEPLOY.md` | → I | done (staging on a real 18.04 host pending) |
| W1-F docs: this file, AGENTS, README, CONTRIBUTING, TEAM, UI-MAP, dev-notes protocol | – | done |
| W2-A1 siswa 1: beranda + tur, katalog + usulan, detail + daftar, undangan tim, bantuan; `StoreUpload`, `FilePolicy`, `files.show` / `files.public`, `ExternalUrl` | → S + user | done |
| W2-A2 siswa 2: lomba saya + tracker, laporkan hasil, portofolio (+ PDF), profil talenta; scheduler `sth:registrations:advance`, `sth:reports:remind` | → S + user | done |
| W2-B kesiswaan: kelola lomba + verifikasi usulan, verifikasi hasil (points), talenta, import siswa | → S + user | done |
| W2-C kepsek persetujuan + dashboard, TU surat tugas (dompdf + QR, issue → send), Humas publikasi/jadwal/apresiasi/arsip, `/verifikasi-surat` | → S + user | done |
| W2-D public pages, Admin pengaturan (6 tabs) + pengguna + ringkasan, archive job, `sth:create-admin`, `lang/id` | → S + user | done |
| W3-A integration: settings-bound `ReportDeadline`/`GraduationCalculator`, `SettingKey::Public*`, `PersonalRank` (D-37), `UpdateConsent`, public certificate links, `ExternalUrl` for lomba URLs, per-test disks, UI fixes, nginx headers, Pint, D-32…D-44 | – | done |
| B1 accounts: Akun › Keamanan, Notifikasi (bell count), Status aktivasi (K9 + CSV, K2 reopen), K1 window, K3 freeze, K8 "Bukan saya?" (`email_notices`), K10 temporary password | → user + S | done (backlog #1, #5, #15) |
| B2 security: upload limits, `security` log channel, OTP cleanup, audit hash chain (`audit_seals`, `sth:audit:verify`), CSP-safe Livewire (no `'unsafe-eval'`), Livewire update throttle, queued import (`student_imports`) | → user | done (#10, #16, #17, #18, #19) |
| B3 registrations/letters: `InviteMembers` + invitation expiry, letter correction (`RevokeLetter`, versions), Humas auto-publish, timeline Actions | → user | done (#9, #11, #12, #14) |
| B4 screens: Siswa Peringkat (active only), Guru Siswa binaan + detail + Lomba, Kesiswaan Database prestasi + export | → S | done (#2, #3, #4) |
| B5 kepsek/TU/admin: Prestasi per bidang, Laporan (export), Surat atas nama saya, TU Template surat, Admin Arsip otomatis + Log audit, role change approval (`role_change_requests`) | → S + user | done (#6, #7, #8, #13) |
| B6 devops/docs: CI (SHA pins, gitleaks, Trivy, E2E Playwright), per-table DB grants (`grants.sh`), `sth:mail-test` + DEPLOY §9 SMTP, favicon, PANDUAN-PENGGUNA, Bantuan text, ARCHITECTURE §4 ratified, D-45…D-60, UI-MAP | → I | done (#20, #22, #23, #24, #25) |
| W4 integration: scheduler `sth:peran-tertunda`, menus (Status aktivasi, Surat atas nama saya, Log audit, Arsip otomatis, account items), new privileged staff accounts via role request (second Admin / 24 h), `SettingKey::ActivationWindowDays`/`LetterTemplate` (`OtpTestMode` removed), `User` casts, staff resets in K10 alert, signature upload via `StoreUpload`, Ringkasan events, per-process compiled views in tests | – | done (#27, #28, #29, #30, #31) |
| D1 docs sync: DECISIONS D-61…D-65, ARCHITECTURE §4 (settings = `SettingKey`, `account.staff_created`), UI-MAP menus, PANDUAN-PENGGUNA (Admin, Notifikasi/Keamanan akun), this file §2/§5/§7/§8/§10 | – | done |
| CI on GitHub: `.env` before `composer install` (the production boot guard stopped `package:discover`), dev MySQL healthcheck over TCP (`compose.yaml`; "healthy" came before port 3306 was up), Dependabot bumps PHP 8.5 / MySQL 9.6 / Node 26 reverted + ignore rules, `start.bat` / `stop.bat` | → I | done (first green run 2026-10-02; CI half of #21) |
| Q1 full check (`docs/dev-notes/Q1.md`, D-66…D-74): email scenario for every role (`tests/E2E/support/email-scenario.php`), security review + fixes (student TOTP lockout, confirm-password limit, PDF scanner, Markdown in mails, mail to frozen/unverified accounts, Humas after withdrawn consent, Talenta export), performance with school-sized data (list limits, gzip for the Livewire script, cache prune); follow-up D-75…D-77 (Admin flyer access, no web address in lomba names, reject/cancel notices for guru and Kepala Sekolah) | – | done |
| PERF1 dev page speed (`docs/dev-notes/PERF1.md`): opcache without timestamp checks (`docker/php/php.dev.ini`), `reload.bat`, `wire:navigate` on the chrome links (`App\Support\Links`) | – | done (follow-up open: repo on a Linux filesystem) |
| M1 the lead's hand test of 2026-10-04, 18 changes (`docs/dev-notes/M1.md` + `M1-A`…`M1-E`, D-78…D-91): student login by email + activation email → code → password (M1-B); public ranking filter + yearly import updates classes (M1-C); letters with a typed full number, one form per letter, the school's own templates, "Cetak" (M1-A); talent notes, Pulihkan, Minta ditinjau ulang (M1-D); appreciation events confirm/delete, Status aktivasi class popup (M1-E); sidebar counts without reload, live top bar search, everyday words, dev php-fpm config/view cache + test guard (coordinator) | – | built; four migrations run on local MySQL; email scenario + Playwright smoke not re-run (backlog #46) |
| M2 fixes for the 12 most important findings of the QA pass of 2026-10-05 (`docs/dev-notes/M2.md` + `M2-A`…`M2-C`, D-92…D-102) and QA4 = S's PR 12, five findings of the retest (`docs/dev-notes/QA4.md`, D-108) | – / → S | done (status lines above); the rest of the QA list = backlog #57–#68 |
| M3 the lead's six requests of 2026-10-06 (`docs/dev-notes/M3.md` + `M3-A`, `M3-C`, `M3-D`, `M3-E`, D-103…D-107): lomba rejection reason optional (coordinator); Talenta "Pulihkan" to a chosen stage + "Download data" with a status choice (M3-A); file preview popup with the hooks `data-sth-preview*` and `data-sth-copy` (M3-C, three more screens by the coordinator); realistic demo documents + `sth:demo:refresh-files` (M3-D); Humas "Data untuk flyer" instead of the flyer generator (M3-E) | – | built; no migration; browser spot checks in Chromium only (SPOT-CHECKS in `docs/dev-notes/M3.md`); open: backlog #69–#74 |
| QA5 fixes after the retest of M3 on 2026-10-07 (`docs/dev-notes/QA5.md`, D-109…D-112): five bugs, the lead's answers (guru pembina for a restore into Peserta pembinaan, export follows the screen filters, guru pembimbing notices for reports and appreciation), small UX items, demo juknis per kind and demo letter invitations | – | built; no migration; `--lengkapi` run on the local database; browser check of Talenta at 1366 px and its download dialog |
| QA6 explore round after PR 14 on 2026-10-07 (`docs/dev-notes/QA6.md`, D-113…D-116): confirmations (bulk approve, Kirim), letters (shared invitation fields, Koreksi from Arsip, QR before Kirim), talent (assessment date, long words), team invitations (withdraw, not activated), registrant count | – | built; no migration; browser check of Talenta (Kesiswaan); Kepala Sekolah, TU and student screens render-tested only |
| QA7 explore round after UI3 on 2026-10-08 (`docs/dev-notes/QA7.md`): long words on every screen (`body` rule + `[overflow-wrap:anywhere]` columns), dashboard chart / TU tabs / Lomba saya letters / catalog paging on a phone, the phone tab on account pages, the WEBP cover message | – | built; no migration; 42 pages × 4 widths measured in headless Chromium with worst-case words; dialogs, Admin › Pengguna / Pengaturan and the PDFs not checked |
| QA8 explore round after PR 17 on 2026-10-08 (`docs/dev-notes/QA8.md`): letter signature never alone on a page, stored file names follow the content, honest Humas toasts, a report decided elsewhere, queue team count, the late flag after a revision, report days / archive months from the settings | – | built; no migration; letter PDFs probed with long and extreme words, dialogs measured at 360 / 390 px; the agents' lower-confidence points are open questions in the note (#77) |
| EM2 fixes after the lead's mail and notification test of 2026-10-09 (`docs/dev-notes/EM2.md`, D-122…D-129): live bell and sidebar counts (`notifikasi.jumlah`), link fields (clean first, one sentence per reason), "Jenis penyelenggara lainnya", lomba change notice, publication schedule = reminder only (`RemindDuePublications`, "Lewat jadwal"), appreciation start time (`EventWhen`) and picker (`RecordAppreciations`), confirm dialogs instead of one-click or native confirms (`NoNativeConfirmTest`), mail templates + `MailGreeting` | – (lead's request; touches user and S files) | built and reviewed (13 minor findings, all settled), PR 20 (branch `fix/em2-uji-email`, not merged yet); three migrations, run on the local MySQL on 2026-10-10; browser check by the coordinator of the bell, the Kesiswaan screens, Apresiasi, the Humas dialog "Tandai sudah terbit?" and two sample mails in Mailpit; the other dialogs, an overdue publication card, phone widths and a real mail in Gmail unseen; email scenario run on the isolated project (123 mails, 0 missing); Playwright smoke green in CI on PR 20; the lead accepted the four coordinator choices he was asked about (2026-10-10); open: backlog #81 |
| RV1 the demo and the joint review of 2026-10-11 (`docs/dev-notes/RV1.md`, D-130…D-134): open mode of the local mail allowlist, "Ikut tim" fixed, student letter centred, NIS in full for Kesiswaan and Admin, guru pembimbing replaced at the approval (`MentorChoice`, dialog `ganti-pembimbing`; the replaced guru is told nothing), guard tests for dead controls (`LivewireRootTest`, `PageWiringTest`, `ViewWiringTest`), bell rows keep a path (`BellChannel`) | – (lead's requests; touches user and S files) | built; committed and pushed to `fix/em2-uji-email` (PR 20, not merged) on 2026-10-11; no migration; browser check of the approval dialog and the mails on the isolated project, of "Ikut tim" on the lead's stack; the other screens render-tested and crawled; open: backlog #82 |
| QA10 explore round after QA9 on 2026-10-09 (`docs/dev-notes/QA10.md`): login locks per account + address (K3), reset-code and TOTP limits per account, named rule for Humas / auto-publish, large-export alerts, letter links, honest notices, registration window after an early close, expired invitations, student screens, lomba form / Bobot / PDF cap / NoWebAddress, scheduler `--date` and overlap locks | – | merged into `main` by PR 19 (`8b9762d`, merge commit `a2d21e1`, 2026-10-09); no migration |
| QA9 explore round after EM1 on 2026-10-09 (`docs/dev-notes/QA9.md`): stale screens everywhere (toast instead of 403/404), archive vs disabled accounts, Talenta double move and honest toasts, audit-log privacy, named-mailer guard, public certificates, Admin resets / role changes / import password, public cache after a lomba edit | – | merged into `main` by PR 19 (`e80c9d2`, merge commit `a2d21e1`, 2026-10-09); no migration; browser check of the Kesiswaan screens only (see the note) |
| UI3 visual direction v3 "Tinta dan Sampul" on 2026-10-07 and the lead's review of it on 2026-10-07/08 (`docs/dev-notes/UI3.md` + `UI3-A`, `UI3-B`, D-117…D-120): tokens + Public Sans + type scale, `<x-sth.sampul>` and `files.cover`, banner motif, component and phone-layout fixes, staff copy; then capsules (`<x-sth.lomba-card>`, `<x-sth.lomba-head>`, Portofolio), the school crest as brand mark, one way to each place, same-height boxes | – (lead's request; touches S and user files) | built; no migration; full suite green; every page checked in the browser at 1440 and 390 px, after the review also a text scan at 1366, 1280 and 360 px; backend owner still has to review D-118 (*sementara*); the school should give a larger or vector crest (D-120) |

Verified 2026-10-01 (W3-A, details in `docs/dev-notes/W3-A.md`): `php artisan test` → **925 passed (3726 assertions)** twice in a
row (SQLite; `--parallel --processes=4` also 925 passed twice); `pint --test` clean; `npm run build` OK; `composer audit` clean; prod compose `config -q` OK;
`docker build -f docker/Dockerfile.prod` OK. Local MySQL migrated; dev `queue` drains `otp,mail,default`.
Backlog wave (`docs/dev-notes/B1.md`…`B6.md`): full suite `--parallel --processes=4` with all B code → **1253 passed (5606
assertions)**, exit 0 (B5); a concurrent B6 run had 1252 passed + 1 `Akun\KeamananTest` error from a Blade compile `tempnam()` race
in the shared compiled-view folder (11/11 pass alone). Prod image with all B migrations + `grants.sh` on a throwaway prod stack:
`activity_log`/`audit_seals` UPDATE/DELETE denied, app + audit chain OK; E2E smoke 2 passed; gitleaks/Trivy clean; actionlint/shellcheck clean.
W4 (`docs/dev-notes/W4.md`): full suite `--parallel --processes=4` → **1283 passed (5861 assertions)** twice in a row, exit 0 (467 s / 457 s);
`pint --test` clean (567 files); `composer audit` clean; `npm run build` OK; `docker build -f docker/Dockerfile.prod -t sth-app:prod .` OK.
Local MySQL migrated (W4 migration), `RoleSeeder` + `SettingSeeder` re-run, `scheduler` + `queue` recreated (`schedule:list` shows `sth:peran-tertunda`).
GitHub CI 2026-10-02 (push to `main`, commit `33c039b`): PHP (validate, Pint, Pest), Frontend, Composer audit, gitleaks (full history),
Docker (prod images, Trivy, prod compose) and E2E (Playwright smoke, 2 tests) → all green, the first fully green run.
Q1 2026-10-02 (`docs/dev-notes/Q1.md`): full suite `--parallel --processes=4` → **1486 passed (6855 assertions)**, exit 0 (677 s);
`pint --test` clean (585 files); `composer audit` and `npm audit --omit=dev` clean; `npm run build` OK; prod images `app` + `web` build,
`nginx -t` OK inside the web image; email scenario on the isolated `sth-e2e` project → **114 mails, 0 missing, 0 unexpected, 0 leaks,
0 failed jobs**; page profile with 1,171 students / 1,393 registrations / 1,420 achievements: query count per page unchanged from the
demo data (no N+1), largest pages after the list limits ≈ 190 kB.
After the follow-up D-75…D-77 (same day): full suite → **1515 passed (6962 assertions)**, exit 0; `pint --test` clean (588 files);
email scenario → **122 mails, 0 missing, 0 unexpected, 0 leaks**; upload check on 19 locally made PDFs → 14 accepted, 5 refused as expected.
GitHub CI on `a148fd8` (after the `pcre2` upgrade in the web image, CVE-2026-103111): all six jobs green. Docs brought in line the same
day: PANDUAN-PENGGUNA, ARCHITECTURE §8a/§8b (notification recipients), UI-MAP §6a, DEPLOY §18 (Trivy procedure).
M1 2026-10-04 (D-78…D-91): what was built, what was checked by hand and the final suite numbers are in `docs/dev-notes/M1.md`.

### Backlog (open; collected from all dev-notes and UI-MAP)
Owner: **S** = frontend/screens, **I** = deploy/QA, **user** = lead/backend core (also asks the school).
Done in the backlog wave B1–B6 (details in the B dev-notes): former #1–#20, #22–#25; in W4 (`docs/dev-notes/W4.md`): #27–#31
(scheduler `sth:peran-tertunda`, menus, privileged staff accounts, settings/model tidy-up, K10 staff resets, signature upload via
`StoreUpload`). The optional `OtpPurpose::ChangeEmail` of #30 was not done (now #35). Kept numbers for the open items.
Closed by M1 (2026-10-04): the "letter template details" part of #26 (decided by the lead, D-82…D-84). Added by M1: #45–#56.
M2 (2026-10-06): the "re-run" half of #46 is done (email scenario on 2026-10-05 with the M1 code → 122 mails, 0 missing, 0 unexpected,
0 leaks, 0 failed jobs; Playwright smoke green in CI on PR 9). Added by M2: #57–#68 = what stays open from the QA pass of 2026-10-05
(`hasil-uji/ringkasan-temuan.md` next to the repo; QA ids Q1-xx / Q2-xx; one line per finding in `docs/dev-notes/M2.md`).
QA4 and M3 (2026-10-06) closed or changed parts of #39, #46, #55, #57, #63, #65, #66 and #67 (marked in the rows). Added by M3:
#69–#74 = what the four M3 notes and QA4 leave open (`docs/dev-notes/M3.md`). QA5 (2026-10-07) closed or changed parts of
#46, #56, #63, #67, #69 and #71; QA6 (same day, after PR 14) parts of #46, #61, #62, #65 and #66. QA8 (2026-10-08) closed the Q1-20 part of #62 and added #77. QA9 (2026-10-09) closed one part of #77 and added #79. QA10 (same day) added #80. EM2 (2026-10-10) closed or changed parts of #46, #63, #65, #75, #77 and #78 and added #81. RV1 (2026-10-11) changed #46 and #78 and added #82.

| # | item | source | owner |
|---|---|---|---|
| 21 | Staging on a real Ubuntu 18.04 / Apache 2.4.29 host (static Docker binaries, cgroup v1). The first real CI run on GitHub is done (2026-10-02, all six jobs green) | W1-E, B6 | I |
| 26 | School answers (§9): parental consent process (then `public_indexing`/`public_certificates`), SMTP relay + DNS records (DEPLOY §9), activation window/school hours (D-54), U-01…U-06. (Letter template and number format: decided by the lead, D-82…D-84) | DECISIONS §F | user |
| 32 | Queued achievement export + signed URL (D-53 follow-up) if exports grow; sort options on Database prestasi | B4 | user + S |
| 33 | K9 extra flags (activation outside the school network: needs the school CIDR; ≥ 3 activations per browser session/hour); K10 items 4–5 (block the hijacker's email, hijack-period timeline) | B1 | user |
| 34 | Never schedule `activitylog:clean` without pruning `audit_seals` the same way; consider HMAC for the audit chain (D-63) | B2 | user |
| 35 | Optional `OtpPurpose::ChangeEmail` (change-email OTP still uses `verify_email`): touches `ChangeEmail`, `CompleteActivation`, `OtpCodeMail` texts, Keamanan tests | B1, W4 | user |
| 36 | Status sistem: attention item "N perubahan peran menunggu" (today only the panel in Pengguna & peran shows pending role requests) | W4 | S + user |
| 37 | Browser check of the W4 menus (Status aktivasi, Surat atas nama saya, Log audit, Arsip otomatis, avatar Notifikasi) and "Tambah pengguna" with a privileged role (render-tested only) | W4 | I |
| 39 | After a withdrawn publish consent the flyer file, the old caption and scheduled appreciation rows stay on purpose (D-75: Humas blocked, Admin keeps the download); SECURITY §3i asks for removal within 3×24 h, which is still a manual task. Since D-107 the flyer part concerns only flyer files made before 2026-10-06: STH makes no new ones and no screen links the old ones (`FilePolicy` still serves them at `/berkas/{file}`); deleting them is a separate, destructive step the lead has to decide (#71) | Q1, M3-E | user + S |
| 40 | A talent application (SaveTalentProfile / ApplyTalent) notifies nobody; Kesiswaan only sees it on the pipeline screen. (Reject/cancel notices for guru and Kepala Sekolah are done, D-77; #38 lomba names with a web address is done, D-76) | Q1 | user |
| 41 | Humas holds `achievement.view_all`; only the route middleware keeps Humas out of Database prestasi: tighten `AchievementPolicy::viewDatabase` or the Humas permission set. Unify the neutral-name rule (`PublicAchievements` + `AchievementFacts`) | Q1 | user |
| 42 | Staff 2FA follow-ups: hourly ceiling for confirm-password (D-67 is 5 per minute), staff can still disable their own TOTP after confirming the password (audited; `staff.2fa` forces setup again) | Q1 | user |
| 43 | `PdfScanner` (D-70): PDFs made on the lead's laptop with Word, Excel, PowerPoint, "Microsoft Print to PDF", Edge, Chrome and photo/scan images are accepted, hostile and oversized ones refused (19 files; results and a rerun script are in `uji-pdf/` next to the repo, not in git). Still to try: Canva downloads, the school's copier/scanner, phone scan apps, organizers' original certificates | Q1 | I |
| 44 | A staff account reset by an Admin only gets account notices until its owner verifies the email again (D-68): add this to PANDUAN-PENGGUNA (Admin). Dev stack: after a Docker restart `queue`/`scheduler` come up before MySQL and fill `laravel.log` with connection errors until `start.bat` runs | Q1 | I |
| 45 | *(12 Okt 2026, D-138: no new activation keeps the school password, so the flag only matters for accounts activated before; the risk-acceptance form also needs the D-138 risk.)* Counterweight for D-79: flag "masih pakai kata sandi dari sekolah" in Status aktivasi (active student with `password_changed_at` null, or the audit event `activation.password_kept`) + a reminder to change it; a pending row can now show a masked email (bound, password step open). Add the D-79 risk to the D-14 risk-acceptance form. Since D-92 `users.temporary_password_issued_at` tells an Admin's temporary password from the school password, so the flag can name only the kept school passwords | M1-B, M2-A | user |
| 46 | ~~Re-run `tests/E2E/support/email-scenario.php` and the Playwright smoke after M1~~ (done 2026-10-05, see above; not re-run with the M2 code unless TEST-RESULTS in `docs/dev-notes/M2.md` says so). Still open: scenario steps 9d/9e: talent review request (mail to every Kesiswaan) and restore (mail to the student), a step that deletes an appreciation event (mail to the scheduled students), and a step for `ReleaseAppreciation` (mail "Jadwal apresiasi berubah" to the student, D-97); the restore step could pass a target stage (D-104); since QA5 also the guru pembimbing mails of D-110 (report decisions, appreciation; for the decisions and "Masukkan" see the EM2 sentences below); since QA6 the mail "Undangan tim dibatalkan" (D-115). Neither the scenario nor the smoke was re-run with the M3 code by the M3 agents. EM2 edited the scenario for its changes and ran it on 2026-10-10 (123 mails, 0 missing, 0 unexpected, 0 leaks, 0 failed jobs with `STH_OTP_TEST_MODE=true` on the isolated project; with a developer `.env` that has real OTP codes the two work mails of the unverified second Humas account are reported missing, so pin that flag on the E2E `app` service): 8b (`CreateAppreciationEvent` with a time), 8c (only the Humas reminder at the scheduled time), new 8d (the row is still `terjadwal`, then `PublishPublication` → the student's mail), 10j (greeting "Yth. …"), 11a (`RemindDuePublications`); its template check and leak scan depend on the new mail template. The review of EM2 added the four guru pembimbing mails the expected lists had been missing since D-110 (7b "Laporan binaan perlu revisi: …", 7d "Laporan binaan diverifikasi: …", 7f "Laporan binaan ditolak: …", 8b "Binaan masuk daftar apresiasi"), written by reading the notification classes and confirmed by that run. The guru mails for `ReleaseAppreciation` and `DeleteAppreciationEvent` still have no step. Still missing since EM2: a step that edits a published lomba with a running registration (D-126). When it is written: after 4c and before 6a; change the place only (a new batas daftar is told only to `menunggu_konfirmasi`); expect one "Info lomba berubah" per member of **every** registration on that lomba and one "Info lomba binaan berubah" to their guru, no lomba address in either; confirm the recipients with a real run. Since RV1 (D-132) also missing: a step that approves with another guru than the student chose (the mails are named in #82; seen once by hand on the isolated project on 2026-10-11) | M1, M1-D, M1-E, M2-B, M3-A, EM2, RV1 | I |
| 47 | Optional sidebar badge on "Talenta siswa" for open review requests (`TalentApplication::query()->reviewRequested()->count()`): since D-100 a badge key in `Navigation` + one line in `NavigationServiceProvider::count()` | M1-D, M2-C | S |
| 48 | `<x-sth.modal size="xl">` (≈ 960 px) so the Status aktivasi class popup can show the email as its own column (today `lg`, 760 px) | M1-E | S |
| 49 | `student.class_changed` (about 750 rows per yearly import): should it be in `SecurityEventSubscriber::AUDITED_EVENT_PREFIXES`, and in the history of `Admin\ArsipOtomatis::EVENTS` (it can move an archive date)? | M1-C | user |
| 50 | Confirm the *sementara* points of D-81 and D-86: the yearly file wins over a manual archive date; a registered student who is missing from the file is left alone (list or flag "siswa aktif yang tidak ada di file"?); Kesiswaan may restore a rejected talent choice without a student's request | M1-C, M1-D | user |
| 51 | Tata Usaha and Kepala Sekolah hold `student.view`; only the route middleware keeps them out of Status aktivasi (same kind of gap as #41): tighten the policy or the permission sets | M1-E | user |
| 52 | ~~CSP console error "Applying inline style violates … style-src"~~ (done QA8: a fresh page load logs none; the one per `wire:navigate` page change, Q2-25, came from Livewire's own `<style data-livewire-style>` arriving with the next page's nonce, and every CSP now allows that style by its hash, `SecurityHeaders::livewireStyleSource()`; checked in the browser: no violation over two page changes) | M1-C, QA | S |
| 53 | ~~The TU letter screen (`tu.surat.show`) overflows sideways below about 480 px~~ (no longer reproduces at 390 px, QA5 retest) | M1 | S |
| 54 | Print both letters on a real printer ("Cetak" was only opened on screen) and compare them with the school's paper; then confirm `resources/pdf/assets/**` is in the production image (read only so far) | M1-A | I |
| 55 | ~~Update `panduan-uji-manual-sth.html` (outside the repo, next to it) to the M1 screens~~ (rewritten on 2026-10-06). Still to do: check it against the M3 screens (optional rejection reason, restore dialog, download dialog, preview popup, "Data untuk flyer"); the docs pass of M3 did not open it | M1, M3 | user |
| 56 | Small leftovers of the M1 notes: old note text "mengganti kata sandi dan menghubungkan email" in `resources/views/dev/{auth,components}.blade.php` and the example in `components/sth/alert.blade.php`; ~~the generic login error still says "NIS/NISN atau kata sandi salah." although it now also answers an email (`lang/id/auth.php`, `lang/id.json`, `LangIdTest`)~~ (done FS1-D, D-137: "NISN atau kata sandi salah."); `AppreciationEventFactory::completed()` state; demo letters without `schedule_date` (~~invitation number and date~~: done QA5; `basis_from` is filled); no bulk "Pulihkan" and no note for the student in the bulk "Pindahkan ke …"; the guru pembina is not told when a talent choice is rejected or restored; Kepala Sekolah "Surat atas nama saya" only downloads (no "Cetak"); `<x-sth.icon>` has no printer icon ("Cetak" uses `external`) | M1-A…M1-E | S + user |
| 57 | Reason fields (Q1-18, rest): "Buka ulang" needs 10 characters (D-95); the lomba rejection is no longer in this list (its reason is optional since D-103, by the lead's request); reject a registration (Kepala Sekolah), reject / ask a revision of a report and "Buka pembekuan" (`UnfreezeAccount`) still accept two letters. Same minimum there needs the lead's yes | QA, M2-B, M3 | user |
| 58 | Confirm the two *sementara* points of §D7: quick actions and bulk moves in Talenta send a saved draft note (D-96); students cannot open the guru letter, which the QA summary asked the lead to decide (D-99) | M2-B, M2-C | user |
| 59 | Browser check of M2, done on 2026-10-06 in Chrome (`hasil-uji/qa-3-cek-perbaikan.md`): Back after Keluar (D-102), activation after an Admin reset, the dialogs `tidak-lolos-talenta` and "Lepas dari acara", 403 / 404, `GET /logout`, the redirect after login. Still not run: the Back scenario in Firefox and Safari, the dialog `lomba-tanggal-lewat` (publishing with past dates), "Tambah prestasi" for an achievement that is already on an event, the error pages 419 / 429 / 500 / 503. On a production-like stack (`APP_DEBUG=false`, https, real OTP): repeat Q2-03 (405 size and time), check the `secure` cookie + HSTS and that links in mails use the configured host, not the request's (Q2-08, suspected) | M2, QA | I |
| 60 | ~~Top bar search (Q1-06, Q1-12, Q2-24): it does not filter the TU queue (`TataUsaha\SuratTugas` has no `q`) and points to `/tu` from Arsip surat; Kesiswaan's box says "Cari lomba atau siswa" but only finds lomba; two search boxes at once on Guru › Siswa binaan and TU › Arsip surat. One box per screen, label matching what it finds (D-90)~~ (done QA5 retest: TU queue `q`, labels, one box per screen) | QA | S |
| 61 | ~~Letters (Q1-13, Q1-15, Q1-23): "Koreksi" from Arsip surat opens the siswa tab instead of the chosen letter; what TU typed for the siswa letter is not carried into the guru form; a letter revoked **before** it was sent answers "Tidak ditemukan" on `/verifikasi-surat` although TU could already print it~~ (done QA6: `?koreksi=`, D-116, D-113). Still for the lead: confirm D-113 (*sementara*) or choose "no Cetak before Kirim" | QA | S + user |
| 62 | Data checks (QA): ~~a PNG named `.pdf` is accepted as a brochure (Q1-20)~~ (done QA8: accepted, stored as `.png`); ~~an assessment date in the past (2020) is accepted in Talenta (Q2-13)~~ (done QA6, D-114); the per-class table of Status aktivasi counts alumni (Q2-11) [user + S]; ~~Admin › Status sistem "Staf aktif" counts accounts that are not activated~~ (done QA5), and "Aktivitas Admin terbaru" shows fewer rows than its title (Q2-12; 8 of 8 on 2026-10-07) [S + user] | QA | user + S |
| 63 | Account pages (QA): ~~no "Keluar" on `/aktivasi` (Q1-11, Q2-04)~~ (done QA5); "Email ini sudah dipakai akun lain." lets a signed-in account test which emails exist: limit the tries or answer neutrally (Q1-19, Q2-06) [user]; ~~a download that asks for the password does not continue after the confirmation, and that page has no "Batal" (Q2-18)~~ (done QA4); ~~closing the Status aktivasi class popup with X or a click outside leaves `?kelas=` in the address (Q2-16)~~ (done QA5, `on-close`); ~~the bell count stays until a reload after "Tandai dibaca" (Q2-26)~~ (done EM2, D-127; the QA5 retest that had struck this through checked "Tandai semua dibaca", which reloaded the page on purpose; browser check of the new client side: #81) | QA | S + user |
| 64 | Admin › Log audit (Q2-33, Q2-35): ~~16 events still show their raw key~~ (done QA5: every key the app writes has a label, a test scans `app/`), the Subjek column shows ids, details are raw JSON (the new properties `previous_expires_at`, `past_dates`, `student_note_from_draft` too), the CSV uses commas while the Status aktivasi CSV uses semicolons | QA, M2-B | S + user |
| 65 | Forms and lists (QA) [S unless noted]: ~~validation messages appear one at a time in Usulkan lomba, Kelola lomba, Tambah pengguna, Jadwalkan, Buat acara (Q1-08, Q2-28)~~ (done QA5 for Tambah pengguna, Jadwalkan, Buat acara; Kelola lomba and Usulkan lomba already showed every error); ~~"Tambah prestasi" is one select without search (Q2-20)~~ (done EM2, D-124: search, ticks, one "Masukkan"); ~~one long word from a student makes Kesiswaan › Talenta scroll sideways (Q2-29)~~ (done QA6); Kepala Sekolah › Persetujuan: ~~"Pilih semua" does not follow the selection~~ (follows it on 2026-10-07), ~~bulk approve and TU's "Kirim" have no confirmation (Q1-10)~~ (done QA6); ~~native `confirm()` in Import and "Tandai dipublikasikan" (Q2-34)~~ (done EM2, D-128 / D-122: both ask in an `<x-sth.modal>`, also Kelola lomba "Tutup"; `NoNativeConfirmTest` keeps `wire:confirm` out); ~~tour highlight about 23 px off~~ (within 1 px on 2026-10-07; the tour also no longer comes back on reload, QA5), last button "Lanjut" on step 5 of 5 before the closing card (Q1-09); ~~"Kirim ulang" countdown jumps every 5 s (Q1-24)~~ (done QA5); the note for the guru pembina is cut at about 300 letters (Q2-21); ~~Humas › Arsip: empty Flyer column (Q2-19)~~ (done M3-E: the column is gone, the last cell is "Lihat"); Database prestasi: no sorting (Q2-23, see #32) | QA | S |
| 66 | Import and teams (QA): ~~the Excel template's NIS/NISN columns are not Text (leading zeros are lost)~~ (done QA4, No HP too) and the "rows to check" file lacks NISN and birth date (Q1-21) [user + S]; ~~a student who is not activated can be invited to a team, and an open invitation cannot be withdrawn (Q1-22)~~ (done QA6, D-115: the search marks "belum aktivasi akun", the ketua withdraws an unanswered invitation) | QA | user + S |
| 67 | Wording (Q1-27, Q1-28, Q2-36; together with #56): ~~"peramban" in the mail footer~~ (done QA5, also the session list), ~~"Daring · Daring"~~ (done QA5), ~~mixed "Anda" / "kamu" for staff~~ (done UI3, D-119), ~~internal codes such as "(D-08)" on Admin screens~~ (done QA5; the last one, "(U-04)", UI3), "1000" vs "1.000". Speed and phone width (ringkasan §5, §6): Kepala Sekolah › Laporan PDF 8.7 s and letter PDF 1.7–3.4 s without a "sedang dibuat" text (~~flyer PNG 3.3 s~~: no longer applies, STH makes no flyer since D-107), portfolio PDF 878 kB for one empty page (fonts not subset), ~~Kepala Sekolah dashboard scrolls 107 px sideways at phone width (Q2-31)~~ (no longer reproduces at 390 px, QA5 retest), touch targets under 44 px (Q2-32; see also #53) | QA | S + user |
| 68 | Small leftovers of the M2 notes: optional demo rows (a pending student with less than 72 h left, a talent row with a `student_note_draft`); optional `data-sth-back` hook for a real history back on the error pages ("Kembali" is a plain link to the Referer); an unknown address has no session, so its 404 links to `/` even for a signed-in visitor; Fortify's login keeps the guest session row from before the login (no `user_id`); the temporary password an Admin types for staff is not checked against the common-password list; `docs/dev-notes/W1-D.md` still shows the old badge resolver signature | M2-A…M2-C | user + S |
| 69 | Preview popup (D-105) on the screens that still only download a file: Kesiswaan › Kelola lomba, drawer "Periksa lomba" (`Kesiswaan\Lomba\Form`, juknis), Siswa › Detail lomba (`livewire/siswa/lomba/detail.blade.php`, juknis), Siswa › Lomba saya (`Siswa\LombaSaya\Index`), Guru › Lomba (`Guru\Lomba`, juknis): the three attributes shown at the top of `components/sth/preview.blade.php`. Surat tugas: the guru detail uses the popup since QA5 (D-112); Siswa › Lomba saya and the TU screens still only download | M3-C | S |
| 70 | Browser check of M3 (only Chromium so far): the popup in Firefox, Safari, Edge and on a real phone (iOS Safari with a PDF in a frame; whether the viewer accepts `default-src 'none'` on the inline PDF), behind the production Apache (`Header setifempty X-Frame-Options` must keep `SAMEORIGIN`; read, not run), "Download" inside the dialog, the real clipboard for "Salin data", a student account (Portofolio, Profil talenta); Talenta: the restore dialog and the download dialog at phone width, "Pilih semua status", password page → automatic download with the chosen statuses; Humas: "Data untuk flyer" at phone width; the realistic demo PDFs in a PDF viewer and on paper | M3-A, M3-C, M3-D, M3-E | I |
| 71 | Confirm the *sementara* points of §D8 and QA4's question (list in §9 and in `docs/dev-notes/M3.md`): ~~restore into Peserta pembinaan without pembina~~ (D-109), order of tidak-lolos rows, bulk "Pulihkan", "Status" vs "Tahap" (D-104); letters in the popup, HEAD not audited, public pages (D-105); organizer names on the sample certificates (D-106); upload for the finished flyer, keep or delete the flyer files made before 2026-10-06, team members without publish consent, the photo sentence, "Lihat" and "Cabang" (D-107); alumni rows in Apresiasi › Tambah prestasi (QA4; since QA5 they are at least labelled "(Alumni)") | M3 notes, QA4 | user |
| 72 | Delete `resources/fonts/{DejaVuSans.ttf,DejaVuSans-Bold.ttf,LICENSE-DejaVu.txt}` (1.4 MB): only the deleted `FlyerRenderer` read them, the PDFs use dompdf's own DejaVu. Optional: GD's `--with-freetype` in both Dockerfiles was only needed to draw flyer text | M3-E | S + I |
| 73 | The E2E seed (`tests/E2E/support/setup-app.sh` runs with `APP_ENV=local`) now renders about 98 demo PDFs (about 30 s on the lead's laptop): set `STH_DEMO_FILES=placeholder` on the E2E app service if that time matters in CI. A real `migrate:fresh --seed` on MySQL with the realistic documents has not been run yet (the seeder's refresh ran only in a test) | M3-D | I |
| 74 | Small leftovers of the M3 notes: one JPG evidence in the demo data so the image path of the popup can be tried by hand; `FileController::previewType()` could move to `App\Models\File`; Admin › Log audit has no "preview" label for `file.downloaded` with `inline: true`, prints the new property `rejected_from` (`talent.restored`) and a `filters.tahap` with several statuses (`talent.exported`) as stored, and keeps the label `publication.flyer_generated` for old rows only (with #64); the comment in `livewire/partials/resume-download.blade.php` still says the call is a literal "export"; Esc does not close the popup while keyboard focus is inside the PDF viewer; `letters.printed` is null for demo letters; whether Tailwind reads `database/seeders/demo/*.blade.php` as a source was not checked; the invented signer names of the sample certificates were not searched for real namesakes; a dompdf PDF with an SVG can differ by one unused font entry between an empty and a filled `storage/app/pdf-fonts` (harmless unless letter hashes are compared) | M3-A, M3-C, M3-D, M3-E | user + S |
| 75 | Small leftovers of QA6: `w2cDraft()` in `tests/Feature/TataUsaha/SuratTugasTest.php` prints the fixed letter date 2026-10-01 and `IssueLetters` refuses a date older than one year, so those tests fail from 2027-10-02 (move to a date relative to today, with the month / year assertions); ~~one line on "Batalkan undangan" and its mail in PANDUAN-PENGGUNA (Siswa)~~ (done EM2 for the button, its dialog and its messages, §2; the mail the invitee gets is still not named there); a browser click-through of the QA6 dialogs (Kepala Sekolah "Setujui terpilih", TU "Kirim", Arsip › Koreksi, "Batalkan undangan"), render-tested only | QA6 | S + I |
| 76 | UI3 leftovers (`docs/dev-notes/UI3.md`): the backend owner's review of D-118 (`files.cover` is not audited and may stay in the browser for a day; posters only after login; old cover files stay when a cover is replaced: `UpdateCompetition` never calls `StoreUpload::discard()`); the cover in the preview popup on Kesiswaan › "Periksa lomba" and Guru › Lomba; posters on public pages; Publik › Prestasi per bidang opens with an empty right panel; menu name vs page title (Kepsek, TU, Humas; QA7 also found Dashboard / Dashboard prestasi, Arsip / Arsip publikasi, Import siswa / Import data siswa) and the formal words in staff mails (`UI3-B.md`); `docs/dev-notes/W1-D.md` and the mockups show the old look. From QA7: Portofolio shows the drawing, not the poster, for a lomba Kesiswaan closed (`CompetitionPolicy::view`; most achievements belong to closed lomba: allow the cover of a lomba the student took part in?); `Admin\Ringkasan` and `Siswa\Beranda` still build unused `links`; the medal icon on Siswa › Peringkat is white on gold (Beranda uses ink) | UI3, QA7 | user + S |
| 77 | Lower-confidence points of the QA8 reviews, read in the code, not reproduced (`docs/dev-notes/QA8.md`, "Requests for the lead"): lowering the archive months archives every student whose new date passed that night (show the count before saving); a stale consent form can overwrite a newer consent, and a `terjadwal` publication from before a withdrawn consent comes back with a new consent (since D-122 it only gets its late reminder to Humas, nothing is published by itself); "Bukan saya?" (`email_notices`) survives an Admin reset; binding an email of a deleted account may answer a 500; ~~"Tolak" in Verifikasi hasil has no confirmation~~ (done EM2, D-128); the first report's evidence files stay after a revision; a disabled ketua leaves nobody who may report (since EM2 the revision banner no longer says "Ketua bisa mengirim ulang laporan." then, `RequestReportRevision::leaderCanResend()`; the dead end itself stays); `perlu_revisi` counted as open on the Kesiswaan dashboard; no own tracker state for `ditolak` / `dibatalkan`; ~~the same ketua in two tabs still gets a 403 on the second "Laporkan hasil"~~ (done QA9) | QA8 | user + S |
| 78 | Mail delivery after EM1 (`docs/dev-notes/EM1.md`): ~~real flows through the allowlist (activation with a real code, one registration end to end, an HTML notification in Gmail and on a phone)~~ (the lead's test of 2026-10-09: 67 steps over every role through Brevo, 105 mails that day; its findings = EM2; whether the OTP test mode was off for the codes is not recorded); then the notification work: ~~the mail look, greeting and footer~~ (done EM2, D-129: own templates, one greeting rule, one link; the look waits for the lead, #81; the wording of the sentences themselves is unchanged, #76), which notices stay mail and which are in-app only (`CompetitionChanged` / `BinaanCompetitionChanged` already have a bell-only mode, `$mail`, that a mail budget can reuse), a daily quota guard that keeps room for OTP, failed mails visible to Admin. For production, still to decide (D-121 and the Brevo addendum in DECISIONS §D13): the provider (confirmed on Brevo's own pages on 2026-10-10: the unsubscribe header cannot be removed below the Enterprise plan, an address that uses it is blocked for that sender, OTP included, and tracking cannot be switched off; logs can be kept 1–24 months and previews not stored; candidates: Amazon SES, Postmark, Resend, or a mailbox on the school's hosting), the sender `noreply@…` (needs the school's DNS), the school's consent to a third-party processor. @I: pin the E2E project's mail settings to Mailpit (`tests/E2E/compose.e2e.yaml`) and give DEPLOY §9 the chosen path. Since RV1 (D-130) the lead's demo stack runs with the allowlist open (`STH_MAIL_ALLOWLIST=*`): every tester's real address gets mail through the one Brevo account (300 a day), only fictional addresses stay in Mailpit, and nothing guards the daily quota; a run of the E2E project on that laptop must set its own `STH_MAIL_ALLOWLIST` (the RV1 browser check used a fictional entry), else its mails to real-looking addresses would go out | EM1, EM2, RV1 | user + I |
| 79 | Questions from QA9 (`docs/dev-notes/QA9.md`, "Requests for the lead"): an audited "Aktifkan lagi" for a disabled account (a reset of a disabled account is refused now, so a mistaken disable cannot be undone); should "Pulihkan" bring back as disabled an account the old archive job archived from `disabled` (local demo data may hold such rows); Talenta: forward into Peserta pembinaan without a guru pembina (D-109 covers only the restore), alumni rows in the pipeline; points shown with one decimal (12,75 and 12,80 both read 12,8) and tingkat weights rounded on Portofolio; winners per lomba mix live lomba labels with snapshot points; one helper for the "named" rule (`PublicAchievements::mapRow()` + `FilePolicy::appearsByName()`); a queued mail cancelled by `LocalMailGuard` ends as a success; "Sudah diarsipkan" sorts by `archive_at`; DECISIONS D-61 still quotes "Akunmu belum punya peran" | QA9 | user + S |
| 80 | Questions from QA10 (`docs/dev-notes/QA10.md`, "Requests for the lead"): re-invite a member whose invitation expired after the deadline was extended (D-50); a registration that reaches "menunggu laporan" late is late from day one; the Laporan PDF cap of 250 rows; refuse a lower "Maks anggota" only when a team is already bigger; login trade-offs (30-failure account lock reachable from one address in ~10 h for new devices, 20 reset codes a day can be used up from 2 addresses so only an Admin reset helps (longer codes / signed links), a shared PC stays a known device, a NAT flood makes everyone wait up to 60 s, alerts past 5 an hour only logged, code-to-browser link in the cache); a `number_key` column for letter numbers (migration); `final_on` moved later after "menunggu laporan"; `VerifyReport` reads the weight version outside its transaction; pending-activation gurus offered as pembimbing; Humas › Arsip and "Tambah prestasi" still use consent only; the named rule in three places | QA10 | user + S |
| 81 | What EM2 leaves open (`docs/dev-notes/EM2.md`). **Browser checks** [I + S]. Seen by the coordinator on 2026-10-10 in the app's browser as the demo Kesiswaan account: the bell ("Tandai dibaca" lowers it at once, two checks after the action, a JSON answer, no console error), the dialogs "Tolak laporan ini?" and "Tutup lomba ini?", the field "Jenis penyelenggara lainnya" and the info alert of "Ubah lomba", Apresiasi (three validation messages at once, the time on the event card, the picker and a search with a tick kept), two sample mails in Mailpit. Still open: the rest of the live counts in a real browser (steps in the note: the request headers, "Tandai semua dibaca" without a page load, the Notifikasi list and another role's sidebar count rising by themselves, the background tick, no request from a hidden or unused tab, Back / Forward, Keluar, the switch at 0, a blocked address that comes back by itself and two 401 answers that still stop it); the new link hints; the toast of "Ubah lomba" and the two-click path of a stale "Periksa lomba" drawer; Humas: an overdue card and its panel, the dialog "Tandai sudah terbit?" at 360 / 390 px, the count on the menu item Jadwal; Apresiasi at 360 / 1280 / 1366 px: 31 ticks, a team added with one click, and typing "nadia ", a pause, then "putri" into "Cari prestasi"; the Talenta bulk dialog, the Import dialog and the student's "Batalkan undangan" dialog (with #75); every phone width; one real mail of each kind (with a button, without one, OTP) in Mailpit (HTML and Text tab) and in Gmail on a phone in dark mode, Outlook desktop. **E2E** [I]: the Playwright smoke passed in CI on PR 20 and the email scenario ran (#46). No bell test exists yet: a Playwright bell test must mock `**/notifikasi/jumlah` (the E2E stack has no queue worker), and a step that waits for network idle after a Livewire action now sees two more requests (2.5 s and 8 s later). **Dev stack**: done on the lead's stack on 2026-10-10 (the three migrations on the local MySQL, `npm run build`, `reload.bat`, restart of `queue` + `scheduler`); on any other checkout of this code the same four steps are needed (a worker that was not restarted still holds the deleted `AutoPublishPublications` class and the old mail config; a queued payload of that job fails once). **Product choices**: the EM2 list in §9. **Left by the review** (nits read in the code, not fixed; one line each in the note) [user + S]: the same trimmed write-back on a live search box in `Humas\Arsip::updatedQ`, `TataUsaha\ArsipSurat::updatedQ` and `Kesiswaan\Lomba\Index::updatedQ` (trim where the query is built, not in the hook) [S]; `Siswa\Pendaftaran\Form::undang()` puts its refusal on `members`, which the screen no longer draws once the team went to the Kepala Sekolah; a count check can still run beside the next page's request after a Livewire full-page redirect, and the ±10 s jitter also applies at the 30 s floor; the typed "Lainnya" kind is dropped only when the input carries `organizer_type`; `ExternalUrl::normalize()` also removes ZWNJ / ZWJ inside an IDN host; `LinkFieldsTest` would pass with the hint on one link field only; a first link or juknis is announced as "diganti"; `publishingId` survives a dismissed dialog; `publication.reminded` is audited also when no Humas account was told; a stale tick the screen no longer shows can stay in `$picked`; `AppreciationEvent::startsAt()` and `BinaanAppreciationNotice::scheduled()` have only tests as callers; `MailGreetingTest` expects "Halo Bambang," for the Kepala Sekolah on student-audience notices; the Jadwal count can run ahead of the open Jadwal / Publikasi page; `RecordAppreciation::blockedMessage()` prints the event date without its time. **Not built** [user + S]: "Ubah acara" for an appreciation event; a start time for Talenta "Jadwal asesmen"; an appreciation line on a student or guru screen; for a lomba change: a notice to TU once letters exist, a notice on close, a question before saving a past deadline, the individu → tim guard, catalog tags; the six-digit code fields still refuse a code pasted with spaces. **Small leftovers**: `Siswa\Pendaftaran\Form` repeats three refusal sentences of `WithdrawInvitation` and the "team is ready" rule of `ConfirmMembership` to word its dialog (a static helper on those Actions would be the one source) [user]; the demo event is still named "Upacara Senin, 5 Oktober 2026" (its notices repeat the date), and the event already in the lead's database has no start time; a closed demo lomba in the lead's database keeps a bare "Lainnya" (no backfill); optional `humas.jadwal` in the Humas preview badges of `resources/views/dev/components.blade.php`; the unused "Whoops!" line in `lang/id.json`; a paratest run prints no summary when a failure's stack points into a compiled view of a worker that already removed its folder (read the names with `--tmp-dir` and the per-worker JUnit files) | EM2 | user + S + I |
| 82 | What RV1 leaves open (`docs/dev-notes/RV1.md`). **Commit**: RV1 was committed and pushed to `fix/em2-uji-email` on 2026-10-11 at the lead's request; PR 20 now carries EM2 and RV1, and the lead merges it himself after his retest [user]; its CI ran again for that push and was not waited for. **Product choices**: the RV1 list in §9. **Browser checks** [I + S]: done in the app's browser on 2026-10-11. On the isolated project: the approval dialog (opens, refuses an empty choice under the field, approves, toast, the row's "Ditetapkan Kepala Sekolah, menggantikan …", the menu count), the mails of that flow, "Ditetapkan Kepala Sekolah" and the bell row on the student's side, and the replaced guru's side (the registration gone from Siswa binaan, its old link 404; the bell row seen there belonged to the first version, that guru gets none since D-134, which was checked by tests only). On the lead's stack: "Ikut tim" after the fix, the three NIS screens as Kesiswaan, and the Persetujuan rows and the dialog measured at 360, 1024, 1280, 1366 and 1440 px. The Browser pane was hidden the whole time, so all of this was read from the page (text, positions, console, network), **not looked at**: nobody has seen a screenshot of the new button, the dialog or the centred letter on a screen yet (the letter was measured in the PDF and one page image was viewed). Still open: the new guru's bell row and binaan list (only the mail was read), 390 px, a printed student letter on paper (with #54). **Email scenario** [I]: `tests/E2E/support/email-scenario.php` has no step for an approval with another guru (expect "Ditetapkan sebagai guru pembimbing" to the new guru and **no** mail at all to the old one, D-134); its existing approval step still passes by subject but the students' mail has one more line (with #46). **Guards** [S + I]: `PageWiringTest` takes about two and a half minutes on the lead's laptop (one demo seed, about 190 requests): watch the CI time, and lower `rv1Crawl()`'s caps if it hurts; it follows links only, so a state reached by a click (a drawer, a tab kept in a property) is covered by `ViewWiringTest` reading the sources, not by a render; the demo data's fixed dates make it travel to 2026-10-06. **Demo data** [user]: its lomba deadlines pass one by one in real time (LPB closed on 10 Okt), so fewer flows can be shown each day. **Left by the review** [user]: a guru account that has not activated yet is offered as pembimbing (to students since W2, now also to the Kepala Sekolah) and gets the bell row but no mail, while the dialog says "Guru yang dipilih di sini diberi tahu." (with #80: offer such accounts at all, or mark them in the list). **Not built**: a change of guru apart from the approval; a notice to a guru at the moment a student chooses them (so a waiting registration stands in that guru's Siswa binaan list unannounced and, after a replacement, leaves it unannounced: D-134); NISN on the three screens. **Bell rows** [user]: rows written before `BellChannel` keep an absolute address on any database that was not repaired (the lead's local one was, by a scratch script); production has one host, so its old rows keep working. **Browser** [I]: the new dialog sentences were read in a render test, not looked at on a screen. **Local files** of the demo (`compose.demo.yaml`, `demo-start.bat`, `demo-stop.bat`, `SETUP-DEMO-MINIPC.md`) and of the copy for a second machine (`demo-ekspor.bat`, `demo-impor.bat`, `salinan-demo/`) are in `.git/info/exclude`, not in the repo; `SETUP-DEMO-MINIPC.md` still describes a fresh seed (`db:seed`), which must not be run on an imported copy | RV1 | user + S + I |

## 11. Team, ownership and agent protocol
- **user** (lead, backend core, 40%): `app/{Enums,Models,Domain,Actions,Policies,Http,Providers,
  Notifications,Jobs,Console}`, `database/`, `config/`, `bootstrap/`, `routes/web.php`,
  `routes/console.php`, `tests/Unit`, backend tests, `docs/{ARCHITECTURE,DECISIONS,SECURITY}.md`; reviews all PRs.
- **S** (frontend & features, 35%): `app/Livewire/`, `app/View/`, `resources/`, screen tests,
  `docs/UI-MAP.md`, `docs/design/`.
- **I** (deploy + QA, 25%): `docker/`, `compose.yaml`, `.dockerignore`, `.github/`, `scripts/deploy/`,
  E2E tests, **`docs/DEPLOY.md`**, user guide.
- Shared (PR reviewed by the other owner): `routes/{siswa,staff,admin}.php` (screen owner adds routes,
  user reviews middleware), `composer.json`, `package.json`, `CLAUDE.md`. More: `docs/TEAM.md`.

AI agents: read this file and every `docs/dev-notes/*.md` first; stay inside your files; run tests +
Pint in Docker before handing off; write `docs/dev-notes/<ID>.md` (protocol: `docs/dev-notes/README.md`)
with conflicts/requests instead of editing others' files; ask the user when a decision is missing; no
commits unless the user asks.
