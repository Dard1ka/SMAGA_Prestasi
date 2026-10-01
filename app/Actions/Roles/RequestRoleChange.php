<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Users\AdminRecipients;
use App\Actions\Users\ChangeStaffRole;
use App\Actions\Users\StaffRoles;
use App\Enums\RoleName;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\Admin\RoleChangeNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Admin › Pengguna & peran › "Ubah peran" (backlog #13). Entry point for every staff role change:
 * - to a non-privileged role (guru, humas) → applied at once by App\Actions\Users\ChangeStaffRole (returns null);
 * - to a privileged role (admin, tata_usaha, kesiswaan) → a pending RoleChangeRequest:
 *   · another Admin exists → waits for that Admin's approval (ApproveRoleChange), no automatic apply;
 *   · the requester is the only Admin → applies by itself after PrivilegedRoles::DELAY_HOURS (24 h) through
 *     ApplyRoleChange::applyDue(), unless an Admin cancels it (CancelRoleChange) — mirrors "Ganti Kepala Sekolah".
 * One pending request per account; a pending Admin request counts towards the 3-Admin limit (D-41).
 * Audited (`role.change_requested`); every Admin and the account are notified (the Kepala Sekolah too in delay mode).
 *
 * W4 (lead decision): a NEW staff account with a privileged role goes through the same request — CreateStaffAccount
 * creates it without a role and calls open() inside its own transaction, then notifyRequested(newAccount: true).
 */
final class RequestRoleChange
{
    public function __construct(
        private readonly ChangeStaffRole $change,
        private readonly AdminRecipients $recipients,
    ) {}

    /**
     * @return RoleChangeRequest|null null when the change was applied at once
     *
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, RoleName $role, string $reason): ?RoleChangeRequest
    {
        Gate::forUser($actor)->authorize('updateRole', $target);

        if (! StaffRoles::isAssignable($role)) {
            throw ValidationException::withMessages(['role' => 'Peran ini tidak bisa diberikan dari sini.']);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan (maks. 500 karakter).']);
        }

        if (! PrivilegedRoles::needsApproval($target, $role)) {
            $this->change->handle($actor, $target, $role, $reason);

            return null;
        }

        $request = DB::transaction(function () use ($actor, $target, $role, $reason): RoleChangeRequest {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            return $this->open($actor, $locked, $role, $reason);
        });

        $this->notifyRequested($actor, $target, $request);

        return $request;
    }

    /**
     * Records a pending privileged role change. Call it inside a DB transaction with `$locked` row-locked; the
     * caller authorizes and validates the reason (1–500 chars) and calls notifyRequested() after the commit.
     *
     * @throws ValidationException
     */
    public function open(User $actor, User $locked, RoleName $role, string $reason): RoleChangeRequest
    {
        $from = $locked->getRoleNames()->values()->all();

        if (in_array($role->value, $from, true)) {
            throw ValidationException::withMessages(['role' => 'Akun ini sudah memegang peran itu.']);
        }

        if (RoleChangeRequest::query()->pending()->where('user_id', $locked->getKey())->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['role' => 'Masih ada perubahan peran yang menunggu untuk akun ini. Batalkan dulu bila perlu.']);
        }

        if ($role === RoleName::Admin) {
            User::query()->role(RoleName::Admin->value)->lockForUpdate()->get();
            $pendingAdmins = RoleChangeRequest::query()->pending()->where('to_role', RoleName::Admin->value)->count();

            if (StaffRoles::adminCount($locked) + $pendingAdmins >= StaffRoles::MAX_ADMINS) {
                throw ValidationException::withMessages(['role' => 'Sudah ada '.StaffRoles::MAX_ADMINS.' akun Admin (termasuk yang menunggu).']);
            }
        }

        $delay = PrivilegedRoles::approvers($actor, $locked)->isEmpty();

        $request = new RoleChangeRequest;
        $request->forceFill([
            'user_id' => $locked->getKey(),
            'from_roles' => $from,
            'to_role' => $role,
            'requested_by' => $actor->getKey(),
            'reason' => $reason,
            'effective_at' => $delay ? now()->addHours(PrivilegedRoles::DELAY_HOURS) : null,
        ])->save();

        activity('account')
            ->performedOn($locked)
            ->causedBy($actor)
            ->event('role.change_requested')
            ->withProperties([
                'request_id' => $request->getKey(),
                'from' => $from,
                'to' => [$role->value],
                'mode' => $delay ? 'delay' : 'approval',
                'effective_at' => $request->effective_at?->toIso8601String(),
                'reason' => $reason,
            ])
            ->log('role.change_requested');

        return $request;
    }

    /** Every Admin + the account (+ the Kepala Sekolah in delay mode). Call after the commit. */
    public function notifyRequested(User $actor, User $target, RoleChangeRequest $request, bool $newAccount = false): void
    {
        $recipients = $this->recipients->admins()->push($target);

        if ($request->usesDelay()) {
            $recipients = $recipients->merge($this->recipients->principals());
        }

        Notification::send(
            $recipients->unique(fn (User $u) => $u->getKey())->values(),
            new RoleChangeNotice(
                RoleChangeNotice::REQUESTED,
                (string) $actor->name,
                (string) $target->name,
                $request->to_role->label(),
                $request->effective_at?->translatedFormat('j F Y H:i'),
                $newAccount,
            ),
        );
    }
}
