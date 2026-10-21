<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Domain\Points\PointFormatter;
use App\Models\Achievement;

/**
 * To each member after the verifier verified the laporan hasil: capaian + points (full points per member).
 * `$decider` = the role label of who decided (`StaffRoleLabel::forDecision()`, D-139). D-160: the verifier's
 * "Catatan untuk siswa" (`reports.note`) goes with it when one was written; it never reached the student before.
 */
final class ReportVerified extends Notice
{
    public static function for(Achievement $achievement, string $decider, ?string $note = null): self
    {
        $competition = (string) ($achievement->competition?->name ?? 'lomba');
        $points = (new PointFormatter)->format((string) $achievement->points);
        $note = trim((string) $note);

        return new self(
            kind: 'report.verified',
            title: 'Prestasi terverifikasi: '.$competition,
            body: 'Capaian '.$achievement->result->label().' sudah diverifikasi '.$decider.'. Kamu mendapat '.$points.' poin.'
                .($note !== '' ? ' Catatan '.$decider.': '.$note : ''),
            routeName: 'siswa.portofolio',
            actionLabel: 'Lihat portofolio',
            extra: ['achievement_id' => (string) $achievement->getKey(), 'report_id' => (string) $achievement->report_id],
        );
    }
}
