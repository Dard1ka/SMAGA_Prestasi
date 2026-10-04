<?php

declare(strict_types=1);

namespace App\Notifications\Talent;

use App\Actions\Talent\TalentNote;
use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Notifications\Verification\Notice;

/**
 * To the student: Kesiswaan restored a "tidak lolos" pilihan talenta ("pengajuanmu dibuka kembali"), with the
 * stage it is in again and the optional note for the student.
 */
final class TalentApplicationRestored extends Notice
{
    public static function for(TalentApplication $application, string $talentLabel, ?string $studentNote = null, bool $reviewRequested = false): self
    {
        $body = ($reviewRequested ? 'Permintaan tinjau ulangmu diterima. ' : '')
            .'Pengajuanmu untuk '.$talentLabel.' dibuka kembali, sekarang di tahap '.$application->status->label().'.';

        if ($studentNote !== null && trim($studentNote) !== '') {
            $body .= ' Catatan dari Kesiswaan: '.TalentNote::inline($studentNote);
        }

        return new self(
            kind: 'talent.restored',
            title: 'Pengajuan talenta dibuka kembali: '.$talentLabel,
            body: $body,
            routeName: 'siswa.talenta',
            actionLabel: 'Lihat profil talenta',
            extra: [
                'talent_application_id' => (string) $application->getKey(),
                'from' => TalentStatus::TidakLolos->value,
                'to' => $application->status->value,
            ],
        );
    }
}
