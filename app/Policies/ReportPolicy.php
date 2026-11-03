<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;

/**
 * Laporan hasil (ARCHITECTURE §6, UI-MAP #12/#13/#17). Deny by default.
 *
 * Owner of create/view: W2-A2 (siswa). W2-B adds the Kesiswaan abilities (viewAny, verify, …) below the
 * marker at the end of this class.
 */
class ReportPolicy
{
    /**
     * Only the ketua submits, only while the registration waits for the report (first time, or again after
     * "perlu revisi", which moves the registration back to menunggu_laporan). Late reports are allowed and
     * flagged by SubmitReport (D-25).
     */
    public function create(User $user, Registration $registration): bool
    {
        if (! $user->canLogIn() || ! $user->can(Permission::ReportSubmit->value)) {
            return false;
        }

        $studentId = $user->student()->value('id');

        if ($studentId === null || $registration->leader_student_id !== $studentId) {
            return false;
        }

        if ($registration->status !== RegistrationStatus::MenungguLaporan) {
            return false;
        }

        $report = $registration->report()->first();

        return $report === null || $report->status === ReportStatus::PerluRevisi;
    }

    /**
     * Team members who did not decline, the guru pembimbing of the registration, and verifiers.
     */
    public function view(User $user, Report $report): bool
    {
        if ($user->can(Permission::ReportVerify->value)) {
            return true;
        }

        $registration = $report->registration()->withTrashed()->first();

        if ($registration === null) {
            return false;
        }

        if ($registration->mentor_id !== null && (int) $registration->mentor_id === (int) $user->getKey()) {
            return true;
        }

        $studentId = $user->student()->value('id');

        return $studentId !== null && $registration->members()
            ->where('student_id', $studentId)
            ->whereNull('declined_at')
            ->exists();
    }

    // --- W2-B: Kesiswaan abilities below this line ---

    /** Kesiswaan/Admin verification queue (UI-MAP #17, `report.verify`). */
    public function viewQueue(User $user): bool
    {
        return $user->canLogIn() && $user->can(Permission::ReportVerify->value);
    }

    /**
     * Verify, ask for a revision or reject: `report.verify`, the report is still `dikirim`, and never for
     * the verifier's own binaan (the registration's guru pembimbing, SECURITY §3c-10).
     */
    public function verify(User $user, Report $report): bool
    {
        if (! $this->viewQueue($user) || $report->status !== ReportStatus::Dikirim) {
            return false;
        }

        $mentorId = $report->registration()->withTrashed()->value('mentor_id');

        return $mentorId === null || (int) $mentorId !== (int) $user->getKey();
    }
}
