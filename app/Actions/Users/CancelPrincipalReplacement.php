<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\PrincipalReplacement;
use App\Models\User;
use App\Notifications\Admin\PrincipalReplacementNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a pending "Ganti Kepala Sekolah" request during its 24 h delay (SECURITY §3m-9): any Admin or the
 * Kepala Sekolah being replaced. Audited with the reason; both accounts and every Admin are notified.
 */
final class CancelPrincipalReplacement
{
    public function __construct(private readonly AdminRecipients $recipients) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, PrincipalReplacement $replacement, string $reason): void
    {
        Gate::forUser($actor)->authorize('cancel', $replacement);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['cancelReason' => 'Isi alasan (maks. 500 karakter).']);
        }

        $locked = DB::transaction(function () use ($actor, $replacement, $reason): PrincipalReplacement {
            /** @var PrincipalReplacement $locked */
            $locked = PrincipalReplacement::query()->whereKey($replacement->getKey())->lockForUpdate()->firstOrFail();

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
                ->event('principal.cancelled')
                ->withProperties(['reason' => $reason])
                ->log('principal.cancelled');

            return $locked;
        });

        $locked->loadMissing(['fromUser', 'toUser']);
        $recipients = $this->recipients->admins();

        foreach ([$locked->fromUser, $locked->toUser] as $user) {
            if ($user !== null) {
                $recipients->push($user);
            }
        }

        Notification::send(
            $recipients->unique(fn (User $u) => $u->getKey()),
            new PrincipalReplacementNotice(
                PrincipalReplacementNotice::CANCELLED,
                (string) $locked->fromUser?->name,
                (string) $locked->toUser?->name,
                $locked->effective_at->translatedFormat('j F Y H:i'),
            ),
        );
    }
}
