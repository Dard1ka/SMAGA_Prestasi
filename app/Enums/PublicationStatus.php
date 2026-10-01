<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** publications.status (Humas queue lanes). */
enum PublicationStatus: string
{
    use HasOptions;

    case Siap = 'siap';
    case Terjadwal = 'terjadwal';
    case Dipublikasikan = 'dipublikasikan';

    public function label(): string
    {
        return match ($this) {
            self::Siap => 'Siap dipublikasikan',
            self::Terjadwal => 'Terjadwal',
            self::Dipublikasikan => 'Dipublikasikan',
        };
    }
}
