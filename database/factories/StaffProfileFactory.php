<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoleName;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffProfile>
 */
class StaffProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->staff(RoleName::Guru),
            'honorific' => fake()->randomElement(['Bu', 'Pak']),
            'nip' => null,
            'rank' => null,
            'position' => 'Guru',
        ];
    }
}
