<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TalentTypeCode;
use App\Models\TalentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Codes are unique (6 possible). In tests that also run TalentSeeder, use TalentType::where('code', …) instead.
 *
 * @extends Factory<TalentType>
 */
class TalentTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var TalentTypeCode $code */
        $code = fake()->unique()->randomElement(TalentTypeCode::cases());

        return [
            'code' => $code,
            'name' => $code->label(),
            'bidang' => $code->bidang(),
            'sort_order' => 0,
        ];
    }

    public function code(TalentTypeCode $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $code,
            'name' => $code->label(),
            'bidang' => $code->bidang(),
        ]);
    }
}
