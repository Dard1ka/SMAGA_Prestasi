<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginLockout;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Reset kata sandi & email" for a student (D-14 recovery, SECURITY K10 — lead decision, B1; the lead on
 * 2026-10-21, replacing the password-only reset of D-158): a student who only forgot the password uses "Lupa kata
 * sandi" on the login page, so this reset is for an account somebody else took over, and it removes the email again.
 *
 * The Admin types the temporary password (at least MIN_PASSWORD characters, nothing else: no PasswordPolicy, it must
 * be replaced at the next login) and hands it over face to face; it is stored only as a hash, valid 72 hours
 * (`initial_password_expires_at`), `must_change_password` is set and `temporary_password_issued_at` marks it as one an
 * Admin has seen (the activation screen words its password step for it, CompleteActivation::hasTemporaryPassword;
 * SetNewPassword clears the mark).
 *
 * The bound email (and its verification) is removed and the account goes back to `pending_activation` without
 * `activated_at`, so the student activates again: NISN + temporary password → bind an own email → 6-digit code → new
 * password. The Data diri (`students.profile_confirmed_at`, birth date, No HP) and the talent choices stay, so the
 * profile step is skipped (DetermineActivationStep::profileOpen()).
 *
 * Also: open OTPs cancelled, a K3/K8 freeze lifted, TOTP 2FA removed (students never have it; `two_factor_removed` in
 * the audit row tells whether there was one), every session + remember token revoked, login lockout cleared,
 * audited with the Admin's reason and `email_detached` (which DetermineActivationStep::boundInTestMode() reads), never
 * the password. A disabled/archived account cannot log in, so it is not reset at all (QA9, refusal(); resetting never
 * re-opens it, and the temporary password would be useless). More than 10 resets by one Admin within an hour alerts
 * every Admin.
 */
final class ResetStudentAccount
{
    public const MIN_PASSWORD = 8;

    public const MSG_PASSWORD = 'Kata sandi sementara minimal 8 karakter.';

    public const MSG_REASON = 'Isi alasan reset (maks. 500 karakter).';

    /**
     * Why this account is not reset, or null when it may be (QA9): the reset hands over a temporary password to
     * log in with, which a disabled or archived account never can. The screen asks before it offers the reset.
     */
    public static function refusal(User $student): ?string
    {
        return match ($student->status) {
            UserStatus::Disabled => 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.',
            UserStatus::Archived => 'Akun alumni tidak bisa login, jadi tidak bisa direset. Pulihkan dulu di Arsip otomatis bila perlu.',
            default => null,
        };
    }

    public function __construct(
        private readonly RevokeUserSessions $revokeSessions,
        private readonly LoginLockout $lockout,
        private readonly SecurityAlerts $alerts,
    ) {}

    /**
     * @param  string  $temporaryPassword  typed by the Admin; hashed only, never stored in plaintext, logged or echoed
     *
     * @throws ValidationException keys `reason` and `password` (together), or `user`
     */
    public function handle(User $actor, User $student, string $reason, string $temporaryPassword): void
    {
        Gate::forUser($actor)->authorize(Permission::UserResetPassword->value);

        $reason = trim($reason);
        $errors = [];

        if ($reason === '' || mb_strlen($reason) > 500) {
            $errors['reason'] = self::MSG_REASON;
        }

        if (mb_strlen($temporaryPassword) < self::MIN_PASSWORD) {
            $errors['password'] = self::MSG_PASSWORD;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($actor->is($student)) {
            throw ValidationException::withMessages(['user' => 'Tidak bisa me-reset akun sendiri.']);
        }

        if (($refusal = self::refusal($student)) !== null) {
            throw ValidationException::withMessages(['user' => $refusal]);
        }

        $hash = Hash::make($temporaryPassword);
        $expiresAt = now()->addHours(AccountControls::TEMPORARY_PASSWORD_HOURS);

        DB::transaction(function () use ($actor, $student, $reason, $hash, $expiresAt): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->student()->exists()) {
                throw ValidationException::withMessages(['user' => 'Akun ini bukan akun siswa.']);
            }

            // Disabled or archived in the meantime: same refusal under the lock.
            if (($refusal = self::refusal($locked)) !== null) {
                throw ValidationException::withMessages(['user' => $refusal]);
            }

            $wasFrozen = AccountControls::frozenReason($locked);
            $hadTwoFactor = $locked->two_factor_secret !== null || $locked->two_factor_confirmed_at !== null;

            $locked->forceFill([
                'password' => $hash,
                'must_change_password' => true,
                'password_changed_at' => null,
                'initial_password_expires_at' => $expiresAt,
                // M2-A: an Admin saw this password, so the owner must replace it (every account does at activation, D-138).
                'temporary_password_issued_at' => now(),
                'failed_activation_attempts' => 0,
                'frozen_at' => null,
                'frozen_reason' => null,
                // The student activates again with an own address: email → code → new password.
                'email' => null,
                'email_verified_at' => null,
                'activated_at' => null,
                'status' => UserStatus::PendingActivation,
                // Students never have TOTP; one enrolled by a hijacker must not survive the recovery.
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            OtpCode::query()
                ->where('user_id', $locked->getKey())
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.reset')
                ->withProperties([
                    'reason' => $reason,
                    'temporary_password_expires_at' => $expiresAt->toIso8601String(),
                    'unfrozen' => $wasFrozen,
                    'two_factor_removed' => $hadTwoFactor,
                    // Always true since 2026-10-21; false only on rows of the password-only reset (D-158, 19–21 Oct).
                    'email_detached' => true,
                ])
                ->log('account.reset');

            $student->setRawAttributes($locked->getAttributes(), true);
        });

        $this->revokeSessions->handle($student);
        $this->lockout->clear('u:'.$student->getKey());
        $this->alerts->resetPerformed($actor);
    }
}
