<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** reports.status (laporan hasil). */
enum ReportStatus: string
{
    use HasOptions;

    case Dikirim = 'dikirim';
    case PerluRevisi = 'perlu_revisi';
    case Diverifikasi = 'diverifikasi';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return match ($this) {
            self::Dikirim => 'Menunggu verifikasi',
            self::PerluRevisi => 'Perlu revisi',
            self::Diverifikasi => 'Diverifikasi',
            self::Ditolak => 'Ditolak',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Dikirim => 'blue',
            self::PerluRevisi => 'orange',
            self::Diverifikasi => 'green',
            self::Ditolak => 'red',
        };
    }
}
