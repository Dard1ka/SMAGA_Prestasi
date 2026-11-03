<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Account status. Archived and disabled accounts cannot log in (D-29, D-30). */
enum UserStatus: string
{
    use HasOptions;

    case PendingActivation = 'pending_activation';
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::PendingActivation => 'Belum aktivasi',
            self::Active => 'Aktif',
            self::Disabled => 'Nonaktif',
            self::Archived => 'Diarsipkan',
        };
    }

    public function canLogIn(): bool
    {
        return $this === self::PendingActivation || $this === self::Active;
    }
}
