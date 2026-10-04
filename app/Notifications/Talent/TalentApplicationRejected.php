<?php

declare(strict_types=1);

namespace App\Notifications\Talent;

use App\Actions\Talent\TalentNote;
use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Notifications\Verification\Notice;

/**
 * To the student: a pilihan talenta did not pass (tidak lolos). The note for the student is optional; the
 * student can answer with "Minta ditinjau ulang" on Profil talenta.
 */
final class TalentApplicationRejected extends Notice
{
    public static function for(TalentApplication $application, TalentStatus $from, string $talentLabel, ?string $studentNote = null): self
    {
        $body = 'Pilihan talenta '.$talentLabel.' belum lolos tahap '.$from->label().'. Kamu bisa minta ditinjau ulang di Profil talenta.';

        if ($studentNote !== null && trim($studentNote) !== '') {
            $body .= ' Catatan dari Kesiswaan: '.TalentNote::inline($studentNote);
        }

        return new self(
            kind: 'talent.rejected',
            title: 'Hasil seleksi talenta: '.$talentLabel,
            body: $body,
            routeName: 'siswa.talenta',
            actionLabel: 'Lihat profil talenta',
            extra: ['talent_application_id' => (string) $application->getKey()],
        );
    }
}
