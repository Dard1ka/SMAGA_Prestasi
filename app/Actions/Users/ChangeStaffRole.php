<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\RevokeUserSessions;
use App\Actions\Roles\PrivilegedRoles;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengguna & peran › "Ubah peran": replaces a staff member's role with one of StaffRoles::assignable()
 * (not for students, not for the Kepala Sekolah, never your own account — UserPolicy::updateRole). The
 * member's sessions are revoked so the new menu/permissions apply at the next login. Audited (from/to +
 * reason); Admins and the member are notified. The role the account already holds is refused (QA9, SAME_ROLE). Privileged roles (admin, tata_usaha, kesiswaan) are refused
 * here: they go through App\Actions\Roles\RequestRoleChange (second Admin or 24 h delay, backlog #13, B5).
 */
final class ChangeStaffRole
{
    /** QA9: the account already holds exactly this role, so nothing would change (no audit, no logout). */
    public const SAME_ROLE = 'Akun ini sudah memegang peran itu.';

    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, RoleName $role, string $reason): void
    {
        Gate::forUser($actor)->authorize('updateRole', $target);

        $reason = trim($reason);

        if (! StaffRoles::isAssignable($role)) {
            throw ValidationException::withMessages(['role' => 'Peran ini tidak bisa diberikan dari sini.']);
        }

        // B5 (backlog #13): a privileged role needs a second Admin or the 24 h delay → App\Actions\Roles\RequestRoleChange.
        if (PrivilegedRoles::needsApproval($target, $role)) {
            throw ValidationException::withMessages(['role' => 'Peran ini perlu persetujuan Admin lain.']);
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan (maks. 500 karakter).']);
        }

        DB::transaction(function () use ($actor, $target, $role, $reason): void {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->getRoleNames()->values()->all();

            // QA9: say so instead of returning quietly, which the screen took for a change.
            if ($from === [$role->value]) {
                throw ValidationException::withMessages(['role' => self::SAME_ROLE]);
            }

            if ($role === RoleName::Admin) {
                User::query()->role(RoleName::Admin->value)->lockForUpdate()->get();

                if (StaffRoles::adminCount($locked) >= StaffRoles::MAX_ADMINS) {
                    throw ValidationException::withMessages(['role' => 'Sudah ada '.StaffRoles::MAX_ADMINS.' akun Admin.']);
                }
            }

            $locked->syncRoles([$role->value]);

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.role_changed')
                ->withProperties(['from' => $from, 'to' => [$role->value], 'reason' => $reason])
                ->log('account.role_changed');

            $target->setRelations($locked->getRelations());
        });

        $this->revokeSessions->handle($target);

        Notification::send(
            $this->recipients->admins()->push($target)->unique(fn (User $u) => $u->getKey()),
            new StaffAccountChanged(StaffAccountChanged::ROLE_CHANGED, (string) $actor->name, (string) $target->name, $role->label()),
        );
    }
}
