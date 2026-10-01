<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default = submitted report waiting for Kesiswaan.
 *
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory()->status(RegistrationStatus::LaporanDikirim),
            'claimed_result' => fake()->randomElement(ResultType::cases()),
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
            'status' => ReportStatus::Dikirim,
            'final_result' => null,
            'verified_by' => null,
            'verified_at' => null,
            'note' => null,
            'research_title' => null,
            'research_abstract' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Diverifikasi,
            'final_result' => $attributes['claimed_result'],
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ]);
    }
}
