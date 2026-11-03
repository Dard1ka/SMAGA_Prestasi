<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** appreciations.status */
enum AppreciationStatus: string
{
    use HasOptions;

    case Dijadwalkan = 'dijadwalkan';
    case Selesai = 'selesai';

    public function label(): string
    {
        return match ($this) {
            self::Dijadwalkan => 'Dijadwalkan',
            self::Selesai => 'Selesai',
        };
    }
}
