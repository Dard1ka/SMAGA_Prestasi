<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\RevokeUserSessions;
use App\Actions\Accounts\SecurityAlerts;
use App\Actions\Fortify\LoginLockout;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengguna & peran › "Reset akun" for STAFF (students use App\Actions\Accounts\ResetStudentAccount):
 * a temporary password typed by the Admin (never shown or logged), must_change_password, email marked
 * unverified (re-confirmed with OTP at the next login), TOTP 2FA removed (lost phone / hijack recovery,
 * set up again at the next login), a K3/K8 freeze lifted (like the student reset, QA9), every session revoked,
 * login lockout cleared. A disabled/archived account cannot log in, so it is not reset (QA9, refusal()); there is
 * no way back to active from here. Audited with the reason; Admins and the member are notified. Counts towards
 * the K10 alert (> 10 student + staff resets by one Admin within an hour, App\Actions\Accounts\SecurityAlerts, W4).
 */
final class ResetStaffAccount
{
    /**
     * Why this account is not reset, or null when it may be (QA9): a reset promises a login with the temporary
     * password, which a disabled or archived account never gets. The screen asks before it offers "Reset akun".
     */
    public static function refusal(User $target): ?string
    {
        return match ($target->status) {
            UserStatus::Disabled => 'Akun nonaktif tidak bisa login, jadi tidak bisa direset.',
            UserStatus::Archived => 'Akun yang diarsipkan tidak bisa login, jadi tidak bisa direset.',
            default => null,
        };
    }

    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly RevokeUserSessions $revokeSessions,
        private readonly LoginLockout $lockout,
        private readonly SecurityAlerts $alerts,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $temporaryPassword, string $reason): void
    {
        Gate::forUser($actor)->authorize('resetPassword', $target);

        if ($target->hasRole(RoleName::Siswa->value)) {
            throw ValidationException::withMessages(['user' => 'Gunakan reset akun siswa.']);
        }

        if (($refusal = self::refusal($target)) !== null) {
            throw ValidationException::withMessages(['user' => $refusal]);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan reset (maks. 500 karakter).']);
        }

        Validator::make(
            ['password' => $temporaryPassword],
            ['password' => ['required', 'string', Password::defaults()]],
            [],
            ['password' => 'kata sandi sementara'],
        )->validate();

        DB::transaction(function () use ($actor, $target, $temporaryPassword, $reason): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            // Disabled or archived in the meantime: same refusal under the lock.
            if (($refusal = self::refusal($locked)) !== null) {
                throw ValidationException::withMessages(['user' => $refusal]);
            }

            $wasFrozen = AccountControls::frozenReason($locked);

            $locked->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'password_changed_at' => null,
                'email_verified_at' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                // QA9: a reset is the way back from a "Bukan saya?" freeze (FreezeAccountFromNotice), as for students.
                'failed_activation_attempts' => 0,
                'frozen_at' => null,
                'frozen_reason' => null,
                'status' => UserStatus::PendingActivation,
            ])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.staff_reset')
                ->withProperties(['reason' => $reason, 'unfrozen' => $wasFrozen])
                ->log('account.staff_reset');

            $target->setRawAttributes($locked->getAttributes(), true);
        });

        $this->revokeSessions->handle($target);
        $this->lockout->clear('u:'.$target->getKey());

        Notification::send(
            $this->recipients->admins()->push($target)->unique(fn (User $u) => $u->getKey()),
            new StaffAccountChanged(StaffAccountChanged::RESET, (string) $actor->name, (string) $target->name),
        );

        $this->alerts->resetPerformed($actor);
    }
}
