<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginLockout;
use App\Domain\Accounts\InitialPassword;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Reset akun siswa" (D-14 recovery, SECURITY K10 — see deviation note in docs/dev-notes/W1-C.md):
 * back to the initial password (hashed, must_change_password), bound email removed, open OTPs cancelled,
 * every session + remember token revoked, login lockout cleared, audited with the Admin's reason.
 * A disabled/archived account keeps its status (resetting never re-opens it).
 */
final class ResetStudentAccount
{
    public function __construct(
        private readonly InitialPassword $initialPassword,
        private readonly RevokeUserSessions $revokeSessions,
        private readonly LoginLockout $lockout,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $student, string $reason): void
    {
        Gate::forUser($actor)->authorize(Permission::UserResetPassword->value);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan reset (maks. 500 karakter).']);
        }

        if ($actor->is($student)) {
            throw ValidationException::withMessages(['user' => 'Tidak bisa me-reset akun sendiri.']);
        }

        DB::transaction(function () use ($actor, $student, $reason): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $profile = $locked->student()->first();

            if ($profile === null || $profile->birth_date === null) {
                throw ValidationException::withMessages(['user' => 'Akun ini bukan akun siswa.']);
            }

            $locked->forceFill([
                'password' => Hash::make($this->initialPassword->make($profile->birth_date)),
                'must_change_password' => true,
                'password_changed_at' => null,
                'email' => null,
                'email_verified_at' => null,
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
                ->withProperties(['reason' => $reason])
                ->log('account.reset');

            $student->setRawAttributes($locked->getAttributes(), true);
        });

        $this->revokeSessions->handle($student);
        $this->lockout->clear('u:'.$student->getKey());
    }
}
