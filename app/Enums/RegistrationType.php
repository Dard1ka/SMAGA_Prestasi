<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** registrations.type */
enum RegistrationType: string
{
    use HasOptions;

    case Individu = 'individu';
    case Tim = 'tim';

    public function label(): string
    {
        return match ($this) {
            self::Individu => 'Individu',
            self::Tim => 'Tim',
        };
    }
}
