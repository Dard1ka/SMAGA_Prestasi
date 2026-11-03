<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * `talent_applications.status` of the old talent pipeline (Terdaftar → Pemetaan → Seleksi/asesmen → Peserta pembinaan,
 * or Tidak lolos). Kept for old rows only (D-142): talents have no stages any more, every new or restored row is
 * Terdaftar, and no screen shows a status. The column stays (no destructive migration).
 */
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
}
