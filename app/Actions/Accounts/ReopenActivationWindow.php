<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Fortify\LoginLockout;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * K2 "Buka ulang aktivasi" (docs/SECURITY.md §3a): Kesiswaan or Admin (`student.import`, the people who
 * onboard students, D-26) give one not-yet-activated student a new 72-hour window for the password they
 * already have (initial or temporary). Also lifts a K3 freeze (failed logins) and resets its counter.
 * A K8 "Bukan saya?" freeze is NOT lifted here: only an Admin may (UnfreezeAccount or a K10 reset).
 * Reason required, audited (`account.activation_reopened`).
 */
final class ReopenActivationWindow
{
    public const MAX_REASON = 500;

    public function __construct(private readonly LoginLockout $lockout) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $student, string $reason): CarbonImmutable
    {
        Gate::forUser($actor)->authorize(Permission::StudentImport->value);

        if (! $actor->canLogIn()) {
            abort(403);
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan (maks. '.self::MAX_REASON.' karakter).']);
        }

        $expiresAt = CarbonImmutable::now()->addHours(AccountControls::REOPEN_HOURS);

        DB::transaction(function () use ($actor, $student, $reason, $expiresAt): void {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($student->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->student()->exists()) {
                throw ValidationException::withMessages(['reason' => 'Akun ini bukan akun siswa.']);
            }

            if ($locked->status !== UserStatus::PendingActivation) {
                throw ValidationException::withMessages(['reason' => $locked->status === UserStatus::Active
                    ? 'Akun ini sudah aktif, tidak perlu dibuka ulang.'
                    : 'Akun ini nonaktif atau diarsipkan.']);
            }

            $frozenReason = AccountControls::frozenReason($locked);

            if ($frozenReason === AccountControls::FROZEN_NOT_ME) {
                throw ValidationException::withMessages(['reason' => 'Akun dibekukan lewat laporan "Bukan saya". Hanya Admin yang bisa membukanya.']);
            }

            $locked->forceFill([
                'initial_password_expires_at' => $expiresAt,
                'failed_activation_attempts' => 0,
                'frozen_at' => null,
                'frozen_reason' => null,
            ])->saveQuietly();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.activation_reopened')
                ->withProperties([
                    'reason' => $reason,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'unfrozen' => $frozenReason,
                ])
                ->log('account.activation_reopened');

            $student->setRawAttributes($locked->getAttributes(), true);
        });

        $this->lockout->clear('u:'.$student->getKey());

        return $expiresAt;
    }
}
