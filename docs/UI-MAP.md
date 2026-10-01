# UI-MAP.md — screens → routes → components → access → owner

Status (2026-10-01, after waves 1–2, checked by W3-A against `php artisan route:list`): rows marked
**built** exist (agent ID in brackets, details in `docs/dev-notes/<ID>.md`); rows without it are backlog
(CLAUDE.md §10). Maintained by S; route/middleware changes reviewed by the user. Route names, class names
and Action names below are the agreed targets. If an implemented name differs, the implementer updates
this file in the same PR (dev-notes record the reason).

Conventions
- Mockup boards: `docs/design/mockup-v2/<Board>.dc.html` (live canvas
  https://claude.ai/artifact/7m2XGmACRwHFfYQ9EaeV2m). 24 boards; titles are from `canvas.json`.
- Livewire 4 full-page components: `Route::livewire('/uri', Class::class)->name('…')`, class
  `App\Livewire\…`, view `resources/views/livewire/…`, layout `layouts::app|public|auth|mobile`
  (W1-D). Desktop and mobile boards of the same screen are one responsive component.
- Class names: namespace = role/area (`Publik`, `Siswa`, `Kesiswaan`, `Kepsek`, `TataUsaha`, `Humas`,
  `Guru`, `Admin`, shared `Sekolah`, `Akun`, `Lomba`, `Peringkat`), class = the screen, named after the
  mockup board (Indonesian domain words are fine). The existing auth screens keep their English names
  (`App\Livewire\Auth\*`, W1-C).
- Access = route middleware + Policy ability. Siswa routes use the group `area.siswa`
  (auth, active, activated, role:siswa); staff and admin routes use `area.staff` (auth, active, activated,
  staff.2fa) + `role:…`. Other people's records → 404. Permissions are the `App\Enums\Permission` values
  (matrix in `RoleName::defaultPermissions()`; Admin has all except `registration.approve`).
- Landing routes: after login `/beranda` redirects to `<area>.beranda`, so every area defines one
  (`siswa`, `kesiswaan`, `kepsek`, `tu`, `humas`, `guru`, `admin`). `App\Support\Navigation` still
  looks for the older names `kepsek.dashboard`, `admin.dashboard`, `tu.surat.index`,
  `humas.publikasi.index`, `guru.binaan` for those menu items: add the `.beranda` names as first
  candidates there in the same PR that creates the route.
- Owner = screen owner / backend owner. `user` = lead (backend core), `S` = frontend, `I` = deploy/QA.
- M = milestone in `docs/TEAM.md`. Wave: W1 = foundation (built), W2 = screens.
- Action names are targets in `app/Actions/<Area>/` (backend owner builds them, screens only call them).
  Built after wave 2: `app/Actions/{Accounts,Achievements,Competitions,Letters,Publications,Registrations,
  Reports,Settings,Students,Talent,Users}/*`; the rows below name the implemented classes.

## 1. Public (no login) · `routes/web.php` · layout `layouts::public` · throttle 60/min/IP

| # | Board (canvas title) | Route name · URL | Livewire class | Access / data rules | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 1 | `Main` (Publik · Beranda prestasi) | `public.home` · `/` | `Publik\Beranda` | guest; data only via `Publik\Data\PublicAchievements` (column allowlist, cache); counts include everyone, names only with consent (D-38) | S / user | M3 · W2 (**built** W2-D) |
| 2 | `Prestasi-Bidang` (Publik · Prestasi per bidang, drill-down) | `public.prestasi` · `/prestasi?bidang=&tahun=&tingkat=&siswa=` | `Publik\PrestasiBidang` | levels 1–3 (bidang → students → `?siswa=<slug>`); photos only with `photo_consent`, else initials | S / user | M3 · W2 (**built** W2-D) |
| 2a | level 3 of `Prestasi-Bidang` | `public.prestasi.siswa` · `/prestasi/siswa/{slug}` | `Publik\PrestasiSiswa` | random `public_slug` (rotated when consent is revoked), always `noindex`; rank among active students (D-37); certificate link `files.public` only with consent of every team member + Admin switch (D-39), else "Terverifikasi" badge | S / user | M3 · W2 (**built** W2-D, W3-A) |
| 2b | no board (D-31, reuse drill-down style) | `public.lomba.pemenang` · `/prestasi/lomba/{competition}` | `Publik\PemenangLomba` | winners + capaian of one lomba, same consent rules | S / user | M3 · W2 (**built** W2-D) |
| 3 | `Peringkat` (Publik · Peringkat kumulatif) | `public.peringkat` · `/peringkat?mode=&bidang=&angkatan=&alumni=1` | `Publik\Peringkat` (table inline; `Peringkat\Tabel` child not built) | cumulative, all years; alumni (chip) only with "Tampilkan alumni" (D-37); modes siswa/kelas; filters bidang/angkatan | S / user | M2 · W2 (**built** W2-D) |
| – | no board | `public.surat.verifikasi` · `/verifikasi-surat/{code}` | controller `LetterVerificationController` | only sent letters are "Sah"; type + number + date + lomba, no names | user | M1 · W2 (**built** W2-C) |

## 2. Account & onboarding · `routes/web.php` (Fortify + Livewire) · layouts `layouts::auth`, `layouts::mobile`

| # | Board | Route name · URL | Component | Access | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 4 | `Masuk` (Masuk NIS/NISN) | `login` · `/login` (Fortify; also `/two-factor-challenge`, `/user/confirm-password`, `logout`) | Blade `auth.login` via `Fortify::loginView()` | guest; one identifier field (students NIS/NISN, staff username/email), generic error, rate limit per account bucket + IP (K3, K11) | user / user | M0 · W1 (built) |
| 5 | `Aktivasi` (Aktivasi akun pertama, mobile) | `aktivasi` · `/aktivasi` | `Auth\Activation` (new password → email → 6-digit code → done) | auth + active; only screen reachable until done (`activated` middleware, K4); OTP test mode local only | user / user | M0 · W1 (built) |
| – | no board | `password.request` · `/lupa-kata-sandi` (OTP reset) | `Auth\ForgotPassword` | guest; same response whether or not the account exists (SECURITY §3b) | user / user | M0 · W1 (built) |
| – | no board | `two-factor.setup` · `/akun/2fa` | `Auth\TwoFactorSetup` | staff; `staff.2fa` redirects here until TOTP is confirmed; `password.confirm` | user / user | M0 · W1 (built) |
| – | no board | `/beranda` (Fortify `home`) | closure redirect | sends each role to its `<area>.beranda` | user | M0 · W1 (built) |
| 6 | `Tur-Menu-Mobile` (Tur singkat menu, mobile) | overlay on `siswa.beranda` (`?tur=1`); `siswa.tour` · `/siswa/tur` redirects there after activation | `Siswa\TurMenu` | siswa; shown until "Lewati"/"Mulai" stores `users.tour_seen_at` | S / user | M1 · W2 (**built** W2-A1) |
| 7 | `Tur-Menu-Desktop` (Tur singkat menu, desktop) | overlay on `siswa.beranda` | `Siswa\TurMenu` (same class, desktop layout) | same | S / user | M1 · W2 (**built** W2-A1) |
| – | no board | `akun.keamanan` · `/akun/keamanan` | `Akun\Keamanan` | any role; change password, change email (password + OTP), link to `/akun/2fa` | S / user | M1 · W2 (not built) |
| – | no board (bell) | `notifikasi.index` · `/notifikasi` | `Akun\Notifikasi` | own database notifications only | S / user | M1 · W2 (not built) |
| – | no board | `files.show` · `/berkas/{file}` | `FileController@show` | policy per `FilePurpose`; `nosniff`, `sandbox`, `no-store` (SECURITY §3d) | user | M1 · W2 (**built** W2-A1) |
| – | no board | `files.public` · `/berkas/publik/{file}` | `FileController@publik` | guests; `FilePolicy::viewPublic` only (verified certificate, consent of every member, Admin switch), else 404 | user | M3 · W2 (**built** W2-A1) |

## 3. Siswa · `routes/siswa.php` · prefix `/siswa`, names `siswa.*`, `->middleware('area.siswa')` · layout `layouts::app` (mobile: `layouts::mobile` + tab bar)

| # | Board | Route name · URL | Livewire class | Access (permission / policy) · Actions | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 8 | `Beranda-Siswa` (Siswa · Beranda) | `siswa.beranda` · `/siswa` | `Siswa\Beranda` | own data; points banner (personal rank among active students, `PersonalRank`, D-37), next action card, open invitations | S / user | M1 · W2 (**built** W2-A1) |
| 9 | `Beranda-Mobile` (Siswa · Beranda, mobile) | same route | `Siswa\Beranda` (responsive) | same | S / user | M1 · W2 (**built** W2-A1) |
| 10 | `Katalog-Lomba` (Siswa · Cari lomba, katalog + usulkan) | `siswa.lomba.index` · `/siswa/lomba` | `Siswa\Lomba\Katalog` + child `Lomba\UsulanForm` | `CompetitionPolicy::viewAny` (only `dipublikasikan`); usulan needs `competition.create` → `SubmitCompetition` → `menunggu_verifikasi` (D-27), 5/day, brochure via `App\Domain\Files`, URL `ExternalUrl` (https only), never fetched | S / user | M1 · W2 (**built** W2-A1) |
| 11 | `Detail-Lomba` (Siswa · Detail lomba + daftar tim) | `siswa.lomba.show` · `/siswa/lomba/{competition}` | `Siswa\Lomba\Detail` + child `Siswa\Pendaftaran\Form` | `CompetitionPolicy::view`; `registration.create` + `RegistrationPolicy::create`; `CreateRegistration` (guru pembimbing required, D-35); `InviteMembers` **not built**; member search: active students only, name ≥ 3 letters, exact NIS/NISN, ≤ 10 results, returns ULID + name + class only, 30/min (§3m-3) | S / user | M1 · W2 (**built** W2-A1) |
| – | no board (link of `TeamInvitation`) | `siswa.undangan.show` · `/siswa/undangan/{registration}` | `Siswa\KonfirmasiTim` | invitee only (else 404); `ConfirmMembership`, `DeclineMembership` (= `RespondToInvitation`, D-36) | S / user | M1 · W2 (**built** W2-A1) |
| 12 | `Lomba-Saya` (Siswa · Lomba saya: status, surat, lapor) | `siswa.lomba-saya.index` · `/siswa/lomba-saya` and `siswa.lomba-saya.show` · `/siswa/lomba-saya/{registration}` | `Siswa\LombaSaya\Index` (list + detail pane + `<x-sth.tracker>`) + embedded `Siswa\LombaSaya\LaporHasil` | `RegistrationPolicy::view` (members; declined → 404); `CancelRegistration` (leader, while `menunggu_konfirmasi` or `menunggu_kepsek`, D-33); letters via `files.show` after TU sends them | S / user | M1 (status, letters) · M2 (report) · W2 (**built** W2-A2) |
| 13 | `Lapor-Hasil-Mobile` (Siswa · Laporkan hasil, mobile) | `siswa.lapor` · `/siswa/lomba-saya/{registration}/lapor` | `Siswa\LombaSaya\LaporHasil` | `report.submit` + `ReportPolicy::create` (leader, status `menunggu_laporan`, first report or after perlu_revisi; after `final_on` + 45 days still accepted but flagged `reports.late`, `ReportDeadline`); `SubmitReport` — built W2-A2; certificate/photo uploads re-encoded, EXIF stripped | S / user | M2 · W2 (**built** W2-A2) |
| 14 | `Portofolio` (Siswa · Portofolio) | `siswa.portofolio` · `/siswa/portofolio` | `Siswa\Portofolio` | own verified achievements, points with weight snapshot, rank among active students (`PersonalRank`, D-37), PDF download | S / user | M2 · W2 (**built** W2-A2) |
| 15 | `Profil-Talenta` (Siswa · Profil talenta, penjaringan) | `siswa.talenta` · `/siswa/talenta` | `Siswa\ProfilTalenta` | `talent.apply` + `TalentApplicationPolicy::create` (several types allowed, kelas XII allowed, unique type+branch incl. OLPAIN without branch); `ApplyTalent`, `CancelTalentApplication` (while terdaftar), `SaveTalentProfile` (+ `TalentProfilePolicy`) | S / S (user reviews) | M3 · W2 (**built** W2-A2) |
| – | sidebar "Peringkat" | `siswa.peringkat` · `/siswa/peringkat` | `Siswa\Peringkat` (child `Peringkat\Tabel`, highlights "kamu") | same data rules as public; can reuse `PublicAchievements::ranking()` | S / user | M2 · W2 (not built; menu falls back to `public.peringkat`) |
| – | sidebar "Bantuan" | `siswa.bantuan` · `/siswa/bantuan` | Blade page | static help text from the user guide | S (content: I) | M4 · W2 (**built** W2-A1; final text from the user guide: I) |

## 4. Staf · `routes/staff.php` · `->middleware(['area.staff', 'role:…'])` · layout `layouts::app`

### 4a. Kesiswaan · prefix `/kesiswaan`, names `kesiswaan.*`, `role:kesiswaan|admin`

| # | Board | Route name · URL | Livewire class | Access (permission / policy) · Actions | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 16 | `Kesiswaan-Kelola-Lomba` (Kesiswaan · Kelola lomba & usulan) | `kesiswaan.lomba.index` · `/kesiswaan/lomba?tab=katalog\|verifikasi` | `Kesiswaan\Lomba\Index` + drawer `Kesiswaan\Lomba\Form` | `competition.create/update/verify/close` + `CompetitionPolicy`; `CreateCompetition` (always → queue, D-27), `UpdateCompetition`, `VerifyCompetition` (`Dipublikasikan` requires the weight/date fields; `Ditolak` = `RejectCompetition`, note required), `CloseCompetition`; URLs validated with `ExternalUrl`. The verification tab (menu label "Usulan lomba" in `Navigation`) lists **every** `menunggu_verifikasi` lomba (siswa usulan, guru, staff, Kesiswaan's own). Weight fields audited. Admin uses the same route. | S / user | M1 · W2 (**built** W2-B) |
| 17 | `Kesiswaan-Verifikasi-Hasil` (Kesiswaan · Verifikasi hasil) | `kesiswaan.verifikasi.index` · `/kesiswaan/verifikasi-hasil` and `kesiswaan.verifikasi.show` · `/kesiswaan/verifikasi-hasil/{report}` | `Kesiswaan\VerifikasiHasil` | `report.verify` + `ReportPolicy::verify` (own binaan read-only, §3c-10); `VerifyReport` (one achievement per member, full points, `PointCalculator::breakdown()` with the active `WeightVersion`), `RequestReportRevision`, `RejectReport` (note required; registration → `selesai` without points, D-34) | S / user | M2 · W2 (**built** W2-B) |
| 18 | `Kesiswaan-Talenta` (Kesiswaan · Talenta siswa, pemetaan & seleksi) | `kesiswaan.talenta.index` · `/kesiswaan/talenta?tahap=&jenis=&cabang=&kelas=&q=` | `Kesiswaan\Talenta` | `talent.manage` / `talent.assess`; `MoveTalentStage` (terdaftar → pemetaan → seleksi → pembinaan), `RejectTalentApplication` (tidak_lolos), `UpdateTalentAssessment` (= UI-MAP's `UpdateTalentStatus`); Excel export with formula escaping; NIS masked in lists | S / S (user reviews) | M3 · W2 (**built** W2-B) |
| – | no board | `kesiswaan.beranda` · `/kesiswaan` | `Kesiswaan\Beranda` | queues + stat tiles | S / user | M1 · W2 (**built** W2-B) |
| – | sidebar "Database prestasi" | `kesiswaan.prestasi.index` · `/kesiswaan/prestasi` | `Kesiswaan\DatabasePrestasi` | `achievement.view_all`, export `achievement.export` | S / user | M3 · W2 (not built) |
| – | sidebar "Apresiasi" | `kesiswaan.apresiasi.index` · `/kesiswaan/apresiasi` | `Humas\Apresiasi` (shared) | `appreciation.manage` | S / user | M3 · W2 (**built** W2-B route, W2-C screen) |
| – | no board (D-26) | `kesiswaan.import` · `/kesiswaan/import-siswa` | `Akun\ImportSiswa` (same as #23) | `student.import` | S / user | M1 · W2 (**built** W2-B) |
| – | no board (K9, K2) | `kesiswaan.aktivasi.index` · `/kesiswaan/aktivasi` | `Akun\StatusAktivasi` | `student.view`; per-class activation report, masked email, `ReopenActivationWindow` (72 h, reason required) | S / user | M1 · W2 (not built) |

### 4b. Kepala Sekolah · prefix `/kepsek`, names `kepsek.*`, `role:kepala_sekolah`

| # | Board | Route name · URL | Livewire class | Access (permission / policy) · Actions | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 19 | `Kepsek-Persetujuan` (Kepala Sekolah · Persetujuan) | `kepsek.persetujuan.index` · `/kepsek/persetujuan` | `Kepsek\Persetujuan` | `registration.approve` + `RegistrationPolicy::decide` (**Kepala Sekolah only; Admin denied**, D-01/§3m-9); `ApproveRegistration`, `RejectRegistration` (reason required); bulk approve = one authorized Action call per row | S / user | M1 · W2 (**built** W2-C) |
| 20 | `Dashboard-Sekolah` (Kepala Sekolah · Dashboard) | `kepsek.beranda` · `/kepsek` (`?tahun=`) | `Sekolah\Dashboard` (shared with Admin) | `dashboard.view`; aggregate queries only; "Data contoh" chip only when `APP_ENV=local` | S / user | M3 · W2 (**built** W2-C) |
| – | sidebar "Prestasi per bidang" | `kepsek.prestasi` · `/kepsek/prestasi` | `Sekolah\PrestasiBidang` (internal variant of #2, staff layout) | staff may see all names; still no NIS/NISN in lists | S / user | M3 · W2 (not built) |
| – | sidebar "Laporan" | `kepsek.laporan` · `/kepsek/laporan` | `Sekolah\Laporan` | `achievement.export`; exports logged + watermarked | S / user | M3 · W2 (not built) |

### 4c. Tata Usaha · prefix `/tu`, names `tu.*`, `role:tata_usaha`

| # | Board | Route name · URL | Livewire class | Access (permission / policy) · Actions | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 21 | `TU-Surat-Tugas` (Tata Usaha · Nomor surat → PDF) | `tu.beranda` · `/tu` (antrean surat) and `tu.surat.show` · `/tu/surat/{registration}` | `TataUsaha\SuratTugas` | `letter.generate`, `letter.send` + `LetterPolicy::create` (registration `disetujui`, checked server-side); `IssueLetters` (two manual numbers validated with `LetterNumber::pattern()`, unique per type+year, hint from `Letter::lastSequence()`; generates 1 siswa letter listing all members + 1 guru letter with the signature image and QR), then `SendLetters` (second step, → `surat_terbit`, D-43). TU never downloads the signature file itself. | S / user | M1 · W2 (**built** W2-C) |
| – | sidebar "Arsip surat" | `tu.surat.arsip` · `/tu/surat/arsip` | `TataUsaha\ArsipSurat` | `letter.view`; issued letters, read-only (immutable) | S / user | M1 · W2 (**built** W2-C) |
| – | sidebar "Template surat" | `tu.surat.template` · `/tu/surat/template` | Blade preview | read-only | S / user | M4 · W2 (not built) |

### 4d. Humas · prefix `/humas`, names `humas.*`, `role:humas`

| # | Board | Route name · URL | Livewire class | Access (permission / policy) · Actions | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 22 | `Humas-Publikasi` (Humas · Publikasi & apresiasi) | `humas.beranda` · `/humas` (antrean publikasi) | `Humas\Publikasi` | `publication.manage` + `PublicationPolicy` (only verified achievements **with** publish consent); `SchedulePublication`, `PublishPublication`, `GenerateFlyer` (Intervention Image; flyers stay on the private disk for now) | S / user | M3 · W2 (**built** W2-C) |
| – | sidebar "Jadwal", "Apresiasi", "Arsip" | `humas.jadwal`, `humas.apresiasi.index`, `humas.arsip` | `Humas\Jadwal`, `Humas\Apresiasi`, `Humas\Arsip` | `appreciation.manage`; `CreateAppreciationEvent`, `RecordAppreciation`, `CompleteAppreciationEvent` | S / user | M3 · W2 (**built** W2-C) |

### 4e. Guru pembimbing · prefix `/guru`, names `guru.*`, `role:guru` (no boards; spec §4 menu)

| Route name · URL | Livewire class | Access | Owner | M · Wave |
|---|---|---|---|---|
| `guru.beranda` · `/guru` (siswa binaan) | `Guru\Binaan` | registrations where `mentor_id` = me, own guru letters, reports of binaan (read) | S / user | M1 landing · M3 full · W2 (not built: guru accounts land on the `/beranda` placeholder) |
| `guru.lomba.index` · `/guru/lomba` | `Guru\Lomba` + `Lomba\UsulanForm` | read catalog; `competition.create` → `menunggu_verifikasi` (D-27); `Lomba\UsulanForm` is ready to embed | S / user | M1 · W2 (not built) |

## 5. Admin · `routes/admin.php` · prefix `/admin`, names `admin.*`, `->middleware(['area.staff', 'role:admin'])` · `password.confirm` for sensitive actions

| # | Board | Route name · URL | Livewire class | Access (permission / policy) · Actions | Owner | M · Wave |
|---|---|---|---|---|---|---|
| 23 | `Admin-Import-Siswa` (Admin · Import siswa, Excel) | `admin.import` · `/admin/import-siswa` | `Akun\ImportSiswa` (also at `kesiswaan.import`) | `student.import`; `ImportStudents` (built: .xlsx/.csv, formulas never evaluated, per-row errors, NIS/NISN unique, computed grade-12 and archive dates → `StudentImportReport`; initial passwords hashed, never shown). Screen: upload → preview (valid/invalid rows) → import | S / user | M1 · W2 (**built** W2-B) |
| 24 | `Admin-Pengaturan` (Admin · Pengaturan) | `admin.pengaturan` · `/admin/pengaturan?tab=bobot\|batas\|surat\|pengguna\|email\|publik` (`password.confirm`) | `Admin\Pengaturan` with tab children `Admin\Pengaturan\{Bobot,BatasArsip,Surat,Pengguna,Email,Publik}` | `weight.manage`: `PublishWeightVersion` (+ queued `RecomputePoints`, audited, Kepsek notified); `setting.manage`: `UpdateLimits` (`report_deadline_days` default **45**, archive months 18; bound into `ReportDeadline`/`GraduationCalculator` by `AppServiceProvider`), `UpdatePublicSettings` (tab `publik`: `public_indexing`, `public_certificates`, both off); `signature.manage`: `ReplaceSignatureImage` (PNG ≤ 1 MB, private, versioned), `RemoveSignatureImage`, `UpdateSigner`, `UpdateLetterFormats`; Pengguna tab = the `admin.pengguna.index` screen; Email tab shows OTP test-mode status read-only (env flag, not a setting) | S / user | M1 (surat, pengguna) · M2 (bobot, batas) · W2 (**built** W2-D) |
| – | `Dashboard-Sekolah` (board #20) | `admin.beranda` · `/admin` | `Sekolah\Dashboard` | `dashboard.view`; aggregate only | S / user | M3 · W2 (**built** W2-C/W2-D) |
| – | no board ("Status sistem") | `admin.ringkasan` · `/admin/ringkasan` | `Admin\Ringkasan` | blockers (no Kepsek, no signature, …) + latest Admin audit entries | S / user | M1 · W2 (**built** W2-D) |
| – | sidebar "Kelola lomba" | → `kesiswaan.lomba.index` | #16 | Admin allowed by `role:kesiswaan\|admin` | – | M1 (**built**) |
| – | sidebar "Pengguna & peran" | `admin.pengguna.index` · `/admin/pengguna` (`password.confirm`; 15-min window inside each action) | `Admin\Pengguna` (wraps `Admin\Pengaturan\Pengguna`) | `user.manage`: `CreateStaffAccount` (max 3 Admins, D-41), `ChangeStaffRole` (second approval **not built**); `user.reset_password`: `ResetStaffAccount`, `ResetStudentAccount` (see W1-C note on K10); `user.archive`: `DisableAccount`, `ArchiveAccount` (D-30, reason); `user.replace_principal`: `RequestPrincipalReplacement` / `CancelPrincipalReplacement` / `ApplyPrincipalReplacement` (24 h, D-40); `student.update`: consent toggles via `Students\UpdateConsent` (W3-A, audited, rotates `public_slug` on revoke) | S / user | M1 · W2 (**built** W2-D, W3-A) |
| – | sidebar "Arsip otomatis" | `admin.arsip.index` · `/admin/arsip` | `Admin\ArsipOtomatis` | `user.archive`; preview "akan diarsipkan dalam 30 hari" (`Student::dueForArchive()`), run history, `UnarchiveStudent` / change archive date (reason) | S / user | M2 · W2 (not built; menu opens Pengaturan › Batas & arsip, job `sth:arsip-otomatis` built W2-D) |
| – | no board | `admin.aktivasi.index` · `/admin/aktivasi` | `Akun\StatusAktivasi` (shared with Kesiswaan) | K9 report, K7 alerts | S / user | M1 · W2 (not built) |
| – | no board | `admin.audit.index` · `/admin/audit` | `Admin\AuditLog` | `audit.view`; read-only, no edit/delete | S / user | M4 · W2 (not built; `admin.ringkasan` shows the latest Admin entries) |

## 6. Developer only · `routes/dev.php` (loaded only when `APP_ENV=local`)

| Route · URL | What | Owner | M · Wave |
|---|---|---|---|
| `dev.components` · `/_dev/components` (`?role=…`), `dev.components.public` · `/_dev/components/public`, `dev.components.auth` · `/_dev/components/auth`, `dev.components.mobile` · `/_dev/components/mobile` | design-system showcase of every `<x-sth.*>` component and layout | S (built by W1-D) | M0 · W1 (built) |

## 7. Board checklist (24/24 mapped)
Main (#1) · Prestasi-Bidang (#2) · Peringkat (#3) · Masuk (#4) · Aktivasi (#5) · Tur-Menu-Mobile (#6) ·
Tur-Menu-Desktop (#7) · Beranda-Siswa (#8) · Beranda-Mobile (#9) · Katalog-Lomba (#10) ·
Detail-Lomba (#11) · Lomba-Saya (#12) · Lapor-Hasil-Mobile (#13) · Portofolio (#14) ·
Profil-Talenta (#15) · Kesiswaan-Kelola-Lomba (#16) · Kesiswaan-Verifikasi-Hasil (#17) ·
Kesiswaan-Talenta (#18) · Kepsek-Persetujuan (#19) · Dashboard-Sekolah (#20, also Admin) ·
TU-Surat-Tugas (#21) · Humas-Publikasi (#22) · Admin-Import-Siswa (#23) · Admin-Pengaturan (#24).

Mockup/spec text that is outdated and must not be implemented as drawn: Admin-Pengaturan "Batas &
arsip" shows 30 days (use 45 days after the final, D-25); the WICE deadline "24 Okt" in Beranda/Lomba
saya boards is **8 Nov 2026** in the demo data; DESIGN-SPEC-v2 §1 calls archived accounts "read-only"
(they cannot log in, D-29); the Kesiswaan queue is labelled "Usulan siswa" but must list every pending
upload from any role (D-27).
