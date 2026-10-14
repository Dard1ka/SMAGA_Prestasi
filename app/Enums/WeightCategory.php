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

    /**
     * Typical organizers per category (Pengaturan › Bobot poin, and through legend() the Kelola lomba drawer). Names
     * every jenis penyelenggara that OrganizerWeight maps to the category (D-146).
     */
    public function hint(): string
    {
        return match ($this) {
            self::A => 'Puspresnas',
            self::B => 'Pemerintah pusat/daerah, BUMN/BUMD',
            self::C => 'Federasi, perguruan tinggi, organisasi profesi',
            self::D => 'Swasta, yayasan, lembaga',
            self::E => 'Lainnya/umum',
        };
    }

    /**
     * "A Puspresnas · B Pemerintah pusat/daerah, BUMN/BUMD · …": the hint under "Kategori bobot" in the Kelola lomba
     * drawer, built from hint() so the two screens cannot drift apart (FS1 QA round 3).
     */
    public static function legend(): string
    {
        return implode(' · ', array_map(fn (self $category): string => $category->value.' '.$category->hint(), self::cases()));
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
