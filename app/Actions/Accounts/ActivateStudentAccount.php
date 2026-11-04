<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Ubah status" › "Aktif" for a student who has not finished activation (the lead, 2026-11-05: the Admin may set
 * every state Status aktivasi shows). Admin only (`user.archive`, the permission of the dialog), never the Admin's own
 * account, only a student account in `pending_activation` that is not frozen (K9 belum or kedaluwarsa; a freeze is
 * lifted first with UnfreezeAccount); reason required (MIN_REASON–MAX_REASON characters).
 *
 * In one transaction under a row lock: status `active`, `initial_password_expires_at` null (the K1 window ends: the
 * initial or temporary password keeps working until the student replaces it), the K3 counter reset.
 * `must_change_password` is left as it is, and the email (none for a never-activated student) too, so the next login
 * still leads through the activation steps: email → code → new password (DetermineActivationStep). "Data diri" is not
 * asked, since that step belongs to a `pending_activation` account (DetermineActivationStep::profileOpen()).
 * `activated_at` stays empty: the student did not activate.
 *
 * Audited as `account.activated_by_admin` (reason, the K9 state before, the old window end; no names). Nobody is
 * notified.
 */
final class ActivateStudentAccount
{
    public const EVENT = 'account.activated_by_admin';

    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public const MSG_ACTIVE = 'Akun ini sudah aktif.';

    public const MSG_CLOSED = 'Akun ini nonaktif atau diarsipkan.';

    public const MSG_FROZEN = 'Akun ini sedang dibekukan. Buka pembekuannya dulu.';

    public const MSG_NOT_STUDENT = 'Akun ini bukan akun siswa.';

    public const MSG_SELF = 'Tidak bisa mengaktifkan akun sendiri.';

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason): User
    {
        Gate::forUser($actor)->authorize(Permission::UserArchive->value);

        if (! $actor->canLogIn()) {
            abort(403);
        }

        $reason = self::reason($reason);

        if ($actor->is($target)) {
            throw ValidationException::withMessages(['user' => self::MSG_SELF]);
        }

        return DB::transaction(function () use ($actor, $target, $reason): User {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->student()->exists()) {
                throw ValidationException::withMessages(['user' => self::MSG_NOT_STUDENT]);
            }

            if ($locked->status !== UserStatus::PendingActivation) {
                throw ValidationException::withMessages(['user' => $locked->status === UserStatus::Active ? self::MSG_ACTIVE : self::MSG_CLOSED]);
            }

            if (AccountControls::isFrozen($locked)) {
                throw ValidationException::withMessages(['user' => self::MSG_FROZEN]);
            }

            $from = AccountControls::state($locked, CarbonImmutable::now());
            $previous = AccountControls::date($locked, 'initial_password_expires_at');

            $locked->forceFill([
                'status' => UserStatus::Active,
                'initial_password_expires_at' => null,
                'failed_activation_attempts' => 0,
            ])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'reason' => $reason,
                    'from' => $from,
                    'previous_expires_at' => $previous?->toIso8601String(),
                ])
                ->log(self::EVENT);

            $target->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * The trimmed reason, or the error of the dialogs of Status aktivasi (10–500 characters).
     *
     * @throws ValidationException
     */
    private static function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Isi alasannya.']);
        }

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => 'Alasan minimal '.self::MIN_REASON.' karakter.']);
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => 'Alasan maksimal '.self::MAX_REASON.' karakter.']);
        }

        return $reason;
    }
}
