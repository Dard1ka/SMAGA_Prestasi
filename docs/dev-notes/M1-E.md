# M1-E — Humas apresiasi (hapus + konfirmasi acara) and Status aktivasi popup  ·  status: done

Lead's test round of 2026-10-04, items 12 and 16. Coordinator writes the DECISIONS entries.

## Files (owned by me)
- `database/migrations/2026_10_04_140000_m1e_add_completion_to_appreciation_events.php` (new) — `appreciation_events.completed_at`
  (timestamp, null, index) + `completed_by` (users FK, null on delete); `backfill()` stamps events whose rows are all `selesai`.
- `app/Models/AppreciationEvent.php` — cast, `completedBy()`, `isCompleted()`, scopes `open()` / `completed()`.
- `app/Actions/Publications/CompleteAppreciationEvent.php` — stamps the event; `blocker()` + `MESSAGES`.
- `app/Actions/Publications/DeleteAppreciationEvent.php` (new) · `app/Notifications/Publications/AppreciationCancelled.php` (new).
- `app/Actions/Publications/RecordAppreciation.php` — refuses a confirmed event.
- `app/Policies/AppreciationEventPolicy.php` — `delete`.
- `app/Livewire/Humas/Apresiasi.php`, `resources/views/livewire/humas/{apresiasi,partials/appreciation-event}.blade.php`.
- `app/Livewire/Akun/StatusAktivasi.php`, `resources/views/livewire/akun/status-aktivasi.blade.php`.
- Tests: `tests/Feature/Humas/{AppreciationActionsTest,HumasScreensTest,HumasPoliciesTest,HumasNotificationsTest}.php`,
  `tests/Feature/Aktivasi/StatusAktivasiTest.php`.
- Not touched: `ActivationReport`, `ReopenActivationWindow`, `UpdateActivationWindow`, `CreateAppreciationEvent`, `HumasQueries`.

## What changed and why
**Item 12, Apresiasi.** Before: "done" only existed on the rows (`appreciations.status`), so an event with zero prestasi could
neither be confirmed nor removed (the lead's test event), and nothing could be deleted at all.
- The event itself is now confirmed: `CompleteAppreciationEvent` sets every `dijadwalkan` row to `selesai` **and** stamps
  `completed_at` / `completed_by`. Rules unchanged: not before `held_on`, not without a scheduled row. New: not twice.
- A confirmed event is a record: `RecordAppreciation` adds nothing to it, `DeleteAppreciationEvent` refuses it.
- `DeleteAppreciationEvent::handle(User, AppreciationEvent): int` — only while not confirmed (also refused when a row is already
  `selesai`, old data). Deletes the scheduled rows (= releases the achievements, they can go on another event), deletes the
  event, audit `appreciation.event_deleted` (causer, `name`, `held_on`, `rows`, `achievement_ids`, `notified`; no names).
  After commit one `AppreciationCancelled` per student who had a row and can log in (kind `appreciation.cancelled`,
  database + mail, `PlainMailMessage`, mail queue).
- Screen: three lists. "Perlu dikonfirmasi" (date passed, not confirmed; only when not empty, max 50), "Acara mendatang",
  "Sudah dilaksanakan" (10 per step, max 200, "Dikonfirmasi <tanggal> oleh <nama>"). Every open card has "Tandai sudah
  dilaksanakan" and "Hapus acara"; both open the dialog `apresiasi-konfirmasi` (`confirmComplete` / `confirmDelete` →
  `runConfirmed`). When the event cannot be confirmed yet the card shows the reason instead of a button
  ("Bisa ditandai sudah dilaksanakan mulai Senin, 5 Oktober 2026."). Row chip "Selesai" → "Sudah diapresiasi", event chips
  "Hari ini" / "Akan datang" / "Belum dikonfirmasi" / "Sudah dilaksanakan". The old `complete()` method and its
  `wire:confirm` are gone.
- Rights: `appreciation.manage` as before, so Humas, Kesiswaan (`kesiswaan.apresiasi.index`, same component) and Admin.

**Item 16, Status aktivasi.** Before: "Lihat" set the class filter of "Daftar siswa" at the bottom of the page.
- "Lihat" (`showClass`) now opens the dialog `aktivasi-kelas`: that class's students (name, masked NIS, masked email, status
  chip + flags, aktivasi, login terakhir, the same "Buka ulang" / "Buka pembekuan" row action), status tabs for the states the
  class has (`setClassStatus`), 40 rows per step (`showMoreClass`), "Tutup" (`closeClass`). Built from the rows the page already
  loads: no extra query. The reason dialog `aktivasi-aksi` opens on top of it and the class dialog stays open afterwards.
- `?kelas=<classroom id>` opens the page with the dialog (`data-sth-autoopen`); an unknown or empty class is dropped.
- "Daftar siswa" stays (all classes, status tabs, search, CSV). Its class filter moved to its own property
  `daftarKelas` / `?kelas_daftar=`; the CSV and its audit entry use that filter.
- Closing with Esc, the X or the backdrop is client-side only, so `kelas` may stay set; the next use of the list (tabs, search,
  filter, more rows, CSV, window form) drops it, so coming back from the password page does not reopen a dialog.
- Permissions unchanged: `student.view` on mount and in `showClass`, `student.import` / `user.reset_password` in the row
  actions; both routes (`kesiswaan.aktivasi.index`, `admin.aktivasi.index`) share the component.

## Contracts other areas can use
- `AppreciationEvent::query()->open()` / `->completed()`, `$event->isCompleted()`.
- `CompleteAppreciationEvent::blocker(AppreciationEvent, int $scheduled, ?CarbonImmutable $today = null): ?string`
  (`BLOCK_DONE` | `BLOCK_NOT_YET` | `BLOCK_EMPTY`), messages in `::MESSAGES`.
- `DeleteAppreciationEvent::COMPLETED_MESSAGE`; policy ability `delete` on `AppreciationEvent`.
- `StatusAktivasi::CLASS_DIALOG = 'aktivasi-kelas'`; link to a class: `route('kesiswaan.aktivasi.index', ['kelas' => $id])`.

## Commands verified (Docker, in-memory SQLite; baseline before my change: 57 passed, 305 assertions)
- `php artisan test --filter="AppreciationActionsTest|HumasScreensTest|HumasPoliciesTest|HumasNotificationsTest|StatusAktivasiTest"`
  → 91 tests: 90 passed (608 assertions) + 1 error `Class "LetterPrintController" not found` at `routes/staff.php:56`, another
  agent's file being written at that second (not my code).
- Rerun `--filter="AppreciationActionsTest|CspSafeTest|StatusAktivasiTest"` → **76 passed (460 assertions)**, so all 91 are green.
- `--filter="ConsentWithdrawnTest|PublikasiScreenTest|HumasRoutesTest|CspSafeTest|MailInjectionTest|ModelFactoriesTest|AutoPublishTest"`
  → 180 passed + the same transient route error in one CspSafeTest case (green in the rerun above). No other test needed a change.
- `./vendor/bin/pint --test <my 14 PHP files>` → PASS.

## Assumptions / deviations
- Confirming an event notifies nobody (as before: students are told when they are scheduled). Only the deletion is new mail.
- An event without prestasi cannot be confirmed (old rule kept); it can only be deleted.
- `update` on the policy stays permission-only; wrong states are answered by the Actions with a message, not a 403.
- The class dialog uses `size="lg"` (760 px), so NIS and email sit under the name (4 columns, no sideways scroll on desktop).

## Requests for other owners
- Coordinator: run the migration; nothing to grant (no new table).
- `app/Livewire/Humas/Jadwal.php::events()` and `Publikasi.php::recordAppreciation()` / `panel()` still list events by
  `held_on >= today` only: add `->open()` so an event confirmed today disappears from the agenda and from the "Masukkan"
  select (today `RecordAppreciation` answers "Acara ini sudah dilaksanakan. Pilih acara lain.").
- Optional: `<x-sth.modal size="xl">` (≈ 960 px) in `modal.blade.php` + `app.css`; then the class dialog can show email as its
  own column. `AppreciationEventFactory::completed()` state. A delete step in `tests/E2E/support/email-scenario.php`.
- Docs: ARCHITECTURE §4 (`appreciation_events`: + `completed_at`, `completed_by`), §8b new row "Appreciation event deleted
  (`DeleteAppreciationEvent`) → every student with a scheduled row on it who can log in"; UI-MAP §4a/§4d/§5 (Apresiasi actions
  + `DeleteAppreciationEvent`; Status aktivasi `?kelas=` dialog, `?kelas_daftar=`; list limits: class dialog 40 per step,
  "Sudah dilaksanakan" 10 per step); PANDUAN-PENGGUNA §4 Status aktivasi ("Lihat" opens the class, "Download CSV") and Humas
  ("Buat acara → Masukkan prestasi → Tandai sudah dilaksanakan; Hapus acara bila batal"); DECISIONS (event-level confirmation,
  delete + notice, class dialog).

## Not verified
- No browser check: two stacked native dialogs (class dialog + reason dialog), `data-sth-autoopen` on load, widths at 1440 / 390.
- The migration and its backfill ran on SQLite only (tests); not on MySQL 8.4.
