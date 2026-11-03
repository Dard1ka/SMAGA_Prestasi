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
 * initial/temporary password gets at least 72 more hours (a later deadline stays, QA9). Admin only
 * (`user.reset_password`), never on the Admin's own account, reason required, audited (`account.unfrozen`). When
 * the email itself is suspect (a "Bukan saya?" freeze), use "Reset kata sandi & email" (ResetStudentAccount) instead: it
 * always removes the email (lead, 21 Okt 2026).
 */
final class UnfreezeAccount
{
    public const MAX_REASON = 500;

    public function __construct(private readonly LoginLockout $lockout) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason): void
    {
        Gate::forUser($actor)->authorize(Permission::UserResetPassword->value);

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
