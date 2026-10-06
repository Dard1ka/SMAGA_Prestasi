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
| `humas` | public relations | publication queue, e-flyer, *apresiasi* |
| `admin` | operator | everything: users, import, settings, signature image, replace the Kepsek account (all audited) |

Students log in with NIS or NISN and, once their activation is finished, also with the email they bound
(D-78); staff with `username` or email. Public pages (no login): prestasi overview, per-*bidang*
drill-down, winners per lomba, cumulative ranking; only name + class + verified achievements, gated by
consent flags (D-16, SECURITY §3m-7).

## 2. Sources of truth (read before changing behaviour)
| file | content |
|---|---|
| `docs/DECISIONS.md` | decision log D-01…D-101 (D-45…D-61 = backlog wave + W4 product choices, *sementara*; D-62…D-65 = security hardening, *final*; D-66…D-77 = Q1 security/performance pass and the lead's follow-up choices; D-78…D-91 = §D6, the lead's choices after the hand test of 2026-10-04; D-92…D-101 = §D7, the rules changed by the fixes after the QA pass of 2026-10-05), U-01…U-06, open questions §F. **Wins over everything.** |
| `docs/ARCHITECTURE.md` | binding contract: data model, enums, state machine, domain rules |
| `docs/SECURITY.md` | threat model, top-10 risks §1, controls §3a–§3m (K1–K12), pre-release checklist §4 |
| `docs/TALENT-FORM.md` | talent types/branches (seed source for `talent_types`, `talent_branches`) |
| `docs/design/DESIGN-SPEC-v2.md`, `docs/design/mockup-v2/` | visual spec, 24-screen mockup, fictional data set §5 |
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
3. **Initial password pattern (D-24) is never displayed or logged**: not in UI, emails, flash
   messages, exceptions, logs, seeder/test output. Only `App\Domain\Accounts\InitialPassword` builds it,
   only at import / Admin reset, and only to hash it. Login only compares stored hashes (K6, K12).
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
9. **UI copy**: Bahasa Indonesia, short, sentence case, students addressed as "kamu", buttons are verbs,
   no emoji, no dark theme, decimal comma (`1,00`). Everyday words, not formal ones: **upload, download,
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
| routes | `docker compose run --rm --no-deps app php artisan route:list --except-vendor` |
| assets | `docker compose run --rm --no-deps node sh -c "npm ci && npm run build"` · HMR: `docker compose --profile frontend up -d node` |
| reload PHP after an edit (dev) | `reload.bat` · `docker compose exec -T app sh docker/php/dev-cache.sh` (config cache for php-fpm only; never `config:cache` by hand) |
| logs | `docker compose logs -f app queue scheduler` |
| validate compose | `docker compose config -q` · prod: `docker compose -f docker/compose.prod.yaml config -q` |
| prod image | `docker build -f docker/Dockerfile.prod -t sth-app:local .` (`--target web` for nginx) |
| E2E smoke (Playwright, isolated project `sth-e2e`) | `docker compose -f compose.yaml -f tests/E2E/compose.e2e.yaml up -d --wait mysql app web` → `… exec -T app sh tests/E2E/support/setup-app.sh` → `… run --rm e2e` → `… down -v` (DEPLOY §18) |
| mail check | `docker compose exec app php artisan sth:mail-test you@example.test` (dev: Mailpit; prod: DEPLOY §9) |
| email scenario (every role's mails end to end, isolated project `sth-e2e`, ~3 min, not in CI) | E2E `up … mysql app web mailpit` → `… exec -T app sh tests/E2E/support/setup-app.sh` → `… exec -T app php tests/E2E/support/email-scenario.php` → `… down -v` (DEPLOY §18) |

Notes: tests stay SQLite-compatible (MySQL-only behaviour gets its own marked test). Git Bash rewrites
paths like `/var/www/html`: prefix `MSYS_NO_PATHCONV=1` or use PowerShell. On Linux, container-created
files are root-owned: `sudo chown -R "$USER": .`. Keep LF line endings.
CI (`.github/workflows/ci.yml`: PHP, Frontend, Composer audit, gitleaks, Docker + Trivy, E2E) must be green before a
merge; read a failed run with `gh run view <id> --log-failed`. Dependabot (`.github/dependabot.yml`) only brings
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
app/Support/Navigation.php        sidebar menus per role; badge keys, counts come from the resolver (§7)
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
password, a code or a 2FA secret there, D-93).
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
  `registration_url` (D-28). URLs validated `https`, never fetched. Date rules in one place,
  `App\Actions\Competitions\CompetitionDates` (D-98): `starts_on` is never before `registration_closes_at` (create,
  update, publish); publishing a lomba whose registration deadline or final date has passed needs an explicit yes
  (`VerifyCompetition::handle(…, bool $confirmPastDates = false)`, error key `CompetitionDates::PAST_KEY`); saving
  without publishing asks nothing. A rejection note has at least 10 characters (`VerifyCompetition::MIN_REJECT_NOTE`, D-95).
- **Registration state machine** (`RegistrationStatus`, 6-phase tracker *Jejak Prestasi*):
  `menunggu_konfirmasi` (1 Daftar) → `menunggu_kepsek` (2 Persetujuan) → `disetujui` | `ditolak`
  (note required) → `surat_terbit` (3 Surat tugas) → `berlangsung` (4 Lomba, scheduler on `starts_on`)
  → `menunggu_laporan` (5, after `final_on`) → `laporan_dikirim` → `selesai` (6 Poin & apresiasi) or back
  to `menunggu_laporan` on *perlu revisi*; `dibatalkan` = leader cancels from `menunggu_konfirmasi` or
  `menunggu_kepsek` (lead, 2026-10-01). Transitions are defined once in
  `App\Domain\Registrations\RegistrationStateMachine` (`assertTransition()`, `phaseFor()`). Team
  members pick from existing accounts, each confirms personally; roster locks after approval.
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
  `report_deadline_days`, Admin-editable, D-25).
- **Archive** (`App\Domain\Archive`): grade-12 start = 1 July of the year the student enters kelas XII;
  `archive_at` = +18 months (e.g. kelas X 2026/2027 → XII 2028-07-01 → archive 2030-01-01). Daily job
  01:00 Asia/Jakarta, idempotent; sessions revoked; **archived users cannot log in** (D-29); their
  achievements stay in ranking/public pages. Admin can also disable/archive manually with a reason (D-30).
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
  `/verifikasi-surat/{code}`; "Cetak" = the PDF inline via `tu.surat.cetak` (`StreamLetter`, policy-checked,
  audited) (SECURITY §3g, §3m-5). The student's "Surat tugas" card (Lomba saya) lists only letters TU already
  **sent**; the siswa letter has a download, the guru letter is an information row without a link (`LetterPolicy`
  keeps that file for the guru and staff: it carries NIP and rank; D-99, the "students may not open it" part is
  *sementara*).
- **Accounts**: students log in by NIS or NISN (10 digits → NISN) and, once activation is finished, by
  the bound email (`LoginIdentifier`, D-78); staff by `users.username` or email. First login (D-79):
  bind email → 6-digit OTP → password; at the password step a student may change it or keep the current
  one (`CompleteActivation::keepPassword()`, audit `activation.password_kept`), staff must change it.
  Only the school password of the import may be kept: after an Admin reset the student **must** choose a new
  password (no "Lewati"; `users.temporary_password_issued_at` is set by `ResetStudentAccount`, cleared by
  `SetNewPassword`, read by `CompleteActivation::mayKeepPassword()`; D-92).
  No activation code (D-14). Initial password only via `App\Domain\Accounts\InitialPassword`. New student
  passwords: **min 10 characters** and `App\Domain\Accounts\PasswordPolicy` rejects birth date / `sm4g4` /
  NIS / NISN / name (K5, lead 2026-10-01) and, for every account, the username, the email's local part and common
  passwords (built-in word list + alphabet/keyboard/digit runs, refused when fewer than 5 other letters remain:
  `password123` fails; D-94). The hint under a new-password field comes from `SetNewPassword::hint($user)`.
  Sessions (D-93): renew the id with `session()->regenerate(true)` (the old row is deleted) and only **after** the
  Action succeeded. "Buka ulang" (`ReopenActivationWindow`, K2) never shortens a deadline: the later one of the
  current deadline and now + 72 h, reason at least 10 characters (D-95). Import (Excel/CSV, dry-run first) by Kesiswaan and Admin
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
  move) sends the saved draft (*sementara*); "Tidak lolos" asks for confirmation first (D-96). A `tidak_lolos` application can be restored to its earlier stage
  (`RestoreTalentApplication`); the student may ask once per rejection (`RequestTalentReview`) and cannot
  apply again for a rejected choice (D-86).
- **Appreciation events**: confirmed as a whole (`appreciation_events.completed_at` / `completed_by`,
  `CompleteAppreciationEvent`); deletable only while not confirmed (`DeleteAppreciationEvent`, the
  scheduled students are told); a confirmed event takes no more prestasi (D-87). Scopes
  `AppreciationEvent::open()` / `completed()`. One achievement is on at most one event (`RecordAppreciation`
  refuses it while it is scheduled elsewhere or once it was appreciated); "Lepas dari acara"
  (`ReleaseAppreciation`, `AppreciationPolicy::delete`) takes one scheduled row off an unconfirmed event and
  tells the student (D-97).
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
  `APP_ENV=local`** (fictional data matching the mockup queues and leaderboard).
- **Audit & jobs**: activitylog on every state change/Admin action, sealed after commit in a hash chain
  (`audit_seals` + `audit_chain_heads`, `sth:audit:verify` daily, D-63; app code only INSERTs into `activity_log`/`audit_seals`, D-51);
  notifications = queued mail + database; queues `otp`, `mail`, `default`.

## 6. Coding conventions
- `declare(strict_types=1);` in every new PHP file; typed properties, parameters and returns; `final`
  classes for Actions and Domain services; readonly value objects; `CarbonImmutable` for dates.
- Validation: FormRequests for controllers (their `authorize()` calls a policy, never a bare
  `return true`); Livewire uses `#[Validate]`/`$this->validate()`. Use `validated()`/`safe()->only()`,
  never `$request->all()`.
- Actions: `App\Actions\<Area>\<Verb><Noun>`, one public `handle(User $actor, …)` method, authorize,
  run in `DB::transaction`, dispatch notifications after commit. Livewire components and controllers
  call Actions; they hold no business rules. **No business logic in Blade**: views only format data.
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
  (user text must not become Markdown links/images, D-69; `MailInjectionTest` checks it). Recipients: a frozen
  account gets no mail and an unverified address only gets `AdminNotice` mails (`User::routeNotificationForMail()`, D-68).
- Tests: Pest, `tests/Unit/Domain/<Area>`, `tests/Feature/<Area>`; factories produce fictional data
  only. Format with Pint (Laravel preset) before every hand-off. `Tests\TestCase` gives every Feature
  test its own empty `local` + `public` disk and PDF sandbox (`Tests\Support\TestDisks`): never use
  `Storage::fake()` (one shared folder, wiped on start, breaks concurrent runs).

## 7. Design system
- Spec: `docs/design/DESIGN-SPEC-v2.md` ("Behance edu-soft"): Urbanist (display) + Plus Jakarta Sans
  (body); primary `#3148D6`; one colour = one meaning (bidang colours, gold only for points/medals,
  status green/orange/red/gray/blue); 20px card radius; AA contrast; ≥ 44px touch targets on mobile;
  every input has a label; status chips always carry text.
- Mockup: `docs/design/mockup-v2/*.dc.html` (24 boards, `canvas.json`), notes in
  `docs/design/mockup-v2-notes/`. Live canvas: https://claude.ai/artifact/7m2XGmACRwHFfYQ9EaeV2m.
  The `.dc.html` files are design references with inline styles: rebuild them with components and
  Tailwind, don't paste their markup.
- Tokens (Tailwind v4 `@theme`, spec colours only) + helmet classes in `resources/css/app.css`; fonts
  self-hosted via `@fontsource/*`. Layouts `<x-layouts::app|public|auth|mobile>` (Livewire:
  `#[Layout('layouts::app')]`), menus from `App\Support\Navigation`. 38 components `<x-sth.*>` (button,
  chip, card, stat-tile, form fields that forward `wire:model` + `$errors`, table, tabs, tracker,
  rumus-poin, lomba-card, modal, toast, …), each documented at the top of its file; contracts in
  `docs/dev-notes/W1-D.md`. **No `style=""` and no inline JS** (CSP nonce): use tokens/`w-pct-*`,
  `data-sth-*` hooks and Livewire events `sth-modal-open|close`, `sth-toast`. Hooks added by M1 (all in
  `resources/js/app.js`): event `sth-badges {badges: {key: count}}` updates `[data-sth-badge="<key>"]` in the
  sidebar without a reload (keys from `Navigation`, e.g. `tu.surat`; dispatch it after an action that changes
  a queue); event `sth-form-invalid {form}` brings the error summary (`data-sth-error-summary`) or the first
  invalid field into view; `data-sth-live-search` on the top bar search form filters the list while typing
  when the form's action is the current page (sets the Livewire property named like the input, `q`; D-90).
  Since M2 the counts themselves come from **one resolver** on every page of `layouts::app`
  (`NavigationServiceProvider::badges()`, registered with `Navigation::resolveBadgesUsing()`, D-100): it counts only
  the badge keys of the rendered menu, only for a user who may open that queue, with the query the screen uses. A
  page may still pass its own `badges` (they win); a new count = a key in `Navigation` + one line in
  `NavigationServiceProvider::count()`. `app.js` also empties password fields after a plain form submit and on a
  page restored from the back/forward cache (D-93).
  **Error pages** (D-101): `resources/views/errors/{401,403,404,405,419,429,500,503,4xx,5xx}.blade.php`, each
  including `errors.layout` (brand, "Kode <status>", title, one sentence, "Kembali ke beranda", "Kembali" when the
  visitor came from another page of the app; links from `ErrorPageServiceProvider::links()`). No role layout (no
  menu, no counts), no inline style/JS, nothing that opens the database for a 5xx, and never
  `$exception->getMessage()`; they are used with `APP_DEBUG=true` too. Another status gets its own file there.
  On Siswa › Cari lomba the in-page box is `lg:hidden`, so exactly one search box shows at any width. The CSP never allows
  `'unsafe-eval'` (D-65): keep `wire:*` expressions simple (method calls with literal arguments; `CspSafeTest` scans every view).
- Showcase (local only): `/_dev/components` (`?role=kesiswaan`), `/_dev/components/{public,auth,mobile}`.
  A screen PR includes screenshots next to its mockup board (desktop 1440, mobile 390 where drawn).

## 8. Decisions you must not undo
Full log: `docs/DECISIONS.md` (append-only; a changed decision gets a new entry that "menggantikan D-xx").
- Initial password is **`sm4g4_DDMMYY`** from the birth date (D-24), not DD-MM-YYYY. Never shown in the UI.
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
3 Admins + `sth:create-admin`; staff login username/email, students NIS/NISN (plus the bound email after
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
cannot read completely; D-71 a withdrawn publish consent also hides the name and the flyer from Humas; D-72 long lists
come in steps (*sementara*); D-73 "Tambah lomba" notifies the other verifiers (*sementara*); D-74 gzip for the
Livewire script in the prod nginx + daily prune of expired cache rows. Lead's choices of 2026-10-02, **final**: D-75 the
flyer file stays after a withdrawn consent, Humas cannot download it, Admin (`student.view`) still can; D-76 a lomba
name must not contain a web address (`App\Rules\NoWebAddress`); D-77 the guru pembimbing is told about a rejected or
cancelled registration, the Kepala Sekolah about a cancel once it had reached `menunggu_kepsek`.
Lead's choices after the hand test of 2026-10-04, **D-78…D-91** (DECISIONS §D6, `docs/dev-notes/M1.md`), **final**:
D-78 a student may log in with the bound email once activation is finished; D-79 first login = email → code → password,
and a student may keep the school password (accepted risk: it stays guessable from the birth date for as long as it is
kept, K7 cannot see it; staff must change theirs); D-80 public ranking filter Siswa aktif / Alumni / Semua, alumni show
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
sentence of D-79; the school password of the import may still be kept); D-93 credential pages are `no-store`, password
fields are emptied, and a renewed session destroys the old one, never on a refused attempt; D-94 new passwords refuse
the username, the email's local part and common passwords; D-95 "Buka ulang" never shortens a deadline, its reason and
a lomba rejection need 10 characters; D-96 the talent note for the student is a draft until a decision and "Tidak
lolos" asks first (quick actions and bulk moves also send a saved draft: *sementara*); D-97 one achievement on one
appreciation event, "Lepas dari acara"; D-98 a lomba cannot start before its registration deadline, and past dates
need an explicit yes to publish; D-99 the student card lists only sent letters and the guru letter is text only
(students may not open it: *sementara*); D-100 sidebar counts come from one resolver on every page; D-101 own error
pages, `GET /logout` redirects, a 403/404 on the first page after login goes to the beranda. Do not bring back:
"Lewati" for a temporary password, `session()->regenerate()` without `true` on these screens or before the Action
succeeded, a "Buka ulang" that sets now + 72 h unconditionally, a download button for the guru letter on the student
card, counts that depend on each page passing `badges`, Laravel's default error views.

Known stale text (do not "fix" code to match it): SECURITY.md §0/§3a still say `sm4g4_DD-MM-YYYY`;
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

## 9. Open questions (answers come from the school; don't guess in code)
Decided by the lead on 2026-10-04, no longer open: the letter template (= the school's own files: one team
letter with the columns NO, NAMA MURID, NIS, KELAS; guru letter + Lampiran; D-84) and the number format
(TU types the complete number, so the meaning of "MRD", the yearly reset and separate books for siswa and
guru numbers stay with TU's agenda book and no longer block code; D-82).
From DECISIONS §F and SECURITY §5: parental consent for names, photos,
certificates on public pages (U-04); go-live date + team hours; Ubuntu Pro (U-01); Docker static
binaries (U-02); mandatory staff TOTP + break-glass (U-03); letter QR (U-05); WordPress isolation (U-06);
server/DNS admins; Kepsek consent for the signature image, TTE?; hijack recovery owner + risk-acceptance
signature (D-14, which must now also name the D-79 risk); per-class activation schedule; SMTP provider;
off-site backups + key holder; DPO.
Questions for the lead from M1 (marked *sementara* in DECISIONS §D6; backlog #45, #49–#51): does the yearly
student file win over a manual archive date, and what happens to a registered student who is missing from
the file (D-81); may Kesiswaan restore a rejected talent choice without a student's request (D-86); the
flag and reminder for students who kept the school password (D-79).
Questions for the lead from M2 (marked *sementara* in DECISIONS §D7; backlog #57, #58): should a saved draft
note go out with the quick actions and bulk moves in Talenta (D-96); may students open the guru letter, which
the QA summary asked the lead to decide (D-99: today they may not); should the other reason fields (reject a
registration, reject / revise a report, "Buka pembekuan") also need 10 characters (D-95).
Public DNS check of 2026-10-01 (not yet confirmed with the school): the domain's DNS is on Cloudflare (`www`, `mail`,
`webmail` proxied); the main website and the school mail are on Desnet shared hosting (SPF, DKIM and DMARC `p=none`
exist), so apparently **not** on the Ubuntu server (§1 assumes the same host: confirm). No `prestasi` record exists
yet: add an A record to the Ubuntu server as "DNS only" (behind the Cloudflare proxy the app would only see Cloudflare
IPs, which breaks the per-IP login/OTP limits). The noreply mailbox would then be DEPLOY §9 path B on that hosting; its
SMTP host must not be `mail.<domain>` (proxied) and its hourly send limit needs asking.

## 10. Status
Last updated: 2026-10-04 (waves 1–2 done, W3-A integration pass, backlog wave B1–B6, W4 integration, D1 docs sync, CI green on GitHub, Q1 end-to-end/security/performance pass, PERF1 by S, M1 = the lead's 18 hand-test changes). Update this section whenever a milestone moves.
M1 2026-10-05 (`docs/dev-notes/M1.md`, plain summary for the team in `catatan.md`; branch `feat/ui-uji-manual-m1`): full suite `--parallel --processes=4` → **1767 passed (8945 assertions)**, exit 0 (680 s); `pint --test` clean (610 files); `npm run build` OK; the four M1 migrations ran on the local MySQL. Dev page times after `reload.bat` + warm-up, all seven roles: 150–540 ms per menu, login 0.75–0.93 s (`catatan.md` §2). Not re-run after M1: the email scenario and the Playwright smoke (backlog).
M2 2026-10-06 (`docs/dev-notes/M2.md` + `M2-A`…`M2-C`, D-92…D-101, plain summary in `catatan.md` §6; same branch): fixes for the 12 most important findings of the QA pass of 2026-10-05 (64 findings; the rest is backlog #57–#68) and the Trivy fix in `docker/Dockerfile.prod`; two migrations run on the local MySQL; suite numbers: TEST-RESULTS in `docs/dev-notes/M2.md`.

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
| 39 | After a withdrawn publish consent the flyer file, the old caption and scheduled appreciation rows stay on purpose (D-75: Humas blocked, Admin keeps the download); SECURITY §3i asks for removal within 3×24 h, which is still a manual task | Q1 | user + S |
| 40 | A talent application (SaveTalentProfile / ApplyTalent) notifies nobody; Kesiswaan only sees it on the pipeline screen. (Reject/cancel notices for guru and Kepala Sekolah are done, D-77; #38 lomba names with a web address is done, D-76) | Q1 | user |
| 41 | Humas holds `achievement.view_all`; only the route middleware keeps Humas out of Database prestasi: tighten `AchievementPolicy::viewDatabase` or the Humas permission set. Unify the neutral-name rule (`PublicAchievements` + `AchievementFacts`) | Q1 | user |
| 42 | Staff 2FA follow-ups: hourly ceiling for confirm-password (D-67 is 5 per minute), staff can still disable their own TOTP after confirming the password (audited; `staff.2fa` forces setup again) | Q1 | user |
| 43 | `PdfScanner` (D-70): PDFs made on the lead's laptop with Word, Excel, PowerPoint, "Microsoft Print to PDF", Edge, Chrome and photo/scan images are accepted, hostile and oversized ones refused (19 files; results and a rerun script are in `uji-pdf/` next to the repo, not in git). Still to try: Canva downloads, the school's copier/scanner, phone scan apps, organizers' original certificates | Q1 | I |
| 44 | A staff account reset by an Admin only gets account notices until its owner verifies the email again (D-68): add this to PANDUAN-PENGGUNA (Admin). Dev stack: after a Docker restart `queue`/`scheduler` come up before MySQL and fill `laravel.log` with connection errors until `start.bat` runs | Q1 | I |
| 45 | Counterweight for D-79: flag "masih pakai kata sandi dari sekolah" in Status aktivasi (active student with `password_changed_at` null, or the audit event `activation.password_kept`) + a reminder to change it; a pending row can now show a masked email (bound, password step open). Add the D-79 risk to the D-14 risk-acceptance form. Since D-92 `users.temporary_password_issued_at` tells an Admin's temporary password from the school password, so the flag can name only the kept school passwords | M1-B, M2-A | user |
| 46 | ~~Re-run `tests/E2E/support/email-scenario.php` and the Playwright smoke after M1~~ (done 2026-10-05, see above; not re-run with the M2 code unless TEST-RESULTS in `docs/dev-notes/M2.md` says so). Still open: scenario steps 9d/9e: talent review request (mail to every Kesiswaan) and restore (mail to the student), a step that deletes an appreciation event (mail to the scheduled students), and a step for `ReleaseAppreciation` (mail "Jadwal apresiasi berubah" to the student, D-97) | M1, M1-D, M1-E, M2-B | I |
| 47 | Optional sidebar badge on "Talenta siswa" for open review requests (`TalentApplication::query()->reviewRequested()->count()`): since D-100 a badge key in `Navigation` + one line in `NavigationServiceProvider::count()` | M1-D, M2-C | S |
| 48 | `<x-sth.modal size="xl">` (≈ 960 px) so the Status aktivasi class popup can show the email as its own column (today `lg`, 760 px) | M1-E | S |
| 49 | `student.class_changed` (about 750 rows per yearly import): should it be in `SecurityEventSubscriber::AUDITED_EVENT_PREFIXES`, and in the history of `Admin\ArsipOtomatis::EVENTS` (it can move an archive date)? | M1-C | user |
| 50 | Confirm the *sementara* points of D-81 and D-86: the yearly file wins over a manual archive date; a registered student who is missing from the file is left alone (list or flag "siswa aktif yang tidak ada di file"?); Kesiswaan may restore a rejected talent choice without a student's request | M1-C, M1-D | user |
| 51 | Tata Usaha and Kepala Sekolah hold `student.view`; only the route middleware keeps them out of Status aktivasi (same kind of gap as #41): tighten the policy or the permission sets | M1-E | user |
| 52 | Every public page logs a CSP console error "Applying inline style violates … style-src" (seen by M1-C, older than M1): find the inline style. QA (Q2-25) found one on every `wire:navigate` page change, on all screens: Livewire's own small `<style nonce data-livewire-style>` arrives with the new page's nonce, which the open page's CSP does not know; no visible effect. Idea from QA: move that style into `app.css` or allow it by hash | M1-C, QA | S |
| 53 | The TU letter screen (`tu.surat.show`) overflows sideways below about 480 px | M1 | S |
| 54 | Print both letters on a real printer ("Cetak" was only opened on screen) and compare them with the school's paper; then confirm `resources/pdf/assets/**` is in the production image (read only so far) | M1-A | I |
| 55 | Update `panduan-uji-manual-sth.html` (outside the repo, next to it) to the M1 screens | M1 | user |
| 56 | Small leftovers of the M1 notes: old note text "mengganti kata sandi dan menghubungkan email" in `resources/views/dev/{auth,components}.blade.php` and the example in `components/sth/alert.blade.php`; the generic login error still says "NIS/NISN atau kata sandi salah." although it now also answers an email (`lang/id/auth.php`, `lang/id.json`, `LangIdTest`); `AppreciationEventFactory::completed()` state; demo letters without `basis_from` / `schedule_date`; no bulk "Pulihkan" and no note for the student in the bulk "Pindahkan ke …"; the guru pembina is not told when a talent choice is rejected or restored; Kepala Sekolah "Surat atas nama saya" only downloads (no "Cetak"); `<x-sth.icon>` has no printer icon ("Cetak" uses `external`) | M1-A…M1-E | S + user |
| 57 | Reason fields (Q1-18, rest): lomba rejection and "Buka ulang" now need 10 characters (D-95); reject a registration (Kepala Sekolah), reject / ask a revision of a report and "Buka pembekuan" (`UnfreezeAccount`) still accept two letters. Same minimum there needs the lead's yes | QA, M2-B | user |
| 58 | Confirm the two *sementara* points of §D7: quick actions and bulk moves in Talenta send a saved draft note (D-96); students cannot open the guru letter, which the QA summary asked the lead to decide (D-99) | M2-B, M2-C | user |
| 59 | Not run in a browser after M2 (list in `docs/dev-notes/M2.md` "Not verified"): the Back scenario after Keluar (also Firefox, Safari), activation after an Admin reset, the dialogs `tidak-lolos-talenta` / `lomba-tanggal-lewat` / "Lepas dari acara", the error pages other than 404, `GET /logout`, the redirect after login. On a production-like stack (`APP_DEBUG=false`, https, real OTP): repeat Q2-03 (405 size and time), check the `secure` cookie + HSTS and that links in mails use the configured host, not the request's (Q2-08, suspected) | M2, QA | I |
| 60 | Top bar search (Q1-06, Q1-12, Q2-24): it does not filter the TU queue (`TataUsaha\SuratTugas` has no `q`) and points to `/tu` from Arsip surat; Kesiswaan's box says "Cari lomba atau siswa" but only finds lomba; two search boxes at once on Guru › Siswa binaan and TU › Arsip surat. One box per screen, label matching what it finds (D-90) | QA | S |
| 61 | Letters (Q1-13, Q1-15, Q1-23): "Koreksi" from Arsip surat opens the siswa tab instead of the chosen letter; what TU typed for the siswa letter is not carried into the guru form; a letter revoked **before** it was sent answers "Tidak ditemukan" on `/verifikasi-surat` although TU could already print it (answer "Dicabut", or no "Cetak" before "Kirim": the lead's call) | QA | S + user |
| 62 | Data checks (QA): a PNG named `.pdf` is accepted as a brochure (Q1-20) [user]; an assessment date in the past (2020) is accepted in Talenta (Q2-13) [user]; the per-class table of Status aktivasi counts alumni (Q2-11) [user + S]; Admin › Status sistem "Staf aktif" counts accounts that are not activated, and "Aktivitas Admin terbaru" shows fewer rows than its title (Q2-12) [S + user] | QA | user + S |
| 63 | Account pages (QA): no "Keluar" on `/aktivasi` (Q1-11, Q2-04) [S]; "Email ini sudah dipakai akun lain." lets a signed-in account test which emails exist: limit the tries or answer neutrally (Q1-19, Q2-06) [user]; a download that asks for the password does not continue after the confirmation, and that page has no "Batal" (Q2-18) [S + user]; closing the Status aktivasi class popup with X or a click outside leaves `?kelas=` in the address (Q2-16) [S]; the bell count stays until a reload after "Tandai dibaca" (Q2-26) [S] | QA | S + user |
| 64 | Admin › Log audit (Q2-33, Q2-35): 16 events still show their raw key (list in `hasil-uji/qa-2-peran-lain.md`; add them to `Admin\AuditLog::EVENT_LABELS`), the Subjek column shows ids, details are raw JSON (the new properties `previous_expires_at`, `past_dates`, `student_note_from_draft` too), the CSV uses commas while the Status aktivasi CSV uses semicolons | QA, M2-B | S + user |
| 65 | Forms and lists (QA) [S unless noted]: validation messages appear one at a time in Usulkan lomba, Kelola lomba, Tambah pengguna, Jadwalkan, Buat acara (Q1-08, Q2-28); "Tambah prestasi" is one select without search (Q2-20); one long word from a student makes Kesiswaan › Talenta scroll sideways (Q2-29); Kepala Sekolah › Persetujuan: "Pilih semua" does not follow the selection, bulk approve and TU's "Kirim" have no confirmation (Q1-10); native `confirm()` in Import and "Tandai dipublikasikan" (Q2-34); tour highlight about 23 px off, last button "Lanjut" (Q1-09); "Kirim ulang" countdown jumps every 5 s (Q1-24); the note for the guru pembina is cut at about 300 letters (Q2-21); Humas › Arsip: empty Flyer column (Q2-19); Database prestasi: no sorting (Q2-23, see #32) | QA | S |
| 66 | Import and teams (QA): the Excel template's NIS/NISN columns are not Text (leading zeros are lost) and the "rows to check" file lacks NISN and birth date (Q1-21) [user + S]; a student who is not activated can be invited to a team, and an open invitation cannot be withdrawn (Q1-22) [user + S] | QA | user + S |
| 67 | Wording (Q1-27, Q1-28, Q2-36; together with #56): "peramban" in the mail footer, "Daring · Daring", mixed "Anda" / "kamu" for staff, internal codes such as "(D-08)" on Admin screens, "1000" vs "1.000". Speed and phone width (ringkasan §5, §6): Kepala Sekolah › Laporan PDF 8.7 s, letter PDF 1.7–3.4 s and flyer PNG 3.3 s without a "sedang dibuat" text, portfolio PDF 878 kB for one empty page (fonts not subset), Kepala Sekolah dashboard scrolls 107 px sideways at phone width (Q2-31), touch targets under 44 px (Q2-32; see also #53) | QA | S + user |
| 68 | Small leftovers of the M2 notes: optional demo rows (a pending student with less than 72 h left, a talent row with a `student_note_draft`); optional `data-sth-back` hook for a real history back on the error pages ("Kembali" is a plain link to the Referer); an unknown address has no session, so its 404 links to `/` even for a signed-in visitor; Fortify's login keeps the guest session row from before the login (no `user_id`); the temporary password an Admin types for staff is not checked against the common-password list; `docs/dev-notes/W1-D.md` still shows the old badge resolver signature | M2-A…M2-C | user + S |

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
