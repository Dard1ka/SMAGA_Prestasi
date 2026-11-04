<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Archive\ArchiveDates;
use App\Actions\Fortify\LoginLockout;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin "Aktif" (formerly "Aktif kembali") for a disabled student account (SV5, the lead 2026-11-04): the undo of DisableAccount, which had
 * none. Admin only (`user.archive`, the permission of DisableAccount), never the Admin's own account, only a student
 * account in `disabled`; reason required (MIN_REASON–MAX_REASON characters, the rule of "Buka ulang").
 *
 * In one transaction under a row lock the account must be able to log in again afterwards:
 * - status `active` when it had finished activation (the rule of UnarchiveStudent: password changed + verified email),
 *   else `pending_activation`;
 * - when the student's automatic archive date is today or earlier, a new date after today is required (error key
 *   `archiveDate`), else the 01:00 job archives the account again that night; it is written with the matching kelas XII
 *   start the way UnarchiveStudent does (ArchiveDates). A date given for a student whose archive date has not passed is
 *   ignored (Admin › Arsip otomatis moves such a date);
 * - a pending account that still has to change its initial or temporary password gets at least 72 more hours of that
 *   window (a later deadline stays: the rule of UnfreezeAccount and "Buka ulang");
 * - a K3/K8 freeze is lifted with its counter, and every login lock of the account is cleared after the commit.
 *
 * Audited as `account.enabled` (reason, from, to, the window, the freeze and the dates that changed; no names). The
 * public data cache is flushed (a disabled student is unnamed there, D-162). Nobody is notified (DisableAccount tells
 * nobody either).
 */
final class EnableAccount
{
    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    public const MSG_DATE_PASSED = 'Tanggal arsip otomatis siswa ini sudah lewat. Isi tanggal arsip baru.';

    public const MSG_NOT_DISABLED = 'Akun ini tidak sedang nonaktif.';

    public const MSG_NOT_STUDENT = 'Akun ini bukan akun siswa.';

    public const MSG_SELF = 'Tidak bisa mengaktifkan akun sendiri.';

    public function __construct(private readonly LoginLockout $lockout) {}

    /**
     * Whether the student's automatic archive date is today or earlier (Asia/Jakarta), i.e. whether the 01:00 job would
     * archive the account again once it can log in (Student::scopeDueForArchive()). No date = never.
     */
    public static function archiveDatePassed(?Student $student, ?DateTimeInterface $today = null): bool
    {
        $archiveAt = $student?->archive_at;

        if ($archiveAt === null) {
            return false;
        }

        $day = CarbonImmutable::instance($today ?? CarbonImmutable::now(config('app.timezone')))->toDateString();

        return $archiveAt->toDateString() <= $day;
    }

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, string $reason, DateTimeInterface|string|null $newArchiveDate = null): User
    {
        Gate::forUser($actor)->authorize(Permission::UserArchive->value);

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

        $date = $newArchiveDate === '' ? null : $newArchiveDate;

        $enabled = DB::transaction(function () use ($actor, $target, $reason, $date): User {
            /** @var User|null $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();
            /** @var Student|null $student */
            $student = $locked === null ? null : Student::query()->where('user_id', $locked->getKey())->lockForUpdate()->first();

            if ($locked === null || $student === null) {
                throw ValidationException::withMessages(['user' => self::MSG_NOT_STUDENT]);
            }

            if ($locked->status !== UserStatus::Disabled) {
                throw ValidationException::withMessages(['user' => self::MSG_NOT_DISABLED]);
            }

            $now = CarbonImmutable::now();
            $dates = null;

            if (self::archiveDatePassed($student)) {
                if ($date === null) {
                    throw ValidationException::withMessages(['archiveDate' => self::MSG_DATE_PASSED]);
                }

                $archiveAt = ArchiveDates::validated($date);
                $grade12 = ArchiveDates::grade12StartFor($archiveAt);
                $dates = [
                    'from' => ['archive_at' => $student->archive_at?->toDateString(), 'grade12_start_date' => $student->grade12_start_date?->toDateString()],
                    'to' => ['archive_at' => $archiveAt->toDateString(), 'grade12_start_date' => $grade12->toDateString()],
                ];
                $student->forceFill(['archive_at' => $archiveAt->toDateString(), 'grade12_start_date' => $grade12->toDateString()])->save();
            }

            $activated = ! $locked->must_change_password && filled($locked->email) && $locked->email_verified_at !== null;
            $status = $activated ? UserStatus::Active : UserStatus::PendingActivation;
            $changes = ['status' => $status];
            $window = null;

            // The initial or temporary password of a pending account: at least 72 more hours, never shorter.
            $current = AccountControls::date($locked, 'initial_password_expires_at');

            if ($status === UserStatus::PendingActivation && (bool) $locked->must_change_password && $current !== null) {
                $until = ReopenActivationWindow::deadlineAfter($current, $now);

                if (! $until->equalTo($current)) {
                    $changes['initial_password_expires_at'] = $until;
                    $window = ['from' => $current->toIso8601String(), 'to' => $until->toIso8601String()];
                }
            }

            // "Aktif" leaves an account that can log in: a freeze and its counter go too.
            $frozenReason = AccountControls::frozenReason($locked);
            $wasFrozen = AccountControls::isFrozen($locked);

            if ($wasFrozen || AccountControls::failedAttempts($locked) > 0) {
                $changes += ['frozen_at' => null, 'frozen_reason' => null, 'failed_activation_attempts' => 0];
            }

            $locked->forceFill($changes)->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.enabled')
                ->withProperties([
                    'reason' => $reason,
                    'from' => UserStatus::Disabled->value,
                    'to' => $status->value,
                    'window' => $window,
                    'unfrozen' => $wasFrozen ? ($frozenReason ?? true) : null,
                    'dates' => $dates,
                ])
                ->log('account.enabled');

            $target->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });

        $this->lockout->clear('u:'.$target->getKey());

        // A disabled student is unnamed on the public pages; an enabled one is named again (D-162, NamedStudent).
        PublicAchievements::flush();

        return $enabled;
    }
}
