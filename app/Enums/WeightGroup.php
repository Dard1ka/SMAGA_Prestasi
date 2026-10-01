<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * weight_values.group. Keys per group: level → Level values, result → ResultType values,
 * organizer → WeightCategory values (A–E).
 */
enum WeightGroup: string
{
    use HasOptions;

    case Level = 'level';
    case Result = 'result';
    case Organizer = 'organizer';

    public function label(): string
    {
        return match ($this) {
            self::Level => 'Tingkat',
            self::Result => 'Capaian',
            self::Organizer => 'Penyelenggara',
        };
    }

    /** @return array<string, string> key => default weight (2-decimal string) */
    public function defaults(): array
    {
        $cases = match ($this) {
            self::Level => Level::cases(),
            self::Result => ResultType::cases(),
            self::Organizer => WeightCategory::cases(),
        };

        $map = [];

        foreach ($cases as $case) {
            $map[$case->value] = $case->defaultWeight();
        }

        return $map;
    }
}
