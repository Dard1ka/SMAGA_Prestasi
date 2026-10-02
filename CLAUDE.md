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
| `tata_usaha` | school admin office (TU) | enter letter numbers, generate surat tugas PDFs |
| `humas` | public relations | publication queue, e-flyer, *apresiasi* |
| `admin` | operator | everything: users, import, settings, signature image, replace the Kepsek account (all audited) |

Students log in with NIS or NISN, staff with `username` or email. Public pages (no login): prestasi
overview, per-*bidang* drill-down, winners per lomba, cumulative ranking; only name + class + verified
achievements, gated by consent flags (D-16, SECURITY §3m-7).

## 2. Sources of truth (read before changing behaviour)
| file | content |
|---|---|
| `docs/DECISIONS.md` | decision log D-01…D-77 (D-45…D-61 = backlog wave + W4 product choices, *sementara*; D-62…D-65 = security hardening, *final*; D-66…D-77 = Q1 security/performance pass and the lead's follow-up choices), U-01…U-06, open questions §F. **Wins over everything.** |
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
   no emoji, no dark theme, decimal comma (`1,00`). Code, identifiers, comments, commits: English
   (Indonesian domain terms are fine).
10. **Tests are required**: every domain rule, Action, Policy and route gets Pest tests, including the
    forbidden cases (role × route matrix). Red tests or failing Pint = not done.
11. **AI agents never `git commit`/`push`/switch branches unless the user asks in chat.** Only edit files
    your area owns (§11); request other changes in your dev-note.
12. Never reintroduce a superseded decision (§8).

## 4. How to run (Docker only)
Dev stack (`compose.yaml`, every port bound to 127.0.0.1):

| service | what | URL / port |
|---|---|---|
| `app` | php-fpm 8.4, `docker/php/Dockerfile` target `dev` (root, dev only); reads the bind-mounted `.env` (no `env_file`) | – |
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
drop `force="true"`.

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
app/Support/Navigation.php        sidebar menus per role
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
Auth routes (routes/web.php, Fortify + Livewire `App\Livewire\Auth\*`): `/login`, `/logout`,
`/two-factor-challenge`, `/user/confirm-password`, `/aktivasi` (`aktivasi`), `/lupa-kata-sandi`
(`password.request`), `/akun/2fa` (`two-factor.setup`). Fortify `home` = `/beranda`, which redirects to
the first existing `<area>.beranda` route of the user's role (`siswa`, `kesiswaan`, `kepsek`, `tu`,
`humas`, `guru`, `admin`): every area must define its `.beranda` route.

- **Keys**: domain tables use ULIDs (`$table->ulid('id')->primary()`, `HasUlids`); `users.id` stays
  bigint (Fortify, sessions, spatie). Only ULIDs appear in URLs.
- **Enums** (values exactly as ARCHITECTURE §5): `Bidang` sains/riset/seni_budaya/olahraga · `Level` ·
  `WeightCategory` A–E · `ResultType` · `UserStatus` pending_activation/active/disabled/archived ·
  `CompetitionStatus` · `RegistrationStatus` (`phase()`, `tone()`) · `ReportStatus` · `TalentStatus` · …
- **Competition**: every create, by any role (siswa *usulan*, guru, staff, even Kesiswaan), starts at
  `menunggu_verifikasi`; only Kesiswaan/Admin publish it, verifier + time audited (D-27). Has
  `registration_url` (D-28). URLs validated `https`, never fetched.
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
- **Ranking**: cumulative SUM over all years. Public leaderboard: alumni included with chip "Alumni"
  behind the "Tampilkan alumni" toggle (D-09). A student's own rank ("peringkatmu": Beranda, Portofolio,
  public student page) is among **active** students only, always via `App\Support\Leaderboard\PersonalRank`
  (D-37; demo Nadia = #14).
- **Report deadline** (`App\Domain\Deadlines`): `final_on` + **45 days** (setting
  `report_deadline_days`, Admin-editable, D-25).
- **Archive** (`App\Domain\Archive`): grade-12 start = 1 July of the year the student enters kelas XII;
  `archive_at` = +18 months (e.g. kelas X 2026/2027 → XII 2028-07-01 → archive 2030-01-01). Daily job
  01:00 Asia/Jakarta, idempotent; sessions revoked; **archived users cannot log in** (D-29); their
  achievements stay in ranking/public pages. Admin can also disable/archive manually with a reason (D-30).
- **Letters** (`App\Domain\Letters\LetterNumber`): TU types two numbers: siswa `400.3.8/{seq}/MRD/
  {roman_month}/{year}`, guru `400.3.8.6/{seq}/{year}`; unique (type, year, sequence), immutable, only for
  `disetujui` registrations (server check). One siswa letter per registration lists all members + one
  *Surat Perintah Tugas* for the pembimbing; one private signature+stamp image (Admin) on every letter;
  dompdf remote/JS off; QR code → public `/verifikasi-surat/{code}` (SECURITY §3g, §3m-5).
- **Accounts**: students log in by NIS or NISN (10 digits → NISN), staff by `users.username` or email.
  First login: change password → bind email → 6-digit OTP. No activation code (D-14). Initial password
  only via `App\Domain\Accounts\InitialPassword`. New student passwords: **min 10 characters** and
  `App\Domain\Accounts\PasswordPolicy` rejects birth date / `sm4g4` / NIS / NISN / name (K5, lead
  2026-10-01). Import (Excel/CSV, dry-run first) by Kesiswaan and Admin (D-12, D-26). Account Actions
  live in `app/Actions/Accounts` (activation, OTP, reset, disable, archive, import).
- **Models & permissions**: status/verification/points columns are not in `$fillable`; Actions set them
  with `forceFill()`. Permissions = `App\Enums\Permission` (`competition.verify`, `registration.approve`,
  `letter.generate`, `report.verify`, `student.import`, `weight.manage`, …), matrix in
  `RoleName::defaultPermissions()`; Admin has everything **except** `registration.approve`.
- **Talent**: 6 types mapped to 4 bidang (OSN, OLPAIN → sains; Riset → riset; FLS3N, Debat →
  seni_budaya; O2SN → olahraga, D-22); a student may pick several; all active classes incl. XII (D-23).
- **Settings**: `Setting::get(SettingKey::…)` / `Setting::put()` (defaults in `SettingKey::defaultValue()`,
  seeded by `SettingSeeder`), exactly these keys: `report_deadline_days` (45), `archive_months_after_grade12` (18),
  `signature_file_id`, `signer_name` / `signer_rank` / `signer_nip`, `letter_format_siswa` / `letter_format_guru`,
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
  `data-sth-*` hooks and Livewire events `sth-modal-open|close`, `sth-toast`. The CSP never allows
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
active students, public ranking with alumni; names only with `publish_consent`, `noindex` by default;
certificate links only with consent + Admin switch; Kepsek replacement after 24 h; max 3 Admins +
`sth:create-admin`; staff login username/email, students NIS/NISN; letters Issue then Send, student letter
prints NIS; `users.id` bigint, domain tables ULID. Also binding: W1-A's schema additions
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

Known stale text (do not "fix" code to match it): SECURITY.md §0/§3a still say `sm4g4_DD-MM-YYYY`;
DESIGN-SPEC-v2 §1 and the Admin-Pengaturan mockup still show 30 days (and the mockup's WICE deadline
24 Okt is now **8 Nov 2026**); SECURITY/DECISIONS call the OTP flag `STH_OTP_DEV_BYPASS`; SECURITY says
port 8081; SECURITY §3e's vhost sample has inline `#` comments Apache rejects (use `docker/apache/*.conf`);
SECURITY §3h's grant sample names `audit_logs` (the table is `activity_log`, DEPLOY §8.2); SECURITY §3f keeps import
files 7 days (queued-import files are deleted when the job ends, D-64). ARCHITECTURE §1–§8 were
brought up to date on 2026-10-01 (backlog #20; §4 settings/staff accounts after W4).

## 9. Open questions (answers come from the school; don't guess in code)
From DECISIONS §F and SECURITY §5: letter template columns, one team letter for all members?, meaning
of "MRD", yearly reset / separate books for siswa and guru numbers; parental consent for names, photos,
certificates on public pages (U-04); go-live date + team hours; Ubuntu Pro (U-01); Docker static
binaries (U-02); mandatory staff TOTP + break-glass (U-03); letter QR (U-05); WordPress isolation (U-06);
server/DNS admins; Kepsek consent for the signature image, TTE?; hijack recovery owner + risk-acceptance
signature (D-14); per-class activation schedule; SMTP provider; off-site backups + key holder; DPO.
Public DNS check of 2026-10-01 (not yet confirmed with the school): the domain's DNS is on Cloudflare (`www`, `mail`,
`webmail` proxied); the main website and the school mail are on Desnet shared hosting (SPF, DKIM and DMARC `p=none`
exist), so apparently **not** on the Ubuntu server (§1 assumes the same host: confirm). No `prestasi` record exists
yet: add an A record to the Ubuntu server as "DNS only" (behind the Cloudflare proxy the app would only see Cloudflare
IPs, which breaks the per-IP login/OTP limits). The noreply mailbox would then be DEPLOY §9 path B on that hosting; its
SMTP host must not be `mail.<domain>` (proxied) and its hourly send limit needs asking.

## 10. Status
Last updated: 2026-10-02 (waves 1–2 done, W3-A integration pass, backlog wave B1–B6, W4 integration, D1 docs sync, CI green on GitHub, Q1 end-to-end/security/performance pass). Update this section whenever a milestone moves.

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

### Backlog (open; collected from all dev-notes and UI-MAP)
Owner: **S** = frontend/screens, **I** = deploy/QA, **user** = lead/backend core (also asks the school).
Done in the backlog wave B1–B6 (details in the B dev-notes): former #1–#20, #22–#25; in W4 (`docs/dev-notes/W4.md`): #27–#31
(scheduler `sth:peran-tertunda`, menus, privileged staff accounts, settings/model tidy-up, K10 staff resets, signature upload via
`StoreUpload`). The optional `OtpPurpose::ChangeEmail` of #30 was not done (now #35). Kept numbers for the open items.

| # | item | source | owner |
|---|---|---|---|
| 21 | Staging on a real Ubuntu 18.04 / Apache 2.4.29 host (static Docker binaries, cgroup v1). The first real CI run on GitHub is done (2026-10-02, all six jobs green) | W1-E, B6 | I |
| 26 | School answers (§9): parental consent process (then `public_indexing`/`public_certificates`), letter template details, SMTP relay + DNS records (DEPLOY §9), activation window/school hours (D-54), U-01…U-06 | DECISIONS §F | user |
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
