<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Classroom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'X-'.fake()->unique()->numberBetween(100, 9999),
            'level' => 10,
            'active' => true,
        ];
    }

    /** Level 10/11/12 with a matching X-/XI-/XII- prefix. */
    public function level(int $level): static
    {
        $prefix = match ($level) {
            11 => 'XI',
            12 => 'XII',
            default => 'X',
        };

        return $this->state(fn (array $attributes) => [
            'name' => $prefix.'-'.fake()->unique()->numberBetween(100, 9999),
            'level' => $level,
        ]);
    }
}
