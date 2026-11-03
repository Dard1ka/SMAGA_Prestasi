<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prefer Setting::put(SettingKey::…, $value) in tests; this factory only makes arbitrary keys.
 *
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test_'.fake()->unique()->lexify('??????'),
            'value' => fake()->word(),
        ];
    }
}
