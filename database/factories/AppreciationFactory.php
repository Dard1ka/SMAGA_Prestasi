<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppreciationStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appreciation>
 */
class AppreciationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'achievement_id' => Achievement::factory(),
            'appreciation_event_id' => AppreciationEvent::factory(),
            'status' => AppreciationStatus::Dijadwalkan,
        ];
    }
}
