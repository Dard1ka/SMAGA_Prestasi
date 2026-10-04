<?php

declare(strict_types=1);

namespace App\Notifications\Talent;

use App\Actions\Talent\TalentNote;
use App\Models\TalentApplication;
use App\Notifications\Verification\Notice;

/**
 * To every Kesiswaan: a student asked for a second look at a rejected pilihan talenta ("Minta ditinjau ulang").
 * Carries the student's name, class and optional message (plain text, D-69); never the NIS. The link opens the
 * tab "Tidak lolos", where the row has the chip and "Pulihkan".
 */
final class TalentReviewRequested extends Notice
{
    public static function for(TalentApplication $application, string $studentName, ?string $classroom, string $talentLabel, ?string $message = null): self
    {
        $who = trim($studentName).($classroom !== null && $classroom !== '' ? ' ('.$classroom.')' : '');
        $body = $who.' minta pilihan talenta '.$talentLabel.' yang tidak lolos ditinjau ulang.';

        if ($message !== null && trim($message) !== '') {
            $body .= ' Pesan siswa: '.TalentNote::inline($message);
        }

        return new self(
            kind: 'talent.review_requested',
            title: 'Minta ditinjau ulang: '.$talentLabel,
            body: $body,
            routeName: 'kesiswaan.talenta.index',
            routeParameters: ['tahap' => 'tidak_lolos'],
            actionLabel: 'Buka talenta siswa',
            extra: ['talent_application_id' => (string) $application->getKey()],
        );
    }
}
