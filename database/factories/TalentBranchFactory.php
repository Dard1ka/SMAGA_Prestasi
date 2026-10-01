<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TalentBranch;
use App\Models\TalentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentBranch>
 */
class TalentBranchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'talent_type_id' => TalentType::factory(),
            'name' => ucfirst(fake('id_ID')->unique()->words(2, true)),
            'team_size' => null,
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function team(int $size): static
    {
        return $this->state(fn (array $attributes) => ['team_size' => $size]);
    }
}
