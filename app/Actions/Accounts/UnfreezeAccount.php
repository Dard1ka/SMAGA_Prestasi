<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginLockout;
use App\Enums\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Buka pembekuan" (B1, docs/SECURITY.md §3a K3/K8): lifts any freeze (failed logins or "Bukan saya?")
 * after the Admin checked the owner's identity. Counter reset; an account that still has to change an
 * initial/temporary password gets at least 72 more hours (a later deadline stays, QA9). Admin (`user.reset_password`),
 * and since 2026-11-05 Kesiswaan and Tata Usaha for a student through "Ubah status" (`student.status`,
 * StudentStatusAuthorization) except a "Bukan saya?" freeze (MSG_NOT_ME_ADMIN); never on the Admin's own account, reason required, audited (`account.unfrozen`). When
 * the email itself is suspect (a "Bukan saya?" freeze), use "Reset kata sandi & email" (ResetStudentAccount) instead: it
 * always removes the email (lead, 21 Okt 2026).
 */
final class UnfreezeAccount
{
    public const MAX_REASON = 500;

    /** A "Bukan saya?" freeze (K8) stays the Admin's: Kesiswaan and Tata Usaha (`student.status`) cannot lift it. */
    public const MSG_NOT_ME_ADMIN = 'Akun ini dibekukan lewat laporan "Bukan saya". Hanya Admin yang bisa membukanya.';

    public function __construct(private readonly LoginLockout $lockout) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason): void
    {
        StudentStatusAuthorization::authorize($actor, $target, Permission::UserResetPassword);

        if (! $actor->canLogIn() || $actor->is($target)) {
            abort(403);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan (maks. '.self::MAX_REASON.' karakter).']);
        }

        DB::transaction(function () use ($actor, $target, $reason): void {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if ($locked === null || ! AccountControls::isFrozen($locked)) {
                throw ValidationException::withMessages(['reason' => 'Akun ini tidak sedang dibekukan.']);
            }

            $frozenReason = AccountControls::frozenReason($locked);

            // K8: a hijack report is checked by an Admin (`user.reset_password`), also when Ubah status is used by Kesiswaan
            // or Tata Usaha (`student.status`, 2026-11-05).
            if ($frozenReason === AccountControls::FROZEN_NOT_ME && ! Gate::forUser($actor)->allows(Permission::UserResetPassword->value)) {
                throw ValidationException::withMessages(['user' => self::MSG_NOT_ME_ADMIN]);
            }

            $changes = ['frozen_at' => null, 'frozen_reason' => null, 'failed_activation_attempts' => 0];

            $current = AccountControls::date($locked, 'initial_password_expires_at');

            // Never shortens the window (QA9, the rule of D-95 for "Buka ulang"): the later one of the current
            // deadline and now + 72 h.
            if ((bool) $locked->must_change_password && $current !== null) {
                $changes['initial_password_expires_at'] = ReopenActivationWindow::deadlineAfter($current, CarbonImmutable::now());
            }

            $locked->forceFill($changes)->saveQuietly();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.unfrozen')
                ->withProperties(['reason' => $reason, 'was' => $frozenReason])
                ->log('account.unfrozen');

            $target->setRawAttributes($locked->getAttributes(), true);
        });

        $this->lockout->clear('u:'.$target->getKey());
    }
}
