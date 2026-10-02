<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FilePurpose;
use App\Enums\Permission;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Models\Competition;
use App\Models\File;
use App\Models\Letter;
use App\Models\Publication;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Downloads through FileController (`files.show`, SECURITY §3d). Deny by default; the decision is
 * delegated to the policy of the record that uses the file, per FilePurpose:
 * - juknis / cover       → CompetitionPolicy::view of a lomba that uses it (unpublished usulan: submitter + verifiers)
 * - letter               → LetterPolicy::view (W2-C) of the letter with that file_id
 * - report_evidence      → ReportPolicy::view (W2-A2) of the report, or report.verify (Kesiswaan)
 * - talent_evidence      → application evidence: TalentApplicationPolicy::view (W2-A2) or talent.manage;
 *                          Riwayat talenta certificates (talent_profile_files): the student, talent.manage,
 *                          or a guru with talent.assess who mentors one of that student's applications
 * - flyer                → publication.manage while its publication is eligible (verified + publish consent,
 *                          PublicationPolicy::eligible(); SECURITY §3i, §3m-7)
 * - signature            → signature.manage only (TU never downloads it, SECURITY §3m-5)
 * - import               → the uploader or student.import
 * The uploader may always fetch their own file, except the signature image and a flyer.
 * Guests: only viewPublic() below (verified certificates with consent, Admin switch on).
 */
class FilePolicy
{
    public function view(User $user, File $file): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        if ($file->purpose === FilePurpose::Signature) {
            return $user->can(Permission::SignatureManage->value);
        }

        // Before the uploader shortcut: the "owner" of a flyer is the Humas user who generated it, and a
        // withdrawn publish consent must win over that.
        if ($file->purpose === FilePurpose::Flyer) {
            return $this->viaPublication($user, $file);
        }

        if ($file->owner_user_id !== null && (int) $file->owner_user_id === (int) $user->getKey()) {
            return true;
        }

        return match ($file->purpose) {
            FilePurpose::Juknis, FilePurpose::Cover => $this->viaCompetition($user, $file),
            FilePurpose::Letter => $this->viaLetter($user, $file),
            FilePurpose::ReportEvidence => $this->viaReport($user, $file),
            FilePurpose::TalentEvidence => $this->viaTalentEvidence($user, $file),
            FilePurpose::Import => $user->can(Permission::StudentImport->value),
            default => false,
        };
    }

    /**
     * Guest access (route `files.public`, W2-D request; D-16, U-04, SECURITY §3m-7). Only a certificate
     * (report_files.kind = sertifikat) of a VERIFIED report, only while the Admin switch
     * `public_certificates` is on, and only when every member of that registration (not declined) has
     * publish_consent — a team certificate shows all names. Everything else: denied (the route answers 404).
     */
    public function viewPublic(?User $user, File $file): bool
    {
        if ($file->purpose !== FilePurpose::ReportEvidence || ! (bool) Setting::get(SettingKey::PublicCertificates)) {
            return false;
        }

        $reportFile = ReportFile::query()
            ->with('report')
            ->where('file_id', $file->getKey())
            ->where('kind', ReportFileKind::Sertifikat)
            ->first();

        $report = $reportFile?->report;

        if ($report === null || $report->status !== ReportStatus::Diverifikasi) {
            return false;
        }

        $consents = Student::query()
            ->whereHas('memberships', fn ($m) => $m->where('registration_id', $report->registration_id)->whereNull('declined_at'))
            ->pluck('publish_consent');

        return $consents->isNotEmpty() && $consents->every(fn ($consent): bool => (bool) $consent);
    }

    private function viaCompetition(User $user, File $file): bool
    {
        return Competition::query()
            ->where(fn ($q) => $q->where('juknis_file_id', $file->getKey())->orWhere('cover_file_id', $file->getKey()))
            ->get()
            ->contains(fn (Competition $competition): bool => $user->can('view', $competition));
    }

    private function viaLetter(User $user, File $file): bool
    {
        $letter = Letter::query()->where('file_id', $file->getKey())->first();

        return $letter !== null && $user->can('view', $letter);
    }

    /**
     * A flyer carries the student's name and class: `publication.manage` AND a publication that uses it
     * and is still publishable (verified achievement + publish consent, PublicationPolicy::eligible()).
     * Consent withdrawn → nobody downloads it any more, the user who generated it included.
     */
    private function viaPublication(User $user, File $file): bool
    {
        if (! $user->can(Permission::PublicationManage->value)) {
            return false;
        }

        // D-75: whoever may see student data anyway (`student.view`: Admin) keeps the download after a withdrawal,
        // e.g. to check what has to be taken down. Humas has no `student.view`.
        $seesStudents = $user->can(Permission::StudentView->value);

        return Publication::query()
            ->where('flyer_file_id', $file->getKey())
            ->with('achievement.student')
            ->get()
            ->contains(fn (Publication $publication): bool => $seesStudents || PublicationPolicy::eligible($publication));
    }

    private function viaReport(User $user, File $file): bool
    {
        $reportFile = ReportFile::query()->with('report')->where('file_id', $file->getKey())->first();

        if ($reportFile === null || $reportFile->report === null) {
            return false;
        }

        return $user->can(Permission::ReportVerify->value) || $user->can('view', $reportFile->report);
    }

    /**
     * talent_evidence is used twice: the evidence of one talent application, and the supporting
     * certificates of a student's Riwayat talenta (W2-A2 table talent_profile_files).
     */
    private function viaTalentEvidence(User $user, File $file): bool
    {
        $application = TalentApplication::query()->where('evidence_file_id', $file->getKey())->first();

        if ($application !== null && ($user->can(Permission::TalentManage->value) || $user->can('view', $application))) {
            return true;
        }

        $profileId = DB::table('talent_profile_files')->where('file_id', $file->getKey())->value('talent_profile_id');
        $studentId = $profileId === null ? null : TalentProfile::query()->whereKey($profileId)->value('student_id');

        if ($studentId === null) {
            return false;
        }

        if ($user->can(Permission::TalentManage->value)) {
            return true;
        }

        if ((int) Student::query()->whereKey($studentId)->value('user_id') === (int) $user->getKey()) {
            return true;
        }

        // Guru pembina: only for their own binaan (a talent application of that student they mentor).
        return $user->can(Permission::TalentAssess->value)
            && TalentApplication::query()->where('student_id', $studentId)->where('mentor_id', $user->getKey())->exists();
    }
}
