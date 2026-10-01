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
| `docs/DECISIONS.md` | decision log D-01…D-44, U-01…U-06, open questions §F. **Wins over everything.** |
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

Notes: tests stay SQLite-compatible (MySQL-only behaviour gets its own marked test). Git Bash rewrites
paths like `/var/www/html`: prefix `MSYS_NO_PATHCONV=1` or use PowerShell. On Linux, container-created
files are root-owned: `sudo chown -R "$USER": .`. Keep LF line endings.
Production (image, `docker/compose.prod.yaml`, Apache vhost, deploy/backup/restore, CI) is documented in
**`docs/DEPLOY.md`** (owner I). Prod facts that affect code: queues `otp`, `mail`, `default`; the runtime
DB user `sth_app` has DML only (no DDL, no `TRUNCATE`: use `delete()`); web on `127.0.0.1:8080`.

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
- **Settings**: `Setting::get(SettingKey::…)` / `Setting::put()` (defaults in `SettingKey::defaultValue()`):
  `report_deadline_days` (45), `archive_months_after_grade12` (18), `signature_file_id`, signer
  name/rank/NIP, letter formats, `public_indexing` / `public_certificates` (both false, D-38/D-39). The
  password pattern is never a setting. Resolve `app(ReportDeadline::class)` / `app(GraduationCalculator::class)`
  (bound to the settings in `AppServiceProvider`); never `new ReportDeadline(…)` outside previews.
- **Consent**: `students.photo_consent` / `publish_consent` change only through
  `App\Actions\Students\UpdateConsent` (Admin › Pengguna, audited; revoking publish consent rotates
  `public_slug`). Call `App\Livewire\Publik\Data\PublicAchievements::flush()` after anything that changes
  public data.
- **Seeders** (`DatabaseSeeder`): roles + permissions, 36 classrooms, talent types/branches, default
  weight version, settings, sample signature (not in production), and `DemoSeeder` **only when
  `APP_ENV=local`** (fictional data matching the mockup queues and leaderboard).
- **Audit & jobs**: activitylog on every state change/Admin action (hash chain planned, SECURITY §3m-9);
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
  `data-sth-*` hooks and Livewire events `sth-modal-open|close`, `sth-toast`.
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

Known stale text (do not "fix" code to match it): SECURITY.md §0/§3a still say `sm4g4_DD-MM-YYYY`;
DESIGN-SPEC-v2 §1 and the Admin-Pengaturan mockup still show 30 days (and the mockup's WICE deadline
24 Okt is now **8 Nov 2026**); SECURITY/DECISIONS call the OTP flag `STH_OTP_DEV_BYPASS`; SECURITY says
port 8081; ARCHITECTURE §1 says Livewire 3 / Intervention v3 (v4 of both is installed); ARCHITECTURE §2
cites D-11 (read D-01) and U-02 (read U-03) for 2FA; SECURITY §3e's vhost sample has inline `#`
comments Apache rejects (use `docker/apache/*.conf`).

## 9. Open questions (answers come from the school; don't guess in code)
From DECISIONS §F and SECURITY §5: letter template columns, one team letter for all members?, meaning
of "MRD", yearly reset / separate books for siswa and guru numbers; parental consent for names, photos,
certificates on public pages (U-04); go-live date + team hours; Ubuntu Pro (U-01); Docker static
binaries (U-02); mandatory staff TOTP + break-glass (U-03); letter QR (U-05); WordPress isolation (U-06);
server/DNS admins; Kepsek consent for the signature image, TTE?; hijack recovery owner + risk-acceptance
signature (D-14); per-class activation schedule; SMTP provider; off-site backups + key holder; DPO.

## 10. Status
Last updated: 2026-10-01 (waves 1–2 done, W3-A integration pass). Update this section whenever a milestone moves.

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

Verified 2026-10-01 (W3-A, details in `docs/dev-notes/W3-A.md`): `php artisan test` → **925 passed (3726 assertions)** twice in a
row (SQLite; `--parallel --processes=4` also 925 passed twice); `pint --test` clean; `npm run build` OK; `composer audit` clean; prod compose `config -q` OK;
`docker build -f docker/Dockerfile.prod` OK. Local MySQL migrated; dev `queue` drains `otp,mail,default`.

### Backlog (not built yet; collected from all dev-notes and UI-MAP)
Owner: **S** = frontend/screens, **I** = deploy/QA, **user** = lead/backend core (also asks the school).

| # | item | source | owner |
|---|---|---|---|
| 1 | Akun › Keamanan (`akun.keamanan`: change password/email) and Notifikasi (`notifikasi.index`, bell) | UI-MAP §2 | S |
| 2 | Siswa Peringkat (`siswa.peringkat`, highlight "kamu"; reuse `PublicAchievements::ranking()`) | UI-MAP §3, W2-D | S |
| 3 | Guru area: `guru.beranda` (Binaan) + `guru.lomba.index` with `Lomba\UsulanForm`; guru accounts now land on the `/beranda` placeholder | UI-MAP §4e | S (+ user policies) |
| 4 | Kesiswaan Database prestasi (`kesiswaan.prestasi.index` + export) | W2-B | S + user |
| 5 | Status aktivasi (`kesiswaan.aktivasi.index`, `admin.aktivasi.index`): K9 report, K2 reopen | UI-MAP, W1-C | S + user |
| 6 | Kepsek prestasi per bidang + laporan (`kepsek.prestasi`, `kepsek.laporan`, logged/watermarked export); Kepsek list of letters issued in their name (§3m-5) | W2-C | S + user |
| 7 | TU template surat (`tu.surat.template`) | W2-C | S |
| 8 | Admin arsip otomatis screen (`admin.arsip.index`, unarchive/change date) and audit log viewer (`admin.audit.index`) | W2-D | S + user |
| 9 | `InviteMembers` (replace a declined member, D-36) + invitation expiry at `registration_closes_at` | W2-A1 | user |
| 10 | Upload limits: 30 uploads/hour/user, 200 MB per student (SECURITY §3d) | W2-A1 | user |
| 11 | Letters: correction flow (cabut + versi baru); store signature file id + schedule on `letters` (§3m-5) | W2-C | user |
| 12 | Humas: auto-publish at `scheduled_at`; flyer to the public disk after publication (decide) | W2-C | user |
| 13 | Staff role change: second approval (UI-MAP §5) | W2-D | user |
| 14 | Move the registration timeline transitions (scheduler commands) into `app/Actions` | W2-A2 | user |
| 15 | Accounts: K1 activation window, K3 freeze (columns), K8 "Bukan saya?", K10 random 72 h temporary password vs initial password, alert on > 10 resets/hour | W1-C | user |
| 16 | `security` log channel, OTP rows cleanup job (> 24 h), audit hash chain (§3m-9) | W1-C, SECURITY | user |
| 17 | `livewire.csp_safe = true` (drop `'unsafe-eval'`) once no screen needs Alpine expressions | W1-C, W1-D | user + S |
| 18 | Throttle Livewire's `/livewire-*/update` endpoint for the public pages | W2-D | user |
| 19 | Student import: queue 1 000+ rows; decide reject vs strip formula cells (§3f) | W1-C | user |
| 20 | ARCHITECTURE §4: ratify W1-A additions + `students.public_slug`, `principal_replacements`, `reports.late`, `talent_profile_files`, `registration_reminders`, `users.tour_seen_at`; fix the stale refs in §8 | W1-A, W2 | user |
| 21 | Staging on a real Ubuntu 18.04 / Apache 2.4.29 host (static Docker binaries, cgroup v1) | W1-E | I |
| 22 | CI: Trivy + gitleaks, actions pinned to SHAs; E2E tests | W1-E | I |
| 23 | Per-table DB grants so `sth_app` cannot UPDATE/DELETE `activity_log` (§3h; table list from user) | W1-E | I + user |
| 24 | SMTP noreply (D-15, DEPLOY §9); favicon/brand SVG; user guide + final Bantuan text | W1-E, W1-D | I (+ S) |
| 25 | When the repo is put under git: mark `scripts/deploy/*.sh`, `docker/php/*.sh` executable (`git update-index --chmod=+x`) | W1-E | I |
| 26 | School answers (§9): parental consent process (then `public_indexing`/`public_certificates`), letter template details, U-01…U-06 | DECISIONS §F | user |

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
