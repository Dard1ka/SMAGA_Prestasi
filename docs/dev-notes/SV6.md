# SV6 — Bigger stamp, registered students in the import, one set of student actions, Ubah data siswa, Hapus permanen, Koreksi with the old number (2026-11-05)

`smaga_server` only (Smaga4 unchanged). Decisions D-183…D-188 (DECISIONS §D29). Not committed (base commit `49ab3ab` on
`smaga-server`). Built by the coordinator and several agents in parallel after a read-only investigation of the lead's
requests. One part is not complete: the "Hapus permanen" button of D-186 follows in the next release (its Action, rules and
tests are in this one).

## The lead's requests (5 Nov 2026) and the choices
1. Letter templates ("Dasar no 4 salah", the student letter "sama persis" as the school's file): **no change**, the lead:
   "skip". The investigation found the guru texts identical to the school's file; the student letter differs on purpose
   since D-154 / D-157.
2. "Stempel tanda tangan pada tiap surat dibesarkan sedikit, nabrak atau kena tulisan gpp" → D-183.
3. Import siswa: show which registered student a row matches → D-184; the school-year algorithm stays ("algoritma tahun
   ajarannya biarkan saja balikkan menjadi seperti awal"); then (18:20) "kembalikan namanya jadi tahun ajaran tpi bukan
   2026 melainkan 2026/2027 saja dulu untuk saat ini lalu dibawahnya kasih opsi pilihan kelas yang pilihannya hanya X,
   XI, XII": the field reads "Tahun ajaran" with only "2026/2027" (no "Tahun lain…", no typed year), and a required
   "Kelas" X / XI / XII below it; the lead chose "Cek tingkat file" (a row of another tingkat is refused).
4. Status aktivasi with the student actions of Admin › Kelas, and the Excel that came out empty → D-185. The lead chose
   "Admin dan Kesiswaan" for deleting students into the Sampah.
5. "Hapus permanen" now instead of waiting 7 days → D-186 (may ship in a follow-up package).
6. Staff edit a student's data → D-187; the lead chose a TU page "Data siswa".
7. Koreksi of a surat tugas: no orange warning, the old number usable again, the old data pre-filled and tinted → D-188.

## What was built

### Signature + stamp (D-183)
- `resources/pdf/layout.blade.php`: `.s-ttd .gambar img { height: 79.25pt; position: relative; top: -9.15pt; }` and
  `.g-ttd .gambar img { height: 75.4pt; position: relative; top: -8.7pt; }` (the Lampiran inherits `.g-ttd`). The
  `.gambar` boxes keep 60.95 pt / 58 pt, so no line moves and the page count stays (dompdf 3.1.6 supports
  `position: relative` on a block child).
- `resources/views/livewire/tata-usaha/partials/ttd.blade.php`: the 52 px slot stays; the dashed placeholder lies over it
  8 px higher and lower (68 px). TU still never sees the real image.
- Tests: `tests/Feature/Letters/LetterLayoutTest.php` (the new `img` rules, the unchanged box heights, page counts).
- Letters issued before keep the old size; a stamp image needs a transparent background to keep the text under it legible.

### Import siswa names the registered student (D-184)
- `App\Actions\Accounts\ImportStudents`: every report row whose NIS / NISN points to a registered student carries
  `matches` (users.id list: the matched student, or the one or two owners of a collision, `matchIds()`); rows taken by
  another import meanwhile name what that import wrote (`markTaken($report, $matches)`). The ids live in the server-side
  preview cache only: never in the Livewire payload, "Download baris yang perlu dicek" or an audit row.
- `App\Livewire\Akun\ImportSiswa`: `registeredLines()` (read at every render) prints "Terdaftar: <nama> · <kelas> · NISN
  <nisn> · <status>" (classes in the Sampah included; a trashed student "Di Sampah, terhapus permanen <tanggal>");
  `showRegistered(int $row, int $slot = 0)` takes the row NUMBER, finds the id among the listed rows of the cached
  preview and opens `UbahDataSiswa` (needs `student.import` and `student.update`); a trashed or purged student gets
  `REGISTERED_TRASHED` / `REGISTERED_GONE`; Admin gets a link to `#h-kelas-sampah` (`TRASH_ANCHOR`) in a new tab,
  Kesiswaan `ASK_ADMIN_RESTORE`; `studentDataSaved()` (on `UbahDataSiswa::DONE_EVENT`) only toasts
  `STUDENT_DATA_SAVED`.
- The year field "Tahun ajaran" offers only "2026/2027" (*sementara*): no "Tahun lain…", no typed year
  (`yearOptions()`, view, `ImportSiswaTahunLainTest`). `GraduationCalculator` and `SchoolYear`
  are unchanged.
- Below it a required select "Kelas" with X / XI / XII: the file may hold students of that tingkat only; a row of
  another tingkat is refused (Ditolak) with "Kelas <nama kelas> bukan kelas <X|XI|XII>. File ini untuk kelas
  <X|XI|XII> saja." (`Akun\ImportSiswa`, `ImportStudents`, view, tests).
- Tests: `ImportStudentsTest` (matches), `tests/Feature/Accounts/ImportSiswaTerdaftarTest.php`.

### Status aktivasi: same student actions, Kesiswaan may delete, Excel (D-185)
- Permission `student.trash` (`App\Enums\Permission::StudentTrash`): Admin (all) and Kesiswaan (`RoleName`).
  `TrashEntryPolicy::trashStudents()` = `student.trash` or `classroom.manage`; `TrashStudents` authorizes it. `create()`
  (a class), `viewAny()` (the Sampah list) and `restore()` stay `classroom.manage`.
- `App\Livewire\Admin\HapusSiswa` + `resources/views/livewire/admin/hapus-siswa.blade.php`: the shared "Hapus siswa?"
  dialog (OPEN_EVENT / DONE_EVENT, like `UbahStatusSiswa`), rendered once on Admin › Kelas and on Status aktivasi;
  open() re-checks the ids (student accounts not in the trash, at most 400), asks the recent password; the toast after a
  Kesiswaan delete says the Admin can restore within 7 days. Kelas's own `kelas-siswa-hapus` dialog is gone.
- `App\Livewire\Admin\Concerns\SelectsListedStudents` (the ticks and the listed-id checks, taken from Kelas) and the
  partials `resources/views/livewire/partials/siswa-aksi.blade.php` (Ubah data · Ubah status / Aktifkan lagi · Hapus),
  `siswa-pilih.blade.php` (Pilih semua yang tampil, Ubah status terpilih (N), Hapus terpilih (N)),
  `siswa-cek.blade.php` (the row tick). `Akun\StatusAktivasi` gained `askTrashStudent()`, `askTrashSelected()`,
  `askData()`, `askStatusSelected()`, `toggleAllStudents()`, `exportClass()`; `askStatus()` now checks the listed rows.
- `App\Actions\Accounts\ExportActivationReport` (moved out of `StatusAktivasi::export()`): `student.view`, the count
  first (0 rows → null, `MSG_EMPTY` "Tidak ada siswa untuk pilihan ini.", no file, no audit), audit
  `account.activation_report_exported` with tingkat, class name, status filter, search used yes/no, screen, row count;
  `LargeExportNotice` above the limit. `ActivationReport::writeXlsx($rows, $path, ?int $level, ?string $classroom)`:
  a class = one sheet named after it (`sheetName()`), a tingkat = one sheet, the whole school = `Ringkasan` first (per
  class: Kelas, Jumlah siswa, the six states; total row "Semua kelas") and only the tingkat sheets with rows.
- Downloads: Status aktivasi (both tabs, the class dialog footer), Admin › Kelas (`exportAll()` "Download semua kelas",
  `exportClass()` "Download Excel" in Lihat siswa). The password page resumes the download by itself.

### Hapus permanen per Sampah entry (D-186)
- `App\Actions\Trash\PurgeFromTrash` (`TrashEntryPolicy::forceDelete`, `classroom.manage`): one open top-level entry; a
  class entry also takes its open children and the open single-student entries of that class (else the foreign key
  keeps the class); the typed class name is compared with `ClassroomName::key()` (`MSG_CONFIRM_NAME` "Ketik nama kelas
  persis seperti di judul."); a student entry needs nothing typed.
- `PurgeStudents::handleWithin($ids, $before, $after)`: the caller's lock + re-check run first and the entry close +
  audit after the deletes, in the same transaction (the lock order of `RestoreFromTrash`); file bytes go after the
  commit. One audit row `trash.purged_by_admin` (log `account`, the Admin as causer, ids and counts only). A refusal
  writes nothing and the entry stays restorable. `PurgeTrash` (02:00) is unchanged in behaviour.
- Tests: `tests/Feature/Trash/PurgeFromTrashTest.php`, `PurgeStudentsTest`.
- Admin › Kelas › Sampah: "Hapus permanen" next to "Pulihkan", dialog with the recent password and, for a class, the
  typed class name and the students it takes; Log audit label. (the on-screen button follows in the next release; the Action, rules and tests are in)

### Ubah data siswa (D-187)
- `App\Actions\Students\UpdateStudentData` (rules, uniqueness incl. the Sampah, class / archive dates, the initial-password
  re-hash, one transaction with the snapshot check, one audit row `student.data_updated`, `PublicAchievements::flush()`).
- `App\Livewire\Akun\UbahDataSiswa` + `resources/views/livewire/akun/ubah-data-siswa.blade.php`: the shared dialog
  (`OPEN_EVENT` with one user id, `DONE_EVENT`, `#[Locked]` id and snapshot, password asked on open and checked on save,
  D-128 silent no-op without open()).
- Hosts: `Admin\Kelas`, `Akun\StatusAktivasi`, `Akun\ImportSiswa`, `Admin\Pengaturan\Pengguna` (`openStudentData()`,
  `refreshStudentData()`, button "Ubah data" on the Bantuan akun siswa card), and the new `App\Livewire\TataUsaha\DataSiswa`
  + `resources/views/livewire/tata-usaha/data-siswa.blade.php` (route `tu.siswa.index` `/tu/data-siswa` in
  `routes/staff.php`, TU menu group "Data" › "Data siswa" in `App\Support\Navigation`).
- `StudentPolicy::update()` (`student.update`, not a siswa, student not trashed, has an account); `RoleName`: Kesiswaan and
  Tata Usaha get `student.update` (Admin already had it; the permission was unused since D-162).
- `InitialPassword` docblock and CLAUDE.md rule 3 name the new place that hashes `forBirthDate()`.
- `SecurityEventSubscriber::AUDITED_EVENT_PREFIXES` += `student.data_updated`; Log audit label "Data siswa diubah".
- Tests: `tests/Feature/Students/UpdateStudentDataTest.php`, `tests/Feature/Students/UbahDataSiswaTest.php`,
  `tests/Feature/TataUsaha/DataSiswaTest.php`.

### Koreksi with the old number (D-188)
- Migration `database/migrations/2026_11_05_180000_sv6_letters_number_free_after_revoke.php`: virtual generated column
  `letters.active_full_number` (`CASE WHEN revoked_at IS NULL THEN LOWER(REPLACE(full_number, ' ', '')) ELSE NULL END`),
  unique `(type, active_full_number)` created before the old unique `(type, full_number)` is dropped; refuses to run on
  duplicate active numbers; `down()` only while no number was used again.
- `IssueLetters`: the number check leaves out revoked letters and the letter a Koreksi replaces; the old row is revoked
  before the new one is inserted. `RevokeLetter` docblock.
- `TataUsaha\SuratTugas` (`SAME_LINE`) + view, `x-sth.input` (`bg-blue-bg`): no warning box on a Koreksi; every field
  starts with what the old letter printed and stays light blue while unchanged, with "Isian berwarna biru masih sama
  dengan surat sebelum dikoreksi.".
- Tests: `tests/Feature/TataUsaha/KoreksiSuratTest.php`, `SuratTugasTest`, `RevokeLetterTest`, `HapusAntreanTest`.
 

### Also
- `.gitignore`: `/Template surat/` (the school's own letter files with real names, NIS and NIP, CLAUDE.md §3.2).

## Permissions (re-run `RoleSeeder` on every database)
| permission | Admin | Kesiswaan | Tata Usaha | new? |
|---|---|---|---|---|
| `student.trash` (students into the Sampah) | yes | yes | no | new (D-185) |
| `student.update` (Ubah data siswa) | yes | yes (new) | yes (new) | existing, unused since D-162 (D-187) |
| `classroom.manage` (class delete, Sampah list, Pulihkan, Hapus permanen) | yes | no | no | unchanged |
| `user.archive` (Ubah status) | yes | no | no | unchanged |

Until `RoleSeeder` runs, Kesiswaan gets no "Hapus" and no "Ubah data", TU gets 403 on Data siswa; Admin keeps working
(`trashStudents()` also accepts `classroom.manage`).

## Migrations
- `2026_11_05_180000_sv6_letters_number_free_after_revoke` (D-188): adds a virtual column and swaps a unique index on
  `letters`; no row changes. Production: E6a after the E4 backup. The runtime user needs no new grant (no new table).

## Server steps
1. Backup (E4), then the code (package), then `docker compose exec app php artisan migrate --force` (E6a).
2. `docker compose exec app php artisan db:seed --class=RoleSeeder --force` (E6c, **required**: `student.trash`,
   `student.update` for Kesiswaan and TU).
3. `reload.bat` (or `docker compose exec -T app sh docker/php/dev-cache.sh --force`), then restart `queue` + `scheduler`.
   No container rebuild.
4. Browser checks: see PANDUAN-DEPLOY-SERVER part G.

## Open questions for the lead
1. Import "Tahun ajaran": when should years other than 2026/2027 come back (next school year 2027/2028 in July 2027)?
   Until then a file of another school year cannot be imported. A school file with several tingkat must be split into one
   file per tingkat (the "Kelas" choice).
2. D-186: purge one entry only, or also "Kosongkan Sampah"? (not built.)
3. D-187: a bell notice to the student when the NISN changes? (today nobody is told.)
4. Kesiswaan deleting students: the Sampah and "Pulihkan" stay Admin only; is that enough, or should Kesiswaan see the
   Sampah too?
5. Letters already issued keep the smaller stamp; reissue (Koreksi) only where the lead wants the bigger one.

## TEST-RESULTS
See final run (the coordinator records the full Pest run and Pint after all SV6 parts are merged).
