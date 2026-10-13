<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Models\Competition;
use App\Models\User;
use App\Notifications\Verification\Notice;
use App\Support\StaffRoleLabel;

/**
 * To the submitter: Kesiswaan rejected the lomba upload, with the reason when one was written (D-27, D-103). When the
 * submitter's own registration for it was waiting (`menunggu_lomba`, D-148) and was cancelled with the rejection, this
 * one notice says so too: no separate cancel notice. The sentence names the role of the account that decided
 * (`$decider`, StaffRoleLabel, as D-139/D-150 do for the other decisions): an Admin may reject a lomba too. Without a
 * decider (previews, tests) it reads "Kesiswaan", the role that decides lomba.
 */
final class CompetitionRejected extends Notice
{
    public const NO_REASON = 'Kesiswaan tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu.';

    public const REGISTRATION_CANCELLED = 'Pendaftaranmu untuk lomba ini ikut dibatalkan.';

    public static function for(Competition $competition, ?string $note = null, bool $registrationCancelled = false, ?User $decider = null): self
    {
        $role = self::role($decider);
        $note = trim((string) $note);
        $body = match (true) {
            $note !== '' => 'Alasan '.$role.': '.$note,
            $role === 'Kesiswaan' => self::NO_REASON,
            default => $role.' tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu.',
        };

        return new self(
            kind: 'competition.rejected',
            title: 'Lomba tidak dipublikasikan: '.$competition->name,
            body: $registrationCancelled ? $body.' '.self::REGISTRATION_CANCELLED : $body,
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }

    /** The deciding role ("Kesiswaan", "Admin"); "Kesiswaan" when no decider is given. */
    public static function role(?User $decider): string
    {
        return $decider === null ? 'Kesiswaan' : StaffRoleLabel::forDecision($decider);
    }
}
