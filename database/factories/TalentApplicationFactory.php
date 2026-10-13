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
            'other_text' => null,
            'evidence_file_id' => null,
            'status' => TalentStatus::Terdaftar,
            'mentor_id' => null,
            'assessment_on' => null,
            'note' => null,
            'student_note' => null,
            'student_note_draft' => null,
            'rejected_from' => null,
            'review_requested_at' => null,
            'review_message' => null,
        ];
    }

    public function status(TalentStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    /** An old "tidak lolos" row from $from (before D-142: talents have no stages any more). */
    public function rejected(TalentStatus $from = TalentStatus::Seleksi, ?string $studentNote = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TalentStatus::TidakLolos,
            'rejected_from' => $from,
            'student_note' => $studentNote,
        ]);
    }

    /** An old rejected row whose student sent "Minta ditinjau ulang" (before D-142). */
    public function reviewRequested(?string $message = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TalentStatus::TidakLolos,
            'review_requested_at' => now(),
            'review_message' => $message,
        ]);
    }
}
