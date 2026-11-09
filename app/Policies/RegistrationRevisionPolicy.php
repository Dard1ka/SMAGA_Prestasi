<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Models\User;

/**
 * SV8, D-199: revisions of an approved registration. Deny by default; not-yours → callers answer 404.
 * - viewQueue / decide: Tata Usaha and Kesiswaan (`registration.revise_decide`, "TU atau kesiswaan", the lead); never
 *   the Kepala Sekolah, Admin, Humas or a guru; decide only while the revision waits (the first decision wins).
 * - respond: the student the revision invites, once, while it waits.
 * - cancel: the ketua of the registration, while it waits.
 * - view: those three (the invitee's page Siswa › Undangan revisi; the ketua's card on Lomba saya; the staff screen).
 */
class RegistrationRevisionPolicy
{
    public function viewQueue(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::RegistrationReviseDecide->value);
    }

    public function decide(User $user, RegistrationRevision $revision): bool
    {
        return $this->viewQueue($user) && $revision->isPending();
    }

    public function view(User $user, RegistrationRevision $revision): bool
    {
        if ($this->viewQueue($user)) {
            return true;
        }

        $student = $this->student($user);

        if ($student === null || ! $user->canLogIn()) {
            return false;
        }

        return in_array((string) $student->getKey(), $revision->additions(), true)
            || (string) $revision->loadMissing('registration')->registration?->leader_student_id === (string) $student->getKey();
    }

    public function respond(User $user, RegistrationRevision $revision): bool
    {
        $student = $this->student($user);

        return $student !== null
            && $user->canLogIn()
            && $user->can(Permission::RegistrationCreate->value)
            && $revision->isPending()
            && in_array((string) $student->getKey(), $revision->additions(), true)
            && $revision->answerOf((string) $student->getKey()) === null;
    }

    public function cancel(User $user, RegistrationRevision $revision): bool
    {
        $student = $this->student($user);

        return $student !== null
            && $user->canLogIn()
            && $revision->isPending()
            && (string) $revision->loadMissing('registration')->registration?->leader_student_id === (string) $student->getKey();
    }

    private function student(User $user): ?Student
    {
        return $user->loadMissing('student')->getRelation('student');
    }
}
