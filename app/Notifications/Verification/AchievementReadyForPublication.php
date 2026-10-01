<?php

declare(strict_types=1);

namespace App\Notifications\Verification;

use App\Models\Achievement;

/** To Humas: a verified achievement (n members) is in the publication queue (status siap). */
final class AchievementReadyForPublication extends Notice
{
    public static function for(Achievement $achievement, int $members): self
    {
        $competition = (string) ($achievement->competition?->name ?? 'lomba');
        $who = $members > 1 ? 'Tim '.$members.' siswa' : 'Satu siswa';

        return new self(
            kind: 'achievement.ready_for_publication',
            title: 'Prestasi baru siap dipublikasikan',
            body: $who.' meraih '.$achievement->result->label().' di '.$competition.'. Cek persetujuan publikasi sebelum diunggah.',
            routeName: 'humas.beranda',
            actionLabel: 'Buka antrean publikasi',
            extra: ['report_id' => (string) $achievement->report_id],
        );
    }
}
