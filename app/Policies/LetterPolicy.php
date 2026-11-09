<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\Registrations\ApproveRegistration;
use App\Enums\LetterType;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Surat tugas (D-06, SECURITY §3g, UI-MAP #21). Deny by default; letters are immutable (no update/delete).
 * - staff with `letter.view` (TU, Kepala Sekolah, Kesiswaan, Admin) see every letter;
 * - after sending, team members see the siswa letter, the guru pembimbing sees both; the guru letter only while it
 *   names that guru (namesCurrentMentor(): no guru change since it was generated; SV8, 2026-11-09);
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
            // SV8: the guru letter prints its guru's NIP, pangkat and jabatan. After a guru change it names the guru
            // before, so the new guru gets it only from Tata Usaha's Koreksi (the next version, generated later).
            return self::namesCurrentMentor($letter);
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
     * SV8 (2026-11-09): whether a letter still names the guru pembimbing its registration has now. Only the guru letter
     * prints a guru (name, NIP, pangkat, jabatan), and a letter is never edited: after a guru change (Kesiswaan, D-198;
     * an approved revision, D-199; audit `registration.mentor_changed`) logged at or after the moment the letter was
     * generated, it names the guru before until Tata Usaha's Koreksi issues the next version from the current data. A
     * change in the same second counts as later (the letter is withheld rather than shown to the wrong guru). Always
     * true for the student letter (it names no guru). One query on the activity log's subject index. view() uses it for
     * the guru pembimbing, and so does every screen that lists a guru letter to the guru or names its guru (Guru › detail
     * binaan, the student's "Surat tugas" card), so no link there leads to a 403.
     */
    public static function namesCurrentMentor(Letter $letter): bool
    {
        if ($letter->type !== LetterType::Guru) {
            return true;
        }

        $since = $letter->generated_at ?? $letter->created_at;

        return $since !== null && Activity::query()
            ->where('log_name', 'registration')
            ->where('event', ApproveRegistration::EVENT_MENTOR_CHANGED)
            ->where('subject_type', (new Registration)->getMorphClass())
            ->where('subject_id', (string) $letter->registration_id)
            ->where('created_at', '>=', $since)
            ->doesntExist();
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
