# W1-A — data layer (enums, migrations, models, factories, seeders)  ·  status: done

## Files (owned by me)
- `app/Enums/*.php` (27 enums + `Concerns/HasOptions`: `values()`, `options()` value=>label)
- `app/Models/*.php` (23 models, `User.php` rewritten) · `database/migrations/*` (users + activity_log edited, 14 new)
- `database/factories/*` (23) · `database/seeders/*` + `Support/DemoFiles.php` + `assets/ttd-stempel-contoh.png`
- `tests/Feature/DataLayer/{ModelFactoriesTest,SeedersTest}.php` (new folder, 40 tests)

## Models (domain: HasUlids, explicit `$fillable`; * = SoftDeletes; L = LogsActivity, only dirty attrs)
User* L (bigint; HasRoles, TwoFactorAuthenticatable; logs name/username/email/status only) · Student* · StaffProfile ·
Classroom · File (`App\Models\File`, table `files`) · Competition* L · Registration* L · RegistrationMember · Letter L ·
Report* L · ReportFile · Achievement L · WeightVersion L · WeightValue · Setting L (PK `key`) · TalentType · TalentBranch ·
TalentApplication* L · TalentProfile · Publication · AppreciationEvent · Appreciation · OtpCode.
Relations: `User::student()/staffProfile()/mentoredRegistrations()/mentoredTalentApplications()/otpCodes()` ·
`Student::user()/classroom()/memberships()/registrations()/ledRegistrations()/achievements()/talentApplications()/talentProfile()` ·
`Competition::registrations()/achievements()/defaultMentor()/submitter()/verifier()/juknisFile()/coverFile()` ·
`Registration::competition()/leader()/mentor()/principalDecider()/members()/students()(read-only)/letters()/studentLetter()/guruLetter()/report()` ·
`Report::registration()/files()/achievements()` · `Achievement::report()/student()/competition()/weightVersion()/publication()/appreciations()`.
Scopes: `Student::active()/alumni()/dueForArchive($today)/publishable()/search($term)/busyIn($competition)` ·
`Competition::published()/pendingVerification()/openForRegistration()/forBidang()` · `Registration::status(...)/awaitingPrincipal()/
awaitingLetters()/nonFinal()/forStudent()` · `Report::awaitingVerification()` · `Achievement::forBidang()/inSchoolYear('2025/2026')/
publicVisible()` · `User::canLogIn()/staff()/gurus()` · `OtpCode::usable()` · `TalentApplication::inStatus()`.
Helpers: `WeightVersion::active(): ?self`, `->toWeightTable(): WeightTable` · `Setting::get(SettingKey)` (falls back to
`SettingKey::defaultValue()`), `Setting::put()` · `Letter::lastSequence(LetterType, int $year)` · Letter fills `verification_code`
(32 hex) on create · `Classroom::levelFromName('XI-4')` · dates are `immutable_date/_datetime`, decimals are strings ("68.00").

**Not mass-assignable (CLAUDE.md §3.6) → `forceFill()` in Actions:** every `status`, `verified_by/_at`, `final_result`,
report `submitted_by/_at`, `principal_*`, `cancelled_at`, member `role/confirmed_at/declined_at`, achievement
`result/level/weight_category/w_*/points/weight_version_id/verified_at`, talent `mentor_id/assessment_on/note`, letter
`verification_code/generated_*/sent_at`, user `status/must_change_password/password_changed_at/last_login_at/email_verified_at`.
`shouldBeStrict()` makes `create([...])` with those keys throw `MassAssignmentException` (tested).

## Enums (string-backed, `label()` in Bahasa Indonesia)
Bidang (`cssKey()` sains/riset/seni/olahraga, `forTalentCode()`) · Level (`defaultWeight()`, `rank()`, `shortLabel()`) ·
WeightCategory A–E (`defaultWeight()`, `hint()`) · ResultType (`defaultWeight()`, `tier()` = Ranker tiers, `isPodium()`) ·
OrganizerType · EventStatus · EventMode · Participation (`allows(RegistrationType)`) · UserStatus (`canLogIn()`) ·
CompetitionStatus (`tone()`) · RegistrationStatus (`phase()` = RegistrationStateMachine, `isFinal()`, `nonFinal()`, `tone()`,
`phaseLabel(int)`) · RegistrationType · MemberRole · ReportStatus · ReportFileKind · LetterType (`formatSetting()`) ·
TalentStatus (`step()`, `next()`, `tone()`) · TalentTypeCode (`bidang()` D-22, `requiresBranch()`) · WeightGroup (`defaults()`) ·
PublicationStatus · PublicationChannel · AppreciationStatus · OtpPurpose · FilePurpose · SettingKey (`defaultValue()`) ·
RoleName (`defaultPermissions()`, `isStaff()`, `staff()`) · Permission.

## Permissions (`App\Enums\Permission`, guard `web`, matrix = `RoleName::defaultPermissions()`)
competition.{create,verify,update,close,delete} · registration.{create,approve,view_all} · letter.{generate,send,view} ·
report.{submit,verify} · achievement.{view_all,export} · publication.manage · appreciation.manage · talent.{apply,manage,assess} ·
student.{import,view,update} · user.{manage,reset_password,archive,replace_principal} · weight.manage · setting.manage ·
signature.manage · dashboard.view · audit.view. Admin = all except `registration.approve` (D-01). siswa: competition.create,
registration.create, report.submit, talent.apply. guru: competition.create, talent.assess.

## Seeders (`DatabaseSeeder`, WithoutModelEvents)
Role · Classroom (36) · Talent (6 types, 35 branches, team sizes 2/3/5) · Weight (1 version, effective 2024-07-01, only if none)
· Setting (missing keys only; deadline 45) · Signature (skipped in production) · Demo (APP_ENV=local only, skips if data exists).
Demo logins: staff username `kesiswaan`, `kepsek`, `tu`, `humas`, `operator` (admin), `retno.w`, `hendra.k`, `maya.a`, `agus.s`
(email `<username>@sth.test`), password `password` — dev only; no 2FA yet (W1-C setup flow applies). Students: NIS/NISN from
DESIGN-SPEC §5 (Nadia 225226201 / 0098765432, born 2009-03-02, fictional), initial password per D-24 via `InitialPassword`,
status pending_activation. 4 alumni archived. Demo placeholder PDFs live in `storage/app/private/demo/`.
Matches the mockup: leaderboard (Raka 312 … Nadia 104 = #14 active, #15 with alumni), Kepsek queue 5, TU queue 3 (approved
30 Sep), verification queue 4, usulan 3, last letter numbers 312 / 1680, Humas 3 siap / 2 terjadwal / 6 dipublikasikan, talent rows T2.

## Commands verified
- `docker compose run --rm app php artisan migrate:fresh --seed --force` (MySQL) → OK: 9 staf, 21 siswa, 68 lomba, 83 pendaftaran, 70 prestasi
- `docker compose run --rm --no-deps -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: … app php artisan test` →
  335 passed, 8 failed (all `ImportStudentsTest`, see Requests); `tests/Feature/DataLayer` → 40 passed (incl. demo seed on SQLite)
- `./vendor/bin/pint app/Enums app/Models database tests/Feature/DataLayer` → pass

## Assumptions / deviations
- Schema additions (please ratify in ARCHITECTURE §4): `users.username` (nullable unique; W1-C uses it), `users.email` nullable,
  `staff_profiles.honorific`, `competitions.description`, `registrations.branch` (cabang), `talent_*.sort_order`,
  unique `letters(registration_id,type)`, unique `publications.achievement_id`, `notifications` table,
  `activity_log.subject_id` string(64). Competition weight/date fields nullable (usulan) — publish Action must require them.
- D-25 wins over the mockup: WICE report due date = final 24 Sep + 45 = **8 Nov 2026** (mockup shows 24 Okt).
- Mockup-note conflicts resolved: Citra NIS 225226073 (T2), Raka/Bima/Yoga use the XII pattern 224225xxx, Galang in X-TAHFIDZ.
- Catalog "N siswa mendaftar" and dashboard sample totals (93 prestasi / 61 siswa) are not reproduced (queues + leaderboard are).
- Invented: Laras `ditolak`, Rizky `dibatalkan`, LPB 2026 team Farhan+Rizky+Keisha `menunggu_konfirmasi`; alumni Putri 85,2 / Bagas 100,8.
- No `surat_terbit`/`berlangsung` rows (they would need letter numbers > 312). WeightValue is not activity-logged (the version is).

## Requests for other owners
- **@lead / phpunit.xml owner (urgent):** compose `env_file: .env` exports APP_ENV=local + DB_CONNECTION=mysql into the container
  and phpunit `<env>` does not override them without `force="true"`. So `docker compose run --rm --no-deps app php artisan test`
  runs RefreshDatabase against the **dev MySQL DB and wipes it**, and ~25 auth tests fail (419 CSRF, env checks). Add
  `force="true"` to the `<env>` entries (APP_ENV, DB_*, CACHE_STORE, SESSION_DRIVER, QUEUE_CONNECTION, MAIL_MAILER, BCRYPT_ROUNDS).
- @W1-C: `tests/Feature/Accounts/ImportStudentsTest` fails 8× in the test itself ("Indirect modification of overloaded property
  Pest\Support\HigherOrderTapProxy::$tmpFiles"). OLPAIN (branch NULL) duplicates must be checked in the talent Action.
- @W1-C / wave 2: production seeds no accounts — a `sth:create-admin` command (or similar) is needed for the first Admin.
