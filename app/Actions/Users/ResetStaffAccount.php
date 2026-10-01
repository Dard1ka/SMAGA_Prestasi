<?php

declare(strict_types=1);

namespace App\Actions\Users;

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
 * set up again at the next login), every session revoked, login lockout cleared. A disabled/archived account
 * keeps its status. Audited with the reason; Admins and the member are notified. Counts towards the K10 alert
 * (> 10 student + staff resets by one Admin within an hour, App\Actions\Accounts\SecurityAlerts, W4).
 */
final class ResetStaffAccount
{
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

            $locked->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'password_changed_at' => null,
                'email_verified_at' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'status' => $locked->status?->canLogIn() ? UserStatus::PendingActivation : $locked->status,
            ])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.staff_reset')
                ->withProperties(['reason' => $reason])
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
