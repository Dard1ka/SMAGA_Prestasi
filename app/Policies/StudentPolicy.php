<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;

/**
 * Student records. Deny by default; only the abilities below exist. The consent flags and `updateConsent` are gone
 * since D-162 (20 Okt 2026): every student may be named and every photo may be used.
 */
final class StudentPolicy
{
    /** Disabled/archived accounts can never act (D-29, D-30). */
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    /** The student's own No HP (D-149, UpdateStudentPhone): only the account the record belongs to. */
    public function updatePhone(User $actor, Student $student): bool
    {
        return ! $student->trashed()
            && $student->user_id !== null
            && (int) $student->user_id === (int) $actor->getKey()
            && $actor->hasRoleName(RoleName::Siswa);
    }

    /** The student's own birth date + No HP on Profil saya (D-152, UpdateStudentProfile): the same owner rule. */
    public function updateProfile(User $actor, Student $student): bool
    {
        return $this->updatePhone($actor, $student);
    }
}
