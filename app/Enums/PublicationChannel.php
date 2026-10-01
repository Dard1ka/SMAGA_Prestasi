<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Values stored in publications.channels (json list). */
enum PublicationChannel: string
{
    use HasOptions;

    case Instagram = 'instagram';
    case Website = 'website';
    case Mading = 'mading';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Website => 'Website',
            self::Mading => 'Mading sekolah',
        };
    }
}
