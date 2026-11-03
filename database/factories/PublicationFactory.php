<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublicationChannel;
use App\Enums\PublicationStatus;
use App\Models\Achievement;
use App\Models\Publication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publication>
 */
class PublicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'achievement_id' => Achievement::factory(),
            'status' => PublicationStatus::Siap,
            'channels' => [PublicationChannel::Instagram->value],
            'scheduled_at' => null,
            'reminded_at' => null,
            'caption' => fake('id_ID')->sentence(10),
            'published_at' => null,
            'created_by' => null,
        ];
    }
}
