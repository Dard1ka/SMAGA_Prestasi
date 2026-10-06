<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Verification\Notice;

/** To the submitter: Kesiswaan rejected the lomba upload, with the reason when one was written (D-27, D-103). */
final class CompetitionRejected extends Notice
{
    public const NO_REASON = 'Kesiswaan tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu.';

    public static function for(Competition $competition, ?string $note = null): self
    {
        $note = trim((string) $note);

        return new self(
            kind: 'competition.rejected',
            title: 'Lomba tidak dipublikasikan: '.$competition->name,
            body: $note === '' ? self::NO_REASON : 'Alasan Kesiswaan: '.$note,
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
