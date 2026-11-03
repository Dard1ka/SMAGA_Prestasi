<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Models\RoleChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Second-Admin approval of a pending privileged role change (backlog #13). Only an Admin with `user.manage`
 * who is neither the requester nor the account itself (RoleChangeRequestPolicy::approve). Works in both modes:
 * a delay-mode request can be approved early once a second Admin exists. Audited (`role.change_approved`),
 * then applied at once by ApplyRoleChange (which re-checks eligibility). Returns whether the role was applied.
 */
final class ApproveRoleChange
{
    public function __construct(private readonly ApplyRoleChange $apply) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, RoleChangeRequest $request): bool
    {
        Gate::forUser($actor)->authorize('approve', $request);

        $approved = DB::transaction(function () use ($actor, $request): RoleChangeRequest {
            /** @var RoleChangeRequest $locked */
            $locked = RoleChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending() || $locked->approved_at !== null) {
                throw ValidationException::withMessages(['request' => 'Permintaan ini sudah selesai atau dibatalkan.']);
            }

            $locked->forceFill(['approved_at' => now(), 'approved_by' => $actor->getKey()])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('role.change_approved')
                ->withProperties(['request_id' => $locked->getKey(), 'user_id' => $locked->user_id, 'to' => $locked->to_role->value])
                ->log('role.change_approved');

            return $locked;
        });

        return $this->apply->handle($approved, $actor);
    }
}
