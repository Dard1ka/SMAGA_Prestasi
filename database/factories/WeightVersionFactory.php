<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Points\WeightTable;
use App\Models\WeightVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeightVersion>
 */
class WeightVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'effective_at' => now()->subDay(),
            'created_by' => null,
            'note' => 'Bobot uji',
        ];
    }

    /** Fill weight_values with the ARCHITECTURE §7 defaults. */
    public function withDefaultValues(): static
    {
        return $this->afterCreating(function (WeightVersion $version): void {
            foreach (WeightTable::defaults()->toRows() as $row) {
                $version->values()->create($row);
            }
        });
    }
}
