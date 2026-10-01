<?php

declare(strict_types=1);

namespace App\Actions\Archive;

use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin restores an archived student account (SECURITY §3m-8: only Admin, with a reason, audited). A new archive
 * date after today is required — otherwise the 01:00 job archives the account again at once. The account
 * becomes `active` when it had finished activation (password changed + verified email), else
 * `pending_activation`; it can log in again (D-29 only blocks archived accounts). Audited
 * (`account.unarchived`, status + dates from/to, reason); public data cache flushed (the "Alumni" chip goes).
 */
final class UnarchiveStudent
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $target, DateTimeInterface|string $newArchiveDate, string $reason): User
    {
        Gate::forUser($actor)->authorize('unarchive', $target);

        $reason = ArchiveDates::reason($reason);
        $archiveAt = ArchiveDates::validated($newArchiveDate);

        $restored = DB::transaction(function () use ($actor, $target, $archiveAt, $reason): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            /** @var Student|null $student */
            $student = Student::query()->where('user_id', $locked->getKey())->lockForUpdate()->first();

            if (! $locked->isArchived() || $student === null) {
                throw ValidationException::withMessages(['archiveDate' => 'Akun ini tidak diarsipkan.']);
            }

            $activated = ! $locked->must_change_password && filled($locked->email) && $locked->email_verified_at !== null;
            $status = $activated ? UserStatus::Active : UserStatus::PendingActivation;
            $from = ['archive_at' => $student->archive_at?->toDateString(), 'grade12_start_date' => $student->grade12_start_date?->toDateString()];
            $grade12 = ArchiveDates::grade12StartFor($archiveAt);

            $locked->forceFill(['status' => $status])->save();
            $student->forceFill(['archive_at' => $archiveAt->toDateString(), 'grade12_start_date' => $grade12->toDateString()])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('account.unarchived')
                ->withProperties([
                    'reason' => $reason,
                    'from' => UserStatus::Archived->value,
                    'to' => $status->value,
                    'dates' => [
                        'from' => $from,
                        'to' => ['archive_at' => $archiveAt->toDateString(), 'grade12_start_date' => $grade12->toDateString()],
                    ],
                ])
                ->log('account.unarchived');

            return $locked;
        });

        PublicAchievements::flush();

        return $restored;
    }
}
