<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * talent_types.code (docs/TALENT-FORM.md) with the bidang mapping of D-22 + D-143: the seventh jenis "Lainnya" has no
 * bidang and no cabang; the student names the talent in "Sebutkan" (`talent_applications.other_text`).
 */
enum TalentTypeCode: string
{
    use HasOptions;

    case Osn = 'osn';
    case Riset = 'riset';
    case Fls3n = 'fls3n';
    case O2sn = 'o2sn';
    case Olpain = 'olpain';
    case Debat = 'debat';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Osn => 'OSN',
            self::Riset => 'Riset',
            self::Fls3n => 'FLS3N',
            self::O2sn => 'O2SN',
            self::Olpain => 'OLPAIN',
            self::Debat => 'Debat',
            self::Lainnya => 'Lainnya',
        };
    }

    /**
     * D-22: OSN & OLPAIN → Sains, Riset → Riset, FLS3N & Debat → Seni Budaya, O2SN → Olahraga. D-143: "Lainnya" has
     * no bidang (null; never a fifth bidang, D-11): screens show "Lainnya" in its place.
     */
    public function bidang(): ?Bidang
    {
        return match ($this) {
            self::Osn, self::Olpain => Bidang::Sains,
            self::Riset => Bidang::Riset,
            self::Fls3n, self::Debat => Bidang::SeniBudaya,
            self::O2sn => Bidang::Olahraga,
            self::Lainnya => null,
        };
    }

    /** OLPAIN and "Lainnya" have no cabang (branch) to pick. */
    public function requiresBranch(): bool
    {
        return $this !== self::Olpain && $this !== self::Lainnya;
    }
}
