<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Ubah status" › "Dibekukan" (the lead, 2026-11-05): freezes a student account by hand, next to the K3
 * (failed logins) and K8 ("Bukan saya?") freezes. A frozen account cannot log in (EnforceLoginControls) and loses an
 * open session (EnsureActivated) until an Admin lifts it ("Buka pembekuan", UnfreezeAccount, or the dialog's other
 * targets); "Buka ulang" of Kesiswaan does not lift it (ReopenActivationWindow).
 *
 * Admin only (`user.archive`, the permission of the dialog), never the Admin's own account, only a student account that
 * can log in (active or pending; a disabled or archived one is brought back first: the dialog chains EnableAccount /
 * UnarchiveStudent) and is not frozen yet; reason required (MIN_REASON–MAX_REASON characters).
 *
 * In one transaction under a row lock `frozen_at` = now, `frozen_reason` = AccountControls::FROZEN_ADMIN; the K3
 * counter and the activation window stay. Afterwards every session and remember token is revoked, as DisableAccount
 * does. Audited as `account.frozen_by_admin` (reason, the K9 state before; no names). Nobody is notified.
 */
final class FreezeAccount
{
    public const EVENT = 'account.frozen_by_admin';

    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public const MSG_CLOSED = 'Akun ini nonaktif atau diarsipkan.';

    public const MSG_FROZEN = 'Akun ini sudah dibekukan.';

    public const MSG_NOT_STUDENT = 'Akun ini bukan akun siswa.';

    public const MSG_SELF = 'Tidak bisa membekukan akun sendiri.';

    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason): void
    {
        StudentStatusAuthorization::authorize($actor, $target, Permission::UserArchive);

        if (! $actor->canLogIn()) {
            abort(403);
        }

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

        if ($actor->is($target)) {
            throw ValidationException::withMessages(['user' => self::MSG_SELF]);
        }

        DB::transaction(function () use ($actor, $target, $reason): void {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->student()->exists()) {
                throw ValidationException::withMessages(['user' => self::MSG_NOT_STUDENT]);
            }

            if (! in_array($locked->status, [UserStatus::Active, UserStatus::PendingActivation], true)) {
                throw ValidationException::withMessages(['user' => self::MSG_CLOSED]);
            }

            if (AccountControls::isFrozen($locked)) {
                throw ValidationException::withMessages(['user' => self::MSG_FROZEN]);
            }

            $now = CarbonImmutable::now();
            $from = AccountControls::state($locked, $now);

            $locked->forceFill(['frozen_at' => $now, 'frozen_reason' => AccountControls::FROZEN_ADMIN])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties(['reason' => $reason, 'from' => $from])
                ->log(self::EVENT);

            $target->setRawAttributes($locked->getAttributes(), true);
        });

        $this->revokeSessions->handle($target);
    }
}
