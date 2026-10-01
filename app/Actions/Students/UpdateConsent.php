<?php

declare(strict_types=1);

namespace App\Actions\Students;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only way to change a student's consent flags (D-16, D-38, SECURITY §3m-7): `photo_consent` (photo on
 * flyers/publications) and `publish_consent` (name, public student page, certificate links). StudentPolicy::
 * updateConsent (`student.update`, Admin). Locked row, audited with the basis of the change (e.g. the parents'
 * form). Revoking publish consent rotates `students.public_slug`, so a shared link to the old public page
 * stops working even if consent is given again later. Public caches are flushed after commit.
 */
final class UpdateConsent
{
    public const MAX_NOTE = 500;

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Student $student, bool $photoConsent, bool $publishConsent, string $note): Student
    {
        Gate::forUser($actor)->authorize('updateConsent', $student);

        $note = trim($note);

        if ($note === '' || mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withMessages(['note' => 'Isi dasar perubahan (maks. '.self::MAX_NOTE.' karakter).']);
        }

        $changed = DB::transaction(function () use ($actor, $student, $photoConsent, $publishConsent, $note): bool {
            /** @var Student $locked */
            $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();
            $from = ['photo_consent' => (bool) $locked->photo_consent, 'publish_consent' => (bool) $locked->publish_consent];
            $to = ['photo_consent' => $photoConsent, 'publish_consent' => $publishConsent];

            if ($from === $to) {
                return false;
            }

            $rotate = $from['publish_consent'] && ! $publishConsent && $locked->public_slug !== null;

            $locked->forceFill($to + ($rotate ? ['public_slug' => self::newSlug()] : []))->save();

            activity('student')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('student.consent_updated')
                ->withProperties(['from' => $from, 'to' => $to, 'note' => $note, 'slug_rotated' => $rotate])
                ->log('student.consent_updated');

            $student->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($changed) {
            PublicAchievements::flush();
        }

        return $student;
    }

    /** Random URL key (SECURITY §3m-7: never NIS or an id), same format as PublicAchievements::SLUG_PATTERN. */
    public static function newSlug(): string
    {
        return bin2hex(random_bytes(10));
    }
}
