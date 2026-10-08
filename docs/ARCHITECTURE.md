# SMAGA Talent Hub (STH) — Architecture contract v1

Status: binding contract for all developers/agents. Change it only through a PR that also updates this file.
Decisions referenced as D-xx live in `docs/DECISIONS.md`; security controls (K1–K12, §3x) in `docs/SECURITY.md`.

## 1. Stack
- PHP 8.4, **Laravel 13**, MySQL 8.4 LTS, all in Docker (dev: `compose.yaml`; prod: `docker/compose.prod.yaml`).
- UI: Blade + **Livewire 4 (4.4)** + Alpine + Tailwind CSS v4 (Vite, built in a Node container at image build time).
- Auth: Laravel **Fortify** (headless) with custom login by NIS or NISN (a student whose activation is finished may also use the bound email, D-78); TOTP 2FA mandatory for staff roles (U-03).
- Packages: `spatie/laravel-permission` (roles), `spatie/laravel-activitylog` (audit), `spatie/simple-excel` (import/export via OpenSpout — never evaluates formulas), `barryvdh/laravel-dompdf` (dompdf ≥ 3.1.6; remote/JS disabled, chroot), `intervention/image` v4 (re-encodes uploaded images, EXIF strip; it no longer draws a flyer, D-107), `pestphp/pest` (tests).
- Queue/cache/session: `database` drivers (no Redis needed on the 8 GB host). Scheduler via `php artisan schedule:work` container.
- Storage: `local` private disk only (`storage/app/private`). Nothing user-uploaded is ever web-served directly; files are streamed by controllers after a Policy check (as a download; inline only for the preview popup, §8e).
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
> W2 tables/columns) and backlog B1–B5; settings keys and staff-account rules updated after W4 (D1); columns of the four M1 migrations (2026-10-04) and of the two M2 migrations (2026-10-06) added; `letters.printed` came with QA4 (PR 12, 2026-10-06); M3 (2026-10-06) has no migration. Origin tags: **[W1-A]**, **[W2-…]**, **[B1]…[B5]**, **[W4]**, **[M1-A]…[M1-E]**, **[M2-A]**, **[M2-B]** (details in `docs/dev-notes/`).
> Runtime DB user `sth_app` has per-table privileges (`scripts/deploy/grants.sh`, DEPLOY §8.2): `activity_log` and
> `audit_seals` are INSERT/SELECT only, `migrations` SELECT only, every other table DML; no DDL, no TRUNCATE.
- **users***: id (bigint), name, username (nullable, unique; staff login, D-42) [W1-A], email (nullable, unique) [W1-A], email_verified_at, password, status (`UserStatus`), must_change_password (bool), initial_password_expires_at (K1 window / K10 temporary password, 72 h) [B1], temporary_password_issued_at (timestamp, nullable: set by `ResetStudentAccount`, cleared by `SetNewPassword` whenever the owner chooses a password; while it is set the stored password is an Admin's temporary one and cannot be kept at activation, D-92; the migration backfills it from `activity_log` `account.reset` for reset accounts that still have `must_change_password`) [M2-A], failed_activation_attempts (K3) [B1], frozen_at, frozen_reason (`failed_attempts` | `not_me`; login refused while set) [B1], activated_at (K9) [B1], password_changed_at (null on an active student = the school password was kept at activation, D-79) [M1-B: meaning only, no new column], last_login_at, tour_seen_at (menu tour done) [W2-A1], Fortify two_factor_* columns, remember_token.
- **classrooms**: id, name (e.g. `X-1`, `X-TAHFIDZ`, `X-OLIMP`, `XI-12`, `XII-3`), level (10/11/12), active.
- **students***: id, user_id (unique), nis (unique), nisn (unique), birth_date (date), classroom_id, import_school_year (e.g. `2026/2027`), grade12_start_date (date, computed), archive_at (date, computed, indexed), phone (nullable, `encrypted` cast), photo_consent (bool, default false), publish_consent (bool, default false), public_slug (string 32, nullable, unique; random URL key of the public student page, rotated when publish consent is revoked, `UpdateConsent`) [W2-D, W3-A]. The yearly import (`ImportStudents`, D-81) updates a registered student: classroom_id, import_school_year, grade12_start_date, archive_at, and phone when the file has one; it never writes name, birth_date, the password, email, status, activation or consent columns [M1-C].
- **staff_profiles**: id, user_id, honorific (Bu/Pak/…, nullable) [W1-A], nip (nullable), rank (pangkat/gol, nullable), position (jabatan).
- **competitions*** (lomba): id, name, description (nullable) [W1-A], bidang (`Bidang`), organizer_name, organizer_type (`OrganizerType`), weight_category (`WeightCategory`), event_status (`EventStatus`), level (`Level`), mode (`EventMode`), registration_opens_at, registration_closes_at, starts_on, final_on (date of the final — report deadline base), location, participation (`Participation`), max_team_size (nullable), default_mentor_id (users.id, guru), website_url, registration_url (D-28), juknis_file_id, cover_file_id, status (`CompetitionStatus`: menunggu_verifikasi, dipublikasikan, ditolak, ditutup), submitted_by, verified_by, verified_at, verification_note (nullable; `VerifyCompetition` accepts at most 1,000 characters, and since D-103 a rejection may leave it empty). Weight and date fields are nullable while `menunggu_verifikasi` (usulan) [W1-A]; `VerifyCompetition` requires them before `dipublikasikan`. **Every create starts at `menunggu_verifikasi` (D-27)**; only Kesiswaan/Admin can move it to `dipublikasikan`.
- **registrations*** (pendaftaran): id, competition_id, leader_student_id, type (individu/tim), branch (cabang, nullable) [W1-A], mentor_id (guru, required D-35), status (`RegistrationStatus`, §6), principal_decision_by, principal_decided_at, principal_note, cancelled_at.
- **registration_members**: id, registration_id, student_id, role (ketua/anggota), invited_at, confirmed_at, declined_at. Unique (registration_id, student_id). A student cannot be in two non-final registrations of the same competition. While `menunggu_konfirmasi` and registration is open, the leader may invite more members or replacements (`InviteMembers`; never someone who declined this team; ≤ max_team_size) [B3]. Open invitations expire at `registration_closes_at` by setting `declined_at`; a team left with < 2 confirmed members stays `menunggu_konfirmasi` until the leader cancels (D-36, D-50) [B3].
- **registration_reminders** [W2-A2]: id (bigint), registration_id, kind (reminder type, e.g. report due), sent_at. Unique (registration_id, kind): each reminder is sent once.
- **letters** (surat tugas): id, registration_id, type (`LetterType`: siswa, guru), version (default 1) [B3], replaces_letter_id (nullable, previous version) [B3], sequence (int, **nullable** since M1-A: only old rows and demo data have one), full_number (the complete number typed by TU, 3–64 characters, D-82) [M1-A], year, month (both taken from issued_on), issued_on, basis_from (200, "Surat dari") [M1-A], basis_number, basis_date (both optional), basis_subject (150, "Hal", guru letter) [M1-A], schedule_day, schedule_date (150; "Hari" and "Tanggal" are two typed fields, old rows keep "Hari, tanggal" in schedule_day and are split on read) [M1-A], schedule_time, place (nullable, printed schedule) [B3], printed (json, nullable: `{content, template}` = the `LetterContent` and letter template the PDF was made from, written once by `IssueLetters` / `RevokeLetter`; the TU preview of an issued letter is drawn from it, never again from today's data; not in the model's activity log, it holds names and NIS; null on letters made before 2026-10-06) [QA4], file_id (PDF), signature_file_id (signature image used, nullable) [B3], verification_code (random, unique; printed as QR/URL `/verifikasi-surat/{code}`), generated_by, generated_at, sent_at, revoked_at, revoked_by, revoke_reason (correction = one step `RevokeLetter`: revoke + new version with a new number, old number never reused, allowed at any stage, Kepala Sekolah notified; the reason stays in the audit log and TU history, not in mails; verification page shows "Dicabut", D-49) [B3]. **Unique (type, full_number)** (replaces unique (type, year, sequence), D-82) [M1-A] and unique (registration_id, type, version) [W1-A, B3]. One siswa letter per registration lists all members; the current letter is the one with `revoked_at` null. Each letter is issued on its own (`IssueLetters` takes one `LetterType`); `SendLetters` refuses until every required type has a PDF (siswa, + guru when `mentor_id` is set; `LetterPolicy::requiredTypes()`, D-83) [M1-A].
- **reports*** (laporan hasil): id, registration_id (unique), claimed_result (`ResultType`), submitted_by, submitted_at, late (bool: submitted after `final_on` + `report_deadline_days`, still accepted [W2-A2]; it belongs to the first report: the answer to a revision request keeps it, so a report sent on time is not late because the revision came after the deadline [QA8]), status (`ReportStatus`: dikirim, perlu_revisi, diverifikasi, ditolak), final_result (nullable), verified_by, verified_at, note, research_title, research_abstract.
- **report_files**: id, report_id, kind (sertifikat, foto, pengumuman, laporan_riset, artikel, poster), file_id.
- **achievements** (prestasi, one row per member): id, report_id, student_id, competition_id, result, level, weight_category, w_level (decimal 6,2), w_result (decimal 4,2), w_organizer (decimal 4,2), points (decimal 8,2), weight_version_id, verified_at. Unique (report_id, student_id).
- **weight_versions**: id, effective_at, created_by, note. **weight_values**: id, weight_version_id, group (`WeightGroup`: level, result, organizer), key, value (decimal 6,2).
- **settings**: key (string PK), value (json). Keys = the 12 cases of `App\Enums\SettingKey` (defaults in `SettingKey::defaultValue()`, seeded by `SettingSeeder`): `report_deadline_days` (45, D-25), `archive_months_after_grade12` (18), `signature_file_id` (null), `signer_name`, `signer_rank`, `signer_nip` (placeholders `[Nama Kepala Sekolah]`, `[Pangkat/Golongan]`, `[NIP]`), `letter_format_siswa` (`400.3.8/{seq}/MRD/{roman_month}/{year}`), `letter_format_guru` (`400.3.8.6/{seq}/{year}`) (since D-82 both are only the example shown under the number field; they no longer validate anything) [M1-A], `public_indexing`, `public_certificates` (both false, D-38/D-39) [W3-A], `activation_window_days` (14, Admin edits 1–30, D-54; `SettingKey::ActivationWindowDays`) [B1, W4], `letter_template` (null = built-in texts; JSON of the fixed letter texts, `App\Actions\Templates\LetterTemplate`, D-60; `SettingKey::LetterTemplate`) [B5, W4]. The initial-password pattern lives in code, never in a setting. OTP test mode is not a setting either: only the env flag `STH_OTP_TEST_MODE` with `APP_ENV=local` (§8, D-62); `SettingKey::OtpTestMode` was removed and the W4 migration `2026_10_01_600000_w4_remove_otp_test_mode_setting` deletes the old `otp_test_mode` row.
- **talent_types**: id, code (osn, riset, fls3n, o2sn, olpain, debat), name, bidang (OSN/OLPAIN→sains, Riset→riset, FLS3N/Debat→seni_budaya, O2SN→olahraga; D-22), sort_order [W1-A]. **talent_branches**: id, talent_type_id, name, team_size (nullable), active, sort_order [W1-A]; unique (talent_type_id, name). Seeded from `docs/TALENT-FORM.md`.
- **talent_applications***: id, student_id, talent_type_id, talent_branch_id (nullable for OLPAIN), evidence_file_id (nullable), status (`TalentStatus`: terdaftar, pemetaan, seleksi, pembinaan, tidak_lolos), mentor_id, assessment_on, note (the note for the guru pembina: internal, read by the guru pembina and Kesiswaan only), student_note (text, nullable: the note for the student of the latest decision, sent in that notification and shown on Profil talenta; a new decision replaces or clears it, D-85) [M1-D], student_note_draft (text, nullable: what Kesiswaan typed for the student and kept with "Simpan"; staff only, never shown to the student, the guru, the export or a notification; a decision sends its note and clears the draft, D-96) [M2-B], rejected_from (`TalentStatus` value, nullable: the stage before `tidak_lolos`; since D-104 it is the stage "Pulihkan" preselects (Terdaftar when unknown) while Kesiswaan chooses the target, and a restore clears it) [M1-D, M3-A], review_requested_at, review_message (≤ 500; one open review request per rejection, closed by a restore, D-86) [M1-D]. Unique (student_id, talent_type_id, talent_branch_id); OLPAIN (branch NULL) duplicates are blocked in the Action; a rejected choice cannot be applied for again (`ApplyTalent::REJECTED_MESSAGE`).
- **talent_profiles**: id, student_id (unique), interests (json, ≤ 4), skills, organizations, junior_high_achievements. **talent_profile_files** [W2-A2]: id, talent_profile_id, file_id; unique (talent_profile_id, file_id) (supporting documents of the profile).
- **publications**: id, achievement_id (unique) [W1-A], status (siap, terjadwal, dipublikasikan), channels (json), scheduled_at, caption, flyer_file_id (private disk; since D-107 nothing writes it and it is not in `$fillable`: it only points at flyer files STH made before 2026-10-06, kept with `FilePurpose::Flyer` and their `FilePolicy` rule, no migration) [M3-E], published_at, created_by. At `scheduled_at` a job flips `terjadwal` → `dipublikasikan` (`published_at` = the scheduled time), notifies the student and reminds Humas to post (D-47) [B3]. STH draws no flyer: Humas reads "Data untuk flyer" (`FlyerData`, §8e) and designs it in its own tools (D-107).
- **appreciation_events**: id, name, held_on, completed_at (timestamp, nullable, indexed), completed_by (users.id, null on delete) [M1-E]. An event is confirmed as a whole (`CompleteAppreciationEvent`: not before held_on, not without a scheduled row, not twice); a confirmed event takes no more rows and cannot be deleted; an unconfirmed one can be deleted (`DeleteAppreciationEvent`: its scheduled rows go, the achievements are free for another event; D-87). Scopes `open()` / `completed()`. **appreciations**: id, achievement_id, appreciation_event_id, status (dijadwalkan, selesai); unique (achievement_id, appreciation_event_id). An achievement is on at most **one** event: `RecordAppreciation` locks the achievement row and refuses it while it has any other row (still scheduled on an unconfirmed event, or already appreciated: a `selesai` row or a confirmed event); this is a rule of the Action, not a DB index. `ReleaseAppreciation` ("Lepas dari acara") deletes one `dijadwalkan` row of an unconfirmed event, which frees the achievement for another event (D-97) [M2-B].
- **files**: id, disk, path, original_name, mime, size, sha256, purpose (`FilePurpose`), owner_user_id. All uploads go through `App\Domain\Files\StoreUpload`: extension + sniffed-MIME allowlist (pdf, jpg, jpeg, png, webp), max 10 MB, images re-encoded with EXIF stripped, random path under `storage/app/private`; `original_name` ends in the extension of the sniffed content (`UploadRules::nameForContent()`: a PNG uploaded as "brosur.pdf" is stored as "brosur.png") [QA8]; per user max 30 uploads/hour and 200 MB per student (`UploadLimits`) [B2].
- **otp_codes**: id, user_id, purpose (verify_email, reset_password), email, code_hash, expires_at, attempts, used_at. Rows older than 24 h are deleted by a scheduled job [B2].
- **principal_replacements** [W2-D]: id, from_user_id (nullable when there is no Kepala Sekolah yet), to_user_id, requested_by, reason, effective_at (+24 h, D-40), applied_at, cancelled_at, cancelled_by, cancel_reason.
- **role_change_requests** [B5]: id, user_id, from_roles (json), to_role, requested_by, reason, effective_at (nullable; +24 h when there is only one Admin), approved_at, approved_by (a second Admin), applied_at, cancelled_at, cancelled_by, cancel_reason. Granting Admin, Kesiswaan or Tata Usaha goes through this table via `RequestRoleChange` (D-46; Kepala Sekolah only through `principal_replacements`, `PrivilegedRoles`); downgrades apply at once. Due delayed requests are applied by the scheduled `sth:peran-tertunda` (every 5 min) [W4]. A **new staff account** with role admin, tata_usaha or kesiswaan (`CreateStaffAccount`) is created **without a role** plus a pending request in the same transaction (`RequestRoleChange::open()`, reason `CreateStaffAccount::NEW_ACCOUNT_REASON`; if the request is refused, e.g. by the 3-Admin limit, the account is rolled back); guru and humas get the role at once; kepala_sekolah only through `principal_replacements` (D-61) [W4]. Audit event `account.staff_created` properties: `role` (role given at once; null for a privileged role), `requested_role` (the privileged role requested; null otherwise), `username`.
- **email_notices** [B1]: id, user_id, kind (e.g. activation, email change), sent_to_masked, expires_at, used_at — single-use "Bukan saya?" (K8) tokens; using one freezes the account.
- **student_imports** [B2]: id, user_id, file_id (nullable), status, extension, school_year, file_name, file_sha256, total_rows, valid_rows, invalid_rows, sanitized_rows (cells that started with `=`, `+`, `-`, `@`, D-48), processed_rows, created_count, updated_count (registered students the run moved to another class, default 0, D-81) [M1-C], error, started_at, finished_at — queued import runs (> 50 valid rows, slices of 100, D-55); the stored spreadsheet is deleted when the job ends (done or failed), `file_sha256` stays (D-64).
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
| surat_terbit | 3 → 4 | TU issued every required letter (its own full number, form and PDF per letter, D-82/D-83), then sent them | berlangsung (scheduler on starts_on) |
| berlangsung | 4 Lomba | today ≥ starts_on | menunggu_laporan (scheduler after final_on) |
| menunggu_laporan | 5 Laporan hasil | today > final_on; deadline = final_on + `report_deadline_days` (45) | laporan_dikirim |
| laporan_dikirim | 5 | leader submits the report | selesai (verified) · menunggu_laporan (perlu revisi) |
| selesai | 6 Poin & apresiasi | Kesiswaan verified → achievements + points, Humas notified | — |
| dibatalkan | end | leader cancels before the principal decides | — |
Transitions happen only in `app/Actions/Registrations/*`, which assert the source status inside a DB transaction with `lockForUpdate()`, write the activity log, and dispatch queued notifications (mail + database). The time-driven transitions (surat_terbit → berlangsung → menunggu_laporan) are Actions too (`StartCompetition`, `AwaitReport`), called by the scheduler command `sth:registrations:advance` [B3].

## 7. Domain rules (pure PHP, unit-tested in tests/Unit)
- **Points** (`App\Domain\Points\PointCalculator`): points = w_level × w_result × w_organizer from the ACTIVE weight version at verification time; weights are snapshotted on the achievement; every team member gets full points; round half-up to 2 decimals (UI shows up to 1 decimal with comma). Defaults — level {sekolah 20, kota 40, provinsi 60, nasional 80, internasional 100}; result {juara_1 1.00, medali_emas 1.00, juara_2 0.85, medali_perak 0.85, juara_3 0.70, medali_perunggu 0.70, finalis 0.50, semifinalis 0.30, penghargaan_khusus 0.30, peserta 0.10}; organizer {A 1.00, B 0.80, C 0.60, D 0.40, E 0.20}. Changing weights creates a new version; a queued job recomputes all achievements (audited).
- **Leaderboard**: SUM(points) per student over all time (cumulative), archived students included with an `alumni` flag; ties → more higher-ranked results first, then name. The public ranking shows one group at a time (`App\Domain\Leaderboard\RankingScope`: `aktif` = default, `alumni` = archived accounts with a verified achievement, `semua` = both) and ranks within that group; an alumnus has no class on public pages (`kelas` null, `kelas_label` "Alumni"), and a class ranking exists for active students only (D-80). A student's own rank stays among active students (`PersonalRank`, D-37).
- **Report deadline** (`App\Domain\Deadlines\ReportDeadline`): final_on + N days (setting, default 45).
- **Archive date** (`App\Domain\Archive\GraduationCalculator`): from the classroom level at import + the import school year → grade-12 start = 1 July of the calendar year the student starts XII → archive_at = +18 months (setting). Example: kelas X in 2026/2027 → XII from 2028-07-01 → archive 2030-01-01. A daily scheduled job archives due students: status → archived, sessions deleted, login refused (D-29); achievements stay public. The yearly import recomputes both dates for a registered student from the class in the file and the chosen school year: a normal promotion (X in 2026/2027 → XI in 2027/2028) gives the same dates and writes nothing; a repeated year or a corrected class gives other dates, which are written with a row note, also over a date an Admin changed by hand (*sementara*, D-81); a corrected archive date that is not after today refuses the row.
- **Initial password** (`App\Domain\Accounts\InitialPassword`): `sm4g4_` + birth_date->format('dmy') (D-24). Used only at import; hashed with the app's hasher (Laravel default bcrypt, `BCRYPT_ROUNDS`); sets `must_change_password=true` and the activation window `initial_password_expires_at` (K1). An Admin reset gives a random temporary password valid 72 h instead (K10, D-45). The new password has at least 10 characters (D-32) and `PasswordPolicy` rejects the pattern, the birth date, NIS, NISN and the name. Since D-79 a student may keep the current password at the last activation step (`CompleteActivation::keepPassword()`: `must_change_password` false, hash and `password_changed_at` untouched, audit `activation.password_kept`); staff must choose a new one. Since D-92 this holds for the school password of the import only: while `users.temporary_password_issued_at` is set (an Admin reset), `CompleteActivation::mayKeepPassword()` is false and `keepPassword()` throws `AuthorizationException`, as for staff. Since D-94 `PasswordPolicy` also rejects, for every account, the username and the part of the email before the "@" (whole, and pieces of 4+ characters with a letter), and common passwords (`isTooCommon()`: a built-in word list, alphabet and keyboard runs of 4+ letters, digit runs of 5+, one character 4+ times; refused when fewer than 5 other letters remain, digits and symbols never count).
- **Letter number** (`App\Domain\Letters\LetterNumber`, D-82): TU types the complete number of each letter. `normalize()` trims and turns runs of spaces into one; `numberError()` accepts 3–64 characters of `A–Z a–z 0–9 . / -` and space with at least one digit; `key()` (lower case, no spaces) is what two numbers are compared by. A number is never used twice for the same letter type, revoked letters included: friendly validation (`IssueLetters::isTaken()`) plus the DB unique index (type, full_number). The settings formats only build the example under the field (`example()`); `format()` / `parse()` / `pattern()` stay for that, the demo data and old rows.

## 8. Auth & security essentials (details in SECURITY.md)
- One login field (D-42, D-78): students use NIS or NISN (10 digits = NISN) and, once activation is finished (`DetermineActivationStep::finished()`: status active, email verified, no forced change), the bound email; staff `users.username` or email; one generic error message; RateLimiter keyed by account bucket (NIS, NISN and a resolved email share one) + IP (strict) and by IP (loose, school NAT). An email that does not resolve (not verified, activation open, disabled, archived, unknown) is treated like an unknown identifier.
- First login (D-79; replaces "password → email → OTP"): bind email → OTP (6 digits, hashed, 10-minute TTL, 5 attempts, 60 s resend cooldown) → password. `CompleteActivation::bindEmail()` stores the verified email and leaves the account pending; `changePassword()` (must differ from the current password) or, for students only, `keepPassword()` then sets it active. Staff must change the password, and so must a student whose password is the temporary one of an Admin reset (D-92). The initial password works only inside its activation window (K1, reopen 72 h = K2; a reopen never shortens the window: the later one of the current deadline and now + 72 h, D-95) and 10 failed attempts freeze a not-activated account (K3). **OTP test mode** accepts any 6 digits ONLY when `APP_ENV=local` AND `STH_OTP_TEST_MODE=true`; `AppServiceProvider::boot()` throws if it is on elsewhere; CI asserts this.
- Staff roles require TOTP 2FA before entering role areas (`staff.2fa`; skipped only when `APP_ENV=local`, `STH_REQUIRE_STAFF_2FA`).
- Archived, disabled and frozen users are rejected at login and their sessions deleted; "Bukan saya?" email links (K8, `email_notices`) freeze the account.
- Public pages expose only name, class and achievements; names only with `publish_consent` (else "Siswa kelas X"), photos only with `photo_consent`, certificate links only with the consent of every team member **and** the Admin switch `public_certificates`; pages are `noindex` while `public_indexing` is off (D-38, D-39).
- Audit: every state change and Admin action writes `activity_log`; rows are sealed in a hash chain (`audit_seals`) and the runtime DB user cannot UPDATE/DELETE either table (SECURITY §3h, §3m-9).

### 8a. Rules added by the Q1 pass (D-66…D-77)
- **TOTP is staff only**: `RestrictTwoFactorToStaff` (web group) answers 404 for a student account on every
  `two-factor.*` management route; a student login is never sent to the challenge; `ResetStudentAccount` clears TOTP.
- **Password confirmation**: `POST /user/confirm-password` allows 5 tries per minute per account; Fortify's 2FA events
  are written to the activity log (`two_factor.*`).
- **Uploads**: PDFs go through `App\Domain\Files\PdfScanner`; a file it cannot read completely is refused.
- **Free-text names**: a lomba name must not contain a web address (`App\Rules\NoWebAddress`).
- **Lists** that can grow render in steps (`$limit` with `STEP`/`MAX`, clamped), never all rows.
- **Withdrawn publish consent**: viewers without `student.view` (Humas) get the neutral label and no flyer
  (`AchievementFacts::visibleTo()`, `FilePolicy::viaPublication()`); holders of `student.view` keep both.
  Since D-107 the flyer half only concerns flyer files made before 2026-10-06 (§8e); the name rule is unchanged.

### 8b. Notifications and mail
Notifications are queued (`mail` + `database` channels unless noted) and sent after the transaction commits. Mails are
built with `App\Notifications\Support\PlainMailMessage` (user text is never Markdown). `User::routeNotificationForMail()`
skips the mail (the bell entry is still stored) for a frozen account, and for an address nobody verified unless the
notification is an `AdminNotice`. A frozen account gets no reset code. Proven end to end by
`tests/E2E/support/email-scenario.php` (re-run with the M1 code on 2026-10-05: 122 mails, 0 missing, 0 unexpected,
0 leaks, 0 failed jobs). The M1 and M2 rows below (talent restore / review request, the deleted appreciation event,
the released appreciation), the guru notices of QA5 (D-110) and the withdrawn invitation of QA6 (D-115) are covered by
Pest only: the scenario has no step for them yet (CLAUDE.md backlog #46).

| event (Action) | recipients |
|---|---|
| OTP for activation, email change, password reset (`IssueOtpCode`) | the address being verified / the bound address (mail only) |
| Email bound or changed (`CompleteActivation::bindEmail`, `ChangeEmail`) | bound address; on a change the old and the new address, with a "Bukan saya?" link (mail only). At activation the notice goes out when the code is accepted (step 2), while the account is still pending; the password step (step 3) sends no mail (D-79) |
| Password changed / reset (`ChangePassword`, `ResetPasswordWithCode`) | the account owner |
| Lomba submitted or added (`SubmitCompetition`, `CreateCompetition`) | every holder of `competition.verify` except the actor |
| Lomba published / rejected (`VerifyCompetition`) | the submitter (not when the verifier is the submitter); a rejection carries "Alasan Kesiswaan: …" when a reason was written, else `CompetitionRejected::NO_REASON` (D-103) |
| Team invitation (`CreateRegistration`, `InviteMembers`) | the invited students |
| Invitation withdrawn by the ketua (`WithdrawInvitation`, kind `registration.invitation_withdrawn`, D-115) | the student whose unanswered invitation was withdrawn (when the account can log in); the Kepala Sekolah when the team is complete after it, as after a decline |
| Member accepted / declined (`ConfirmMembership`, `DeclineMembership`), invitations expired | the ketua |
| Registration waiting for approval (individual: at once; team: when complete) | holders of `registration.approve` (Kepala Sekolah) |
| Registration approved (`ApproveRegistration`) | all members, the guru pembimbing, every Tata Usaha |
| Registration rejected (`RejectRegistration`) | all members, the guru pembimbing |
| Registration cancelled (`CancelRegistration`) | the other members, the guru pembimbing; the Kepala Sekolah only if it had reached `menunggu_kepsek` |
| Letters sent / revised (`SendLetters`, `RevokeLetter`) | members (siswa letter), guru pembimbing (guru letter); a revocation also goes to the Kepala Sekolah |
| Report window opened, reminders 14 and 3 days before the deadline | the ketua |
| Report submitted / resubmitted (`SubmitReport`) | every Kesiswaan |
| Report verified / revision requested / rejected | all confirmed members; the guru pembimbing of the registration (`BinaanReportDecided`, "Yth. …", link to the binaan page; only an active guru account, D-110); on verification also every Humas ("ready for publication") |
| Publication published, appreciation scheduled | the student (an appreciation also the guru pembimbing, `BinaanAppreciationNotice` kind `appreciation.binaan_scheduled`, D-110); a scheduled publication that became due also every Humas (`PublicationDue`, "Waktunya upload publikasi": since D-107 it points to the caption and the data for the flyer on the publication page, it no longer promises a flyer) |
| Appreciation event deleted (`DeleteAppreciationEvent`, kind `appreciation.cancelled`) | every student with a scheduled row on it who can log in; each guru pembimbing of those rows once, listing all their binaan on the event (`appreciation.binaan_cancelled`, D-110); confirming an event notifies nobody (D-87) |
| Appreciation released (`ReleaseAppreciation`, kind `appreciation.released`, "Jadwal apresiasi berubah") | the student of that achievement when the account can log in, and the guru pembimbing (`appreciation.binaan_released`, D-110); the event itself goes on (D-97) |
| Talent stage changed / application rejected / restored (`MoveTalentStage`, `RejectTalentApplication`, `RestoreTalentApplication`) | the student, with the optional note for the student (D-85, D-86): the text typed for that decision, or the saved draft when the action has no note field (D-96); the notification of a restore names the stage the choice is in again (D-104); the guru pembina is not notified; a new application notifies nobody (backlog #40) |
| Talent review requested (`RequestTalentReview`, kind `talent.review_requested`) | every Kesiswaan |
| Account frozen (failed logins before activation / "Bukan saya?") | Admins (+ Kesiswaan for failed logins) |
| Staff account created | Admins |
| Staff account reset / role changed, role change requested / applied / cancelled, Kepala Sekolah replacement | Admins and the account concerned (+ Kepala Sekolah where the 24 h delay applies) |
| Weight version published, signature replaced, letter template changed | Kepala Sekolah and Admins (not the actor for the template) |
| Archive job report, large export | Admins |

### 8c. Rules added by M1 (D-78…D-91, lead's hand test of 2026-10-04)
- **Login and activation**: see §8 (email login for activated students; email → code → password; a student may keep
  the password: since D-92 the school password only, never an Admin's temporary one). Accepted risk and
  counterweights: SECURITY §3a (K4, K7) and D-79.
- **Student import** (`ImportStudents`, D-81): columns Nama lengkap, NIS, NISN, Tanggal lahir, **Kelas**, No HP
  (the old header "Kelas saat pendaftaran" is still read). Row actions `StudentImportReport::NEW | MOVED | UNCHANGED |
  SKIPPED | REJECTED`: both numbers unknown → new account; both numbers of the same registered student → moved or
  unchanged; one number matching, or two different students → rejected; archived → skipped; disabled → updated, stays
  disabled. A differing name or birth date is only reported. The queue decision (> 50 rows) counts the rows that write
  something.
- **Letters**: one `LetterDraft` + one `IssueLetters::handle(User, Registration, LetterType, LetterDraft)` call per
  letter; `RevokeLetter::handle(User, Letter, string $reason, LetterDraft $draftWithNewNumber)`; `SendLetters` once.
  PDFs follow the school's own files; the Pemprov and school emblems are static files in `resources/pdf/assets` (inside
  the dompdf chroot; institution emblems, not personal data; D-84). Print = `tu.surat.cetak` (inline PDF,
  `LetterPolicy::view`, `Cache-Control: no-store, private`, CSP `frame-ancestors 'none'`); `tu.surat.kop` serves the
  emblems to the HTML preview (`LetterPolicy::viewQueue`).
- **Talent**: `note` (guru pembina) and `student_note` (student) are separate and optional; "Simpan" never sends a
  student note (since D-96 it keeps the text as a draft, §8d); `RestoreTalentApplication` (ability `restore` = `talent.manage`;
  since D-104 with a target stage, §8e) and `RequestTalentReview` (ability
  `requestReview`: owner, status tidak_lolos, no open request; 10 per hour per student).
- **Appreciation events**: `CompleteAppreciationEvent` stamps the event, `DeleteAppreciationEvent` (ability `delete`)
  only while not confirmed, `RecordAppreciation` refuses a confirmed event. Humas › Jadwal and the "Masukkan" select use
  `AppreciationEvent::open()`.
- **Audit events added**: `account.email_bound`, `account.activated` (property `password_changed`),
  `activation.password_kept`, `student.class_changed`, `student.phone_updated` (log `student`; `student.import` gained
  `moved`), `letters.issued` (once per letter), `letter.downloaded` (property `inline: true` for print),
  `talent.restored`, `talent.review_requested`, `appreciation.event_deleted`. The account, student and appreciation
  events store no person names, NIS/NISN, birth dates or phone numbers (the bound email is masked);
  `talent.review_requested` stores only `has_message` (the text stays in `talent_applications.review_message`).
- **Screens**: the sidebar prints `data-sth-badge="<key>"` (`Navigation::item()['badgeKey']`) and the browser event
  `sth-badges` updates the counts without a reload; the top bar search form (`data-sth-live-search`) sets the Livewire
  property named like its input after 350 ms when its action is the current page (D-90). UI copy uses upload, download,
  link, preview (D-89).

### 8d. Rules added by M2 (D-92…D-101, fixes after the QA pass of 2026-10-05)
- **Activation after an Admin reset** (D-92): `users.temporary_password_issued_at` marks an Admin's temporary
  password. `CompleteActivation::hasTemporaryPassword()` / `mayKeepPassword()`; `keepPassword()` checks again on the
  locked row and throws `AuthorizationException` (`MSG_MUST_CHANGE`). `SetNewPassword::handle()` clears the mark
  (activation, Keamanan akun, Lupa kata sandi). Staff resets are unchanged (staff never may keep a password).
- **Credential pages and sessions** (D-93): the global middleware `PreventCredentialPageCaching` sets
  `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` and `Pragma: no-cache` on every answer
  (GET, POST, redirect, error) for the paths in `PreventCredentialPageCaching::PATHS` (login, aktivasi,
  lupa-kata-sandi, user/confirm-password, two-factor-challenge, akun/2fa); `resources/js/app.js` empties password
  fields and the account name field (`autocomplete="username"`) after a plain form submit, on `pageshow` with
  `persisted` and on a page loaded again for Back/Forward (D-102). `RememberLoggedOutAccount` (group `web`) keeps
  the id of the account that logged out in the guest session (`sth.logged_out_user`); when a **different** account
  logs in next, the intended address of that session is dropped (D-102). The session id is renewed with
  `session()->regenerate(true)` (the old row is deleted) in `Auth\Activation`, `Akun\Keamanan::changePassword` and
  `Auth\TwoFactorSetup::confirm`, always after the Action succeeded. `ListUserSessions::handle(User, ?string
  $currentSessionId, ?string $currentUserAgent = null, ?string $currentIp = null)` lists the current device from the
  request when its row is not written yet.
- **Password rules** (D-94): see §7 "Initial password". `SetNewPassword::hint(?User $user = null)` builds the line
  under a new-password field from the rules that apply to that account.
- **Reopen** (D-95): `ReopenActivationWindow::deadlineAfter()` (later of the current deadline and now + 72 h),
  `wouldChange()` (the screen offers the button only then: expired, frozen after failed logins, or less than 72 h
  left), `notNeededMessage()`; reason `MIN_REASON` = 10 characters. (`VerifyCompetition::MIN_REJECT_NOTE` = 10 was
  added here by M2 and removed again on 2026-10-06: the lomba rejection reason is optional, D-103, §8e.)
- **Talent draft note** (D-96): `UpdateTalentAssessment` stores `student_note_draft` (the key `student_note` is still
  ignored there). `TalentNote::forDecision(?string $given, bool $wasGiven, ?string $draft)`: a text the caller passed
  is sent (an empty one = no note); a caller that passed none sends the saved draft (*sementara*). Every decision
  clears the draft; only a decision writes `student_note`.
- **Appreciation** (D-97): one achievement on at most one event (§4); `ReleaseAppreciation::handle(User,
  Appreciation): bool` (ability `delete`: `appreciation.manage`, row `dijadwalkan`, event not confirmed; lock order
  event → row; true = the student was told).
- **Competition dates** (D-98): `App\Actions\Competitions\CompetitionDates`. `startsBeforeDeadline()`: `starts_on`
  before `registration_closes_at` is refused on create, on update (a published lomba only when one of the two dates
  changes) and at publish. `past()`: publishing with a registration deadline or final date in the past needs
  `VerifyCompetition::handle(User, Competition, CompetitionStatus, ?string $note = null, bool $confirmPastDates =
  false)` with `true`; otherwise it refuses under the key `CompetitionDates::PAST_KEY`.
- **Letters, student side** (D-99): the student's card lists letters with `sent_at` only; the guru letter is an
  information row without a link. `LetterPolicy` is unchanged (the guru letter and its Lampiran stay with the guru
  and staff).
- **Sidebar counts** (D-100): `NavigationServiceProvider::badges(object $user, string $role, array $except = [])` is
  the one resolver (`Navigation::resolveBadgesUsing()`), asked on every render of `layouts::app`: the badge keys of
  the rendered menu (`Navigation::badgeKeys($role)`), the same ability and query as the screen (`tu.surat`,
  `kepsek.persetujuan`, `kesiswaan.usulan`, `kesiswaan.verifikasi`, `humas.publikasi`), one `count(*)` per key,
  nothing for guests. Keys a page passes itself are skipped and win.
- **Error pages and login landing** (D-101): `resources/views/errors/*` + `errors.layout`;
  `ErrorPageServiceProvider::links(Request, int $status): array{home, back}`; the pages never print the exception
  message. `GET /logout` (`logout.get`) redirects and never logs out. `ErrorPageServiceProvider::afterLogin()`
  redirects a GET that answers 403 or 404 on the first request after a login to `home` (flash `sth.after_login`,
  set by the `Login` event). `/verifikasi-surat/{code}` accepts any one-segment code (`[^/]{1,128}`), so a mistyped
  code reaches the controller's own "not registered" page (status 404).
- **Audit added**: event `appreciation.released` (ids, event name and date, `notified`; no person names); new
  properties `previous_expires_at` (`account.activation_reopened`), `past_dates` (`competition.published`),
  `student_note_from_draft` (`talent.stage_changed`, `talent.rejected`).

### 8e. Rules added by M3 and QA4 (D-103…D-108, the lead's six requests of 2026-10-06)
No migration in M3. Details per area: `docs/dev-notes/M3.md`, `M3-A`, `M3-C`, `M3-D`, `M3-E`, `QA4`.
- **Lomba rejection** (D-103): `VerifyCompetition::handle()` keeps its signature (§8d); for `Ditolak` the note is
  optional. It is trimmed, at most 1,000 characters ("Catatan maksimal 1.000 karakter."), and an empty one is stored
  as null in `verification_note` and in the audit property `note`. `CompetitionRejected::for(Competition, ?string
  $note = null)`: body "Alasan Kesiswaan: …" or the constant `NO_REASON`. Unchanged: the reopen reason
  (`ReopenActivationWindow::MIN_REASON` = 10), the required notes of `RejectRegistration`, `RejectReport` and
  `RequestReportRevision`.
- **Talent restore** (D-104): `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null,
  ?TalentStatus $to = null)`. `$to` must be one of `TARGETS` (Terdaftar, Pemetaan, Seleksi, Pembinaan), else a
  `ValidationException` under `to` (`TARGET_MESSAGE`); null = `TalentApplication::restoreTarget()` (the stage before
  the rejection, Terdaftar when unknown), so older calls behave as before. Still only from `tidak_lolos` (checked on
  the locked row), ability `restore`, one transaction; the open review request is closed; pembina, assessment date
  and the note for the guru stay, and no stage requires a pembina or a date (as in `MoveTalentStage`; *sementara*).
  Audit `talent.restored`: `from` (tidak_lolos), `to`, `rejected_from` (new), `review_requested`, `student_note`.
- **Talenta export** (D-104): `Kesiswaan\Talenta::export(AdminRecipients, string $status = '')`: `''` = what the open
  tab lists, `Talenta::EXPORT_ALL` (`semua`), or `TalentStatus` values joined by commas; anything else → 404. The
  screen calls it through the dialog `Talenta::EXPORT_DIALOG` (`askExport` → `download`, which keeps only real
  `TalentStatus` values; none → "Pilih minimal satu status."). The password gate carries the choice across the
  password page as a literal call rebuilt from enum values (`export('terdaftar,tidak_lolos')`, see QA4 below). Audit
  `talent.exported`: `filters.tahap` = the chosen statuses joined by commas (empty = all). Title, "Diekspor oleh",
  bold header, text cells, row cap and the Admin alert are those of QA4.
- **File preview** (D-105; security reasoning in SECURITY §3d): `GET|HEAD /berkas/{file}` (`files.show`) answers
  `attachment` unless the query has `tampil=1` (`FileController::INLINE_PARAMETER`) **and** the stored MIME is in the
  inline allowlist: `application/pdf` → `pdf`, `image/jpeg`, `image/png`, `image/webp` → `image`
  (`FileController::previewType(File): ?string`; `previewUrl(File): ?string` builds the inline address, null = no
  preview). Headers: every file answer has `nosniff`, `Cache-Control: private, no-store` and CSP `default-src
  'none'; sandbox`; only the inline **PDF** answer of `files.show` has CSP `default-src 'none'; frame-ancestors
  'self'` and `X-Frame-Options: SAMEORIGIN` instead. `files.public` never sends a PDF inline. HEAD runs the same
  policy check, reads no bytes and writes no audit row; a GET preview is audited as `file.downloaded` with
  `inline: true`. Policy, 404-for-not-yours, the route middleware and `throttle:60,1` are unchanged (one PDF
  preview = 2 requests). `SecurityHeaders` writes `frame-src 'self'` into the page CSP and keeps a header a response
  set itself. One dialog per page: `<x-sth.preview />` in `layouts::app` and `layouts::mobile`.
- **Demo documents** (D-106; local only, nothing ships): `Database\Seeders\Support\Demo\*` (`DemoDocumentResolver`
  decides the kind from what the `files` row is attached to, `DemoPdfRenderer` renders with its own locked-down
  dompdf, `DemoFileRefresher::refresh(bool $force = false, ?callable $progress = null, array $names = [])` /
  `attachMissing()` / `lines()`), templates `database/seeders/demo/*.blade.php` (outside every view path of the
  app). `DemoSeeder` calls the refresher after seeding when `DemoFiles::realistic()` (APP_ENV=local, not the test
  process, not `STH_DEMO_FILES=placeholder`). Command `sth:demo:refresh-files` (`App\Console\Commands\
  RefreshDemoFiles`; `--force`, `--berkas=*`, `-v`, `--lengkapi`): rewrites only `files` rows with a PDF under
  `demo/` (bytes at the same path, `size`, `sha256`); `--lengkapi` is the only option that adds rows (one `files` +
  one `report_files` row of kind `laporan_riset` for a demo report with a research title and no such file). Seeder,
  refresher and command refuse unless `APP_ENV=local`; the renderer also refuses in production. Every page carries
  `DemoPdfRenderer::FOOTER`; only names known to `DemoPeople::isFictional()` are printed.
- **Humas** (D-107): `App\Actions\Publications\FlyerData::of(Achievement): self`: `rows` (list of `label`, `values`,
  `list`; only known facts: Nama, Kelas or "Anggota tim", Capaian, Lomba, Cabang, Tingkat, Penyelenggara, Bidang,
  Tanggal lomba, Guru pembimbing, Judul penelitian) and `notes` (consent sentences, outside the copied block);
  `RELATIONS` for eager loading. It never names a student without publish consent (a team member is left out and
  counted in a note) and never carries points, NIS/NISN, birth date, phone or email. Removed: `GenerateFlyer`,
  `FlyerRenderer`, `PublicationPolicy::generateFlyer`, `Publikasi::downloadFlyer()`. Kept without a writer:
  `publications.flyer_file_id`, `FilePurpose::Flyer`, the flyer rule of `FilePolicy` (D-75) and the audit label
  `publication.flyer_generated`. Humas › Arsip has no file link; "Lihat" opens `humas.beranda?pilih=<id>` (not for
  a row with withdrawn consent, whose panel answers 404).
- **Letters** (D-108, QA4): `letters.printed`, §4. `LetterPdf::render()` takes the `LetterTemplate` as a parameter;
  `LetterPdf::printed($content, $template)` builds what is stored.
- **QA4, screens**: `RequiresRecentPassword::passwordRecentlyConfirmed(?string $resumeDownload = null)` keeps
  `{component, call, until}` in the session (`sth.password_resume`) and `mountRequiresRecentPassword()` hands it back
  once to that screen when the password is fresh; the view runs it with `wire:init` (downloads only; data-changing
  actions still need a click). The confirm-password page has "Batal" (back to `url.intended` when it is our own URL,
  else `home`). The import template carries Text cells for NIS, NISN and No HP
  (`ImportStudents::TEMPLATE_TEXT_COLUMNS`). Toasts take an optional `key`; `sth-toast-clear {key}` removes them.
- **Audit**: no new event. New property `rejected_from` on `talent.restored`; `filters.tahap` of `talent.exported`
  may list several statuses; `inline: true` on `file.downloaded`; nothing writes `publication.flyer_generated` now.

### 8f. Rules added by QA5 (D-109…D-112, retest of M3 on 2026-10-07)
No migration. Details: `docs/dev-notes/QA5.md`.
- **Talent restore** (D-109): `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null,
  ?TalentStatus $to = null, int|string|null $mentorId = null)`. A given `$mentorId` must be an active guru (error key
  `mentor_id`, "Pilih guru pembina dari daftar."); null keeps the guru pembina already on the row. A restore into
  `pembinaan` without any guru pembina is refused under `mentor_id` (`MENTOR_MESSAGE`), and so is one whose recorded
  pembina can no longer log in or lost the guru role (`INACTIVE_MENTOR_MESSAGE`); the other targets need none and keep
  the recorded pembina as it is. The dialog preselects the recorded pembina only while
  `RestoreTalentApplication::isActiveGuru()` (the list it shows).
  The chosen guru is written with the restore and is in the audit (`mentor_id`). The assessment date stays optional.
- **Talenta export** (D-111): the file always follows the screen's filters (jenis, cabang, kelas, `q`) plus the chosen
  statuses. `Talenta::filledStatuses()` = statuses with at least one row under those filters; the dialog disables the
  others ("Kosong"), preselects only filled statuses of the open tab, and sends `semua` when every filled status is
  ticked. A choice with no rows is refused in the dialog (`EXPORT_EMPTY_CHOICE`) and by `export()` with a toast
  (`EXPORT_NOTHING`, no file, no audit row).
- **Guru notices** (D-110): `BinaanReportDecided::verified(Report, Collection $achievements)` / `revision(Report,
  string $competitionName, string $note)` / `rejected(…)` (base `Verification\Notice`, own `toMail()` greeting
  "Yth. <full name>,", route `guru.binaan.show`); `BinaanAppreciationNotice::scheduled(Appreciation)` /
  `released(AppreciationEvent, Appreciation)` / `cancelled(AppreciationEvent, iterable)` (kind constants `SCHEDULED`,
  `RELEASED`, `CANCELLED`; links to the binaan page when all rows are of one registration, else `guru.beranda`).
  Recipients: `RequestReportRevision::mentorUser(Report)`, `RecordAppreciation::mentorUser(Achievement)` /
  `mentorsFor(iterable $achievements)` (three queries for any number of rows; `DeleteAppreciationEvent` uses it inside
  its locked transaction), all `User::gurus()->canLogIn()` on the registration's `mentor_id`. Names and capaian only, never NIS/NISN.
- **Screens**: `VerifyCompetition::notifiesSubmitter(?User $submitter, User $actor)` decides the reject toast ("Lomba
  ditolak." when nobody is told: own entry, archived or frozen submitter). `FortifyServiceProvider::confirmPasswordBack()`
  skips an address that is itself behind `password.confirm` and then uses the Referer, else `home`. Admin › Pengguna
  computes the Kepala Sekolah row, the replacement candidates and the dialog target from every staff account, not from
  the filtered table. Guru › detail binaan: "Laporan perlu direvisi" while the report is `perlu_revisi`; the sent
  letters open in the preview popup (D-112). Humas › Apresiasi labels an archived student "(Alumni)" instead of the
  last class (as D-80). Humas › Publikasi: the panel of a published row is titled "Detail publikasi". `/aktivasi`
  has a plain POST "Keluar" form on every step. Kepala Sekolah › Persetujuan: `Persetujuan::modeAndPlace()` prints
  "Daring" once.
- **Retest 2 (full sweep, same day)**: one search box per screen (D-90): a page with its own search box passes
  `'search' => false` to the layout (TU Arsip surat, Kesiswaan Kelola lomba / Talenta / Database prestasi / Status
  aktivasi, Guru Siswa binaan / Lomba, Kepala Sekolah Surat, Admin Pengaturan / Pengguna / Status aktivasi / Arsip
  otomatis / Log audit; Humas Arsip already did); the top bar labels say what their target finds (Kesiswaan "Cari lomba", TU "Cari lomba atau siswa");
  `TataUsaha\SuratTugas` has `#[Url] q` (lomba or member name; the badge counts the whole queue; `updatedQ()` opens the
  first match when nothing was open). Every throttled
  route has its own counter: `throttle:N,M,<prefix>` (`publik` 60, `verifikasi-surat` 30, `berkas` 60,
  `berkas-publik` 30, `bukan-saya` 20); an unnamed `throttle:N,M` is keyed by user / IP only and would share one
  counter with every other such route. `<x-sth.modal on-close="method">` renders `data-sth-on-close`, and `app.js`
  calls that Livewire method on the dialog's `close` event (Status aktivasi: `closeClass`, so `?kelas=` goes however
  the popup is closed). `data-sth-countdown="<seconds>"` + `[data-sth-countdown-text]`: `app.js` ticks m:ss every second
  (activation "Kirim ulang dalam"). `/siswa/tur` sets the flash `TurMenu::FORCE_KEY` instead of `?tur=1`.
  `CreateStaffAccount::validator(array)` returns the form's checks without saving, so "Tambah pengguna" shows them with
  its password check at once; `Publikasi::schedule()` and `CreateAppreciationEvent` also collect every field error
  before answering. `AuditLog::EVENT_LABELS` covers every event key the app writes (a test scans `app/`). Status sistem
  "Staf aktif" = staff with status `active` (+ "N belum aktivasi").
- **Demo data** (local only): `DemoGuideline` has a kind `inovasi` (invention fairs, read from the lomba name) and
  per-kind rules, conduct, schedule labels and awards; `DemoLetterBasis::for(string $key, CarbonImmutable $issuedOn)`
  gives every demo letter the number and date of the invitation it answers (DemoSeeder and
  `DemoFileRefresher::fillLetterBasis()`, which `--lengkapi` runs; without it `missing()` feeds the hint
  "Jalankan dengan --lengkapi untuk melengkapi: …").

### 8g. Rules added by QA6 (D-113…D-116, explore round after PR 14 on 2026-10-07)
No migration. Details: `docs/dev-notes/QA6.md`.
- **Letter verification** (D-113, *sementara*): `/verifikasi-surat/{code}` knows every issued letter, sent or not:
  "Sah" while current, "Dicabut" once revoked, with the replacement's number as soon as it is issued.
- **Assessment date** (D-114): `UpdateTalentAssessment::validated(array $details, ?TalentApplication $current = null)`
  refuses a date before today (Asia/Jakarta) unless it equals the date already saved on `$current`
  ("Jadwal asesmen tidak boleh sebelum hari ini."). `MoveTalentStage`, `RejectTalentApplication` and the "Tidak lolos"
  pre-check of `Kesiswaan\Talenta` pass the row.
- **Withdrawn invitation** (D-115): `WithdrawInvitation::handle(User $actor, Registration, string $studentId)`,
  authorized with `RegistrationPolicy::invite`; under `lockForUpdate()` only an anggota row without `confirmed_at` and
  `declined_at` is deleted (errors under `members`: "Undangan tidak ditemukan.", "… sudah konfirmasi, undangannya tidak
  bisa dibatalkan.", "… sudah menolak undangan ini."); then `ConfirmMembership::advanceIfTeamReady()` (D-36). Audit
  `registration.invitation_withdrawn` (`student_id`). Member search results carry `pending` (account not activated).
- **Letters form** (D-116): `TataUsaha\SuratTugas::SHARED_FIELDS` (dasarDari, dasarNomor, dasarTanggal, hari, tanggal,
  pukul, tempat) go from one letter to a letter that is not issued yet when TU typed them in the source form or the
  source letter is issued; never into a key TU typed for the target itself (`#[Locked] $typed`, keys per letter for
  this visit, reset when another registration opens), never while correcting. `?koreksi=<letter id>` (from Arsip surat) opens
  that current letter's correction form in `mount()`; the parameter is emptied when the correction ends.
- **Confirmations** (Q1-10): Kepala Sekolah › Persetujuan "Setujui terpilih" opens `Persetujuan::BULK_DIALOG` listing
  the ticked rows; TU "Kirim" opens `SuratTugas::SEND_DIALOG` once every letter exists (a missing letter is still named
  at once). Both actions close their dialog (`sth-modal-close`). The Persetujuan checkboxes name the student
  ("Pilih <name>[ dkk.], <lomba>").
- **Screens**: `Siswa\Pendaftaran\Form::CHANGED` (`pendaftaran-berubah`) after send, cancel, invite and withdraw;
  `Siswa\Lomba\Detail` listens and counts the registrants again. Kesiswaan › Talenta: the student's own texts in the
  detail panel wrap inside the card (`grid-cols-[110px_minmax(0,1fr)]`, `[overflow-wrap:anywhere]`).

## 9. Environments
- Dev: `docker compose up` → app (php-fpm 8.4 + nginx on 127.0.0.1:8080), mysql 8.4 (127.0.0.1:3307), mailpit (127.0.0.1:8025), vite, scheduler, queue worker. Because the repo is bind-mounted from a Windows drive, the dev php-fpm workers read a cached config and keep compiled views in the container's own filesystem (`docker/php/fpm.dev.conf`, `docker/php/dev-cache.sh`; pool env only, never the command line), so after editing PHP, Blade, `config/` or `.env` run `reload.bat`; the test suite refuses to run on a cached config (D-91).
- Prod: image from `docker/Dockerfile.prod` (multi-stage: composer → node build → php-fpm runtime, non-root), behind the host Apache 2.4.29 vhost `prestasi.sman3-smg.sch.id` → 127.0.0.1:8080. See SECURITY.md §3e/§3j.
