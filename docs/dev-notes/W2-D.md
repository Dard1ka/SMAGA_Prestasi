# W2-D — public pages, Admin (pengaturan, pengguna), archive job, first-admin CLI, fix-ups  ·  status: done

## Files (owned by me)
- Public: `app/Livewire/Publik/{Beranda,PrestasiBidang,PrestasiSiswa,PemenangLomba,Peringkat}.php` + `Data/{PublicAchievements,PublicSettings}.php`,
  `resources/views/livewire/publik/**`, `app/Http/Middleware/PublicPageHeaders.php` (new, X-Robots-Tag), block `// --- W2-D: public pages ---` in `routes/web.php` (replaces the welcome route)
- Admin: `app/Livewire/Admin/{Pengaturan,Pengguna,Ringkasan}.php`, `Admin/Pengaturan/{Bobot,BatasArsip,Surat,Pengguna,Email,Publik}.php`,
  `Admin/Concerns/RequiresRecentPassword.php`, `resources/views/livewire/admin/**`, `routes/admin.php`
- Actions: `app/Actions/Settings/{PublishWeightVersion,UpdateLimits,UpdateSigner,UpdateLetterFormats,ReplaceSignatureImage,RemoveSignatureImage,UpdatePublicSettings}.php`,
  `app/Actions/Users/{CreateStaffAccount,ChangeStaffRole,ResetStaffAccount,RequestPrincipalReplacement,CancelPrincipalReplacement,ApplyPrincipalReplacement,ArchiveDueStudentAccounts,StaffRoles,AdminRecipients}.php`
- `app/Jobs/RecomputePoints.php` · `app/Console/Commands/{CreateAdmin,ArchiveDueStudents}.php` · block `// --- W2-D ---` in `routes/console.php`
- Policies: `app/Policies/{UserPolicy,SettingPolicy,WeightVersionPolicy,PrincipalReplacementPolicy}.php` (deny by default, `before()` refuses disabled/archived actors)
- New (outside the brief's list, additive): `app/Models/PrincipalReplacement.php`, `app/Notifications/Admin/{AdminNotice,WeightVersionPublished,SignatureReplaced,PrincipalReplacementNotice,StaffAccountChanged,ArchiveRunReport}.php`
- Migrations: `2026_10_01_300000_w2d_add_public_slug_to_students.php` (`students.public_slug`, 20 hex, unique, backfilled), `2026_10_01_300100_w2d_create_principal_replacements_table.php`
- Now mine: `app/Support/Navigation.php`, `resources/views/layouts/auth.blade.php`, `lang/**` (`lang/id/{validation,auth,passwords,pagination}.php`, `lang/id.json`, `lang/en/*` published)
- Docs: `docs/DEPLOY.md` §8.1, stale lines in `docs/SECURITY.md` / `ARCHITECTURE.md` / `design/DESIGN-SPEC-v2.md` (CLAUDE.md §8 list)
- Tests: `tests/Feature/Admin/*` (+ `AdminTestHelpers`), `tests/Feature/Publik/*`; `tests/Feature/ExampleTest.php` got `RefreshDatabase` (it GETs `/`)

## Contracts other areas can use
- Routes: `public.home` `/`, `public.prestasi` `/prestasi?bidang=&tahun=&tingkat=&siswa=`, `public.prestasi.siswa` `/prestasi/siswa/{slug}`,
  `public.lomba.pemenang` `/prestasi/lomba/{competition}`, `public.peringkat` `/peringkat?mode=siswa|kelas&bidang=&angkatan=&alumni=1` (throttle 60/min).
  `admin.beranda` `/admin` = W2-C's `Sekolah\Dashboard` (their request, UI-MAP #20) · `admin.ringkasan` `/admin/ringkasan` (Status sistem: blockers + Admin audit) ·
  `admin.pengaturan` `/admin/pengaturan?tab=bobot|batas|surat|pengguna|email|publik` · `admin.pengguna.index` `/admin/pengguna?q=` (both `password.confirm`).
- `App\Livewire\Publik\Data\PublicAchievements::flush()` — call after anything that changes public data (verification, consent, archive). Cache TTL 10 min.
- `PublicSettings::robots(bool $studentPage)`, `indexingAllowed()`, `certificatesAllowed()` (settings rows `public_indexing`, `public_certificates`, default false).
- `PublishWeightVersion::handle(User, array $weights /* group=>key=>"0,85" */, ?string $note): WeightVersion` → queues `RecomputePoints($versionId, $actorId)` (audit `weight.published`, `weight.recomputed`).
- `UpdateLimits::handle(User, int $days, int $months): array{recalculated:int}` (recomputes `archive_at` of non-archived students).
- `RequestPrincipalReplacement::handle(User $admin, User $new, string $reason)` (24 h delay; immediate when no Kepsek) · `CancelPrincipalReplacement` (Admin or the Kepsek being replaced) · `ApplyPrincipalReplacement::applyDue()` (scheduler, every 5 min).
- Artisan: `sth:arsip-otomatis [--date=Y-m-d]` (daily 01:00 Asia/Jakarta, withoutOverlapping, onOneServer) · `sth:create-admin [--name --username --email --additional --password-stdin]`.
- Trait `App\Livewire\Admin\Concerns\RequiresRecentPassword` (15-min password window inside actions, SECURITY §3m-9).

## Commands verified
- `docker compose run --rm --no-deps app php artisan test tests/Feature/Admin` → 96 passed (incl. LangIdTest 6) · `tests/Feature/Publik` → 52 passed ·
  `tests/Feature/DesignSystem` → 36 passed · `tests/Feature/Auth/AuthPagesTest.php` → 9 passed
- Full suite `php artisan test` (after the fix-ups) → 890 passed, 1 failed: `Siswa\Talenta\SaveTalentProfileTest` counts files in the real
  `storage/app/private/lampiran` (7 ≠ 2 while other agents' suites ran in parallel) — W2-A2's test, not caused by W2-D changes.
- Lead fix-ups: `AuthPagesTest` now asserts `/beranda` → `siswa.beranda` and `/siswa/tur` → `siswa.beranda?tur=1` (+ placeholder for a user
  without an area role); `NavigationTest` asserts "disabled exactly while the route is missing" and the real `siswa.lomba.index` URL.
- Pint: all my files + `bootstrap/providers.php` + `routes/web.php` (moved the W2-D and W2-C FQNs to `use` imports) → pass.
  Remaining `pint --test` issue at hand-off: `app/Models/Competition.php` (not mine).
- Browser (dev stack, operator): all Pengaturan tabs, /admin/pengguna, /admin/ringkasan at 1440 and 390 → no horizontal page scroll; live rumus example, modals open/close; /login split layout at 420 and 340 → brand bar no longer covers the heading.
  Public pages /, /prestasi (+`?siswa=`), /prestasi/siswa/{slug}, /prestasi/lomba/{ulid}, /peringkat (siswa, alumni, kelas) at 1440 and 390 → no horizontal scroll.

## Assumptions / deviations
- Scheduled archive does not call W1-C `ArchiveAccount` (needs a human actor with `user.archive`): `ArchiveDueStudentAccounts` repeats its steps (lock, status, audit `account.archived` with `automatic: true`, `RevokeUserSessions`). Command name `sth:arsip-otomatis` from SECURITY §3m-8.
- Ganti Kepala Sekolah follows SECURITY §3m-9 (24 h delay, cancellable, notifications) rather than an instant move; on apply the old holder loses the role (disabled if no role left), signer name/rank/NIP come from the new account's staff profile and the old signature image is detached (Admin uploads the new one).
- Staff role change: no second approval (UI-MAP) yet — audited + Admins/member notified + sessions revoked. `kepala_sekolah` only via the replacement flow; max 3 Admins; staff get an Admin-typed temporary password (never displayed) + `must_change_password`.
- Pengaturan got a 6th tab `publik` (indexing + certificate links, both off until U-04 is answered). Student pages are always noindex. Names only with `publish_consent`; others show "Siswa kelas XI" and still count.
- Certificate links need guest access in `FilePolicy` (W2-A1) — only rendered when Admin enables it; badge "Terverifikasi" otherwise.
- `routes/console.php`: Pint rewrote the W2-A2 block's fully-qualified `\Illuminate\Support\Facades\Schedule` to an import (behaviour unchanged).
- UI-MAP's `Admin\ArsipOtomatis` screen not built: the menu item "Arsip otomatis" opens Pengaturan › Batas & arsip (preview 30 days + recently archived).
- Public data rules chosen (please confirm): disabled/deleted accounts count in totals but never get a name/row/page; "Lomba diikuti" excludes
  `dibatalkan`/`ditolak`; anonymous rows show "Siswa kelas X/XI/XII" (or "Alumni"), never the exact class; ranking bidang filter counts only that
  bidang's points; school year of a result = lomba `final_on` (fallback `verified_at`); angkatan = year(grade12_start_date) − 2.
- `Peringkat\Tabel` child not built: the table is inline in `Publik\Peringkat`; `siswa.peringkat` can reuse `PublicAchievements::ranking()`.
- The 60/min throttle covers the page routes only, not Livewire's `/livewire-*/update` endpoint (needs a persistent Livewire middleware; lead).

## Requests for other owners
- @lead: add `SettingKey::PublicIndexing` / `PublicCertificates` (I use the string keys); bind `ReportDeadline`/`GraduationCalculator` from settings in a provider;
  log in DECISIONS: 24 h Kepsek replacement + signature detach, max 3 Admins, public indexing off by default; ratify `students.public_slug` + `principal_replacements` in ARCHITECTURE §4.
- @W2-B (VerifyReport) and whoever edits consent flags: call `PublicAchievements::flush()` after commit; rotate `students.public_slug` when publish consent is revoked (SECURITY §3m-7).
- @W2-A1 (FilePolicy): guests may view `report_evidence` certificate files of students with `publish_consent` only when `PublicSettings::certificatesAllowed()`; signature files never.
- @W1-D/S: `.tbl-wrap` should be `position: relative` — an `sr-only` span inside a `<th>` escapes the scroll container and widens the page on phones (I added `relative` on my `th`s).
- @I / W1-C: responses carry `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` twice (nginx + SecurityHeaders): set them in one place.
- @I: `deploy.sh --first` does not seed; DEPLOY.md §8.1 now says `php artisan db:seed --force` + `sth:create-admin`. Queue worker must drain `default` for `RecomputePoints`.
- @S (UI-MAP owner): add `admin.ringkasan`, tab `publik`, `/prestasi?siswa=`; `admin.beranda` → `Sekolah\Dashboard`.
