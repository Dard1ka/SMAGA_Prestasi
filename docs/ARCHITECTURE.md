# SMAGA Talent Hub (STH) — Architecture contract v1

Status: binding contract for all developers/agents. Change it only through a PR that also updates this file.
Decisions referenced as D-xx live in `docs/DECISIONS.md`; security controls (K1–K12, §3x) in `docs/SECURITY.md`.

## 1. Stack
- PHP 8.4, **Laravel 13**, MySQL 8.4 LTS, all in Docker (dev: `compose.yaml`; prod: `docker/compose.prod.yaml`).
- UI: Blade + **Livewire 4 (4.4)** + Alpine + Tailwind CSS v4 (Vite, built in a Node container at image build time).
- Auth: Laravel **Fortify** (headless) with custom login by NIS or NISN; TOTP 2FA mandatory for staff roles (U-03).
- Packages: `spatie/laravel-permission` (roles), `spatie/laravel-activitylog` (audit), `spatie/simple-excel` (import/export via OpenSpout — never evaluates formulas), `barryvdh/laravel-dompdf` (dompdf ≥ 3.1.6; remote/JS disabled, chroot), `intervention/image` v4 (EXIF strip, flyer), `pestphp/pest` (tests).
- Queue/cache/session: `database` drivers (no Redis needed on the 8 GB host). Scheduler via `php artisan schedule:work` container.
- Storage: `local` private disk only (`storage/app/private`). Nothing user-uploaded is ever web-served directly; files are streamed by controllers after a Policy check.
- Locale `id`, timezone `Asia/Jakarta`, decimal comma in UI (`Number::format(..., locale: 'id')`).

## 2. Roles (spatie, guard `web`)
`siswa`, `guru` (guru pembimbing/pembina), `kesiswaan`, `kepala_sekolah`, `tata_usaha`, `humas`, `admin`.
Admin can do everything and manage all accounts incl. replacing the Kepala Sekolah account (D-01) — every Admin action is audited.
Permissions are named `<area>.<action>` (e.g. `competition.verify`, `registration.approve`, `letter.generate`, `student.import`). Policies are the source of truth; UI hides what policies deny.
Student import: Kesiswaan + Admin (D-26). Competition upload: any logged-in role (siswa, guru, staff); publication only after Kesiswaan/Admin verification (D-27).

## 3. Folder conventions
```
app/Enums/                 # PHP backed enums (string) — see §5
app/Models/                # Eloquent models, ULID primary keys (HasUlids), explicit $fillable, never $guarded = []
app/Domain/<Area>/         # pure services & value objects (no HTTP): Points, Deadlines, Archive, Letters, Accounts, Files
app/Actions/<Area>/        # single-purpose use cases called by controllers/Livewire (e.g. ApproveRegistration)
app/Policies/              # one per model
app/Http/Controllers/      # thin; file downloads via FileController (policy-checked streaming)
app/Livewire/<Role>/       # screens per role, mirrors the mockup canvas
resources/views/components/# design-system Blade components (x-sth.*), tokens from DESIGN-SPEC-v2
database/migrations|factories|seeders
tests/Unit (Domain), tests/Feature (HTTP/Livewire/Policy)
docker/                    # Dockerfiles, nginx.conf (inside container), php.ini, host Apache vhost sample
```

## 4. Data model (MySQL 8.4, utf8mb4; ULID PKs unless noted; timestamps everywhere; soft deletes where marked *)
> Exception: `users.id` stays the framework's auto-increment BIGINT because Fortify (2FA/passkeys), sessions, spatie/permission and activitylog are built on it. Every FK to users is `foreignId(...)->constrained('users')`. All domain tables use ULIDs (`$table->ulid('id')->primary()`, `HasUlids`), and only domain ULIDs appear in URLs.
- **users***: id (bigint), name, email (nullable, unique), email_verified_at, password, status (`UserStatus`), must_change_password (bool), password_changed_at, last_login_at, Fortify two_factor_* columns, remember_token.
- **classrooms**: id, name (e.g. `X-1`, `X-TAHFIDZ`, `X-OLIMP`, `XI-12`, `XII-3`), level (10/11/12), active.
- **students***: id, user_id (unique), nis (unique), nisn (unique), birth_date (date), classroom_id, import_school_year (e.g. `2026/2027`), grade12_start_date (date, computed), archive_at (date, computed), phone (nullable, `encrypted` cast), photo_consent (bool, default false), publish_consent (bool, default false).
- **staff_profiles**: id, user_id, nip (nullable), rank (pangkat/gol, nullable), position (jabatan).
- **competitions*** (lomba): id, name, bidang (`Bidang`), organizer_name, organizer_type (`OrganizerType`), weight_category (`WeightCategory`), event_status (`EventStatus`), level (`Level`), mode (`EventMode`), registration_opens_at, registration_closes_at, starts_on, final_on (date of the final — report deadline base), location, participation (`Participation`), max_team_size (nullable), default_mentor_id (users.id, guru), website_url, registration_url (D-28), juknis_file_id, cover_file_id, status (`CompetitionStatus`: menunggu_verifikasi, dipublikasikan, ditolak, ditutup), submitted_by, verified_by, verified_at, verification_note. **Every create starts at `menunggu_verifikasi` (D-27)**; only Kesiswaan/Admin can move it to `dipublikasikan`.
- **registrations*** (pendaftaran): id, competition_id, leader_student_id, type (individu/tim), mentor_id (guru), status (`RegistrationStatus`, §6), principal_decision_by, principal_decided_at, principal_note, cancelled_at.
- **registration_members**: id, registration_id, student_id, role (ketua/anggota), invited_at, confirmed_at, declined_at. Unique (registration_id, student_id). A student cannot be in two non-final registrations of the same competition.
- **letters** (surat tugas): id, registration_id, type (`LetterType`: siswa, guru), sequence (int, entered by TU), full_number, year, month, issued_on, basis_number, basis_date, file_id (PDF), verification_code (random, unique; printed as QR/URL `/verifikasi-surat/{code}`), generated_by, generated_at, sent_at. **Unique (type, year, sequence)**. One siswa letter per registration lists all members.
- **reports*** (laporan hasil): id, registration_id (unique), claimed_result (`ResultType`), submitted_by, submitted_at, status (`ReportStatus`: dikirim, perlu_revisi, diverifikasi, ditolak), final_result (nullable), verified_by, verified_at, note, research_title, research_abstract.
- **report_files**: id, report_id, kind (sertifikat, foto, pengumuman, laporan_riset, artikel, poster), file_id.
- **achievements** (prestasi, one row per member): id, report_id, student_id, competition_id, result, level, weight_category, w_level (decimal 6,2), w_result (decimal 4,2), w_organizer (decimal 4,2), points (decimal 8,2), weight_version_id, verified_at. Unique (report_id, student_id).
- **weight_versions**: id, effective_at, created_by, note. **weight_values**: id, weight_version_id, group (`WeightGroup`: level, result, organizer), key, value (decimal 6,2).
- **settings**: key (string PK), value (json). Keys: `report_deadline_days` (45, D-25), `archive_months_after_grade12` (18), `otp_test_mode` (bool, dev only — §8), `signature_file_id`, `signer_name`, `signer_rank`, `signer_nip`, `letter_format_siswa` (`400.3.8/{seq}/MRD/{roman_month}/{year}`), `letter_format_guru` (`400.3.8.6/{seq}/{year}`). The initial-password pattern lives in code/config, never in a UI-readable setting.
- **talent_types**: id, code (osn, riset, fls3n, o2sn, olpain, debat), name, bidang (OSN/OLPAIN→sains, Riset→riset, FLS3N/Debat→seni_budaya, O2SN→olahraga; D-22). **talent_branches**: id, talent_type_id, name, team_size (nullable), active. Seeded from `docs/TALENT-FORM.md`.
- **talent_applications***: id, student_id, talent_type_id, talent_branch_id (nullable for OLPAIN), evidence_file_id (nullable), status (`TalentStatus`: terdaftar, pemetaan, seleksi, pembinaan, tidak_lolos), mentor_id, assessment_on, note. Unique (student_id, talent_type_id, talent_branch_id).
- **talent_profiles**: id, student_id (unique), interests (json, ≤ 4), skills, organizations, junior_high_achievements.
- **publications**: id, achievement_id, status (siap, terjadwal, dipublikasikan), channels (json), scheduled_at, caption, flyer_file_id, published_at, created_by.
- **appreciation_events**: id, name, held_on. **appreciations**: id, achievement_id, appreciation_event_id, status (dijadwalkan, selesai).
- **files**: id, disk, path, original_name, mime, size, sha256, purpose, owner_user_id. All uploads go through `App\Domain\Files\StoreUpload`: extension + sniffed-MIME allowlist (pdf, jpg, jpeg, png, webp), max 10 MB, images re-encoded with EXIF stripped, random path under `storage/app/private`.
- **otp_codes**: id, user_id, purpose (verify_email, reset_password), email, code_hash, expires_at, attempts, used_at.
- Laravel defaults (sessions, cache, jobs, failed_jobs, notifications) + spatie tables (roles/permissions, activity_log).

## 5. Enums (app/Enums, string-backed, each with `label(): string` in Bahasa Indonesia)
- `Bidang`: sains, riset, seni_budaya, olahraga.
- `Level`: sekolah, kota, provinsi, nasional, internasional.
- `WeightCategory`: A, B, C, D, E.
- `ResultType`: juara_1, juara_2, juara_3, medali_emas, medali_perak, medali_perunggu, finalis, semifinalis, penghargaan_khusus, peserta.
- `OrganizerType`: pemerintah_pusat, pemerintah_daerah, ptn, pts, bumn_bumd, organisasi_profesi, federasi_olahraga, yayasan_lembaga, perusahaan_swasta, lainnya.
- `EventStatus`: puspresnas, terkurasi, pemerintah_non_puspresnas, non_pemerintah, belum_terverifikasi.
- `EventMode`: daring, luring, hybrid. `Participation`: individu, tim, keduanya.
- `UserStatus`: pending_activation, active, disabled, archived.
- `CompetitionStatus`, `RegistrationStatus`, `ReportStatus`, `LetterType`, `TalentStatus`, `WeightGroup` exactly as listed in §4/§6.

## 6. Registration state machine (`RegistrationStatus`) — maps to the 6-phase tracker in the UI
| status | tracker phase | entered when | next |
|---|---|---|---|
| menunggu_konfirmasi | 1 Daftar | team created, not all members confirmed | menunggu_kepsek · dibatalkan |
| menunggu_kepsek | 2 Persetujuan | individu submitted, or all members confirmed (email to Kepala Sekolah) | disetujui · ditolak · dibatalkan |
| disetujui | 3 Surat tugas | Kepala Sekolah approves (email to students, mentor, TU) | surat_terbit |
| ditolak | 2 (end) | Kepala Sekolah rejects with required note (email to students) | — |
| surat_terbit | 3 → 4 | TU entered both numbers, generated both PDFs, sent | berlangsung (scheduler on starts_on) |
| berlangsung | 4 Lomba | today ≥ starts_on | menunggu_laporan (scheduler after final_on) |
| menunggu_laporan | 5 Laporan hasil | today > final_on; deadline = final_on + `report_deadline_days` (45) | laporan_dikirim |
| laporan_dikirim | 5 | leader submits the report | selesai (verified) · menunggu_laporan (perlu revisi) |
| selesai | 6 Poin & apresiasi | Kesiswaan verified → achievements + points, Humas notified | — |
| dibatalkan | end | leader cancels before the principal decides | — |
Transitions happen only in `app/Actions/Registrations/*`, which assert the source status inside a DB transaction with `lockForUpdate()`, write the activity log, and dispatch queued notifications (mail + database).

## 7. Domain rules (pure PHP, unit-tested in tests/Unit)
- **Points** (`App\Domain\Points\PointCalculator`): points = w_level × w_result × w_organizer from the ACTIVE weight version at verification time; weights are snapshotted on the achievement; every team member gets full points; round half-up to 2 decimals (UI shows up to 1 decimal with comma). Defaults — level {sekolah 20, kota 40, provinsi 60, nasional 80, internasional 100}; result {juara_1 1.00, medali_emas 1.00, juara_2 0.85, medali_perak 0.85, juara_3 0.70, medali_perunggu 0.70, finalis 0.50, semifinalis 0.30, penghargaan_khusus 0.30, peserta 0.10}; organizer {A 1.00, B 0.80, C 0.60, D 0.40, E 0.20}. Changing weights creates a new version; a queued job recomputes all achievements (audited).
- **Leaderboard**: SUM(points) per student over all time (cumulative), archived students included with an `alumni` flag; ties → more higher-ranked results first, then name.
- **Report deadline** (`App\Domain\Deadlines\ReportDeadline`): final_on + N days (setting, default 45).
- **Archive date** (`App\Domain\Archive\GraduationCalculator`): from the classroom level at import + the import school year → grade-12 start = 1 July of the calendar year the student starts XII → archive_at = +18 months (setting). Example: kelas X in 2026/2027 → XII from 2028-07-01 → archive 2030-01-01. A daily scheduled job archives due students: status → archived, sessions deleted, login refused (D-29); achievements stay public.
- **Initial password** (`App\Domain\Accounts\InitialPassword`): `sm4g4_` + birth_date->format('dmy') (D-24). Used only at import/admin reset; hashed (argon2id); sets `must_change_password=true`; the new password must not equal or contain the pattern or the birth date.
- **Letter number** (`App\Domain\Letters\LetterNumber`): built from the settings format; `sequence` entered manually by TU; uniqueness enforced by the DB unique index plus friendly validation; roman month for siswa letters.

## 8. Auth & security essentials (details in SECURITY.md)
- Login field accepts NIS or NISN (digits); lookup by either; one generic error message; RateLimiter keyed by identifier+IP (strict) and by IP (loose, school NAT).
- First login: force password change → bind email → OTP (6 digits, hashed, 10-minute TTL, 5 attempts, 60 s resend cooldown). **OTP test mode** accepts any 6 digits ONLY when `APP_ENV=local` AND `STH_OTP_TEST_MODE=true`; `AppServiceProvider::boot()` throws if it is on in production; a CI test asserts this.
- Staff roles require TOTP 2FA before entering role areas.
- Archived/disabled users are rejected at login and their sessions deleted.
- Public pages expose only name, class and achievements; photos/certificate images only with `photo_consent` / `publish_consent`.

## 9. Environments
- Dev: `docker compose up` → app (php-fpm 8.4 + nginx on 127.0.0.1:8080), mysql 8.4 (127.0.0.1:3307), mailpit (127.0.0.1:8025), vite, scheduler, queue worker.
- Prod: image from `docker/Dockerfile.prod` (multi-stage: composer → node build → php-fpm runtime, non-root), behind the host Apache 2.4.29 vhost `prestasi.sman3-smg.sch.id` → 127.0.0.1:8080. See SECURITY.md §3e/§3j.
