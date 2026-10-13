<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Notifications\Verification\Notice;

/**
 * To the submitter: Kesiswaan rejected the lomba upload, with the reason when one was written (D-27, D-103). When the
 * submitter's own registration for it was waiting (`menunggu_lomba`, D-148) and was cancelled with the rejection, this
 * one notice says so too: no separate cancel notice.
 */
final class CompetitionRejected extends Notice
{
    public const NO_REASON = 'Kesiswaan tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu.';

    public const REGISTRATION_CANCELLED = 'Pendaftaranmu untuk lomba ini ikut dibatalkan.';

    public static function for(Competition $competition, ?string $note = null, bool $registrationCancelled = false): self
    {
        $note = trim((string) $note);
        $body = $note === '' ? self::NO_REASON : 'Alasan Kesiswaan: '.$note;

        return new self(
            kind: 'competition.rejected',
            title: 'Lomba tidak dipublikasikan: '.$competition->name,
            body: $registrationCancelled ? $body.' '.self::REGISTRATION_CANCELLED : $body,
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
