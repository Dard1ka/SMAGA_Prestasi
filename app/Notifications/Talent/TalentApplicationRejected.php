<?php

declare(strict_types=1);

namespace App\Notifications\Talent;

use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Notifications\Verification\Notice;

/** To the student: a pilihan talenta did not pass (tidak lolos), with the pembina note. */
final class TalentApplicationRejected extends Notice
{
    public static function for(TalentApplication $application, TalentStatus $from, string $talentLabel, string $note): self
    {
        return new self(
            kind: 'talent.rejected',
            title: 'Hasil seleksi talenta: '.$talentLabel,
            body: 'Pilihan talenta '.$talentLabel.' belum lolos tahap '.$from->label().'. Catatan: '.$note,
            routeName: 'siswa.talenta',
            actionLabel: 'Lihat profil talenta',
            extra: ['talent_application_id' => (string) $application->getKey()],
        );
    }
}
