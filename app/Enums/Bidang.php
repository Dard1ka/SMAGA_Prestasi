<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * The four STH fields (D-11). Talent types map onto them via TalentTypeCode::bidang() (D-22).
 */
enum Bidang: string
{
    use HasOptions;

    case Sains = 'sains';
    case Riset = 'riset';
    case SeniBudaya = 'seni_budaya';
    case Olahraga = 'olahraga';

    public function label(): string
    {
        return match ($this) {
            self::Sains => 'Sains',
            self::Riset => 'Riset',
            self::SeniBudaya => 'Seni Budaya',
            self::Olahraga => 'Olahraga',
        };
    }

    /** Short key used by the design system classes (`b-sains`, `cover-seni`, …). */
    public function cssKey(): string
    {
        return match ($this) {
            self::Sains => 'sains',
            self::Riset => 'riset',
            self::SeniBudaya => 'seni',
            self::Olahraga => 'olahraga',
        };
    }

    /** Talent type code (osn, riset, fls3n, o2sn, olpain, debat) → bidang (D-22); "lainnya" → null (D-143). */
    public static function forTalentCode(TalentTypeCode|string $code): ?self
    {
        $code = $code instanceof TalentTypeCode ? $code : TalentTypeCode::from($code);

        return $code->bidang();
    }
}
