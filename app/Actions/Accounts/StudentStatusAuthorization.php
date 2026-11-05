<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Who may run an account Action that "Ubah status" chains (UbahStatusSiswa; 2026-11-05, the lead: the Kelas page of
 * Kesiswaan and Tata Usaha "sama persis Admin"): the Action's own permission (Admin: `user.archive`,
 * `user.reset_password`, `student.import`, unchanged for Pengguna & peran, Arsip otomatis and Status aktivasi), or
 * `student.status` (Admin, Kesiswaan, Tata Usaha) when the target is a student account (UserPolicy::changeStudentStatus).
 * A staff account can therefore still only be disabled, unfrozen or reset by an Admin.
 */
final class StudentStatusAuthorization
{
    /** @throws AuthorizationException */
    public static function authorize(User $actor, User $target, Permission $permission): void
    {
        $gate = Gate::forUser($actor);

        if ($gate->allows($permission->value)) {
            return;
        }

        $gate->authorize('changeStudentStatus', $target);
    }
}
