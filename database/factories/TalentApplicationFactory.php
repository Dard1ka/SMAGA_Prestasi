<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TalentStatus;
use App\Models\Student;
use App\Models\TalentApplication;
use App\Models\TalentBranch;
use App\Models\TalentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentApplication>
 */
class TalentApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'talent_type_id' => TalentType::factory(),
            'talent_branch_id' => fn (array $a) => TalentBranch::factory()->create(['talent_type_id' => $a['talent_type_id']])->getKey(),
            'evidence_file_id' => null,
            'status' => TalentStatus::Terdaftar,
            'mentor_id' => null,
            'assessment_on' => null,
            'note' => null,
        ];
    }

    public function status(TalentStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
