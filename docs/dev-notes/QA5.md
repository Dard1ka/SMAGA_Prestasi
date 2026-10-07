# QA5 — fixes after the retest of PR 13 / M3 (2026-10-07)  ·  status: done (branch `fix/qa5-bug-fixing`)

Retest of `main` @ `16780d2` (PR 13 merged) in the app's built-in browser, plus every demo document opened and
checked. Feedback with the screens and roles: `workspace/laporan/feedback-uji-m3.md` (outside the repo). The lead
answered four questions and asked us to decide the rest from the client document "SMAGA TALENT HUB (STH)": new
decisions **D-109…D-112** (DECISIONS §D9). Every fix test-first (red seen, then green). Fictional data only; no
migration.

## Files
- `app/Actions/Talent/RestoreTalentApplication.php`, `app/Livewire/Kesiswaan/Talenta.php`,
  `resources/views/livewire/kesiswaan/talenta.blade.php` — guru pembina for a restore into Peserta pembinaan
  (D-109), "Tahap N" labels, empty statuses in the download dialog (D-111), narrower table at 1366 px
- `app/Notifications/Verification/BinaanReportDecided.php` (new), `app/Actions/Reports/{VerifyReport,
  RequestReportRevision,RejectReport}.php` — guru pembimbing hears report decisions (D-110)
- `app/Notifications/Publications/BinaanAppreciationNotice.php` (new), `app/Actions/Publications/{RecordAppreciation,
  ReleaseAppreciation,DeleteAppreciationEvent}.php` — guru pembimbing hears appreciation changes (D-110)
- `app/Actions/Competitions/VerifyCompetition.php`, `app/Livewire/Kesiswaan/Lomba/Index.php` — honest reject toast
- `app/Livewire/Admin/Pengaturan/Pengguna.php` — Kepala Sekolah row and replacement independent of the search
- `app/Providers/FortifyServiceProvider.php` — "Batal" never goes back to a page behind the password
- `app/Livewire/Guru/BinaanDetail.php`, `resources/views/livewire/guru/binaan-detail.blade.php` — revision alert,
  letters in the preview popup (D-112)
- `app/Livewire/Humas/Apresiasi.php`, `resources/views/livewire/humas/partials/publication-panel.blade.php`
- `resources/views/livewire/auth/activation.blade.php` — "Keluar"
- `app/Livewire/Kepsek/Persetujuan.php`, `resources/views/livewire/kepsek/persetujuan.blade.php` — "Daring" once
- `database/seeders/Support/Demo/{DemoGuideline,DemoFileRefresher}.php`, `DemoLetterBasis.php` (new),
  `database/seeders/DemoSeeder.php`, `app/Console/Commands/RefreshDemoFiles.php` — demo juknis per kind, demo letter
  invitations, `--lengkapi` hint
- `docs/DECISIONS.md` (§D9), `docs/ARCHITECTURE.md` (§8b rows, §8f), `CLAUDE.md` (§2, §5, §8, §9, §10)

## What changed (finding → root cause → fix)
**Bugs**
1. **Admin › Pengguna, search hides the Kepala Sekolah.** Typing a name in the search emptied the Kepala Sekolah row,
   the replacement candidates and the dialog target, because all three were taken from the filtered table.
   `staff()` now loads every non-student account; only the table is filtered (in PHP, name or username).
2. **"Batal" on the password page looped.** From Admin › Pengaturan / Pengguna / 2FA (pages that are themselves
   behind `password.confirm`) "Batal" went back to `url.intended` = the same gated page, which showed the password
   page again. `confirmPasswordBack()` skips an address that matches a route with `password.confirm` middleware and
   uses the Referer, else `home`.
3. **Reject toast claimed a notification that was not sent.** Rejecting one's own entry, or an entry of an archived
   or frozen student, sends nothing, yet the toast said "Pengirim diberi tahu." `VerifyCompetition::notifiesSubmitter()`
   is the one rule for both; the toast then reads "Lomba ditolak."
4. **Guru detail said "Laporan hasil belum dikirim" for a report that waits for its revision.** A revision puts the
   registration back to `menunggu_laporan`, which the alert read as "never sent". With `perlu_revisi` the alert is
   now "Laporan perlu direvisi · Ketua tim memperbaiki laporan dari akunnya. Catatan Kesiswaan ada di Laporan hasil."
5. **Talenta at 1366 px: names cut to "Gal…".** Fixed columns took 544 px of about 650. Below `2xl` the avatar is
   hidden, Talenta `w-36`, Status `w-32`, the side panel 300 px: the Siswa column went from 108 px to 196 px
   (measured in the browser at 1366 × 768; no name cut, no sideways scroll, nothing overflows the panel).

**UX (decided by the lead or from the client document)**
6. **Pulihkan → Peserta pembinaan** needs a guru pembina (D-109); the stage options say "Tahap 2 · tahap
   sebelumnya" instead of only "Tahap sebelumnya".
7. **Download data** follows the screen filters (it already did; the old feedback item saying it ignored them was
   wrong and is corrected in the feedback file). Empty statuses say "Kosong" and cannot be ticked; a choice with no
   rows never makes an empty file (D-111).
8. **Guru pembimbing notices** for report decisions and appreciation (D-110).
9. **Humas › Apresiasi** offers an alumnus as "Nama (Alumni)", never with the last class (as the ranking, D-80). The
   question whether alumni should be offered at all (QA4) stays open.
10. **Humas › Publikasi**: the panel of a published row is "Detail publikasi", not "Siapkan publikasi".
11. **Guru detail**: the sent letters open in the preview popup (D-112).
12. **/aktivasi** has "Bukan akun kamu? Keluar" on every step (QA Q1-11, backlog #63).
13. **Kepala Sekolah › Persetujuan**: "Daring · Daring" → "Daring" (QA Q1-27, backlog #67).

**Demo documents (local only)**
14. **Juknis of one bidang read the same** (LPB vs NSIF 96 %, O2SN vs Pencak Silat 93 % `similar_text`). New kind
    `inovasi` (invention fairs, from the lomba name: invention, inovasi, krenova, fiksi, nsif, wice, wseec, expo,
    fair) with stan pameran and medals; bela diri with kategori tanding and kategori seni; rules for participants,
    conduct, schedule labels and awards per kind. Now O2SN vs Silat 73 %, LPB vs NSIF < 75 % (test), and the 8 demo
    juknis are 8 different kinds. Two lomba of the same kind still share their text (99.7 %); the demo set has none.
15. **Demo surat tugas had empty "Nomor :" / "Tanggal :" under Dasar.** `DemoLetterBasis` gives every demo letter a
    fictional invitation number and a date 5–14 days before the letter (the WICE one keeps its hand-written values).
    The seeder writes it; `sth:demo:refresh-files --lengkapi` fills it on an older database
    (`DemoFileRefresher::fillLetterBasis()`, no model events, only letters whose PDF is in `demo/`).
16. **`--lengkapi` was easy to miss.** Without it the command now says what it would add, e.g. "Jalankan dengan
    --lengkapi untuk melengkapi: 6 surat demo tanpa nomor undangan." (the lead's local database on 2026-10-07). Then
    `sth:demo:refresh-files --lengkapi --berkas="surat-*"` was run there: 6 letters filled, the 8 demo letter PDFs
    rendered again; the krenova letter now prints "Nomor : 431/PAN-KRENOVA/I/2026 · Tanggal : 31 Januari 2026".

Not changed, on purpose: the QA uploads with "UJI COBA" files (WICE report, robotik brochure) stay; the lead: follow
the friend, and the refresher never touches uploads.

## Commands verified
- New or changed tests (each seen failing first): `TalentRestoreStageTest` (+3), `TalentaM3Test` (+2, 3 adapted),
  `LombaDatesTest` (+2 dataset rows), `UsersActionsTest` (+2), `PasswordConfirmResumeTest` (+2), `GuruAreaTest`
  (+2), `ReportVerificationTest` (+2), `AppreciationActionsTest` (+2, 1 adapted: the guru still hears when the
  student cannot log in), `HumasScreensTest` (+1), `PublikasiScreenTest` (+1), `ActivationTest` (+1),
  `PersetujuanTest` (+1), `DemoDocumentsTest` (+1), `RefreshDemoFilesTest` (+2); `FilePreviewScreensTest` (1 adapted:
  M3 pinned D-105's *sementara* "the guru letter stays a plain download"; since D-112 it opens in the popup too).
- `npm run build` OK; `reload.bat` (dev-cache) run.
- Full suite and Pint: see TEST-RESULTS below.
- Browser (built-in, 1366 × 768, demo accounts): Talenta column widths and panel; download dialog ("Kosong",
  nothing preselected on an empty tab, "Pilih minimal satu status.", new filter sentence); Persetujuan rows; Guru ›
  detail binaan WICE 2026 ("Laporan perlu direvisi", both letters "Buka" → the PDF in the popup, "Tutup"); Humas ›
  Arsip › Lihat → "Detail publikasi · Terbit 5 Okt 2026"; Apresiasi › Tambah prestasi lists alumni as "Nama (Alumni)".
  Not clicked in the browser: Pulihkan (the local database has no Tidak lolos row and rejecting one would notify a
  student), and the notices (Pest only).

## Review of the change set (code reviewer agent, then fixed test-first)
- **A pembina who left blocked every restore.** The dialog preselected the recorded pembina even when that account
  was frozen or no longer a guru (not in its list), and the Action refused that id for every stage. Now the check
  runs on the final pembina inside the transaction: a new choice must be an active guru; Peserta pembinaan needs an
  active guru ("Guru pembina yang tercatat tidak aktif lagi. Pilih guru pembina lain."); earlier stages keep the
  recorded pembina; the dialog preselects only an active guru. Tests: `TalentRestoreStageTest` (+1), `TalentaM3Test` (+1).
- **N+1 inside the locked delete.** `DeleteAppreciationEvent` looked up the guru with two queries per row while
  holding the event lock (9 lookups for 4 rows). `RecordAppreciation::mentorsFor()` does it in three queries for any
  number of rows. Test: `AppreciationActionsTest` (+1, at most 3 lookups for 4 rows).

## TEST-RESULTS
- Final, after retest 2 and both reviews (2026-10-07): `pint --test` → 656 files pass; full suite
  `--parallel --processes=4` → **2230 passed, 1 skipped (12859 assertions)**, exit 0 (1051 s). `npm run build` OK.
- 2026-10-07, after every fix above incl. the review: `pint --test` → 654 files pass; full suite
  `--parallel --processes=4` → **2194 passed, 1 skipped (12686 assertions)**, exit 0 (1091 s). (Before this run:
  2163 passed on `main` after PR 13.)
- The run before it had 1 failure, `FilePreviewScreensTest` (the M3 test of the guru letter, adapted for D-112 as
  noted above); nothing else failed.
- `npm run build` OK; `reload.bat` run after the last PHP change.

## Retest 2: full sweep of every role (2026-10-07, same day)
The lead asked to test everything once more and to put every fix in one bug-fixing PR. Sweep in the app's browser:
all seven roles (staff demo accounts; the student role through Hana Safira, reset by Admin with a temporary password
and activated again) and the public pages, every menu page at 1366 × 768 and at 390 px. Per page: a crawl of every
link on it (pages by GET, files by HEAD, which is not audited; export / print / logout links skipped) for error
pages, raw text (`null`, `undefined`, raw keys, banned words, decision codes) and broken links; and a render check
(sideways scroll outside an intended scroller, text cut off at the edge, broken images, buttons and links without a
name, console errors). `laravel.log` and `failed_jobs` were compared before and after: no new error, no failed job.

Confirmed and fixed (test-first, each test seen failing):
1. **TU › Surat tugas: the top bar search did nothing** (#60). `SuratTugas` gets `#[Url] q`: the queue filters by lomba
   or member name, "Hasil pencarian" + "Hapus pencarian", "Tidak ada pendaftaran yang cocok"; the badge still counts
   the whole queue. The label is "Cari lomba atau siswa" (the queue holds registrations, not letters yet).
2. **Two search boxes on one screen, the top bar one going elsewhere** (#60, D-90): TU › Arsip surat, Kesiswaan ›
   Kelola lomba / Talenta / Database prestasi / Status aktivasi, Guru › Lomba, Kepala Sekolah › Surat atas nama saya,
   Admin › Pengguna / Status aktivasi / Arsip otomatis / Log audit now set `'search' => false` (like Humas › Arsip).
3. **Kesiswaan's "Cari lomba atau siswa" found lomba only** and left Talenta for Kelola lomba: label "Cari lomba".
4. **Admin › Log audit printed raw keys** ("file.downloaded file.downloaded", 13 raw keys in the filter; #64): 26 labels
   added to `AuditLog::EVENT_LABELS`; a test scans `app/` for every key passed to `->event()` plus the runtime ones.
5. **Status sistem "Staf aktif" counted accounts not yet activated** (11 = 9 + 2; #62): it counts `active` staff and the
   note says "2 belum aktivasi".
6. **"(D-08)" / "(D-25)" on Admin › Pengaturan** and the seeded weight note "Bobot bawaan (D-08)" (#67): removed (the
   local database row was updated too); a test scans the views and seeders.
7. **Closing the Status aktivasi class popup with X, Esc or a click outside kept `?kelas=`** and the popup came back on
   reload (#63): `<x-sth.modal on-close="method">` → `data-sth-on-close`, `app.js` calls that Livewire method on the
   dialog's `close` event (every way of closing; the method name is checked, no eval). Checked by hand: X, "Tutup", Esc.
8. **Forms that showed their errors in rounds** (#65): "Tambah pengguna" (password first, then name / username),
   "Jadwalkan" (date and time first, then caption and channels) and "Buat acara" (an empty name hid a missing date) now
   name every problem at once (`CreateStaffAccount::validator()`, one merged check in `Publikasi::schedule()`, name and
   date together in `CreateAppreciationEvent`). Not a bug after all: "Tambah lomba" (only Nama lomba and Bidang are
   required to save into the queue) and "Usulkan lomba" (already collects every error).
9. **"Kirim ulang dalam 0:54" jumped five seconds at a time** (#65): `data-sth-countdown` + `app.js` tick the text every
   second; `wire:poll.5s` stays the server's truth and shows the button at 0.
10. **The menu tour came back on every reload after "Lewati"**: `/siswa/tur` passed `?tur=1`, which forced it on every
    load of that address. It now sets the one-time session flash `TurMenu::FORCE_KEY`; an old `?tur=1` forces nothing.
    (The 23 px highlight offset of #65 no longer reproduces: replica and menu item within 1 px.)
11. **Rate limits shared one counter per visitor**: Laravel keys an unnamed `throttle:N,M` by user or IP only, so the
    public pages (60), the QR letter check (30), public certificate links (30) and "Bukan saya?" (20) counted together:
    after about 35 public page loads the QR check answered 429 (at school many visitors share one IP). Each route got a
    key prefix (`throttle:60,1,publik`, `30,1,verifikasi-surat`, `60,1,berkas`, `30,1,berkas-publik`,
    `20,1,bukan-saya`); the limits are unchanged. Checked by hand: 35 public loads, then the QR check → its own 404 page.
12. **"peramban" in every mail footer and "Peramban lain" in Akun › Keamanan** (#67, D-89): "browser".
13. **The finished activation screen still said "Bukan akun kamu? Keluar"** (added earlier today): shown on steps 1–3 only.

Looked at and not bugs: the CSP console errors during the sweep came from the crawler (`DOMParser` on fetched pages
with another nonce), a fresh page load logs none; "unggah" in Humas notifications are rows stored on 3 Okt, before
D-89 (the code writes "upload"); searching "Nadia" on Humas finds nothing because her consent was withdrawn (D-71);
backlog #53 (TU letter screen too wide below 480 px) and Q2-31 (Kepala Sekolah dashboard 107 px too wide) no longer
reproduce at 390 px; the bell after "Tandai semua dibaca" updates (#63). The `production.ERROR` "STH_OTP_TEST_MODE
hanya boleh aktif di APP_ENV=local" lines in the dev `laravel.log` come from the parallel test runner booting a worker
without `APP_ENV` (the guard refuses as designed); dev noise only, not changed.

Tests (each seen failing first): `Support/SearchBoxTest` (new, 19 with the review), `Admin/Audit/AuditLogTest` (+2),
`Admin/AdminAccessTest` (+1), `Admin/LangIdTest` (+2), `DesignSystem/ComponentsTest` (+1),
`Aktivasi/StatusAktivasiTest` (+1), `Admin/UsersActionsTest` (+1), `Humas/HumasScreensTest` (+1),
`Humas/PublikasiScreenTest` (+1), `Auth/ActivationTest` (+2), `Security/RouteThrottleTest` (new, 2); adapted:
`Siswa/Lomba/SiswaScreensTest`, `Auth/AuthPagesTest` (tour), `Files/FilePreviewTest`, `Publik/PublikHeadersTest`
(throttle strings).

Second review (code reviewer agent on the retest-2 diff; all four findings real, fixed test-first):
- the merged checks of "Tambah pengguna" and "Jadwalkan" did not clear the old messages when they passed (Livewire's
  own `validate()` does), and "Buat acara" kept a name error after the name was filled: `resetErrorBag()` there;
- two runtime audit keys had no label (`registration.berlangsung`, `registration.menunggu_laporan`, written by the
  scheduled `StartCompetition` / `AwaitReport`) and `restored` (a restored soft-deleted row): labels added, the test
  now also derives those keys;
- TU: after a search that found nothing, clearing it kept "Belum ada surat untuk dibuat" under a full queue:
  `SuratTugas::updatedQ()` opens the first match again, and that empty state shows only for an empty queue;
- two more screens had two search boxes: Guru › Siswa binaan and Admin › Pengaturan (tab Pengguna & peran):
  `'search' => false` there too (`SearchBoxTest` lists them).

## Requests for the lead
- D-110 adds mails for every guru pembimbing: worth one line in PANDUAN-PENGGUNA (Guru) and a step in the email
  scenario (backlog #46).
- Still open from the feedback: the alumni rows in Apresiasi › Tambah prestasi (offered at all?); the student and TU
  letter screens still only download (D-105 / backlog #69).
