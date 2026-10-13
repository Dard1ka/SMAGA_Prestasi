<?php

declare(strict_types=1);

namespace App\Notifications\Competitions;

use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Notifications\Verification\Notice;

/**
 * To the submitter (siswa usulan, guru, staff): the lomba passed Kesiswaan verification (D-27). When the submitter's own
 * registration waited for it (D-148), the same notice says where it went: on to the approval (individu) or the team
 * invitations sent (tim).
 */
final class CompetitionPublished extends Notice
{
    public const BODY = 'Kesiswaan sudah memverifikasi lomba yang dikirim. Lomba ini sekarang tampil di katalog.';

    public static function for(Competition $competition, bool $toStudent, ?RegistrationStatus $registrationNow = null): self
    {
        $registration = match ($registrationNow) {
            RegistrationStatus::MenungguKepsek => ' Pendaftaranmu diteruskan untuk persetujuan.',
            RegistrationStatus::MenungguKonfirmasi => ' Undangan tim sudah dikirim ke anggota.',
            default => '',
        };

        return new self(
            kind: 'competition.published',
            title: 'Lomba dipublikasikan: '.$competition->name,
            body: self::BODY.$registration,
            routeName: $toStudent ? 'siswa.lomba.show' : null,
            routeParameters: ['competition' => (string) $competition->getKey()],
            actionLabel: 'Lihat lomba',
            extra: ['competition_id' => (string) $competition->getKey()],
        );
    }
}
