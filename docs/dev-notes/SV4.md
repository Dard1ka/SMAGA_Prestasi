# SV4 — kelas bernomor, siswa per kelas, tahun ajaran manual, data siswa dikosongkan (2026-11-04)

`smaga_server` only (Smaga4 unchanged). Decisions D-175…D-178 (DECISIONS §D27). Built by the coordinator (classes) and
three agents (Kelas dialog; Import year + sample lomba; delete-all command), then checked together.

## The lead's requests (4 Nov 2026) and the choices
1. "menonaktifkan siswa … kalo mencet kelas contoh kelas X-A, akan langsung menunjukan siswa yg ada di kelas itu dan bisa
   jg langsung menoaktifkan dari situ" → choice: a dialog per class, one student at a time (D-176).
2. "tambahkan opsi untuk menambahkan tahun ajaran, jadi bisa manual contoh tahun 2028" → "Tahun lain…" + "Tahun mulai" (D-177).
3. "data siswa hapus semua, ingat cuman siswa" → `sth:siswa:hapus-semua`; lomba stay ("Tetap ada … buat demo aja workflow
   secara keseluruhan yg lomba dummy tetap ada, sekalian buatkan satu lomba dummy dari awal") → `LombaContohSeeder` (D-178).
4. "kelas skrng fix adanya X-1 sampai X-12, XI-1 sampai XI-12, kelas XII-1 sampai XII-12 bukan X-A … kalo di import dua
   kali siswa akan ganti kelas" → numbered classes, empty other classes deleted (lead's choice) (D-175).

## What changed
- Classes: `ClassroomSeeder` (36 names), `App\Actions\Classrooms\NumberClassrooms` + `sth:kelas:nomor` (rename in place,
  merge into an Admin-made numbered class, create, delete empty others; audit `classroom.numbered`). Every lettered class
  name in `app/`, `resources/views`, `database/seeders` (+ demo), `tests/` was replaced by its number (891 places, a
  script with word boundaries; `X - A` letter format too). Left alone: the two migrations of 21 Okt 2026 and
  `KelasHurufTest` (it tests them), and `tests/Feature/Admin/KelasTest.php` (the Admin types arbitrary suffixes such as
  "K" there; the replacement had broken its expectations, so the file was restored). Two lowercase test rows (`xi-d`)
  were fixed by hand.
- Admin › Kelas: "Lihat siswa" dialog + "Nonaktifkan" (D-176), `tests/Feature/Admin/KelasSiswaTest.php` (20 tests).
- Import siswa: "Tahun lain…" (D-177); after the browser check the coordinator added `?tahun=…&tahun_mulai=…` to the
  address the password page returns to (the year choice used to fall back to the current school year there, also for
  listed years). `tests/Feature/Accounts/ImportSiswaTahunLainTest.php` (23 tests).
- `App\Actions\Students\DeleteAllStudents` + `sth:siswa:hapus-semua` (D-178), `tests/Feature/Students/DeleteAllStudentsTest.php`
  (9 tests incl. a schema guard over every foreign key).
- `database/seeders/LombaContohSeeder.php` + `tests/Feature/DataLayer/LombaContohSeederTest.php` (6 tests). Its juknis is
  stored under `lampiran/`, not `demo/` (a `demo/` file would switch on the "Data contoh" chip on the server).
- Docs: DECISIONS §D27, this note, CLAUDE.md §2 + §10, AKUN-UJI.md (local file).

## Deploy (school server, from `/opt/sth/app`, after the usual backups of code, database and `storage/app/private`)
1. Extract the package, `docker compose exec -T app sh docker/php/dev-cache.sh --force`, `docker compose restart queue scheduler`.
2. `php artisan sth:siswa:hapus-semua` (dry run) → `--terapkan`. Not while a student import runs.
3. `php artisan sth:kelas:nomor` (dry run) → `--terapkan`.
4. `php artisan db:seed --class=LombaContohSeeder --force`.
No migration, no new package, no asset build.

## Checks
- Laptop stack (2026-11-04): the three steps above on the trial data (9 students deleted, 34 classes renamed + X-11/X-12,
  sample lomba published; second runs change nothing). Browser (app's pane, Admin): Admin › Kelas shows X-1…XII-12 with
  "Lihat siswa"; Import siswa "Tahun lain…" 2028 → "Tahun ajaran 2028/2029", 2050 refused with "Tahun mulai harus antara
  2016 dan 2036."; after the password page the choice came back from the address; a two-row fictional CSV imported into
  X-1 with 2028/2029 (archive 2032-01-01); Kelas › X-1 › Lihat siswa › Nonaktifkan with a reason → "Nonaktif", "1 siswa
  aktif", the toast; no console error; the sample lomba on Kelola lomba. The two test students were deleted again.
- Tests: TEST-RESULTS below.

## TEST-RESULTS
- Full suite (`--parallel --processes=6`, before the two last Import changes below): **4397 passed, 1 skipped (27176
  assertions)**, exit 0, 1995 s. `pint --test`: PASS, 918 files (run again at the end: PASS).
- After the lead's last request ("Hapus Tahun saat tahun ajaran dimulai (Juli), dari 2016 sampai 2036. dan · Juli 2028
  sampai Juni 2029": the hint under "Tahun mulai" and the month range after "Tahun ajaran 2028/2029" are gone; the range
  still stands in the refusal sentence) and the year kept across the password page:
  `ImportSiswa|ImportQueue|Fs1QaCopyLayout|UploadErrorMessage|KesiswaanScreens|SearchKeepsTypedText|PageWiringTest|
  LivewireRootTest|WireModelSendsTest|CspSafeTest|ImportStudentsTest` → **248 passed (2156 assertions)**.
- Focused runs on the way: Kelas + KelasSiswa 86 passed; ImportStudentsTest 53 passed; ImportSiswaTahunLainTest 23 passed;
  DeleteAllStudentsTest 9 passed; LombaContohSeederTest 6 passed; AuditLogTest 19 passed.
- Not checked: a phone width of the new dialogs, the commands on MySQL with real-sized data (laptop: 9 trial students).

## Later the same day: "Ubah status" (D-179)
The lead's two requests after trying SV4 on the laptop: an undo for nonaktif / arsip / dibekukan / kedaluwarsa on Status
aktivasi, and changing the status of picked students on Admin › Kelas ("apapun, semuanya bisa"). Choices: ticks for
several students at once; a new archive date when re-activating a student whose archive date passed. Built by one agent:
`App\Actions\Accounts\EnableAccount`, the shared dialog `App\Livewire\Admin\UbahStatusSiswa` (+ view), Kelas (ticks,
"Pilih semua yang tampil", "Ubah status terpilih (N)", per-row "Ubah status"; SV4's "Nonaktifkan" and its dialog removed),
Status aktivasi (Admin: "Aktifkan lagi" / "Ubah status" per row). The coordinator added "Diaktifkan lagi" to the history of
Admin › Arsip otomatis (only when the archive date moved) and the docs.
Checks: the agent's runs (new + changed tests 87 passed; with Kelas, Status aktivasi, Pengguna, Arsip otomatis, Log audit
and the seven guards 360 passed); `ArsipOtomatisTest|ArsipQa9Test|EnableAccountTest` 42 passed; browser on the laptop as
Admin: Status aktivasi › "Aktifkan lagi" on a disabled student (password page, then "1 akun diubah.", the student back to
"Belum aktivasi", window kept, one `account.enabled` row), Kelas › X-1 › "Pilih semua yang tampil" → "Ubah status
terpilih (1)" → Nonaktif, then the row's "Ubah status" → Aktif kembali; no console error. Full suite: below.
Full suite after D-179 (`--parallel --processes=6`): **4470 passed, 1 skipped (27550 assertions)**, exit 0, 1510 s.
`pint --test`: PASS, 923 files.
