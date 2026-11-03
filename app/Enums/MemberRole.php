<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** registration_members.role */
enum MemberRole: string
{
    use HasOptions;

    case Ketua = 'ketua';
    case Anggota = 'anggota';

    public function label(): string
    {
        return match ($this) {
            self::Ketua => 'Ketua',
            self::Anggota => 'Anggota',
        };
    }
}
