<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WeightGroup;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeightValue>
 */
class WeightValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weight_version_id' => WeightVersion::factory(),
            'group' => WeightGroup::Level,
            'key' => 'nasional',
            'value' => '80.00',
        ];
    }
}
