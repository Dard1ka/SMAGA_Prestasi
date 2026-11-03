<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Who may enter a competition: individu, tim, or both. */
enum Participation: string
{
    use HasOptions;

    case Individu = 'individu';
    case Tim = 'tim';
    case Keduanya = 'keduanya';

    public function label(): string
    {
        return match ($this) {
            self::Individu => 'Individu',
            self::Tim => 'Tim',
            self::Keduanya => 'Individu/Tim',
        };
    }

    public function allows(RegistrationType $type): bool
    {
        return match ($this) {
            self::Individu => $type === RegistrationType::Individu,
            self::Tim => $type === RegistrationType::Tim,
            self::Keduanya => true,
        };
    }
}
