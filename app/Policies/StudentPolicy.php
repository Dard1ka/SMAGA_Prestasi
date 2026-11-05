<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
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

    /**
     * SV6, D-187 "Ubah data siswa" (App\Actions\Students\UpdateStudentData): staff holding `student.update` (Admin,
     * Kesiswaan, Tata Usaha) change name, NIS, NISN, birth date and class of a student who has an account and is not in
     * the Sampah. A student never edits these fields through this ability (Siswa does not hold the permission).
     */
    public function update(User $actor, Student $student): bool
    {
        return $actor->can(Permission::StudentUpdate->value)
            && ! $actor->hasRoleName(RoleName::Siswa)
            && ! $student->trashed()
            && $student->user_id !== null;
    }
}
