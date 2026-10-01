<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Verification\Notice;

/** To the submitter: Kesiswaan rejected the lomba upload, with the reason (D-27). */
final class CompetitionRejected extends Notice
{
    public static function for(Competition $competition, string $note): self
    {
        return new self(
            kind: 'competition.rejected',
            title: 'Lomba tidak dipublikasikan: '.$competition->name,
            body: 'Alasan Kesiswaan: '.$note,
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
