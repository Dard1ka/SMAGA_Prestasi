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
 * Admin "Ubah status" › "Kedaluwarsa" (the lead, 2026-11-05): ends the K1 window of a student who has not finished
 * activation, so the initial or temporary password no longer logs in (EnforceLoginControls) until "Buka ulang"
 * (ReopenActivationWindow) or the dialog's "Belum aktivasi" / "Aktif": the opposite of "Buka ulang".
 *
 * Admin only (`user.archive`, the permission of the dialog), never the Admin's own account, only a student account in
 * `pending_activation` that still has to replace an initial or temporary password (`must_change_password`), is not
 * frozen and has not expired yet (K9 belum); reason required (MIN_REASON–MAX_REASON characters). An active student is
 * reset first (ResetStudentAccount), a frozen one unfrozen (UnfreezeAccount): the dialog chains them.
 *
 * In one transaction under a row lock `initial_password_expires_at` = now; afterwards every session of the account is
 * ended (as DisableAccount does), so a login opened before cannot finish the activation. Audited as
 * `account.activation_expired_by_admin` (reason, the old and the new window end; no names). Nobody is notified.
 */
final class ExpireActivationWindow
{
    public const EVENT = 'account.activation_expired_by_admin';

    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public const MSG_ACTIVE = 'Akun ini sudah aktif.';

    public const MSG_CLOSED = 'Akun ini nonaktif atau diarsipkan.';

    public const MSG_FROZEN = 'Akun ini sedang dibekukan. Buka pembekuannya dulu.';

    public const MSG_NO_WINDOW = 'Akun ini tidak memakai kata sandi awal atau sementara.';

    public const MSG_EXPIRED = 'Akun ini sudah kedaluwarsa.';

    public const MSG_NOT_STUDENT = 'Akun ini bukan akun siswa.';

    public const MSG_SELF = 'Tidak bisa mengubah akun sendiri.';

    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason): CarbonImmutable
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

        $expiredAt = DB::transaction(function () use ($actor, $target, $reason): CarbonImmutable {
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

            if (! $locked->must_change_password) {
                throw ValidationException::withMessages(['user' => self::MSG_NO_WINDOW]);
            }

            $now = CarbonImmutable::now();

            if (AccountControls::initialPasswordExpired($locked, $now)) {
                throw ValidationException::withMessages(['user' => self::MSG_EXPIRED]);
            }

            $previous = AccountControls::date($locked, 'initial_password_expires_at');

            $locked->forceFill(['initial_password_expires_at' => $now])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties([
                    'reason' => $reason,
                    'previous_expires_at' => $previous?->toIso8601String(),
                    'expires_at' => $now->toIso8601String(),
                ])
                ->log(self::EVENT);

            $target->setRawAttributes($locked->getAttributes(), true);

            return $now;
        });

        $this->revokeSessions->handle($target);

        return $expiredAt;
    }
}
