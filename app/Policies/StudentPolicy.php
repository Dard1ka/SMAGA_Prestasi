<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Student;
use App\Models\User;

/**
 * Student records. Deny by default; only the abilities below exist. Consent flags (photo_consent,
 * publish_consent, D-16, SECURITY §3m-7) are changed by holders of `student.update` (Admin) through
 * App\Actions\Students\UpdateConsent, never by the student.
 */
final class StudentPolicy
{
    /** Disabled/archived accounts can never act (D-29, D-30). */
    public function before(User $actor): ?bool
    {
        return $actor->canLogIn() ? null : false;
    }

    public function updateConsent(User $actor, Student $student): bool
    {
        return ! $student->trashed() && $actor->checkPermissionTo(Permission::StudentUpdate->value);
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
