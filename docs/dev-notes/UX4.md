# UX4: persiapan server sekolah (D-166…D-169) · status: committed 2026-10-21

The lead's requests of 2026-10-21 after UX3, on the Smaga4 checkout of `feat/fs1-feedback-sekolah` (uncommitted, on top
of UX1–UX3) and copied into the sibling folder `smaga_server` (see `UX3.md` §1). Decisions: DECISIONS §D23. Built by four
sub-agents in parallel on separate files, integrated and documented by the coordinator.

## 1. Classes managed by the Admin (D-166)
- Admin › Data › **Kelas** (`/admin/kelas`, `admin.kelas.index`, `App\Livewire\Admin\Kelas`): add (tingkat + part after
  the dash), rename (part after the dash only), delete (only a class no student row references, also alumni and
  soft-deleted). Permission `classroom.manage` (Admin only, re-run `RoleSeeder`), `ClassroomPolicy`, Actions in
  `app/Actions/Classrooms/` (`ClassroomName` holds the name rule), audit `classroom.created|renamed|deleted`, public cache
  flushed after rename/delete, dialogs `kelas-ganti-nama` / `kelas-hapus`, stale-tab toasts.
- X-TAHFIDZ and X-OLIMP removed: `ClassroomSeeder` makes 34 classes; migration
  `2026_10_21_100100_remove_tahfidz_olimp_classrooms` deletes them when empty. On the lead's local MySQL the demo
  student Galang was moved to X-A first; DemoSeeder puts him there.
- `ImportStudents` now finds a class with a space in its name ("XI-IPA 1" = "xi-ipa1"): it compared the cell without
  spaces against names with spaces.
- Tests: `tests/Feature/Admin/KelasTest.php`, `tests/Feature/DataLayer/KelasHurufTest.php`, `ImportStudentsTest` (space).

## 2. Admin student reset is "Reset kata sandi & email" again (D-167)
- `ResetStudentAccount::handle(User, User, string $reason, string $temporaryPassword)`: the Admin types the temporary
  password (min 8, nothing else); the email is removed, the account goes back to `pending_activation`, the student
  activates again (email → code → new password; Data diri and talents stay, so the profile step is skipped).
- Removed: the generated password ("bakuro4827") on the screen, its one-time dialog `siswa-sandi`, the copy button and
  `data-sth-copy-flash`, `detachesEmail()`. `GenerateTemporaryPassword` stays only because the E2E email scenario uses it.
- The initial student password stays one value for every student from `.env` (D-138, the lead confirmed it).
- Tests: `BantuanAkunSiswaTest` (rewritten), `AccountAdminActionsTest`, `AccountsQa9Test`, `UsersActionsTest`,
  `PenggunaQa9Test`, `AccountControlsTest`, `ActivationTest`, `ActivationQa10Test`, `LoginLockoutQa10Test`,
  `StudentTwoFactorTest`; `SalinKataSandiTest` deleted.

## 3. Switches for 2FA and `/_dev` (D-168)
- `STH_STAFF_2FA` (config `fortify.sth.staff_2fa`, default true; read only through
  `EnsureStaffTwoFactor::featureEnabled()`): off = no Fortify 2FA feature or routes, no challenge (the login pipeline skips
  `RedirectIfTwoFactorAuthenticatable`), `/akun/2fa` 404, `staff.2fa` lets everyone through, no 2FA text on Keamanan akun,
  Status sistem, Pengguna & peran or the staff reset notice.
- `STH_DEV_PAGES` (config `sth.dev_pages`, default true): off = `routes/dev.php` is not loaded, also in local.
- `phpunit.xml` forces `STH_STAFF_2FA`, `STH_REQUIRE_STAFF_2FA` and `STH_DEV_PAGES` to true (the developer `.env` now has
  them false). Off-state tests: `tests/Support/WithoutStaffTwoFactor.php` (trait, boots the file with the switch off),
  `tests/Support/ProcessEnv.php`; `tests/Feature/Auth/StaffTwoFactorSwitchTest.php`, `tests/Feature/Support/RuntimeSwitchesTest.php`.
- Known effect: without `/_dev` the opcache warm-up of `reload.bat` fails silently, so the first click after a reload is
  slower (about 5–7 s). Kept strict on purpose (the lead wants `/_dev` closed on both stacks).

## 4. Roles apply at once (D-169)
- `CreateStaffAccount` assigns every role it accepts at once; `ChangeStaffRole` applies every role change at once (the
  3-Admin cap moved into `StaffRoles::assertAdminRoom()`, own-role refusal and session revocation unchanged);
  `RequestRoleChange` is a thin entry that calls it. No new `role_change_requests` row: audit `account.role_changed` /
  `account.staff_created` (always with `role`), notices "Peran akun staf diubah" / "Akun staf baru dibuat".
- Old pending requests still approve, cancel and auto-apply (`sth:peran-tertunda`); while one is pending the account's
  role cannot be changed directly (`ChangeStaffRole::PENDING_REQUEST`). `RoleChangeNotice::REQUESTED`,
  `PrivilegedRoles::DELAY_HOURS|needsApproval()|approvers()` removed: drain the queue before deploying.
- Pengguna & peran: "Peran langsung berlaku." hints and toasts; the four 2FA texts follow
  `EnsureStaffTwoFactor::featureEnabled()`; the "Perubahan peran" panel only lists old requests ("Belum diputuskan").
- Tests: `tests/Feature/Admin/RoleChange/*` rewritten/updated, `PenggunaWithoutTwoFactorTest`, `PenggunaTwoFactorTextTest`,
  `NotificationsQa10Test`, `MailInjectionTest`; email scenario steps 0a, 10g, 10i edited (not run).

## Environment and data (coordinator)
- Both `.env`: `STH_REQUIRE_STAFF_2FA=false`, `STH_STAFF_2FA=false`, `STH_DEV_PAGES=false`, `STH_INITIAL_STUDENT_PASSWORD`
  set (the lead's example value; never printed). Both stay `APP_ENV=local` (the school's server also runs as dev for now).
- Pending students re-hashed to that value: 11 demo students on Smaga4 (not the ones holding an Admin temporary
  password), the 3 trial students on `smaga_server`.
- `smaga_server`: the 7 staff accounts now use the lead's own Gmail address with a plus tag per account (not written in the repo) (seeder `staffEmail()` and the
  database); no `@sth.test` address is left there. Smaga4 keeps `@sth.test` (the lead: fine for testing).

## TEST-RESULTS
- Sub-agents, focused: classes 74 passed + 291 (guards and neighbours) + 677; reset 185 + 21; 2FA/dev switches 13 + 705
  (one `.env` leak fixed in `phpunit.xml`) + 47; roles 516 + 54.
- First full run (`--parallel --processes=6`): 3914 passed, 1 skipped, 1 failed: `InitialPasswordConfigTest` read the
  developer `.env` value of `STH_INITIAL_STUDENT_PASSWORD`; `phpunit.xml` now forces it empty (the config default
  applies, no value written anywhere). Account + auth tests then 318 passed.
- Final full run: **3915 passed, 1 skipped, 0 failed (23239 assertions)**, 756 s. `pint --test` clean (850 files).
- Both stacks: migration `remove_tahfidz_olimp_classrooms` run, `RoleSeeder` re-run, php-fpm reloaded. `smaga_server`
  synced (also `bootstrap/app.php`, missed by the first diff that excluded the whole `bootstrap` folder). Probes:
  `/_dev/components` 404 on both, `/akun/2fa` and `/two-factor-challenge` 404. Browser on `smaga_server` as Test Admin:
  login with username + password only, Admin › Kelas 34 rows, Keamanan akun without 2FA text, Pengguna & peran without a
  waiting text, the dialog "Reset kata sandi & email?" (opened and cancelled, nothing reset).
- Not run: the email scenario (steps 0a, 10g, 10i edited), the Playwright smoke.
