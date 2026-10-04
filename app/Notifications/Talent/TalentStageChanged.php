<?php

declare(strict_types=1);

namespace App\Notifications\Talent;

use App\Actions\Talent\TalentNote;
use App\Enums\TalentStatus;
use App\Models\TalentApplication;
use App\Notifications\Verification\Notice;

/** To the student: a pilihan talenta moved to the next pipeline stage, with the optional note for the student. */
final class TalentStageChanged extends Notice
{
    public static function for(TalentApplication $application, TalentStatus $from, string $talentLabel, ?string $studentNote = null): self
    {
        $body = $application->status === TalentStatus::Pembinaan
            ? 'Selamat, kamu lolos menjadi peserta pembinaan '.$talentLabel.'.'
            : 'Pilihan talenta '.$talentLabel.' lolos ke tahap '.$application->status->label().'.';

        if ($application->assessment_on !== null && $application->status === TalentStatus::Seleksi) {
            $body .= ' Jadwal asesmen '.$application->assessment_on->translatedFormat('j M Y').'.';
        }

        if ($studentNote !== null && trim($studentNote) !== '') {
            $body .= ' Catatan dari Kesiswaan: '.TalentNote::inline($studentNote);
        }

        return new self(
            kind: 'talent.stage_changed',
            title: 'Tahap talenta berubah: '.$talentLabel,
            body: $body,
            routeName: 'siswa.talenta',
            actionLabel: 'Lihat profil talenta',
            extra: ['talent_application_id' => (string) $application->getKey(), 'from' => $from->value, 'to' => $application->status->value],
        );
    }
}
