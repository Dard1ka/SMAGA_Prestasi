# SMAGA Talent Hub (STH) — Architecture contract v1

Status: binding contract for all developers/agents. Change it only through a PR that also updates this file.
Decisions referenced as D-xx live in `docs/DECISIONS.md`; security controls (K1–K12, §3x) in `docs/SECURITY.md`.

## 1. Stack
- PHP 8.4, **Laravel 13**, MySQL 8.4 LTS, all in Docker (dev: `compose.yaml`; prod: `docker/compose.prod.yaml`).
- UI: Blade + **Livewire 4 (4.4)** + Alpine + Tailwind CSS v4 (Vite, built in a Node container at image build time).
- Auth: Laravel **Fortify** (headless) with custom login by NISN (a student whose activation is finished may also use the bound email, D-78; the NIS is stored only, D-137); TOTP 2FA mandatory for staff roles (U-03).
- Packages: `spatie/laravel-permission` (roles), `spatie/laravel-activitylog` (audit), `spatie/simple-excel` (import/export via OpenSpout — never evaluates formulas), `barryvdh/laravel-dompdf` (dompdf ≥ 3.1.6; remote/JS disabled, chroot), `intervention/image` v4 (re-encodes uploaded images, EXIF strip; it no longer draws a flyer, D-107), `pestphp/pest` (tests).
- Queue/cache/session: `database` drivers (no Redis needed on the 8 GB host). Scheduler via `php artisan schedule:work` container.
- Storage: `local` private disk only (`storage/app/private`). Nothing user-uploaded is ever web-served directly; files are streamed by controllers after a Policy check (as a download; inline only for the preview popup, §8e).
- Locale `id`, timezone `Asia/Jakarta`, decimal comma in UI (`Number::format(..., locale: 'id')`).

## 2. Roles (spatie, guard `web`)
`siswa`, `guru` (guru pembimbing/pembina), `kesiswaan`, `kepala_sekolah`, `tata_usaha`, `humas`, `admin`.
Admin can do everything and manage all accounts incl. replacing the Kepala Sekolah account (D-01) — every Admin action is audited.
Permissions are named `<area>.<action>` (e.g. `competition.verify`, `registration.approve`, `letter.generate`, `student.import`). Policies are the source of truth; UI hides what policies deny.
`report.verify` (Verifikasi hasil: verify and give points, ask for a revision): Kesiswaan, Humas (D-139, 2026-10-12) and Admin. Privileged roles (`App\Actions\Roles\PrivilegedRoles`: a grant needs a second Admin, or applies 24 h later when there is only one Admin; Kepala Sekolah only through the replacement, D-40): Admin, Kepala Sekolah, Tata Usaha, Kesiswaan, Humas (since D-139). TOTP 2FA is required for every staff role (`staff.2fa`, §8).
Student import: Kesiswaan + Admin (D-26). Competition create (`competition.create`): **siswa and Admin only** since D-145 (2026-10-12; before: any logged-in role); a student proposes through Siswa › Usulkan lomba, Admin through "Tambah lomba"; Kesiswaan checks and corrects (`competition.update`, `competition.verify`); publication only after Kesiswaan/Admin verification (D-27).
`registration.approve` (Persetujuan): **Kepala Sekolah and Tata Usaha** since D-150 (2026-10-12; before: Kepala Sekolah only); Admin never (D-01). Each opens `Kepsek\Persetujuan` under its own address (`kepsek.persetujuan.index`, `tu.persetujuan.index`; `App\Support\ApprovalQueue`).

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
> W2 tables/columns) and backlog B1–B5; settings keys and staff-account rules updated after W4 (D1); columns of the four M1 migrations (2026-10-04) and of the two M2 migrations (2026-10-06) added; `letters.printed` came with QA4 (PR 12, 2026-10-06); M3 (2026-10-06) has no migration; the three columns of EM2 (2026-10-10: `competitions.organizer_type_other`, `publications.reminded_at`, `appreciation_events.held_time`; nullable, no backfill) added; FS1 (2026-10-12) adds `talent_applications.other_text` and `registrations.answer_until` (both nullable, no backfill), makes `talent_types.bidang` nullable and replaces the unique index of `talent_applications` by a plain one (migrations `2026_10_12_100000_fs1_add_other_text_to_talent_applications`, `2026_10_12_100100_fs1_add_answer_until_to_registrations`; tag **[FS1]**). Origin tags: **[W1-A]**, **[W2-…]**, **[B1]…[B5]**, **[W4]**, **[M1-A]…[M1-E]**, **[M2-A]**, **[M2-B]**, **[EM2]** (details in `docs/dev-notes/`).
> Runtime DB user `sth_app` has per-table privileges (`scripts/deploy/grants.sh`, DEPLOY §8.2): `activity_log` and
> `audit_seals` are INSERT/SELECT only, `migrations` SELECT only, every other table DML; no DDL, no TRUNCATE.
- **users***: id (bigint), name, username (nullable, unique; staff login, D-42) [W1-A], email (nullable, unique) [W1-A], email_verified_at, password, status (`UserStatus`), must_change_password (bool), initial_password_expires_at (K1 window / K10 temporary password, 72 h) [B1], temporary_password_issued_at (timestamp, nullable: set by `ResetStudentAccount`, cleared by `SetNewPassword` whenever the owner chooses a password; while it is set the stored password is an Admin's temporary one and cannot be kept at activation, D-92; the migration backfills it from `activity_log` `account.reset` for reset accounts that still have `must_change_password`) [M2-A], failed_activation_attempts (K3) [B1], frozen_at, frozen_reason (`failed_attempts` | `not_me`; login refused while set) [B1], activated_at (K9) [B1], password_changed_at (null on an active student = the school password was kept at activation, D-79) [M1-B: meaning only, no new column], last_login_at, tour_seen_at (menu tour done) [W2-A1], Fortify two_factor_* columns, remember_token.
- **classrooms**: id, name (e.g. `X-1`, `X-TAHFIDZ`, `X-OLIMP`, `XI-12`, `XII-3`), level (10/11/12), active.
- **students***: id, user_id (unique), nis (unique), nisn (unique), birth_date (date), classroom_id, import_school_year (e.g. `2026/2027`), grade12_start_date (date, computed), archive_at (date, computed, indexed), phone (nullable, `encrypted` cast), photo_consent (bool, default false), publish_consent (bool, default false), public_slug (string 32, nullable, unique; random URL key of the public student page, rotated when publish consent is revoked, `UpdateConsent`) [W2-D, W3-A]. The yearly import (`ImportStudents`, D-81) updates a registered student: classroom_id, import_school_year, grade12_start_date, archive_at, and phone when the file has one; it never writes name, birth_date, the password, email, status, activation or consent columns [M1-C].
- **staff_profiles**: id, user_id, honorific (Bu/Pak/…, nullable) [W1-A], nip (nullable), rank (pangkat/gol, nullable), position (jabatan).
- **competitions*** (lomba): id, name, description (nullable) [W1-A], bidang (`Bidang`), organizer_name, organizer_type (`OrganizerType`), organizer_type_other (string 60, nullable: the kind typed for "Jenis penyelenggara: Lainnya"; `CreateCompetition::normalize()` keeps it only while organizer_type = lainnya and writes null for every other type or no type; it may stay empty in the queue, `VerifyCompetition::missingFields()` requires it to publish; shown through `Competition::organizerTypeLabel()` ("Lainnya (Sanggar seni)"); never a weight field; D-125) [EM2], weight_category (`WeightCategory`), event_status (`EventStatus`), level (`Level`), mode (`EventMode`), registration_opens_at, registration_closes_at, starts_on, final_on (the end date of the lomba, "Tanggal selesai" / the one "Tanggal lomba" of a one-day lomba; laporan hasil opens after it; since D-151 no report deadline is computed from it, only the reminders on day 3 and 14) [FS1], location, participation (`Participation`), max_team_size (nullable), default_mentor_id (users.id, guru), website_url, registration_url (D-28), juknis_file_id, cover_file_id, status (`CompetitionStatus`: menunggu_verifikasi, dipublikasikan, ditolak, ditutup), submitted_by, verified_by, verified_at, verification_note (nullable; `VerifyCompetition` accepts at most 1,000 characters, and since D-103 a rejection may leave it empty). Weight and date fields are nullable while `menunggu_verifikasi` (usulan) [W1-A]; `VerifyCompetition` requires them before `dipublikasikan`. **Every create starts at `menunggu_verifikasi` (D-27)**; only Kesiswaan/Admin can move it to `dipublikasikan`. Since FS1 [FS1]: only siswa (`SubmitCompetition`, the whole lomba) and Admin (`CreateCompetition`) create one (D-145); `weight_category` and `event_status` are **not fillable** and are set with `forceFill()` (`CreateCompetition::WEIGHT_FIELDS`; a student's lomba takes them from `OrganizerWeight`, D-146); `juknis_file_id` is required to create, to save again and to publish (D-147; rows from before stay readable); a one-day lomba stores `starts_on` = `final_on` (D-147).
- **registrations*** (pendaftaran): id, competition_id, leader_student_id, type (individu/tim), branch (cabang, nullable) [W1-A], mentor_id (guru, required D-35), status (`RegistrationStatus`, §6), answer_until (timestamp, nullable, not fillable: the end of the three-day answer window of a team resumed from `menunggu_lomba` after, or less than three days before, `registration_closes_at`; written only by `ResumeWaitingRegistrations`; read only through `Registration::answersCloseAt()` = the later of it and the deadline; D-148) [FS1], principal_decision_by (the Kepala Sekolah or, since D-150, Tata Usaha account that decided), principal_decided_at, principal_note, cancelled_at.
- **registration_members**: id, registration_id, student_id, role (ketua/anggota), invited_at, confirmed_at, declined_at. Unique (registration_id, student_id). A student cannot be in two non-final registrations of the same competition. While `menunggu_konfirmasi` and registration is open, the leader may invite more members or replacements (`InviteMembers`; never someone who declined this team; ≤ max_team_size) [B3]. Open invitations expire at `registration_closes_at` by setting `declined_at`; a team left with < 2 confirmed members stays `menunggu_konfirmasi` until the leader cancels (D-36, D-50) [B3]. Since D-148 the moment is `Registration::answersCloseAt()` (a resumed team may answer until `answer_until`), and the anggota rows of a `menunggu_lomba` team carry `invited_at` = null until the lomba is published; screens and policies treat such a row as "not invited yet" (the team is hidden from that student) [FS1].
- **registration_reminders** [W2-A2]: id (bigint), registration_id, kind (reminder type, e.g. report due), sent_at. Unique (registration_id, kind): each reminder is sent once. Since D-151 the kinds are `report_after_3` and `report_after_14` (no migration; rows of the old kinds stay) [FS1].
- **letters** (surat tugas): id, registration_id, type (`LetterType`: siswa, guru), version (default 1) [B3], replaces_letter_id (nullable, previous version) [B3], sequence (int, **nullable** since M1-A: only old rows and demo data have one), full_number (the complete number typed by TU, 3–64 characters, D-82) [M1-A], year, month (both taken from issued_on), issued_on, basis_from (200, "Surat dari") [M1-A], basis_number, basis_date (both optional), basis_subject (150, "Hal", guru letter) [M1-A], schedule_day, schedule_date (150; "Hari" and "Tanggal" are two typed fields, old rows keep "Hari, tanggal" in schedule_day and are split on read) [M1-A], schedule_time, place (nullable, printed schedule) [B3], printed (json, nullable: `{content, template}` = the `LetterContent` and letter template the PDF was made from, written once by `IssueLetters` / `RevokeLetter`; the TU preview of an issued letter is drawn from it, never again from today's data; not in the model's activity log, it holds names and NIS; null on letters made before 2026-10-06) [QA4], file_id (PDF), signature_file_id (signature image used, nullable) [B3], verification_code (random, unique; printed as QR/URL `/verifikasi-surat/{code}`), generated_by, generated_at, sent_at, revoked_at, revoked_by, revoke_reason (correction = one step `RevokeLetter`: revoke + new version with a new number, old number never reused, allowed at any stage, Kepala Sekolah notified; the reason stays in the audit log and TU history, not in mails; verification page shows "Dicabut", D-49) [B3]. **Unique (type, full_number)** (replaces unique (type, year, sequence), D-82) [M1-A] and unique (registration_id, type, version) [W1-A, B3]. One siswa letter per registration lists all members; the current letter is the one with `revoked_at` null. Each letter is issued on its own (`IssueLetters` takes one `LetterType`); `SendLetters` refuses until every required type has a PDF (siswa, + guru when `mentor_id` is set; `LetterPolicy::requiredTypes()`, D-83) [M1-A].
- **reports*** (laporan hasil): id, registration_id (unique), claimed_result (`ResultType`), submitted_by, submitted_at, late (bool: submitted after `final_on` + `report_deadline_days`, still accepted [W2-A2]; it belongs to the first report: the answer to a revision request keeps it, so a report sent on time is not late because the revision came after the deadline [QA8]; since D-151 `SubmitReport` always writes false and no screen reads it: the column only keeps old values [FS1]), status (`ReportStatus`: dikirim, perlu_revisi, diverifikasi, ditolak), final_result (nullable), verified_by, verified_at, note, research_title, research_abstract.
- **report_files**: id, report_id, kind (sertifikat, foto, pengumuman, laporan_riset, artikel, poster), file_id.
- **achievements** (prestasi, one row per member): id, report_id, student_id, competition_id, result, level, weight_category, w_level (decimal 6,2), w_result (decimal 4,2), w_organizer (decimal 4,2), points (decimal 8,2), weight_version_id, verified_at. Unique (report_id, student_id).
- **weight_versions**: id, effective_at, created_by, note. **weight_values**: id, weight_version_id, group (`WeightGroup`: level, result, organizer), key, value (decimal 6,2).
- **settings**: key (string PK), value (json). Keys = the 12 cases of `App\Enums\SettingKey` (defaults in `SettingKey::defaultValue()`, seeded by `SettingSeeder`): `report_deadline_days` (45, D-25; since D-151 not on any screen and not used for display, the key and its `ReportDeadline` binding stay), `archive_months_after_grade12` (18), `signature_file_id` (null), `signer_name`, `signer_rank`, `signer_nip` (placeholders `[Nama Kepala Sekolah]`, `[Pangkat/Golongan]`, `[NIP]`), `letter_format_siswa` (`400.3.8/{seq}/MRD/{roman_month}/{year}`), `letter_format_guru` (`400.3.8.6/{seq}/{year}`) (since D-82 both are only the example shown under the number field; they no longer validate anything) [M1-A], `public_indexing`, `public_certificates` (both false, D-38/D-39) [W3-A], `activation_window_days` (14, Admin edits 1–30, D-54; `SettingKey::ActivationWindowDays`) [B1, W4], `letter_template` (null = built-in texts; JSON of the fixed letter texts, `App\Actions\Templates\LetterTemplate`, D-60; `SettingKey::LetterTemplate`) [B5, W4]. The initial-password pattern lives in code, never in a setting. OTP test mode is not a setting either: only the env flag `STH_OTP_TEST_MODE` with `APP_ENV=local` (§8, D-62); `SettingKey::OtpTestMode` was removed and the W4 migration `2026_10_01_600000_w4_remove_otp_test_mode_setting` deletes the old `otp_test_mode` row.
- **talent_types**: id, code (osn, riset, fls3n, o2sn, olpain, debat, **lainnya** since D-143), name, bidang (nullable since D-143 [FS1]: OSN/OLPAIN→sains, Riset→riset, FLS3N/Debat→seni_budaya, O2SN→olahraga; D-22; `lainnya` → null, never a fifth bidang, D-11), sort_order [W1-A]. **talent_branches**: id, talent_type_id, name, team_size (nullable), active, sort_order [W1-A]; unique (talent_type_id, name); every type with branches ends with the branch "Lainnya" (`TalentBranch::OTHER`, D-143). Seeded from `docs/TALENT-FORM.md` (`TalentSeeder`: 7 types, idempotent).
- **talent_applications***: **Since D-142/D-143 (2026-10-12) [FS1]:** id, student_id, talent_type_id, talent_branch_id (nullable for OLPAIN and the type "Lainnya"), other_text (string 60, nullable, not fillable: the student's "Sebutkan" text for the type or a branch "Lainnya", 3–60 characters, tidied by `TalentOtherText::clean()`; excluded from the model's activity log; formula-escaped in the export), evidence_file_id (nullable), status (always `terdaftar` for new and restored rows; old rows keep their value and no screen reads it), mentor_id (the optional guru pembina), note (the note for the guru pembina); index (student_id, talent_type_id) instead of the old unique index: `ApplyTalent` refuses a duplicate (type + branch + normalised `other_text`, `TalentOtherText::key()`) under a lock on the student row, a deleted match is restored with its guru pembina and note. The columns below stay for old rows and nothing writes them any more except `ApplyTalent`, which empties them on a restore. *Before D-142:* status (`TalentStatus`: terdaftar, pemetaan, seleksi, pembinaan, tidak_lolos), mentor_id, assessment_on, note (the note for the guru pembina: internal, read by the guru pembina and Kesiswaan only), student_note (text, nullable: the note for the student of the latest decision, sent in that notification and shown on Profil talenta; a new decision replaces or clears it, D-85) [M1-D], student_note_draft (text, nullable: what Kesiswaan typed for the student and kept with "Simpan"; staff only, never shown to the student, the guru, the export or a notification; a decision sends its note and clears the draft, D-96) [M2-B], rejected_from (`TalentStatus` value, nullable: the stage before `tidak_lolos`; since D-104 it is the stage "Pulihkan" preselects (Terdaftar when unknown) while Kesiswaan chooses the target, and a restore clears it) [M1-D, M3-A], review_requested_at, review_message (≤ 500; one open review request per rejection, closed by a restore, D-86) [M1-D]. Unique (student_id, talent_type_id, talent_branch_id); OLPAIN (branch NULL) duplicates are blocked in the Action; a rejected choice cannot be applied for again (`ApplyTalent::REJECTED_MESSAGE`).
- **talent_profiles**: id, student_id (unique), interests (json, ≤ 4), skills, organizations, junior_high_achievements. **talent_profile_files** [W2-A2]: id, talent_profile_id, file_id; unique (talent_profile_id, file_id) (supporting documents of the profile).
- **publications**: id, achievement_id (unique) [W1-A], status (siap, terjadwal, dipublikasikan), channels (json), scheduled_at, reminded_at (timestamp, nullable: when the scheduler sent Humas the reminder for the current `scheduled_at`; null = not yet; not in `$fillable`; set only by `RemindDuePublication`, emptied by every schedule and re-schedule in `SchedulePublication`) [EM2], caption, flyer_file_id (private disk; since D-107 nothing writes it and it is not in `$fillable`: it only points at flyer files STH made before 2026-10-06, kept with `FilePurpose::Flyer` and their `FilePolicy` rule, no migration) [M3-E], published_at, created_by. **The schedule is only a reminder for Humas** (D-122, which replaces the auto-publish half of D-47) [EM2]: at `scheduled_at` the job `RemindDuePublications` (every 5 minutes) calls `RemindDuePublication` per due row, which mails and bells every active Humas account once per schedule (`PublicationDue`), stamps `reminded_at` and audits `publication.reminded` (`by` = scheduler). The row stays `terjadwal` and `published_at` stays empty; "lewat jadwal" is derived, not a status (`Publication::isOverdue()` / `scopeOverdue()`: `terjadwal` and `scheduled_at` ≤ now, or no time at all). Only `PublishPublication` ("Tandai dipublikasikan") sets `dipublikasikan` (`published_at` = the moment of the click) and notifies the student. Rows the old job (`AutoPublishPublications`, B3) published before 2026-10-10 stay as they are. STH draws no flyer: Humas reads "Data untuk flyer" (`FlyerData`, §8e) and designs it in its own tools (D-107).
- **appreciation_events**: id, name, held_on, held_time (TIME, nullable: the start time, wall-clock time of the school; set by `CreateAppreciationEvent`, which requires it for a new event ("Isi jam acara."); null = an event made before 2026-10-10, which prints its date only; the rules read `held_on` only, every text comes from `App\Actions\Publications\EventWhen`; D-123) [EM2], completed_at (timestamp, nullable, indexed), completed_by (users.id, null on delete) [M1-E]. An event is confirmed as a whole (`CompleteAppreciationEvent`: not before held_on, not without a scheduled row, not twice); a confirmed event takes no more rows and cannot be deleted; an unconfirmed one can be deleted (`DeleteAppreciationEvent`: its scheduled rows go, the achievements are free for another event; D-87). Scopes `open()` / `completed()`. **appreciations**: id, achievement_id, appreciation_event_id, status (dijadwalkan, selesai); unique (achievement_id, appreciation_event_id). An achievement is on at most **one** event: `RecordAppreciations` (one "Masukkan" = up to 30 achievements, all or nothing; `RecordAppreciation` is its one-row entry with the same rules and messages, D-124) [EM2] locks the event, then the achievement rows in id order, and refuses an achievement while it has any other row (still scheduled on an unconfirmed event, or already appreciated: a `selesai` row or a confirmed event); this is a rule of the Action, not a DB index. `ReleaseAppreciation` ("Lepas dari acara") deletes one `dijadwalkan` row of an unconfirmed event, which frees the achievement for another event (D-97) [M2-B].
- **files**: id, disk, path, original_name, mime, size, sha256, purpose (`FilePurpose`), owner_user_id. All uploads go through `App\Domain\Files\StoreUpload`: extension + sniffed-MIME allowlist (pdf, jpg, jpeg, png, webp), max 10 MB, images re-encoded with EXIF stripped, random path under `storage/app/private`; `original_name` ends in the extension of the sniffed content (`UploadRules::nameForContent()`: a PNG uploaded as "brosur.pdf" is stored as "brosur.png") [QA8]; per user max 30 uploads/hour and 200 MB per student (`UploadLimits`) [B2].
- **otp_codes**: id, user_id, purpose (verify_email, reset_password), email, code_hash, expires_at, attempts, used_at. Rows older than 24 h are deleted by a scheduled job [B2].
- **principal_replacements** [W2-D]: id, from_user_id (nullable when there is no Kepala Sekolah yet), to_user_id, requested_by, reason, effective_at (+24 h, D-40), applied_at, cancelled_at, cancelled_by, cancel_reason.
- **role_change_requests** [B5]: id, user_id, from_roles (json), to_role, requested_by, reason, effective_at (nullable; +24 h when there is only one Admin), approved_at, approved_by (a second Admin), applied_at, cancelled_at, cancelled_by, cancel_reason. Granting Admin, Kesiswaan or Tata Usaha goes through this table via `RequestRoleChange` (D-46; Kepala Sekolah only through `principal_replacements`, `PrivilegedRoles`); downgrades apply at once. Due delayed requests are applied by the scheduled `sth:peran-tertunda` (every 5 min) [W4]. A **new staff account** with role admin, tata_usaha or kesiswaan (`CreateStaffAccount`) is created **without a role** plus a pending request in the same transaction (`RequestRoleChange::open()`, reason `CreateStaffAccount::NEW_ACCOUNT_REASON`; if the request is refused, e.g. by the 3-Admin limit, the account is rolled back); guru and humas get the role at once; kepala_sekolah only through `principal_replacements` (D-61) [W4]. Audit event `account.staff_created` properties: `role` (role given at once; null for a privileged role), `requested_role` (the privileged role requested; null otherwise), `username`. *(2026-10-12, D-139: Humas is privileged too, so granting it goes through this table and a new Humas account starts without a role; only guru gets its role at once. Accounts that hold Humas already keep it.)*
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
- `TalentTypeCode`: osn, riset, fls3n, o2sn, olpain, debat, lainnya (D-143; `bidang()` is null for lainnya, `requiresBranch()` false for olpain and lainnya). `TalentStatus` keeps its five values and `label()` only (old rows, D-142).

## 6. Registration state machine (`RegistrationStatus`) — maps to the 6-phase tracker in the UI
| status | tracker phase | entered when | next |
|---|---|---|---|
| menunggu_lomba (D-148) | 1 Daftar | the submitter registers (individu or tim) for the own lomba while it is still `menunggu_verifikasi` (same date window as a published lomba); label "Menunggu verifikasi lomba"; nobody is told, anggota not invited yet (`invited_at` null), no approver, no guru | menunggu_konfirmasi (tim, lomba published) · menunggu_kepsek (individu, lomba published) · dibatalkan (lomba rejected, or the leader cancels) |
| menunggu_konfirmasi | 1 Daftar | team created on a published lomba, or a waiting team whose lomba was published (anggota invited then; answers until `Registration::answersCloseAt()`, D-148), not all members confirmed | menunggu_kepsek · dibatalkan |
| menunggu_kepsek | 2 Persetujuan | individu submitted, or all members confirmed, or an individu registration whose lomba was published (email to every approver: Kepala Sekolah and Tata Usaha, D-150); label "Menunggu persetujuan" since D-150 (the value stays) | disetujui · ditolak · dibatalkan |
| disetujui | 3 Surat tugas | Kepala Sekolah or Tata Usaha (D-150; the first decision wins) approves, with the student's guru pembimbing or another one (D-132, §8i; email to students, the guru of the registration and TU, never the approving TU account itself; the guru who was replaced is told nothing, D-134) | surat_terbit |
| ditolak | 2 (end) | Kepala Sekolah or Tata Usaha rejects with required note (email to students and the guru; the sentence names the deciding role, D-150) | — |
| surat_terbit | 3 → 4 | TU issued every required letter (its own full number, form and PDF per letter, D-82/D-83), then sent them | berlangsung (scheduler on starts_on) |
| berlangsung | 4 Lomba | today ≥ starts_on | menunggu_laporan (scheduler after final_on) |
| menunggu_laporan | 5 Laporan hasil | today > final_on; no deadline since D-151 (before: final_on + `report_deadline_days`); the ketua is reminded on day 3 and day 14 after final_on while no report exists | laporan_dikirim |
| laporan_dikirim | 5 | leader submits the report (mail to Kesiswaan and Humas, D-139) | selesai (verified) · menunggu_laporan (perlu revisi) |
| selesai | 6 Poin & apresiasi | Kesiswaan or Humas verified (D-139; the first decision wins) → achievements + points, Humas notified. A report rejected before D-140 also ended here, without points | — |
| dibatalkan | end | leader cancels before the approver decides (from `menunggu_lomba` too, D-148: nobody is told then), or Kesiswaan rejects the lomba a `menunggu_lomba` registration waited for (`ResumeWaitingRegistrations`; the submitter's one rejection notice says so) | — |
Transitions happen only in `app/Actions/Registrations/*`, which assert the source status inside a DB transaction with `lockForUpdate()`, write the activity log, and dispatch queued notifications (mail + database). The time-driven transitions (surat_terbit → berlangsung → menunggu_laporan) are Actions too (`StartCompetition`, `AwaitReport`), called by the scheduler command `sth:registrations:advance` [B3]. The transitions out of `menunggu_lomba` run inside `VerifyCompetition`'s transaction (`ResumeWaitingRegistrations::handle()`, which refuses to run outside a transaction; notices after the commit through `notify()`) [FS1].

## 7. Domain rules (pure PHP, unit-tested in tests/Unit)
- **Points** (`App\Domain\Points\PointCalculator`): points = w_level × w_result × w_organizer from the ACTIVE weight version at verification time; weights are snapshotted on the achievement; every team member gets full points; round half-up to 2 decimals (UI shows up to 1 decimal with comma). Defaults — level {sekolah 20, kota 40, provinsi 60, nasional 80, internasional 100}; result {juara_1 1.00, medali_emas 1.00, juara_2 0.85, medali_perak 0.85, juara_3 0.70, medali_perunggu 0.70, finalis 0.50, semifinalis 0.30, penghargaan_khusus 0.30, peserta 0.10}; organizer {A 1.00, B 0.80, C 0.60, D 0.40, E 0.20}. Changing weights creates a new version; a queued job recomputes all achievements (audited).
- **Leaderboard**: SUM(points) per student over all time (cumulative), archived students included with an `alumni` flag; ties → more higher-ranked results first, then name. The public ranking shows one group at a time (`App\Domain\Leaderboard\RankingScope`: `aktif` = default, `alumni` = archived accounts with a verified achievement, `semua` = both) and ranks within that group; an alumnus has no class on public pages (`kelas` null, `kelas_label` "Alumni"), and a class ranking exists for active students only (D-80). A student's own rank stays among active students (`PersonalRank`, D-37).
- **Report follow-up** (`App\Domain\Deadlines\ReportFollowUp`, D-151): no deadline; `REMINDER_DAYS` = [3, 14] after final_on (`sth:reports:remind`), `NOT_REPORTED_DAYS` = 14 (school dashboard "Belum lapor > 14 hari"), `PROMPT` "Segera laporkan hasil lombamu.", `daysSinceFinal()` (calendar days, day-level). *Before D-151:* `App\Domain\Deadlines\ReportDeadline`: final_on + N days (setting, default 45); the class and its binding stay, unused for display.
- **Organizer weight** (`App\Domain\Competitions\OrganizerWeight`, D-146): `for(OrganizerType, bool $puspresnas): OrganizerWeightResult` (Puspresnas → A · puspresnas; pemerintah_pusat, pemerintah_daerah, bumn_bumd → B · pemerintah_non_puspresnas; ptn, pts, organisasi_profesi, federasi_olahraga → C · non_pemerintah; yayasan_lembaga, perusahaan_swasta → D · non_pemerintah; lainnya → E · non_pemerintah); `isOverride(?OrganizerType, ?WeightCategory, ?EventStatus)` reads the tick from the status (puspresnas = ticked), false without a type. Pure; the weight of a category still comes from the active weight version.
- **Archive date** (`App\Domain\Archive\GraduationCalculator`): from the classroom level at import + the import school year → grade-12 start = 1 July of the calendar year the student starts XII → archive_at = +18 months (setting). Example: kelas X in 2026/2027 → XII from 2028-07-01 → archive 2030-01-01. A daily scheduled job archives due students: status → archived, sessions deleted, login refused (D-29); achievements stay public. The yearly import recomputes both dates for a registered student from the class in the file and the chosen school year: a normal promotion (X in 2026/2027 → XI in 2027/2028) gives the same dates and writes nothing; a repeated year or a corrected class gives other dates, which are written with a row note, also over a date an Admin changed by hand (*sementara*, D-81); a corrected archive date that is not after today refuses the row.
- **Initial password** (`App\Domain\Accounts\InitialPassword`; D-138, 2026-10-12): `make()` returns the config value `sth.initial_student_password` (env `STH_INITIAL_STUDENT_PASSWORD`, default in `config/sth.php`), the same for every student and independent of the birth date; `matches()` (K7 detection only) knows that value and the old pattern `sm4g4_` + birth_date->format('dmy') (D-24), which survives only in accounts imported before 2026-10-12. Used only at import (and the local demo seed); hashed with the app's hasher (Laravel default bcrypt, `BCRYPT_ROUNDS`); sets `must_change_password=true` and the activation window `initial_password_expires_at` (K1). An Admin reset gives a random temporary password valid 72 h instead (K10, D-45). The new password has at least 10 characters (D-32) and `PasswordPolicy` rejects the pattern, the birth date, NIS, NISN, the name and (D-138, equality) the configured initial password. *Historic (D-79, D-92; removed by D-138):* a student could keep the current password at the last activation step (`CompleteActivation::keepPassword()`, audit `activation.password_kept`) unless `users.temporary_password_issued_at` was set; `keepPassword()` and `mayKeepPassword()` are deleted, and every account must choose a new password at step 3 (the column still marks an Admin's temporary password and picks the wording of step 3). Since D-94 `PasswordPolicy` also rejects, for every account, the username and the part of the email before the "@" (whole, and pieces of 4+ characters with a letter), and common passwords (`isTooCommon()`: a built-in word list, alphabet and keyboard runs of 4+ letters, digit runs of 5+, one character 4+ times; refused when fewer than 5 other letters remain, digits and symbols never count).
- **Letter number** (`App\Domain\Letters\LetterNumber`, D-82): TU types the complete number of each letter. `normalize()` trims and turns runs of spaces into one; `numberError()` accepts 3–64 characters of `A–Z a–z 0–9 . / -` and space with at least one digit; `key()` (lower case, no spaces) is what two numbers are compared by. A number is never used twice for the same letter type, revoked letters included: friendly validation (`IssueLetters::isTaken()`) plus the DB unique index (type, full_number). The settings formats only build the example under the field (`example()`); `format()` / `parse()` / `pattern()` stay for that, the demo data and old rows.

## 8. Auth & security essentials (details in SECURITY.md)
- One login field (D-42, D-78, D-137): students use the NISN (10 digits) and, once activation is finished (`DetermineActivationStep::finished()`: status active, email verified, no forced change), the bound email; staff `users.username` or email; one generic error message; RateLimiter keyed by account bucket (NISN and a resolved email share one; since D-137 a number that matches only a NIS resolves to nobody: same generic error "NISN atau kata sandi salah.", dummy hash, HMAC bucket) + IP (strict) and by IP (loose, school NAT). An email that does not resolve (not verified, activation open, disabled, archived, unknown) is treated like an unknown identifier.
- First login (D-79; replaces "password → email → OTP"): bind email → OTP (6 digits, hashed, 10-minute TTL, 5 attempts, 60 s resend cooldown) → password. `CompleteActivation::bindEmail()` stores the verified email and leaves the account pending; `changePassword()` (must differ from the current password) then sets it active; since D-138 every account must change it (the former student choice `keepPassword()`, D-79, is deleted). The initial password works only inside its activation window (K1, reopen 72 h = K2; a reopen never shortens the window: the later one of the current deadline and now + 72 h, D-95) and 10 failed attempts freeze a not-activated account (K3). **OTP test mode** accepts any 6 digits ONLY when `APP_ENV=local` AND `STH_OTP_TEST_MODE=true`; `AppServiceProvider::boot()` throws if it is on elsewhere; CI asserts this.
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
built with `App\Notifications\Support\PlainMailMessage` (user text is never Markdown). Since D-129 (EM2, 2026-10-10;
*sementara* until the lead has seen a real mail) they are rendered from the app's own template (`resources/views/vendor/notifications/email.blade.php`, components in
`resources/views/vendor/mail/{html,text}`, theme `sth`; §8h): the greeting, the lines, at most one link (the action
button) and one footer sentence (`PlainMailMessage::FOOTER`); the text part is plain text without Markdown. **Greeting
rule**, one for every mail incl. the OTP mail and the binding notice (`App\Notifications\Support\MailGreeting`): a
student account "Halo <first name>," (`App\Support\FirstName`: the first word that is not an abbreviation), staff and
an account without a role yet "Yth. <full name>,", an unknown recipient "Halo,". `MailGreeting::for($notifiable)`
decides by the account (`LoginIdentifier::isStudentAccount()`); a notification with one fixed audience calls
`::student()` / `::staff()`. No notification calls `->salutation()`. `User::routeNotificationForMail()`
skips the mail (the bell entry is still stored) for a frozen account, and for an address nobody verified unless the
notification is an `AdminNotice`. A frozen account gets no reset code. Proven end to end by
`tests/E2E/support/email-scenario.php` (re-run with the M1 code on 2026-10-05: 122 mails, 0 missing, 0 unexpected,
0 leaks, 0 failed jobs). The M1 and M2 rows below (talent restore / review request, deleted with the talent stages by
D-142; the deleted appreciation event,
the released appreciation), the guru notices of QA5 for a released appreciation and a deleted event (D-110), the
withdrawn invitation of QA6 (D-115) and the lomba change notice of EM2 (D-126) are covered by Pest only: the scenario
has no step for them yet (CLAUDE.md backlog #46).
EM2 (2026-10-10) changed the scenario's steps 8b (event with a start time), 8c / 8d (the reminder goes to Humas only;
the student's mail follows `PublishPublication`), 10j (greeting) and 11a, and its review added the guru pembimbing
mails the steps 7b, 7d, 7f (report decisions) and 8b ("Binaan masuk daftar apresiasi") had been missing since D-110.
Those four rows were written by reading the notification classes. The edited file was run on 2026-10-10 on the
isolated project: 123 mails, 0 missing, 0 unexpected, 0 leaks, 0 failed jobs, with the OTP test mode the scenario
expects (details and the run with real OTP codes: TEST-RESULTS in `docs/dev-notes/EM2.md`).

| event (Action) | recipients |
|---|---|
| OTP for activation, email change, password reset (`IssueOtpCode`) | the address being verified / the bound address (mail only) |
| Email bound or changed (`CompleteActivation::bindEmail`, `ChangeEmail`) | bound address; on a change the old and the new address, with a "Bukan saya?" link (mail only). At activation the notice goes out when the code is accepted (step 2), while the account is still pending; the password step (step 3) sends no mail (D-79) |
| Password changed / reset (`ChangePassword`, `ResetPasswordWithCode`) | the account owner |
| Lomba submitted or added (`SubmitCompetition` = a student's usulan, `CreateCompetition` = Admin's "Tambah lomba"; nobody else since D-145) | every holder of `competition.verify` except the actor |
| Lomba published / rejected (`VerifyCompetition`) | the submitter (not when the verifier is the submitter); a rejection carries "Alasan <peran>: …" when a reason was written, else `CompetitionRejected::NO_REASON` (D-103); since FS1 the sentences name the deciding role (`StaffRoleLabel`: "Kesiswaan" or "Admin"; "Kesiswaan" without a decider). D-148: when the submitter's own registration waited for the lomba, the same notice adds where it went ("Pendaftaranmu diteruskan untuk persetujuan." / "Undangan tim sudah dikirim ke anggota.") or "Pendaftaranmu untuk lomba ini ikut dibatalkan."; there is no separate cancel notice. After the commit `ResumeWaitingRegistrations::notify()` sends the team invitations of resumed teams and "Pendaftaran lomba menunggu persetujuan" for resumed individu registrations to the approvers (never to the verifier); a rejection tells nobody else |
| Published lomba changed (`UpdateCompetition`; who and what: `CompetitionChange`; kinds `competition.changed` / `competition.binaan_changed`; D-126, *sementara*) | only when a **fact** changed (`CompetitionChange::FACTS`), one notice per save per person, one sentence per changed fact. **Batas daftar, peserta + maks anggota**: the students of registrations in `menunggu_konfirmasi`, no guru. **Tanggal mulai / final, pelaksanaan, tempat, link registrasi, website resmi, juknis**: the students of registrations from `menunggu_konfirmasi` up to `berlangsung` and their guru pembimbing; a changed **final date** also `menunggu_laporan` (sentence with the new report deadline). Students = member rows nobody declined (ketua, confirmed, still invited) whose account can log in; guru = an active account that still holds the guru role, **one** notice for all the guru's registrations of that lomba ("Yth. …", link to the binaan page, or to `guru.beranda` with several). Never the actor, never `laporan_dikirim` / `selesai` / `ditolak` / `dibatalkan`, never a student who is not registered, never all students; nothing for a lomba in `menunggu_verifikasi` (also through "Simpan & publikasikan") or for a change of any other field. More than 50 recipients in one save (`CompetitionChange::MAIL_LIMIT`): `database` only for everyone. No sentence prints a web address ("Link registrasi diganti." / "dihapus."); "Tempat" is printed as typed, except when it holds a web address (`NoWebAddress::found()`): then the sentence is only "Tempat diganti." (the field itself has no address rule and the save is never refused) |
| Team invitation (`CreateRegistration`, `InviteMembers`, `ResumeWaitingRegistrations` when the lomba of a waiting team is published) | the invited students; nobody while the registration is `menunggu_lomba` (D-148). A resumed team's invitation names its answer deadline ("Konfirmasi sebelum <tanggal, jam> …", `Registration::graceText()`) |
| Invitation withdrawn by the ketua (`WithdrawInvitation`, kind `registration.invitation_withdrawn`, D-115) | the student whose unanswered invitation was withdrawn (when the account can log in); the Kepala Sekolah when the team is complete after it, as after a decline |
| Member accepted / declined (`ConfirmMembership`, `DeclineMembership`), invitations expired | the ketua |
| Registration waiting for approval (individual: at once; team: when complete; a `menunggu_lomba` registration only once its lomba is published) | holders of `registration.approve` who can log in: Kepala Sekolah **and** Tata Usaha since D-150, each with a link to its own Persetujuan address (`ApprovalQueue`) |
| Registration approved (`ApproveRegistration`) | all members, the guru pembimbing, every Tata Usaha except the TU account that approved it (D-150). The decider is named (D-150): students "Pendaftaranmu di <lomba> disetujui oleh <nama> (<peran>).", TU "<nama> (<peran>) menyetujui pendaftaran …", the guru "… disetujui <peran>."; a notice queued before D-150 reads "sekolah". The members' notice always names their guru pembimbing (D-132). When the Kepala Sekolah replaced the guru with the approval: the members read both names, the **new** guru gets the notice as an appointment ("Ditetapkan sebagai guru pembimbing"), and the guru who was replaced gets **nothing**: neither the approval notice nor a notice of the change (D-134; that account never heard it had been chosen, a new registration is told to the Kepala Sekolah and the team only) |
| Registration rejected (`RejectRegistration`) | all members, the guru pembimbing ("… tidak disetujui <peran>.", D-150) |
| Registration cancelled (`CancelRegistration`) | the other members, the guru pembimbing; the approvers (Kepala Sekolah and Tata Usaha, each linked to its own Persetujuan address) only if it had reached `menunggu_kepsek`; **nobody** when it was cancelled from `menunggu_lomba` (D-148) |
| Letters sent / revised (`SendLetters`, `RevokeLetter`) | members (siswa letter), guru pembimbing (guru letter); a revocation also goes to the Kepala Sekolah |
| Report window opened (`AwaitReport`, `ReportWindowOpened`: "Segera laporkan hasil lombamu. …"), reminders on day 3 and day 14 after the final date while no report exists (`sth:reports:remind`, `ReportReminder`, D-151; before: 14 and 3 days before the deadline) | the ketua |
| Report submitted / resubmitted (`SubmitReport`) | every account that can log in and holds Kesiswaan **or** Humas (D-139; before: Kesiswaan only), once each (`App\Actions\Reports\ReportVerifiers::recipients()`; Admin is not mailed). `ReportSubmitted` builds its link per recipient: a Humas account gets `humas.verifikasi.index`, any other (Kesiswaan, also one that is Humas too) `kesiswaan.verifikasi.index` (`VerificationArea::forUser()`), so nobody is sent to an address that answers 403 |
| Report verified / revision requested / rejected | all confirmed members; the guru pembimbing of the registration (`BinaanReportDecided`, "Yth. …", link to the binaan page; only an active guru account, D-110); on verification also every Humas ("ready for publication"; since D-139 this includes the Humas who decided). Since D-139 the sentences name the role that decided (`StaffRoleLabel::forDecision($actor)`: "Capaian … sudah diverifikasi Humas.", "Catatan Humas: …"; "sekolah" for no staff role). "Rejected" comes only from `RejectReport`, which no screen calls since D-140 |
| Publication marked as published (`PublishPublication`, "Tandai dipublikasikan"; the only moment since D-122) | the student of the achievement, when the account can log in (`PublishPublication::notifiesStudent()`); nobody else |
| Scheduled publication whose time arrived (`RemindDuePublication`, job `RemindDuePublications` every 5 minutes; D-122) | every active Humas account, **once per schedule** (`PublicationDue`, "Waktunya upload publikasi": upload it to the channels now, then press "Tandai dipublikasikan"; it points to the caption and the data for the flyer on the publication page). **The student is not told at that time.** A re-schedule gives the new time its own reminder. No reminder for a student who may not be named (`NamedStudent`: no publish consent, disabled or deleted; an alumnus with consent is still reminded about); a row without a channel is reminded too. The reminder says "siswa baru diberi tahu saat itu" only when the button will really tell the student (`PublicationDue::$notifiesStudent`, set from `PublishPublication::notifiesStudent()` when the reminder is sent); for an alumnus it says "… supaya statusnya menjadi Dipublikasikan." (D-109) |
| Appreciation scheduled (`RecordAppreciations`, one "Masukkan"; `RecordAppreciation` = the same with one achievement) | each student who can log in, one notice per achievement (`AppreciationScheduled`); each guru pembimbing **one** notice per call that names every binaan of that call (`BinaanAppreciationNotice::scheduledMany()`, kind `appreciation.binaan_scheduled`, D-110 / D-124), so a team added with one click is one guru notice and two separate clicks are two. Every appreciation notice prints the event's day and start time (`EventWhen::sentence()`, "… pada Senin, 12 Oktober 2026 pukul 07.00 WIB"; the date alone for an event without a time, D-123) |
| Appreciation event deleted (`DeleteAppreciationEvent`, kind `appreciation.cancelled`) | every student with a scheduled row on it who can log in; each guru pembimbing of those rows once, listing all their binaan on the event (`appreciation.binaan_cancelled`, D-110); confirming an event notifies nobody (D-87) |
| Appreciation released (`ReleaseAppreciation`, kind `appreciation.released`, "Jadwal apresiasi berubah") | the student of that achievement when the account can log in, and the guru pembimbing (`appreciation.binaan_released`, D-110); the event itself goes on (D-97) |
| *Deleted by D-142 (2026-10-12): the two talent rows below describe notices that no longer exist; adding, deleting or restoring a talent choice and Kesiswaan's guru pembina / note change notify nobody.* Talent stage changed / application rejected / restored (`MoveTalentStage`, `RejectTalentApplication`, `RestoreTalentApplication`) | the student, with the optional note for the student (D-85, D-86): the text typed for that decision, or the saved draft when the action has no note field (D-96); the notification of a restore names the stage the choice is in again (D-104); the guru pembina is not notified; a new application notifies nobody (backlog #40) |
| Talent review requested (`RequestTalentReview`, kind `talent.review_requested`) | every Kesiswaan |
| Account frozen (failed logins before activation / "Bukan saya?") | Admins (+ Kesiswaan for failed logins) |
| Staff account created | Admins |
| Staff account reset / role changed, role change requested / applied / cancelled, Kepala Sekolah replacement | Admins and the account concerned (+ Kepala Sekolah where the 24 h delay applies) |
| Weight version published, signature replaced, letter template changed | Kepala Sekolah and Admins (not the actor for the template) |
| Archive job report, large export | Admins |

QA10 (2026-10-09) changes to the rows above: the letter notices (sent, revised) link to Lomba saya / the binaan detail,
which list the current letters, never one file version; a report revision asks only the ketua to "Perbaiki laporan" (the
other members read "Laporan hasil perlu revisi" and "Lihat lomba"); Humas "ready for publication" goes out only when at
least one member may be named and counts only those; the guru pembimbing is told only while he still holds the guru role
(approve, reject, cancel, letters); a delayed role change applied or cancelled also tells the Kepala Sekolah, and a
Kepala Sekolah replacement that cancels itself tells the current one; `LetterRevokedNotice` is a `StaffNotice` (verified
address only), `AdminNotice` extends `StaffNotice`; Database prestasi and the Status aktivasi CSV send
`LargeExportNotice` above 500 rows like the other exports.

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
- **Talent** *(history: removed by D-142, §8k; only "note" for the guru pembina remains)*: `note` (guru pembina) and `student_note` (student) are separate and optional; "Simpan" never sends a
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
  password. `CompleteActivation::hasTemporaryPassword()`; `SetNewPassword::handle()` clears the mark
  (activation, Keamanan akun, Lupa kata sandi). *(12 Okt 2026, D-138: `mayKeepPassword()`, `keepPassword()` and
  `MSG_MUST_CHANGE` are deleted: no account may keep a password at activation.)*
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
- **Talent draft note** (D-96; *removed by D-142, §8k*): `UpdateTalentAssessment` stores `student_note_draft` (the key `student_note` is still
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
- **Talent restore** (D-104; *removed by D-142, §8k*): `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null,
  ?TalentStatus $to = null)`. `$to` must be one of `TARGETS` (Terdaftar, Pemetaan, Seleksi, Pembinaan), else a
  `ValidationException` under `to` (`TARGET_MESSAGE`); null = `TalentApplication::restoreTarget()` (the stage before
  the rejection, Terdaftar when unknown), so older calls behave as before. Still only from `tidak_lolos` (checked on
  the locked row), ability `restore`, one transaction; the open review request is closed; pembina, assessment date
  and the note for the guru stay, and no stage requires a pembina or a date (as in `MoveTalentStage`; *sementara*).
  Audit `talent.restored`: `from` (tidak_lolos), `to`, `rejected_from` (new), `review_requested`, `student_note`.
- **Talenta export** (D-104; *the status dialog is removed by D-142, §8k*): `Kesiswaan\Talenta::export(AdminRecipients, string $status = '')`: `''` = what the open
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
- **Talent restore** (D-109; *removed by D-142, §8k*): `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null,
  ?TalentStatus $to = null, int|string|null $mentorId = null)`. A given `$mentorId` must be an active guru (error key
  `mentor_id`, "Pilih guru pembina dari daftar."); null keeps the guru pembina already on the row. A restore into
  `pembinaan` without any guru pembina is refused under `mentor_id` (`MENTOR_MESSAGE`), and so is one whose recorded
  pembina can no longer log in or lost the guru role (`INACTIVE_MENTOR_MESSAGE`); the other targets need none and keep
  the recorded pembina as it is. The dialog preselects the recorded pembina only while
  `RestoreTalentApplication::isActiveGuru()` (the list it shows).
  The chosen guru is written with the restore and is in the audit (`mentor_id`). The assessment date stays optional.
- **Talenta export** (D-111; *the status part is removed by D-142, §8k; filters and "no empty file" stay*): the file always follows the screen's filters (jenis, cabang, kelas, `q`) plus the chosen
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
- **Assessment date** (D-114; *removed by D-142, §8k: no assessment date*): `UpdateTalentAssessment::validated(array $details, ?TalentApplication $current = null)`
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

### 8h. Rules added by EM2 (D-122…D-129, the lead's mail and notification test of 2026-10-09)
Three migrations, each one nullable column without backfill (§4); `scripts/deploy/grants.sh` needs no change (grants
are per table). No new permission. Details: `docs/dev-notes/EM2.md`.
- **Publication schedule = reminder** (D-122): §4 `publications`. `RemindDuePublication::due(CarbonImmutable $now)`
  (`terjadwal`, `scheduled_at` ≤ now, `reminded_at` null, verified achievement, a student `NamedStudent` allows) and
  `handle(Publication|string, ?CarbonImmutable $now = null): ?Publication` (checks again under `lockForUpdate()`,
  stamps `reminded_at`, audits, then sends `PublicationDue` to `humasRecipients()`; the row is stamped also when no
  Humas account can log in at that moment: exactly once per schedule). A `terjadwal` row without `scheduled_at` shows
  as overdue but is never reminded. `HumasQueries::overduePublications()` = the visible overdue rows: the chip "Lewat
  jadwal" on a card, the box on Humas › Jadwal and the sidebar count `humas.jadwal` (menu item Jadwal; a key in
  `Navigation::BADGES` + one arm of `NavigationServiceProvider::count()`). Screen: `Humas\Publikasi::askPublish()`
  validates caption (optional, at most 2.000 characters) and channels like `PublishPublication`, sets `#[Locked]
  $publishingId` and opens `PUBLISH_DIALOG` (`tandai-terbit`); `publish()` is the confirm step (D-128 below). The
  texts that speak of a student notice (the reminder, the alert "Jadwal sudah lewat", the hint under the date, the
  dialog line and the toast) promise one only when `PublishPublication::notifiesStudent()` is true: `Publikasi::panel()`
  passes it as `notifies`, `PublicationDue` keeps it as `$notifiesStudent` (read with `?? true`, so a payload queued
  before the property existed still renders). An alumnus with publish consent stays in the queue and gets no notice,
  so those texts leave the promise out (D-109).
- **Scheduled jobs** (`routes/console.php`, all `withoutOverlapping(60)`): `sth:registrations:advance` 00:15 ·
  `sth:reports:remind` 07:00 · `ExpireInvitations` and **`RemindDuePublications`** every 5 minutes (the latter replaces
  `AutoPublishPublications`, which is deleted with `AutoPublishPublication`; `ShouldBeUnique`, at most 500 rows per
  run) · `sth:arsip-otomatis` 01:00 · `sth:ganti-kepala-sekolah` and `sth:peran-tertunda` every 5 minutes ·
  `CleanupOtpCodes` hourly · `sth:audit:seal` every 10 minutes · `sth:audit:verify` 02:30 · `PruneExpiredCache` 03:10
  (times Asia/Jakarta). After a deploy of EM2 restart `queue` and `scheduler`: a queued payload of the deleted job
  class fails once.
- **Appreciation events** (D-123): `CreateAppreciationEvent::handle(User, string $name, DateTimeInterface|string
  $heldOn, string $heldTime)` (error keys `name`, `held_on`, `held_time`, all collected before answering; a time of
  today that already passed is accepted, a past date is not; name + date still make a duplicate). `EventWhen`:
  `parseTime()` (`HH:MM` or `HH:MM:SS`, 00–23 / 00–59, seconds dropped, else `TIME_MESSAGE`), `long()` ("Senin, 12
  Oktober 2026, 07.00 WIB": cards, dialogs), `sentence()` ("… pukul 07.00 WIB": notifications), `short()` ("12 Okt
  2026, 07.00": select options, the publication panel). `AppreciationEvent::heldTime()` ("07:00" or null) /
  `startsAt()`. Events of one day are ordered by `held_time` (Apresiasi, Humas › Jadwal, the event choice of the
  publication panel). The day-level rules of D-87 are unchanged (confirm from the event day on, add during the whole
  event day).
- **"Tambah prestasi"** (D-124; search and several ticks are final, "one guru notice per Masukkan" is *sementara*):
  `RecordAppreciations::handle(User, AppreciationEvent, iterable $achievements):
  Collection` (`MAX` = 30 per call, the same achievement twice counts once, error keys `achievement` / `event`; one
  transaction, one `appreciation.recorded` row per achievement; notices after commit, §8b); `toldStudents()` counts
  the distinct students who get a notice, for the toast. `RecordAppreciation::handle()` delegates to it. Screen
  `Humas\Apresiasi` (also `kesiswaan.apresiasi.index`): `$search` (student or lomba name, `%` and `_` literal,
  `HumasQueries::searchAchievements()` on top of `appreciableAchievements()`, so a student without publish consent is
  never found by name; the box keeps the text as typed: `updatedSearch()` never writes a trimmed value back, because
  Livewire would put it into the focused input and eat the space before the next word, so only `picker()` and
  `searchAchievements()` trim the term, and the hook only cuts a text above `SEARCH_MAX` = 100), `$picked` (browser input: every id is loaded again through the eligibility query; a stale,
  forged or malformed tick writes nothing and answers `PICK_STALE_MESSAGE`), `#[Locked] $pickLimit` (`PICK_STEP` 10,
  `PICK_MAX` 100), `addAchievements()`. The page passes `'search' => false` (one search box per screen, D-90).
- **Lomba links** (lead's test, no decision number): `App\Rules\ExternalUrl::normalize(mixed): string` cleans a pasted
  link (byte order mark, zero-width characters, soft hyphens and text-direction marks anywhere; whitespace of any
  kind at both ends; never adds a scheme) and `problem(string): ?string` returns one sentence per refusal reason
  (`MESSAGE`, `NO_HTTPS`, `HTTP`, `SPACE`, `HOST`, `IP`, `PORT`, `CREDENTIALS`, `CHARACTERS`, `TOO_LONG` = 500);
  `passes()` = no problem. The gates that decide are the old ones in the old order, so the accepted set is unchanged;
  the top-level domain is not judged. All four link fields use it (usulan "Link resmi" of siswa and guru:
  `SubmitCompetition`; Kelola lomba "Website resmi" and "Link registrasi": `CreateCompetition::prepare()` /
  `UpdateCompetition`). `Lomba\UsulanForm::kirim()` trims its text fields first, checks the name with `NoWebAddress`
  together with the other fields, and errors raised by the Action also dispatch `sth-form-invalid`. *(Since D-145 the sheet is gone and the guru proposes nothing: the page `Siswa\Lomba\Usulkan::simpan()` cleans the text fields (`CompetitionFields::cleanText()`) and dispatches `sth-form-invalid` the same way, §8k.)*
- **"Jenis penyelenggara: Lainnya"** (D-125; final, but "required to publish" and the format "Lainnya (…)" are
  *sementara*): §4 `competitions.organizer_type_other`. `CreateCompetition::rules()`
  (`ORGANIZER_TYPE_OTHER_MAX` = 60, "Maksimal 60 karakter."), `normalize()` (dropped for another type or no type),
  `VerifyCompetition::missingFields()` (adds `OTHER_TYPE_LABEL` right after the type, so the queue card,
  "Publikasikan", "Simpan & publikasikan" and a save of a published lomba all name it). Shown where the type was
  shown before: Guru › Lomba detail, Kesiswaan › Database prestasi detail and the Excel column "Jenis penyelenggara";
  the filter stays per enum value; students, public pages, letters and "Data untuk flyer" show no organizer type.
- **Lomba change notice** (D-126, *sementara*): `App\Actions\Competitions\CompetitionChange` (final, static):
  `FACTS` = deadline, dates, mode, location, participants, registration_url, website_url, juknis; `snapshot()` taken
  on the locked row before and after the save, compared on what a reader sees (dates by day, place without case and
  spacing, "maks anggota" ignored while individu); `changed()`, `finalChanged()`, `statusesFor(string $fact, bool
  $finalChanged = false)`, `tellsMentor()`, `line(string $fact, Competition, ?RegistrationStatus $status = null)`,
  `audience(Competition, array $facts, User $actor, bool $finalChanged = false)`, `summary()` (the drawer's toast:
  null when nobody was told), `attachedStudents()` (the number in the info alert at the top of "Ubah lomba": the
  members nobody declined of the registrations up to `berlangsung`; which of them hear a changed fact is
  `statusesFor()`, and the alert says so in two sentences), `MAIL_LIMIT` = 50. `line('location')` prints the place as
  typed, or only "Tempat diganti." when `NoWebAddress::found()` sees a web address in it.
  `UpdateCompetition::handle()` keeps its signature: inside the transaction it writes `competition.facts_changed`
  (also with zero counts when nobody is attached), after the commit it sends `CompetitionChanged::for(Competition,
  string $registrationId, list<string> $lines, bool $mail = true)` and `BinaanCompetitionChanged::for(Competition,
  list<string> $lines, ?string $registrationId = null, bool $mail = true)` (both `Verification\Notice`; `$mail` false
  = `database` only). Recipients: §8b. A new lomba field is a fact only when it is added to `FACTS`, `snapshot()`,
  `statusesFor()` and `line()`. Screen: `Kesiswaan\Lomba\Form::changedElsewhere($competition, bool $expectsQueued)`
  (`save()` passes `formMode === 'verify'`, `saveAndPublish()` true, `open()` false): a "Periksa lomba" drawer whose
  lomba another account published meanwhile does not save on the first "Simpan" (warning toast "Lomba ini sudah
  dipublikasikan. Perubahan belum tersimpan, periksa lalu simpan.", the drawer becomes "Ubah lomba" with the info
  alert); the next "Simpan perubahan" saves and notifies.
- **Live counts** (D-127; final, the 60-second interval and the switch `STH_COUNTS_POLL_SECONDS` are *sementara*):
  `GET /notifikasi/jumlah` (`notifikasi.jumlah`, `NotificationCountController`; middleware
  of the Notifikasi page: auth, active, activated, staff.2fa, plus its own `throttle:30,1,lonceng`) answers
  `{"badges": {"akun.notifikasi": n, "<sidebar key>": n, …}}` = `NavigationServiceProvider::liveCounts(User)`: the
  bell (`Navigation::BELL`, the user's own unread database notifications; not a menu key, not in `BADGES`) plus
  `badges()` of every role the user holds, with the same policy checks and queries as the sidebar. Numbers only;
  `Cache-Control: no-store, private`; not audited; `session()->reflash()` (not a page view: a flashed toast stays
  for the next page); a request that does not ask for JSON is redirected to `notifikasi.index`, so the address is
  never a page to land on, and a guest gets a plain 401. Interval: `config('sth.counts.poll_seconds')` /
  `STH_COUNTS_POLL_SECONDS` (new `config/sth.php`; default 60; 1–29 are read as 30 (`MIN_SECONDS`), above 3600 as
  3600 (`MAX_SECONDS`; the script clamps again with `COUNTS_MIN_EVERY` / `COUNTS_MAX_EVERY`, because a browser timer
  delay is 32 bits of milliseconds and a larger one can fire at once); 0 = the layout prints no address and no page
  asks, the only off switch), read through `NotificationCountController::pollSeconds()` / `pollUrl($user)`.
  Only `layouts::app` prints the address (`data-sth-counts-url` / `data-sth-counts-every` on the bell);
  `layouts::mobile`, `auth` and `public` never ask. `resources/js/app.js` ("live counts") asks 2.5 s and 8 s after a
  Livewire action (not after `wire:poll`, `$refresh`, `$set`), about every 60 s ± 10 s while the tab is visible and
  was used in the last 5 minutes, and 0.3 s after Back / Forward; never next to a Livewire request. It stops after two
  refused answers in a row (401 / 403: the session ended or the account is not allowed) until the next page; what can
  pass only holds it for at least 60 s while the timer chain goes on: a 429 or a 5xx (as long as `Retry-After` asks,
  so the 503 of a deploy's `artisan down --retry=60` is waited out), a network error, no answer within 15 s, an
  answer that is not ours. The answer goes through the event `sth-badges`.
  `Akun\Notifikasi` dispatches `sth-badges` with the bell key on every render ("Tandai dibaca" lowers the bell at
  once; "Tandai semua dibaca" no longer reloads the page) and carries `data-sth-refresh-on="akun.notifikasi"`, so
  the list refreshes when the bell number changed. Every check is one full request to php-fpm and extends the
  session (SECURITY §3a).
- **Confirmations** (D-128; final for "Tolak" of a report, *sementara* for the other dialogs of the sweep): a
  question before an action is always the app's own dialog (`<x-sth.modal>`), never the browser's `confirm()`
  (`wire:confirm`; `tests/Feature/Support/NoNativeConfirmTest.php` fails on any view that uses it). The one-click and
  bulk actions swept in EM2 got such a dialog (table below). Not every action that notifies people asks: the senders
  left without a question on purpose are listed in D-128, among them Apresiasi "Masukkan" (up to 30 ticks) and
  "Simpan perubahan" on a published lomba (up to `CompetitionChange::MAIL_LIMIT` mails); whether those two should ask
  is an open question for the lead. Two steps: an `ask…()` method authorizes, checks the input, stores what was asked
  in a `#[Locked]` property and dispatches `sth-modal-open`; the confirm method acts only on that stored value and is
  a **silent no-op** without it (no 404, no toast, so a double click never shows an error page). Built in EM2:

  | screen · button | dialog id (constant) | ask → confirm · locked state |
  |---|---|---|
  | Kesiswaan › Verifikasi hasil · "Tolak" (**removed by D-140**, 2026-10-12: button, dialog, constant, methods and property are gone; §8j) | `tolak-laporan` (`VerifikasiHasil::REJECT_DIALOG`) | `askReject()` → `reject()` · `$rejectingId` |
  | Siswa › Daftar lomba · × "Batalkan undangan" | `batal-undangan` (`Siswa\Pendaftaran\Form::WITHDRAW_DIALOG`) | `askBatalkanUndangan($studentId)` → `batalkanUndangan()` · `$withdrawingId` |
  | ~~Kesiswaan › Talenta · bulk "Pindahkan ke …"~~ (removed by D-142) | `pindahkan-talenta` (`Talenta::ADVANCE_DIALOG`) | `askBulkAdvance()` → `bulkAdvance()` · `$advanceAsk` (which rows move, and from which stage) |
  | Kesiswaan › Kelola lomba · "Tutup" | `tutup-lomba` (`Lomba\Index::CLOSE_DIALOG`) | `askClose($id)` → `close()` · `$closingId` |
  | Import siswa · "Impor N siswa" | `impor-siswa` (`Akun\ImportSiswa::IMPORT_DIALOG`) | `askImport()` → `import()` · `$importAsked` (the preview token; the password page comes before the dialog) |
  | Humas › Publikasi · "Tandai dipublikasikan" | `tandai-terbit` (`Publikasi::PUBLISH_DIALOG`) | `askPublish()` → `publish()` · `$publishingId` |

  `RejectReport::reason(string $note): string` is the one check of the rejection reason (required, at most 1.000
  characters, `NOTE_REQUIRED`; since D-140 only the Action uses it); `RequestReportRevision::audience(Report): array{told: int, of: int, mentor: bool}`
  says who hears about a decision, and the dialogs and result banners of "Tolak" and "Minta revisi" name only those
  people (D-109; since D-140 only the "Minta revisi" banner is left). `RequestReportRevision::leaderCanResend(Report): bool` = the ketua's account can log in; the
  revision banner adds "Ketua / Siswa bisa mengirim ulang laporan." only then (`ReportPolicy::create` lets only the
  ketua send a report again). "Batalkan undangan": the line under the dialog title is "<Nama> langsung diberi tahu."
  when the invitee is told, else the neutral "Undangan ini belum dijawab." (a student screen never states another
  student's account status, SECURITY §3m-3); a refused withdrawal (ask or confirm step) is also a warning toast
  (`Form::refuseWithdrawal()`: the block that prints `members` errors is drawn only while the ketua may still invite);
  the result toast is read from the status `WithdrawInvitation` wrote under its lock and adds "Pendaftaran diteruskan
  ke Kepala Sekolah." when the withdrawal completed the team. Older confirm steps keep their behaviour
  (`Talenta::reject()` and `Lomba\Index::publishPast()` still answer 404 without their ask step; `Talenta::reject()` is gone with D-142). Guru › detail binaan: an invitation the scheduler ended at the deadline
  reads "Tidak menjawab" (`ExpirePendingInvitations::expiredMemberIds()`), "Menolak" only for a real decline.
- **Mail templates** (D-129, *sementara*: the look, and with it the greeting rule, the text part, the single link
  and the footer sentence, wait for the lead to see a real mail): `config/mail.php` `markdown.theme` = `sth`, `markdown.paths` =
  `resources/views/vendor/mail`. The notification view prints the greeting and every line through
  `PlainMailMessage::plain()` (public now), so text stays text in every compile order: production compiles views
  beforehand (`view:cache`) with the ordinary Blade echo, where `Markdown::withSecuredEncoding()` does nothing. The
  header is text ("SMAGA Talent Hub / SMA Negeri 3 Semarang"), the address under the button is text, so the button
  is the only `href`; no image, no remote font. `OtpCodeMail` (plain Blade, `resources/views/auth/mail/otp-code*`)
  and `EmailBindingNotice` take the greeting as an optional property with a default (`IssueOtpCode` /
  `SendEmailBindingNotice` pass `MailGreeting::for($user)`), so a job queued before the deploy still unserializes;
  the OTP mail has no link at all. `sth:mail-test` ends with the same footer sentence. `FirstName::of()` also feeds
  "Halo, …" on Siswa › Beranda and the menu tour. After editing a mail view or `config/mail.php`: `reload.bat` and
  restart `queue`.
- **Audit**: new events `publication.reminded` (`achievement_id`, `scheduled_at`, `channels`, `by` = scheduler; no
  causer) and `competition.facts_changed` (`changed` = fact keys, `students`, `mentors`, `mail`: counts only, no
  names, ids or addresses); new property `held_time` on `appreciation.event_created`, `.recorded`, `.released`,
  `.event_completed` and `.event_deleted` (null for an event without a time). The count endpoint, the dialogs and
  the mail templates write nothing.

### 8i. Rules added by RV1 (D-130…D-133, the demo and the joint review of 2026-10-11)
No migration, no new permission, no new route. Details: `docs/dev-notes/RV1.md`.
- **Local mail allowlist, open mode** (D-130, *sementara*, `APP_ENV=local` only): `STH_MAIL_ALLOWLIST=*`.
  `RecipientAllowlist::fromString()` reads the single star as "open" (`isOpen()`); `allows()` then accepts every
  well-formed address except a fictional one (`isFictional()`: a host without a dot or written as numbers, the
  endings `.test`, `.example`, `.invalid`, `.localhost`, `.local`, and `example.com|net|org` with their
  subdomains). A listed entry still delivers as before; an entry that opens one domain is still dropped into
  `rejected()`, and a star next to any dropped entry does not open the list (`openRefused()`, said by
  `sth:mail-test`): a typing mistake never sends more mail out. Both boot guards of D-121 are unchanged.
- **NIS in full for Kesiswaan and Admin** (D-131): `ActivationReport::row()['nis']`, the rows of `Kesiswaan\Talenta`
  and the rows and detail of `Kesiswaan\DatabasePrestasi` carry the NIS as stored; the Status aktivasi CSV too. The
  email stays masked (`AccountControls::maskEmail()`); the NISN is on none of these screens. No route, policy or
  export rule changed. *(12 Okt 2026, D-137: the key is now `nisn` and those screens, their Excel/CSV files, Profil
  talenta and the import "rows to check" show the NISN instead of the NIS; the NIS is no longer shown there.)*
- **Guru pembimbing at the approval** (D-132):
  - `App\Actions\Registrations\MentorChoice` is the one rule for who may be a guru pembimbing: `query()` (role guru
    + `canLogIn()`), `find(int|string|null $id): ?User`, `options(int ...$except): array<int, string>` (by name,
    without the given accounts), `name(?User): ?string` (honorific + name) and `closing(string): string` (a name at
    the end of a sentence). The student's form, `CreateRegistration` and the approval use it.
  - `ApproveRegistration::handle(User $actor, Registration $registration, ?string $note = null, int|string|null
    $mentorId = null)`: `$mentorId` null, '' or the guru the registration already has = nothing changes. Otherwise,
    inside the same transaction and after the lock: `RegistrationPolicy::changeMentor` (= `decide`: the Kepala
    Sekolah while `menunggu_kepsek`), `MentorChoice::find()` (else `ValidationException` key `mentor`,
    `ApproveRegistration::MSG_MENTOR`, and nothing is decided), `mentor_id` is set together with the status, and
    the audit row `registration.mentor_changed` (`from`, `to`: user ids, `from` null when there was none) is
    written before `registration.approved`. The model the caller passed is updated and its `mentor` relation
    dropped. There is no other way to change `mentor_id` of an existing registration, and none after the decision.
  - Who is told (after commit): the members (`memberUsers()`), the guru the registration has **now**
    (`mentorToTell()`) and Tata Usaha. The guru who was replaced is told **nothing** (D-134: that account never
    heard a student had chosen it, so "diganti" would be the first thing it hears; the class `MentorReplaced` and
    `replacedMentorToTell()` of the first version are gone). The actor never gets a guru notice of their own decision,
    also not as the registration's guru (an account can be Kepala Sekolah and guru at once). `RegistrationApproved::for(Registration, string $audience, bool $mentorChanged = false,
    ?string $replacedMentorName = null)` keeps the names as they were at the decision in plain properties with
    defaults (`mentorName`, `mentorChanged`, `replacedMentorName`), so a notice queued before this change still
    unserializes; `mentorLine()` is the students' sentence ("Guru pembimbing: …", null without a guru); the `tu`
    audience carries no guru. A rejection changes nothing: `RejectRegistration` still tells the guru the student
    chose (D-77).
  - `ApproveRegistration::mentorWasReplaced(Registration): bool` reads the audit row; Siswa › Lomba saya shows
    "Ditetapkan Kepala Sekolah" under the guru only then.
  - Screen: `Kepsek\Persetujuan::askMentor(string $id)` → dialog `Persetujuan::MENTOR_DIALOG` (`ganti-pembimbing`)
    → `approveWithMentor()`; the ask stores the row in `#[Locked] $mentorFor`, the confirm step is a silent no-op
    without it (D-128), a refused guru lands under the field, a row decided or cancelled meanwhile is a toast
    (QA9). The dialog lists `MentorChoice::options()` without the current guru and without the Kepala Sekolah's
    own account, and says in two plain sentences who is told (D-109, D-134): the students ("Siswa diberi tahu bahwa
    pendaftarannya disetujui dan siapa guru pembimbingnya.", or the variants for accounts that cannot log in) and
    the new guru ("Guru pengganti diberi tahu bahwa ia ditetapkan sebagai guru pembimbing."); nothing about the
    guru the student chose. "Setujui" and "Setujui terpilih" pass no guru.
- **Student letter centred** (D-133): `resources/pdf/layout.blade.php` prints the surat tugas siswa with equal left
  and right margins (61.14 pt) and 473 pt wide blocks. Issued letters keep their stored PDF.
- **Guards against dead controls** (lead: "jangan ada error simpel seperti tombol Ikut tim"). The root element of a
  Livewire view comes first, with no directive around it (Livewire puts `wire:id` on the first tag that starts a
  line; an `@if` before the root prints a marker in front of it). Three tests keep this and its relatives away:
  `tests/Feature/Support/LivewireRootTest.php` (the source of every screen), `PageWiringTest.php` (opens every page
  each role reaches with the demo data: status 200, every `wire:` control inside a component, its method or
  property exists, every opened dialog and referenced form is on the page) and `ViewWiringTest.php` (the same
  names read from the Blade sources, so branches no test renders count too; `data-sth-*` hooks known to
  `app.js`; `route('…')`, `@include` and `<x-sth.*>` exist; every view compiles to PHP that parses).
- **A bell row keeps the path of its target** (found when the demo ran behind a tunnel whose address changes at
  every start). A notification builds one address with `route()` for the mail button and for the bell row. The
  mail needs it absolute; the bell row is opened inside the app, and `Akun\Notifikasi::internalTarget()` only offers
  "Buka" for an address of the app host or of the host the page runs on, so a row written under another address
  (127.0.0.1 by the queue worker, an older tunnel address, the same database on a second machine) had lost its
  button without a word. `App\Notifications\Channels\BellChannel` (extends Laravel's `DatabaseChannel`, bound to
  that class name in `AppServiceProvider::register()`, which is how the notification manager resolves the
  `database` channel) cuts `data['url']` down to path + query + fragment when it starts with this application's own
  root at that moment (`BellChannel::relative()`); an address of any other site is stored as it came and stays
  refused by the screen. `toArray()` of a notification keeps passing `route()`; `internalTarget()` and its host
  check are unchanged. Nothing may read a stored `data['url']` as an absolute address.

### 8j. Rules added by FS1 Paket C (D-139…D-141, the school's feedback of 2026-10-12)
No migration. One new permission grant (`report.verify` for `humas`: re-run `RoleSeeder` on deploy), three new routes.
Details: `docs/dev-notes/FS1.md` ("Paket C").
- **Humas verifies results** (D-139). `Kesiswaan\VerifikasiHasil` serves two route families: `kesiswaan.verifikasi.index|show`
  (`role:kesiswaan|admin`) and `humas.verifikasi.index|show` (`/humas/verifikasi-hasil[/{report}]`, `role:humas`,
  `whereUlid`). `App\Support\VerificationArea` (`KESISWAAN` / `HUMAS`; `resolve(?string $routeName, User)` by the route
  prefix, else `forUser()`: Kesiswaan or Admin → `KESISWAAN`, otherwise `HUMAS`; `indexUrl()` / `showUrl()` refuse any
  other area) fills `#[Locked] public string $area` once in `mount()`, after `authorize('viewQueue', Report::class)`. The
  area chooses the `sth-address` URL after a change of the selected report, the address after a decision or a stale
  decision (`decidedElsewhere()`), the badge payload (`verificationBadges()`: the Kesiswaan keys, or
  `['humas.verifikasi' => n]` dispatched as `sth-badges`) and one sentence (`catalogGap`: Humas reads "… Kesiswaan
  melengkapinya di Kelola lomba."); it never grants anything. Sidebar key `humas.verifikasi` (in `Navigation::BADGES`,
  counted by `NavigationServiceProvider::count()` with `viewQueue` and `Report::awaitingVerification()`, also set by
  `HumasQueries::badges()`). Own binaan stays refused for every verifier (`ReportPolicy::verify`, warning "Laporan
  binaan sendiri diverifikasi petugas lain."). The first decision wins (`decidedElsewhere()`, QA8).
- **Two decisions only** (D-140): `verify` and `requestRevision` are the screen's only decisions; `askReject()`,
  `reject()`, `REJECT_DIALOG`, `$rejectingId` and the dialog `tolak-laporan` are deleted, and the component file does not
  mention `RejectReport` (a test reads it). `RejectReport`, `ReportStatus::Ditolak`, `ReportRejected` and
  `BinaanReportDecided::rejected()` stay for reports rejected before 2026-10-12.
- **Who is told about a new report**: `App\Actions\Reports\ReportVerifiers::recipients()` (`ROLES` = Kesiswaan, Humas;
  `canLogIn()`; one row per account), called by `SubmitReport::notifyVerifiers()`; the link of `ReportSubmitted` is per
  recipient (§8b).
- **The deciding role in texts**: `App\Support\StaffRoleLabel` (pure, static): `of(User): string` = the label of the
  highest role by `PRIORITY` (`kepala_sekolah`, `tata_usaha`, `kesiswaan`, `humas`, `admin`, `guru`; '' without a staff
  role), `forDecision(?User): string` = `of()` or `FALLBACK` "sekolah". `VerifyReport` and `RequestReportRevision` pass
  `forDecision($actor)` to `ReportVerified::for(Achievement, string $decider)`, `ReportRevisionRequested::for(Report,
  string, string, string $decider)` and `BinaanReportDecided::revision(…, string $decider)`; the screens read
  `forDecision($report->verifier)` (Lomba saya: key `decider`, eager load `report.verifier.roles`; Guru › detail binaan:
  the report array's `decider`). Sentences before any decision say "sekolah". Paket B reuses the helper for approvals.
- **Privileged Humas**: `PrivilegedRoles::all()` gains `RoleName::Humas`; see §2 and `role_change_requests` in §4.
- **"Cetak daftar"** (D-141): `GET /apresiasi/{event}/cetak` → `apresiasi.cetak` (`whereUlid`, `area.staff` +
  `role:humas|kesiswaan|admin`) → `AppreciationListPrintController::show()` → `PrintAppreciationList::handle(User,
  AppreciationEvent): PdfDocument` (`Gate::authorize('printList')` = `AppreciationEventPolicy::viewAny`, i.e.
  `appreciation.manage`; renders `pdf::daftar-apresiasi` through `PdfRenderer`; audit `appreciation.list_printed` with
  `event_id`, `rows`, `omitted`). Rows from `AppreciationList::for(AppreciationEvent)`: every appreciation with an
  achievement (scheduled and given), kept only when `NamedStudent::allows()` at print time (else counted in
  `omitted`), `name` / `result` / `competition` / `level` from `AchievementFacts`, `class` = "Alumni" for an archived
  account, "–" without a class; sorted by real class (`strnatcasecmp`), then "Alumni", then "–", then name; never NIS,
  NISN, points or mentor. Headers as `StreamLetter` (inline, `no-store, private`, `nosniff`, `frame-ancestors 'none'`);
  file name `PrintAppreciationList::fileName()` (`daftar-apresiasi-<slug, 60>.pdf`). `Humas\Apresiasi::event()` adds
  `canPrint` (the event holds prestasi and `printList` allows) and `printUrl`; the button opens a new tab.

### 8k. Rules added by FS1 Paket E and Paket B (D-142…D-151, the school's feedback of 2026-10-12)
Two migrations (`talent_applications.other_text` + index swap + nullable `talent_types.bidang`; `registrations.answer_until`),
permission changes (`competition.create` = siswa + Admin, `registration.approve` += Tata Usaha: re-run `RoleSeeder`),
`TalentSeeder` (the type and the branches "Lainnya"), one new route each for siswa and TU. Details and the deploy order:
`docs/dev-notes/FS1.md` ("Paket E", "Paket B", "FS1 overview").
- **Talent without stages** (D-142). `ApplyTalent::handle(User, TalentType, ?TalentBranch, ?UploadedFile $evidence = null,
  ?string $otherText = null)` writes `terdaftar`, locks the student row, refuses a live duplicate (`DUPLICATE_MESSAGE`
  under `cabang`; `DUPLICATE_OTHER_MESSAGE` "Talenta ini sudah kamu pilih." under `lainnya`) and restores a deleted match
  (new evidence replaces the old one; guru pembina and note stay; the old stage fields are emptied); audit `talent.applied`
  (`talent_type`, `talent_branch_id`, `restored`, `evidence`, `other_text` as a boolean). `CancelTalentApplication`
  (policy `cancel`: the owning student, not deleted; any status) soft-deletes and audits `talent.cancelled` (`status`,
  `mentor` as a boolean); nobody is notified. `UpdateTalentAssessment::handle(User, TalentApplication, array $details)`
  (policy `update` = `talent.manage` and not deleted) takes only `mentor_id` (an active guru, or the one already on the row)
  and `note` (`TalentNote::MAX` 1.000); audit `talent.assessment_updated` with the changed keys. `Kesiswaan\Talenta`:
  `TABS` = semua, belum (`#[Url] $tab`), filters `jenis`, `cabang`, `kelas`, `q`, `#[Locked] $panelId`, `showPanel()`,
  `savePanel()`, `export(AdminRecipients)` (no argument; `EXPORT_HEADER`; `EXPORT_MAX_ROWS` 10.000; `EXPORT_NOTHING`; audit
  `talent.exported` with `filters`, `rows`, `large`, `format`; the button only on "Semua"); the tab "belum" lists
  `Student::active()` students of a level-10 classroom without a non-deleted choice (`missingQuery()`). Guru › Binaan
  counts and lists choices by `mentor_id` only; Portofolio and the Kesiswaan beranda read no status.
- **"Lainnya"** (D-143). `TalentTypeCode::Lainnya`, `TalentBranch::OTHER` / `isOther()`, `ApplyTalent::needsOtherText()`
  (type Lainnya or a branch Lainnya), `TalentOtherText` (`MIN` 3, `MAX` 60, `clean()`, `key()`), `TalentApplication::title()`
  ("OSN · Lainnya (Robotika)", "Lainnya (Kaligrafi)") and `branchLabel()`; every caller of `bidang()` handles null.
- **Kelas X gate** (D-144). `EnsureTalentChosen` (`talent.chosen`, last in `area.siswa`): `applies(User)` = can log in, a
  `Student` of a level-10 classroom with no non-deleted choice; `EXEMPT_ROUTES` = `siswa.talenta`, `siswa.bantuan`,
  `akun.keamanan`, `notifikasi.index`, `notifikasi.jumlah`, `logout`, `logout.get`; a route whose name ends in
  `livewire.update` passes; JSON → 403 with `ALERT`, else a redirect to `siswa.talenta`. Not a persistent Livewire
  middleware, so a Livewire update never re-runs it.
- **Lomba create** (D-145). `CompetitionPolicy::create` = `competition.create` (siswa, Admin). `SubmitCompetition::handle(User,
  array $data, ?UploadedFile $juknis = null, ?UploadedFile $cover = null)`: drops the weights, `default_mentor_id` and
  `puspresnas` from the input, validates `CreateCompetition::rules()` with `required` for `REQUIRED` (+ `organizer_type_other`
  for "Lainnya", `max_team_size` for tim/keduanya), `CompetitionDates::checkOrder`, `CreateCompetition::requireJuknis()`; a
  missing `starts_on` = one day; `DAILY_LIMIT` 5 per 24 h checked before the files are stored and again under the user lock;
  weights from `OrganizerWeight::for()`; audit `competition.submitted` (`has_brochure`, `weight_category`, `event_status`);
  `CompetitionSubmitted` to the verifiers. `Siswa\Lomba\Usulkan` (`siswa.lomba.usulkan`, `#[Locked] $competitionId`, 10
  sends an hour through `RateLimiter` key `usulan:<user id>`), `Siswa\Lomba\Katalog` (`TABS`, `myProposals()`:
  `submitted_by` = the user, `oldest('created_at')`, name search). `App\Livewire\Forms\CompetitionFields` (`FIELDS`,
  `DURATION_ONE_DAY` / `DURATION_MULTI_DAY`, `rules()`, `toAttributes()`, `fillFrom()`, `applyOrganizerWeight()`,
  `weightLine()`, `mapped()`, `isOverride()`, `rumus()`, `presentation()`, `addActionErrors()` returns the errors without a
  field) is the `form` of the Usulkan page and of `Kesiswaan\Lomba\Form`.
- **Weights** (D-146): see §7; `VerifyCompetition` writes `weight_override` = null or `{computed, chosen}` on both
  decisions (null while no weight or no organizer type is set).
- **Juknis and dates** (D-147): `CreateCompetition::JUKNIS_MISSING` (key `juknis`), `UpdateCompetition` refuses a lomba
  that still has no juknis after the save, `VerifyCompetition::REQUIRED_TO_PUBLISH` lists `juknis_file_id`;
  `CompetitionDates::STARTS_BEFORE_DEADLINE` = "Lomba tidak boleh dimulai sebelum pendaftaran ditutup.";
  `LombaPresenter::range()`, `pendaftaran()`, `REPORT_LINE`.
- **Waiting registrations** (D-148). `Competition::isOpenForRegistrationBy(User)` = `isOpenForRegistration()` or
  (`isAwaitingVerificationFor(User)` within the same date window). `CreateRegistration` writes `menunggu_lomba` and
  `invited_at` = null on the anggota rows when the locked lomba is `menunggu_verifikasi`, and sends nothing.
  `ResumeWaitingRegistrations::handle(User $actor, Competition $locked, CompetitionStatus $decision): WaitingOutcome`
  (locks the waiting rows; `WaitingOutcome` = `invited`, `toApprovers`, `submitterNow`) and `notify(WaitingOutcome, ?User
  $actor)`. `Registration::ANSWER_GRACE_DAYS` = 3, `answersCloseAt()`, `graceText()`, `cancelledWithLomba()` (audit row
  `registration.cancelled` with `reason` = `competition_rejected`), `scopeKnownToMentor()` / `isKnownToMentor()` (not
  `menunggu_lomba`, not cancelled from it: audit `properties->from`). `RegistrationPolicy::teamWindowOpen(Registration)`
  serves `respond` and `invite`; `ConfirmMembership`, `InviteMembers`, `WithdrawInvitation` and
  `ExpirePendingInvitations::due()` (the SQL form of `answersCloseAt()`) use the same moment. `LombaPresenter::WAITING_FOR_LOMBA`
  / `waitingForLomba()`.
- **Data peserta** (D-149). `App\Domain\Accounts\PhoneNumber` (`PATTERN` `/^(\+62|0)8\d{7,12}$/`, `INVALID`, `normalize()`,
  `isValid()`; also `ImportStudents`), `UpdateStudentPhone::handle(User, Student, string $phone)` (`EMPTY` "Isi No HP.",
  policy `StudentPolicy::updatePhone`: own, not deleted, siswa), `Siswa\Pendaftaran\Form::$phone` (validated with the other
  fields, saved before `CreateRegistration`), `Siswa\KonfirmasiTim::$phone` (saved before `ConfirmMembership`).
- **Approvers** (D-150). `App\Support\ApprovalQueue` (`KEPSEK`, `TU`, `routeName(User)`: TU when the account is TU and not
  Kepala Sekolah, `url(User)`); route `tu.persetujuan.index` (`/tu/persetujuan`, `Kepsek\Persetujuan`); TU menu item with
  the badge key `kepsek.persetujuan`; `RegistrationNotification::approvalLink($notifiable)`; `RegistrationApproved::for(…,
  ?User $decider)` (`deciderName`, `deciderRole`), `RegistrationRejected::for(…, ?User $decider)` (`deciderRole`);
  `CreateRegistration::principals()` = every account that can log in and holds `registration.approve`
  (`Admin\Ringkasan` warns only when it is empty).
- **No report deadline** (D-151). `ReportFollowUp` (§7); `SendReportReminders` (`CATCH_UP_DAYS` 7, `dueReminder(int)`;
  `whereDoesntHave('report')`; `--date` refused in production as before); `ReportReminder::$daysAfterFinal` (default 0;
  `dueOn` / `daysLeft` stay declared for payloads queued before D-151); `ReportSubmitted` without `late`;
  `ReportWindowOpened(string $registrationId, string $competitionName)`; `ReportHelpers::reportChip()`; `UpdateLimits::handle(User,
  int $archiveMonths)`; `Sekolah\Dashboard::overdueReports()` = `menunggu_laporan`, no report, `final_on` before today − 14.

## 9. Environments
- Dev: `docker compose up` → app (php-fpm 8.4 + nginx on 127.0.0.1:8080), mysql 8.4 (127.0.0.1:3307), mailpit (127.0.0.1:8025), vite, scheduler, queue worker. Because the repo is bind-mounted from a Windows drive, the dev php-fpm workers read a cached config and keep compiled views in the container's own filesystem (`docker/php/fpm.dev.conf`, `docker/php/dev-cache.sh`; pool env only, never the command line), so after editing PHP, Blade, `config/` or `.env` run `reload.bat`; the test suite refuses to run on a cached config (D-91).
- Prod: image from `docker/Dockerfile.prod` (multi-stage: composer → node build → php-fpm runtime, non-root), behind the host Apache 2.4.29 vhost `prestasi.sman3-smg.sch.id` → 127.0.0.1:8080. See SECURITY.md §3e/§3j.
