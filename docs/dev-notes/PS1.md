# PS1 — Profil saya + Data diri saat aktivasi (D-152)  ·  status: built, not committed

The lead's request of 2026-10-15 after the check of the school's two lists: "buatkan menu Profil saya di atas
Notifikasi (berisi data profil + Keamanan akun) dan wajibkan tanggal lahir + no HP saat aktivasi akun siswa".
Built on the demo copy checkout (`feat/fs1-feedback-sekolah` at `1741687` + the uncommitted LCT demo lomba).

## Files
- `database/migrations/2026_10_15_100000_ps1_add_profile_confirmed_at_to_students.php` — `students.profile_confirmed_at`
  (nullable timestamp, not fillable, no backfill)
- `app/Domain/Accounts/StudentProfileInput.php` — the one rule for birth date (`Y-m-d`, real day, 10–30 years ago) + No HP
  (PhoneNumber); `errors()`, `parseBirthDate()`, `withinRange()`
- `app/Actions/Accounts/DetermineActivationStep.php` — `PROFILE`, `profileOpen(User)` (pending student, not confirmed)
- `app/Actions/Accounts/CompleteActivation.php` — `confirmProfile(User, string $birthDate, string $phone)`; `bindEmail()` and
  `whenPasswordAlreadyChosen()` do not activate a student with an open profile; `changePassword()` before it →
  `MSG_PROFILE_FIRST`
- `app/Actions/Accounts/UpdateStudentProfile.php` — Profil saya save; audit `student.profile_updated` `{fields: [...]}`
- `app/Policies/StudentPolicy.php` — `updateProfile` (= the owner rule of `updatePhone`)
- `app/Livewire/Auth/Activation.php` + `resources/views/livewire/auth/activation.blade.php` — `STEP_PROFILE` (value 5,
  shown as step 3 of 4), `birth_date` / `phone`, `saveProfile()`; step bar from `$steps` / `$position`
- `app/Livewire/Akun/Profil.php` + `resources/views/livewire/akun/profil.blade.php` — `akun.profil`, `Profil::tabs()`
- `app/Livewire/Akun/Keamanan.php` + view — title "Profil saya", the same tabs (functions unchanged)
- `routes/web.php` (`akun.profil`), `resources/views/components/sth/topbar.blade.php` (menu: Profil saya, Notifikasi,
  Keluar), `app/Support/Navigation.php` (siswa › Akun: Profil talenta (icon bulb), Profil saya, Notifikasi, Bantuan),
  `app/Http/Middleware/EnsureTalentChosen.php` (exempt `akun.profil`), `app/Livewire/Admin/AuditLog.php` (two labels),
  `resources/views/siswa/bantuan.blade.php`, `database/factories/StudentFactory.php` (`profileConfirmed()`),
  `tests/E2E/support/email-scenario.php` (`finishActivation` confirms the profile)
- Tests: `tests/Unit/Domain/Accounts/StudentProfileInputTest.php`, `tests/Feature/Auth/ActivationProfileTest.php`,
  `tests/Feature/Akun/ProfilTest.php`; updated for the new order: `ActivationTest`, `AuthPagesTest`,
  `ForgotPasswordTest`, `Aktivasi/AccountControlsTest`, `DesignSystem/NavigationTest`
- Docs: DECISIONS D-152 (§D16), CLAUDE.md §5/§8/§10, UI-MAP, PANDUAN-PENGGUNA

## Contracts
- Activation order: email → code → (student) Data diri → password. `DetermineActivationStep` returns `profile` only while
  the account is `pending_activation` and the student row has no `profile_confirmed_at`; middleware and login only check
  `!== null`, so they send such a student to `/aktivasi` as before.
- `CompleteActivation::confirmProfile()` errors on `birth_date` / `phone`; audit `activation.profile_confirmed` with
  `birth_date_changed` / `phone_changed` only. Activates when no password step is left (`must_change_password` false).
- Profil saya: `Akun\Profil::tabs()` = Data diri (`akun.profil`) + Keamanan akun (`akun.keamanan`).

## Interpretations (coordinator choices, *sementara* in D-152)
- The student may correct the birth date (activation and Profil saya); the yearly import never overwrites it (D-81).
- Age window 10–30 years before today.
- Active students without a No HP are not forced (no gate); the registration panel still asks the No HP (D-149).
- The talent requirement stays kelas X only (D-144); the school's line said "setiap siswa".
- The registration panel still says "Data salah? Hubungi Kesiswaan." under the read-only birth date (not changed).

## Follow-up 2026-10-16: Talenta saya (D-153)
The lead: "profil talenta dipindah ke menu profil saya menjadi talenta saya dan itu harus diisi saat setelah aktivasi
berhasil"; asked who must fill it, the lead chose "semua siswa tanpa talenta" (also students already active).
- `Akun\Profil::tabs(?User)`: Data diri · **Talenta saya** (siswa only, `siswa.talenta`) · Keamanan akun; passed the
  user by `Profil`, `Keamanan` and `Siswa\ProfilTalenta` (title "Profil saya", the tabs above its old grid).
- `Navigation`: the sidebar item `profil-talenta` is gone (siswa › Akun = Profil saya, Notifikasi, Bantuan); `profil-saya`
  also matches `siswa.talenta` / `siswa.profil-talenta`; the held phone tab is named "Talenta saya".
- `EnsureTalentChosen::applies()`: every student who can log in and has no talent (no kelas X condition); the activation
  done screen and "Mulai" lead there (the gate keeps the tour address and `ProfilTalenta::daftar()` returns to it).
- `Siswa\TurMenu`: tip 5 = Profil saya (same position), the closing tip points at Bantuan at 490 px (one item up);
  the phone tip of Portofolio no longer speaks of talents.
- Copy: Bantuan, the activation done screen, the Portofolio card "Talenta saya", PANDUAN-PENGGUNA, UI-MAP.
- Open for the lead: Kesiswaan › Database talenta siswa still has the tab "Belum mengisi (kelas X)" (only kelas X
  rows); with D-153 it could list every class. Not changed without a request.
- Tests: `HasilFixtures::student()` and `LombaFixtures::student()` give a student outside kelas X one talent ("Lainnya
  (Membaca)", `TalentGate::pass`), `talent: false` for none (the talent tests); three tests on other helpers pass the
  gate themselves (`StaffTwoFactorTest`, `CspSafeTest`, `SiswaPeringkatTest`); the old "kelas XI/XII are never held"
  tests now assert D-153.

- TEST-RESULTS of D-153 (2026-10-16): full suite `--parallel --processes=8` → **3752 passed, 1 skipped (22500
  assertions)**, 994 s; `pint` clean. `reload.bat` run on the demo stack; as the demo TU account Profil saya shows the
  tabs Data diri and Keamanan akun only. Not seen in a browser: a student's Talenta saya tab, the held student and the
  activation done screen (no student password of the demo copy is known here; render tests only).

## Demo copy database, 2026-10-16
The lead saw a 403 for Tata Usaha on `/tu/persetujuan` (through the demo tunnel). Cause: the demo copy's database came
from the 2026-10-11 dump and only `migrate` had run after the FS1 code arrived; FS1 deploy steps 3–4 were missing, so TU
had no `registration.approve`, Humas no `report.verify`, every role still held `competition.create`, and the talent
type "Lainnya" did not exist. Fixed by `db:seed --class=RoleSeeder --force` and `db:seed --class=TalentSeeder --force`
on the local MySQL; `/tu/persetujuan` then opened as the demo TU account (7 waiting registrations). No code change.

## TEST-RESULTS (2026-10-15, on this machine)
- Full suite `--parallel --processes=8` → **3749 passed, 1 skipped, 2 failed (22473 assertions)**, 962 s; the two failures
  were `Kesiswaan/Fs1QaTalentaTest` (the old menu key `keamanan`, the activation without Data diri), fixed in that file and
  then green alone (11 passed). The suite was not run a second time.
- `pint --test` clean (825 files); `npm run build` OK; the migration ran on the local MySQL (demo copy); `reload.bat` run.
- Browser (the app's pane, demo Kesiswaan account): Profil saya › Data diri (staff, read-only), the avatar menu
  (Profil saya · Notifikasi · Keluar), the tab Keamanan akun under the title Profil saya, Data diri at 375 px (no
  sideways scroll). **Not seen in a browser:** the student's Data diri form on Profil saya and the activation step
  "Data diri" (render and Livewire tests only: no student password of the demo copy is known here).
- Not run: the email scenario (its `finishActivation` was edited), the Playwright smoke, `composer audit`.
