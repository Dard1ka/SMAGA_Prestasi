# SV5 — Ubah status with six states, Sampah for classes and students, purge (2026-11-05)

`smaga_server` only (Smaga4 unchanged: D-166 and D-179 stay there). Decisions D-180…D-182 (DECISIONS §D28). Not
committed (base commit `8c5947d` on `smaga-server`). Built by the coordinator and several agents (Ubah status; Sampah and
purge; leak fixes per area), then reviewed together. Note: code comments also say "SV5" for the first "Ubah status"
of 2026-11-04 (D-179), which the docs log under SV4.

## The lead's requests (5 Nov 2026) and the choices
1. "bisa hapus kelas dengan memberi notifikasi jika hapus kelas maka akan menghapus seluruh data siswa. Namun berikan juga
   opsi tambahan backup jika kelas tidak sengaja terhapus yang akan terhapus secara total selama 7 hari setelah konfirmasi
   hapus" → "Hapus" puts a class with all its students into a 7-day "Sampah" with "Pulihkan" (D-181), then a nightly
   permanent delete (D-182).
2. "Ubah statusnya di bagian admin lalu menu kelas itu bisa segala status … dan siswa dan kelasnya bisa dihapus oleh
   admin" → "Ubah status" offers the six states of Status aktivasi (D-180); single students can be deleted into the
   Sampah too (D-181).
3. Then a leak pass: a student in the Sampah must never show, count, get mail or get points anywhere.

Coordinator's choices (*sementara*, open for the lead, see "Open questions"): the purge moment, the password on
"Pulihkan", the reason shown in the Sampah list, a trashed NISN in the import is "Dilewati", and "Aktif" for a student
who never activated.

## What was built

### Ubah status: six states (D-180)
- `App\Livewire\Admin\UbahStatusSiswa` + `resources/views/livewire/admin/ubah-status-siswa.blade.php`: targets =
  `AccountControls::STATE_*` (Belum aktivasi, Aktif, Kedaluwarsa, Dibekukan, Nonaktif, Diarsipkan) as `x-sth.radio-pick`
  tiles, three per row; `step()` / `apply()` walk each student through a chain of Actions (at most `MAX_STEPS` = 5), one
  transaction per student; the field "Kata sandi sementara" (`statusTemporaryPassword`, min 8) only when a picked student
  is reset (aktif → Belum aktivasi or Kedaluwarsa), never shown back, forgotten after "Simpan".
- New Actions in `app/Actions/Accounts`, all Admin only (`user.archive`), never the own account, student accounts only,
  reason 10–500, nobody notified:
  - `ActivateStudentAccount` → `account.activated_by_admin`: `pending_activation` (not frozen) → `active`,
    `initial_password_expires_at` = null, K3 counter reset; `must_change_password` and the email stay, so the next login
    still asks email → code → new password. `CompleteActivation::stampActivatedByAdmin()` writes the missing
    `activated_at` once those steps are done (also through "Lupa kata sandi"), without an audit row.
  - `ExpireActivationWindow` → `account.activation_expired_by_admin`: `initial_password_expires_at` = now for a pending
    student on an initial or temporary password; sessions ended.
  - `FreezeAccount` → `account.frozen_by_admin`: `frozen_at` = now, `frozen_reason` = `admin`
    (`AccountControls::FROZEN_ADMIN`, label `FROZEN_ADMIN_LABEL` "Dibekukan Admin"); sessions and remember token revoked.
- `ReopenActivationWindow` refuses an Admin freeze ("Akun dibekukan Admin. Hanya Admin yang bisa membukanya.");
  `ActivationReport::FLAG_FROZEN_ADMIN`; `Akun\StatusAktivasi` names the freeze reason in words in "Akun dibekukan";
  `EnableAccount` comments say "Aktif" (formerly "Aktif kembali").

### Sampah (D-181)
- Migration `2026_11_05_100000_create_trash_entries_and_soft_delete_classrooms`: `classrooms.deleted_at` and the table
  `trash_entries` (ULID, `kind` classroom|student, `classroom_id`, `user_id`, `parent_id`, `deleted_by`, `deleted_at`,
  `purge_after`, `reason`, `restored_at` / `restored_by`, `purged_at`; ids only, no foreign keys on purpose). Forward-safe,
  no data changed.
- `App\Models\TrashEntry` (`KEEP_DAYS` 7, `PURGE_TIME` 02:00, `purgeAfterFor()`, scopes `open()` / `due()`,
  `isRestorable()`), `App\Policies\TrashEntryPolicy` (`classroom.manage` = Admin), `Classroom` uses `SoftDeletes`.
- `app/Actions/Trash`: `TrashStudents` (`student.trashed`, at most 400 per call), `TrashClassroom` (`classroom.trashed`,
  the class + every student of any status as child entries), `RestoreFromTrash` (`classroom.restored` /
  `student.restored`; refuses a student whose class is still in the trash, and anything from `purge_after` on),
  `TrashReason` (10–500). Rows are written with the query builder, so the User model's own activity log (which logs the
  name) stays silent; every audit row holds ids and counts, never a name or NISN.
- `App\Livewire\Admin\Kelas` + `kelas.blade.php`: "Hapus" on every class row (dialog `kelas-hapus`, names the student
  count and the purge date), "Hapus" per row and "Hapus terpilih (N)" in "Lihat siswa" (dialog `kelas-siswa-hapus`), the
  section "Sampah" (`TRASH_STEP` 20, `TRASH_MAX` 200; who, when, why, "Terhapus permanen <tanggal>") with "Pulihkan"
  (dialog `kelas-pulihkan`). Reason + recent password for a delete, recent password for "Pulihkan"; stale tabs get a
  toast (CLAUDE.md §6).
- Classes: `ClassroomName` keeps a trashed name taken (`inTrash()`), `DeleteClassroom` (no caller on a screen any more)
  and `NumberClassrooms` use `forceDelete()`, and `sth:kelas:nomor` leaves a trashed class alone.
- Import: `ImportStudents::NOTE_IN_TRASH`: a row whose NIS and NISN belong to a trashed student is skipped
  ("Dilewati"); `StudentImportReport`, `Akun\ImportSiswa` + view count those rows.

### Purge (D-182)
- `App\Actions\Trash\PurgeTrash` + `App\Console\Commands\PurgeTrashCommand` (`sth:sampah:hapus-permanen`, scheduled in
  `routes/console.php` daily 02:00 Asia/Jakarta, `withoutOverlapping(60)`, `onOneServer()`); exit 1 when a group stays.
- `App\Actions\Students\PurgeStudents::handle(array $studentIds)`: the sibling of `DeleteAllStudents` (D-178) for a set
  of students. Mixed teams stay (only the set's member rows go; a purged ketua hands over to the first remaining member,
  `reports.submitted_by` and the report files' owner follow); a lomba a purged student proposed moves to the first
  active Admin; nullable user columns of kept rows are emptied (`USER_REFERENCES_NULLED`); refusals
  (`USER_REFERENCES_BLOCKING`, no active Admin, no member to take over) throw a `DomainException` with counts only and
  write nothing. `letters.printed` of a kept letter is not touched. File bytes go after the commit. No audit row of its
  own: `PurgeTrash` writes one `trash.purged` per run (counts only, `via` cli).

### Audit keys (labels in `Admin\AuditLog`, `Admin\Ringkasan`, `ExportAuditLog`)
`account.activated_by_admin` "Diaktifkan Admin", `account.activation_expired_by_admin` "Masa aktivasi diakhiri Admin",
`account.frozen_by_admin` "Dibekukan Admin", `student.trashed` "Siswa dipindahkan ke Sampah", `classroom.trashed` "Kelas
dipindahkan ke Sampah", `student.restored` "Siswa dipulihkan dari Sampah", `classroom.restored` "Kelas dipulihkan dari
Sampah", `trash.purged` "Sampah dihapus permanen" (Log audit only; Ringkasan lists Admin actions, not the nightly run).
`SecurityEventSubscriber` lists the trash events as security events.

## Leak fixes: a trashed student never shows, counts, gets mail or points
A trashed student keeps its `status`; the SoftDeletes scopes hide its `students` / `users` rows from plain queries, but
relations that read `withTrashed()` (e.g. `Achievement::student()`), joins and queued notifications do not. Fixed:
- **Login and mail:** `User::canLogIn()` is false for a trashed account; `User::routeNotificationForMail()` returns null;
  `BellChannel::send()` writes no bell row for a trashed notifiable (a notice queued before the trash is dropped);
  `CompleteActivation::bindEmail()` / `ChangeEmail` check the email `withTrashed()` (a trashed student's address stays
  taken, with the normal message); `UpdateLimits` recalculates trashed students' archive dates too.
- **Queues and counts:** `Registration::scopeWithListedLeader()` / `hasListedLeader()` (a trashed ketua's registration
  leaves `awaitingPrincipal()`, `awaitingLetters()`, `Report::awaitingVerification()`, the school dashboard and the
  sidebar counts; back on "Pulihkan"); `Kepsek\Persetujuan`, `TataUsaha\SuratTugas` (old link → queue with a toast),
  `TataUsaha\ArsipSurat` (names only students outside the trash), `Kesiswaan\VerifikasiHasil` (decision on a row whose
  ketua went to the trash = toast; trashed anggota dropped from the team), `Kesiswaan\Lomba\Index` counts,
  `Sekolah\Dashboard`, `Admin\Ringkasan`.
- **Teams:** `ConfirmMembership::onlyPeople()` / `peopleAmong()` / `leaderIsTrashed()`: a trashed member is no person
  of the team (an unanswered trashed invitee does not hold it back, a confirmed one does not make it big enough), a team
  whose ketua is in the trash never moves on; `ExpirePendingInvitations` and `ResumeWaitingRegistrations` follow the
  same rule (no invitation to a trashed anggota; a trashed ketua's waiting registration stays waiting).
  `Siswa\Beranda`, `Siswa\LombaSaya\Index`, `Siswa\Pendaftaran\Form`, `Siswa\KonfirmasiTim`, `LombaPresenter`: no blank
  row for a trashed teammate, a trashed ketua's registration and invitation hidden, its invitation link answered as no
  longer valid.
- **Letters:** `LetterContent` prints no member in the trash on a new letter.
- **Points and verification:** `VerifyReport` / `RecordAchievements` give a trashed member no achievement, no points and
  no notice; `BinaanReportDecided` names only members outside the trash to the guru ("Siswa binaan" when none is left).
- **Guru:** `Guru\Binaan`, `Guru\BinaanDetail` (404 once the whole team is in the trash), `Guru\Lomba`.
- **Humas:** `Humas\Apresiasi`, `RecordAppreciations`, `ReleaseAppreciation`, `DeleteAppreciationEvent`,
  `PublishPublication` (nobody in the trash told or counted; rows hidden on the event card), `Humas\ArsipFoto`.
- **Lists, public pages, exports:** `Achievement::scopeOfListedStudent()`, `AchievementFilters`,
  `Kesiswaan\DatabasePrestasi`, `ExportPrestasiData`, `PublicAchievements` (no anonymous row either), Status aktivasi,
  Talenta, the Kelas counts.

## Tests
New: `tests/Feature/Accounts/AdminStudentStatusActionsTest.php` (11), `tests/Feature/Admin/SampahTest.php` (32),
`tests/Feature/Students/PurgeStudentsTest.php` (11), `tests/Feature/Trash/` (`TrashedAccountsMiscTest`,
`TrashedAppreciationTest`, `TrashedNotificationsTest`, `TrashedQueuesTest`, `TrashedRegistrationFlowTest`,
`TrashedStudentScreensTest`, `TrashedVerificationTest`). Changed: `UbahStatusSiswaTest`, `StatusAktivasiUbahStatusTest`,
`EnableAccountTest`, `KelasTest`, `ImportStudentsTest`, `AuditLogTest`, `RingkasanSettingsEventsTest`,
`SecurityLogTest`.

## Deploy (school server, from `/opt/sth/app`; steps of `PANDUAN-DEPLOY-SERVER.md` part E, release notes in part G)
1. Backups first (E4: code, database, `storage/app/private`).
2. Extract the package (E5).
3. **Migration: yes** (E6a, only after the E4 backup): `docker compose exec app php artisan migrate --force`. One
   nullable column and one new table, no data changed.
4. E7: `docker compose exec -T app sh docker/php/dev-cache.sh --force`, then `docker compose restart queue scheduler`.
5. **The `scheduler` container must be `Up`** (`docker compose ps`): without it nothing in the Sampah is ever purged at
   02:00. `php artisan sth:sampah:hapus-permanen` may also be run by hand (idempotent; prints counts only).
6. `scripts/deploy/grants.sh` only on a production stack (`docker/compose.prod.yaml` with the runtime user `sth_app`);
   the school server runs `compose.yaml` + `compose.server.yaml` and needs no grant.
No new package, no container change, no RoleSeeder run (`classroom.manage` and `user.archive` already exist), no asset
build.

## Open questions for the lead
1. **"Aktif" for a student who never activated** (D-180, *sementara*): the K1 window ends and the K3 freeze no longer
   applies (it covers pending accounts only), so until the student replaces it the birth-date initial password (D-173)
   works without a deadline and without a freeze after 10 failures; only the progressive `LoginLockout` remains. Keep,
   or keep the window and the freeze until the password is replaced?
2. **Purge moment** (D-181): the first 02:00 run after 7 full days (deleted Monday 10:00 → gone Tuesday of the next week
   02:00). Or exactly 7 × 24 h, or midnight of day 7?
3. **"Pulihkan"** asks for the Admin's password (no reason). Also a reason?
4. **The delete reason** is shown in the Sampah list to every Admin. Fine?
5. **Import of a trashed student** (same NIS and NISN): the row is "Dilewati". Or restore the student automatically?
6. **Known gap** (D-182): after "Pulihkan" or a purge a team can stay in `menunggu_konfirmasi` with no way on (a trashed
   ketua's team never moves while it is in the trash, also when the last invitation is answered, and nothing checks it
   again after "Pulihkan"; a team whose only unanswered invitee is purged is not moved on either). The ketua can still
   cancel. Should "Pulihkan" and the purge move such a team on?
7. Issued letters of a mixed team keep the purged student's name and NIS in `letters.printed` and the PDF (D-108: a
   letter keeps what it printed). Fine?

## Not checked
- The purge on MySQL with real-sized data and the 02:00 run on the school server.
- A phone width of the new dialogs and the Sampah section.

## TEST-RESULTS
Final run, 5 Nov 2026: `php artisan test --parallel --processes=12` → `Tests: 1 skipped, 4660 passed (29003 assertions)`,
1225 s; `./vendor/bin/pint --test` → pass (946 files). On the local MySQL 8.4: the migration ran (744 ms), and a rolled-back
smoke run trashed a class with one student (hidden, cannot log in, purge 13 Nov 2026 02:00), opened 20 Admin, Kesiswaan
and public pages without an error, and restored it (the student can log in again).
