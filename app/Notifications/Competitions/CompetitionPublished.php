<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\User;
use App\Notifications\Verification\Notice;
use App\Support\StaffRoleLabel;

/**
 * To the submitter (siswa usulan, guru, staff): the lomba passed Kesiswaan verification (D-27). When the submitter's own
 * registration waited for it (D-148), the same notice says where it went: on to the approval (individu) or the team
 * invitations sent (tim). The first sentence names the role of the account that decided (`$decider`) by the one lomba
 * rule, StaffRoleLabel::forLomba(), as CompetitionRejected does; BODY is its wording without a decider.
 */
final class CompetitionPublished extends Notice
{
    /** The first sentence after the deciding role. */
    private const VERIFIED_TAIL = ' sudah memverifikasi lomba yang dikirim. Lomba ini sekarang tampil di katalog.';

    public const BODY = StaffRoleLabel::LOMBA_FALLBACK.self::VERIFIED_TAIL;

    public static function for(Competition $competition, bool $toStudent, ?RegistrationStatus $registrationNow = null, ?User $decider = null): self
    {
        $registration = match ($registrationNow) {
            RegistrationStatus::MenungguKepsek => ' Pendaftaranmu diteruskan untuk persetujuan.',
            RegistrationStatus::MenungguKonfirmasi => ' Undangan tim sudah dikirim ke anggota.',
            default => '',
        };

        return new self(
            kind: 'competition.published',
            title: 'Lomba dipublikasikan: '.$competition->name,
            body: StaffRoleLabel::forLomba($decider).self::VERIFIED_TAIL.$registration,
            routeName: $toStudent ? 'siswa.lomba.show' : null,
            routeParameters: ['competition' => (string) $competition->getKey()],
            actionLabel: 'Lihat lomba',
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
