<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Result (capaian). Default weights: D-08 (semifinalis and penghargaan khusus are 0,30). */
enum ResultType: string
{
    use HasOptions;

    case Juara1 = 'juara_1';
    case Juara2 = 'juara_2';
    case Juara3 = 'juara_3';
    case MedaliEmas = 'medali_emas';
    case MedaliPerak = 'medali_perak';
    case MedaliPerunggu = 'medali_perunggu';
    case Finalis = 'finalis';
    case Semifinalis = 'semifinalis';
    case PenghargaanKhusus = 'penghargaan_khusus';
    case Peserta = 'peserta';

    public function label(): string
    {
        return match ($this) {
            self::Juara1 => 'Juara I',
            self::Juara2 => 'Juara II',
            self::Juara3 => 'Juara III',
            self::MedaliEmas => 'Medali emas',
            self::MedaliPerak => 'Medali perak',
            self::MedaliPerunggu => 'Medali perunggu',
            self::Finalis => 'Finalis',
            self::Semifinalis => 'Semifinalis',
            self::PenghargaanKhusus => 'Penghargaan khusus',
            self::Peserta => 'Peserta',
        };
    }

    /** Default weight as a 2-decimal string (the active WeightVersion is the source of truth). */
    public function defaultWeight(): string
    {
        return match ($this) {
            self::Juara1, self::MedaliEmas => '1.00',
            self::Juara2, self::MedaliPerak => '0.85',
            self::Juara3, self::MedaliPerunggu => '0.70',
            self::Finalis => '0.50',
            self::Semifinalis, self::PenghargaanKhusus => '0.30',
            self::Peserta => '0.10',
        };
    }

    /**
     * Ordering for leaderboard tie-breaks ("more higher-ranked results first"): lower = better.
     * Juara I and medali emas share a tier, etc.
     */
    public function tier(): int
    {
        return match ($this) {
            self::Juara1, self::MedaliEmas => 1,
            self::Juara2, self::MedaliPerak => 2,
            self::Juara3, self::MedaliPerunggu => 3,
            self::Finalis => 4,
            self::Semifinalis, self::PenghargaanKhusus => 5,
            self::Peserta => 6,
        };
    }

    /** Juara I–III or a medal: shown with the gold medal styling. */
    public function isPodium(): bool
    {
        return $this->tier() <= 3;
    }
}
