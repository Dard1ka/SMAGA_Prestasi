<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\LetterType;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;

/**
 * Surat tugas (D-06, SECURITY §3g, UI-MAP #21). Deny by default; letters are immutable (no update/delete).
 * - staff with `letter.view` (TU, Kepala Sekolah, Kesiswaan, Admin) see every letter;
 * - after sending, team members see the siswa letter, the guru pembimbing sees both;
 * - only `letter.generate` issues letters, only for `disetujui` registrations without letters (checked
 *   again inside IssueLetters under a row lock); only `letter.send` sends them.
 * FilePolicy (W2-A1) delegates downloads of `letter` files to view().
 */
class LetterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::LetterView->value);
    }

    /** TU queue screen (mockup TU-Surat-Tugas). */
    public function viewQueue(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::LetterGenerate->value);
    }

    public function view(User $user, Letter $letter): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        if ($user->can(Permission::LetterView->value)) {
            return true;
        }

        if ($letter->sent_at === null) {
            return false;
        }

        $registration = Registration::withTrashed()->find($letter->registration_id);

        if ($registration === null) {
            return false;
        }

        if ($registration->mentor_id !== null && (int) $registration->mentor_id === (int) $user->getKey()) {
            return true;
        }

        if ($letter->type !== LetterType::Siswa) {
            return false;
        }

        $studentId = $user->student()->value('id');

        return $studentId !== null && $registration->members()
            ->where('student_id', $studentId)
            ->whereNotNull('confirmed_at')
            ->whereNull('declined_at')
            ->exists();
    }

    /** Issue both letters (numbers + PDFs) for an approved registration. */
    public function create(User $user, Registration $registration): bool
    {
        return $this->viewQueue($user)
            && $registration->status === RegistrationStatus::Disetujui
            && $registration->letters()->doesntExist();
    }

    /** Send the issued letters to the team and the mentor (disetujui → surat_terbit). */
    public function send(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn() || ! $user->can(Permission::LetterSend->value)) {
            return false;
        }

        if ($registration->status !== RegistrationStatus::Disetujui) {
            return false;
        }

        $letters = $registration->letters()->get();

        return $letters->contains(fn (Letter $letter): bool => $letter->type === LetterType::Siswa && $letter->file_id !== null)
            && $letters->every(fn (Letter $letter): bool => $letter->sent_at === null);
    }

    public function update(User $user, Letter $letter): bool
    {
        return false;
    }

    public function delete(User $user, Letter $letter): bool
    {
        return false;
    }
}
