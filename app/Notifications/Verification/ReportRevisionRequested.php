<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Models\Report;

/** To the members: Kesiswaan needs a corrected laporan hasil (note included). */
final class ReportRevisionRequested extends Notice
{
    public static function for(Report $report, string $competitionName, string $note): self
    {
        return new self(
            kind: 'report.revision_requested',
            title: 'Perbaiki laporan hasil: '.$competitionName,
            body: 'Catatan Kesiswaan: '.$note,
            routeName: 'siswa.lomba-saya.show',
            routeParameters: ['registration' => (string) $report->registration_id],
            actionLabel: 'Perbaiki laporan',
            extra: ['report_id' => (string) $report->getKey()],
        );
    }
}
