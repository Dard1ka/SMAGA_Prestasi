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
 * - only `letter.generate` issues letters, one letter type at a time, only for `disetujui` registrations
 *   that do not have that letter yet (checked again inside IssueLetters under a row lock); only `letter.send`
 *   sends them, once every required letter exists.
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

        // B3: a revoked version is history for staff only; members/mentor get the replacement.
        if ($letter->sent_at === null || $letter->revoked_at !== null) {
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

    /**
     * Issue ONE letter (number + PDF) of an approved registration (M1-A: one letter type at a time). With a
     * type: that letter does not exist yet (a guru letter needs a guru pembimbing). Without a type: at least
     * one required letter is still missing.
     */
    public function create(User $user, Registration $registration, ?LetterType $type = null): bool
    {
        if (! $this->viewQueue($user) || $registration->status !== RegistrationStatus::Disetujui) {
            return false;
        }

        if ($type === null) {
            return $registration->letters()->count() < count(self::requiredTypes($registration));
        }

        return in_array($type, self::requiredTypes($registration), true)
            && $registration->letters()->where('type', $type)->doesntExist();
    }

    /**
     * Send the issued letters to the team and the mentor (disetujui → surat_terbit): every required letter
     * has its PDF (siswa, plus guru when there is a guru pembimbing) and none was sent yet.
     */
    public function send(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn() || ! $user->can(Permission::LetterSend->value)) {
            return false;
        }

        if ($registration->status !== RegistrationStatus::Disetujui) {
            return false;
        }

        $letters = $registration->letters()->get();

        foreach (self::requiredTypes($registration) as $type) {
            if (! $letters->contains(fn (Letter $letter): bool => $letter->type === $type && $letter->file_id !== null)) {
                return false;
            }
        }

        return $letters->every(fn (Letter $letter): bool => $letter->sent_at === null);
    }

    /**
     * The letters a registration needs before "Kirim": always the siswa letter, the guru letter when it has a
     * guru pembimbing.
     *
     * @return list<LetterType>
     */
    public static function requiredTypes(Registration $registration): array
    {
        return $registration->mentor_id !== null ? [LetterType::Siswa, LetterType::Guru] : [LetterType::Siswa];
    }

    // --- B3: correction (backlog #11) ---

    /**
     * Cabut + issue the next version (RevokeLetter): `letter.generate` (Tata Usaha; Admin holds every
     * permission), only the current version of an issued letter. Allowed at any stage, always audited.
     */
    public function revoke(User $user, Letter $letter): bool
    {
        return $this->viewQueue($user)
            && $letter->revoked_at === null
            && $letter->file_id !== null;
    }

    // --- end B3 ---

    public function update(User $user, Letter $letter): bool
    {
        return false;
    }

    public function delete(User $user, Letter $letter): bool
    {
        return false;
    }
}
