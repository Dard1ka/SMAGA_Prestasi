<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** files.purpose: what an uploaded/generated file is for (drives policy checks on download). */
enum FilePurpose: string
{
    use HasOptions;

    case Juknis = 'juknis';
    case Cover = 'cover';
    case ReportEvidence = 'report_evidence';
    case TalentEvidence = 'talent_evidence';
    case Letter = 'letter';
    case Signature = 'signature';
    case Flyer = 'flyer';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Juknis => 'Juknis/brosur',
            self::Cover => 'Sampul lomba',
            self::ReportEvidence => 'Bukti laporan hasil',
            self::TalentEvidence => 'Bukti dukung talenta',
            self::Letter => 'Surat tugas',
            self::Signature => 'Tanda tangan + stempel',
            self::Flyer => 'E-flyer',
            self::Import => 'File impor siswa',
        };
    }
}
