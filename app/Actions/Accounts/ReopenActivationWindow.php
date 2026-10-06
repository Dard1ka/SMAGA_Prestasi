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
 * onboard students, D-26) give one not-yet-activated student 72 more hours for the password they already
 * have (initial or temporary). Also lifts a K3 freeze (failed logins) and resets its counter.
 * A K8 "Bukan saya?" freeze is NOT lifted here: only an Admin may (UnfreezeAccount or a K10 reset).
 * Reason required (at least MIN_REASON characters), audited (`account.activation_reopened`).
 *
 * A reopen never shortens a deadline (M2-B, QA 2026-10-05 Q2-09): the new deadline is the later one of the
 * current deadline and now + 72 hours. A student whose window already runs longer than that and who is not
 * frozen has nothing to reopen, so the Action refuses and the screen offers no button (wouldChange()).
 */
final class ReopenActivationWindow
{
    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public function __construct(private readonly LoginLockout $lockout) {}

    /**
     * The deadline a reopen at `$now` gives: the later one of the current deadline and now + REOPEN_HOURS.
     * An account without a deadline gets one (as before: a reopened password is always time-limited).
     */
    public static function deadlineAfter(?CarbonImmutable $current, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $reopened = ($now ?? CarbonImmutable::now())->addHours(AccountControls::REOPEN_HOURS);

        return $current !== null && $current->greaterThan($reopened) ? $current : $reopened;
    }

    /**
     * Whether "Buka ulang" does anything for a pending student right now: it lifts a failed-login freeze, or
     * it moves the deadline later (expired, or less than REOPEN_HOURS left). False for a window that still
     * runs longer than a reopen would give, and for an account that has no deadline at all.
     */
    public static function wouldChange(?CarbonImmutable $current, bool $frozenByFailedLogins, ?CarbonImmutable $now = null): bool
    {
        if ($frozenByFailedLogins) {
            return true;
        }

        return $current !== null && self::deadlineAfter($current, $now)->greaterThan($current);
    }

    /**
     * The sentence for "there is nothing to reopen" (no freeze to lift and a deadline that already runs
     * longer than a reopen would give), or null when a reopen does something. The screen asks before it
     * opens the dialog; handle() asks again under the lock.
     */
    public static function notNeededMessage(User $student, ?CarbonImmutable $now = null): ?string
    {
        $current = AccountControls::date($student, 'initial_password_expires_at');

        if ($current === null || AccountControls::isFrozen($student) || self::wouldChange($current, false, $now)) {
            return null;
        }

        return 'Batas aktivasi siswa ini masih sampai '.$current->translatedFormat('j M Y H:i').'. Belum perlu dibuka ulang.';
    }

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

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Isi alasannya.']);
        }

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => 'Alasan minimal '.self::MIN_REASON.' karakter.']);
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => 'Alasan maksimal '.self::MAX_REASON.' karakter.']);
        }

        $expiresAt = DB::transaction(function () use ($actor, $student, $reason): CarbonImmutable {
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

            $now = CarbonImmutable::now();
            $current = AccountControls::date($locked, 'initial_password_expires_at');

            // Nothing to lift and a deadline that already runs longer: a reopen would only add a log line.
            if (($notNeeded = self::notNeededMessage($locked, $now)) !== null) {
                throw ValidationException::withMessages(['reason' => $notNeeded]);
            }

            $expiresAt = self::deadlineAfter($current, $now);

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
                    'previous_expires_at' => $current?->toIso8601String(),
                    'unfrozen' => $frozenReason,
                ])
                ->log('account.activation_reopened');

            $student->setRawAttributes($locked->getAttributes(), true);

            return $expiresAt;
        });

        $this->lockout->clear('u:'.$student->getKey());

        return $expiresAt;
    }
}
