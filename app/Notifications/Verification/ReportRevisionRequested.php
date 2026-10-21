<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Models\Registration;
use App\Models\Report;
use App\Models\Student;
use App\Models\User;

/**
 * To the members: the verifier needs a corrected laporan hasil (note included; `$decider` names the role, D-139). Only the ketua may fix it
 * (ReportPolicy::create), so only the ketua is asked to "Perbaiki laporan" (QA10); every other member hears that
 * the ketua does it, like the guru (BinaanReportDecided::revision), and gets a link to the lomba, not a fix button.
 */
final class ReportRevisionRequested extends Notice
{
    /** Account of the ketua when the notice was built; null = unknown (everyone then gets the ketua's text). */
    public ?int $leaderUserId = null;

    public string $memberTitle = '';

    public string $memberBody = '';

    /**
     * `$leaderCanResend` false (D-160): the ketua's account cannot log in, so nobody can send the report again; the
     * members are not told that the ketua fixes it but to ask the school (RequestReportRevision::leaderCanResend()).
     */
    public static function for(Report $report, string $competitionName, string $note, string $decider, bool $leaderCanResend = true): self
    {
        $notice = new self(
            kind: 'report.revision_requested',
            title: 'Perbaiki laporan hasil: '.$competitionName,
            body: 'Catatan '.$decider.': '.$note,
            routeName: 'siswa.lomba-saya.show',
            routeParameters: ['registration' => (string) $report->registration_id],
            actionLabel: 'Perbaiki laporan',
            extra: ['report_id' => (string) $report->getKey()],
        );

        $notice->leaderUserId = self::leaderUserId($report);
        $notice->memberTitle = 'Laporan hasil perlu revisi: '.$competitionName;
        $notice->memberBody = 'Catatan '.$decider.': '.self::sentence($note)
            .($leaderCanResend ? ' Ketua tim memperbaiki laporan dari akunnya.' : ' Akun ketua tim tidak bisa dipakai masuk, jadi hubungi Kesiswaan untuk melanjutkan laporan ini.');

        return $notice;
    }

    protected function titleFor(object $notifiable): string
    {
        return $this->isLeader($notifiable) ? $this->title : $this->memberTitle;
    }

    protected function bodyFor(object $notifiable): string
    {
        return $this->isLeader($notifiable) ? $this->body : $this->memberBody;
    }

    protected function actionLabelFor(object $notifiable): string
    {
        return $this->isLeader($notifiable) ? $this->actionLabel : 'Lihat lomba';
    }

    private function isLeader(object $notifiable): bool
    {
        return $this->leaderUserId === null
            || $this->memberTitle === ''
            || ($notifiable instanceof User && (int) $notifiable->getKey() === $this->leaderUserId);
    }

    private static function leaderUserId(Report $report): ?int
    {
        $leaderStudentId = Registration::query()->whereKey($report->registration_id)->value('leader_student_id');
        $userId = $leaderStudentId !== null ? Student::withTrashed()->whereKey($leaderStudentId)->value('user_id') : null;

        return $userId !== null ? (int) $userId : null;
    }

    /** The note as one sentence, so the text after it does not run on. */
    private static function sentence(string $note): string
    {
        $note = trim($note);

        return preg_match('/[.!?]$/u', $note) === 1 ? $note : $note.'.';
    }
}
