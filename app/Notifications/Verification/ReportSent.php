<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Models\Report;
use App\Models\User;

/**
 * D-160 (the lead, 2026-10-19: "pastikan ada notifikasi ke siswa"): to the members when the laporan hasil is sent or
 * sent again after "Minta revisi". The ketua gets a receipt (the toast on the screen leaves no record); every other
 * member hears that the ketua sent it, so after a revision request they also learn that the corrected report went out.
 * Sentences before any decision say "sekolah" (D-139). Bell + mail like every Notice, after commit.
 */
final class ReportSent extends Notice
{
    /** Account of the ketua when the notice was built: that recipient reads the receipt. */
    public ?int $leaderUserId = null;

    public string $memberTitle = '';

    public string $memberBody = '';

    public static function for(Report $report, string $competitionName, ?User $leader, bool $resubmitted): self
    {
        $notice = new self(
            kind: 'report.sent',
            title: ($resubmitted ? 'Perbaikan laporan terkirim: ' : 'Laporan hasil terkirim: ').$competitionName,
            body: ($resubmitted ? 'Perbaikan laporan hasilmu' : 'Laporan hasilmu').' sudah diterima dan menunggu verifikasi sekolah. Kamu diberi tahu lagi setelah sekolah memeriksanya.',
            routeName: 'siswa.lomba-saya.show',
            routeParameters: ['registration' => (string) $report->registration_id],
            actionLabel: 'Lihat lomba',
            extra: ['report_id' => (string) $report->getKey()],
        );

        $leaderName = trim((string) $leader?->name) !== '' ? (string) $leader?->name : 'Ketua tim';
        $notice->leaderUserId = $leader !== null ? (int) $leader->getKey() : null;
        $notice->memberTitle = ($resubmitted ? 'Laporan hasil diperbaiki: ' : 'Laporan hasil dikirim: ').$competitionName;
        $notice->memberBody = $leaderName.($resubmitted ? ' mengirim perbaikan laporan hasil tim.' : ' mengirim laporan hasil tim.')
            .' Laporan menunggu verifikasi sekolah.';

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

    private function isLeader(object $notifiable): bool
    {
        return $this->memberTitle === ''
            || ($notifiable instanceof User && $this->leaderUserId !== null && (int) $notifiable->getKey() === $this->leaderUserId);
    }
}
