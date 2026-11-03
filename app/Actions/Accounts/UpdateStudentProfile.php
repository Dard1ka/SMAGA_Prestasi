<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\PhoneNumber;
use App\Domain\Accounts\StudentProfileInput;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Profil saya › Data diri (D-152): the student keeps the own birth date and No HP current. Only the own record
 * (StudentPolicy::updateProfile). The same rules as the "Data diri" step of the activation (StudentProfileInput);
 * values that did not change write nothing. Audit `student.profile_updated` with the names of the changed fields
 * only, never a value (both are personal data). Nobody is notified.
 */
final class UpdateStudentProfile
{
    public const EVENT = 'student.profile_updated';

    /**
     * @return list<string> the changed fields (`birth_date`, `phone`); empty when nothing was written
     *
     * @throws ValidationException keys `birth_date` / `phone`
     */
    public function handle(User $actor, Student $student, string $birthDate, string $phone): array
    {
        Gate::forUser($actor)->authorize('updateProfile', $student);

        $errors = StudentProfileInput::errors($birthDate, $phone, CarbonImmutable::today());

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var CarbonImmutable $date */
        $date = StudentProfileInput::parseBirthDate($birthDate);
        $number = PhoneNumber::normalize($phone);

        $changed = DB::transaction(function () use ($actor, $student, $date, $number): array {
            /** @var Student $locked */
            $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            $changed = [];

            if ($locked->birth_date?->format('Y-m-d') !== $date->format('Y-m-d')) {
                $changed[] = 'birth_date';
            }

            // A value that cannot be decrypted (an old key) counts as "different": it is replaced.
            if (rescue(fn () => $locked->phone, null, false) !== $number) {
                $changed[] = 'phone';
            }

            if ($changed === []) {
                return [];
            }

            $locked->forceFill([
                'birth_date' => $date->format('Y-m-d'),
                'phone' => $number,
            ])->save();

            activity('student')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event(self::EVENT)
                ->withProperties(['fields' => $changed])
                ->log(self::EVENT);

            return $changed;
        });

        if ($changed !== []) {
            $student->forceFill(['birth_date' => $date->format('Y-m-d'), 'phone' => $number])
                ->syncOriginalAttributes(['birth_date', 'phone']);
        }

        return $changed;
    }
}
