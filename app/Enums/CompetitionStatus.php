<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Catalog entry status. Every create starts at menunggu_verifikasi (D-27). */
enum CompetitionStatus: string
{
    use HasOptions;

    case MenungguVerifikasi = 'menunggu_verifikasi';
    case Dipublikasikan = 'dipublikasikan';
    case Ditolak = 'ditolak';
    case Ditutup = 'ditutup';

    public function label(): string
    {
        return match ($this) {
            self::MenungguVerifikasi => 'Menunggu verifikasi',
            self::Dipublikasikan => 'Dipublikasikan',
            self::Ditolak => 'Ditolak',
            self::Ditutup => 'Ditutup',
        };
    }

    /** Status chip tone (DESIGN-SPEC §2). */
    public function tone(): string
    {
        return match ($this) {
            self::MenungguVerifikasi => 'gray',
            self::Dipublikasikan => 'green',
            self::Ditolak => 'red',
            self::Ditutup => 'gray',
        };
    }
}
