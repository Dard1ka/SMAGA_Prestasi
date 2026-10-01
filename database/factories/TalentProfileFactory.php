<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\TalentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentProfile>
 */
class TalentProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'interests' => fake()->randomElements(['Penelitian', 'Kimia', 'Biologi', 'Debat', 'Tari', 'Atletik', 'Puisi'], 2),
            'skills' => fake('id_ID')->sentence(4),
            'organizations' => 'KIR SMAGA',
            'junior_high_achievements' => null,
        ];
    }
}
