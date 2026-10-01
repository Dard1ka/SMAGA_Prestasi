<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Models\Report;

/** To the members: the laporan hasil was rejected (reason included), no points. */
final class ReportRejected extends Notice
{
    public static function for(Report $report, string $competitionName, string $note): self
    {
        return new self(
            kind: 'report.rejected',
            title: 'Laporan hasil ditolak: '.$competitionName,
            body: 'Alasan: '.$note,
            routeName: 'siswa.lomba-saya.show',
            routeParameters: ['registration' => (string) $report->registration_id],
            actionLabel: 'Lihat lomba saya',
            extra: ['report_id' => (string) $report->getKey()],
        );
    }
}
