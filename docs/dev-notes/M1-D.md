# M1-D — Talent pipeline: two addressed notes, "Tidak lolos" can be undone  ·  status: done (not migrated, no browser run)

Lead, 2026-10-04, items 14 + 15 (Kesiswaan › Talenta siswa, Gambar 12/13). The lead's wishes override older log entries.

## Files (owned by me)
- `database/migrations/2026_10_04_130000_m1d_add_notes_and_review_to_talent_applications.php` — 4 nullable columns + data backfill
- `app/Models/TalentApplication.php` (casts, `restoreTarget()`, `hasOpenReviewRequest()`, scope `reviewRequested()`), `app/Enums/TalentStatus.php` (`isRestorable()`)
- `app/Actions/Talent/`: new `TalentNote`, `RestoreTalentApplication`, `RequestTalentReview`; changed `MoveTalentStage`, `RejectTalentApplication`, `UpdateTalentAssessment`, `ApplyTalent`
- `app/Policies/TalentApplicationPolicy.php` — new abilities `restore`, `requestReview`
- `app/Notifications/Talent/`: new `TalentApplicationRestored`, `TalentReviewRequested`; changed `TalentStageChanged`, `TalentApplicationRejected`
- `app/Livewire/Kesiswaan/Talenta.php` + view, `app/Livewire/Siswa/ProfilTalenta.php` + view, `app/Livewire/Guru/Binaan.php` + view (Talenta tab only)
- `resources/views/components/sth/icon.blade.php` — one new `@case('recycle')`, nothing else
- `database/factories/TalentApplicationFactory.php` — states `rejected($from, $note)`, `reviewRequested($message)`
- Tests: `tests/Feature/Kesiswaan/TalentActionsTest.php` (rewritten), `KesiswaanScreensTest.php` (talenta cases), `tests/Feature/Siswa/Talenta/{ProfilTalentaTest,ApplyTalentTest,TalentPoliciesTest}.php`, new `RequestTalentReviewTest.php`, `tests/Feature/Guru/GuruAreaTest.php` (+1 talenta case)

## What changed and why
- **Two notes, both optional.** `talent_applications.note` stays and now means only *catatan untuk guru pembina* (internal: guru pembina + Kesiswaan; shown to the guru in the Talenta tab, in full up to 300 characters, also on phones). New `student_note` = *catatan untuk siswa*: it belongs to one decision (lolos, tidak lolos, dipulihkan), is sent in that notification (bell + mail) and shown on Profil talenta next to the choice. A new decision replaces it (no note = cleared), so an old note is never sent twice. "Simpan" never stores a student note. A rejection needs no note any more (panel and bulk bar).
- **Existing data.** The old single note was mailed to the student on a rejection, so the note of every `tidak_lolos` row moves to `student_note`; all other notes were never shown to a student and stay in `note`. `rejected_from` is filled from the audit log (`talent.rejected`, property `from`) where it exists. `backfill()` is public and idempotent (tested).
- **Pulihkan.** `RejectTalentApplication` stores the stage in `rejected_from`. `RestoreTalentApplication` (policy `restore` = `talent.manage`, transaction + lock, audit `talent.restored` with `from`/`to`/`review_requested`) puts the row back at that stage, or Terdaftar when unknown, keeps pembina/date/internal note, closes an open review request and notifies the student. UI: recycle icon instead of the check on every tidak-lolos row, "Pulihkan" in the panel, confirm dialog `pulihkan-talenta` with an optional note for the student.
- **Minta ditinjau ulang.** `RequestTalentReview` (policy `requestReview`: owner, status tidak_lolos, no open request): optional message ≤ 500 characters in `review_message`, `review_requested_at` set, status untouched, audit `talent.review_requested`, every Kesiswaan account notified. Livewire limit 10 per hour per student (`talenta-tinjau:<user id>`). Kesiswaan sees the chip "Minta ditinjau ulang" on the row (requests sort first inside the stage) and the message in the panel.
- **Applying again after a rejection** is refused with a pointer to "Minta ditinjau ulang" (`ApplyTalent::REJECTED_MESSAGE`); no second row, the rejected row is never reset by the student. Other cabang stay open.
- Export: column "Catatan" became "Catatan untuk guru pembina" + "Catatan untuk siswa".
- `Kesiswaan\Talenta::render()` now re-authorizes `manage`.

## Contracts other areas can use
- `MoveTalentStage::handle(User, TalentApplication, TalentStatus $to, ?array $details)` — details keys `mentor_id`, `assessment_on`, `note`, `student_note`
- `RejectTalentApplication::handle(User, TalentApplication, ?string $studentNote = null, ?array $details = null)` (old 3-argument calls still work)
- `RestoreTalentApplication::handle(User, TalentApplication, ?string $studentNote = null): TalentApplication`
- `RequestTalentReview::handle(User, TalentApplication, ?string $message = null): TalentApplication` (validation keys `pilihan`, `pesan`)
- `TalentNote::clean(?string, string $key, int $max = 1000, string $label = 'Catatan'): ?string` · `TalentNote::inline(string): string`
- `TalentApplication::query()->reviewRequested()` (open requests, e.g. for a sidebar badge)
- Notification kinds: `talent.stage_changed`, `talent.rejected`, `talent.restored` (student) · `talent.review_requested` (Kesiswaan, link `kesiswaan.talenta.index?tahap=tidak_lolos`)

## Commands verified (2026-10-04, in-memory SQLite)
- `php artisan test --filter="TalentActionsTest|RequestTalentReviewTest|ApplyTalentTest|TalentPoliciesTest"` → **37 passed (266 assertions)**
- `… --filter="ProfilTalentaTest|KesiswaanScreensTest|GuruAreaTest"` → 63 passed, 1 failed (431 assertions): the failure was `KesiswaanScreensTest › imports students in three steps` ("Siap diimpor"), the import screen another agent was changing at that moment; every talenta case passed
- `… --filter="KesiswaanScreensTest"` again after that agent's edit → **29 passed (205 assertions)**
- `… --filter="CspSafeTest|ComponentsTest|KesiswaanHardeningTest|ModelFactoriesTest|MailInjectionTest"` → **145 passed (781 assertions)**
- `./vendor/bin/pint <my 27 files>` → pass · `pint --test` on the two shared test files → pass

## Not verified
- The migration on MySQL 8.4 (not run: only the coordinator migrates). It uses plain `ALTER TABLE … ADD` and two `UPDATE`s.
- No browser run and no `npm run build`: the dev DB lacks the new columns until it is migrated (the Talenta screens fail before that).
- `tests/E2E/support/email-scenario.php` was not run; its steps 9b/9c call the Actions with the old argument shapes, which still work.
- `DemoSeeder` was not run; it sets none of the changed columns and `note` still exists.

## Requests for other owners
- Coordinator: `migrate`; `npm run build` (new utility classes in three views).
- `icon.blade.php` header comment and `resources/views/dev/components.blade.php` icon list: add `recycle`.
- `email-scenario.php` step 9: add a review request (mail to every Kesiswaan, subject `Minta ditinjau ulang: FLS3N Baca Puisi`) and a restore (mail to the student, subject `Pengajuan talenta dibuka kembali: FLS3N Baca Puisi`).
- `WithKesiswaanBadges` + `Navigation`: optional badge on "Talenta siswa" = `TalentApplication::query()->reviewRequested()->count()`.
- `Admin\AuditLog` label map: optional labels for `talent.restored`, `talent.review_requested` (other talent events have none either).
- Docs: ARCHITECTURE §4 `talent_applications` columns and §8b table (stage changed / rejected / restored → the student; review requested → every Kesiswaan); UI-MAP #15, #18 and `guru.beranda`; PANDUAN-PENGGUNA (Siswa: catatan + Minta ditinjau ulang; Kesiswaan: two notes, Pulihkan); DECISIONS: new entries for the two notes, the undo, and "no second application after a rejection".

## Follow-ups (not done)
- The guru pembina gets no notification when a choice is rejected or restored (only sees the stage in the Talenta tab).
- No bulk "Pulihkan"; no note for the student in the bulk "Pindahkan ke …".
