<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Actions\Users\AdminRecipients;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Notifications\Admin\RoleChangeNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a pending privileged role change (backlog #13): any Admin with `user.manage`, the requester included
 * (RoleChangeRequestPolicy::cancel). Reason required; audited (`role.change_cancelled`); every Admin and the
 * account are notified.
 */
final class CancelRoleChange
{
    public function __construct(private readonly AdminRecipients $recipients) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, RoleChangeRequest $request, string $reason): void
    {
        Gate::forUser($actor)->authorize('cancel', $request);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['cancelReason' => 'Isi alasan (maks. 500 karakter).']);
        }

        $locked = DB::transaction(function () use ($actor, $request, $reason): RoleChangeRequest {
            /** @var RoleChangeRequest $locked */
            $locked = RoleChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['cancelReason' => 'Permintaan ini sudah selesai atau dibatalkan.']);
            }

            $locked->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $reason,
            ])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('role.change_cancelled')
                ->withProperties(['request_id' => $locked->getKey(), 'user_id' => $locked->user_id, 'reason' => $reason])
                ->log('role.change_cancelled');

            return $locked;
        });

        $locked->loadMissing('user');
        $recipients = $this->recipients->admins();

        if ($locked->user !== null && ! $locked->user->trashed()) {
            $recipients->push($locked->user);
        }

        Notification::send(
            $recipients->unique(fn (User $u) => $u->getKey())->values(),
            new RoleChangeNotice(
                RoleChangeNotice::CANCELLED,
                (string) $actor->name,
                (string) $locked->user?->name,
                $locked->to_role->label(),
            ),
        );
    }
}
