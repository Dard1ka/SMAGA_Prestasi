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
>
> Ratified 2026-10-01 (backlog #20): this section now matches `database/migrations` after waves 1–2 (W1-A additions,
> W2 tables/columns) and backlog B1–B5; settings keys and staff-account rules updated after W4 (D1). Origin tags: **[W1-A]**, **[W2-…]**, **[B1]…[B5]**, **[W4]** (details in `docs/dev-notes/`).
> Runtime DB user `sth_app` has per-table privileges (`scripts/deploy/grants.sh`, DEPLOY §8.2): `activity_log` and
> `audit_seals` are INSERT/SELECT only, `migrations` SELECT only, every other table DML; no DDL, no TRUNCATE.
- **users***: id (bigint), name, username (nullable, unique; staff login, D-42) [W1-A], email (nullable, unique) [W1-A], email_verified_at, password, status (`UserStatus`), must_change_password (bool), initial_password_expires_at (K1 window / K10 temporary password, 72 h) [B1], failed_activation_attempts (K3) [B1], frozen_at, frozen_reason (`failed_attempts` | `not_me`; login refused while set) [B1], activated_at (K9) [B1], password_changed_at, last_login_at, tour_seen_at (menu tour done) [W2-A1], Fortify two_factor_* columns, remember_token.
- **classrooms**: id, name (e.g. `X-1`, `X-TAHFIDZ`, `X-OLIMP`, `XI-12`, `XII-3`), level (10/11/12), active.
- **students***: id, user_id (unique), nis (unique), nisn (unique), birth_date (date), classroom_id, import_school_year (e.g. `2026/2027`), grade12_start_date (date, computed), archive_at (date, computed, indexed), phone (nullable, `encrypted` cast), photo_consent (bool, default false), publish_consent (bool, default false), public_slug (string 32, nullable, unique; random URL key of the public student page, rotated when publish consent is revoked, `UpdateConsent`) [W2-D, W3-A].
- **staff_profiles**: id, user_id, honorific (Bu/Pak/…, nullable) [W1-A], nip (nullable), rank (pangkat/gol, nullable), position (jabatan).
- **competitions*** (lomba): id, name, description (nullable) [W1-A], bidang (`Bidang`), organizer_name, organizer_type (`OrganizerType`), weight_category (`WeightCategory`), event_status (`EventStatus`), level (`Level`), mode (`EventMode`), registration_opens_at, registration_closes_at, starts_on, final_on (date of the final — report deadline base), location, participation (`Participation`), max_team_size (nullable), default_mentor_id (users.id, guru), website_url, registration_url (D-28), juknis_file_id, cover_file_id, status (`CompetitionStatus`: menunggu_verifikasi, dipublikasikan, ditolak, ditutup), submitted_by, verified_by, verified_at, verification_note. Weight and date fields are nullable while `menunggu_verifikasi` (usulan) [W1-A]; `VerifyCompetition` requires them before `dipublikasikan`. **Every create starts at `menunggu_verifikasi` (D-27)**; only Kesiswaan/Admin can move it to `dipublikasikan`.
- **registrations*** (pendaftaran): id, competition_id, leader_student_id, type (individu/tim), branch (cabang, nullable) [W1-A], mentor_id (guru, required D-35), status (`RegistrationStatus`, §6), principal_decision_by, principal_decided_at, principal_note, cancelled_at.
- **registration_members**: id, registration_id, student_id, role (ketua/anggota), invited_at, confirmed_at, declined_at. Unique (registration_id, student_id). A student cannot be in two non-final registrations of the same competition. While `menunggu_konfirmasi` and registration is open, the leader may invite more members or replacements (`InviteMembers`; never someone who declined this team; ≤ max_team_size) [B3]. Open invitations expire at `registration_closes_at` by setting `declined_at`; a team left with < 2 confirmed members stays `menunggu_konfirmasi` until the leader cancels (D-36, D-50) [B3].
- **registration_reminders** [W2-A2]: id (bigint), registration_id, kind (reminder type, e.g. report due), sent_at. Unique (registration_id, kind): each reminder is sent once.
- **letters** (surat tugas): id, registration_id, type (`LetterType`: siswa, guru), version (default 1) [B3], replaces_letter_id (nullable, previous version) [B3], sequence (int, entered by TU), full_number, year, month, issued_on, basis_number, basis_date, schedule_day, schedule_time, place (nullable, printed schedule) [B3], file_id (PDF), signature_file_id (signature image used, nullable) [B3], verification_code (random, unique; printed as QR/URL `/verifikasi-surat/{code}`), generated_by, generated_at, sent_at, revoked_at, revoked_by, revoke_reason (correction = one step `RevokeLetter`: revoke + new version with a new number, old number never reused, allowed at any stage, Kepala Sekolah notified; the reason stays in the audit log and TU history, not in mails; verification page shows "Dicabut", D-49) [B3]. **Unique (type, year, sequence)** and unique (registration_id, type, version) [W1-A, B3]. One siswa letter per registration lists all members; the current letter is the one with `revoked_at` null.
- **reports*** (laporan hasil): id, registration_id (unique), claimed_result (`ResultType`), submitted_by, submitted_at, late (bool: submitted after `final_on` + `report_deadline_days`, still accepted) [W2-A2], status (`ReportStatus`: dikirim, perlu_revisi, diverifikasi, ditolak), final_result (nullable), verified_by, verified_at, note, research_title, research_abstract.
- **report_files**: id, report_id, kind (sertifikat, foto, pengumuman, laporan_riset, artikel, poster), file_id.
- **achievements** (prestasi, one row per member): id, report_id, student_id, competition_id, result, level, weight_category, w_level (decimal 6,2), w_result (decimal 4,2), w_organizer (decimal 4,2), points (decimal 8,2), weight_version_id, verified_at. Unique (report_id, student_id).
- **weight_versions**: id, effective_at, created_by, note. **weight_values**: id, weight_version_id, group (`WeightGroup`: level, result, organizer), key, value (decimal 6,2).
- **settings**: key (string PK), value (json). Keys = the 12 cases of `App\Enums\SettingKey` (defaults in `SettingKey::defaultValue()`, seeded by `SettingSeeder`): `report_deadline_days` (45, D-25), `archive_months_after_grade12` (18), `signature_file_id` (null), `signer_name`, `signer_rank`, `signer_nip` (placeholders `[Nama Kepala Sekolah]`, `[Pangkat/Golongan]`, `[NIP]`), `letter_format_siswa` (`400.3.8/{seq}/MRD/{roman_month}/{year}`), `letter_format_guru` (`400.3.8.6/{seq}/{year}`), `public_indexing`, `public_certificates` (both false, D-38/D-39) [W3-A], `activation_window_days` (14, Admin edits 1–30, D-54; `SettingKey::ActivationWindowDays`) [B1, W4], `letter_template` (null = built-in texts; JSON of the fixed letter texts, `App\Actions\Templates\LetterTemplate`, D-60; `SettingKey::LetterTemplate`) [B5, W4]. The initial-password pattern lives in code, never in a setting. OTP test mode is not a setting either: only the env flag `STH_OTP_TEST_MODE` with `APP_ENV=local` (§8, D-62); `SettingKey::OtpTestMode` was removed and the W4 migration `2026_10_01_600000_w4_remove_otp_test_mode_setting` deletes the old `otp_test_mode` row.
- **talent_types**: id, code (osn, riset, fls3n, o2sn, olpain, debat), name, bidang (OSN/OLPAIN→sains, Riset→riset, FLS3N/Debat→seni_budaya, O2SN→olahraga; D-22), sort_order [W1-A]. **talent_branches**: id, talent_type_id, name, team_size (nullable), active, sort_order [W1-A]; unique (talent_type_id, name). Seeded from `docs/TALENT-FORM.md`.
- **talent_applications***: id, student_id, talent_type_id, talent_branch_id (nullable for OLPAIN), evidence_file_id (nullable), status (`TalentStatus`: terdaftar, pemetaan, seleksi, pembinaan, tidak_lolos), mentor_id, assessment_on, note. Unique (student_id, talent_type_id, talent_branch_id); OLPAIN (branch NULL) duplicates are blocked in the Action.
- **talent_profiles**: id, student_id (unique), interests (json, ≤ 4), skills, organizations, junior_high_achievements. **talent_profile_files** [W2-A2]: id, talent_profile_id, file_id; unique (talent_profile_id, file_id) (supporting documents of the profile).
- **publications**: id, achievement_id (unique) [W1-A], status (siap, terjadwal, dipublikasikan), channels (json), scheduled_at, caption, flyer_file_id (private disk), published_at, created_by. At `scheduled_at` a job flips `terjadwal` → `dipublikasikan` (`published_at` = the scheduled time), notifies the student and reminds Humas to post; the flyer stays private (D-47) [B3].
- **appreciation_events**: id, name, held_on. **appreciations**: id, achievement_id, appreciation_event_id, status (dijadwalkan, selesai); unique (achievement_id, appreciation_event_id).
- **files**: id, disk, path, original_name, mime, size, sha256, purpose (`FilePurpose`), owner_user_id. All uploads go through `App\Domain\Files\StoreUpload`: extension + sniffed-MIME allowlist (pdf, jpg, jpeg, png, webp), max 10 MB, images re-encoded with EXIF stripped, random path under `storage/app/private`; per user max 30 uploads/hour and 200 MB per student (`UploadLimits`) [B2].
- **otp_codes**: id, user_id, purpose (verify_email, reset_password), email, code_hash, expires_at, attempts, used_at. Rows older than 24 h are deleted by a scheduled job [B2].
- **principal_replacements** [W2-D]: id, from_user_id (nullable when there is no Kepala Sekolah yet), to_user_id, requested_by, reason, effective_at (+24 h, D-40), applied_at, cancelled_at, cancelled_by, cancel_reason.
- **role_change_requests** [B5]: id, user_id, from_roles (json), to_role, requested_by, reason, effective_at (nullable; +24 h when there is only one Admin), approved_at, approved_by (a second Admin), applied_at, cancelled_at, cancelled_by, cancel_reason. Granting Admin, Kesiswaan or Tata Usaha goes through this table via `RequestRoleChange` (D-46; Kepala Sekolah only through `principal_replacements`, `PrivilegedRoles`); downgrades apply at once. Due delayed requests are applied by the scheduled `sth:peran-tertunda` (every 5 min) [W4]. A **new staff account** with role admin, tata_usaha or kesiswaan (`CreateStaffAccount`) is created **without a role** plus a pending request in the same transaction (`RequestRoleChange::open()`, reason `CreateStaffAccount::NEW_ACCOUNT_REASON`; if the request is refused, e.g. by the 3-Admin limit, the account is rolled back); guru and humas get the role at once; kepala_sekolah only through `principal_replacements` (D-61) [W4]. Audit event `account.staff_created` properties: `role` (role given at once; null for a privileged role), `requested_role` (the privileged role requested; null otherwise), `username`.
- **email_notices** [B1]: id, user_id, kind (e.g. activation, email change), sent_to_masked, expires_at, used_at — single-use "Bukan saya?" (K8) tokens; using one freezes the account.
- **student_imports** [B2]: id, user_id, file_id (nullable), status, extension, school_year, file_name, file_sha256, total_rows, valid_rows, invalid_rows, sanitized_rows (cells that started with `=`, `+`, `-`, `@`, D-48), processed_rows, created_count, error, started_at, finished_at — queued import runs (> 50 valid rows, slices of 100, D-55); the stored spreadsheet is deleted when the job ends (done or failed), `file_sha256` stays (D-64).
- **audit_seals** [B2]: id (bigint), activity_id (unique), prev_hash, hash (sha256 hash chain over `activity_log`, written once, INSERT only). **audit_chain_heads** [B2]: id (tinyint, single row), last_seal_id, last_hash. Seals are written after the business commit in their own short transaction (D-63); verified by `sth:audit:verify` (SECURITY §3m-9).
- **activity_log** (spatie/activitylog v5): id, log_name, description, subject_type, subject_id (string 64: ULIDs and bigint ids) [W1-A], event, causer_type, causer_id, attribute_changes (json), properties (json), timestamps. Append-only for the app (no UPDATE/DELETE, §4 note).
- **notifications** (Laravel database notifications, uuid PK) [W1-A], Laravel defaults (sessions, password_reset_tokens, cache, cache_locks, jobs, job_batches, failed_jobs, migrations), Fortify `passkeys`, spatie tables (roles, permissions, model_has_roles, model_has_permissions, role_has_permissions).

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
Transitions happen only in `app/Actions/Registrations/*`, which assert the source status inside a DB transaction with `lockForUpdate()`, write the activity log, and dispatch queued notifications (mail + database). The time-driven transitions (surat_terbit → berlangsung → menunggu_laporan) are Actions too (`StartCompetition`, `AwaitReport`), called by the scheduler command `sth:registrations:advance` [B3].

## 7. Domain rules (pure PHP, unit-tested in tests/Unit)
- **Points** (`App\Domain\Points\PointCalculator`): points = w_level × w_result × w_organizer from the ACTIVE weight version at verification time; weights are snapshotted on the achievement; every team member gets full points; round half-up to 2 decimals (UI shows up to 1 decimal with comma). Defaults — level {sekolah 20, kota 40, provinsi 60, nasional 80, internasional 100}; result {juara_1 1.00, medali_emas 1.00, juara_2 0.85, medali_perak 0.85, juara_3 0.70, medali_perunggu 0.70, finalis 0.50, semifinalis 0.30, penghargaan_khusus 0.30, peserta 0.10}; organizer {A 1.00, B 0.80, C 0.60, D 0.40, E 0.20}. Changing weights creates a new version; a queued job recomputes all achievements (audited).
- **Leaderboard**: SUM(points) per student over all time (cumulative), archived students included with an `alumni` flag; ties → more higher-ranked results first, then name.
- **Report deadline** (`App\Domain\Deadlines\ReportDeadline`): final_on + N days (setting, default 45).
- **Archive date** (`App\Domain\Archive\GraduationCalculator`): from the classroom level at import + the import school year → grade-12 start = 1 July of the calendar year the student starts XII → archive_at = +18 months (setting). Example: kelas X in 2026/2027 → XII from 2028-07-01 → archive 2030-01-01. A daily scheduled job archives due students: status → archived, sessions deleted, login refused (D-29); achievements stay public.
- **Initial password** (`App\Domain\Accounts\InitialPassword`): `sm4g4_` + birth_date->format('dmy') (D-24). Used only at import; hashed with the app's hasher (Laravel default bcrypt, `BCRYPT_ROUNDS`); sets `must_change_password=true` and the activation window `initial_password_expires_at` (K1). An Admin reset gives a random temporary password valid 72 h instead (K10, D-45). The new password has at least 10 characters (D-32) and `PasswordPolicy` rejects the pattern, the birth date, NIS, NISN and the name.
- **Letter number** (`App\Domain\Letters\LetterNumber`): built from the settings format; `sequence` entered manually by TU; uniqueness enforced by the DB unique index plus friendly validation; roman month for siswa letters.

## 8. Auth & security essentials (details in SECURITY.md)
- One login field (D-42): students use NIS or NISN (10 digits = NISN), staff `users.username` or email; one generic error message; RateLimiter keyed by account bucket (NIS and NISN share one) + IP (strict) and by IP (loose, school NAT).
- First login: force password change → bind email → OTP (6 digits, hashed, 10-minute TTL, 5 attempts, 60 s resend cooldown); the initial password works only inside its activation window (K1, reopen 72 h = K2) and 10 failed attempts freeze a not-activated account (K3). **OTP test mode** accepts any 6 digits ONLY when `APP_ENV=local` AND `STH_OTP_TEST_MODE=true`; `AppServiceProvider::boot()` throws if it is on elsewhere; CI asserts this.
- Staff roles require TOTP 2FA before entering role areas (`staff.2fa`; skipped only when `APP_ENV=local`, `STH_REQUIRE_STAFF_2FA`).
- Archived, disabled and frozen users are rejected at login and their sessions deleted; "Bukan saya?" email links (K8, `email_notices`) freeze the account.
- Public pages expose only name, class and achievements; names only with `publish_consent` (else "Siswa kelas X"), photos only with `photo_consent`, certificate links only with the consent of every team member **and** the Admin switch `public_certificates`; pages are `noindex` while `public_indexing` is off (D-38, D-39).
- Audit: every state change and Admin action writes `activity_log`; rows are sealed in a hash chain (`audit_seals`) and the runtime DB user cannot UPDATE/DELETE either table (SECURITY §3h, §3m-9).

## 9. Environments
- Dev: `docker compose up` → app (php-fpm 8.4 + nginx on 127.0.0.1:8080), mysql 8.4 (127.0.0.1:3307), mailpit (127.0.0.1:8025), vite, scheduler, queue worker.
- Prod: image from `docker/Dockerfile.prod` (multi-stage: composer → node build → php-fpm runtime, non-root), behind the host Apache 2.4.29 vhost `prestasi.sman3-smg.sch.id` → 127.0.0.1:8080. See SECURITY.md §3e/§3j.
