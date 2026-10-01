<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationMember>
 */
class RegistrationMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'student_id' => Student::factory(),
            'role' => MemberRole::Anggota,
            'invited_at' => now(),
            'confirmed_at' => null,
            'declined_at' => null,
        ];
    }

    public function leader(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MemberRole::Ketua,
            'confirmed_at' => now(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => ['confirmed_at' => now()]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes) => ['declined_at' => now()]);
    }
}
