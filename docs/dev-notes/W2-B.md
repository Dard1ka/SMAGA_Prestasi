# W2-B — Kesiswaan screens (kelola lomba, verifikasi hasil, talenta, import siswa)  ·  status: done

## Files (owned by me)
- Livewire: `app/Livewire/Kesiswaan/{Beranda,VerifikasiHasil,Talenta}.php`, `Kesiswaan/Lomba/{Index,Form}.php`,
  `Kesiswaan/Concerns/WithKesiswaanBadges.php` (sidebar badges + date/size helpers), `app/Livewire/Akun/ImportSiswa.php`;
  views `resources/views/livewire/kesiswaan/{beranda,verifikasi-hasil,talenta}.blade.php`, `kesiswaan/lomba/{index,form}.blade.php`,
  `resources/views/livewire/akun/import-siswa.blade.php`.
- Actions: `Competitions/{CreateCompetition,UpdateCompetition,VerifyCompetition,CloseCompetition}`, `Reports/{VerifyReport,
  RequestReportRevision,RejectReport}`, `Achievements/RecordAchievements`, `Talent/{MoveTalentStage,RejectTalentApplication,UpdateTalentAssessment}`.
- Notifications (queued mail + database, afterCommit, scalars only; base `Verification/Notice`): `Competitions/{CompetitionPublished,
  CompetitionRejected}`, `Verification/{ReportVerified,ReportRevisionRequested,ReportRejected,AchievementReadyForPublication}`,
  `Talent/{TalentStageChanged,TalentApplicationRejected}`. Payload keys `kind,title,body,url` (+ ids), same as W2-A2.
- Policy methods added below the W2-B markers: `CompetitionPolicy::{manage,update,verify,close}`, `ReportPolicy::{viewQueue,verify}`,
  `TalentApplicationPolicy::{manage,move}`.
- Routes: `routes/staff.php` block `--- W2-B ---`. Tests: `tests/Feature/Kesiswaan/*` (+ `KesiswaanFixtures`).

## Contracts other areas can use
- Routes (`area.staff` + `role:kesiswaan|admin`): `kesiswaan.beranda` /kesiswaan · `kesiswaan.lomba.index` /kesiswaan/lomba?tab=katalog|verifikasi ·
  `kesiswaan.verifikasi.index` /kesiswaan/verifikasi-hasil · `kesiswaan.verifikasi.show` /kesiswaan/verifikasi-hasil/{report} ·
  `kesiswaan.talenta.index` /kesiswaan/talenta?tahap=&jenis=&cabang=&kelas=&q= · `kesiswaan.import` /kesiswaan/import-siswa ·
  `kesiswaan.apresiasi.index` /kesiswaan/apresiasi (mounts W2-C's `Humas\Apresiasi`). `admin.import` /admin/import-siswa (`role:admin`) lives in my block.
- `CreateCompetition::handle(User, array $input, ?UploadedFile $juknis, ?UploadedFile $cover): Competition` — always `menunggu_verifikasi` (D-27);
  `CreateCompetition::rules()/messages()/normalize()` shared with Update; `storeFile()` → `StoreUpload` (errors on `juknis`/`cover`).
- `UpdateCompetition::handle(User, Competition, array, ?juknis, ?cover, bool $removeCover)` — audit event `competition.weights_changed`; a published lomba must stay complete.
- `VerifyCompetition::handle(User, Competition, CompetitionStatus $decision /*Dipublikasikan|Ditolak*/, ?string $note)`; `::missingFields(Competition): list<string>`,
  `::REQUIRED_TO_PUBLISH`. UI-MAP's `RejectCompetition` = this Action with `Ditolak` (note required). `CloseCompetition::handle(User, Competition, ?note)`.
- `VerifyReport::handle(User, Report, ResultType $final, array $checks, ?note): Collection<Achievement>`; `VerifyReport::CHECKS` (4 keys, all required).
  One Achievement per confirmed, non-declined member, full points, active `WeightVersion` snapshot; report → diverifikasi; registration → selesai;
  one `publications` row (`siap`, created_by = verifier) **per member, consent is NOT filtered here — Humas must filter by publish_consent**.
- `RequestReportRevision::handle(User, Report, string $note)` → report perlu_revisi, registration → menunggu_laporan.
  `RejectReport::handle(User, Report, string $note)` → report ditolak, registration → **selesai** (only terminal transition allowed), no points.
- `MoveTalentStage::handle(User, TalentApplication, TalentStatus $to, ?array $details)` (only `status->next()`), `RejectTalentApplication::handle(User, TalentApplication, string $note)`,
  `UpdateTalentAssessment::handle(User, TalentApplication, array{mentor_id?,assessment_on?,note?})` (pembina must be an active guru).

## Commands verified
- `php artisan test tests/Feature/Kesiswaan` → 50 passed (actions 25, screens 25); `tests/Feature/{Humas,KepalaSekolah,Kesiswaan}` → 197 passed.
- Full suite (while other agents ran tests in parallel) → 883 passed, 5 failed: my import screen test (passes alone and in the
  197-test run; parallel runs share `storage/framework/testing/disks`), `Admin\SettingsActionsTest` (UnableToRetrieveMetadata, same cause?),
  `Auth\AuthPagesTest` placeholder home (302: area routes exist now) and 2× `DesignSystem\NavigationTest` (expects missing routes) — not mine.
- `./vendor/bin/pint` on all my files → pass. `docker compose run --rm node sh -c "npm run build"` → OK.
- Browser (dev stack, isolated context, demo `kesiswaan`): beranda, kelola lomba (katalog + antrean + drawer "Tambah/Periksa lomba",
  publish refused with "Lengkapi dulu: …"), verifikasi hasil (OKN/LKTI/Popda/Tari queue, rumus 40 × 1,00 × 0,20 = 8), talenta (10 rows, masked NIS,
  panel), import (3 steps) render without console errors; no horizontal page scroll at 1440 or ~500 px.

## Assumptions / deviations
- Kesiswaan "Tambah lomba" = `CreateCompetition` (full form) → queue; publishing is always a second step ("Simpan & publikasikan" in the
  drawer or "Publikasikan" in the queue card, verifier may be the same person; audited). Mockup's "Simpan draf/Publikasikan" pair replaced.
- UI-MAP `UpdateTalentStatus` = `MoveTalentStage` + `RejectTalentApplication` (+ `UpdateTalentAssessment` for pembina/date without stage change).
- `ImportSiswa` uses UI-MAP class `App\Livewire\Akun\ImportSiswa` (outside Kesiswaan/**; new file, nobody else owns it). Preview rows are kept in
  the cache (30 min, per user) instead of the Livewire payload; template = header row only (`No HP`, not "No HP (opsional)", so the importer maps it).
- Verification queue is FIFO (oldest first); reports of the verifier's own binaan are shown read-only with a notice (§3c-10).
- Stat "Pendaftar 30 hari" instead of "bulan ini" (on the 1st of a month it is always 0).

## Requests for other owners
- @W2-C (Humas): publication rows are created for every member; filter the queue by `students.publish_consent` (D-16).
- @W2-D (Navigation): `kesiswaan.prestasi.index` (Database prestasi) still has no screen — not in my brief; lead to assign.
- @S (UI-MAP): rows #16/#18/#23 — record the Action names above; add `kesiswaan.verifikasi.show`, `kesiswaan.apresiasi.index`.
- @lead: `RejectReport` ends the registration in `selesai` (tracker phase 6 without points); confirm or add a state.
