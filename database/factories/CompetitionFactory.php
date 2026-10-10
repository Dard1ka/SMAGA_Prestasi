<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\WeightCategory;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default = a fully filled entry still waiting for Kesiswaan (D-27). Use published() for catalog tests.
 *
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = fake('id_ID');
        $startsOn = now()->addDays($faker->numberBetween(14, 60))->startOfDay();

        return [
            'name' => 'Lomba '.ucfirst($faker->words(2, true)).' '.$startsOn->year,
            'description' => $faker->sentence(12),
            'bidang' => $faker->randomElement(Bidang::cases()),
            'organizer_name' => $faker->company(),
            'organizer_type' => $faker->randomElement(OrganizerType::cases()),
            // "Lainnya" needs the typed kind before it can be published (D-125), so a random or an explicit
            // "Lainnya" is always complete; every other type and a proposal have none.
            'organizer_type_other' => function (array $attributes): ?string {
                $type = $attributes['organizer_type'] ?? null;
                $type = $type instanceof OrganizerType ? $type : OrganizerType::tryFrom((string) $type);

                return $type === OrganizerType::Lainnya ? 'Komunitas pelajar' : null;
            },
            'weight_category' => $faker->randomElement(WeightCategory::cases()),
            'event_status' => $faker->randomElement(EventStatus::cases()),
            'level' => $faker->randomElement(Level::cases()),
            'mode' => $faker->randomElement(EventMode::cases()),
            'registration_opens_at' => now()->subDays(7)->startOfDay(),
            'registration_closes_at' => $startsOn->subDays(5)->endOfDay(),
            'starts_on' => $startsOn->format('Y-m-d'),
            'final_on' => $startsOn->format('Y-m-d'),
            'location' => $faker->city(),
            'participation' => Participation::Individu,
            'max_team_size' => null,
            'default_mentor_id' => null,
            'website_url' => 'https://example.test/lomba/'.$faker->slug(2),
            'registration_url' => null,
            'status' => CompetitionStatus::MenungguVerifikasi,
            'submitted_by' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Dipublikasikan,
            'verified_by' => fn (array $resolved) => $resolved['submitted_by'],
            'verified_at' => now()->subDays(6),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Ditolak,
            'verification_note' => 'Informasi lomba belum lengkap.',
        ]);
    }

    /** Past event whose registration is closed. */
    public function finished(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompetitionStatus::Ditutup,
            'registration_opens_at' => now()->subDays(60),
            'registration_closes_at' => now()->subDays(40),
            'starts_on' => now()->subDays(30)->format('Y-m-d'),
            'final_on' => now()->subDays(30)->format('Y-m-d'),
        ]);
    }

    public function team(int $maxTeamSize = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'participation' => Participation::Tim,
            'max_team_size' => $maxTeamSize,
        ]);
    }

    /** Student proposal (usulan, D-04): only name, organizer and link are known. */
    public function proposal(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => null,
            'bidang' => null,
            'organizer_type' => null,
            'weight_category' => null,
            'event_status' => null,
            'level' => null,
            'mode' => null,
            'registration_opens_at' => null,
            'registration_closes_at' => null,
            'starts_on' => null,
            'final_on' => null,
            'location' => null,
            'participation' => null,
        ]);
    }
}
