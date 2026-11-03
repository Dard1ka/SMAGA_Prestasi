# UX2: masukan lead 20 Okt 2026 (D-162…D-164) · status: committed 2026-10-21

Seven points of the lead on 2026-10-20 after testing UX1, built on the same Smaga4 checkout (`feat/fs1-feedback-sekolah`,
on top of the uncommitted UX1). Decisions: DECISIONS §D21.

## 1. Status aktivasi: the count tiles (no decision)
The three tiles next to the two boxes were stretched to the height of the boxes (the lead: "jadi jelek").
`<x-sth.stat-tile>` got `layout="row"`: one compact line at every width (icon · label + note · the value on the right).
Status aktivasi stacks those rows in the left half (`xl:grid-cols-2`, rows `auto-rows-fr`) next to the boxes; two per
row from `sm`, one per row on a phone. Checked in the app's browser at 1460 px as the demo Kesiswaan account.

## 2. Copy button for the temporary password (no decision)
`resources/views/livewire/admin/pengaturan/pengguna.blade.php`: the password sits in `#kata-sandi-sementara` next to an
icon button (new icon `copy` in `<x-sth.icon>`) with `data-sth-copy` + `data-sth-copy-flash="Kata sandi disalin."`.
`resources/js/app.js`: the copy hook trims the text; with `data-sth-copy-flash` it calls `showCopyFlash()`, which appends
a `.copy-flash` (green, centred, gone after 1.8 s, `resources/css/app.css`) inside the open dialog and announces the
sentence for screen readers. Without the Clipboard API the old fallback (text selected, toast "Tekan Ctrl+C …") stays.
Checked in the browser with a stubbed clipboard (the pane refuses the real one while the window is not focused): the
password alone is copied, the note sits in the middle (722/1460, 453/900 px) and disappears. Test:
`tests/Feature/Admin/SalinKataSandiTest.php`.

## 3. Laporan hasil in one place (D-163)
- `routes/siswa.php`: `siswa.lapor` (`/siswa/lomba-saya/{registration}/lapor`) is a redirect to
  `siswa.lomba-saya.show?lapor=1`; old mails and bell rows keep working, Lomba saya authorizes as always.
- `Siswa\LombaSaya\Index`: `#[Url(except: false)] $lapor`; `mount()` keeps it only for the ketua who may report now
  (`ReportPolicy::create`), and drops it on the list route.
- `partials/status-card.blade.php`: the button is `openLapor` / "Batal" at every width ("Perbaiki laporan" after a
  revision request), the inline `LaporHasil` shows at every width (it was `hidden lg:block`, phones used the page).
- `Siswa\Beranda` and `Siswa\Pendaftaran\Form` link to `siswa.lomba-saya.show?lapor=1#lapor-judul`.
- `LaporHasil` stays a component (inline); its full-page mode is no longer routed.
- Tests: `LaporHasilTest` (redirect, open card, member without form, outsiders), `LombaSayaTest`, `ReportNoDeadlineTest`
  (the Beranda link), `Fs1QaLombaSiswaTest`.

## 4. Kesiswaan queues newest first (D-164)
`Kesiswaan\Lomba\Index::queueCards()` (`latest()`, id), `Kesiswaan\VerifikasiHasil::queueQuery()` (`submitted_at` desc,
id; also Humas, the same screen; the newest opens by itself), `Kesiswaan\Beranda` (both preview cards). The catalog tab
keeps pending lomba oldest first (D-135, the school's request). Tests: `Fs1KatalogUrutanTest`,
`tests/Feature/Kesiswaan/AntreanVerifikasiTerbaruTest.php` (new), `KesiswaanScreensTest` and `HumasVerifikasiTest`
(their two-report setups now make the expected report the newest).

## 5. IPA 123 still open for registration (answer, no code)
The lead saw that Nadia's finished IPA 123 was still open. That came from the date simulation of UX1
(`sth:registrations:advance --date=2026-11-05` while the registration closes on 25 Okt). In real use a lomba cannot start
before its registration closes (`CompetitionDates`, D-98), so a finished lomba is always closed; and a student with a
non-final registration in a lomba cannot register again (`Student::scopeBusyIn`, "Kamu sudah terdaftar di lomba ini.").

## 6. Humas before verification (answer, no code)
Publications are created only by `VerifyReport` (`RecordAchievements`), appreciation needs a verified achievement and
`PublicationPolicy::eligible()` checks `verified_at`, so Humas cannot publish or appreciate before the report is
verified. Asked whether Humas should keep verifying (D-139), the lead chose to keep it.

## 7. No consent any more (D-162)
The lead: no photo consent at all, and every name may always be shown on the public pages (which never show a photo).
Done by a sub-agent (general-purpose, one brief), reviewed here (`NamedStudent`, `FilePolicy`, grep of every consent word).
- The one rule is `App\Actions\Publications\NamedStudent` without its consent condition: not named = student row or
  account deleted, or account disabled; alumni stay named.
- Changed: `AchievementFacts` (`named` instead of `photoConsent` / `publishConsent`), `FlyerData` (no consent notes; a
  disabled member is left out with "N anggota tim tidak ditampilkan karena akunnya nonaktif."), `RecordAppreciations`
  (`INACTIVE_MESSAGE` "Akun siswa ini nonaktif, jadi prestasinya tidak bisa dimasukkan ke acara apresiasi."),
  `RecordAppreciation::withTeammates()`, `HumasQueries`, Humas `Publikasi` / `Arsip` / `Jadwal` / `Apresiasi` /
  `ArsipFoto` (no "Izin dicabut", no photo chips, the hidden-count sentence names an inactive account), `PublicAchievements`
  (`named = !inactive`, slugs for every student with an achievement, `SHAPE` 3 so old cache rows are dropped),
  `Siswa\Peringkat`, `FilePolicy::viewPublic`, `StudentPolicy` (no `updateConsent`), Admin `Pengguna` (no consent box),
  Admin › Halaman publik texts, `DatabasePrestasi` (no "Boleh dipublikasikan"), `AchievementReadyForPublication` (no
  "Cek persetujuan publikasi …"), `SecurityEventSubscriber` (no `student.consent` prefix; the audit label stays for old
  rows), `Student` (keys out of `$fillable` and casts; two unused scopes delegate to `NamedStudent`), `StudentFactory`,
  `DemoSeeder`, the public page notes ("Nama siswa tampil bila ada persetujuan publikasi." removed), the PDF of
  "Cetak daftar".
- Deleted: `app/Actions/Students/UpdateConsent.php`, `tests/Feature/Admin/ConsentTest.php`,
  `tests/Feature/Humas/ConsentWithdrawnTest.php`. New: `tests/Feature/Admin/ConsentRemovedTest.php`,
  `tests/Feature/Humas/InactiveStudentTest.php`. About 25 test files adjusted (fixtures take `disabled:` instead of
  consent).
- Not changed on purpose: the DB columns (no migration; production migrations are forward-only), the permission
  `student.update` (now unused), `public_indexing` / `public_certificates` (Admin switches, still off).
- Side effects to know: a disabled student is now unnamed for Humas on Arsip, Jadwal and Apresiasi too (before, consent
  alone decided there) and cannot be put on an appreciation event; `composer dump-autoload -o` was run once because the
  optimized classmap still listed the deleted Action (vendor only).
- Docs: DECISIONS D-162 (+ dated pointers on D-16, D-38, D-39, D-71, D-75, D-156, U-04), SECURITY header + §3m-7 note,
  ARCHITECTURE, UI-MAP, PANDUAN-PENGGUNA (Humas, Admin, Masalah umum), CLAUDE.md.

## TEST-RESULTS
- Focused: the 11 files of points 1–4 → 113 passed after one fix (`KesiswaanScreensTest`, order); `HumasVerifikasiTest`
  8 passed after the same kind of fix.
- The sub-agent's full run (`--parallel --processes=6`): 3809 passed, 1 skipped, 2 failed: `HumasVerifikasiTest` (the
  queue order of point 4, fixed above) and a flaky `KeamananTest` case (a Faker name colliding with the test password;
  20 passed alone).
- Final full run (`--parallel --processes=6`, after every change of this round): **3811 passed, 1 skipped, 0 failed
  (22829 assertions)**, 751 s. `pint --test` clean (835 files).
- Email scenario on `sth-e2e` (`STH_OTP_TEST_MODE=true`, placeholder demo files): 147 mails, 0 missing, 0 leaks, 0 failed
  jobs, 1 unexpected = the same date-drift reminder of UX1 (step 6b, a demo registration whose fixed final date now lies
  3 days back), not caused by this round. Project removed afterwards (`down -v`).
- Not checked in a browser: the report card opened from the Beranda on a phone, the Humas screens without consent
  chips, Admin › Bantuan akun siswa without the consent box (all covered by tests).
- `npm run build` OK, php-fpm reloaded, queue asked to restart.
