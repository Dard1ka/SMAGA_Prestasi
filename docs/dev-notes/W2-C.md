# W2-C — Kepala Sekolah (persetujuan, dashboard), Tata Usaha (surat tugas PDF), Humas (publikasi, apresiasi)  ·  status: done

## Files (owned by me; UI-MAP class names win over the brief's `KepalaSekolah` folder)
- Livewire + views: `Kepsek\Persetujuan`, `Sekolah\Dashboard` (also mounted by W2-D on `admin.beranda`), `TataUsaha\{SuratTugas,ArsipSurat}`
  (+ `livewire/tata-usaha/partials/{pratinjau-siswa,pratinjau-guru,kop,nomor,ttd}`), `Humas\{Publikasi,Jadwal,Apresiasi,Arsip,HumasQueries}`
  (Apresiasi is also mounted by W2-B on `kesiswaan.apresiasi.index`); views under `resources/views/livewire/{kepsek,sekolah,tata-usaha,humas}/`.
- Actions: `Registrations\{ApproveRegistration,RejectRegistration}` · `Letters\{IssueLetters,SendLetters,LetterDraft,LetterContent}` ·
  `Publications\{SchedulePublication,PublishPublication,GenerateFlyer,FlyerRenderer,CreateAppreciationEvent,RecordAppreciation,CompleteAppreciationEvent,PublicationInput,AchievementFacts}`.
- Domain (new): `App\Domain\Letters\{PdfRenderer,PdfQrCode,PdfDocument}`. PDF templates `resources/pdf/{layout,surat-tugas-siswa,surat-perintah-tugas-guru}.blade.php` + `partials/{kop,ttd}`.
- Policies: `LetterPolicy`, `PublicationPolicy`, `AppreciationPolicy`, `AppreciationEventPolicy`; added `RegistrationPolicy::{viewApprovalQueue,decide}` (below W2-A1's marker).
- Notifications (queued mail + database, extend W2-A1's `Registrations\RegistrationNotification`): `Approval\{RegistrationApproved(audience siswa|guru|tu),RegistrationRejected}`,
  `Letters\LettersIssued(audience siswa|guru)`, `Publications\{AchievementPublished,AppreciationScheduled}`.
- Public: `App\Http\Controllers\LetterVerificationController` + `resources/views/letters/verifikasi.blade.php`. Fonts `resources/fonts/DejaVuSans(-Bold).ttf` (+ LICENSE) for flyers.
- Routes: `routes/staff.php` block "W2-C" · `routes/web.php` block "W2-C" (`public.surat.verifikasi`).
- Tests: `tests/Feature/{KepalaSekolah,TataUsaha,Humas}/**` (+ `KepalaSekolah/W2cFixtures.php`, `Humas/HumasFixtures.php`).

## Contracts
- Routes: `kepsek.beranda` GET /kepsek (Dashboard) · `kepsek.persetujuan.index` /kepsek/persetujuan (`?bidang=&q=`) · `tu.beranda` /tu ·
  `tu.surat.show` /tu/surat/{registration} (ULID; 404 unless `disetujui` or letters exist) · `tu.surat.arsip` /tu/surat/arsip (`?q=&jenis=`) ·
  `humas.beranda` /humas (`?pilih=<publication>&q=`) · `humas.jadwal` · `humas.apresiasi.index` · `humas.arsip` · `public.surat.verifikasi` GET
  /verifikasi-surat/{code} (throttle 30/min, noindex, only sent letters are "Sah"; shows type, number, date, lomba — no names).
- `ApproveRegistration::handle(User $actor, Registration $r, ?string $note = null): Registration` (menunggu_kepsek → disetujui; notifies members, mentor, all TU).
  `RejectRegistration::handle(User $actor, Registration $r, string $note)` (note 5–500 chars; → ditolak; notifies members). Both: 403 for anyone
  without `registration.approve` (Admin included), ValidationException `registration` when already decided/cancelled. `ApproveRegistration::memberUsers($r)`.
- `IssueLetters::handle(User $actor, Registration $r, LetterDraft $d): Collection<Letter>` — numbers from settings formats (`number()`, `numberParts()`,
  static `isTaken(type, year, seq)`), unique (type, year, seq) checked + DB index (UniqueConstraintViolation → friendly error), guru letter skipped when
  the registration has no mentor, PDFs on the private disk `surat/<Y>/<ulid>.pdf` (files purpose `letter`, SHA-256), audit `letters.issued` (numbers,
  sha256, signature file id + sha256, schedule). Registration stays `disetujui`. Error keys: siswa_sequence, guru_sequence, issued_on, basis_number,
  basis_date, schedule_day, schedule_time, place, registration, signature.
- `SendLetters::handle(User $actor, Registration $r)` → letters `sent_at`, registration `surat_terbit`, `LettersIssued` to members (siswa letter link via
  `files.show`) and mentor (guru letter). `LetterPolicy`: viewAny/view = `letter.view` staff; members see the siswa letter and the mentor sees both
  **only after sending**; create = `letter.generate` + disetujui + no letters; send = `letter.send`; update/delete always false.
- `PdfRenderer::render(string $template, array $data, array $images): PdfDocument{bytes,pageCount,sha256()}` — own dompdf `Options` (not config):
  remote/JS/PHP off, `allowedProtocols = ['file://']` (no data:/http), chroot = resources/pdf + per-render sandbox `storage/app/pdf-tmp/<ulid>` (images
  written there from bytes: signature from `settings.signature_file_id`, QR PNG from `PdfQrCode` via bacon-qr-code + GD), sandbox deleted after render.
- Dashboard: `Sekolah\Dashboard` needs `dashboard.view`; `?tahun=2025/2026`; bars use `h-pct-*`/`w-pct-*` + sr-only tables; bidang rows link to
  `public.prestasi?bidang=`; "Data contoh" chip only when APP_ENV=local.
- Humas: `SchedulePublication::handle($actor, Publication, string $caption, array $channels, DateTimeInterface $at)` (→ terjadwal, re-schedule ok) ·
  `PublishPublication::handle($actor, Publication, ?caption, ?channels)` (siap|terjadwal → dipublikasikan, ≥1 channel, notifies student) ·
  `GenerateFlyer::handle($actor, Publication, 'biru'|'emas'|'putih'): File` (1080×1350 PNG, Intervention 4 GD + DejaVu, private `flyer/Y/m/<ulid>.png`,
  purpose `flyer`, replaces the old flyer; initials only — no photo column exists) · `CreateAppreciationEvent::handle($actor, $name, $heldOn)` ·
  `RecordAppreciation::handle($actor, AppreciationEvent, Achievement)` (verified + publish_consent, notifies student) · `CompleteAppreciationEvent`.
  `PublicationPolicy::{viewAny,view,update,schedule,publish,generateFlyer}` + static `eligible()` (verified achievement + publish_consent; queue hides
  the rest); `Appreciation(Event)Policy` = `appreciation.manage` (Humas, Kesiswaan, Admin).

## Commands verified
- `docker compose run --rm --no-deps app php artisan test tests/Feature/KepalaSekolah tests/Feature/TataUsaha tests/Feature/Humas` → **164 passed**
  (708 assertions): Kepsek 16, TU 17, Humas 131.
- `… ./vendor/bin/pint --test <all 52 W2-C files + routes/staff.php>` → PASS · `docker compose run --rm node sh -c "npm run build"` → OK
- Sample PDFs rendered from the dev demo data (NSIF team; rendered only, no letter rows saved): `storage/app/private/w2c-contoh/surat-tugas-siswa-contoh.pdf`
  (1 page) and `…/surat-perintah-tugas-guru-contoh.pdf` (2 pages with Lampiran) — dummy "CONTOH" signature, QR + code bottom-left. Sample flyers:
  `…/w2c-contoh/flyer-{biru,emas,putih}-contoh.png`. (Browser checks of the live stack were unreliable because several agents share one browser
  session; pages were checked as static snapshots + Livewire tests instead.)

## Assumptions / deviations
- Issue and send are two Actions (UI-MAP `IssueLetters` + `SendLetters`, mockup step 3 "Buat 2 PDF" then "Kirim"); the brief's single "GenerateLetters"
  = both. disetujui → surat_terbit happens on **send**. Numbers/PDFs are immutable; a correction flow (cabut + versi baru) is not built.
- dompdf gets images as files inside the chroot sandbox instead of data: URIs (SECURITY §3g removes `data://`); the signature still never leaves the PDF.
- Student letter prints NIS (school template NO|NAMA MURID|NIS|KELAS, brief); SECURITY §3g says only if the official template requires it → confirm (DECISIONS §F-2).
- Schedule fields (hari/tanggal, pukul, tempat) are not stored on `letters` (only in the PDF + audit properties); no migration added.
- Letter verification code = the model's 32 hex (W1-A), not SECURITY's 10-char Crockford. Unsent letters verify as "Tidak ditemukan".
- `tu.surat.template` (M4), `kepsek.prestasi`, `kepsek.laporan` are not built (menu items stay disabled).
- Humas: no separate UpdatePublication (caption/channels are saved by Schedule/Publish); nothing auto-publishes at `scheduled_at` (Jadwal lists
  overdue ones); flyers stay on the private disk (UI-MAP says public disk after publication → W2-D/lead decide); RecordAppreciation requires publish_consent.

## Requests for other owners
- @W2-B: Pint rewrote the FQCNs in `routes/staff.php` into `use` imports (your block too; behaviour unchanged). Your W2-B block would get the same from Pint.
- @W2-D: `routes/web.php` W2-D + W2-C blocks use FQCNs; Pint (`fully_qualified_strict_types`) will want imports there as well.
- @lead: `bacon/bacon-qr-code` is used directly (QR) but only installed through Fortify → add it to composer.json `require`. Consider a
  `letters.signature_file_id`/`schedule` column (SECURITY §3m-5 "ttd_version per PDF"; now in the audit log). Kepala Sekolah list of letters
  issued in their name (SECURITY §3m-5) needs a kepsek route to `TataUsaha\ArsipSurat` (component already authorizes `letter.view`).
- @W1-D/S: `<x-sth.textarea>` has an `@if` inside `<textarea>`, so Livewire's `<!--[if BLOCK]>` markers end up in the text before Livewire boots
  (seen in static renders) → compute the value in `@php` and print it once.
- Tests: never `Storage::fake('local')` while other agents run tests — it wipes the shared bind-mounted folder; use `W2cFixtures::isolatedLocalDisk()`.
