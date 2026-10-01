<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Verification\Notice;

/** To the submitter (siswa usulan, guru, staff): the lomba passed Kesiswaan verification (D-27). */
final class CompetitionPublished extends Notice
{
    public static function for(Competition $competition, bool $toStudent): self
    {
        return new self(
            kind: 'competition.published',
            title: 'Lomba dipublikasikan: '.$competition->name,
            body: 'Kesiswaan sudah memverifikasi lomba yang kamu kirim. Lomba ini sekarang tampil di katalog.',
            routeName: $toStudent ? 'siswa.lomba.show' : null,
            routeParameters: ['competition' => (string) $competition->getKey()],
            actionLabel: 'Lihat lomba',
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
