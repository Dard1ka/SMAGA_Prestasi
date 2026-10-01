<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default = individual registration waiting for the Kepala Sekolah. The leader is added as a confirmed
 * `ketua` member automatically; add anggota with withMembers().
 *
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory()->published(),
            'leader_student_id' => Student::factory(),
            'type' => RegistrationType::Individu,
            'branch' => null,
            'mentor_id' => null,
            'status' => RegistrationStatus::MenungguKepsek,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Registration $registration): void {
            if ($registration->members()->where('student_id', $registration->leader_student_id)->doesntExist()) {
                RegistrationMember::factory()->leader()->create([
                    'registration_id' => $registration->getKey(),
                    'student_id' => $registration->leader_student_id,
                ]);
            }
        });
    }

    public function status(RegistrationStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => [
            'competition_id' => Competition::factory()->published()->team(),
            'type' => RegistrationType::Tim,
            'status' => RegistrationStatus::MenungguKonfirmasi,
        ]);
    }

    /** Add $count anggota (confirmed unless $confirmed = false). */
    public function withMembers(int $count = 2, bool $confirmed = true): static
    {
        return $this->afterCreating(function (Registration $registration) use ($count, $confirmed): void {
            RegistrationMember::factory()
                ->count($count)
                ->state(fn () => [
                    'registration_id' => $registration->getKey(),
                    'role' => MemberRole::Anggota,
                    'confirmed_at' => $confirmed ? now() : null,
                ])
                ->create();
        });
    }
}
