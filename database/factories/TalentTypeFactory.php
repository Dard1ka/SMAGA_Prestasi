<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TalentTypeCode;
use App\Models\TalentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Codes are unique (6 possible at random: "Lainnya", the jenis without bidang, only through `code()`, so no test gets
 * a null bidang by chance). In tests that also run TalentSeeder, use TalentType::where('code', …) instead.
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
        $code = fake()->unique()->randomElement(array_values(array_filter(
            TalentTypeCode::cases(),
            fn (TalentTypeCode $c): bool => $c !== TalentTypeCode::Lainnya,
        )));

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
