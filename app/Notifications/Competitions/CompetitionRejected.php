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
 * (`$decider`) by the one lomba rule, StaffRoleLabel::forLomba(): an Admin may reject a lomba too, and without a decider
 * (previews, tests) it reads "Kesiswaan".
 */
final class CompetitionRejected extends Notice
{
    /** The sentence without a reason, after the deciding role. */
    private const NO_REASON_TAIL = ' tidak menulis alasan. Tanyakan ke Kesiswaan bila perlu.';

    public const NO_REASON = StaffRoleLabel::LOMBA_FALLBACK.self::NO_REASON_TAIL;

    public const REGISTRATION_CANCELLED = 'Pendaftaranmu untuk lomba ini ikut dibatalkan.';

    public static function for(Competition $competition, ?string $note = null, bool $registrationCancelled = false, ?User $decider = null): self
    {
        $role = StaffRoleLabel::forLomba($decider);
        $note = trim((string) $note);
        $body = $note !== '' ? 'Alasan '.$role.': '.$note : $role.self::NO_REASON_TAIL;

        return new self(
            kind: 'competition.rejected',
            title: 'Lomba tidak dipublikasikan: '.$competition->name,
            body: $registrationCancelled ? $body.' '.self::REGISTRATION_CANCELLED : $body,
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
