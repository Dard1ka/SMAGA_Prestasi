# W3-A — integration & polish (whole repo, after waves 1–2)  ·  status: done

## Changes (by task)
1. `composer require bacon/bacon-qr-code:^3.1` (was transitive via Fortify; lock unchanged at v3.1.1). `composer audit` → clean.
2. `SettingKey::PublicIndexing` / `PublicCertificates` (labels, default `false`, seeded by `SettingSeeder`); `PublicSettings`,
   `UpdatePublicSettings`, `FilePolicy::viewPublic` and tests use the enum (string constants `PublicSettings::INDEXING/CERTIFICATES` removed).
3. `AppServiceProvider` binds `ReportDeadline` (`report_deadline_days`, 1–365, default 45) and `GraduationCalculator`
   (`archive_months_after_grade12`, 1–120, default 18) with `bind()` (fresh per resolve; out of range/no table → default).
   Call sites now `app(...)`: `SubmitReport`, `AdvanceRegistrations`, `SendReportReminders`, `Siswa\Beranda` (2×), `Siswa\Lomba\Detail`,
   `LombaSaya\Concerns\ReportHelpers`, `Sekolah\Dashboard`, `DemoSeeder`; `ImportStudents` (constructor injection) now honours the setting.
   Kept `new …`: `BatasArsip` previews and `UpdateLimits` (they use the value being edited), `StudentFactory` (fixed default for test data).
4. `App\Support\Leaderboard\PersonalRank` (`summary()`, `rank()`): rank among active students (`active`, `pending_activation`, not
   soft-deleted), same `Ranker`. Used by `Siswa\Beranda`, `Siswa\Portofolio`, `PublicAchievements::studentDetail()['rank_active']`.
   Public leaderboard unchanged (alumni only with the toggle). Demo Nadia: #14 on Beranda, Portofolio, public page; #15 with alumni.
5. Guest certificate rule already in W2-A1 (`files.public`, `FilePolicy::viewPublic`). Public student page now links
   `route('files.public', …)` and only when `Gate::forUser(null)->allows('viewPublic', $file)` (decided in
   `PublicAchievements::publicCertificate()`); otherwise only the "Terverifikasi" badge.
6. `App\Actions\Students\UpdateConsent::handle(User $actor, Student, bool $photo, bool $publish, string $note): Student` +
   `App\Policies\StudentPolicy::updateConsent` (`student.update` = Admin; disabled/archived actors denied). Lock, audit
   `student.consent_updated` (from/to/note/slug_rotated, never the slug), revoking publish consent rotates `public_slug`,
   `PublicAchievements::flush()` after commit. UI: Admin › Pengguna › Bantuan akun siswa → "Persetujuan publikasi" (2 checkboxes +
   "Dasar perubahan", 15-min password window). Also flush after `VerifyReport` and `DisableAccount`/`ArchiveAccount` (W2-D request).
7. `CreateCompetition::rules()` (shared with `UpdateCompetition`): `website_url`, `registration_url` → `new ExternalUrl`
   (rejects IP literals, credentials, odd ports, localhost). `CompetitionSubmitted` docblock records owner W2-A1.
8. `Tests\Support\TestDisks` + `Tests\TestCase::setUp()`: every Feature test gets its own empty `local` and `public` disk and its own
   PdfRenderer sandbox root (`PdfRenderer` got an optional `$sandboxRoot` constructor arg, default `storage/app/pdf-tmp`); all
   `Storage::fake('local')` calls and the helpers `LombaFixtures::isolatedDisk()`, `W2cFixtures::isolatedLocalDisk()/dropDisk()`,
   `HumasFixtures::isolatedPublicDisk()` removed. `SaveTalentProfileTest` now counts files on its own disk.
9. `<x-sth.card padding="none">` → no padding (`match`, null filtered); `p-0!` removed from Portofolio. `<x-sth.textarea>` computes
   `$content` in `@php` (no `@if` inside `<textarea>`). `.tbl-wrap { position: relative; }`.
10. nginx: security `add_header`s removed from `docker/nginx/default.conf` (all three) and `prod.conf` (`/build/` nosniff; Apache
    `setifempty` covers static files). Live check on :8080 → each header once. Also `config/filesystems.php` local disk
    `'serve' => false`: removes Laravel's signed `/storage/{path}` route for private files (rule 4: only FileController).
11. Pint on the whole repo: 23 files fixed (incl. `app/Models/Competition.php`); `pint --test` → pass.
12. Dev stack: `migrate` → nothing pending (W2 migrations were applied); `db:seed --class=SettingSeeder` added the two public keys;
    no reseed. Demo accounts unchanged (staff active; Nadia active, PersonalRank #14). `queue` recreated (drains `otp,mail,default`),
    app/web/scheduler/mailpit up, `npm run build` OK.
13. DECISIONS.md D-32…D-44 (summary rows + section "D2"); CLAUDE.md §2/§4/§5/§6/§8 + §10 Status with one backlog table (26 rows,
    owner S/I/user); UI-MAP rows marked **built** (checked against `route:list`) incl. new rows `files.public`, `siswa.undangan.show`,
    `admin.ringkasan`.

## Tests added
`tests/Feature/Integration/{RankConsistencyTest,SettingBindingsTest,NginxHeadersTest}.php`, `tests/Feature/Admin/ConsentTest.php`,
plus cases in `Publik/PublikPrivacyTest` (files.public link; no link for a team with a non-consenting member), `Kesiswaan/
CompetitionActionsTest` (ExternalUrl ×4), `Kesiswaan/ReportVerificationTest` (cache flush), `DesignSystem/ComponentsTest`
(card padding, textarea), `DataLayer/SeedersTest` (public settings seeded false).

## Commands verified (2026-10-01)
- `docker compose run --rm --no-deps app php artisan test` → run 1: **925 passed (3726 assertions)**, 947 s · run 2: **925 passed (3726 assertions)**, 1021 s (back to back, exit 0)
- `php artisan test --parallel --processes=4` (paratest 7.20) → 925 passed twice (≈ 350 s each); stable now that every test has its own disks + PDF sandbox (a preliminary sequential run before the last edits: 922 passed)
- `./vendor/bin/pint --test` → pass · `composer audit` → no advisories · `npm run build` → OK
- `docker compose -f docker/compose.prod.yaml config -q` → 0 · `docker build -f docker/Dockerfile.prod -t sth-app:prod .` → OK

## Requests / open
- Everything still open is in CLAUDE.md §10 Backlog (26 rows). Not done here on purpose: ARCHITECTURE §4 ratification of the W2
  tables, `livewire.csp_safe`, Livewire update-endpoint throttle, InviteMembers, letter correction flow.
- `siswa/bantuan` reads the deadline with `Setting::get()` directly (display only; same value as the binding).
