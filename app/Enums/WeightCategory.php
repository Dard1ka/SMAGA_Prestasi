<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Organizer weight category A–E (kategori bobot penyelenggara). Default weights: D-08. */
enum WeightCategory: string
{
    use HasOptions;

    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';

    public function label(): string
    {
        return 'Kategori '.$this->value;
    }

    /** Typical organizers per category (hint text in the Kelola lomba form / Pengaturan). */
    public function hint(): string
    {
        return match ($this) {
            self::A => 'Puspresnas',
            self::B => 'Pemerintah pusat/daerah',
            self::C => 'Federasi, perguruan tinggi, organisasi profesi',
            self::D => 'Swasta, yayasan, lembaga',
            self::E => 'Lainnya/umum',
        };
    }

    /** Default weight as a 2-decimal string (the active WeightVersion is the source of truth). */
    public function defaultWeight(): string
    {
        return match ($this) {
            self::A => '1.00',
            self::B => '0.80',
            self::C => '0.60',
            self::D => '0.40',
            self::E => '0.20',
        };
    }
}
