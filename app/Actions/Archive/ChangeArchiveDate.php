<?php

declare(strict_types=1);

namespace App\Actions\Archive;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Admin moves the archive date of a student who is not archived yet (e.g. tinggal kelas), SECURITY §3m-8:
 * `user.archive` (UserPolicy::changeArchiveDate), reason required, date after today (ArchiveDates). The kelas XII
 * start moves with it so a later change of the archive-months setting keeps the shift. Audited
 * (`student.archive_date_changed`, from/to + reason); public data cache flushed (angkatan follows kelas XII).
 */
final class ChangeArchiveDate
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Student $student, DateTimeInterface|string $date, string $reason): Student
    {
        /** @var User|null $user */
        $user = User::withTrashed()->find($student->user_id);
        abort_if($user === null, 404);

        Gate::forUser($actor)->authorize('changeArchiveDate', $user);

        $reason = ArchiveDates::reason($reason);
        $archiveAt = ArchiveDates::validated($date);

        $updated = DB::transaction(function () use ($actor, $student, $archiveAt, $reason): Student {
            /** @var Student $locked */
            $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            /** @var User $owner */
            $owner = User::withTrashed()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            if ($owner->isArchived()) {
                throw ValidationException::withMessages(['archiveDate' => 'Akun sudah diarsipkan. Pulihkan dulu.']);
            }

            $from = ['archive_at' => $locked->archive_at?->toDateString(), 'grade12_start_date' => $locked->grade12_start_date?->toDateString()];
            $grade12 = ArchiveDates::grade12StartFor($archiveAt);

            $locked->forceFill(['archive_at' => $archiveAt->toDateString(), 'grade12_start_date' => $grade12->toDateString()])->save();

            activity('account')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('student.archive_date_changed')
                ->withProperties([
                    'user_id' => $owner->getKey(),
                    'reason' => $reason,
                    'from' => $from,
                    'to' => ['archive_at' => $archiveAt->toDateString(), 'grade12_start_date' => $grade12->toDateString()],
                ])
                ->log('student.archive_date_changed');

            return $locked;
        });

        PublicAchievements::flush();

        return $updated;
    }
}
