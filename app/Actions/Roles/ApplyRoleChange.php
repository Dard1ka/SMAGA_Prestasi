<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Accounts\RevokeUserSessions;
use App\Actions\Users\AdminRecipients;
use App\Actions\Users\StaffRoles;
use App\Enums\RoleName;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\Admin\RoleChangeNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Carries out a pending RoleChangeRequest that is due: approved by a second Admin (ApproveRoleChange calls this
 * at once) or, in delay mode, past `effective_at` (scheduler: applyDue()). Idempotent: anything not pending or
 * not due yet is ignored.
 *
 * Re-checks under row locks before applying; when the account is no longer eligible (cannot log in, became a
 * student or Kepala Sekolah, its roles changed since the request, or the 3-Admin limit is reached) the request
 * is cancelled automatically instead. On apply: roles replaced (`syncRoles`), sessions revoked (new menu and
 * permissions at the next login), audit `account.role_changed` (same event as ChangeStaffRole, with the
 * request id), every Admin and the account notified.
 */
final class ApplyRoleChange
{
    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(RoleChangeRequest $request, ?User $actor = null): bool
    {
        $result = DB::transaction(function () use ($request, $actor): ?array {
            /** @var RoleChangeRequest|null $locked */
            $locked = RoleChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isDue()) {
                return null;
            }

            /** @var User|null $target */
            $target = User::withTrashed()->whereKey($locked->user_id)->lockForUpdate()->first();

            try {
                $this->assertStillEligible($locked, $target);
            } catch (ValidationException $e) {
                $reason = 'Otomatis: '.collect($e->errors())->flatten()->first();
                $locked->forceFill(['cancelled_at' => now(), 'cancel_reason' => $reason])->save();

                activity('account')->performedOn($target ?? $locked)->event('role.change_cancelled')
                    ->withProperties(['request_id' => $locked->getKey(), 'automatic' => true, 'reason' => $reason])
                    ->log('role.change_cancelled');

                return ['applied' => false, 'request' => $locked, 'target' => $target];
            }

            /** @var User $target */
            $from = $target->getRoleNames()->values()->all();
            $target->syncRoles([$locked->to_role->value]);
            $locked->forceFill(['applied_at' => now()])->save();

            $log = activity('account')
                ->performedOn($target)
                ->event('account.role_changed')
                ->withProperties([
                    'from' => $from,
                    'to' => [$locked->to_role->value],
                    'reason' => $locked->reason,
                    'request_id' => $locked->getKey(),
                    'requested_by' => $locked->requested_by,
                    'approved_by' => $locked->approved_by,
                    'via' => $locked->approved_at !== null ? 'approval' : 'delay',
                ]);

            if ($actor !== null) {
                $log->causedBy($actor);
            }

            $log->log('account.role_changed');

            return ['applied' => true, 'request' => $locked, 'target' => $target];
        });

        if ($result === null) {
            return false;
        }

        /** @var RoleChangeRequest $done */
        $done = $result['request'];
        $target = $result['target'];

        if ($result['applied'] && $target !== null) {
            $this->revokeSessions->handle($target);
        }

        $recipients = $this->recipients->admins();

        if ($target !== null && ! $target->trashed()) {
            $recipients->push($target);
        }

        Notification::send(
            $recipients->unique(fn (User $u) => $u->getKey())->values(),
            new RoleChangeNotice(
                $result['applied'] ? RoleChangeNotice::APPLIED : RoleChangeNotice::CANCELLED,
                (string) ($actor?->name ?? 'Sistem'),
                (string) ($target?->name ?? '-'),
                $done->to_role->label(),
            ),
        );

        return $result['applied'];
    }

    /** Scheduler entry point: apply every delay-mode request whose 24 h have passed. Returns how many were applied. */
    public function applyDue(): int
    {
        $applied = 0;

        RoleChangeRequest::query()->due()->orderBy('effective_at')->get()
            ->each(function (RoleChangeRequest $request) use (&$applied): void {
                $applied += (int) $this->handle($request);
            });

        return $applied;
    }

    /**
     * @throws ValidationException
     */
    private function assertStillEligible(RoleChangeRequest $request, ?User $target): void
    {
        if ($target === null || $target->trashed() || ! $target->canLogIn()) {
            throw ValidationException::withMessages(['role' => 'akun tujuan tidak aktif.']);
        }

        if ($target->hasAnyRole([RoleName::Siswa->value, RoleName::KepalaSekolah->value])) {
            throw ValidationException::withMessages(['role' => 'akun tujuan sekarang siswa atau Kepala Sekolah.']);
        }

        $current = $target->getRoleNames()->sort()->values()->all();
        $then = collect($request->from_roles)->sort()->values()->all();

        if ($current !== $then) {
            throw ValidationException::withMessages(['role' => 'peran akun berubah sejak permintaan dibuat.']);
        }

        if ($request->to_role === RoleName::Admin) {
            User::query()->role(RoleName::Admin->value)->lockForUpdate()->get();

            if (StaffRoles::adminCount($target) >= StaffRoles::MAX_ADMINS) {
                throw ValidationException::withMessages(['role' => 'sudah ada '.StaffRoles::MAX_ADMINS.' akun Admin.']);
            }
        }
    }
}
