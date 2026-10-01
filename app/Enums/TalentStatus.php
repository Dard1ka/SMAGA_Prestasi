<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Talent scouting pipeline: Terdaftar → Pemetaan → Seleksi/asesmen → Peserta pembinaan, or Tidak lolos. */
enum TalentStatus: string
{
    use HasOptions;

    case Terdaftar = 'terdaftar';
    case Pemetaan = 'pemetaan';
    case Seleksi = 'seleksi';
    case Pembinaan = 'pembinaan';
    case TidakLolos = 'tidak_lolos';

    public function label(): string
    {
        return match ($this) {
            self::Terdaftar => 'Terdaftar',
            self::Pemetaan => 'Pemetaan',
            self::Seleksi => 'Seleksi/asesmen',
            self::Pembinaan => 'Peserta pembinaan',
            self::TidakLolos => 'Tidak lolos',
        };
    }

    /** "Tahap N dari 4" in the student view; null for tidak_lolos. */
    public function step(): ?int
    {
        return match ($this) {
            self::Terdaftar => 1,
            self::Pemetaan => 2,
            self::Seleksi => 3,
            self::Pembinaan => 4,
            self::TidakLolos => null,
        };
    }

    /** Next pipeline stage ("Lolos ke …"), null at the end. */
    public function next(): ?self
    {
        return match ($this) {
            self::Terdaftar => self::Pemetaan,
            self::Pemetaan => self::Seleksi,
            self::Seleksi => self::Pembinaan,
            self::Pembinaan, self::TidakLolos => null,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Pembinaan || $this === self::TidakLolos;
    }

    /** Chip tone as used in the Talenta mockups (T1/T2 notes). */
    public function tone(): string
    {
        return match ($this) {
            self::Terdaftar, self::Pemetaan => 'blue',
            self::Seleksi => 'orange',
            self::Pembinaan => 'green',
            self::TidakLolos => 'red',
        };
    }
}
