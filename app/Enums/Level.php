<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Competition level (tingkat). Default weights: ARCHITECTURE §7 / D-08. */
enum Level: string
{
    use HasOptions;

    case Sekolah = 'sekolah';
    case Kota = 'kota';
    case Provinsi = 'provinsi';
    case Nasional = 'nasional';
    case Internasional = 'internasional';

    public function label(): string
    {
        return match ($this) {
            self::Sekolah => 'Sekolah',
            self::Kota => 'Kota/Kabupaten',
            self::Provinsi => 'Provinsi',
            self::Nasional => 'Nasional',
            self::Internasional => 'Internasional',
        };
    }

    /** Compact label for chips and tables ("Kota/Kab"). */
    public function shortLabel(): string
    {
        return $this === self::Kota ? 'Kota/Kab' : $this->label();
    }

    /** Default weight as a 2-decimal string (the active WeightVersion is the source of truth). */
    public function defaultWeight(): string
    {
        return match ($this) {
            self::Sekolah => '20.00',
            self::Kota => '40.00',
            self::Provinsi => '60.00',
            self::Nasional => '80.00',
            self::Internasional => '100.00',
        };
    }

    /** Higher = more prestigious; used for sorting/tie-breaks. */
    public function rank(): int
    {
        return match ($this) {
            self::Sekolah => 1,
            self::Kota => 2,
            self::Provinsi => 3,
            self::Nasional => 4,
            self::Internasional => 5,
        };
    }
}
