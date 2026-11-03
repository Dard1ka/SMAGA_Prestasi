<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin disables an account by hand (D-30: pindah sekolah, meninggal, …). The user can no longer log in,
 * every session is revoked, and the change is audited with the reason. Achievements stay untouched.
 * ArchiveAccount does the same with status `archived` (alumni, D-29).
 */
final class DisableAccount
{
    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason, UserStatus $status = UserStatus::Disabled): void
    {
        Gate::forUser($actor)->authorize(Permission::UserArchive->value);

        if (! in_array($status, [UserStatus::Disabled, UserStatus::Archived], true)) {
            throw ValidationException::withMessages(['status' => 'Status tidak valid.']);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan (maks. 500 karakter).']);
        }

        if ($actor->is($target)) {
            throw ValidationException::withMessages(['user' => 'Tidak bisa menonaktifkan akun sendiri.']);
        }

        DB::transaction(function () use ($actor, $target, $reason, $status): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $locked->forceFill(['status' => $status])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event($status === UserStatus::Archived ? 'account.archived' : 'account.disabled')
                ->withProperties(['reason' => $reason, 'from' => $from?->value])
                ->log($status === UserStatus::Archived ? 'account.archived' : 'account.disabled');

            $target->setRawAttributes($locked->getAttributes(), true);
        });

        $this->revokeSessions->handle($target);

        // Disabled accounts lose their public name/rank row, archived ones become "Alumni" (D-29, D-30).
        PublicAchievements::flush();
    }
}
