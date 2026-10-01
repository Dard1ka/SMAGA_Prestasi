<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Pelaksanaan lomba. */
enum EventMode: string
{
    use HasOptions;

    case Daring = 'daring';
    case Luring = 'luring';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Daring => 'Daring',
            self::Luring => 'Luring',
            self::Hybrid => 'Hybrid',
        };
    }
}
