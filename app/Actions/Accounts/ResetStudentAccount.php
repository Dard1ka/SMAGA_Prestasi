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
 * Admin "Reset kata sandi & email" for a student (D-14 recovery, SECURITY K10 — lead decision, B1):
 * a RANDOM temporary password (12 characters, GenerateTemporaryPassword) is returned once for the Admin to
 * hand over face to face; it is stored only as a hash, valid 72 hours (`initial_password_expires_at`) and must
 * be changed at the next login (must_change_password). The initial birth-date pattern is no longer used here.
 * Also: bound email removed, open OTPs cancelled, a K3/K8 freeze lifted, TOTP 2FA removed (students never have
 * it; `two_factor_removed` in the audit row tells whether there was one), every session + remember token
 * revoked, login lockout cleared, audited with the Admin's reason (never the password). A disabled/archived
 * account keeps its status (resetting never re-opens it). More than 10 resets by one Admin within an hour
 * alerts every Admin.
 */
final class ResetStudentAccount
{
    public function __construct(
        private readonly GenerateTemporaryPassword $temporaryPassword,
        private readonly RevokeUserSessions $revokeSessions,
        private readonly LoginLockout $lockout,
        private readonly SecurityAlerts $alerts,
    ) {}

    /**
     * @return string the temporary password — display it once, never store or log it
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $student, string $reason): string
    {
        Gate::forUser($actor)->authorize(Permission::UserResetPassword->value);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan reset (maks. 500 karakter).']);
        }

        if ($actor->is($student)) {
            throw ValidationException::withMessages(['user' => 'Tidak bisa me-reset akun sendiri.']);
        }

        $password = $this->temporaryPassword->handle();
        $expiresAt = now()->addHours(AccountControls::TEMPORARY_PASSWORD_HOURS);

        DB::transaction(function () use ($actor, $student, $reason, $password, $expiresAt): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->student()->exists()) {
                throw ValidationException::withMessages(['user' => 'Akun ini bukan akun siswa.']);
            }

            $wasFrozen = AccountControls::frozenReason($locked);
            $hadTwoFactor = $locked->two_factor_secret !== null || $locked->two_factor_confirmed_at !== null;

            $locked->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => true,
                'password_changed_at' => null,
                'initial_password_expires_at' => $expiresAt,
                'failed_activation_attempts' => 0,
                'frozen_at' => null,
                'frozen_reason' => null,
                'activated_at' => null,
                'email' => null,
                'email_verified_at' => null,
                // Students never have TOTP; one enrolled by a hijacker must not survive the recovery.
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'status' => $locked->status?->canLogIn() ? UserStatus::PendingActivation : $locked->status,
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
                ])
                ->log('account.reset');

            $student->setRawAttributes($locked->getAttributes(), true);
        });

        $this->revokeSessions->handle($student);
        $this->lockout->clear('u:'.$student->getKey());
        $this->alerts->resetPerformed($actor);

        return $password;
    }
}
