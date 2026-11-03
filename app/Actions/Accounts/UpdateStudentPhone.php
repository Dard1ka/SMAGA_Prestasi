<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Domain\Accounts\PhoneNumber;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The student keeps the own No HP current (D-149): on the registration form ("Data peserta") and on "Ikut tim".
 * Only the own record (StudentPolicy::updatePhone). The number is normalised and checked with the import's rule
 * (PhoneNumber); the same number again writes nothing. Audit `student.phone_updated` without the number (the import
 * writes the same event).
 */
final class UpdateStudentPhone
{
    public const EMPTY = 'Isi No HP.';

    /** @throws ValidationException */
    public function handle(User $actor, Student $student, string $phone): void
    {
        Gate::forUser($actor)->authorize('updatePhone', $student);

        $phone = PhoneNumber::normalize($phone);

        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => self::EMPTY]);
        }

        if (! PhoneNumber::isValid($phone)) {
            throw ValidationException::withMessages(['phone' => PhoneNumber::INVALID]);
        }

        DB::transaction(function () use ($actor, $student, $phone): void {
            /** @var Student $locked */
            $locked = Student::query()->whereKey($student->getKey())->lockForUpdate()->firstOrFail();

            // A value that cannot be decrypted (an old key) counts as "different": it is replaced.
            if (rescue(fn () => $locked->phone, null, false) === $phone) {
                return;
            }

            $locked->forceFill(['phone' => $phone])->save();

            activity('student')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('student.phone_updated')
                ->withProperties([])
                ->log('student.phone_updated');
        });

        $student->forceFill(['phone' => $phone])->syncOriginalAttribute('phone');
    }
}
