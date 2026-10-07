<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Points\WeightTable;
use App\Models\WeightVersion;
use Illuminate\Database\Seeder;

/**
 * Default weight version (ARCHITECTURE §7, D-08). Runs only when no version exists, so Admin changes
 * (new versions) are never overwritten. Effective from 1 July 2024 so demo history can reference it.
 */
class WeightSeeder extends Seeder
{
    public const DEFAULT_EFFECTIVE_AT = '2024-07-01 00:00:00';

    public function run(): void
    {
        if (WeightVersion::query()->exists()) {
            return;
        }

        $version = WeightVersion::query()->create([
            'effective_at' => self::DEFAULT_EFFECTIVE_AT,
            'note' => 'Bobot bawaan',
        ]);

        foreach (WeightTable::defaults()->toRows() as $row) {
            $version->values()->create($row);
        }
    }
}
