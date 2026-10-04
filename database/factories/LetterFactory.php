<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Letters\LetterNumber;
use App\Enums\LetterType;
use App\Enums\RegistrationStatus;
use App\Models\Letter;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Letter>
 */
class LetterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory()->status(RegistrationStatus::SuratTerbit),
            'type' => LetterType::Siswa,
            'sequence' => fake()->unique()->numberBetween(1, 99999),
            'full_number' => fn (array $a) => (new LetterNumber)->format(
                $this->template($a['type']),
                $a['sequence'],
                $a['issued_on'],
            ),
            'year' => fn (array $a) => (int) substr((string) $a['issued_on'], 0, 4),
            'month' => fn (array $a) => (int) substr((string) $a['issued_on'], 5, 2),
            'issued_on' => '2026-10-01',
            'basis_from' => null,
            'basis_number' => null,
            'basis_date' => null,
            'basis_subject' => null,
            'schedule_day' => null,
            'schedule_date' => null,
            'schedule_time' => null,
            'place' => null,
            'file_id' => null,
            'verification_code' => fn () => Letter::newVerificationCode(),
            'generated_by' => null,
            'generated_at' => now(),
            'sent_at' => null,
        ];
    }

    public function guru(): static
    {
        return $this->state(fn (array $attributes) => ['type' => LetterType::Guru]);
    }

    private function template(LetterType|string $type): string
    {
        return ($type instanceof LetterType ? $type : LetterType::from($type)) === LetterType::Guru
            ? LetterNumber::TEMPLATE_GURU
            : LetterNumber::TEMPLATE_SISWA;
    }
}
