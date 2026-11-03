<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** report_files.kind */
enum ReportFileKind: string
{
    use HasOptions;

    case Sertifikat = 'sertifikat';
    case Foto = 'foto';
    case Pengumuman = 'pengumuman';
    case LaporanRiset = 'laporan_riset';
    case Artikel = 'artikel';
    case Poster = 'poster';

    public function label(): string
    {
        return match ($this) {
            self::Sertifikat => 'Sertifikat',
            self::Foto => 'Foto',
            self::Pengumuman => 'Pengumuman',
            self::LaporanRiset => 'Laporan riset',
            self::Artikel => 'Artikel',
            self::Poster => 'Poster',
        };
    }
}
