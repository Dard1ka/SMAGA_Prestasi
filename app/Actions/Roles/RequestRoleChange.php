<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Users\ChangeStaffRole;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengguna & peran › "Ubah peran": the entry point for every staff role change.
 *
 * Since 21 Okt 2026 (the lead: "kalo sdh dibuat ya sudah tidak perlu persetujuan admin lainnya") every change applies
 * at once through App\Actions\Users\ChangeStaffRole, privileged roles (Admin, Kesiswaan, Tata Usaha, Humas) included:
 * no new RoleChangeRequest row, no second Admin, no 24 h delay (this replaces the waiting rule of backlog #13, D-46 and
 * D-61). The audit row is ChangeStaffRole's `account.role_changed` (actor, from, to, reason), the notice
 * StaffAccountChanged::ROLE_CHANGED to every Admin and the account. Requests made before that day are still approved
 * (ApproveRoleChange), cancelled (CancelRoleChange) or applied after their 24 h (ApplyRoleChange::applyDue(),
 * scheduler `sth:peran-tertunda`).
 */
final class RequestRoleChange
{
    public function __construct(private readonly ChangeStaffRole $change) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, RoleName $role, string $reason): void
    {
        $this->change->handle($actor, $target, $role, $reason);
    }
}
