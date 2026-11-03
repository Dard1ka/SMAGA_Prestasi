<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Accounts\RevokeUserSessions;
use App\Enums\RoleName;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\Admin\StaffAccountChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengguna & peran › "Ubah peran": replaces a staff member's role with one of StaffRoles::assignable()
 * (not for students, not for the Kepala Sekolah, never your own account — UserPolicy::updateRole; since the actor is
 * an Admin who cannot change their own role, the last Admin always keeps the role). The member's sessions are revoked
 * so the new menu/permissions apply at the next login. Audited `account.role_changed` (from/to + reason); Admins and
 * the member are notified. The role the account already holds is refused (QA9, SAME_ROLE).
 *
 * Since 21 Okt 2026 (the lead: "kalo sdh dibuat ya sudah tidak perlu persetujuan admin lainnya") this applies at once
 * for every assignable role, privileged ones (Admin, Kesiswaan, Tata Usaha, Humas) included: no RoleChangeRequest,
 * no second Admin, no 24 h delay. It replaces the waiting rule of backlog #13 / D-46 / D-61. A request made before
 * that day that is still pending blocks a change of the same account until it is approved or cancelled
 * (PENDING_REQUEST), and a pending Admin request still holds a place in the 3-Admin limit (StaffRoles::assertAdminRoom).
 */
final class ChangeStaffRole
{
    /** QA9: the account already holds exactly this role, so nothing would change (no audit, no logout). */
    public const SAME_ROLE = 'Akun ini sudah memegang peran itu.';

    /** An old request (from before 21 Okt 2026) still waits for this account; it would be cancelled automatically later. */
    public const PENDING_REQUEST = 'Masih ada perubahan peran yang menunggu untuk akun ini. Setujui atau batalkan dulu di daftar Perubahan peran.';

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

            if (RoleChangeRequest::query()->pending()->where('user_id', $locked->getKey())->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['role' => self::PENDING_REQUEST]);
            }

            if ($role === RoleName::Admin) {
                StaffRoles::assertAdminRoom($locked);
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
